<?php
declare(strict_types=1);

use App\Database\SchemaInicial;
use Migrations\BaseMigration;

final class SessoesCompartilhadas extends BaseMigration
{
    public function up(): void
    {
        $pdo = $this->getAdapter()->getConnection()->getDriver()->pdo();
        SchemaInicial::aplicar($pdo, '002_sessoes.sql', <<<'SQL'
CREATE TABLE sessions (
    id VARCHAR(256) PRIMARY KEY,
    data TEXT NOT NULL,
    expires BIGINT NOT NULL
);

CREATE INDEX ix_sessions_expires ON sessions (expires);
SQL, $this->execute(...));
    }
}
