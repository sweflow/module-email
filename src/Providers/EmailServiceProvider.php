<?php
namespace SweflowModules\Email\Providers;

use Src\Kernel\Contracts\ContainerInterface;
use Src\Kernel\Contracts\ModuleProviderInterface;
use Src\Kernel\Contracts\RouterInterface;
use Src\Kernel\Contracts\EmailSenderInterface;
use SweflowModules\Email\Services\EmailService;

class EmailServiceProvider implements ModuleProviderInterface
{
    private string $name = 'Email';

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

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
            'name'        => $this->name,
            'description' => 'Módulo de envio e histórico de e-mails via SMTP.',
            'version'     => '1.0.0',
            'routes' => [
                ['method' => 'POST',   'uri' => '/api/email/custom',                    'tipo' => 'privada', 'protected' => true],
                ['method' => 'GET',    'uri' => '/api/email/history',                   'tipo' => 'privada', 'protected' => true],
                ['method' => 'GET',    'uri' => '/api/email/history/{id}',              'tipo' => 'privada', 'protected' => true],
                ['method' => 'DELETE', 'uri' => '/api/email/history/{id}',              'tipo' => 'privada', 'protected' => true],
                ['method' => 'POST',   'uri' => '/api/email/history/{id}/resend',       'tipo' => 'privada', 'protected' => true],
                ['method' => 'POST',   'uri' => '/email/ping',                          'tipo' => 'pública', 'protected' => false],
            ],
        ];
    }

    public function onInstall(): void {}
    public function onEnable(): void {}
    public function onDisable(): void {}
    public function onUninstall(): void {}
}
