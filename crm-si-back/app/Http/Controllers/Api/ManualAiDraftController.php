<?php

namespace App\Http\Controllers\Api;

use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Events\ManualAiDraftUpdated;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateManualAiDraftJob;
use App\Models\AiConfig;
use App\Models\Conversation;
use App\Models\ManualAiDraft;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\JsonResponse;

class ManualAiDraftController extends Controller
{
    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('sendMessage', $conversation);
        $draft = $this->activeDraft($request, $conversation);
        return response()->json(['data' => $draft?->payload()]);
    }

    public function store(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('sendMessage', $conversation);
        if ($conversation->ai_autoreply_enabled) return $this->error('autoreply_enabled', 409);

        $validated = $request->validate(['source_message_id' => ['required', 'integer']]);
        $source = $conversation->messages()->withoutTrashed()->whereKey($validated['source_message_id'])->first();
        if (! $source || $source->direction !== MessageDirection::INBOUND || ! in_array($source->message_type, [MessageType::Text, MessageType::Image], true)) {
            return $this->error('unsupported_source', 422);
        }

        $latest = $conversation->messages()->withoutTrashed()->latest('created_at')->latest('id')->first();
        if (! $latest || $latest->id !== $source->id) return $this->error('stale_source', 409);
        $config = AiConfig::where('tenant_id', $conversation->tenant_id)->first();
        if (! $config?->enabled || ! $config->getDecryptedApiKey()) return $this->error('ai_unavailable', 422);

        $key = 'manual-ai-draft:count:'.$request->user()->id;
        Cache::add($key, 0, now()->addMinute());
        $count = (int) Cache::increment($key);
        if ($count > 5) return $this->error('rate_limited', 429);

        ManualAiDraft::where('conversation_id', $conversation->id)->where('user_id', $request->user()->id)->whereIn('status', ['pending', 'ready'])->update(['status' => 'cancelled']);
        $draft = ManualAiDraft::create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'user_id' => $request->user()->id,
            'source_message_id' => $source->id,
            'status' => 'pending',
            'expires_at' => now()->addDay(),
        ]);
        GenerateManualAiDraftJob::dispatch($draft->id);
        broadcast(new ManualAiDraftUpdated($draft));
        return response()->json(['data' => $draft->payload()], 202);
    }

    public function destroy(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('sendMessage', $conversation);
        $draft = $this->activeDraft($request, $conversation);
        if ($draft) {
            $draft->update(['status' => 'cancelled']);
            broadcast(new ManualAiDraftUpdated($draft->fresh()));
        }
        return response()->json(['data' => null]);
    }

    private function activeDraft(Request $request, Conversation $conversation): ?ManualAiDraft
    {
        $draft = ManualAiDraft::where('conversation_id', $conversation->id)->where('user_id', $request->user()->id)->whereIn('status', ['pending', 'ready'])->latest('id')->first();
        if ($draft?->expires_at?->isPast()) { $draft->update(['status' => 'expired']); return null; }
        return $draft;
    }

    private function error(string $code, int $status): JsonResponse
    {
        return response()->json(['message' => $code, 'error_code' => $code], $status);
    }
}
