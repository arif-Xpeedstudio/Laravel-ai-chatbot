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
        // $chatData = $request->input('message');
        // return view('chat',
        //  ['message' => $chatData,
        //  'length' => strlen($chatData)
        //  ]);

        $chatData = $request->validate(
            [
                'message' => 'required|string|max:255|min:5',
            ],

            [
                'message.required' => 'অনুগ্রহ করে একটি মেসেজ লিখুন।',
                'message.min' => 'মেসেজটি কমপক্ষে ৫ অক্ষরের হতে হবে।',
                'message.max' => 'মেসেজটি ২৫৫ অক্ষরের বেশি হতে পারবে না।',
            ]
        );


        Chat::create([
            'message' => $chatData['message'],
        ]);

        return view('chat', [
            'foo' => $chatData['message'],
            'loo' => strlen($chatData['message'])
        ]);
    }
}
