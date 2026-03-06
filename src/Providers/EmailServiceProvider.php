<?php
namespace SweflowModules\Email;

use Src\Kernel\Contracts\ContainerInterface;
use Src\Kernel\Contracts\ModuleProviderInterface;
use Src\Kernel\Contracts\RouterInterface;
use Src\Kernel\Contracts\EmailSenderInterface;
use SweflowModules\Email\Services\EmailService;

class EmailServiceProvider implements ModuleProviderInterface
{
    public function registerRoutes(RouterInterface $router): void
    {
        $file = __DIR__ . '/../Routes/routes.php';
        if (is_file($file)) {
            $r = $router;
            unset($router);
            $router = $r;
            require $file;
        }
    }

    public function boot(ContainerInterface $container): void
    {
        $container->bind(EmailSenderInterface::class, EmailService::class, true);
    }

    public function describe(): array
    {
        return [
            'routes' => [
                ['method' => 'POST', 'uri' => '/api/email/custom', 'tipo' => 'privada', 'protected' => true],
                ['method' => 'POST', 'uri' => '/email/ping', 'tipo' => 'pública', 'protected' => false],
            ],
        ];
    }

    public function onInstall(): void {}
    public function onEnable(): void {}
    public function onDisable(): void {}
    public function onUninstall(): void {}
}
