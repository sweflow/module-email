<?php
use Src\Kernel\Middlewares\AuthHybridMiddleware;
use Src\Kernel\Middlewares\AdminOnlyMiddleware;
use Src\Kernel\Http\Response\Response;
use SweflowModules\Email\Controllers\EmailApiController;

$protected = [AuthHybridMiddleware::class, AdminOnlyMiddleware::class];

/** @var \Src\Kernel\Contracts\RouterInterface $router */

$router->post('/email/ping', function () {
    return Response::json(['status' => 'ok', 'module' => 'email']);
});

$router->post('/api/email/custom', [EmailApiController::class, 'custom'], $protected);
