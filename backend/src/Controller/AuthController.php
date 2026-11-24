<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\AuthService;
use Cake\Http\Response;

final class AuthController extends ApiController
{
    public function me(): Response
    {
        return $this->json((new AuthService($this->getRequest()))->me());
    }

    public function login(): Response
    {
        return $this->json((new AuthService($this->getRequest()))->login());
    }

    public function logout(): Response
    {
        return $this->json((new AuthService($this->getRequest()))->logout());
    }

    public function senha(): Response
    {
        return $this->json((new AuthService($this->getRequest()))->senha());
    }

    public function trocar(): Response
    {
        return $this->json((new AuthService($this->getRequest()))->trocar());
    }
}
