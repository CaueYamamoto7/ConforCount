<?php
declare(strict_types=1);

namespace App\Service;

use stdClass;

class PainelService extends Operacao
{
    public function dados(): array
    {
        $u = $this->exigirLogin();
        $eid = $u['escritorio_id'];
        $mes = $this->mesValido((string)($this->request->getQuery('mes', date('Y-m'))));
        $pdo = $this->db();
        $colab = $u['papel'] === 'colaborador';
        $admin = $u['papel'] === 'admin';

        $st = $pdo->prepare('SELECT u.id, u.nome, u.email, u.dono_plataforma, u.ultimo_login, v.papel, v.ativo,
                                    (SELECT COUNT(*) FROM vinculos x WHERE x.usuario_id = u.id AND x.ativo = 1) AS qtd_vinculos
                               FROM vinculos v JOIN usuarios u ON u.id = v.usuario_id
                              WHERE v.escritorio_id = ?' . ($admin ? '' : ' AND v.ativo = 1 AND u.ativo = 1') . ' ORDER BY u.nome');
        $st->execute([$eid]);
        $usuarios = array_map(fn($r) => $this->usuarioJson($r, $admin), $st->fetchAll());

        $departamentos = $this->cadastroOrdenado('Departamentos', $eid);
        $caracteristicas = $this->cadastroOrdenado('Caracteristicas', $eid);
        $consultaClientes = $this->tabela('Clientes')->find()
            ->where(['escritorio_id' => $eid])->orderBy(['nome' => 'ASC'])->disableHydration();
        if ($colab) {
            $consultaClientes->where(['responsavel_id' => $u['id']]);
        }
        $clientes = [];
        $idx = [];
        foreach ($consultaClientes as $r) {
            $clientes[] = [
                'id' => (int)$r['id'], 'nome' => $r['nome'], 'cnpj' => $r['cnpj'] ?? '', 'regime' => $r['regime'],
                'respId' => $r['responsavel_id'] === null ? null : (int)$r['responsavel_id'],
                'ativo' => (bool)$r['ativo'], 'inicio' => $r['inicio'], 'fim' => $r['fim'], 'obs' => $r['obs'] ?? '',
                'caract' => [], 'excecoes' => new stdClass(),
            ];
            $idx[(int)$r['id']] = count($clientes) - 1;
        }
        if ($idx) {
            $st = $pdo->prepare('SELECT cc.cliente_id, cc.caracteristica_id FROM cliente_caracteristicas cc
                                   JOIN clientes c ON c.id = cc.cliente_id WHERE c.escritorio_id = ?');
            $st->execute([$eid]);
            foreach ($st->fetchAll() as $r) {
                if (isset($idx[(int)$r['cliente_id']])) {
                    $clientes[$idx[(int)$r['cliente_id']]]['caract'][] = (int)$r['caracteristica_id'];
                }
            }
            $st = $pdo->prepare('SELECT cm.cliente_id, cm.modelo_id, cm.acao FROM cliente_modelos cm
                                   JOIN clientes c ON c.id = cm.cliente_id WHERE c.escritorio_id = ?');
            $st->execute([$eid]);
            $exc = [];
            foreach ($st->fetchAll() as $r) {
                $exc[(int)$r['cliente_id']][(string)(int)$r['modelo_id']] = $r['acao'];
            }
            foreach ($exc as $cid => $mapa) {
                if (isset($idx[$cid])) {
                    $clientes[$idx[$cid]]['excecoes'] = $mapa;
                }
            }
        }

        $modelos = $this->tabela('Modelos')->find()
            ->where(['escritorio_id' => $eid])->orderBy(['id' => 'ASC'])->disableHydration()
            ->all()->map($this->modeloJson(...))->toList();

        $sql = 'SELECT t.cliente_id, t.modelo_id, t.status, t.obs, t.atualizado_em, uu.nome AS por
                  FROM tarefas t
                  JOIN clientes c ON c.id = t.cliente_id
                  LEFT JOIN usuarios uu ON uu.id = t.atualizado_por
                 WHERE t.escritorio_id = ? AND t.mes = ?' . ($colab ? ' AND c.responsavel_id = ?' : '');
        $st = $pdo->prepare($sql);
        $st->execute($colab ? [$eid, $mes, $u['id']] : [$eid, $mes]);
        $tarefas = [];
        foreach ($st->fetchAll() as $r) {
            $tarefas[] = [
                'clienteId' => (int)$r['cliente_id'], 'modeloId' => (int)$r['modelo_id'], 'status' => $r['status'],
                'obs' => $r['obs'], 'por' => $r['por'] ?? '', 'em' => $r['atualizado_em'],
            ];
        }

        $st = $pdo->prepare('SELECT data, descricao FROM feriados WHERE escritorio_id = ? ORDER BY data');
        $st->execute([$eid]);

        return [
            'mes' => $mes,
            'usuarios' => $usuarios,
            'departamentos' => $departamentos,
            'caracteristicas' => $caracteristicas,
            'clientes' => $clientes,
            'modelos' => $modelos,
            'tarefas' => $tarefas,
            'feriados' => $st->fetchAll(),
        ];
    }

    private function cadastroOrdenado(string $tabela, int $escritorio): array
    {
        return $this->tabela($tabela)->find()->select(['id', 'nome'])
            ->where(['escritorio_id' => $escritorio])->orderBy(['ordem' => 'ASC', 'nome' => 'ASC'])
            ->disableHydration()->all()->toList();
    }
}
