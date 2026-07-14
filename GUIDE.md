# Laravel AI Chatbot — A to Z Guide

এই ডকুমেন্টটা পুরো প্রজেক্টের শিক্ষামূলক ম্যানুয়াল। প্রতিটা ফাইল **কেন** আছে, ডাটা **কোন পথে** যায়, আর ChatGPT-এর মত ফিচারগুলো (memory, streaming, auto title) **আসলে কীভাবে কাজ করে** — সব ধাপে ধাপে।

---

## 0. আগে চালিয়ে দেখুন

```bash
composer install
php artisan migrate:fresh
php artisan serve
```

ব্রাউজারে খুলুন: <http://127.0.0.1:8000/chat>

**API key ছাড়াই পুরো অ্যাপ চলবে।** `.env`-এ `OPENAI_API_KEY` খালি থাকলে অ্যাপ **demo mode**-এ চলে (sidebar-এ `demo mode` badge দেখবেন) — নকল উত্তর আসে, কিন্তু বাকি সবকিছু (database save, history, streaming, markdown) আসল। এটা ইচ্ছা করে রাখা হয়েছে যাতে টাকা খরচ না করে শিখতে পারেন।

আসল উত্তর চাইলে `.env`-এ key বসান:

```env
OPENAI_API_KEY=sk-...আপনার-key...
OPENAI_MODEL=gpt-4o-mini
```

তারপর `php artisan config:clear`।

---

## 1. পুরো ছবিটা এক নজরে

একটা মেসেজ পাঠালে যা ঘটে:

```
1. Browser  ──POST /chat/1/messages──▶  ChatController@store
2.                                       └─▶ ChatService::addUserMessage()  ──▶  messages টেবিলে user row
3.                                       ◀── redirect  /chat/1
4. Browser  ──GET /chat/1─────────────▶  ChatController@show   (user মেসেজ + খালি AI bubble দেখায়)
5. Browser  ──GET /chat/1/stream──────▶  ChatController@stream   (JavaScript নিজে থেকে খোলে)
6.                                       └─▶ ChatService::streamReply()
7.                                            └─▶ OpenAIService::streamReply()  ──▶  OpenAI API
8.                                       ◀── শব্দে শব্দে chunk (SSE)  ──▶  স্ক্রিনে টাইপ হতে থাকে
9. স্ট্রিম শেষ ──▶ পুরো উত্তর messages টেবিলে assistant row ──▶ পেজ reload ──▶ সুন্দর Markdown
```

**সবচেয়ে গুরুত্বপূর্ণ ডিজাইন সিদ্ধান্ত:** মেসেজ save করা আর উত্তর generate করা — এই দুটো **আলাদা দুটো request**। কেন, সেটা §9-এ।

---

## 2. তিনটা স্তর (Layers) — এটাই আসল শিক্ষা

| স্তর | ফাইল | কী জানে | কী জানে না |
|---|---|---|---|
| HTTP | `ChatController` | request, redirect, view | OpenAI কী, database কেমন |
| Workflow | `ChatService` | session, message, title | OpenAI-র SDK কেমন |
| API | `OpenAIService` | OpenAI SDK, model, prompt | database আছে কি না |

কেন এত কষ্ট? কারণ কাল যদি OpenAI-র API বদলায়, শুধু **একটা ফাইল** বদলাবে — [OpenAIService.php](app/Services/OpenAIService.php)। Controller-এ যদি সরাসরি `OpenAI::chat()` লিখতেন, তাহলে ৫ জায়গায় খুঁজে খুঁজে বদলাতে হত।

> **নিয়ম:** Controller থেকে কখনো সরাসরি SDK ডাকবেন না।

---

## 3. Routes — অ্যাপের ম্যাপ

[routes/web.php](routes/web.php):

