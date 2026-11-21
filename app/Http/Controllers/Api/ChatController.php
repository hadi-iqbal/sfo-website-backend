<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChatController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email'],
            'phone' => ['required', 'string', 'max:40'],
            'message' => ['required', 'string', 'max:5000'],
            'track' => ['nullable', 'in:life,pnc,general'],
            'request_kind' => ['nullable', 'in:contact,quote,rate,schedule'],
        ]);

        return DB::transaction(function () use ($data) {
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
                'track' => ($data['track'] ?? null) === 'general' ? null : ($data['track'] ?? null),
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'message' => $data['message'],
                'payload' => ['conversation_id' => $conversation->id, 'request_kind' => $data['request_kind'] ?? null],
            ]);

            return response()->json([
                'conversation_id' => $conversation->id,
                'reply' => $reply,
            ], 201);
        });
    }

    private function visibleConversations(Request $request)
    {
        $query = Conversation::query();
        $tracks = (new Lead)->restrictedTracks($request->user());
        if ($tracks) {
            $query->whereIn('id', Lead::where('type', 'chat')->whereIn('track', $tracks)
                ->select('payload->conversation_id'));
        }
        if (!$request->user()->is_super_admin) {
            $query->where(function ($scope) use ($request) {
                $scope->whereNull('visitor_token_hash')->orWhereIn('id', Lead::where('type', 'chat')
                    ->where('owner_id', $request->user()->id)->select('payload->conversation_id'));
            });
        }
        return $query;
    }

    public function index(Request $request)
    {
        $query = $this->visibleConversations($request);
        $conversations = (clone $query)
            ->with(['messages' => fn ($query) => $query->where('sender', 'visitor')->latest()->limit(1)])
            ->latest('last_message_at')
            ->get();
        $ids = $conversations->pluck('id');
        $meetings = DB::table('nirvana_meetings')->whereIn('conversation_id', $ids)
            ->orderByDesc('starts_at')->get(['id', 'conversation_id', 'starts_at', 'status', 'join_url', 'visitor_timezone', 'visitor_state', 'visitor_zip'])
            ->unique('conversation_id')->keyBy('conversation_id');
        $rates = DB::table('nirvana_rate_requests')->whereIn('conversation_id', $ids)
            ->orderByDesc('id')->get(['id', 'conversation_id', 'result', 'emailed_at'])
            ->unique('conversation_id')->keyBy('conversation_id');
        $conversations = $conversations->map(function (Conversation $conversation) use ($meetings, $rates) {
                return [
                    'id' => $conversation->id,
                    'name' => $conversation->name,
                    'email' => $conversation->email,
                    'phone' => $conversation->phone,
                    'status' => $conversation->status,
                    'last_message_at' => $conversation->last_message_at,
                    'preview' => $conversation->messages->first()?->body,
                    'chat_brief' => $conversation->chat_brief,
                    'meeting' => $meetings->get($conversation->id),
                    'rate_guide' => $rates->get($conversation->id),
                ];
            });

        return response()->json([
            'stats' => [
                'conversations' => (clone $query)->count(),
                'new' => $query->where('status', 'new')->count(),
            ],
            'data' => $conversations,
        ]);
    }

    public function show(Request $request, Conversation $conversation)
    {
        abort_unless($this->visibleConversations($request)->whereKey($conversation->id)->exists(), 403);
        $conversation->load('messages');

        return response()->json([
            'data' => [
                'id' => $conversation->id,
                'name' => $conversation->name,
                'email' => $conversation->email,
                'phone' => $conversation->phone,
                'status' => $conversation->status,
                'messages' => $conversation->messages,
                'chat_brief' => $conversation->chat_brief,
                'nirvana_summary' => $conversation->nirvana_summary,
                'timeline' => NirvanaController::snapshot($conversation)['timeline'],
                'rate_guides' => DB::table('nirvana_rate_requests')->where('conversation_id', $conversation->id)
                    ->orderByDesc('id')->get()->map(fn ($rate) => [
                        'id' => $rate->id, 'answers' => \Illuminate\Support\Facades\Crypt::decryptString($rate->answers),
                        'result' => $rate->result, 'emailed_at' => $rate->emailed_at,
                    ]),
                'meetings' => DB::table('nirvana_meetings')->where('conversation_id', $conversation->id)
                    ->orderByDesc('starts_at')->get(['id', 'starts_at', 'status', 'join_url', 'visitor_timezone', 'visitor_state', 'visitor_zip', 'recap']),
            ],
        ]);
    }

    public function update(Request $request, Conversation $conversation)
    {
        abort_unless($this->visibleConversations($request)->whereKey($conversation->id)->exists(), 403);
        $data = $request->validate([
            'status' => ['required', 'in:new,reviewed,closed'],
        ]);

        $conversation->update($data);

        return response()->json(['data' => $conversation]);
    }
}
