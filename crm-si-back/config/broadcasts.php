<?php

return [
    'cost_per_message_usd' => (float) env('BROADCAST_COST_PER_MESSAGE_USD', 0.065),
    'max_recipients' => (int) env('BROADCAST_MAX_RECIPIENTS', 5000),
    // Por encima de esto, store() exige acknowledge_audience_size aunque no
    // haya problema de consentimiento ni de messaging limit: evita el "le di
    // a enviar sin mirar y salieron miles".
    'confirmation_threshold' => (int) env('BROADCAST_CONFIRMATION_THRESHOLD', 500),
    // Tope de segundos entre el primer y el último mensaje de una campaña.
    // Por encima, store() rechaza sugiriendo un intervalo menor: Meta no
    // garantiza que la plantilla siga aprobada varios días después, y con
    // audiencias grandes un interval_seconds alto deja miles de jobs
    // demorados en la cola durante días.
    'max_campaign_duration_seconds' => (int) env('BROADCAST_MAX_CAMPAIGN_DURATION_SECONDS', 86400),
];
