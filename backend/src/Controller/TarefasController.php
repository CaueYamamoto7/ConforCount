<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\TarefasService;
use Cake\Http\Response;

final class TarefasController extends ApiController
{
    public function tarefaSalvar(): Response
    {
        return $this->json((new TarefasService($this->getRequest()))->tarefaSalvar());
    }

    public function historico(): Response
    {
        return $this->json((new TarefasService($this->getRequest()))->historico());
    }
}