```php
Route::middleware('throttle:30,1')->group(function () {
    Route::get('/chat', [ChatController::class, 'index'])->name('chat.index');
    Route::get('/chat/{session}', [ChatController::class, 'show'])->name('chat.show');

    Route::post('/chat', [ChatController::class, 'store'])->name('chat.store');
    Route::post('/chat/{session}/messages', [ChatController::class, 'store'])->name('chat.messages.store');

    Route::get('/chat/{session}/stream', [ChatController::class, 'stream'])->name('chat.stream');
    Route::post('/chat/{session}/reply', [ChatController::class, 'reply'])->name('chat.reply');

    Route::patch('/chat/{session}', [ChatController::class, 'update'])->name('chat.update');
    Route::delete('/chat/{session}', [ChatController::class, 'destroy'])->name('chat.destroy');
});
```

শেখার পয়েন্ট:

- **`{session}` → `ChatSession $session`** — একে বলে *route model binding*। Laravel নিজেই id দিয়ে model খুঁজে এনে দেয়; `ChatSession::find()` লিখতে হয় না। না পেলে অটো 404।
- **`->name()`** — Blade-এ `route('chat.show', $session)` লিখলেই URL তৈরি। URL বদলালে view ঠিক থাকে।
- **`throttle:30,1`** — মিনিটে ৩০ request। AI-তে পৌঁছাতে পারে এমন প্রতিটা route-এর সামনে rate limit **অবশ্যই** দিতে হবে, নইলে একটা bug বা একজন দুষ্টু ব্যবহারকারী আপনার OpenAI বিল উড়িয়ে দেবে।
- **দুটো route, একটা method** — `store()`-এ session optional (`?ChatSession $session = null`)। session না থাকলে নতুন খোলে।

---

## 4. Database — দুটো টেবিল

```
chat_sessions                messages
├── id                       ├── id
├── title (nullable)  ◀──┐   ├── chat_session_id ──┘ (foreign key, cascade delete)
└── timestamps           │   ├── role  (system | user | assistant)
                         │   ├── content
                         └───┤ timestamps
```

[create_messages_table](database/migrations/2026_07_14_083359_create_messages_table.php)-এর তিনটা লাইন খেয়াল করুন:

```php
$table->foreignId('chat_session_id')->constrained()->cascadeOnDelete();
$table->enum('role', ['system', 'user', 'assistant']);
$table->index(['chat_session_id', 'id']);
```

- **`cascadeOnDelete()`** — chat মুছলে তার মেসেজগুলোও database নিজেই মুছে দেয়। PHP-তে loop করে মুছতে হয় না, আর orphan row কখনো থাকে না।
- **`enum`** — role-এ `assistent` টাইপো লিখলে database সাথে সাথে error দেবে। ঢাল হিসেবে কাজ করে।
- **`title` nullable** — session আগে তৈরি হয়, নাম পরে (§10)।

`title` কেন nullable? কারণ প্রথম মেসেজ পাঠানোর সময় আমরা জানিই না চ্যাটটা কী নিয়ে হবে।

---

## 5. Models — সম্পর্ক আর ছোট ছোট helper

[ChatSession.php](app/Models/ChatSession.php) / [Message.php](app/Models/Message.php):

```php
// ChatSession
public function messages(): HasMany { return $this->hasMany(Message::class); }

// Message
public function chatSession(): BelongsTo { return $this->belongsTo(ChatSession::class); }
```

এতটুকু লিখলেই `$session->messages` দিয়ে সব মেসেজ, `$message->chatSession` দিয়ে তার session পাওয়া যায়।

দুটো helper বিশেষভাবে দেখুন:

```php
// ChatSession.php
public function awaitsReply(): bool
{
    return $this->lastMessage()?->role === Message::ROLE_USER;
}
```

**"শেষ মেসেজটা user-এর মানে AI এখনো উত্তর দেয়নি।"** — পুরো streaming সিস্টেমের ভিত্তি এই এক লাইন (§9)।

```php
// Message.php
public const ROLE_USER = 'user';
```

স্ট্রিং হাতে না লিখে constant — টাইপো করলে PHP সাথে সাথে ধরবে, চুপচাপ ভুল data save হবে না।

---

## 6. Validation — Form Request

Controller-এ validation নিয়ম রাখলে controller মোটা হয়। তাই আলাদা ক্লাস — [StoreMessageRequest.php](app/Http/Requests/StoreMessageRequest.php):

