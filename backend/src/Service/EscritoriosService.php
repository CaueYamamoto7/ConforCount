<?php
declare(strict_types=1);

namespace App\Service;

use App\Exception\HttpErro;
use Throwable;

class EscritoriosService extends Operacao
{
    public function plataformaLista(): array
    {
        $u = $this->exigirLogin();
        $this->exigirDono($u);
        $rows = $this->db()->query('SELECT e.id, e.cnpj, e.nome, e.ativo, e.criado_em,
                                    (SELECT COUNT(*) FROM vinculos v WHERE v.escritorio_id = e.id AND v.ativo = 1) AS pessoas,
                                    (SELECT COUNT(*) FROM clientes c WHERE c.escritorio_id = e.id AND c.ativo = 1) AS clientes
                               FROM escritorios e ORDER BY e.nome')->fetchAll();

        return ['escritorios' => array_map(fn($r) => [
            'id' => (int)$r['id'], 'cnpj' => $r['cnpj'], 'nome' => $r['nome'], 'ativo' => (bool)$r['ativo'],
            'criadoEm' => $r['criado_em'], 'pessoas' => (int)$r['pessoas'], 'clientes' => (int)$r['clientes'],
        ], $rows)];
    }

    public function plataformaSalvar(): array
    {
        $u = $this->exigirLogin();
        $this->exigirDono($u);
        $b = $this->corpo();
        $id = (int)($b['id'] ?? 0);
        $nome = $this->texto($b, 'nome', 150, true);
        $cnpj = Cnpj::normalizar($this->texto($b, 'cnpj', 30, true));
        if (!Cnpj::valido($cnpj)) {
            throw new HttpErro('CNPJ inválido. Confira os números e letras.', 422);
        }
        $ativo = $this->booleano($b, 'ativo');
        $pdo = $this->db();
        $st = $pdo->prepare('SELECT 1 FROM escritorios WHERE cnpj = ? AND id <> ?');
        $st->execute([$cnpj, $id]);
        if ($st->fetchColumn()) {
            throw new HttpErro('Já existe um escritório com este CNPJ.', 422);
        }

        if ($id > 0) {
            $st = $pdo->prepare('SELECT 1 FROM escritorios WHERE id = ?');
            $st->execute([$id]);
            if (!$st->fetchColumn()) {
                throw new HttpErro('Escritório não encontrado.', 404);
            }
            if ($id === $u['escritorio_id'] && (!$ativo || $cnpj !== $u['escritorio_cnpj'])) {
                throw new HttpErro('Para desativar ou trocar o CNPJ do escritório em que você está, troque antes para outro escritório.', 422);
            }
            $pdo->prepare('UPDATE escritorios SET nome = ?, cnpj = ?, ativo = ? WHERE id = ?')->execute([$nome, $cnpj, $ativo, $id]);

            return ['ok' => true, 'id' => $id];
        }

        // novo escritório: cria, copia o cadastro-padrão e liga o primeiro administrador
        $adminNome = $this->texto($b, 'adminNome', 120, true);
        $adminEmail = mb_strtolower($this->texto($b, 'adminEmail', 160, true));
        if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            throw new HttpErro('E-mail do administrador inválido.', 422);
        }
        $adminSenha = is_string($b['adminSenha'] ?? null) ? $b['adminSenha'] : '';
        $st = $pdo->prepare('SELECT id FROM usuarios WHERE email = ?');
        $st->execute([$adminEmail]);
        $existente = (int)$st->fetchColumn();
        $jaExistia = $existente > 0;
        if (!$jaExistia) {
            $this->senhaValida($adminSenha);
        }
        $pdo->beginTransaction();
        try {
            $novo = $this->inserirId($pdo, 'INSERT INTO escritorios (cnpj, nome, ativo) VALUES (?, ?, 1)', [$cnpj, $nome]);
            CatalogoPadrao::semearEscritorio($pdo, $novo);
            if ($existente === 0) {
                $existente = $this->inserirId($pdo, 'INSERT INTO usuarios (nome, email, senha_hash, ativo) VALUES (?, ?, ?, 1)', [$adminNome, $adminEmail, password_hash($adminSenha, PASSWORD_DEFAULT)]);
            }
            $pdo->prepare('INSERT INTO vinculos (usuario_id, escritorio_id, papel, ativo) VALUES (?, ?, \'admin\', 1)')->execute([$existente, $novo]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['ok' => true, 'id' => $novo, 'adminJaExistia' => $jaExistia];
    }
}
