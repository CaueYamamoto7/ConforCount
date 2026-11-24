<?php
declare(strict_types=1);

namespace App\Service;

use App\Exception\HttpErro;
use Throwable;

class ClientesService extends Operacao
{
    public function clienteSalvar(): array
    {
        $u = $this->exigirLogin();
        $this->exigirPapel($u, ['admin', 'contador']);
        $eid = $u['escritorio_id'];
        $b = $this->corpo();
        $id = (int)($b['id'] ?? 0);
        $nome = $this->texto($b, 'nome', 150, true);
        $cnpjBruto = $this->texto($b, 'cnpj', 30);
        $cnpj = null;
        if ($cnpjBruto !== '') {
            $cnpj = Cnpj::normalizar($cnpjBruto);
            if (!Cnpj::valido($cnpj)) {
                throw new HttpErro('CNPJ inválido. Confira os números e letras.', 422);
            }
        }
        $regime = (string)($b['regime'] ?? '');
        if (!in_array($regime, self::REGIMES_VALIDOS, true)) {
            throw new HttpErro('Regime inválido.', 422);
        }
        $pdo = $this->db();
        if ($id > 0 && !$this->pertence('clientes', $id, $eid)) {
            throw new HttpErro('Cliente não encontrado.', 404);
        }
        $resp = (int)($b['respId'] ?? 0);
        if ($resp > 0) {
            $st = $pdo->prepare('SELECT 1 FROM vinculos v JOIN usuarios u ON u.id = v.usuario_id
                                  WHERE v.usuario_id = ? AND v.escritorio_id = ? AND v.ativo = 1 AND u.ativo = 1');
            $st->execute([$resp, $eid]);
            if (!$st->fetchColumn()) {
                throw new HttpErro('Responsável inválido.', 422);
            }
        }
        $inicio = $this->mesOpcional($b, 'inicio');
        $fim = $this->mesOpcional($b, 'fim');
        if ($inicio !== null && $fim !== null && $fim < $inicio) {
            throw new HttpErro('O mês final não pode ser anterior ao inicial.', 422);
        }
        $obs = $this->texto($b, 'obs', 500);
        if ($cnpj !== null) {
            $st = $pdo->prepare('SELECT 1 FROM clientes WHERE escritorio_id = ? AND cnpj = ? AND id <> ?');
            $st->execute([$eid, $cnpj, $id]);
            if ($st->fetchColumn()) {
                throw new HttpErro('Já existe um cliente com este CNPJ.', 422);
            }
        }
        // características e exceções: só ids deste escritório
        $caract = [];
        foreach (is_array($b['caract'] ?? null) ? $b['caract'] : [] as $x) {
            $x = (int)$x;
            if ($x > 0 && $this->pertence('caracteristicas', $x, $eid)) {
                $caract[$x] = $x;
            }
        }
        $exc = [];
        foreach (is_array($b['excecoes'] ?? null) ? $b['excecoes'] : [] as $mid => $acao) {
            $mid = (int)$mid;
            if ($mid > 0 && in_array($acao, ['incluir', 'excluir'], true) && $this->pertence('modelos', $mid, $eid)) {
                $exc[$mid] = $acao;
            }
        }

        $campos = [$nome, $cnpj, $regime, $resp > 0 ? $resp : null, $this->booleano($b, 'ativo'), $inicio, $fim, $obs === '' ? null : $obs];
        $pdo->beginTransaction();
        try {
            if ($id > 0) {
                $pdo->prepare('UPDATE clientes SET nome=?, cnpj=?, regime=?, responsavel_id=?, ativo=?, inicio=?, fim=?, obs=? WHERE id=? AND escritorio_id=?')
                    ->execute(array_merge($campos, [$id, $eid]));
            } else {
                $id = $this->inserirId($pdo, 'INSERT INTO clientes (nome, cnpj, regime, responsavel_id, ativo, inicio, fim, obs, escritorio_id) VALUES (?,?,?,?,?,?,?,?,?)', array_merge($campos, [$eid]));
            }
            $pdo->prepare('DELETE FROM cliente_caracteristicas WHERE cliente_id = ?')->execute([$id]);
            $ins = $pdo->prepare('INSERT INTO cliente_caracteristicas (cliente_id, caracteristica_id) VALUES (?, ?)');
            foreach ($caract as $x) {
                $ins->execute([$id, $x]);
            }
            $pdo->prepare('DELETE FROM cliente_modelos WHERE cliente_id = ?')->execute([$id]);
            $ins = $pdo->prepare('INSERT INTO cliente_modelos (cliente_id, modelo_id, acao) VALUES (?, ?, ?)');
            foreach ($exc as $mid => $acao) {
                $ins->execute([$id, $mid, $acao]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['ok' => true, 'id' => $id];
    }

    public function clienteExcluir(): array
    {
        $u = $this->exigirLogin();
        $this->exigirPapel($u, ['admin', 'contador']);
        $id = (int)($this->corpo()['id'] ?? 0);
        if (!$this->pertence('clientes', $id, $u['escritorio_id'])) {
            throw new HttpErro('Cliente não encontrado.', 404);
        }
        $pdo = $this->db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM clientes WHERE id = ? AND escritorio_id = ?')->execute([$id, $u['escritorio_id']]);
            $pdo->prepare('DELETE FROM log_tarefas WHERE escritorio_id = ? AND cliente_id = ?')->execute([$u['escritorio_id'], $id]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['ok' => true];
    }
}