```php
public function rules(): array
{
    return ['message' => ['required', 'string', 'min:2', 'max:4000']];
}

public function messages(): array
{
    return ['message.required' => 'অনুগ্রহ করে একটি মেসেজ লিখুন।'];
}
```

Controller-এ শুধু type-hint করলেই Laravel নিজে validate করে; fail করলে controller-এ ঢোকেই না, নিজে থেকে ফর্মে ফেরত পাঠায়:

```php
public function store(StoreMessageRequest $request, ?ChatSession $session = null)
{
    // এখানে পৌঁছালে data ১০০% valid
    $this->chat->addUserMessage($session, $request->validated('message'));
}
```

Blade-এ error দেখানো:

```blade
@error('message')
    <p class="flash error">{{ $message }}</p>
@enderror
```

---

## 7. Service Pattern — OpenAIService

[app/Services/OpenAIService.php](app/Services/OpenAIService.php) — পুরো অ্যাপে **এই একটা ফাইলই** OpenAI-র সাথে কথা বলে।

```php
public function reply(array $history): string
{
    try {
        $response = OpenAI::chat()->create($this->payload($history));
    } catch (Throwable $e) {
        throw $this->fail($e);   // নোংরা SDK exception ভেতরেই আটকে যায়
    }

    return trim($response->choices[0]->message->content ?? '');
}
```

তিনটা জিনিস শিখুন:

**(ক) System prompt সবসময় ভেতরে বসে**

```php
protected function payload(array $history): array
{
    return [
        'model' => config('chat.model'),
        'messages' => [
            ['role' => 'system', 'content' => config('chat.system_prompt')],
            ...$history,
        ],
    ];
}
```

`payload()` private, তাই কোনো caller system prompt দিতে **ভুলতে পারে না**।

**(খ) Config, hard-code নয়**

মডেলের নাম কোডে লিখলে বদলাতে হলে কোড খুঁজতে হবে। [config/chat.php](config/chat.php)-এ রাখলে `.env` বদলালেই হল:

```php
'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
```

**(গ) প্রোভাইডার বদলানো — একটাও কোড না ছুঁয়ে**

Groq, Google Gemini, Ollama — সবাই OpenAI-র API ফরম্যাট মানে। তাই শুধু ঠিকানা বদলে দিলেই চলে। `.env`-এ তিনটা preset কমেন্ট করা আছে, যেটা চান সেটার `#` তুলে দিন:

```env
# Groq (ফ্রি, কার্ড লাগে না)
OPENAI_API_KEY=gsk_...
OPENAI_BASE_URL=api.groq.com/openai/v1
OPENAI_MODEL=llama-3.3-70b-versatile
CHAT_FAKE=false
```

তারপর `php artisan config:clear` — ব্যস।

> এটাই service pattern-এর আসল পুরস্কার। Controller-এ সরাসরি `OpenAI::chat()` লিখলে আজ প্রতিটা জায়গায় হাত দিতে হত। এখন **শূন্য লাইন** PHP বদলাল।

⚠️ `.env`-এ একই key দুবার লিখবেন না — **শেষ লাইনটাই জেতে**। নিচে একটা ফাঁকা `OPENAI_API_KEY=` থাকলে সেটা উপরের আসল key-কে চুপচাপ মুছে দেবে, আর আপনি ভাববেন key কাজ করছে না।

**(ঘ) পরিষ্কার Exception**

বাইরের কেউ যেন `OpenAI\Exceptions\ErrorException` না দেখে — [ChatServiceException](app/Exceptions/ChatServiceException.php) দিয়ে ঢেকে দিই। আসল error `Log::error()`-এ যায় (developer-এর জন্য), user দেখে ভদ্র একটা বাক্য।

---

## 8. Conversation Memory — AI আসলে কিছুই মনে রাখে না

**সবচেয়ে বড় ভুল ধারণা:** অনেকে ভাবে OpenAI আগের কথা মনে রাখে। **রাখে না।** প্রতিটা API call সম্পূর্ণ নতুন — মডেল আপনাকে চেনেই না।

