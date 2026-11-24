<?php
declare(strict_types=1);

namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Datasource\ConnectionManager;
use PDO;
use RuntimeException;
use Throwable;

final class ImportarMysqlCommand extends Command
{
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        // Lista fixa e na ordem das dependências do schema legado.
        $tabelas = [
            'escritorios', 'usuarios', 'vinculos', 'departamentos', 'caracteristicas',
            'clientes', 'cliente_caracteristicas', 'modelos', 'cliente_modelos',
            'tarefas', 'log_tarefas', 'feriados', 'login_tentativas',
        ];
        $identidades = [
            'escritorios', 'usuarios', 'vinculos', 'departamentos', 'caracteristicas',
            'clientes', 'modelos', 'tarefas', 'log_tarefas', 'login_tentativas',
        ];

        try {
            $dsn = getenv('MYSQL_SOURCE_DSN') ?: '';
            if (!str_starts_with($dsn, 'mysql:') || !getenv('MYSQL_SOURCE_USER')) {
                throw new RuntimeException('Configure MYSQL_SOURCE_DSN, MYSQL_SOURCE_USER e MYSQL_SOURCE_PASSWORD.');
            }
            $origem = new PDO($dsn, getenv('MYSQL_SOURCE_USER'), getenv('MYSQL_SOURCE_PASSWORD') ?: '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
            ]);
            $origem->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $origem->beginTransaction();

            $destino = ConnectionManager::get('direct')->getDriver()->pdo();
            $destino->beginTransaction();
            $destino->exec('LOCK TABLE ' . implode(', ', $tabelas) . ' IN SHARE ROW EXCLUSIVE MODE');
            foreach ($tabelas as $tabela) {
                if ((int)$destino->query("SELECT COUNT(*) FROM $tabela")->fetchColumn() !== 0) {
                    throw new RuntimeException("A tabela de destino $tabela precisa estar vazia.");
                }
            }

            $contagens = [];
            foreach ($tabelas as $tabela) {
                $contagens[$tabela] = 0;
                // As colunas vêm do schema PostgreSQL fixado pelas migrations.
                $schema = $destino->prepare('SELECT column_name FROM information_schema.columns
                    WHERE table_schema = current_schema() AND table_name = ? ORDER BY ordinal_position');
                $schema->execute([$tabela]);
                $colunas = $schema->fetchAll(PDO::FETCH_COLUMN);
                $nomes = implode(', ', $colunas);
                $parametros = implode(', ', array_fill(0, count($colunas), '?'));
                $inserir = $destino->prepare("INSERT INTO $tabela ($nomes) VALUES ($parametros)");
                $linhas = $origem->query("SELECT $nomes FROM $tabela");
                while (($linha = $linhas->fetch(PDO::FETCH_NUM)) !== false) {
                    $inserir->execute($linha);
                    $contagens[$tabela]++;
                }
                $linhas->closeCursor();
                $qtd = (int)$destino->query("SELECT COUNT(*) FROM $tabela")->fetchColumn();
                if ($qtd !== $contagens[$tabela]) {
                    throw new RuntimeException("Contagem divergente em $tabela.");
                }
                if (in_array($tabela, $identidades, true)) {
                    $proximo = (int)$destino->query("SELECT COALESCE(MAX(id), 0) + 1 FROM $tabela")->fetchColumn();
                    $destino->exec("ALTER TABLE $tabela ALTER COLUMN id RESTART WITH $proximo");
                }
            }
            $origem->commit();
            $destino->commit();
            foreach ($contagens as $tabela => $qtd) {
                $io->out("$tabela: $qtd registros importados.");
            }
        } catch (Throwable $e) {
            if (isset($destino) && $destino->inTransaction()) {
                $destino->rollBack();
            }
            $io->err('Importação cancelada. ' . $e->getMessage());

            return static::CODE_ERROR;
        }

        return static::CODE_SUCCESS;
    }
}
