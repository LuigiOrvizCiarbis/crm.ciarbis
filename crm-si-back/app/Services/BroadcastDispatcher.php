<?php

namespace App\Services;

use App\Enums\BroadcastRecipientStatus;
use App\Enums\BroadcastStatus;
use App\Jobs\SendBroadcastMessageJob;
use App\Models\BroadcastCampaign;
use Illuminate\Support\Facades\DB;

class BroadcastDispatcher
{
    public function dispatch(BroadcastCampaign $campaign): int
    {
        $recipientIds = DB::transaction(function () use ($campaign): array {
            $locked = BroadcastCampaign::withoutGlobalScopes()
                ->whereKey($campaign->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== BroadcastStatus::Scheduled || $locked->scheduled_at->isFuture()) {
                return [];
            }

            $recipients = $locked->recipients()
                ->where('status', BroadcastRecipientStatus::Pending)
                ->orderBy('id')
                ->get(['id', 'conversation_id']);

            $locked->update([
                'status' => BroadcastStatus::Processing,
                'started_at' => now(),
            ]);

            $locked->recipients()
                ->whereKey($recipients->modelKeys())
                ->update([
                    'status' => BroadcastRecipientStatus::Queued,
                    'queued_at' => now(),
                ]);

            return $recipients->map(fn ($recipient): array => [
                'id' => $recipient->id,
                'conversation_id' => $recipient->conversation_id,
            ])->all();
        });

        foreach ($recipientIds as $index => $recipient) {
            $job = SendBroadcastMessageJob::dispatch(
                $recipient['conversation_id'],
                $campaign->whatsapp_template_id,
                $campaign->components,
                $campaign->created_by,
                $campaign->tenant_id,
                $recipient['id'],
            );

            if ($campaign->interval_seconds > 0) {
                $job->delay(now()->addSeconds($index * $campaign->interval_seconds));
            }
        }

        return count($recipientIds);
    }
}
