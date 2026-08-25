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
Schedule::command('automations:dispatch-due')->everyMinute()->withoutOverlapping();
Schedule::command('broadcasts:dispatch-due')->everyMinute()->withoutOverlapping();
// Polling IMAP de los canales de email. Los canales de chat usan webhooks; el
// correo no, así que la latencia de entrada es el intervalo de este schedule.
Schedule::command('mail:sync-channels')->everyMinute()->withoutOverlapping();
Schedule::command('mail:purge-expired-intakes')->daily()->withoutOverlapping();
Schedule::command('model:prune', ['--model' => [AutomationRun::class]])->dailyAt('02:15');

// Un worker muerto a mitad de una extracción (OOM, deploy) deja la fila en
// processing y el claim compare-and-set impide que otro job la retome: sin este
// barrido el usuario ve un spinner eterno.
Schedule::command('extractions:reclaim')->everyFiveMinutes()->withoutOverlapping();

// El upload del PDF y el encolado son dos requests: si el usuario cierra el
// diálogo entre medio, el archivo queda en disco sin que nada lo referencie.
Schedule::command('extractions:purge-orphans')->dailyAt('03:30')->withoutOverlapping();
