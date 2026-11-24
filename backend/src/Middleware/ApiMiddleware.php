<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Exception\HttpErro;
use App\Service\Operacao;
use Cake\Core\Configure;
use Cake\Http\Exception\HttpException;
use Cake\Http\Response;
use PDOException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

final class ApiMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $this->validarHost($request);
            $caminho = $request->getUri()->getPath();
            if ($request->getMethod() === 'POST' && $caminho !== '/api/auth/login') {
                (new Operacao($request))->exigirCsrf();
            }
            $response = $handler->handle($request);
        } catch (HttpErro $erro) {
            $response = $this->erro($erro->getCode() ?: 400, $erro->getMessage());
        } catch (HttpException $erro) {
            $response = $this->erro($erro->getCode(), 'Rota ou requisição inválida.');
        } catch (Throwable $erro) {
            if ($erro instanceof PDOException && str_starts_with((string)$erro->getCode(), '23')) {
                $response = $this->erro(409, 'Não foi possível: o registro já existe ou está em uso.');
            } else {
                error_log('[conforcont] ' . $erro);
                $response = $this->erro(500, 'Erro interno. Tente de novo em instantes.');
            }
        }

        try {
            $request->getSession()->close();
        } catch (Throwable $erro) {
            error_log('[conforcont] ' . $erro);
            $response = $this->erro(500, 'Não foi possível gravar a sessão.');
        }

        return $response->withHeader('Cache-Control', 'no-store')->withHeader('X-Content-Type-Options', 'nosniff');
    }

    private function validarHost(ServerRequestInterface $request): void
    {
        if (getenv('APP_ENV') !== 'production') {
            return;
        }
        $hostPrincipal = parse_url((string)Configure::read('App.fullBaseUrl'), PHP_URL_HOST);
        $hosts = array_filter([$hostPrincipal, getenv('VERCEL_URL'), getenv('VERCEL_BRANCH_URL')]);
        if (!in_array(strtolower($request->getUri()->getHost()), array_map('strtolower', $hosts), true)) {
            throw new HttpErro('Endereço de acesso inválido.', 400);
        }
    }

    private function erro(int $status, string $mensagem): Response
    {
        return (new Response())->withStatus($status)->withType('json')
            ->withStringBody(json_encode(['erro' => $mensagem], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
