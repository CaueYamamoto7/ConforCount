<?php
declare(strict_types=1);

namespace App\Service;

use App\Exception\HttpErro;
use DateTime;
use Throwable;

class ModelosService extends Operacao
{
    protected function cadastroSalvar(string $tabela, int $max, string $rotulo): array
    {
        $u = $this->exigirLogin();
        $this->exigirPapel($u, ['admin']);
        $eid = $u['escritorio_id'];
        $b = $this->corpo();
        $id = (int)($b['id'] ?? 0);
        $nome = $this->texto($b, 'nome', $max, true);
        $pdo = $this->db();
        if ($id > 0 && !$this->pertence($tabela, $id, $eid)) {
            throw new HttpErro("$rotulo não encontrado(a).", 404);
        }
        $st = $pdo->prepare("SELECT 1 FROM $tabela WHERE escritorio_id = ? AND nome = ? AND id <> ?");
        $st->execute([$eid, $nome, $id]);
        if ($st->fetchColumn()) {
            throw new HttpErro("Já existe $rotulo com este nome.", 422);
        }
        if ($id > 0) {
            $pdo->prepare("UPDATE $tabela SET nome = ? WHERE id = ? AND escritorio_id = ?")->execute([$nome, $id, $eid]);
        } else {
            $st = $pdo->prepare("SELECT COALESCE(MAX(ordem), 0) + 1 FROM $tabela WHERE escritorio_id = ?");
            $st->execute([$eid]);
            $id = $this->inserirId($pdo, "INSERT INTO $tabela (escritorio_id, nome, ordem) VALUES (?, ?, ?)", [$eid, $nome, (int)$st->fetchColumn()]);
        }

        return ['ok' => true, 'id' => $id];
    }

    public function modeloSalvar(): array
    {
        $u = $this->exigirLogin();
        $this->exigirPapel($u, ['admin']);
        $eid = $u['escritorio_id'];
        $b = $this->corpo();
        $id = (int)($b['id'] ?? 0);
        $nome = $this->texto($b, 'nome', 120, true);
        $deptoId = (int)($b['deptoId'] ?? 0);
        if (!$this->pertence('departamentos', $deptoId, $eid)) {
            throw new HttpErro('Departamento inválido.', 422);
        }
        $caractId = (int)($b['caractId'] ?? 0);
        if ($caractId > 0 && !$this->pertence('caracteristicas', $caractId, $eid)) {
            throw new HttpErro('Característica inválida.', 422);
        }
        if ($id > 0 && !$this->pertence('modelos', $id, $eid)) {
            throw new HttpErro('Obrigação não encontrada.', 404);
        }
        $regimes = array_values(array_unique(array_filter(is_array($b['regimes'] ?? null) ? $b['regimes'] : [], fn($r) => in_array($r, self::REGIMES_VALIDOS, true))));
        if (!$regimes) {
            throw new HttpErro('Marque ao menos um regime.', 422);
        }
        $meses = [];
        foreach (is_array($b['meses'] ?? null) ? $b['meses'] : [] as $m) {
            $m = (int)$m;
            if ($m >= 1 && $m <= 12) {
                $meses[$m] = $m;
            }
        }
        ksort($meses);
        $tipo = (string)($b['vencTipo'] ?? '');
        if (!in_array($tipo, ['dia', 'nutil', 'ultimo'], true)) {
            throw new HttpErro('Tipo de prazo inválido.', 422);
        }
        $valor = (int)($b['vencValor'] ?? 0);
        if ($tipo === 'dia' && ($valor < 1 || $valor > 31)) {
            throw new HttpErro('Informe um dia entre 1 e 31.', 422);
        }
        if ($tipo === 'nutil' && ($valor < 1 || $valor > 23)) {
            throw new HttpErro('Informe o dia útil (1 a 23).', 422);
        }
        if ($tipo === 'ultimo') {
            $valor = 0;
        }
        $ajuste = (string)($b['ajuste'] ?? 'prorroga');
        if (!in_array($ajuste, ['prorroga', 'antecipa'], true)) {
            throw new HttpErro('Ajuste inválido.', 422);
        }
        $desde = $this->mesOpcional($b, 'desde');
        $obs = $this->texto($b, 'obs', 255);
        $campos = [$deptoId, $nome, implode(',', $regimes), $caractId > 0 ? $caractId : null, implode(',', $meses), $tipo, $valor, $ajuste, $desde, $obs === '' ? null : $obs, $this->booleano($b, 'ativo')];
        $pdo = $this->db();
        if ($id > 0) {
            $pdo->prepare('UPDATE modelos SET departamento_id=?, nome=?, regimes=?, caracteristica_id=?, meses=?, venc_tipo=?, venc_valor=?, ajuste=?, vigente_desde=?, observacao=?, ativo=? WHERE id=? AND escritorio_id=?')
                ->execute(array_merge($campos, [$id, $eid]));
        } else {
            $id = $this->inserirId($pdo, 'INSERT INTO modelos (departamento_id, nome, regimes, caracteristica_id, meses, venc_tipo, venc_valor, ajuste, vigente_desde, observacao, ativo, escritorio_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', array_merge($campos, [$eid]));
        }

        return ['ok' => true, 'id' => $id];
    }

