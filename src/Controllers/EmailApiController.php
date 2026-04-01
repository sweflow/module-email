<?php

namespace SweflowModules\Email\Controllers;

use Src\Kernel\Contracts\EmailSenderInterface;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;
use Src\Kernel\Support\EmailHistory;
use SweflowModules\Email\Services\EmailService;

class EmailApiController
{
    private EmailSenderInterface $sender;
    private ?EmailHistory $history = null;

    public function __construct(?EmailSenderInterface $sender = null)
    {
        $this->sender = $sender ?? new EmailService();
        try {
            $this->history = new EmailHistory($this->storageDir());
        } catch (\Throwable) {
            // History unavailable — continue without it
        }
    }

    // ── Envio ─────────────────────────────────────────────────────────────

    public function custom(Request $request): Response
    {
        if (!$this->moduleEnabled()) {
            $msg = 'Módulo de e-mail não está instalado ou está desabilitado.';
            $this->saveHistory([
                'subject'    => $request->body['subject'] ?? '(sem assunto)',
                'recipients' => $request->body['recipients'] ?? [],
                'html'       => $request->body['html'] ?? '',
                'logo_url'   => null,
                'status'     => 'falhou',
                'error'      => $msg,
            ]);
            return Response::json(['error' => $msg, 'module_disabled' => true], 503);
        }

        $body       = $request->body ?? [];
        $recipients = $body['recipients'] ?? [];
        $subject    = trim((string) ($body['subject'] ?? ''));
        $html       = trim((string) ($body['html'] ?? ''));
        $logoUrl    = trim((string) ($body['logo_url'] ?? '')) ?: null;

        if (empty($recipients) || $subject === '' || $html === '') {
            return Response::json(['error' => 'Campos obrigatórios: recipients, subject, html.'], 422);
        }

        // Validate SMTP config before attempting send
        $configError = $this->validateSmtpConfig();
        if ($configError) {
            $saved = $this->saveHistory([
                'subject'    => $subject,
                'recipients' => $recipients,
                'html'       => $html,
                'logo_url'   => $logoUrl,
                'status'     => 'falhou',
                'error'      => $configError,
            ]);
            return Response::json(['error' => $configError, 'id' => $saved['id'] ?? null], 503);
        }

        $entry = [
            'subject'    => $subject,
            'recipients' => $recipients,
            'html'       => $html,
            'logo_url'   => $logoUrl,
            'status'     => 'enviado',
            'error'      => null,
        ];

        try {
            $this->sender->sendCustom($recipients, $subject, $html, $logoUrl);
        } catch (\Throwable $e) {
            $entry['status'] = 'falhou';
            $entry['error']  = $e->getMessage();
            $saved = $this->saveHistory($entry);
            return Response::json(['error' => $e->getMessage(), 'id' => $saved['id'] ?? null], 500);
        }

        $saved = $this->saveHistory($entry);
        return Response::json(['message' => 'E-mail enviado com sucesso.', 'id' => $saved['id'] ?? null]);
    }

    // ── Histórico ─────────────────────────────────────────────────────────

    public function listarHistorico(Request $request): Response
    {
        if (!$this->history) {
            return Response::json(['items' => []]);
        }
        $q     = trim(strtolower((string) ($request->query['q'] ?? '')));
        $items = $this->history->all();
        if ($q !== '') {
            $items = array_values(array_filter($items, function ($item) use ($q) {
                return str_contains(strtolower($item['subject'] ?? ''), $q)
                    || str_contains(strtolower(json_encode($item['recipients'] ?? [])), $q)
                    || str_contains(strtolower($item['status'] ?? ''), $q)
                    || str_contains(strtolower($item['error'] ?? ''), $q);
            }));
        }
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

        if (!$this->moduleEnabled()) {
            $msg = 'Módulo de e-mail não está instalado ou está desabilitado.';
            $this->saveHistory([
                'subject'     => $entry['subject'],
                'recipients'  => $entry['recipients'],
                'html'        => $entry['html'],
                'logo_url'    => $entry['logo_url'] ?? null,
                'status'      => 'falhou',
                'error'       => $msg,
                'resent_from' => $id,
            ]);
            return Response::json(['error' => $msg, 'module_disabled' => true], 503);
        }

        $configError = $this->validateSmtpConfig();
        if ($configError) {
            $saved = $this->saveHistory([
                'subject'     => $entry['subject'],
                'recipients'  => $entry['recipients'],
                'html'        => $entry['html'],
                'logo_url'    => $entry['logo_url'] ?? null,
                'status'      => 'falhou',
                'error'       => $configError,
                'resent_from' => $id,
            ]);
            return Response::json(['error' => $configError, 'id' => $saved['id'] ?? null], 503);
        }

        try {
            $this->sender->sendCustom(
                $entry['recipients'],
                $entry['subject'],
                $entry['html'],
                $entry['logo_url'] ?? null
            );
            $this->saveHistory([
                'subject'     => $entry['subject'],
                'recipients'  => $entry['recipients'],
                'html'        => $entry['html'],
                'logo_url'    => $entry['logo_url'] ?? null,
                'status'      => 'enviado',
                'error'       => null,
                'resent_from' => $id,
            ]);
            return Response::json(['message' => 'E-mail reenviado com sucesso.']);
        } catch (\Throwable $e) {
            $this->saveHistory([
                'subject'     => $entry['subject'],
                'recipients'  => $entry['recipients'],
                'html'        => $entry['html'],
                'logo_url'    => $entry['logo_url'] ?? null,
                'status'      => 'falhou',
                'error'       => $e->getMessage(),
                'resent_from' => $id,
            ]);
            return Response::json(['error' => $e->getMessage()], 500);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function saveHistory(array $entry): array
    {
        if (!$this->history) {
            return $entry;
        }
        try {
            return $this->history->save($entry);
        } catch (\Throwable) {
            return $entry;
        }
    }

    private function validateSmtpConfig(): ?string
    {
        $host = trim((string) ($_ENV['EMAIL_HOST'] ?? $_ENV['MAILER_HOST'] ?? ''));
        $user = trim((string) ($_ENV['EMAIL_USERNAME'] ?? $_ENV['MAILER_USERNAME'] ?? ''));
        if ($host === '') {
            return 'SMTP não configurado: MAILER_HOST está vazio no .env.';
        }
        if ($user === '') {
            return 'SMTP não configurado: MAILER_USERNAME está vazio no .env.';
        }
        return null;
    }

    private function moduleEnabled(): bool
    {
        $stateFile = $this->storageDir() . DIRECTORY_SEPARATOR . 'modules_state.json';
        if (!is_file($stateFile)) {
            return true; // no state file = fresh install, allow
        }
        $state   = json_decode((string) file_get_contents($stateFile), true) ?? [];
        $enabled = $state['Email'] ?? $state['email'] ?? null;
        return $enabled !== false;
    }

    private function storageDir(): string
    {
        $dir = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($dir . DIRECTORY_SEPARATOR . 'storage')) {
                return $dir . DIRECTORY_SEPARATOR . 'storage';
            }
            $parent = dirname($dir);
            if ($parent === $dir) break;
            $dir = $parent;
        }
        return dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'storage';
    }
}
