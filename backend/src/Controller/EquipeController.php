<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\EquipeService;
use Cake\Http\Response;

final class EquipeController extends ApiController
{
    public function usuarioSalvar(): Response
    {
        return $this->json((new EquipeService($this->getRequest()))->usuarioSalvar());
    }

    public function usuarioExcluir(): Response
    {
        return $this->json((new EquipeService($this->getRequest()))->usuarioExcluir());
    }
}
