<?php
declare(strict_types=1);

namespace App\Database;

use Cake\Database\Driver\Postgres as PostgresDriver;
use PDO;
use RuntimeException;

final class Postgres extends PostgresDriver
{
    public function pdo(): PDO
    {
        return $this->getPdo();
    }

    public function connect(): void
    {
        if ($this->pdo !== null) {
            return;
        }
        $url = $this->_config['connectionUrl'];
        $partes = parse_url($url);
        if (
            !$partes || !in_array($partes['scheme'] ?? '', ['postgres', 'postgresql'], true)
            || empty($partes['host']) || empty($partes['user']) || empty(ltrim($partes['path'] ?? '', '/'))
        ) {
            throw new RuntimeException('Configure uma DATABASE_URL PostgreSQL válida.');
        }
        parse_str($partes['query'] ?? '', $opcoes);
        $ssl = $opcoes['sslmode'] ?? 'require';
        if (
            !in_array($ssl, ['disable', 'require', 'verify-ca', 'verify-full'], true)
            || (getenv('APP_ENV') === 'production' && $ssl === 'disable')
        ) {
            throw new RuntimeException('Configure sslmode com TLS no ambiente de produção.');
        }
        $host = $partes['host'];
        $banco = rawurldecode(substr($partes['path'], 1));
        if (!preg_match('/\A[a-zA-Z0-9.:-]+\z/', $host) || !preg_match('/\A[a-zA-Z0-9_.-]+\z/', $banco)) {
            throw new RuntimeException('Host ou nome do banco inválido em DATABASE_URL.');
        }
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s;connect_timeout=10',
            $host,
            $partes['port'] ?? 5432,
            $banco,
            $ssl,
        );
        if (isset($opcoes['channel_binding'])) {
            if (!in_array($opcoes['channel_binding'], ['disable', 'prefer', 'require'], true)) {
                throw new RuntimeException('channel_binding inválido em DATABASE_URL.');
            }
            $dsn .= ';channel_binding=' . $opcoes['channel_binding'];
        }
        $this->pdo = new PDO($dsn, rawurldecode($partes['user']), rawurldecode($partes['pass'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::PGSQL_ATTR_DISABLE_PREPARES => true,
        ]);
    }
}
