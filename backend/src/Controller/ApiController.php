<?php
declare(strict_types=1);

namespace App\Controller;

use Cake\Controller\Controller;
use Cake\Http\Response;

abstract class ApiController extends Controller
{
    protected function json(array $dados): Response
    {
        return $this->getResponse()->withType('json')->withStringBody(
            json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }
}
