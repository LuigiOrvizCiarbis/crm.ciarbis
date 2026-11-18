<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * `deadline` se guardaba con la hora UTC del ISO que manda el navegador
 * (10:30 en Buenos Aires llegaba como 13:30Z y quedaba "13:30"), pero se lee
 * como hora de la aplicación: el CRM mostraba cada vencimiento tres horas
 * después y Google Calendar lo habría recibido igual de corrido. Desde ahora
 * el modelo convierte al guardar (`Task::setDeadlineAttribute`); esto corrige
 * lo ya guardado.
 *
 * Las reuniones vuelven a su invariante `deadline = starts_at` (`starts_at` ya
 * se guardaba bien). El resto solo se carga desde el formulario de tareas, que
 * siempre manda el instante UTC, así que se convierte de UTC a la hora local.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tasks')
            ->where('type', 'reunion')
            ->whereNotNull('starts_at')
            ->update(['deadline' => DB::raw('starts_at')]);

        $this->shiftReminderDeadlines('UTC', (string) config('app.timezone'));
    }

    public function down(): void
    {
        $appTz = (string) config('app.timezone');

        $this->shiftReminderDeadlines($appTz, 'UTC');

        DB::table('tasks')
            ->where('type', 'reunion')
            ->whereNotNull('starts_at')
            ->orderBy('id')
            ->each(fn (object $task) => DB::table('tasks')->where('id', $task->id)->update([
                'deadline' => Carbon::parse($task->starts_at, $appTz)->utc()->format('Y-m-d H:i:s'),
            ]));
    }

    private function shiftReminderDeadlines(string $from, string $to): void
    {
        DB::table('tasks')
            ->where('type', '!=', 'reunion')
            ->whereNotNull('deadline')
            ->orderBy('id')
            ->each(fn (object $task) => DB::table('tasks')->where('id', $task->id)->update([
                'deadline' => Carbon::parse($task->deadline, $from)->setTimezone($to)->format('Y-m-d H:i:s'),
            ]));
    }
};
