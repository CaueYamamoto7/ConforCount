<?php
declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;

final class SchemaInicial
{
    public static function aplicar(PDO $pdo, string $versao, string $sql, callable $executar): void
    {
        preg_match_all('/^CREATE TABLE (\w+) \(/m', $sql, $tabelas);
        $existe = $pdo->prepare('SELECT to_regclass(quote_ident(current_schema()) || \'.\' || quote_ident(?))');
        $existentes = [];
        foreach ($tabelas[1] as $tabela) {
            $existe->execute([$tabela]);
            if ($existe->fetchColumn() !== null) {
                $existentes[] = $tabela;
            }
        }

        if ($existentes === []) {
            foreach (preg_split('/;\s*(?:\R|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) as $comando) {
                $executar($comando);
            }

            return;
        }

        $existe->execute(['schema_migrations']);
        if ($existentes !== $tabelas[1] || $existe->fetchColumn() === null) {
            throw new RuntimeException('O esquema encontrado é parcial ou possui origem desconhecida: ' . $versao);
        }
        $registro = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE versao = ?');
        $registro->execute([$versao]);
        if (!$registro->fetchColumn()) {
            throw new RuntimeException('Migration anterior não registrada: ' . $versao);
        }

        $colunas = $pdo->prepare('SELECT column_name, data_type, character_maximum_length, is_identity
            FROM information_schema.columns
            WHERE table_schema = current_schema() AND table_name = ? ORDER BY ordinal_position');
        preg_match_all('/^CREATE TABLE (\w+) \(\R(.*?)^\);/ms', $sql, $definicoes, PREG_SET_ORDER);
        $tipos = ['VARCHAR' => 'character varying', 'CHAR' => 'character', 'TIMESTAMP' => 'timestamp without time zone'];
        foreach ($definicoes as [, $tabela, $definicao]) {
            preg_match_all(
                '/^ *([a-z_]\w*) +(BIGINT|SMALLINT|VARCHAR|CHAR|TEXT|DATE|TIMESTAMP)\b(?:\((\d+)\))?(.*)$/m',
                $definicao,
                $definicoesColunas,
                PREG_SET_ORDER,
            );
            $esperadas = array_map(static function (array $c) use ($tipos): array {
                return [
                    'column_name' => $c[1],
                    'data_type' => $tipos[$c[2]] ?? strtolower($c[2]),
                    'character_maximum_length' => in_array($c[2], ['VARCHAR', 'CHAR'], true) ? (int)$c[3] : null,
                    'is_identity' => str_contains($c[4], ' AS IDENTITY') ? 'YES' : 'NO',
                ];
            }, $definicoesColunas);
            $colunas->execute([$tabela]);
            if ($colunas->fetchAll() !== $esperadas) {
                throw new RuntimeException('Colunas inesperadas no esquema anterior: ' . $tabela);
            }
        }

        preg_match_all('/^CREATE INDEX (\w+) ON /m', $sql, $indices);
        foreach ($indices[1] as $indice) {
            $existe->execute([$indice]);
            if ($existe->fetchColumn() === null) {
                throw new RuntimeException('Índice não encontrado no esquema anterior: ' . $indice);
            }
        }
    }
}