তাহলে ChatGPT মনে রাখে কীভাবে? প্রতিবার **পুরনো সব মেসেজ আবার পাঠিয়ে**। "Memory" মানে শুধু এটুকুই। [ChatService.php](app/Services/ChatService.php):

```php
protected function history(ChatSession $session): array
{
    return $session->messages()
        ->latest('id')                        // নতুনগুলো আগে...
        ->limit(config('chat.history_limit')) // ...তাই limit সাম্প্রতিক মেসেজ রাখে
        ->get()
        ->reverse()                           // তারপর আবার পুরনো→নতুন ক্রমে
        ->map(fn (Message $m) => ['role' => $m->role, 'content' => $m->content])
        ->values()
        ->all();
}
```

OpenAI-তে যা যায়:

```php
[
    ['role' => 'system',    'content' => 'You are a helpful assistant...'],
    ['role' => 'user',      'content' => 'আমার নাম আরিফ'],
    ['role' => 'assistant', 'content' => 'হ্যালো আরিফ!'],
    ['role' => 'user',      'content' => 'আমার নাম কী?'],   // ◀ নতুন প্রশ্ন
]
```

মডেল উপরের লাইনগুলো পড়েই "জানে" আপনার নাম আরিফ। এই `history()` method মুছে দিলে bot প্রতিবার আপনাকে ভুলে যাবে।

**`latest()` → `limit()` → `reverse()` কেন?** সরাসরি `oldest()->limit(20)` করলে সবচেয়ে **পুরনো** ২০টা আসত — অর্থাৎ bot শুধু কথোপকথনের শুরুটা মনে রাখত, সাম্প্রতিক কথা ভুলে যেত। তাই আগে নতুন ২০টা নিই, তারপর উল্টে দিই।

**খরচের হিসাব:** history যত বড়, token তত বেশি, বিল তত বেশি। তাই `OPENAI_HISTORY_LIMIT=20`।

---

## 9. Streaming (SSE) — ChatGPT-র মত শব্দে শব্দে

### সমস্যা

সাধারণ ফর্ম POST করলে ব্রাউজার ১০ সেকেন্ড ঘোরে, তারপর পুরো উত্তর একসাথে আসে। খারাপ অভিজ্ঞতা।

### সমাধান: দুটো আলাদা request

আমাদের `store()` **AI-র জন্য অপেক্ষা করে না**:

```php
public function store(StoreMessageRequest $request, ?ChatSession $session = null): RedirectResponse
{
    $session ??= $this->chat->startSession();
    $this->chat->addUserMessage($session, $request->validated('message'));

    return redirect()->route('chat.show', $session);   // সাথে সাথে redirect
}
```

পেজ লোড হয়ে user-এর মেসেজ দেখায়, আর নিচে **খালি একটা AI bubble** — [chat/index.blade.php](resources/views/chat/index.blade.php):

```blade
@if ($session?->awaitsReply())
    <div class="message assistant" id="pending" data-stream-url="{{ route('chat.stream', $session) }}">
        ...
    </div>
@endif
```

JavaScript ওই `data-stream-url` দেখে stream খোলে — [public/js/chat.js](public/js/chat.js):

```js
const source = new EventSource(pending.dataset.streamUrl);

source.addEventListener('delta', (event) => {
    text.textContent += JSON.parse(event.data).text;   // শব্দ যোগ হতে থাকে
});

source.addEventListener('done', () => {
    source.close();
    window.location.reload();   // এবার server সুন্দর Markdown render করে দেবে
});
```

### Server-এর দিকে

[ChatController::stream()](app/Http/Controllers/ChatController.php):

```php
public function stream(ChatSession $session): StreamedResponse
{
    abort_unless($session->awaitsReply(), 409, 'This conversation is not waiting for a reply.');

    return response()->stream(function () use ($session) {
        try {
            foreach ($this->chat->streamReply($session) as $chunk) {
                $this->emit('delta', ['text' => $chunk]);
            }
            $this->emit('done', []);
        } catch (ChatServiceException $e) {
            $this->emit('failed', ['message' => $e->getMessage()]);
        }
    }, 200, [
        'Content-Type' => 'text/event-stream',
        'Cache-Control' => 'no-cache',
        'X-Accel-Buffering' => 'no',
    ]);
}
```

