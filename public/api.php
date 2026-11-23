<?php
declare(strict_types=1);

/**
 * API do Controle de Obrigações. Recebe e devolve JSON.
 * Rotas: api.php?r=<rota>   (veja o switch em rotear()).
 */

require __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const STATUS_VALIDOS = ['pendente', 'andamento', 'aguardando', 'concluida', 'na'];
const REGIMES_VALIDOS = ['MEI', 'Simples Nacional', 'Lucro Presumido', 'Lucro Real'];
const PAPEIS_VALIDOS = ['admin', 'contador', 'colaborador'];
// Hash fixo só para gastar o mesmo tempo quando o e-mail não existe (evita descobrir e-mails pelo tempo de resposta).
const HASH_FALSO = '$2y$10$jr3lPV0yzaqk.0Z/ka39qeunxOCIq.weISunehFSD7D6dd/ye78Na';
const MSG_LOGIN = 'CNPJ, e-mail ou senha incorretos.';

require __DIR__ . '/../app/padrao.php';

try {
    rotear();
} catch (HttpErro $e) {
    responder(['erro' => $e->getMessage()], $e->codigo);
} catch (Throwable $e) {
    if ($e instanceof PDOException && (string)$e->getCode() === '23000') {
        responder(['erro' => 'Não foi possível: o registro já existe ou está em uso.'], 409);
    }
    error_log('[obrigacoes] ' . $e);
    responder(['erro' => 'Erro interno. Tente de novo em instantes.'], 500);
}

function rotear()
{
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $rota = (string)($_GET['r'] ?? '');

    if ($metodo === 'GET') {
        switch ($rota) {
            case 'me':                    return rota_me();
            case 'dados':                 return rota_dados();
            case 'tarefa/historico':      return rota_historico();
            case 'plataforma/escritorios': return rota_plataforma_lista();
        }
    } elseif ($metodo === 'POST') {
        if ($rota === 'login') {
            return rota_login();
        }
        exigir_csrf();
        switch ($rota) {
            case 'logout':                      return rota_logout();
            case 'senha/alterar':               return rota_senha();
            case 'escritorio/trocar':           return rota_trocar();
            case 'tarefa/salvar':               return rota_tarefa_salvar();
            case 'cliente/salvar':              return rota_cliente_salvar();
            case 'cliente/excluir':             return rota_cliente_excluir();
            case 'modelo/salvar':               return rota_modelo_salvar();
            case 'modelo/excluir':              return rota_modelo_excluir();
            case 'departamento/salvar':         return rota_depto_salvar();
            case 'departamento/excluir':        return rota_depto_excluir();
            case 'caracteristica/salvar':       return rota_caract_salvar();
            case 'caracteristica/excluir':      return rota_caract_excluir();
            case 'usuario/salvar':              return rota_usuario_salvar();
            case 'usuario/excluir':             return rota_usuario_excluir();
            case 'feriado/salvar':              return rota_feriado_salvar();
            case 'feriado/excluir':             return rota_feriado_excluir();
            case 'plataforma/escritorio/salvar': return rota_plataforma_salvar();
        }
    } else {
        throw new HttpErro('Método não permitido.', 405);
    }
    throw new HttpErro('Rota não encontrada.', 404);
}

/** Confere que o registro (id) existe E pertence ao escritório. Tabelas em lista fixa. */
function pertence(string $tabela, int $id, int $eid): bool
{
    if (!in_array($tabela, ['departamentos', 'caracteristicas', 'clientes', 'modelos'], true) || $id <= 0) {
        return false;
    }
    $st = db()->prepare("SELECT 1 FROM $tabela WHERE id = ? AND escritorio_id = ?");
    $st->execute([$id, $eid]);
    return (bool)$st->fetchColumn();
}

function mes_opcional(array $b, string $campo): ?string
{
    $v = texto($b, $campo, 7);
    return $v === '' ? null : mes_valido($v);
}


/* ===================== conversores ===================== */

function usuario_json(array $r, bool $completo): array
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

