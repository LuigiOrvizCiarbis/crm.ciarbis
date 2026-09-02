<?php

namespace App\Http\Requests;

use App\Models\Channel;
use App\Models\WhatsAppGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWhatsAppGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user) {
            return false;
        }

        // channel_id todavía no pasó por rules() acá (authorize() corre antes),
        // así que se resuelve manualmente para poder chequear acceso al canal
        // puntual, no solo el permiso genérico de crear grupos.
        $channelId = $this->input('channel_id');
        $channel = $channelId ? Channel::find($channelId) : null;

        return $user->can('create', [WhatsAppGroup::class, $channel]);
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'channel_id' => [
                'required',
                'integer',
                Rule::exists('channels', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'subject' => ['required', 'string', 'max:128'],
            'description' => ['nullable', 'string', 'max:2048'],
            'join_approval_mode' => ['nullable', Rule::in(['approval_required', 'auto_approve'])],
            'opportunity_id' => [
                'nullable',
                'integer',
                Rule::exists('opportunities', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
        ];
    }
}