আর `emit()`-এ সবচেয়ে জরুরি লাইন:

```php
echo "event: {$event}\n";
echo 'data: '.json_encode($data)."\n\n";   // ◀ SSE ফরম্যাট: দুটো newline লাগবেই

if (ob_get_level() > 0) { ob_flush(); }
flush();                                    // ◀ এটা না দিলে PHP সব জমিয়ে রাখবে
```

**`flush()` ছাড়া streaming হয় না** — PHP পুরো উত্তর buffer করে শেষে একসাথে পাঠাবে, তখন আর streaming-এর কোনো মানেই থাকে না।

### তিনটা সূক্ষ্ম কিন্তু জরুরি সিদ্ধান্ত

**১. `abort_unless($session->awaitsReply(), 409)` — duplicate উত্তর ঠেকায়**
পেজ refresh করলে বা দ্বিতীয় tab খুললে আবার stream শুরু হত, একই প্রশ্নের দুটো উত্তর save হত, দুবার বিল আসত। শেষ মেসেজ user-এর না হলে আমরা সোজা 409 দিই।

**২. উত্তর save হয় stream শেষ হওয়ার পর** — [ChatService::streamReply()](app/Services/ChatService.php):

```php
foreach ($this->openai->streamReply($this->history($session)) as $chunk) {
    $reply .= $chunk;
    yield $chunk;          // ব্রাউজারে পাঠাই
}

if (trim($reply) !== '') {
    $this->storeReply($session, $reply);   // সব শেষে database-এ
}
```

মাঝপথে user tab বন্ধ করলে **অর্ধেক উত্তর save হয় না** — session আবার "awaiting reply" অবস্থায় থাকে, refresh করলে নতুন করে উত্তর তৈরি হয়।

**৩. event-এর নাম `failed`, `error` নয়** — কারণ `EventSource` নিজের network error-এর জন্য `error` নামে event পাঠায়। একই নাম দিলে দুটো মিশে যেত।

### PHP `Generator` — `yield` কী করছে?

`streamReply()` `return` করে না, `yield` করে। মানে function টুকরো টুকরো করে মান ফেরত দেয়, আর caller (`foreach`) প্রতিটা টুকরো সাথে সাথে পায়। পুরো উত্তরের জন্য অপেক্ষা করতে হয় না — এটাই streaming-এর মূল কারিগরি।

---

## 10. Auto Title — চ্যাটের নাম নিজে থেকে

প্রথম উত্তর save হওয়ার পর, title খালি থাকলে AI-কে দিয়েই নাম বানাই — [ChatService::titleSession()](app/Services/ChatService.php):

```php
protected function titleSession(ChatSession $session, string $reply): void
{
    if (filled($session->title)) {
        return;                       // একবারই নাম দেওয়া হয়
    }

    $session->update([
        'title' => $this->openai->generateTitle($firstUserMessage, $reply),
    ]);
}
```

`generateTitle()`-এ আলাদা একটা ছোট prompt (`max_tokens: 20`), আর **fail করলেও চ্যাট ভাঙে না** — প্রথম মেসেজের প্রথম ৪০ অক্ষর নাম হিসেবে বসে:

```php
} catch (Throwable $e) {
    Log::warning('Auto-title failed, falling back to the first message.');
    return $fallback;
}
```

> শেখার পয়েন্ট: **যে ফিচার ছাড়া অ্যাপ চলে, সেটা কখনো অ্যাপ ভাঙতে দেবেন না।** নাম না হলেও চ্যাট কাজ করে — তাই এখানে exception গিলে ফেলাই সঠিক।

---

## 11. Blade + Markdown + Security

### user-এর লেখা কখনো HTML নয়

[message.blade.php](resources/views/chat/partials/message.blade.php):