function sessao_json(array $u): array
{
    return [
        'usuario' => ['id' => $u['id'], 'nome' => $u['nome'], 'email' => $u['email'], 'papel' => $u['papel'], 'dono' => (bool)$u['dono_plataforma']],
        'escritorio' => ['id' => $u['escritorio_id'], 'nome' => $u['escritorio_nome'], 'cnpj' => $u['escritorio_cnpj']],
        'escritorios' => escritorios_do_usuario($u['id']),
        'csrf' => $_SESSION['csrf'] ?? '',
    ];
}

function modelo_json(array $r): array
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

/* ===================== sessão ===================== */

function rota_me(): void
{
    $u = usuario_atual();
    if (!$u) {
        responder(['usuario' => null]);
    }
    responder(sessao_json($u));
}

function rota_login(): void
{
    iniciar_sessao();
    $b = corpo();
    $cnpj = cnpj_normalizar(texto($b, 'cnpj', 30));
    $email = mb_strtolower(texto($b, 'email', 160));
    $senha = is_string($b['senha'] ?? null) ? $b['senha'] : '';
    $ip = ip_cliente();
    $pdo = db();

    $janela = date('Y-m-d H:i:s', time() - max(1, (int)cfg('login_janela_minutos', 15)) * 60);
    $max = max(3, (int)cfg('login_max_tentativas', 8));
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
    if (!cnpj_valido($cnpj)) {
        $pdo->prepare('INSERT INTO login_tentativas (email, ip, criado_em) VALUES (?, ?, ?)')->execute([$email, $ip, agora()]);
        throw new HttpErro('CNPJ inválido. Confira os números e letras.', 422);
    }
    $st = $pdo->prepare('SELECT id FROM escritorios WHERE cnpj = ? AND ativo = 1');
    $st->execute([$cnpj]);
    $eid = (int)$st->fetchColumn();

    $st = $pdo->prepare('SELECT id, senha_hash, ativo FROM usuarios WHERE email = ?');
    $st->execute([$email]);
    $u = $st->fetch();
    $ok = password_verify($senha, $u ? $u['senha_hash'] : HASH_FALSO);

    $vinculado = false;
    if ($u && $eid > 0) {
        $st = $pdo->prepare('SELECT 1 FROM vinculos WHERE usuario_id = ? AND escritorio_id = ? AND ativo = 1');
        $st->execute([$u['id'], $eid]);
        $vinculado = (bool)$st->fetchColumn();
    }
    if (!$u || !$ok || !(int)$u['ativo'] || $eid <= 0 || !$vinculado) {
        $pdo->prepare('INSERT INTO login_tentativas (email, ip, criado_em) VALUES (?, ?, ?)')->execute([$email, $ip, agora()]);
        usleep(300000);
        throw new HttpErro(MSG_LOGIN, 401);
    }
    if (password_needs_rehash($u['senha_hash'], PASSWORD_DEFAULT)) {
        $pdo->prepare('UPDATE usuarios SET senha_hash = ? WHERE id = ?')->execute([password_hash($senha, PASSWORD_DEFAULT), $u['id']]);
    }
    $pdo->prepare('DELETE FROM login_tentativas WHERE email = ?')->execute([$email]);
    $pdo->prepare('UPDATE usuarios SET ultimo_login = ? WHERE id = ?')->execute([agora(), $u['id']]);

    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    $_SESSION['eid'] = $eid;
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    $_SESSION['ultimo'] = time();
    $logado = usuario_atual();
    responder(sessao_json($logado));
}

function rota_logout(): void
{
    iniciar_sessao();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Strict']);
    }
    session_destroy();
    responder(['ok' => true]);
}

