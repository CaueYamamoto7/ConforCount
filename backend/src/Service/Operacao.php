<?php
declare(strict_types=1);

namespace App\Service;

use App\Exception\HttpErro;
use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\Http\ServerRequest;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use PDO;

class Operacao
{
    protected const STATUS_VALIDOS = ['pendente', 'andamento', 'aguardando', 'concluida', 'na'];
    protected const REGIMES_VALIDOS = ['MEI', 'Simples Nacional', 'Lucro Presumido', 'Lucro Real'];
    protected const PAPEIS_VALIDOS = ['admin', 'contador', 'colaborador'];
    protected const HASH_FALSO = '$2y$10$jr3lPV0yzaqk.0Z/ka39qeunxOCIq.weISunehFSD7D6dd/ye78Na';
    protected const MSG_LOGIN = 'CNPJ, e-mail ou senha incorretos.';

    public function __construct(protected ServerRequest $request)
    {
    }

    protected function iniciarSessao(): void
    {
        $this->request->getSession()->start();
    }

    protected function db(): PDO
    {
        return ConnectionManager::get('default')->getDriver()->pdo();
    }

    protected function tabela(string $nome): Table
    {
        return TableRegistry::getTableLocator()->get($nome);
    }

    protected function cfg(string $chave, mixed $padrao): mixed
    {
        return Configure::read('Conforcont.' . $chave, $padrao);
    }

    protected function corpo(): array
    {
        $bruto = (string)$this->request->getBody();
        if ($bruto === '') {
            return [];
        }
        if (strlen($bruto) > 100000) {
            throw new HttpErro('Requisição grande demais.', 413);
        }
        $dados = json_decode($bruto, true);
        if (!is_array($dados)) {
            throw new HttpErro('Dados inválidos.', 400);
        }

        return $dados;
    }

    protected function inserirId(PDO $pdo, string $sql, array $campos): int
    {
        $st = $pdo->prepare($sql . ' RETURNING id');
        $st->execute($campos);

        return (int)$st->fetchColumn();
    }

    protected function agora(): string
    {
        return date('Y-m-d H:i:s');
    }

    protected function ipCliente(): string
    {
        if (getenv('VERCEL') === '1') {
            $ip = $this->request->getHeaderLine('X-Vercel-Forwarded-For');
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return substr((string)($this->request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
    }

    protected function usuarioAtual(): ?array
    {
        $this->iniciarSessao();
        $uid = (int)($this->request->getSession()->read('uid', 0));
        $eid = (int)($this->request->getSession()->read('eid', 0));
        if ($uid <= 0 || $eid <= 0) {
            return null;
        }
        $st = $this->db()->prepare(
            'SELECT u.id, u.nome, u.email, u.dono_plataforma, v.papel, e.id AS escritorio_id, e.nome AS escritorio_nome, e.cnpj AS escritorio_cnpj
               FROM usuarios u
               JOIN vinculos v ON v.usuario_id = u.id AND v.escritorio_id = ? AND v.ativo = 1
               JOIN escritorios e ON e.id = v.escritorio_id AND e.ativo = 1
              WHERE u.id = ? AND u.ativo = 1',
        );
        $st->execute([$eid, $uid]);
        $u = $st->fetch();
        if (!$u) {
            $this->request->getSession()->clear();

            return null;
        }
        $u['id'] = (int)$u['id'];
        $u['escritorio_id'] = (int)$u['escritorio_id'];
        $u['dono_plataforma'] = (int)$u['dono_plataforma'];

        return $u;
    }

    protected function exigirLogin(): array
    {
        $u = $this->usuarioAtual();
        if (!$u) {
            throw new HttpErro('Sessão expirada. Entre novamente.', 401);
        }

        return $u;
    }

    protected function exigirPapel(array $u, array $papeis): void
    {
        if (!in_array($u['papel'], $papeis, true)) {
            throw new HttpErro('Você não tem permissão para isso.', 403);
        }
    }

    protected function exigirDono(array $u): void
    {
        if (empty($u['dono_plataforma'])) {
            throw new HttpErro('Você não tem permissão para isso.', 403);
        }
    }

    protected function escritoriosDoUsuario(int $uid): array
    {
        $st = $this->db()->prepare(
            'SELECT e.id, e.nome, e.cnpj, v.papel
               FROM vinculos v JOIN escritorios e ON e.id = v.escritorio_id
              WHERE v.usuario_id = ? AND v.ativo = 1 AND e.ativo = 1 ORDER BY e.nome',
        );
        $st->execute([$uid]);

        return array_map(fn($r) => ['id' => (int)$r['id'], 'nome' => $r['nome'], 'cnpj' => $r['cnpj'], 'papel' => $r['papel']], $st->fetchAll());
    }

    public function exigirCsrf(): void
    {
        $this->iniciarSessao();
        $enviado = (string)($this->request->getHeaderLine('X-CSRF-Token'));
        $esperado = (string)($this->request->getSession()->read('csrf', ''));
        if ($esperado === '' || !hash_equals($esperado, $enviado)) {
            throw new HttpErro('Sessão inválida. Recarregue a página.', 403);
        }
    }

    protected function texto(array $b, string $campo, int $max, bool $obrigatorio = false): string
    {
        $v = $b[$campo] ?? '';
        $v = is_scalar($v) ? trim((string)$v) : '';
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $v) ?? '';
        if ($obrigatorio && $v === '') {
            throw new HttpErro('Preencha o campo: ' . $campo . '.', 422);
        }
        if (mb_strlen($v) > $max) {
            throw new HttpErro('Texto longo demais no campo: ' . $campo . '.', 422);
        }

        return $v;
    }

    protected function booleano(array $b, string $campo): int
    {
        return !empty($b[$campo]) ? 1 : 0;
    }

    protected function mesValido(string $mes): string
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) {
            throw new HttpErro('Mês inválido.', 422);
        }

        return $mes;
    }

