<?php

return [
    // El número del negocio ocupa un slot como participante: 8 total = 7 invitados.
    'max_participants' => (int) env('WHATSAPP_GROUPS_MAX_PARTICIPANTS', 8),
    'max_groups_per_number' => (int) env('WHATSAPP_GROUPS_MAX_PER_NUMBER', 10000),
];