function rota_trocar(): void
{
    $u = exigir_login();
    $id = (int)(corpo()['id'] ?? 0);
    $st = db()->prepare('SELECT 1 FROM vinculos v JOIN escritorios e ON e.id = v.escritorio_id
                          WHERE v.usuario_id = ? AND v.escritorio_id = ? AND v.ativo = 1 AND e.ativo = 1');
    $st->execute([$u['id'], $id]);
    if (!$st->fetchColumn()) {
        throw new HttpErro('Você não tem acesso a este escritório.', 403);
    }
    $_SESSION['eid'] = $id;
    responder(sessao_json(exigir_login()));
}

function rota_senha(): void
{
    $u = exigir_login();
    $b = corpo();
    $atual = is_string($b['atual'] ?? null) ? $b['atual'] : '';
    $nova = is_string($b['nova'] ?? null) ? $b['nova'] : '';
    $st = db()->prepare('SELECT senha_hash FROM usuarios WHERE id = ?');
    $st->execute([$u['id']]);
    $hash = (string)$st->fetchColumn();
    if (!password_verify($atual, $hash)) {
        throw new HttpErro('A senha atual não confere.', 422);
    }
    senha_valida($nova);
    if ($nova === $atual) {
        throw new HttpErro('A nova senha precisa ser diferente da atual.', 422);
    }
    db()->prepare('UPDATE usuarios SET senha_hash = ? WHERE id = ?')->execute([password_hash($nova, PASSWORD_DEFAULT), $u['id']]);
    session_regenerate_id(true);
    responder(['ok' => true]);
}

/* ===================== dados do mês ===================== */

function rota_dados(): void
{
    $u = exigir_login();
    $eid = $u['escritorio_id'];
    $mes = mes_valido((string)($_GET['mes'] ?? date('Y-m')));
    $pdo = db();
    $colab = $u['papel'] === 'colaborador';
    $admin = $u['papel'] === 'admin';

    $st = $pdo->prepare('SELECT u.id, u.nome, u.email, u.dono_plataforma, u.ultimo_login, v.papel, v.ativo,
                                (SELECT COUNT(*) FROM vinculos x WHERE x.usuario_id = u.id AND x.ativo = 1) AS qtd_vinculos
                           FROM vinculos v JOIN usuarios u ON u.id = v.usuario_id
                          WHERE v.escritorio_id = ?' . ($admin ? '' : ' AND v.ativo = 1 AND u.ativo = 1') . ' ORDER BY u.nome');
    $st->execute([$eid]);
    $usuarios = array_map(fn($r) => usuario_json($r, $admin), $st->fetchAll());

    $st = $pdo->prepare('SELECT id, nome FROM departamentos WHERE escritorio_id = ? ORDER BY ordem, nome');
    $st->execute([$eid]);
    $departamentos = array_map(fn($r) => ['id' => (int)$r['id'], 'nome' => $r['nome']], $st->fetchAll());

    $st = $pdo->prepare('SELECT id, nome FROM caracteristicas WHERE escritorio_id = ? ORDER BY ordem, nome');
    $st->execute([$eid]);
    $caracteristicas = array_map(fn($r) => ['id' => (int)$r['id'], 'nome' => $r['nome']], $st->fetchAll());

    $sql = 'SELECT * FROM clientes WHERE escritorio_id = ?' . ($colab ? ' AND responsavel_id = ?' : '') . ' ORDER BY nome';
    $st = $pdo->prepare($sql);
    $st->execute($colab ? [$eid, $u['id']] : [$eid]);
    $clientes = [];
    $idx = [];
    foreach ($st->fetchAll() as $r) {
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

    $st = $pdo->prepare('SELECT * FROM modelos WHERE escritorio_id = ? ORDER BY id');
    $st->execute([$eid]);
    $modelos = array_map('modelo_json', $st->fetchAll());

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

    $st = $pdo->prepare('SELECT DATE_FORMAT(data, "%Y-%m-%d") AS data, descricao FROM feriados WHERE escritorio_id = ? ORDER BY data');
    $st->execute([$eid]);

    responder([
        'mes' => $mes,
        'usuarios' => $usuarios,
        'departamentos' => $departamentos,
        'caracteristicas' => $caracteristicas,
        'clientes' => $clientes,
        'modelos' => $modelos,
        'tarefas' => $tarefas,
        'feriados' => $st->fetchAll(),
    ]);
}

/* ===================== tarefas ===================== */

/** Garante que o cliente é DESTE escritório e que o usuário pode mexer nele; devolve a linha do cliente. */
function cliente_permitido(array $u, int $clienteId): array
{
    $st = db()->prepare('SELECT * FROM clientes WHERE id = ? AND escritorio_id = ?');
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

function rota_tarefa_salvar(): void
{
    $u = exigir_login();
    $eid = $u['escritorio_id'];
    $b = corpo();
    $mes = mes_valido((string)($b['mes'] ?? ''));
    $cid = (int)($b['clienteId'] ?? 0);
    $mid = (int)($b['modeloId'] ?? 0);
    $status = (string)($b['status'] ?? '');
    if (!in_array($status, STATUS_VALIDOS, true)) {
        throw new HttpErro('Situação inválida.', 422);
    }
    $obs = texto($b, 'obs', 1000);
    cliente_permitido($u, $cid);
    if (!pertence('modelos', $mid, $eid)) {
        throw new HttpErro('Obrigação não encontrada.', 404);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
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
                           ON DUPLICATE KEY UPDATE status = VALUES(status), obs = VALUES(obs),
                                                   atualizado_por = VALUES(atualizado_por), atualizado_em = VALUES(atualizado_em)')
                ->execute([$eid, $mes, $cid, $mid, $status, $obs, $u['id'], agora()]);
        }
        if ($statusAntes !== $status || $obsAntes !== $obs) {
            $pdo->prepare('INSERT INTO log_tarefas (escritorio_id, mes, cliente_id, modelo_id, usuario_id, status_antes, status_depois, obs, criado_em)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$eid, $mes, $cid, $mid, $u['id'], $statusAntes, $status, $obs, agora()]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    responder(['ok' => true]);
}

function rota_historico(): void
{
    $u = exigir_login();
    $mes = mes_valido((string)($_GET['mes'] ?? ''));
    $cid = (int)($_GET['cliente_id'] ?? 0);
    $mid = (int)($_GET['modelo_id'] ?? 0);
    cliente_permitido($u, $cid);
    $st = db()->prepare('SELECT l.criado_em, l.status_antes, l.status_depois, l.obs, uu.nome AS por
                           FROM log_tarefas l LEFT JOIN usuarios uu ON uu.id = l.usuario_id
                          WHERE l.escritorio_id = ? AND l.mes = ? AND l.cliente_id = ? AND l.modelo_id = ?
                          ORDER BY l.id DESC LIMIT 15');
    $st->execute([$u['escritorio_id'], $mes, $cid, $mid]);
    responder(['historico' => array_map(fn($r) => [
        'em' => $r['criado_em'], 'por' => $r['por'] ?? '(usuário removido)',
        'antes' => $r['status_antes'], 'depois' => $r['status_depois'], 'obs' => $r['obs'],
    ], $st->fetchAll())]);
}

/* ===================== clientes ===================== */

function rota_cliente_salvar(): void
{
    $u = exigir_login();
    exigir_papel($u, ['admin', 'contador']);
    $eid = $u['escritorio_id'];
    $b = corpo();
    $id = (int)($b['id'] ?? 0);
    $nome = texto($b, 'nome', 150, true);
    $cnpjBruto = texto($b, 'cnpj', 30);
    $cnpj = null;
    if ($cnpjBruto !== '') {
        $cnpj = cnpj_normalizar($cnpjBruto);
        if (!cnpj_valido($cnpj)) {
            throw new HttpErro('CNPJ inválido. Confira os números e letras.', 422);
        }
    }
    $regime = (string)($b['regime'] ?? '');
    if (!in_array($regime, REGIMES_VALIDOS, true)) {
        throw new HttpErro('Regime inválido.', 422);
    }
    $pdo = db();
    if ($id > 0 && !pertence('clientes', $id, $eid)) {
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
    $inicio = mes_opcional($b, 'inicio');
    $fim = mes_opcional($b, 'fim');
    if ($inicio !== null && $fim !== null && $fim < $inicio) {
        throw new HttpErro('O mês final não pode ser anterior ao inicial.', 422);
    }
    $obs = texto($b, 'obs', 500);
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
        if ($x > 0 && pertence('caracteristicas', $x, $eid)) {
            $caract[$x] = $x;
        }
    }
    $exc = [];
    foreach (is_array($b['excecoes'] ?? null) ? $b['excecoes'] : [] as $mid => $acao) {
        $mid = (int)$mid;
        if ($mid > 0 && in_array($acao, ['incluir', 'excluir'], true) && pertence('modelos', $mid, $eid)) {
            $exc[$mid] = $acao;
        }
    }

    $campos = [$nome, $cnpj, $regime, $resp > 0 ? $resp : null, booleano($b, 'ativo'), $inicio, $fim, $obs === '' ? null : $obs];
    $pdo->beginTransaction();
    try {
        if ($id > 0) {
            $pdo->prepare('UPDATE clientes SET nome=?, cnpj=?, regime=?, responsavel_id=?, ativo=?, inicio=?, fim=?, obs=? WHERE id=? AND escritorio_id=?')
                ->execute(array_merge($campos, [$id, $eid]));
        } else {
            $pdo->prepare('INSERT INTO clientes (nome, cnpj, regime, responsavel_id, ativo, inicio, fim, obs, escritorio_id) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute(array_merge($campos, [$eid]));
            $id = (int)$pdo->lastInsertId();
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
    responder(['ok' => true, 'id' => $id]);
}

function rota_cliente_excluir(): void
{
    $u = exigir_login();
    exigir_papel($u, ['admin', 'contador']);
    $id = (int)(corpo()['id'] ?? 0);
    if (!pertence('clientes', $id, $u['escritorio_id'])) {
        throw new HttpErro('Cliente não encontrado.', 404);
    }
    $pdo = db();
    $pdo->prepare('DELETE FROM log_tarefas WHERE escritorio_id = ? AND cliente_id = ?')->execute([$u['escritorio_id'], $id]);
    $pdo->prepare('DELETE FROM clientes WHERE id = ? AND escritorio_id = ?')->execute([$id, $u['escritorio_id']]);
    responder(['ok' => true]);
}

/* ===================== modelos ===================== */

function rota_modelo_salvar(): void
{
    $u = exigir_login();
    exigir_papel($u, ['admin']);
    $eid = $u['escritorio_id'];
    $b = corpo();
    $id = (int)($b['id'] ?? 0);
    $nome = texto($b, 'nome', 120, true);
    $deptoId = (int)($b['deptoId'] ?? 0);
    if (!pertence('departamentos', $deptoId, $eid)) {
        throw new HttpErro('Departamento inválido.', 422);
    }
    $caractId = (int)($b['caractId'] ?? 0);
    if ($caractId > 0 && !pertence('caracteristicas', $caractId, $eid)) {
        throw new HttpErro('Característica inválida.', 422);
    }
    if ($id > 0 && !pertence('modelos', $id, $eid)) {
        throw new HttpErro('Obrigação não encontrada.', 404);
    }
    $regimes = array_values(array_unique(array_filter(is_array($b['regimes'] ?? null) ? $b['regimes'] : [], fn($r) => in_array($r, REGIMES_VALIDOS, true))));
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
    $desde = mes_opcional($b, 'desde');
    $obs = texto($b, 'obs', 255);
    $campos = [$deptoId, $nome, implode(',', $regimes), $caractId > 0 ? $caractId : null, implode(',', $meses), $tipo, $valor, $ajuste, $desde, $obs === '' ? null : $obs, booleano($b, 'ativo')];
    $pdo = db();
    if ($id > 0) {
        $pdo->prepare('UPDATE modelos SET departamento_id=?, nome=?, regimes=?, caracteristica_id=?, meses=?, venc_tipo=?, venc_valor=?, ajuste=?, vigente_desde=?, observacao=?, ativo=? WHERE id=? AND escritorio_id=?')
            ->execute(array_merge($campos, [$id, $eid]));
    } else {
        $pdo->prepare('INSERT INTO modelos (departamento_id, nome, regimes, caracteristica_id, meses, venc_tipo, venc_valor, ajuste, vigente_desde, observacao, ativo, escritorio_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute(array_merge($campos, [$eid]));
        $id = (int)$pdo->lastInsertId();
    }
    responder(['ok' => true, 'id' => $id]);
}

function rota_modelo_excluir(): void
{
    $u = exigir_login();
    exigir_papel($u, ['admin']);
    $id = (int)(corpo()['id'] ?? 0);
    if (!pertence('modelos', $id, $u['escritorio_id'])) {
        throw new HttpErro('Obrigação não encontrada.', 404);
    }
    $pdo = db();
    $pdo->prepare('DELETE FROM log_tarefas WHERE escritorio_id = ? AND modelo_id = ?')->execute([$u['escritorio_id'], $id]);
    $pdo->prepare('DELETE FROM modelos WHERE id = ? AND escritorio_id = ?')->execute([$id, $u['escritorio_id']]);
    responder(['ok' => true]);
}

/* ===================== departamentos e características ===================== */

/** Cadastro simples com nome único por escritório (departamentos e caracteristicas). */
function cadastro_salvar(string $tabela, int $max, string $rotulo): void
{
    $u = exigir_login();
    exigir_papel($u, ['admin']);
    $eid = $u['escritorio_id'];
    $b = corpo();
    $id = (int)($b['id'] ?? 0);
    $nome = texto($b, 'nome', $max, true);
    $pdo = db();
    if ($id > 0 && !pertence($tabela, $id, $eid)) {
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
        $pdo->prepare("INSERT INTO $tabela (escritorio_id, nome, ordem) VALUES (?, ?, ?)")->execute([$eid, $nome, (int)$st->fetchColumn()]);
        $id = (int)$pdo->lastInsertId();
    }
    responder(['ok' => true, 'id' => $id]);
}

function rota_depto_salvar(): void
{
    cadastro_salvar('departamentos', 60, 'um departamento');
}

function rota_caract_salvar(): void
{
    cadastro_salvar('caracteristicas', 80, 'uma característica');
}

function rota_depto_excluir(): void
{
    $u = exigir_login();
    exigir_papel($u, ['admin']);
    $id = (int)(corpo()['id'] ?? 0);
    if (!pertence('departamentos', $id, $u['escritorio_id'])) {
        throw new HttpErro('Departamento não encontrado.', 404);
    }
    $st = db()->prepare('SELECT COUNT(*) FROM modelos WHERE departamento_id = ? AND escritorio_id = ?');
    $st->execute([$id, $u['escritorio_id']]);
    $n = (int)$st->fetchColumn();
    if ($n > 0) {
        throw new HttpErro("Este departamento ainda tem $n obrigação(ões). Mova ou exclua essas obrigações antes.", 422);
    }
    db()->prepare('DELETE FROM departamentos WHERE id = ? AND escritorio_id = ?')->execute([$id, $u['escritorio_id']]);
    responder(['ok' => true]);
}

function rota_caract_excluir(): void
{
    $u = exigir_login();
    exigir_papel($u, ['admin']);
    $id = (int)(corpo()['id'] ?? 0);
    if (!pertence('caracteristicas', $id, $u['escritorio_id'])) {
        throw new HttpErro('Característica não encontrada.', 404);
    }
    $st = db()->prepare('SELECT COUNT(*) FROM modelos WHERE caracteristica_id = ? AND escritorio_id = ?');
    $st->execute([$id, $u['escritorio_id']]);
    $n = (int)$st->fetchColumn();
    if ($n > 0) {
        throw new HttpErro("Esta característica é exigida por $n obrigação(ões). Troque a característica dessas obrigações antes.", 422);
    }
    db()->prepare('DELETE FROM caracteristicas WHERE id = ? AND escritorio_id = ?')->execute([$id, $u['escritorio_id']]);
    responder(['ok' => true]);
}

/* ===================== equipe (vínculos do escritório) ===================== */

function outros_admins_ativos(int $eid, int $excetoUsuario): int
{
    $st = db()->prepare("SELECT COUNT(*) FROM vinculos v JOIN usuarios u ON u.id = v.usuario_id
                          WHERE v.escritorio_id = ? AND v.papel = 'admin' AND v.ativo = 1 AND u.ativo = 1 AND v.usuario_id <> ?");
    $st->execute([$eid, $excetoUsuario]);
    return (int)$st->fetchColumn();
}

function rota_usuario_salvar(): void
{
    $u = exigir_login();
    exigir_papel($u, ['admin']);
    $eid = $u['escritorio_id'];
    $b = corpo();
    $id = (int)($b['id'] ?? 0);
    $nome = texto($b, 'nome', 120, true);
    $email = mb_strtolower(texto($b, 'email', 160, true));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new HttpErro('E-mail inválido.', 422);
    }
    $papel = (string)($b['papel'] ?? '');
    if (!in_array($papel, PAPEIS_VALIDOS, true)) {
        throw new HttpErro('Papel inválido.', 422);
    }
    $ativo = booleano($b, 'ativo');
    $senha = is_string($b['senha'] ?? null) ? $b['senha'] : '';
    $pdo = db();

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
        if ($id === $u['id']) {      // não deixa o administrador se trancar para fora
            $papel = 'admin';
            $ativo = 1;
        }
        if ($atual['papel'] === 'admin' && (int)$atual['ativo'] === 1 && ($papel !== 'admin' || !$ativo) && outros_admins_ativos($eid, $id) < 1) {
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
                    senha_valida($senha);
                    $pdo->prepare('UPDATE usuarios SET senha_hash = ? WHERE id = ?')->execute([password_hash($senha, PASSWORD_DEFAULT), $id]);
                }
            }
            $pdo->prepare('UPDATE vinculos SET papel = ?, ativo = ? WHERE usuario_id = ? AND escritorio_id = ?')->execute([$papel, $ativo, $id, $eid]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        responder(['ok' => true, 'id' => $id]);
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
        responder(['ok' => true, 'id' => $existente, 'vinculado' => true]);
    }
    senha_valida($senha);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO usuarios (nome, email, senha_hash, ativo) VALUES (?, ?, ?, 1)')->execute([$nome, $email, password_hash($senha, PASSWORD_DEFAULT)]);
        $novo = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO vinculos (usuario_id, escritorio_id, papel, ativo) VALUES (?, ?, ?, ?)')->execute([$novo, $eid, $papel, $ativo]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    responder(['ok' => true, 'id' => $novo]);
}

function rota_usuario_excluir(): void
{
    $u = exigir_login();
    exigir_papel($u, ['admin']);
    $eid = $u['escritorio_id'];
    $id = (int)(corpo()['id'] ?? 0);
    if ($id === $u['id']) {
        throw new HttpErro('Você não pode excluir o próprio usuário.', 422);
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT v.papel, v.ativo, u.dono_plataforma FROM vinculos v JOIN usuarios u ON u.id = v.usuario_id WHERE v.usuario_id = ? AND v.escritorio_id = ?');
    $st->execute([$id, $eid]);
    $alvo = $st->fetch();
    if (!$alvo) {
        throw new HttpErro('Usuário não encontrado neste escritório.', 404);
    }
    if ($alvo['papel'] === 'admin' && (int)$alvo['ativo'] === 1 && outros_admins_ativos($eid, $id) < 1) {
        throw new HttpErro('É preciso manter ao menos um administrador ativo.', 422);
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE clientes SET responsavel_id = NULL WHERE escritorio_id = ? AND responsavel_id = ?')->execute([$eid, $id]);
        $pdo->prepare('DELETE FROM vinculos WHERE usuario_id = ? AND escritorio_id = ?')->execute([$id, $eid]);
        $st = $pdo->prepare('SELECT COUNT(*) FROM vinculos WHERE usuario_id = ?');
        $st->execute([$id]);
        if ((int)$st->fetchColumn() === 0 && !(int)$alvo['dono_plataforma']) {
            $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$id]);   // sem vínculos e sem papel de dono: some da plataforma
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    responder(['ok' => true]);
}

/* ===================== feriados ===================== */

function rota_feriado_salvar(): void
{
    $u = exigir_login();
    exigir_papel($u, ['admin']);
    $b = corpo();
    $data = texto($b, 'data', 10, true);
    $d = DateTime::createFromFormat('Y-m-d', $data);
    if (!$d || $d->format('Y-m-d') !== $data) {
        throw new HttpErro('Data inválida.', 422);
    }
    $desc = texto($b, 'descricao', 80, true);
    db()->prepare('INSERT INTO feriados (escritorio_id, data, descricao) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE descricao = VALUES(descricao)')
        ->execute([$u['escritorio_id'], $data, $desc]);
    responder(['ok' => true]);
}

function rota_feriado_excluir(): void
{
    $u = exigir_login();
    exigir_papel($u, ['admin']);
    $data = texto(corpo(), 'data', 10, true);
    db()->prepare('DELETE FROM feriados WHERE escritorio_id = ? AND data = ?')->execute([$u['escritorio_id'], $data]);
    responder(['ok' => true]);
}

/* ===================== painel da plataforma (só o dono) ===================== */
/* Mostra apenas dados cadastrais e contagens dos escritórios — nunca clientes nem tarefas de outro escritório. */

function rota_plataforma_lista(): void
{
    $u = exigir_login();
    exigir_dono($u);
    $rows = db()->query('SELECT e.id, e.cnpj, e.nome, e.ativo, e.criado_em,
                                (SELECT COUNT(*) FROM vinculos v WHERE v.escritorio_id = e.id AND v.ativo = 1) AS pessoas,
                                (SELECT COUNT(*) FROM clientes c WHERE c.escritorio_id = e.id AND c.ativo = 1) AS clientes
                           FROM escritorios e ORDER BY e.nome')->fetchAll();
    responder(['escritorios' => array_map(fn($r) => [
        'id' => (int)$r['id'], 'cnpj' => $r['cnpj'], 'nome' => $r['nome'], 'ativo' => (bool)$r['ativo'],
        'criadoEm' => $r['criado_em'], 'pessoas' => (int)$r['pessoas'], 'clientes' => (int)$r['clientes'],
    ], $rows)]);
}

function rota_plataforma_salvar(): void
{
    $u = exigir_login();
    exigir_dono($u);
    $b = corpo();
    $id = (int)($b['id'] ?? 0);
    $nome = texto($b, 'nome', 150, true);
    $cnpj = cnpj_normalizar(texto($b, 'cnpj', 30, true));
    if (!cnpj_valido($cnpj)) {
        throw new HttpErro('CNPJ inválido. Confira os números e letras.', 422);
    }
    $ativo = booleano($b, 'ativo');
    $pdo = db();
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
        responder(['ok' => true, 'id' => $id]);
    }

    // novo escritório: cria, copia o cadastro-padrão e liga o primeiro administrador
    $adminNome = texto($b, 'adminNome', 120, true);
    $adminEmail = mb_strtolower(texto($b, 'adminEmail', 160, true));
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        throw new HttpErro('E-mail do administrador inválido.', 422);
    }
    $adminSenha = is_string($b['adminSenha'] ?? null) ? $b['adminSenha'] : '';
    $st = $pdo->prepare('SELECT id FROM usuarios WHERE email = ?');
    $st->execute([$adminEmail]);
    $existente = (int)$st->fetchColumn();
    $jaExistia = $existente > 0;
    if (!$jaExistia) {
        senha_valida($adminSenha);
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO escritorios (cnpj, nome, ativo) VALUES (?, ?, 1)')->execute([$cnpj, $nome]);
        $novo = (int)$pdo->lastInsertId();
        semear_escritorio($pdo, $novo);
        if ($existente === 0) {
            $pdo->prepare('INSERT INTO usuarios (nome, email, senha_hash, ativo) VALUES (?, ?, ?, 1)')->execute([$adminNome, $adminEmail, password_hash($adminSenha, PASSWORD_DEFAULT)]);
            $existente = (int)$pdo->lastInsertId();
        }
        $pdo->prepare('INSERT INTO vinculos (usuario_id, escritorio_id, papel, ativo) VALUES (?, ?, "admin", 1)')->execute([$existente, $novo]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    responder(['ok' => true, 'id' => $novo, 'adminJaExistia' => $jaExistia]);
}
