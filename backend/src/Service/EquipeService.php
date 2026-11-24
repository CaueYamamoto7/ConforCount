<?php
declare(strict_types=1);

namespace App\Service;

use App\Exception\HttpErro;
use Throwable;

class EquipeService extends Operacao
{
    protected function outrosAdminsAtivos(int $eid, int $excetoUsuario): int
    {
        $st = $this->db()->prepare("SELECT COUNT(*) FROM vinculos v JOIN usuarios u ON u.id = v.usuario_id
                              WHERE v.escritorio_id = ? AND v.papel = 'admin' AND v.ativo = 1 AND u.ativo = 1 AND v.usuario_id <> ?");
        $st->execute([$eid, $excetoUsuario]);

        return (int)$st->fetchColumn();
    }

    public function usuarioSalvar(): array
    {
        $u = $this->exigirLogin();
        $this->exigirPapel($u, ['admin']);
        $eid = $u['escritorio_id'];
        $b = $this->corpo();
        $id = (int)($b['id'] ?? 0);
        $nome = $this->texto($b, 'nome', 120, true);
        $email = mb_strtolower($this->texto($b, 'email', 160, true));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpErro('E-mail inválido.', 422);
        }
        $papel = (string)($b['papel'] ?? '');
        if (!in_array($papel, self::PAPEIS_VALIDOS, true)) {
            throw new HttpErro('Papel inválido.', 422);
        }
        $ativo = $this->booleano($b, 'ativo');
        $senha = is_string($b['senha'] ?? null) ? $b['senha'] : '';
        $pdo = $this->db();

        if ($id > 0) {
            $st = $pdo->prepare('SELECT u.id, u.nome, u.email, u.dono_plataforma, v.papel, v.ativo,
                                        (SELECT COUNT(*) FROM vinculos x WHERE x.usuario_id = u.id) AS qtd
                                   FROM vinculos v JOIN usuarios u ON u.id = v.usuario_id
                                  WHERE u.id = ? AND v.escritorio_id = ?');
            $st->execute([$id, $eid]);
            $atual = $st->fetch();
            if (!$atual) {
                throw new HttpErro('Usuário não encontrado neste escritório.', 404);
            }
            if ($id === $u['id']) { // não deixa o administrador se trancar para fora
                $papel = 'admin';
                $ativo = 1;
            }
            if ($atual['papel'] === 'admin' && (int)$atual['ativo'] === 1 && ($papel !== 'admin' || !$ativo) && $this->outrosAdminsAtivos($eid, $id) < 1) {
                throw new HttpErro('É preciso manter ao menos um administrador ativo.', 422);
            }
            $compartilhado = (int)$atual['qtd'] > 1 || (int)$atual['dono_plataforma'] === 1;
            $mudaIdentidade = $nome !== $atual['nome'] || $email !== $atual['email'] || $senha !== '';
            if ($mudaIdentidade && $compartilhado) {
                throw new HttpErro('Esta pessoa atende mais de um escritório: nome, e-mail e senha só ela mesma altera (em "Minha conta").', 422);
            }
            $pdo->beginTransaction();
            try {
                if ($mudaIdentidade) {
                    $st = $pdo->prepare('SELECT 1 FROM usuarios WHERE email = ? AND id <> ?');
                    $st->execute([$email, $id]);
                    if ($st->fetchColumn()) {
                        throw new HttpErro('Já existe um usuário com este e-mail.', 422);
                    }
                    $pdo->prepare('UPDATE usuarios SET nome = ?, email = ? WHERE id = ?')->execute([$nome, $email, $id]);
                    if ($senha !== '') {
                        $this->senhaValida($senha);
                        $pdo->prepare('UPDATE usuarios SET senha_hash = ? WHERE id = ?')->execute([password_hash($senha, PASSWORD_DEFAULT), $id]);
                    }
                }
                $pdo->prepare('UPDATE vinculos SET papel = ?, ativo = ? WHERE usuario_id = ? AND escritorio_id = ?')->execute([$papel, $ativo, $id, $eid]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            return ['ok' => true, 'id' => $id];
        }

        // novo: se o e-mail já existe na plataforma, apenas vincula a pessoa a este escritório
        $st = $pdo->prepare('SELECT id FROM usuarios WHERE email = ?');
        $st->execute([$email]);
        $existente = (int)$st->fetchColumn();
        if ($existente > 0) {
            $st = $pdo->prepare('SELECT 1 FROM vinculos WHERE usuario_id = ? AND escritorio_id = ?');
            $st->execute([$existente, $eid]);
            if ($st->fetchColumn()) {
                throw new HttpErro('Esta pessoa já faz parte da equipe deste escritório.', 422);
            }
            $pdo->prepare('INSERT INTO vinculos (usuario_id, escritorio_id, papel, ativo) VALUES (?, ?, ?, ?)')->execute([$existente, $eid, $papel, $ativo]);

            return ['ok' => true, 'id' => $existente, 'vinculado' => true];
        }
        $this->senhaValida($senha);
        $pdo->beginTransaction();
        try {
            $novo = $this->inserirId($pdo, 'INSERT INTO usuarios (nome, email, senha_hash, ativo) VALUES (?, ?, ?, 1)', [$nome, $email, password_hash($senha, PASSWORD_DEFAULT)]);
            $pdo->prepare('INSERT INTO vinculos (usuario_id, escritorio_id, papel, ativo) VALUES (?, ?, ?, ?)')->execute([$novo, $eid, $papel, $ativo]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['ok' => true, 'id' => $novo];
    }

    public function usuarioExcluir(): array
    {
        $u = $this->exigirLogin();
        $this->exigirPapel($u, ['admin']);
        $eid = $u['escritorio_id'];
        $id = (int)($this->corpo()['id'] ?? 0);
        if ($id === $u['id']) {
            throw new HttpErro('Você não pode excluir o próprio usuário.', 422);
        }
        $pdo = $this->db();
        $st = $pdo->prepare('SELECT v.papel, v.ativo, u.dono_plataforma FROM vinculos v JOIN usuarios u ON u.id = v.usuario_id WHERE v.usuario_id = ? AND v.escritorio_id = ?');
        $st->execute([$id, $eid]);
        $alvo = $st->fetch();
        if (!$alvo) {
            throw new HttpErro('Usuário não encontrado neste escritório.', 404);
        }
        if ($alvo['papel'] === 'admin' && (int)$alvo['ativo'] === 1 && $this->outrosAdminsAtivos($eid, $id) < 1) {
            throw new HttpErro('É preciso manter ao menos um administrador ativo.', 422);
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE clientes SET responsavel_id = NULL WHERE escritorio_id = ? AND responsavel_id = ?')->execute([$eid, $id]);
            $pdo->prepare('DELETE FROM vinculos WHERE usuario_id = ? AND escritorio_id = ?')->execute([$id, $eid]);
            $st = $pdo->prepare('SELECT COUNT(*) FROM vinculos WHERE usuario_id = ?');
            $st->execute([$id]);
            if ((int)$st->fetchColumn() === 0 && !(int)$alvo['dono_plataforma']) {
                $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$id]); // sem vínculos e sem papel de dono: some da plataforma
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['ok' => true];
    }
}
