<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\PainelService;
use Cake\Http\Response;

final class PainelController extends ApiController
{
    public function dados(): Response
    {
        return $this->json((new PainelService($this->getRequest()))->dados());
    }
}
