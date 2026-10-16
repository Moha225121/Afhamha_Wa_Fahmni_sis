<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Services\SchoolChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SchoolChatController extends Controller
{
    public function __construct(private readonly SchoolChatService $chat) {}

    public function index(Request $request): View
    {
        return view('shared.chat.inbox', ['layout' => $request->user()->role.'.layout',
            'conversations' => $this->chat->inbox($request->user())->get()]);
    }

    public function show(Request $request, Conversation $conversation): View
    {
        $this->chat->authorize($request->user(), $conversation);
        $conversation->load(['student.user', 'participants']);
        $messages = $conversation->messages()->with('sender')->latest('id')->limit(50)->get()->reverse()->values();
        $this->chat->markRead($request->user(), $conversation);

        return view('shared.chat.thread', ['conversation' => $conversation, 'messages' => $messages,
            'layout' => $request->user()->role.'.layout', 'routePrefix' => $request->user()->role,
            'hasOlderMessages' => $messages->isNotEmpty() && $conversation->messages()->where('id', '<', $messages->first()->id)->exists()]);
    }

    public function storeMessage(Request $request, Conversation $conversation): RedirectResponse|JsonResponse
    {
        $this->chat->authorize($request->user(), $conversation);
        $validated = $request->validate(['body' => ['required', 'string', 'max:3000']]);
        $message = $this->chat->send($request->user(), $conversation, $validated['body']);

        return $request->expectsJson()
            ? response()->json(['message' => $this->chat->serialize($message, $request->user())], 201)
            : redirect()->to($this->chat->destination($request->user(), $conversation).'#chat-bottom');
    }

    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $this->chat->authorize($request->user(), $conversation);
        $filters = $request->validate(['after' => ['nullable', 'integer', 'min:0'], 'before' => ['nullable', 'integer', 'min:1']]);
        $before = $filters['before'] ?? null;
        $query = $conversation->messages()->with('sender')
            ->when($before, fn ($items) => $items->where('id', '<', $before))
            ->when(! $before, fn ($items) => $items->where('id', '>', $filters['after'] ?? 0));
        $messages = $before ? $query->latest('id')->limit(50)->get()->reverse()->values() : $query->orderBy('id')->limit(100)->get();
        if (! $before && $request->boolean('read')) {
            $this->chat->markRead($request->user(), $conversation);
        }

        return response()->json(['messages' => $messages->map(fn ($message) => $this->chat->serialize($message, $request->user())),
            'status' => $conversation->status,
            'has_older' => $messages->isNotEmpty() && $conversation->messages()->where('id', '<', $messages->first()->id)->exists(),
        ])->header('Cache-Control', 'private, no-store');
    }
}
