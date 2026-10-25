<?php

namespace App\Http\Controllers\Api;

use App\Enums\ScheduledMessageStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreScheduledMessageRequest;
use App\Http\Requests\UpdateScheduledMessageRequest;
use App\Http\Resources\ScheduledMessageResource;
use App\Models\Conversation;
use App\Models\ScheduledMessage;
use App\Services\ScheduledMessageService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class ScheduledMessageController extends Controller
{
    /** Los fallidos se siguen mostrando un tiempo para poder reprogramarlos. */
    private const FAILED_VISIBLE_DAYS = 7;

    public function __construct(private ScheduledMessageService $service) {}

    public function index(Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $scheduledMessages = ScheduledMessage::query()
            ->with('user:id,name')
            ->where('conversation_id', $conversation->id)
            ->where(fn ($query) => $query->pending()->orWhere(fn ($failed) => $failed
                ->where('status', ScheduledMessageStatus::Failed)
                // updated_at es timestamptz: comparar en UTC. Ver HasTimezoneAwareDates.
                ->where('updated_at', '>=', now()->utc()->subDays(self::FAILED_VISIBLE_DAYS))))
            ->orderBy('scheduled_for')
            ->get();

        return response()->json([
            'data' => ScheduledMessageResource::collection($scheduledMessages)->resolve(),
            'meta' => [
                'window_expires_at' => $this->service->windowExpiresAt($conversation->loadMissing('channel'))?->toIso8601String(),
            ],
        ]);
    }

    public function store(StoreScheduledMessageRequest $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('sendMessage', $conversation);

        $data = $request->validated();
        $file = $data['type'] === 'text' ? null : $request->file($data['type']);

        return $this->respond(fn () => new ScheduledMessageResource(
            $this->service->schedule($conversation->loadMissing('channel'), $request->user(), $data, $file)
        ), 201);
    }

    public function update(UpdateScheduledMessageRequest $request, ScheduledMessage $scheduledMessage): JsonResponse
    {
        $this->authorize('update', $scheduledMessage);

        return $this->respond(fn () => new ScheduledMessageResource(
            $this->service->reschedule($scheduledMessage, $request->validated())
        ));
    }

    public function destroy(ScheduledMessage $scheduledMessage): JsonResponse
    {
        $this->authorize('delete', $scheduledMessage);

        return $this->respond(function () use ($scheduledMessage) {
            $this->service->cancel($scheduledMessage);

            return null;
        }, 204);
    }

    /**
     * @param  callable(): (ScheduledMessageResource|null)  $action
     */
    private function respond(callable $action, int $status = 200): JsonResponse
    {
        try {
            $resource = $action();
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($resource === null ? null : ['data' => $resource->resolve()], $status);
    }
}
