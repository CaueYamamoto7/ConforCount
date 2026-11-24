<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\ModelosService;
use Cake\Http\Response;

final class ModelosController extends ApiController
{
    public function modeloSalvar(): Response
    {
        return $this->json((new ModelosService($this->getRequest()))->modeloSalvar());
    }

    public function modeloExcluir(): Response
    {
        return $this->json((new ModelosService($this->getRequest()))->modeloExcluir());
    }

    public function deptoSalvar(): Response
    {
        return $this->json((new ModelosService($this->getRequest()))->deptoSalvar());
    }

    public function deptoExcluir(): Response
    {
        return $this->json((new ModelosService($this->getRequest()))->deptoExcluir());
    }

    public function caractSalvar(): Response
    {
        return $this->json((new ModelosService($this->getRequest()))->caractSalvar());
    }

    public function caractExcluir(): Response
    {
        return $this->json((new ModelosService($this->getRequest()))->caractExcluir());
    }

    public function feriadoSalvar(): Response
    {
        return $this->json((new ModelosService($this->getRequest()))->feriadoSalvar());
    }

    public function feriadoExcluir(): Response
    {
        return $this->json((new ModelosService($this->getRequest()))->feriadoExcluir());
    }
}
