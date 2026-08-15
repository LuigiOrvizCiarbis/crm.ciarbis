<?php

namespace App\Services;

use App\Enums\BroadcastRecipientStatus;
use App\Enums\BroadcastStatus;
use App\Jobs\SendBroadcastMessageJob;
use App\Models\BroadcastCampaign;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

            // Entre que se programó la campaña y este momento pueden haber
            // pasado días, y Meta pausa o deshabilita plantillas por su cuenta.
            // Se corta acá: sin esta guarda cada job fallaría por separado y
            // marcaría miles de destinatarios como error uno por uno.
            $template = $locked->template()->withoutGlobalScopes()->first();

            if (! $template?->status->isApproved()) {
                Log::warning('BroadcastDispatcher: plantilla no enviable al momento del disparo', [
                    'campaign_id' => $locked->id,
                    'tenant_id' => $locked->tenant_id,
                    'template_id' => $locked->whatsapp_template_id,
                    'template_status' => $template?->status->value,
                ]);

                $locked->update([
                    'status' => BroadcastStatus::Failed,
                    'started_at' => now(),
                    'completed_at' => now(),
                ]);

                $locked->recipients()
                    ->where('status', BroadcastRecipientStatus::Pending)
                    ->update([
                        'status' => BroadcastRecipientStatus::Failed,
                        'error' => $template === null
                            ? 'La plantilla ya no existe.'
                            : 'Meta dejó de permitir esta plantilla ('.$template->status->label().') antes del envío.',
                    ]);

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
