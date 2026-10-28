<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Hasta ahora `deadline` se persistía sin convertir a la zona de la app: el
 * front manda ISO en UTC (20:00Z) y se guardaba la hora UTC como hora local
 * (20:00), así que cada vencimiento quedaba corrido +3 hs. El modelo ya lo
 * convierte (Task::setDeadlineAttribute); esto corrige lo guardado antes.
 *
 * Las reuniones no se recalculan restando: su `starts_at` siempre se guardó
 * bien y su deadline debe ser igual a él.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->shift(fn (Carbon $deadline) => $this->toAppWallTime($deadline), true);
    }

    public function down(): void
    {
        $this->shift(fn (Carbon $deadline) => $this->fromAppWallTime($deadline), false);
    }

    private function shift(callable $convert, bool $alignMeetings): void
    {
        DB::table('tasks')
            ->whereNotNull('deadline')
            ->orderBy('id')
            ->select(['id', 'type', 'deadline', 'starts_at'])
            ->chunkById(500, function ($tasks) use ($convert, $alignMeetings) {
                foreach ($tasks as $task) {
                    $deadline = $alignMeetings && $task->type === 'reunion' && $task->starts_at
                        ? $task->starts_at
                        : $convert(Carbon::parse($task->deadline, 'UTC'))->format('Y-m-d H:i:s');

                    DB::table('tasks')->where('id', $task->id)->update(['deadline' => $deadline]);
                }
            });
    }

    /** Hora UTC guardada como si fuera local → hora local real. */
    private function toAppWallTime(Carbon $utcWallTime): Carbon
    {
        return $utcWallTime->setTimezone((string) config('app.timezone'));
    }

    private function fromAppWallTime(Carbon $wallTime): Carbon
    {
        return Carbon::parse($wallTime->format('Y-m-d H:i:s'), (string) config('app.timezone'))->setTimezone('UTC');
    }
};
