<?php

use Src\Kernel\Middlewares\AuthHybridMiddleware;
use Src\Kernel\Middlewares\AdminOnlyMiddleware;
use Src\Kernel\Http\Response\Response;
use Src\Modules\Email\Controllers\EmailApiController;

/** @var \Src\Kernel\Contracts\RouterInterface $router */

$protected = [AuthHybridMiddleware::class, AdminOnlyMiddleware::class];

// Health check
$router->post('/email/ping', function () {
    return Response::json(['status' => 'ok', 'module' => 'email']);
});

// Envio de e-mail personalizado
$router->post('/api/email/custom', [EmailApiController::class, 'custom'], $protected);

// Histórico
$router->get('/api/email/history',              [EmailApiController::class, 'listarHistorico'],  $protected);
$router->get('/api/email/history/{id}',         [EmailApiController::class, 'detalheHistorico'], $protected);
$router->delete('/api/email/history/{id}',      [EmailApiController::class, 'deletarHistorico'], $protected);
$router->post('/api/email/history/{id}/resend', [EmailApiController::class, 'reenviar'],         $protected);
