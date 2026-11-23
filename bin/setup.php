<?php
declare(strict_types=1);

/**
 * Utilitário de linha de comando (só funciona no terminal do servidor).
 *
 *   php bin/setup.php                       primeira instalação: cria o primeiro escritório (com o cadastro-padrão)
 *                                           e o seu usuário, que será o dono da plataforma
 *   php bin/setup.php --novo-admin=CNPJ     cria outro administrador para o escritório deste CNPJ
 *   php bin/setup.php --redefinir=EMAIL     redefine a senha de um usuário (esqueceu a senha)
 *   php bin/setup.php --exemplos=CNPJ       cria clientes FICTÍCIOS no escritório, só para testar (não use em produção)
 *
 * Novos escritórios depois do primeiro são criados pela tela "Escritórios" (só o dono da plataforma vê).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/padrao.php';

function perguntar(string $rotulo, bool $oculto = false, ?string $env = null): string
{
    if ($env !== null && ($v = getenv($env)) !== false && $v !== '') {   // uso automatizado (testes)
        return trim($v);
    }
    fwrite(STDOUT, $rotulo);
    if ($oculto && DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec')) {
        @shell_exec('stty -echo 2>/dev/null');
        $v = fgets(STDIN);
        @shell_exec('stty echo 2>/dev/null');
        fwrite(STDOUT, PHP_EOL);
    } else {
        $v = fgets(STDIN);
    }
    return trim((string)$v);
}

function sair(string $msg, int $cod = 1): void
{
    fwrite($cod ? STDERR : STDOUT, $msg . PHP_EOL);
    exit($cod);
}

function pedir_senha(int $min): string
{
    $s1 = perguntar('Senha (mínimo ' . $min . ' caracteres): ', true, 'SETUP_SENHA');
    if (mb_strlen($s1) < $min) {
        sair('Senha curta demais.');
    }
    if (getenv('SETUP_SENHA') === false) {
        $s2 = perguntar('Repita a senha: ', true);
        if ($s1 !== $s2) {
            sair('As senhas não conferem.');
        }
    }
    return $s1;
}

function pedir_cnpj(string $rotulo, ?string $env = null): string
{
    $c = cnpj_normalizar(perguntar($rotulo, false, $env));
    if (!cnpj_valido($c)) {
        sair('CNPJ inválido. Confira os números e letras.');
    }
    return $c;
}

try {
    $pdo = db();
    $pdo->query('SELECT 1 FROM escritorios LIMIT 1');
} catch (Throwable $e) {
    sair('Não consegui usar o banco. Confira config/config.php e se o schema.sql foi importado. (' . $e->getMessage() . ')');
}

$opcoes = getopt('', ['novo-admin:', 'redefinir:', 'exemplos:']);
$min = max(8, (int)cfg('senha_minima', 8));

function escritorio_por_cnpj(PDO $pdo, string $valor): array
{
    $c = cnpj_normalizar($valor);
    $st = $pdo->prepare('SELECT id, nome, cnpj FROM escritorios WHERE cnpj = ?');
    $st->execute([$c]);
    $e = $st->fetch();
    if (!$e) {
        sair('Não há escritório com o CNPJ ' . cnpj_formatar($c) . '.');
    }
    return $e;
}

if (isset($opcoes['redefinir'])) {
    $email = mb_strtolower(trim((string)$opcoes['redefinir']));
    $st = $pdo->prepare('SELECT id, nome FROM usuarios WHERE email = ?');
    $st->execute([$email]);
    $u = $st->fetch();
    if (!$u) {
        sair('Usuário não encontrado: ' . $email);
    }
    echo 'Redefinindo a senha de ' . $u['nome'] . PHP_EOL;
    $senha = pedir_senha($min);
    $pdo->prepare('UPDATE usuarios SET senha_hash = ?, ativo = 1 WHERE id = ?')->execute([password_hash($senha, PASSWORD_DEFAULT), $u['id']]);
    sair('Senha redefinida.', 0);
}

if (isset($opcoes['exemplos'])) {
    $esc = escritorio_por_cnpj($pdo, (string)$opcoes['exemplos']);
    $st = $pdo->prepare('SELECT COUNT(*) FROM clientes WHERE escritorio_id = ?');
    $st->execute([$esc['id']]);
    if ((int)$st->fetchColumn() > 0) {
        sair('Este escritório já tem clientes; os exemplos só entram em escritório vazio.');
    }
    $cars = [];
    $st = $pdo->prepare('SELECT id, nome FROM caracteristicas WHERE escritorio_id = ?');
    $st->execute([$esc['id']]);
    foreach ($st->fetchAll() as $r) {
        $cars[$r['nome']] = (int)$r['id'];
    }
    $pdo->beginTransaction();
    foreach (exemplos_clientes() as $i => [$nome, $regime, $lista]) {
        $base = '990000' . str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) . '0001';   // CNPJs inventados (base 99...)
        $d1 = cnpj_dv($base);
        $cnpj = $base . $d1 . cnpj_dv($base . $d1);
        $pdo->prepare('INSERT INTO clientes (escritorio_id, nome, cnpj, regime, ativo) VALUES (?, ?, ?, ?, 1)')->execute([$esc['id'], $nome, $cnpj, $regime]);
        $cid = (int)$pdo->lastInsertId();
        foreach ($lista as $nomeCar) {
            if (isset($cars[$nomeCar])) {
                $pdo->prepare('INSERT INTO cliente_caracteristicas (cliente_id, caracteristica_id) VALUES (?, ?)')->execute([$cid, $cars[$nomeCar]]);
            }
        }
    }
    $pdo->commit();
    sair(count(exemplos_clientes()) . ' clientes fictícios criados em "' . $esc['nome'] . '".', 0);
}

if (isset($opcoes['novo-admin'])) {
    $esc = escritorio_por_cnpj($pdo, (string)$opcoes['novo-admin']);
    echo 'Novo administrador para: ' . $esc['nome'] . PHP_EOL;
    $nome = perguntar('Nome: ', false, 'SETUP_NOME');
    $email = mb_strtolower(perguntar('E-mail: ', false, 'SETUP_EMAIL'));
    if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sair('Nome ou e-mail inválido.');
    }
    $st = $pdo->prepare('SELECT id FROM usuarios WHERE email = ?');
    $st->execute([$email]);
    $uid = (int)$st->fetchColumn();
    if ($uid === 0) {
        $senha = pedir_senha($min);
        $pdo->prepare('INSERT INTO usuarios (nome, email, senha_hash, ativo) VALUES (?, ?, ?, 1)')->execute([$nome, $email, password_hash($senha, PASSWORD_DEFAULT)]);
        $uid = (int)$pdo->lastInsertId();
    } else {
        echo 'Este e-mail já existe: a pessoa será apenas vinculada ao escritório (a senha dela continua a mesma).' . PHP_EOL;
    }
    $pdo->prepare('INSERT INTO vinculos (usuario_id, escritorio_id, papel, ativo) VALUES (?, ?, "admin", 1)
                   ON DUPLICATE KEY UPDATE papel = "admin", ativo = 1')->execute([$uid, $esc['id']]);
    sair('Pronto.', 0);
}

// primeira instalação
if ((int)$pdo->query('SELECT COUNT(*) FROM escritorios')->fetchColumn() > 0) {
    sair("A instalação já foi feita. Use --novo-admin=CNPJ, --redefinir=EMAIL ou crie escritórios pela tela \"Escritórios\".");
}
echo 'Primeira instalação: cadastro do seu escritório e do seu usuário.' . PHP_EOL;
$nomeEsc = perguntar('Nome do escritório: ', false, 'SETUP_ESCRITORIO');
if ($nomeEsc === '') {
    sair('Informe o nome do escritório.');
}
$cnpj = pedir_cnpj('CNPJ do escritório (é ele que identifica o escritório no login): ', 'SETUP_CNPJ');
$nome = perguntar('Seu nome: ', false, 'SETUP_NOME');
$email = mb_strtolower(perguntar('Seu e-mail (será o seu login): ', false, 'SETUP_EMAIL'));
if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    sair('Nome ou e-mail inválido.');
}
$senha = pedir_senha($min);

$pdo->beginTransaction();
$pdo->prepare('INSERT INTO escritorios (cnpj, nome, ativo) VALUES (?, ?, 1)')->execute([$cnpj, $nomeEsc]);
$eid = (int)$pdo->lastInsertId();
semear_escritorio($pdo, $eid);
$pdo->prepare('INSERT INTO usuarios (nome, email, senha_hash, ativo, dono_plataforma) VALUES (?, ?, ?, 1, 1)')->execute([$nome, $email, password_hash($senha, PASSWORD_DEFAULT)]);
$uid = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO vinculos (usuario_id, escritorio_id, papel, ativo) VALUES (?, ?, "admin", 1)')->execute([$uid, $eid]);
$pdo->commit();
sair('Pronto. Escritório "' . $nomeEsc . '" criado com os departamentos, características e obrigações-padrão. Entre com o CNPJ ' . cnpj_formatar($cnpj) . ', seu e-mail e a senha.', 0);
