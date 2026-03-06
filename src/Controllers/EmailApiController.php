<?php
namespace SweflowModules\Email\Controllers;

use Src\Kernel\Contracts\EmailSenderInterface;
use Src\Kernel\Http\Response\Response;
use SweflowModules\Email\Services\EmailService;

class EmailApiController
{
    private EmailSenderInterface $sender;

    public function __construct()
    {
        // Instancia diretamente para evitar problemas de binding se o provider não carregar
        $this->sender = new EmailService();
    }

    public function custom($request): Response
    {
        $body = $request->body ?? [];
        $recipients = $body['recipients'] ?? [];
        $subject = $body['subject'] ?? '';
        $html = $body['html'] ?? '';
        if (!$recipients || !$subject || !$html) {
            return Response::json(['status' => 'error', 'message' => 'Campos obrigatórios: recipients, subject, html'], 400);
        }
        $logoUrl = $body['logo_url'] ?? $_ENV['APP_LOGO_URL'] ?? null;
        
        try {
            $this->sender->sendCustom($recipients, $subject, $html, $logoUrl);
            return Response::json(['status' => 'success']);
        } catch (\Throwable $e) {
            return Response::json(['status' => 'error', 'message' => 'Falha ao enviar e-mail'], 500);
        }
    }
}
