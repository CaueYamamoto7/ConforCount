<?php
declare(strict_types=1);

namespace App\Service;

use App\Exception\HttpErro;

class AuthService extends Operacao
{
    public function me(): array
    {
        $u = $this->usuarioAtual();
        if (!$u) {
            return ['usuario' => null];
        }

        return $this->sessaoJson($u);
    }

    public function login(): array
    {
        $this->iniciarSessao();
        $b = $this->corpo();
        $cnpj = Cnpj::normalizar($this->texto($b, 'cnpj', 30));
        $email = mb_strtolower($this->texto($b, 'email', 160));
        $senha = is_string($b['senha'] ?? null) ? $b['senha'] : '';
        $ip = $this->ipCliente();
        $pdo = $this->db();

        $janela = date('Y-m-d H:i:s', time() - max(1, (int)$this->cfg('login_janela_minutos', 15)) * 60);
        $max = max(3, (int)$this->cfg('login_max_tentativas', 8));
        $pdo->prepare('DELETE FROM login_tentativas WHERE criado_em < ?')->execute([date('Y-m-d H:i:s', time() - 86400)]);
        $st = $pdo->prepare('SELECT COUNT(*) FROM login_tentativas WHERE criado_em >= ? AND email = ?');
        $st->execute([$janela, $email]);
        $porEmail = (int)$st->fetchColumn();
        $st = $pdo->prepare('SELECT COUNT(*) FROM login_tentativas WHERE criado_em >= ? AND ip = ?');
        $st->execute([$janela, $ip]);
        $porIp = (int)$st->fetchColumn();
        if ($porEmail >= $max || $porIp >= $max * 5) {
            throw new HttpErro('Muitas tentativas. Aguarde alguns minutos e tente de novo.', 429);
        }

        // O CNPJ é público; então a falha é sempre a mesma mensagem, para não revelar quais escritórios ou e-mails existem.
        if (!Cnpj::valido($cnpj)) {
            $pdo->prepare('INSERT INTO login_tentativas (email, ip, criado_em) VALUES (?, ?, ?)')->execute([$email, $ip, $this->agora()]);
            throw new HttpErro('CNPJ inválido. Confira os números e letras.', 422);
        }
        $st = $pdo->prepare('SELECT id FROM escritorios WHERE cnpj = ? AND ativo = 1');
        $st->execute([$cnpj]);
        $eid = (int)$st->fetchColumn();

        $u = $this->tabela('Usuarios')->find()->select(['id', 'senha_hash', 'ativo'])
            ->where(['email' => $email])->first();
        $ok = password_verify($senha, $u ? $u['senha_hash'] : self::HASH_FALSO);

        $vinculado = false;
        if ($u && $eid > 0) {
            $st = $pdo->prepare('SELECT 1 FROM vinculos WHERE usuario_id = ? AND escritorio_id = ? AND ativo = 1');
            $st->execute([$u['id'], $eid]);
            $vinculado = (bool)$st->fetchColumn();
        }
        if (!$u || !$ok || !(int)$u['ativo'] || $eid <= 0 || !$vinculado) {
            $pdo->prepare('INSERT INTO login_tentativas (email, ip, criado_em) VALUES (?, ?, ?)')->execute([$email, $ip, $this->agora()]);
            usleep(300000);
            throw new HttpErro(self::MSG_LOGIN, 401);
        }
        if (password_needs_rehash($u['senha_hash'], PASSWORD_DEFAULT)) {
            $pdo->prepare('UPDATE usuarios SET senha_hash = ? WHERE id = ?')->execute([password_hash($senha, PASSWORD_DEFAULT), $u['id']]);
        }
        $pdo->prepare('DELETE FROM login_tentativas WHERE email = ?')->execute([$email]);
        $pdo->prepare('UPDATE usuarios SET ultimo_login = ? WHERE id = ?')->execute([$this->agora(), $u['id']]);

        session_regenerate_id(true);
        $this->request->getSession()->write('uid', (int)$u['id']);
        $this->request->getSession()->write('eid', $eid);
        $this->request->getSession()->write('csrf', bin2hex(random_bytes(32)));
        $this->request->getSession()->write('ultimo', time());
        $logado = $this->usuarioAtual();

        return $this->sessaoJson($logado);
    }

    public function logout(): array
    {
        $this->iniciarSessao();

        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Strict']);
        }
        $this->request->getSession()->destroy();

        return ['ok' => true];
    }

    public function senha(): array
    {
        $u = $this->exigirLogin();
        $b = $this->corpo();
        $atual = is_string($b['atual'] ?? null) ? $b['atual'] : '';
        $nova = is_string($b['nova'] ?? null) ? $b['nova'] : '';
        $st = $this->db()->prepare('SELECT senha_hash FROM usuarios WHERE id = ?');
        $st->execute([$u['id']]);
        $hash = (string)$st->fetchColumn();
        if (!password_verify($atual, $hash)) {
            throw new HttpErro('A senha atual não confere.', 422);
        }
        $this->senhaValida($nova);
        if ($nova === $atual) {
            throw new HttpErro('A nova senha precisa ser diferente da atual.', 422);
        }
        $this->db()->prepare('UPDATE usuarios SET senha_hash = ? WHERE id = ?')->execute([password_hash($nova, PASSWORD_DEFAULT), $u['id']]);
        session_regenerate_id(true);

        return ['ok' => true];
    }

    public function trocar(): array
    {
        $u = $this->exigirLogin();
        $id = (int)($this->corpo()['id'] ?? 0);
        $st = $this->db()->prepare('SELECT 1 FROM vinculos v JOIN escritorios e ON e.id = v.escritorio_id
                              WHERE v.usuario_id = ? AND v.escritorio_id = ? AND v.ativo = 1 AND e.ativo = 1');
        $st->execute([$u['id'], $id]);
        if (!$st->fetchColumn()) {
            throw new HttpErro('Você não tem acesso a este escritório.', 403);
        }
        $this->request->getSession()->write('eid', $id);

        return $this->sessaoJson($this->exigirLogin());
    }
}
