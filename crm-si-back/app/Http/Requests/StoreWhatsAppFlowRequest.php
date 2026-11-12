<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWhatsAppFlowRequest extends FormRequest
{
    public const CATEGORIES = [
        'SIGN_UP',
        'SIGN_IN',
        'APPOINTMENT_BOOKING',
        'LEAD_GENERATION',
        'CONTACT_US',
        'CUSTOMER_SUPPORT',
        'SURVEY',
        'OTHER',
    ];

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('flows.manage');
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'categories' => ['required', 'array', 'min:1'],
            'categories.*' => ['string', Rule::in(self::CATEGORIES)],
            'builder_definition' => ['required_without:flow_json', 'array'],
            'flow_json' => ['required_without:builder_definition', 'prohibits:builder_definition', 'string', 'max:10485760', 'json'],
        ];
    }
}
