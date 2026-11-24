<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\ClientesService;
use Cake\Http\Response;

final class ClientesController extends ApiController
{
    public function clienteSalvar(): Response
    {
        return $this->json((new ClientesService($this->getRequest()))->clienteSalvar());
    }

    public function clienteExcluir(): Response
    {
        return $this->json((new ClientesService($this->getRequest()))->clienteExcluir());
    }
}
