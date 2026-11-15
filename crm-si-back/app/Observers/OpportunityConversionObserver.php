<?php

namespace App\Observers;

use App\Jobs\SendMetaConversionEventJob;
use App\Models\MetaConversionEvent;
use App\Models\Opportunity;

/**
 * Despacha los eventos de la Conversions API de Meta: LeadSubmitted al crear
 * una oportunidad desde un chat y Purchase al ganarla. El job decide si el
 * chat vino de un anuncio; volver a ganar no reenvía (fila única por evento).
 */
class OpportunityConversionObserver
{
    public bool $afterCommit = true;

    public function created(Opportunity $opportunity): void
    {
        if (! $this->enabled()) {
            return;
        }

        if ($opportunity->conversation_id) {
            SendMetaConversionEventJob::dispatch($opportunity->id, MetaConversionEvent::LEAD_SUBMITTED);
        }
        if ($opportunity->status === 'won') {
            SendMetaConversionEventJob::dispatch($opportunity->id, MetaConversionEvent::PURCHASE);
        }
    }

    public function updated(Opportunity $opportunity): void
    {
        if ($this->enabled() && $opportunity->wasChanged('status') && $opportunity->status === 'won') {
            SendMetaConversionEventJob::dispatch($opportunity->id, MetaConversionEvent::PURCHASE);
        }
    }

    private function enabled(): bool
    {
        return config('services.meta.conversions_api_platforms') !== [];
    }
}
