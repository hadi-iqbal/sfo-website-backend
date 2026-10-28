<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Lead;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email'],
            'phone' => ['required', 'string', 'max:40'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $conversation = Conversation::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'status' => 'new',
            'last_message_at' => now(),
        ]);

        $conversation->messages()->create([
            'sender' => 'visitor',
            'body' => $data['message'],
        ]);

        $reply = config('sfo.chat_acknowledgment');

        $conversation->messages()->create([
            'sender' => 'system',
            'body' => $reply,
        ]);

        Lead::create([
            'type' => 'chat',
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'message' => $data['message'],
            'payload' => ['conversation_id' => $conversation->id],
        ]);

        return response()->json([
            'conversation_id' => $conversation->id,
            'reply' => $reply,
        ], 201);
    }

    public function index()
    {
        $conversations = Conversation::query()
            ->with(['messages' => fn ($query) => $query->latest()->limit(1)])
            ->latest('last_message_at')
            ->get()
            ->map(function (Conversation $conversation) {
                return [
                    'id' => $conversation->id,
                    'name' => $conversation->name,
                    'email' => $conversation->email,
                    'phone' => $conversation->phone,
                    'status' => $conversation->status,
                    'last_message_at' => $conversation->last_message_at,
                    'preview' => $conversation->messages->first()?->body,
                ];
            });

        return response()->json([
            'stats' => [
                'conversations' => Conversation::count(),
                'new' => Conversation::where('status', 'new')->count(),
            ],
            'data' => $conversations,
        ]);
    }

    public function show(Conversation $conversation)
    {
        $conversation->load('messages');

        return response()->json([
            'data' => [
                'id' => $conversation->id,
                'name' => $conversation->name,
                'email' => $conversation->email,
                'phone' => $conversation->phone,
                'status' => $conversation->status,
                'messages' => $conversation->messages,
            ],
        ]);
    }

    public function update(Request $request, Conversation $conversation)
    {
        $data = $request->validate([
            'status' => ['required', 'in:new,reviewed,closed'],
        ]);

        $conversation->update($data);

        return response()->json(['data' => $conversation]);
    }
}