    public function modeloExcluir(): array
    {
        $u = $this->exigirLogin();
        $this->exigirPapel($u, ['admin']);
        $id = (int)($this->corpo()['id'] ?? 0);
        if (!$this->pertence('modelos', $id, $u['escritorio_id'])) {
            throw new HttpErro('Obrigação não encontrada.', 404);
        }
        $pdo = $this->db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM modelos WHERE id = ? AND escritorio_id = ?')->execute([$id, $u['escritorio_id']]);
            $pdo->prepare('DELETE FROM log_tarefas WHERE escritorio_id = ? AND modelo_id = ?')->execute([$u['escritorio_id'], $id]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['ok' => true];
    }

    public function deptoSalvar(): array
    {
        return $this->cadastroSalvar('departamentos', 60, 'um departamento');
    }

    public function deptoExcluir(): array
    {
        $u = $this->exigirLogin();
        $this->exigirPapel($u, ['admin']);
        $id = (int)($this->corpo()['id'] ?? 0);
        if (!$this->pertence('departamentos', $id, $u['escritorio_id'])) {
            throw new HttpErro('Departamento não encontrado.', 404);
        }
        $st = $this->db()->prepare('SELECT COUNT(*) FROM modelos WHERE departamento_id = ? AND escritorio_id = ?');
        $st->execute([$id, $u['escritorio_id']]);
        $n = (int)$st->fetchColumn();
        if ($n > 0) {
            throw new HttpErro("Este departamento ainda tem $n obrigação(ões). Mova ou exclua essas obrigações antes.", 422);
        }
        $this->db()->prepare('DELETE FROM departamentos WHERE id = ? AND escritorio_id = ?')->execute([$id, $u['escritorio_id']]);

        return ['ok' => true];
    }

    public function caractSalvar(): array
    {
        return $this->cadastroSalvar('caracteristicas', 80, 'uma característica');
    }

    public function caractExcluir(): array
    {
        $u = $this->exigirLogin();
        $this->exigirPapel($u, ['admin']);
        $id = (int)($this->corpo()['id'] ?? 0);
        if (!$this->pertence('caracteristicas', $id, $u['escritorio_id'])) {
            throw new HttpErro('Característica não encontrada.', 404);
        }
        $st = $this->db()->prepare('SELECT COUNT(*) FROM modelos WHERE caracteristica_id = ? AND escritorio_id = ?');
        $st->execute([$id, $u['escritorio_id']]);
        $n = (int)$st->fetchColumn();
        if ($n > 0) {
            throw new HttpErro("Esta característica é exigida por $n obrigação(ões). Troque a característica dessas obrigações antes.", 422);
        }
        $this->db()->prepare('DELETE FROM caracteristicas WHERE id = ? AND escritorio_id = ?')->execute([$id, $u['escritorio_id']]);

        return ['ok' => true];
    }

    public function feriadoSalvar(): array
    {
        $u = $this->exigirLogin();
        $this->exigirPapel($u, ['admin']);
        $b = $this->corpo();
        $data = $this->texto($b, 'data', 10, true);
        $d = DateTime::createFromFormat('Y-m-d', $data);
        if (!$d || $d->format('Y-m-d') !== $data) {
            throw new HttpErro('Data inválida.', 422);
        }
        $desc = $this->texto($b, 'descricao', 80, true);
        $this->db()->prepare('INSERT INTO feriados (escritorio_id, data, descricao) VALUES (?, ?, ?) ON CONFLICT (escritorio_id, data) DO UPDATE SET descricao = EXCLUDED.descricao')
            ->execute([$u['escritorio_id'], $data, $desc]);

        return ['ok' => true];
    }

    public function feriadoExcluir(): array
    {
        $u = $this->exigirLogin();
        $this->exigirPapel($u, ['admin']);
        $data = $this->texto($this->corpo(), 'data', 10, true);
        $this->db()->prepare('DELETE FROM feriados WHERE escritorio_id = ? AND data = ?')->execute([$u['escritorio_id'], $data]);

        return ['ok' => true];
    }
}