```blade
{{-- User: escape করা বাধ্যতামূলক --}}
<div class="prose">{!! nl2br(e($message->content)) !!}</div>

{{-- Assistant: Markdown, কিন্তু raw HTML নিষিদ্ধ --}}
<div class="prose">
    {!! Str::markdown($message->content, [
        'html_input' => 'escape',
        'allow_unsafe_links' => false,
    ]) !!}
</div>
```

- `e()` — user যদি `<script>alert(1)</script>` পাঠায়, সেটা **লেখা হিসেবেই** দেখাবে, চলবে না। (পরীক্ষা করে দেখা হয়েছে ✔)
- `nl2br()` শুধু `e()`-র তৈরি নিরাপদ output-এর newline-কে `<br>` বানায়।
- `'html_input' => 'escape'` — **মডেলের উত্তরকেও বিশ্বাস করি না।** AI যদি `<img onerror=...>` লিখে বসে, সেটাও শুধু লেখা হয়ে থাকবে।

> নিয়ম: `{{ }}` সবসময় নিরাপদ। `{!! !!}` তখনই, যখন আপনি নিজে নিশ্চিত করেছেন ভেতরের জিনিস নিরাপদ।

### বাকি নিরাপত্তা

| বিষয় | কোথায় |
|---|---|
| CSRF | প্রতিটা form-এ `@csrf` |
| Rate limit | `throttle:30,1` — বিল বাঁচায় |
| Validation | `StoreMessageRequest` (min 2, max 4000) |
| API key | শুধু `.env`-এ, কোডে কখনো নয় (`.env` git-ignored) |
| SQL injection | Eloquent নিজেই bind করে |

---

## 12. ফাইল কোথায় কী

```
app/
├── Exceptions/ChatServiceException.php   ← "AI ব্যর্থ" — এক ধরনের error
├── Http/
│   ├── Controllers/ChatController.php    ← শুধু HTTP
│   └── Requests/
│       ├── StoreMessageRequest.php       ← মেসেজের validation
│       └── UpdateChatSessionRequest.php  ← rename-এর validation
├── Models/
│   ├── ChatSession.php                   ← একটা কথোপকথন
│   └── Message.php                       ← একটা লাইন (user/assistant)
└── Services/
    ├── ChatService.php                   ← workflow: save, history, title
    └── OpenAIService.php                 ← শুধু OpenAI API

config/chat.php                           ← model, temperature, history limit
resources/views/
├── layouts/app.blade.php
└── chat/
    ├── index.blade.php
    └── partials/  (sidebar, header, message, composer, empty)
public/css/chat.css, public/js/chat.js    ← build ছাড়াই চলে
```

---

## 13. যেসব ভুল সবাই করে (এবং এখানে করা হয়নি)

1. **Controller থেকে সরাসরি `OpenAI::chat()`** — service ছাড়া কোড ছড়িয়ে যায়, test করা যায় না।
2. **History না পাঠিয়ে bot "memory" আশা করা** — মডেল কিছুই মনে রাখে না (§8)।
3. **Rate limit না দেওয়া** — একটা infinite loop = বিশাল বিল।
4. **`{!! $userInput !!}`** — সরাসরি XSS।
5. **Streaming-এর মাঝপথে database-এ লেখা** — user tab বন্ধ করলে অর্ধেক উত্তর জমে থাকে।
6. **Refresh-এ আবার উত্তর generate** — `awaitsReply()` guard না থাকলে ঠিক এটাই হত।
7. **`flush()` ভুলে যাওয়া** — streaming কোড লিখেও streaming হয় না।

---

## 13.5 "The AI could not answer right now" — এলে কী করবেন

এই বার্তাটা ইচ্ছা করেই অস্পষ্ট (user-কে technical detail দেখাই না)। **আসল কারণ বের করার ৩টা ধাপ:**

**ধাপ ১ — SDK কী exception দিল, দেখুন:**

```bash
php artisan tinker --execute="
try {
    OpenAI\Laravel\Facades\OpenAI::chat()->create(['model'=>config('chat.model'),'messages'=>[['role'=>'user','content'=>'hi']]]);
    echo 'SUCCESS';
} catch (Throwable \$e) { echo \$e::class . ': ' . \$e->getMessage(); }
"
```

