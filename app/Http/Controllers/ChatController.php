<?php

namespace App\Http\Controllers;

use App\Exceptions\ChatServiceException;
use App\Http\Requests\StoreMessageRequest;
use App\Http\Requests\UpdateChatSessionRequest;
use App\Models\ChatSession;
use App\Services\ChatService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * HTTP only. Every action reads the request, calls one service method, and
 * returns a response -- there is no OpenAI code and no business rule here.
 */
class ChatController extends Controller
{
    public function __construct(
        protected ChatService $chat,
    ) {}

    /**
     * The empty state: sidebar, no conversation open.
     */
    public function index(Request $request): View
    {
        return $this->page($request, session: null);
    }

    public function show(Request $request, ChatSession $session): View
    {
        $session->load('messages');

        return $this->page($request, $session);
    }

    /**
     * Send a message.
     *
     * Note what this does NOT do: it does not wait for the AI. It stores the
     * user's message and redirects. The page then opens a stream (see stream()
     * below) which is what produces the answer. That keeps this request fast
     * and means a refresh never re-sends anything.
     */
    public function store(StoreMessageRequest $request, ?ChatSession $session = null): RedirectResponse
    {
        $session ??= $this->chat->startSession();

        $this->chat->addUserMessage($session, $request->validated('message'));

        return redirect()->route('chat.show', $session);
    }

    /**
     * Stream the assistant's reply over Server-Sent Events.
     *
     * The guard matters: a reply is only generated when the newest message in
     * the session came from the user. Refreshing the page, or opening it in a
     * second tab, therefore cannot produce a duplicate answer.
     */
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
                // A clean, already-user-safe message. The real error was logged
                // inside the service. The event is called 'failed', not 'error',
                // because EventSource already dispatches its own 'error' event
                // for network problems and the two would collide.
                $this->emit('failed', ['message' => $e->getMessage()]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no', // tell nginx not to buffer the stream
        ]);
    }

    /**
     * The no-JavaScript fallback: generate the reply in one blocking request.
     */
    public function reply(ChatSession $session): RedirectResponse
    {
        if (! $session->awaitsReply()) {
            return redirect()->route('chat.show', $session);
        }

        try {
            $this->chat->generateReply($session);
        } catch (ChatServiceException $e) {
            return redirect()->route('chat.show', $session)->with('error', $e->getMessage());
        }

        return redirect()->route('chat.show', $session);
    }

    public function update(UpdateChatSessionRequest $request, ChatSession $session): RedirectResponse
    {
        $session->update($request->validated());

        return redirect()->route('chat.show', $session)->with('success', 'চ্যাটের নাম পরিবর্তন হয়েছে।');
    }

    public function destroy(ChatSession $session): RedirectResponse
    {
        $session->delete(); // messages go with it (cascade on delete)

        return redirect()->route('chat.index')->with('success', 'চ্যাট মুছে ফেলা হয়েছে।');
    }

    /**
     * Both index() and show() render the same screen; only the open
     * conversation differs.
     */
    protected function page(Request $request, ?ChatSession $session): View
    {
        $search = $request->string('q')->toString();

        $sessions = ChatSession::query()
            ->search($search)
            ->latest('updated_at')
            ->get();

        return view('chat.index', [
            'sessions' => $sessions,
            'session' => $session,
            'search' => $search,
        ]);
    }

    /**
     * Write one Server-Sent Event to the open connection and push it out
     * immediately -- without the flush, PHP would buffer the whole reply and
     * the user would see nothing until the end, defeating the point.
     *
     * @param  array<string, mixed>  $data
     */
    protected function emit(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo 'data: '.json_encode($data)."\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}
