<?php
declare(strict_types=1);

namespace App\Http\Session;

use Cake\Datasource\ConnectionManager;
use PDO;
use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;

final class SessaoPostgres implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    private PDO $pdo;
    private int $duracao;

    public function __construct(array $config)
    {
        $this->duracao = (int)$config['duration'];
    }

    public function open(string $path, string $name): bool
    {
        $this->pdo = ConnectionManager::get('sessions')->getDriver()->pdo();

        return $this->pdo->beginTransaction();
    }

    public function close(): bool
    {
        return !$this->pdo->inTransaction() || $this->pdo->commit();
    }

    private function bloquear(string $id): void
    {
        $this->pdo->prepare('SELECT pg_advisory_xact_lock(2, hashtext(?))')->execute([$id]);
    }

    public function validateId(string $id): bool
    {
        $this->bloquear($id);
        $st = $this->pdo->prepare('SELECT 1 FROM sessions WHERE id = ? AND expires > ?');
        $st->execute([$id, time()]);

        return $st->fetchColumn() !== false;
    }

    public function read(string $id): string
    {
        $this->bloquear($id);
        $st = $this->pdo->prepare('SELECT data FROM sessions WHERE id = ? AND expires > ?');
        $st->execute([$id, time()]);
        $dados = $st->fetchColumn();

        return $dados === false ? '' : base64_decode($dados);
    }

    public function write(string $id, string $data): bool
    {
        $this->bloquear($id);

        return $this->pdo->prepare('INSERT INTO sessions (id, data, expires) VALUES (?, ?, ?)
            ON CONFLICT (id) DO UPDATE SET data = EXCLUDED.data, expires = EXCLUDED.expires')
            ->execute([$id, base64_encode($data), time() + $this->duracao]);
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        return $this->write($id, $data);
    }

    public function destroy(string $id): bool
    {
        $this->bloquear($id);

        return $this->pdo->prepare('DELETE FROM sessions WHERE id = ?')->execute([$id]);
    }

    public function gc(int $max_lifetime): int
    {
        $st = $this->pdo->prepare('DELETE FROM sessions WHERE id IN
            (SELECT id FROM sessions WHERE expires <= ? ORDER BY expires LIMIT 500 FOR UPDATE SKIP LOCKED)');
        $st->execute([time()]);

        return $st->rowCount();
    }
}
