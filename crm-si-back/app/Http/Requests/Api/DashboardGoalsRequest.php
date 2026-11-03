<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DashboardGoalsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && ($user->isTenantOwner() || $user->can('analytics.view_team'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'response_time_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10080'],
            'cycle_time_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
            'revenue_monthly' => ['sometimes', 'nullable', 'numeric', 'min:1', 'max:1000000000000'],
        ];
    }
}
