<?php

namespace SweflowModules\Email\Controllers;

use PDO;
use Src\Kernel\Contracts\EmailSenderInterface;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;
use Src\Kernel\Support\EmailHistory;
use SweflowModules\Email\Services\EmailService;

class EmailApiController
{
    private EmailSenderInterface $sender;
    private ?EmailHistory $history = null;

    public function __construct(?EmailSenderInterface $sender = null, ?PDO $pdo = null)
    {
        $this->sender = $sender ?? new EmailService();
        try {
            $this->history = new EmailHistory($pdo);
        } catch (\Throwable $e) {
            error_log('[EmailModule] EmailHistory init failed: ' . $e->getMessage());
        }
    }

    // ── Envio ─────────────────────────────────────────────────────────────

    public function custom(Request $request): Response
    {
        $body       = $request->body ?? [];
        $recipients = $body['recipients'] ?? [];
        $subject    = trim((string) ($body['subject'] ?? ''));
        $html       = trim((string) ($body['html'] ?? ''));
        $logoUrl    = trim((string) ($body['logo_url'] ?? '')) ?: null;

        if (empty($recipients) || $subject === '' || $html === '') {
            return Response::json(['error' => 'Campos obrigatórios: recipients, subject, html.'], 422);
        }

        $recipients = $this->normalizeRecipients($recipients);

        $configError = $this->validateSmtpConfig();
        if ($configError) {
            $saved = $this->saveHistory($subject, $recipients, $html, $logoUrl, 'falhou', $configError);
            return Response::json(['error' => $configError, 'module_disabled' => true, 'id' => $saved['id'] ?? null], 503);
        }

        try {
            $this->sender->sendCustom($recipients, $subject, $html, $logoUrl);
            $saved = $this->saveHistory($subject, $recipients, $html, $logoUrl, 'enviado', null);
            return Response::json(['message' => 'E-mail enviado com sucesso.', 'id' => $saved['id'] ?? null]);
        } catch (\Throwable $e) {
            $saved = $this->saveHistory($subject, $recipients, $html, $logoUrl, 'falhou', $e->getMessage());
            $code  = $this->classifySmtpError($e) ? 503 : 500;
            return Response::json(['error' => $e->getMessage(), 'id' => $saved['id'] ?? null], $code);
        }
    }

    // ── Histórico ─────────────────────────────────────────────────────────

    public function listarHistorico(Request $request): Response
    {
        if (!$this->history) {
            return Response::json(['items' => [], 'warning' => 'Histórico indisponível.']);
        }
        $q     = trim((string) ($request->query['q'] ?? ''));
        $items = $this->history->all($q);
        return Response::json(['items' => $items]);
    }

    public function detalheHistorico(Request $request, string $id): Response
    {
        if (!$this->history) {
            return Response::json(['error' => 'Histórico indisponível.'], 503);
        }
        $entry = $this->history->find($id);
        if (!$entry) {
            return Response::json(['error' => 'Registro não encontrado.'], 404);
        }
        return Response::json($entry);
    }

    public function deletarHistorico(Request $request, string $id): Response
    {
        if (!$this->history) {
            return Response::json(['error' => 'Histórico indisponível.'], 503);
        }
        if (!$this->history->delete($id)) {
            return Response::json(['error' => 'Registro não encontrado.'], 404);
        }
        return Response::json(['message' => 'Registro excluído.']);
    }

    public function reenviar(Request $request, string $id): Response
    {
        if (!$this->history) {
            return Response::json(['error' => 'Histórico indisponível.'], 503);
        }
        $entry = $this->history->find($id);
        if (!$entry) {
            return Response::json(['error' => 'Registro não encontrado.'], 404);
        }

        $configError = $this->validateSmtpConfig();
        if ($configError) {
            $saved = $this->saveHistory($entry['subject'], $entry['recipients'], $entry['html'], $entry['logo_url'] ?? null, 'falhou', $configError, $id);
            return Response::json(['error' => $configError, 'id' => $saved['id'] ?? null], 503);
        }

        try {
            $this->sender->sendCustom($entry['recipients'], $entry['subject'], $entry['html'], $entry['logo_url'] ?? null);
            $this->saveHistory($entry['subject'], $entry['recipients'], $entry['html'], $entry['logo_url'] ?? null, 'enviado', null, $id);
            return Response::json(['message' => 'E-mail reenviado com sucesso.']);
        } catch (\Throwable $e) {
            $this->saveHistory($entry['subject'], $entry['recipients'], $entry['html'], $entry['logo_url'] ?? null, 'falhou', $e->getMessage(), $id);
            $code = $this->classifySmtpError($e) ? 503 : 500;
            return Response::json(['error' => $e->getMessage()], $code);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function normalizeRecipients(mixed $recipients): array
    {
        if (is_string($recipients)) {
            $list = [];
            foreach (preg_split('/[;,\n]+/', $recipients) ?: [] as $email) {
                $email = trim($email);
                if ($email !== '') {
                    $list[] = ['email' => $email, 'name' => $email];
                }
            }
            return $list;
        }

        if (!is_array($recipients)) {
            return [];
        }

        $list = [];
        foreach ($recipients as $rec) {
            if (is_string($rec)) {
                $email = trim($rec);
                if ($email !== '') {
                    $list[] = ['email' => $email, 'name' => $email];
                }
            } elseif (is_array($rec)) {
                $email = trim((string) ($rec['email'] ?? ($rec['to'] ?? '')));
                if ($email !== '') {
                    $list[] = ['email' => $email, 'name' => trim((string) ($rec['name'] ?? $email))];
                }
            }
        }
        return $list;
    }

    private function saveHistory(
        string $subject,
        mixed $recipients,
        string $html,
        ?string $logoUrl,
        string $status,
        ?string $error,
        ?string $resentFrom = null
    ): array {
        if (!is_array($recipients)) {
            $recipients = [];
        }

        $entry = [
            'subject'    => $subject,
            'recipients' => $recipients,
            'html'       => $html,
            'logo_url'   => $logoUrl,
            'status'     => $status,
            'error'      => $error,
        ];

        if ($resentFrom !== null) {
            $entry['resent_from'] = $resentFrom;
        }

        if (!$this->history) {
            error_log('[EmailModule] History not available.');
            return $entry;
        }

        try {
            return $this->history->save($entry);
        } catch (\Throwable $e) {
            error_log('[EmailModule] History save failed: ' . $e->getMessage());
            return $entry;
        }
    }

    private function validateSmtpConfig(): ?string
    {
        $host = trim((string) ($_ENV['MAILER_HOST'] ?? $_ENV['EMAIL_HOST'] ?? ''));
        $user = trim((string) ($_ENV['MAILER_USERNAME'] ?? $_ENV['EMAIL_USERNAME'] ?? ''));
        if ($host === '') return 'SMTP não configurado: MAILER_HOST está vazio no .env.';
        if ($user === '') return 'SMTP não configurado: MAILER_USERNAME está vazio no .env.';
        return null;
    }

    private function classifySmtpError(\Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());
        return str_contains($msg, 'authenticate')
            || str_contains($msg, 'connection')
            || str_contains($msg, 'connect')
            || str_contains($msg, 'timeout')
            || str_contains($msg, 'smtp');
    }
}
