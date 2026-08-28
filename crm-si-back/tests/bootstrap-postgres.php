<?php

/**
 * Bootstrap para phpunit.postgres.xml.
 *
 * tests/bootstrap.php fuerza DB_CONNECTION=sqlite vía putenv() para blindar el
 * Postgres de dev contra RefreshDatabase — putenv() gana sobre los <env> de
 * cualquier phpunit.xml, así que ese shim no se puede pisar desde ahí.
 *
 * Esta suite necesita justamente lo contrario: Postgres real, porque SQLite no
 * distingue timestamp de timestamptz. Apunta explícitamente a la conexión
 * pgsql_testing (base si_crm_test, separada de la de dev) para que
 * RefreshDatabase drop/recree esa base y no toque la de desarrollo.
 */
$testDefaults = [
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'pgsql_testing',
    'CACHE_STORE' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'SESSION_DRIVER' => 'array',
    'BROADCAST_CONNECTION' => 'null',
    'MAIL_MAILER' => 'array',
];

foreach ($testDefaults as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

require __DIR__.'/../vendor/autoload.php';
