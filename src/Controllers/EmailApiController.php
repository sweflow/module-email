<?php
namespace SweflowModules\Email\Controllers;

use Src\Kernel\Contracts\EmailSenderInterface;
use Src\Kernel\Http\Response\Response;

class EmailApiController
{
    public function __construct(private EmailSenderInterface $sender) {}

    public function custom($request): Response
    {
        $body = $request->body ?? [];
        $recipients = $body['recipients'] ?? [];
        $subject = $body['subject'] ?? '';
        $html = $body['html'] ?? '';
        if (!$recipients || !$subject || !$html) {
            return Response::json(['status' => 'error', 'message' => 'Campos obrigatórios: recipients, subject, html'], 400);
        }
        try {
            $this->sender->sendCustom($recipients, $subject, $html, $_ENV['APP_LOGO_URL'] ?? null);
            return Response::json(['status' => 'success']);
        } catch (\Throwable $e) {
            return Response::json(['status' => 'error', 'message' => 'Falha ao enviar e-mail'], 500);
        }
    }
}
