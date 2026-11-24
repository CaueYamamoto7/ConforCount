<?php
declare(strict_types=1);

use Cake\Routing\RouteBuilder;

return static function (RouteBuilder $routes): void {
    $routes->scope('/api', static function (RouteBuilder $api): void {
        $api->get('/auth/session', ['controller' => 'Auth', 'action' => 'me']);
        $api->post('/auth/login', ['controller' => 'Auth', 'action' => 'login']);
        $api->post('/auth/logout', ['controller' => 'Auth', 'action' => 'logout']);
        $api->post('/auth/password', ['controller' => 'Auth', 'action' => 'senha']);
        $api->post('/auth/escritorio', ['controller' => 'Auth', 'action' => 'trocar']);
        $api->get('/painel', ['controller' => 'Painel', 'action' => 'dados']);
        $api->post('/tarefas', ['controller' => 'Tarefas', 'action' => 'tarefaSalvar']);
        $api->get('/tarefas/historico', ['controller' => 'Tarefas', 'action' => 'historico']);
        $api->post('/clientes', ['controller' => 'Clientes', 'action' => 'clienteSalvar']);
        $api->post('/clientes/excluir', ['controller' => 'Clientes', 'action' => 'clienteExcluir']);
        $api->post('/modelos', ['controller' => 'Modelos', 'action' => 'modeloSalvar']);
        $api->post('/modelos/excluir', ['controller' => 'Modelos', 'action' => 'modeloExcluir']);
        $api->post('/departamentos', ['controller' => 'Modelos', 'action' => 'deptoSalvar']);
        $api->post('/departamentos/excluir', ['controller' => 'Modelos', 'action' => 'deptoExcluir']);
        $api->post('/caracteristicas', ['controller' => 'Modelos', 'action' => 'caractSalvar']);
        $api->post('/caracteristicas/excluir', ['controller' => 'Modelos', 'action' => 'caractExcluir']);
        $api->post('/feriados', ['controller' => 'Modelos', 'action' => 'feriadoSalvar']);
        $api->post('/feriados/excluir', ['controller' => 'Modelos', 'action' => 'feriadoExcluir']);
        $api->post('/equipe', ['controller' => 'Equipe', 'action' => 'usuarioSalvar']);
        $api->post('/equipe/excluir', ['controller' => 'Equipe', 'action' => 'usuarioExcluir']);
        $api->get('/escritorios', ['controller' => 'Escritorios', 'action' => 'plataformaLista']);
        $api->post('/escritorios', ['controller' => 'Escritorios', 'action' => 'plataformaSalvar']);
    });
};
