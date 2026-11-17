<?php

namespace App\Rules;

use App\Models\WhatsAppConfig;
use App\Models\WhatsAppFlow;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Meta exige nombres de formulario únicos en toda la cuenta de WhatsApp
 * Business (WABA), incluidos los deprecados. Varios números pueden compartir
 * la cuenta, así que se mira cada número con el mismo `waba_id`. Un formulario
 * creado en WhatsApp Manager y todavía no sincronizado no está acá: ese caso
 * lo devuelve Meta (FlowNameTakenException).
 */
class UniqueFlowName implements ValidationRule
{
    public const MESSAGE = 'Ya hay un formulario con ese nombre en esta cuenta de WhatsApp. Elegí otro nombre.';

    public function __construct(
        private readonly ?int $whatsappConfigId,
        private readonly ?int $ignoreFlowId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $wabaId = $this->whatsappConfigId !== null
            ? WhatsAppConfig::withoutGlobalScopes()->whereKey($this->whatsappConfigId)->value('waba_id')
            : null;

        if ($wabaId === null || ! is_string($value)) {
            return;
        }

        $taken = WhatsAppFlow::withoutGlobalScopes()
            ->whereIn('whatsapp_config_id', WhatsAppConfig::withoutGlobalScopes()->where('waba_id', $wabaId)->select('id'))
            ->where('name', trim($value))
            ->when($this->ignoreFlowId !== null, fn ($query) => $query->whereKeyNot($this->ignoreFlowId))
            ->exists();

        if ($taken) {
            $fail(self::MESSAGE);
        }
    }
}
