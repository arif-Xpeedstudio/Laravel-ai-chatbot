<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Chat;

class ChatController extends Controller
{
    public function index()
    {
        $chats = Chat::latest()->get();

        return view('chat', compact('chats'));
    }

    public function chat(Request $request)
    {
        $chatData = $request->validate(
            [
                'message' => 'required|string|min:5|max:255',
            ],
            [
                'message.required' => 'অনুগ্রহ করে একটি মেসেজ লিখুন।',
                'message.min' => 'মেসেজটি কমপক্ষে ৫ অক্ষরের হতে হবে।',
                'message.max' => 'মেসেজটি ২৫৫ অক্ষরের বেশি হতে পারবে না।',
            ]
        );

        // Database-এ Save
        Chat::create([
            'message' => $chatData['message'],
        ]);

        // আবার সব Chat আনো
        $chats = Chat::latest()->get();

        // View-তে Data পাঠাও
        return view('chat', [
            'chats' => $chats,
            'foo'    => $chatData['message'],
            'loo'    => strlen($chatData['message']),
        ]);
    }
}