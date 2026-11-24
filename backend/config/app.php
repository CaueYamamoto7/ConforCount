<?php
declare(strict_types=1);

use App\Database\Postgres;
use App\Http\Session\SessaoPostgres;

$production = getenv('APP_ENV') === 'production';
$salt = getenv('SECURITY_SALT') ?: '';
if ($production && strlen($salt) < 32) {
    throw new RuntimeException('Configure SECURITY_SALT com pelo menos 32 caracteres.');
}

$databaseUrl = getenv('DATABASE_URL') ?: '';
$directUrl = getenv('DATABASE_URL_DIRECT') ?: $databaseUrl;
$database = static fn (string $url): array => [
    'className' => \Cake\Database\Connection::class,
    'driver' => Postgres::class,
    'database' => rawurldecode(ltrim((string)parse_url($url, PHP_URL_PATH), '/')),
    'connectionUrl' => $url,
    'cacheMetadata' => false,
];
$sessionMinutes = 480;

return [
    'debug' => !$production && filter_var(getenv('DEBUG'), FILTER_VALIDATE_BOOLEAN),
    'App' => [
        'encoding' => 'UTF-8',
        'defaultLocale' => 'pt_BR',
        'defaultTimezone' => getenv('APP_DEFAULT_TIMEZONE') ?: 'America/Sao_Paulo',
        'fullBaseUrl' => getenv('APP_FULL_BASE_URL') ?: null,
        'namespace' => 'App',
    ],
    'Security' => ['salt' => $salt ?: 'conforcont-desenvolvimento-local'],
    'Datasources' => [
        'default' => $database($databaseUrl ?: $directUrl),
        'direct' => $database($directUrl),
        'sessions' => $database($databaseUrl ?: $directUrl),
        'test' => $database(getenv('DATABASE_TEST_URL') ?: ''),
    ],
    'Session' => [
        'defaults' => 'php',
        'cookie' => 'obrig_sess',
        'timeout' => $sessionMinutes,
        'handler' => ['engine' => SessaoPostgres::class, 'duration' => $sessionMinutes * 60],
        'ini' => [
            'session.use_only_cookies' => 1,
            'session.use_strict_mode' => 1,
            'session.cookie_lifetime' => 0,
            'session.cookie_httponly' => 1,
            'session.cookie_secure' => $production || filter_var(getenv('SESSION_SECURE'), FILTER_VALIDATE_BOOLEAN),
            'session.cookie_samesite' => 'Strict',
            'session.gc_probability' => 1,
            'session.gc_divisor' => 100,
        ],
    ],
];
