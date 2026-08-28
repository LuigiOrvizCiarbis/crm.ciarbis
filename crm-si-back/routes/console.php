<?php

use App\Models\AutomationRun;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Purga los deliveries de webhooks más viejos que la retención configurada
// (config/webhooks.php). Requiere que el scheduler corra en el deploy
// (servicio `scheduler` en docker-compose.prod.yml).
Schedule::command('model:prune', ['--model' => [WebhookDelivery::class]])->daily();
// withoutOverlapping(N): expira el lock a los N minutos en vez del default de
// 24h. Si el scheduler muere a mitad de una corrida, un lock de 24h bloquea el
// comando todo ese tiempo; con el minutero acotado, se autolibera pronto.
Schedule::command('automations:dispatch-due')->everyMinute()->withoutOverlapping(5);
Schedule::command('broadcasts:dispatch-due')->everyMinute()->withoutOverlapping(5);
// Polling IMAP de los canales de email. Los canales de chat usan webhooks; el
// correo no, así que la latencia de entrada es el intervalo de este schedule.
Schedule::command('mail:sync-channels')->everyMinute()->withoutOverlapping(10);
Schedule::command('mail:purge-expired-intakes')->daily()->withoutOverlapping();
Schedule::command('model:prune', ['--model' => [AutomationRun::class]])->dailyAt('02:15');
// Detecta difusiones trabadas en la cola: si un destinatario lleva más de 15
// minutos en `queued`, algo está tapando el worker (ver retry_after en
// config/queue.php) y hoy nadie se entera hasta que el cliente se queja.
Schedule::command('broadcasts:check-stuck')->everyFifteenMinutes()->withoutOverlapping(10);