    protected function senhaValida(string $senha): void
    {
        $min = max(8, (int)$this->cfg('senha_minima', 8));
        if (mb_strlen($senha) < $min) {
            throw new HttpErro("A senha precisa ter pelo menos $min caracteres.", 422);
        }
        if (mb_strlen($senha) > 200) {
            throw new HttpErro('Senha longa demais.', 422);
        }
    }

    protected function pertence(string $tabela, int $id, int $eid): bool
    {
        if (!in_array($tabela, ['departamentos', 'caracteristicas', 'clientes', 'modelos'], true) || $id <= 0) {
            return false;
        }

        return $this->tabela(ucfirst($tabela))->exists(['id' => $id, 'escritorio_id' => $eid]);
    }

    protected function mesOpcional(array $b, string $campo): ?string
    {
        $v = $this->texto($b, $campo, 7);

        return $v === '' ? null : $this->mesValido($v);
    }

    protected function usuarioJson(array $r, bool $completo): array
    {
        $o = ['id' => (int)$r['id'], 'nome' => $r['nome'], 'papel' => $r['papel'], 'ativo' => (bool)$r['ativo']];
        if ($completo) {
            $o['email'] = $r['email'];
            $o['ultimoLogin'] = $r['ultimo_login'] ?? null;
            $o['dono'] = !empty($r['dono_plataforma']);
            $o['escritorios'] = (int)($r['qtd_vinculos'] ?? 1);
        }

        return $o;
    }

    protected function sessaoJson(array $u): array
    {
        return [
            'usuario' => ['id' => $u['id'], 'nome' => $u['nome'], 'email' => $u['email'], 'papel' => $u['papel'], 'dono' => (bool)$u['dono_plataforma']],
            'escritorio' => ['id' => $u['escritorio_id'], 'nome' => $u['escritorio_nome'], 'cnpj' => $u['escritorio_cnpj']],
            'escritorios' => $this->escritoriosDoUsuario($u['id']),
            'csrf' => $this->request->getSession()->read('csrf', ''),
        ];
    }

    protected function modeloJson(array $r): array
    {
        return [
            'id' => (int)$r['id'],
            'nome' => $r['nome'],
            'deptoId' => (int)$r['departamento_id'],
            'regimes' => $r['regimes'] === '' ? [] : explode(',', $r['regimes']),
            'caractId' => $r['caracteristica_id'] === null ? null : (int)$r['caracteristica_id'],
            'meses' => $r['meses'] === '' ? [] : array_map('intval', explode(',', $r['meses'])),
            'vencTipo' => $r['venc_tipo'],
            'vencValor' => (int)$r['venc_valor'],
            'ajuste' => $r['ajuste'],
            'desde' => $r['vigente_desde'],
            'obs' => $r['observacao'] ?? '',
            'ativo' => (bool)$r['ativo'],
        ];
    }
}
