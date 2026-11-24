<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\EscritoriosService;
use Cake\Http\Response;

final class EscritoriosController extends ApiController
{
    public function plataformaLista(): Response
    {
        return $this->json((new EscritoriosService($this->getRequest()))->plataformaLista());
    }

    public function plataformaSalvar(): Response
    {
        return $this->json((new EscritoriosService($this->getRequest()))->plataformaSalvar());
    }
}
