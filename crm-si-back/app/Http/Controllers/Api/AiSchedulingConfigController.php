<?php

namespace App\Http\Controllers\Api;

use App\Enums\AiSchedulingMode;
use App\Http\Controllers\Controller;
use App\Models\AiSchedulingConfig;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Config de la agenda de turnos que maneja la IA. Mismos permisos que la
 * config de IA (ai_config.view / ai_config.manage).
 */
class AiSchedulingConfigController extends Controller
{
    private const CRITERIA_MAX_LENGTH = 5000;

    private const MAX_RANGES_PER_DAY = 3;

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user?->can('ai_config.view') && ! $user?->can('ai_config.manage')) {
            abort(403);
        }

        $config = AiSchedulingConfig::firstOrNew(['tenant_id' => $user->tenant_id]);

        return response()->json(['data' => $this->transform($config, $user->tenant_id)]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user?->can('ai_config.manage')) {
            abort(403);
        }

        $tenantId = $user->tenant_id;

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'mode' => ['required', Rule::in(AiSchedulingMode::values())],
            'criteria' => ['nullable', 'string', 'max:'.self::CRITERIA_MAX_LENGTH, 'required_if:mode,'.AiSchedulingMode::CRITERIA->value],
            'default_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId))],
            'timezone' => ['required', 'timezone'],
            'meeting_duration_minutes' => ['required', 'integer', 'between:10,480'],
            'slot_interval_minutes' => ['required', 'integer', 'between:5,240'],
            'min_notice_hours' => ['required', 'integer', 'between:0,168'],
            'max_days_ahead' => ['required', 'integer', 'between:1,60'],
            'working_hours' => ['required', 'array'],
            'working_hours.*' => ['array', 'max:'.self::MAX_RANGES_PER_DAY],
            'working_hours.*.*.start' => ['required', 'date_format:H:i'],
            'working_hours.*.*.end' => ['required', 'date_format:H:i'],
        ]);

        $validated['working_hours'] = $this->normalizeWorkingHours($validated['working_hours']);

        $config = AiSchedulingConfig::firstOrNew(['tenant_id' => $tenantId]);
        $config->fill($validated);
        $config->tenant_id = $tenantId;
        $config->save();

        return response()->json(['data' => $this->transform($config->fresh(), $tenantId)]);
    }

    /**
     * Valida claves de día y rangos, y completa los días faltantes con [].
     *
     * @param  array<string, mixed>  $workingHours
     * @return array<string, list<array{start: string, end: string}>>
     */
    private function normalizeWorkingHours(array $workingHours): array
    {
        $normalized = [];

        foreach ($workingHours as $day => $ranges) {
            if (! in_array($day, AiSchedulingConfig::WEEKDAYS, true)) {
                throw ValidationException::withMessages(['working_hours' => 'Horario de atención inválido.']);
            }

            foreach ($ranges as $range) {
                // HH:MM se compara bien como string.
                if ($range['start'] >= $range['end']) {
                    throw ValidationException::withMessages(['working_hours' => 'Horario de atención inválido.']);
                }
            }

            $normalized[$day] = array_values(array_map(
                fn (array $range) => ['start' => $range['start'], 'end' => $range['end']],
                $ranges,
            ));
        }

        foreach (AiSchedulingConfig::WEEKDAYS as $day) {
            $normalized[$day] ??= [];
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(AiSchedulingConfig $config, int $tenantId): array
    {
        return [
            'enabled' => $config->enabled,
            'mode' => $config->mode->value,
            'criteria' => $config->criteria,
            'default_user_id' => $config->default_user_id,
            'timezone' => $config->timezone,
            'meeting_duration_minutes' => $config->meeting_duration_minutes,
            'slot_interval_minutes' => $config->slot_interval_minutes,
            'min_notice_hours' => $config->min_notice_hours,
            'max_days_ahead' => $config->max_days_ahead,
            'working_hours' => $config->working_hours,
            'calendar_users' => User::where('tenant_id', $tenantId)
                ->whereHas('googleCalendarConnection', fn ($query) => $query->where('status', 'connected'))
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
                ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email])
                ->all(),
        ];
    }
}
