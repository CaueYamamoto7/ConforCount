<?php
declare(strict_types=1);

namespace App\Service;

use App\Exception\HttpErro;
use Throwable;

class TarefasService extends Operacao
{
    protected function clientePermitido(array $u, int $clienteId): array
    {
        $st = $this->db()->prepare('SELECT * FROM clientes WHERE id = ? AND escritorio_id = ?');
        $st->execute([$clienteId, $u['escritorio_id']]);
        $c = $st->fetch();
        if (!$c) {
            throw new HttpErro('Cliente não encontrado.', 404);
        }
        if ($u['papel'] === 'colaborador' && (int)$c['responsavel_id'] !== $u['id']) {
            throw new HttpErro('Este cliente não está na sua carteira.', 403);
        }

        return $c;
    }

    public function tarefaSalvar(): array
    {
        $u = $this->exigirLogin();
        $eid = $u['escritorio_id'];
        $b = $this->corpo();
        $mes = $this->mesValido((string)($b['mes'] ?? ''));
        $cid = (int)($b['clienteId'] ?? 0);
        $mid = (int)($b['modeloId'] ?? 0);
        $status = (string)($b['status'] ?? '');
        if (!in_array($status, self::STATUS_VALIDOS, true)) {
            throw new HttpErro('Situação inválida.', 422);
        }
        $obs = $this->texto($b, 'obs', 1000);
        $this->clientePermitido($u, $cid);
        if (!$this->pertence('modelos', $mid, $eid)) {
            throw new HttpErro('Obrigação não encontrada.', 404);
        }

        $pdo = $this->db();
        $pdo->beginTransaction();
        try {
            // Serializa a gravação mesmo quando ainda não existe linha em tarefas.
            $st = $pdo->prepare('SELECT pg_advisory_xact_lock(1, hashtext(?))');
            $st->execute(["$eid:$mes:$cid:$mid"]);

            $st = $pdo->prepare('SELECT status, obs FROM tarefas WHERE mes = ? AND cliente_id = ? AND modelo_id = ? FOR UPDATE');
            $st->execute([$mes, $cid, $mid]);
            $antes = $st->fetch();
            $statusAntes = $antes ? $antes['status'] : 'pendente';
            $obsAntes = $antes ? $antes['obs'] : '';

            if ($status === 'pendente' && $obs === '') {
                $pdo->prepare('DELETE FROM tarefas WHERE mes = ? AND cliente_id = ? AND modelo_id = ?')->execute([$mes, $cid, $mid]);
            } else {
                $pdo->prepare('INSERT INTO tarefas (escritorio_id, mes, cliente_id, modelo_id, status, obs, atualizado_por, atualizado_em)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                               ON CONFLICT (mes, cliente_id, modelo_id) DO UPDATE SET status = EXCLUDED.status, obs = EXCLUDED.obs,
                                                       atualizado_por = EXCLUDED.atualizado_por, atualizado_em = EXCLUDED.atualizado_em')
                    ->execute([$eid, $mes, $cid, $mid, $status, $obs, $u['id'], $this->agora()]);
            }
            if ($statusAntes !== $status || $obsAntes !== $obs) {
                $pdo->prepare('INSERT INTO log_tarefas (escritorio_id, mes, cliente_id, modelo_id, usuario_id, status_antes, status_depois, obs, criado_em)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$eid, $mes, $cid, $mid, $u['id'], $statusAntes, $status, $obs, $this->agora()]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['ok' => true];
    }

    public function historico(): array
    {
        $u = $this->exigirLogin();
        $mes = $this->mesValido((string)($this->request->getQuery('mes', '')));
        $cid = (int)($this->request->getQuery('cliente_id', 0));
        $mid = (int)($this->request->getQuery('modelo_id', 0));
        $this->clientePermitido($u, $cid);
        $st = $this->db()->prepare('SELECT l.criado_em, l.status_antes, l.status_depois, l.obs, uu.nome AS por
                               FROM log_tarefas l LEFT JOIN usuarios uu ON uu.id = l.usuario_id
                              WHERE l.escritorio_id = ? AND l.mes = ? AND l.cliente_id = ? AND l.modelo_id = ?
                              ORDER BY l.id DESC LIMIT 15');
        $st->execute([$u['escritorio_id'], $mes, $cid, $mid]);

        return ['historico' => array_map(fn($r) => [
            'em' => $r['criado_em'], 'por' => $r['por'] ?? '(usuário removido)',
            'antes' => $r['status_antes'], 'depois' => $r['status_depois'], 'obs' => $r['obs'],
        ], $st->fetchAll())];
    }
}
