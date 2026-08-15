<?php

return [
    'cost_per_message_usd' => (float) env('BROADCAST_COST_PER_MESSAGE_USD', 0.065),
    'max_recipients' => (int) env('BROADCAST_MAX_RECIPIENTS', 5000),
];
