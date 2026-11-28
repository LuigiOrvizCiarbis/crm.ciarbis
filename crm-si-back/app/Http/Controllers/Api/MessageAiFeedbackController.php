<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AiMessageFeedbackRequest;
use App\Jobs\SendLangfuseScoreJob;
use App\Models\AiMessageFeedback;
use App\Models\Message;
use Illuminate\Http\Response;

/**
 * Valoración 👍/👎 del operador sobre un mensaje generado por la IA; se envía a Langfuse como score.
 */
class MessageAiFeedbackController extends Controller
{
    public function __invoke(AiMessageFeedbackRequest $request, Message $message): Response
    {
        $this->authorize('view', $message);

        if ($message->ai_trace_id === null) {
            abort(422, 'Este mensaje no fue generado por la IA.');
        }

        $userId = $request->user()->id;

        $feedback = AiMessageFeedback::query()->updateOrCreate(
            ['user_id' => $userId, 'message_id' => $message->id],
            ['tenant_id' => $message->tenant_id, 'rating' => (int) $request->validated('rating')],
        );

        SendLangfuseScoreJob::dispatch($feedback, 'ai-msg-'.$message->id, $message->ai_trace_id, $message->ai_observation_id);

        return response()->noContent();
    }
}