**ধাপ ২ — SDK-র বার্তা প্রায়ই অসম্পূর্ণ। প্রোভাইডারের কাঁচা উত্তর দেখুন:**

```bash
curl -s https://generativelanguage.googleapis.com/v1beta/openai/chat/completions \
  -H "Authorization: Bearer আপনার-key" -H "Content-Type: application/json" \
  -d '{"model":"gemini-flash-latest","messages":[{"role":"user","content":"hi"}]}'
```

এখানেই আসল উত্তর লেখা থাকে। যেমন আমাদের ক্ষেত্রে SDK শুধু বলছিল *"Request rate limit has been exceeded"*, কিন্তু কাঁচা JSON-এ ছিল আসল কথা:

```
"limit: 0, model: gemini-2.0-flash"
```

অর্থাৎ rate limit শেষ হয়নি — ওই মডেলের **ফ্রি কোটাই শূন্য**। মডেল বদলাতেই সব ঠিক।

**ধাপ ৩ — সাধারণ error-গুলোর মানে:**

| HTTP | মানে | সমাধান |
|---|---|---|
| **401** | key ভুল বা `.env` load হয়নি | `php artisan config:clear` |
| **404** | মডেলের নাম ভুল / মডেল বন্ধ | `*-latest` নাম ব্যবহার করুন |
| **429** + `limit: 0` | ওই মডেলে ফ্রি কোটা নেই | অন্য মডেল বা ক্রেডিট যোগ করুন |
| **429** + `insufficient_quota` | অ্যাকাউন্টে টাকা নেই | billing-এ ক্রেডিট দিন |

> শিক্ষা: **মডেলের নাম hard-code করবেন না।** `config/chat.php`-এ `env()` দিয়ে রাখায় প্রোভাইডার একটা মডেল বন্ধ করে দিলেও শুধু `.env`-এর এক লাইন বদলাতে হয়।

---

## 14. নিজে হাত চালান (অনুশীলন)

সহজ থেকে কঠিন:

1. `config/chat.php`-এ `system_prompt` বদলে bot-কে বাংলায় উত্তর দিতে বলুন।
2. `OPENAI_HISTORY_LIMIT=2` করুন। এখন bot নাম ভুলে যাবে — memory যে সত্যিই history পাঠানোর ফল, নিজের চোখে দেখুন।
3. মেসেজ delete করার ফিচার যোগ করুন (route → controller → service → blade)।
4. `messages` টেবিলে `tokens` কলাম যোগ করে API-র `usage` save করুন, প্রতি চ্যাটে কত খরচ হচ্ছে দেখান।
5. Auto-title-কে queue-তে পাঠান (`php artisan queue:work`) — তাহলে উত্তরের পর title-এর জন্য কেউ অপেক্ষা করবে না।
6. একটা Feature test লিখুন: মেসেজ পাঠালে database-এ user row বসে কি না (`OpenAIService`-কে mock করে)।

---

## 15. Production-এ নেওয়ার আগে

- [ ] `.env`-এ আসল `OPENAI_API_KEY`, আর `APP_DEBUG=false`
- [ ] Login/auth যোগ করে `chat_sessions`-এ `user_id` বসান — এখন যে কেউ সব চ্যাট দেখতে পায়
- [ ] nginx ব্যবহার করলে SSE route-এ buffering বন্ধ (কোডে `X-Accel-Buffering: no` আছে, তবু server config দেখে নিন)
- [ ] `php artisan config:cache route:cache view:cache`
- [ ] দীর্ঘ কাজ (auto-title, summary) queue-তে

---

### এক লাইনে সারমর্ম

> **AI-র কোনো memory নেই। Chatbot বানানো মানে — কথোপকথন database-এ জমানো, প্রতিবার সেটা আবার পাঠানো, আর উত্তরটা ভদ্রভাবে দেখানো।** বাকি সব (streaming, title, markdown) কেবল সাজসজ্জা।
