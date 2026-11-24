<?php
declare(strict_types=1);

namespace App;

use App\Middleware\ApiMiddleware;
use Cake\Http\BaseApplication;
use Cake\Http\MiddlewareQueue;
use Cake\Routing\Middleware\RoutingMiddleware;

final class Application extends BaseApplication
{
    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        return $middlewareQueue
            ->add(new ApiMiddleware())
            ->add(new RoutingMiddleware($this));
    }
}
