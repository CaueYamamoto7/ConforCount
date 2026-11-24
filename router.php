<?php
declare(strict_types=1);

$caminho = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_string($caminho) && str_starts_with($caminho, '/api/')) {
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['PHP_SELF'] = '/index.php';
    require __DIR__ . '/backend/webroot/index.php';
    return;
}

$paginas = ['login', 'obrigacoes', 'clientes', 'equipe', 'modelos', 'escritorios', 'conta'];
$nome = is_string($caminho) ? substr($caminho, 1) : '';
if ($nome === 'index.html' || (str_ends_with($nome, '.html') && in_array(substr($nome, 0, -5), $paginas, true))) {
    $destino = $nome === 'index.html' ? '/' : '/' . substr($nome, 0, -5);
    $consulta = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: ' . $destino . ($consulta === '' ? '' : '?' . $consulta), true, 308);
    return;
}
if ($caminho === '/') {
    $arquivo = __DIR__ . '/index.html';
} elseif (in_array($nome, $paginas, true)) {
    $arquivo = __DIR__ . '/' . $nome . '.html';
} elseif (preg_match('#^/assets/(?:[a-z0-9_-]+/)*[a-z0-9_-]+\.(?:js|css)$#D', (string)$caminho)) {
    $arquivo = __DIR__ . $caminho;
}

if (!isset($arquivo) || !is_file($arquivo)) {
    http_response_code(404);
    return;
}

$tipo = match (pathinfo($arquivo, PATHINFO_EXTENSION)) {
    'css' => 'text/css',
    'js' => 'text/javascript',
    default => 'text/html',
};
header('Content-Type: ' . $tipo . '; charset=utf-8');
readfile($arquivo);
