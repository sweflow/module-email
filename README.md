# Sweflow Module: Email

[![Latest Stable Version](https://img.shields.io/packagist/v/sweflow/module-email.svg)](https://packagist.org/packages/sweflow/module-email)
[![Total Downloads](https://img.shields.io/packagist/dt/sweflow/module-email.svg)](https://packagist.org/packages/sweflow/module-email)
[![License](https://img.shields.io/packagist/l/sweflow/module-email.svg)](https://packagist.org/packages/sweflow/module-email)

Este plugin fornece funcionalidades de envio de e-mail para a API Modular Sweflow. Ele utiliza o PHPMailer para garantir alta compatibilidade com diversos provedores SMTP.

## Capability

Este módulo implementa a capability **`email-sender`**.
Ao instalar este plugin, o sistema Sweflow automaticamente o registrará como o provedor padrão para envio de e-mails, a menos que outro plugin com maior prioridade seja configurado.

## Requisitos

- PHP >= 8.1
- Sweflow Core >= 1.0

## Instalação

Instale via Composer:

```bash
composer require sweflow/module-email
```

Após a instalação, execute o comando de instalação do plugin para registrar as configurações e migrações necessárias:

```bash
php sweflow plugin:install email
```

## Configuração

Adicione as seguintes variáveis ao seu arquivo `.env` na raiz do projeto Sweflow:

```env
# Configurações de SMTP
EMAIL_HOST=smtp.mailtrap.io
EMAIL_PORT=2525
EMAIL_USERNAME=seu_usuario
EMAIL_PASSWORD=sua_senha
EMAIL_ENCRYPTION=tls # ou ssl

# Remetente Padrão
EMAIL_FROM=noreply@seudominio.com
EMAIL_FROM_NAME="Sweflow System"

# Debugging (opcional)
EMAIL_DEBUG=false
```

## Uso

O plugin registra o serviço `EmailSenderInterface` no container de dependência. Você pode injetá-lo em qualquer controller ou serviço do seu sistema.

### Exemplo de Injeção de Dependência

```php
use Src\Kernel\Contracts\EmailSenderInterface;

class AuthController
{
    public function __construct(
        private EmailSenderInterface $emailService
    ) {}

    public function register(Request $request)
    {
        // Lógica de registro...

        // Enviar e-mail de boas-vindas
        $this->emailService->send(
            to: $user->email,
            subject: 'Bem-vindo ao Sweflow!',
            body: '<h1>Obrigado por se registrar.</h1>'
        );
    }
}
```

### Uso via Helper (se disponível)

Se o seu sistema tiver helpers configurados para capabilities:

```php
app('email-sender')->send($to, $subject, $body);
```

## Estrutura do Banco de Dados

Este plugin cria a tabela `emails` para log de envios (opcional, dependendo da configuração). As migrações estão localizadas em `src/Database/Migrations`.

Para rodar as migrações manualmente:

```bash
php sweflow plugin:migrate email
```

## Contribuição

1. Faça um Fork do projeto
2. Crie sua Feature Branch (`git checkout -b feature/AmazingFeature`)
3. Commit suas mudanças (`git commit -m 'Add some AmazingFeature'`)
4. Push para a Branch (`git push origin feature/AmazingFeature`)
5. Abra um Pull Request

## Licença

Distribuído sob a licença MIT. Veja `LICENSE` para mais informações.
