<?php
declare(strict_types=1);

/**
 * Inicialização: configuração, banco de dados, sessão e funções de apoio.
 * Este arquivo fica fora da pasta pública (public/), por isso não pode ser aberto pelo navegador.
 */

define('BASE_PATH', dirname(__DIR__));

class HttpErro extends Exception
{
    public int $codigo;
    public function __construct(string $mensagem, int $codigo = 400)
    {
        parent::__construct($mensagem);
        $this->codigo = $codigo;
    }
}

function falha_config(string $msg): void
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $msg . PHP_EOL);
        exit(1);
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['erro' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

$arquivoConfig = BASE_PATH . '/config/config.php';
if (!is_file($arquivoConfig)) {
    falha_config('Configuração ausente: copie config/config.sample.php para config/config.php e preencha.');
}
$CONFIG = require $arquivoConfig;
date_default_timezone_set($CONFIG['timezone'] ?? 'America/Sao_Paulo');

function cfg(string $chave, $padrao = null)
{
    global $CONFIG;
    return $CONFIG[$chave] ?? $padrao;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $c = cfg('db');
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], (int)($c['port'] ?? 3306), $c['name']);
    $pdo = new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function agora(): string
{
    return date('Y-m-d H:i:s');
}

function ip_cliente(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

/* ---------- sessão ---------- */

function iniciar_sessao(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || cfg('cookie_seguro', false);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('obrig_sess');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (bool)$https,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
    $limite = max(5, (int)cfg('sessao_minutos', 480)) * 60;
    if (isset($_SESSION['ultimo']) && time() - (int)$_SESSION['ultimo'] > $limite) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['ultimo'] = time();
}

/**
 * Usuário logado NO ESCRITÓRIO da sessão (lido do banco a cada requisição, para respeitar bloqueios e mudanças de papel).
 * Só é válido se usuário, vínculo e escritório estiverem ativos.
 */
function usuario_atual(): ?array
{
    iniciar_sessao();
    $uid = (int)($_SESSION['uid'] ?? 0);
    $eid = (int)($_SESSION['eid'] ?? 0);
    if ($uid <= 0 || $eid <= 0) {
        return null;
    }
    $st = db()->prepare(
        'SELECT u.id, u.nome, u.email, u.dono_plataforma, v.papel, e.id AS escritorio_id, e.nome AS escritorio_nome, e.cnpj AS escritorio_cnpj
           FROM usuarios u
           JOIN vinculos v ON v.usuario_id = u.id AND v.escritorio_id = ? AND v.ativo = 1
           JOIN escritorios e ON e.id = v.escritorio_id AND e.ativo = 1
          WHERE u.id = ? AND u.ativo = 1'
    );
    $st->execute([$eid, $uid]);
    $u = $st->fetch();
    if (!$u) {
        $_SESSION = [];
        return null;
    }
    $u['id'] = (int)$u['id'];
    $u['escritorio_id'] = (int)$u['escritorio_id'];
    $u['dono_plataforma'] = (int)$u['dono_plataforma'];
    return $u;
}

function exigir_login(): array
{
    $u = usuario_atual();
    if (!$u) {
        throw new HttpErro('Sessão expirada. Entre novamente.', 401);
    }
    return $u;
}

/** @param string[] $papeis */
function exigir_papel(array $u, array $papeis): void
{
    if (!in_array($u['papel'], $papeis, true)) {
        throw new HttpErro('Você não tem permissão para isso.', 403);
    }
}

function exigir_dono(array $u): void
{
    if (empty($u['dono_plataforma'])) {
        throw new HttpErro('Você não tem permissão para isso.', 403);
    }
}

/** Escritórios (ativos) em que o usuário tem vínculo ativo. */
function escritorios_do_usuario(int $uid): array
{
    $st = db()->prepare(
        'SELECT e.id, e.nome, e.cnpj, v.papel
           FROM vinculos v JOIN escritorios e ON e.id = v.escritorio_id
          WHERE v.usuario_id = ? AND v.ativo = 1 AND e.ativo = 1 ORDER BY e.nome'
    );
    $st->execute([$uid]);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'nome' => $r['nome'], 'cnpj' => $r['cnpj'], 'papel' => $r['papel']], $st->fetchAll());
}

/* ---------- CNPJ (numérico e alfanumérico — IN RFB 2.229/2024) ---------- */

/** Remove pontuação e põe em maiúsculas. Não valida. */
function cnpj_normalizar(string $v): string
{
    return strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $v) ?? '');
}

/** Dígito verificador (módulo 11): valor de cada caractere = código ASCII − 48. */
function cnpj_dv(string $base): int
{
    $n = strlen($base);
    $pesos = $n === 12 ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2] : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
    $soma = 0;
    for ($i = 0; $i < $n; $i++) {
        $soma += (ord($base[$i]) - 48) * $pesos[$i];
    }
    $r = $soma % 11;
    return $r < 2 ? 0 : 11 - $r;
}

/** Aceita CNPJ numérico antigo e alfanumérico novo (12 posições alfanuméricas + 2 dígitos). */
function cnpj_valido(string $v): bool
{
    $c = cnpj_normalizar($v);
    if (!preg_match('/^[0-9A-Z]{12}[0-9]{2}$/', $c)) {
        return false;
    }
    if (preg_match('/^(.)\1{13}$/', $c)) {
        return false;
    }
    $d1 = cnpj_dv(substr($c, 0, 12));
    $d2 = cnpj_dv(substr($c, 0, 12) . $d1);
    return $c[12] === (string)$d1 && $c[13] === (string)$d2;
}

function cnpj_formatar(string $c): string
{
    $c = cnpj_normalizar($c);
    if (strlen($c) !== 14) {
        return $c;
    }
    return substr($c, 0, 2) . '.' . substr($c, 2, 3) . '.' . substr($c, 5, 3) . '/' . substr($c, 8, 4) . '-' . substr($c, 12, 2);
}

function exigir_csrf(): void
{
    iniciar_sessao();
    $enviado = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $esperado = (string)($_SESSION['csrf'] ?? '');
    if ($esperado === '' || !hash_equals($esperado, $enviado)) {
        throw new HttpErro('Sessão inválida. Recarregue a página.', 403);
    }
}

/* ---------- entrada e saída ---------- */

function corpo(): array
{
    $bruto = file_get_contents('php://input');
    if ($bruto === false || $bruto === '') {
        return [];
    }
    if (strlen($bruto) > 100000) {
        throw new HttpErro('Requisição grande demais.', 413);
    }
    $j = json_decode($bruto, true);
    if (!is_array($j)) {
        throw new HttpErro('Dados inválidos.', 400);
    }
    return $j;
}

function responder($dados, int $codigo = 200): void
{
    http_response_code($codigo);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function texto(array $b, string $campo, int $max, bool $obrigatorio = false): string
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

function booleano(array $b, string $campo): int
{
    return !empty($b[$campo]) ? 1 : 0;
}

function mes_valido(string $mes): string
{
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) {
        throw new HttpErro('Mês inválido.', 422);
    }
    return $mes;
}

function senha_valida(string $senha): void
{
    $min = max(8, (int)cfg('senha_minima', 8));
    if (mb_strlen($senha) < $min) {
        throw new HttpErro("A senha precisa ter pelo menos $min caracteres.", 422);
    }
    if (mb_strlen($senha) > 200) {
        throw new HttpErro('Senha longa demais.', 422);
    }
}
