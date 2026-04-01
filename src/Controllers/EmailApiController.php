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
    private string $storagePath;

    public function __construct(?EmailSenderInterface $sender = null)
    {
        $this->sender      = $sender ?? new EmailService();
        $this->storagePath = $this->resolveStorageDir();
        try {
            $this->history = new EmailHistory($this->storagePath);
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

        // Module disabled check
        if (!$this->moduleEnabled()) {
            $msg = 'Módulo de e-mail não está instalado ou está desabilitado.';
            $this->saveHistory($subject, $recipients, $html, $logoUrl, 'falhou', $msg);
            return Response::json(['error' => $msg, 'module_disabled' => true], 503);
        }

        // SMTP config check
        $configError = $this->validateSmtpConfig();
        if ($configError) {
            $saved = $this->saveHistory($subject, $recipients, $html, $logoUrl, 'falhou', $configError);
            return Response::json(['error' => $configError, 'module_disabled' => true, 'id' => $saved['id'] ?? null], 503);
        }

        // Send
        try {
            $this->sender->sendCustom($recipients, $subject, $html, $logoUrl);
            $saved = $this->saveHistory($subject, $recipients, $html, $logoUrl, 'enviado', null);
            return Response::json(['message' => 'E-mail enviado com sucesso.', 'id' => $saved['id'] ?? null]);
        } catch (\Throwable $e) {
            $saved = $this->saveHistory($subject, $recipients, $html, $logoUrl, 'falhou', $e->getMessage());
            return Response::json(['error' => $e->getMessage(), 'id' => $saved['id'] ?? null], 500);
        }
    }

    // ── Histórico ─────────────────────────────────────────────────────────

    public function listarHistorico(Request $request): Response
    {
        if (!$this->history) {
            return Response::json(['items' => [], 'warning' => 'Histórico indisponível (storage inacessível).']);
        }
        $q     = trim(strtolower((string) ($request->query['q'] ?? '')));
        $items = $this->history->all();
        if ($q !== '') {
            $items = array_values(array_filter($items, fn($item) =>
                str_contains(strtolower($item['subject'] ?? ''), $q)
                || str_contains(strtolower(json_encode($item['recipients'] ?? [])), $q)
                || str_contains(strtolower($item['status'] ?? ''), $q)
                || str_contains(strtolower($item['error'] ?? ''), $q)
            ));
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
            $this->saveHistory($entry['subject'], $entry['recipients'], $entry['html'], $entry['logo_url'] ?? null, 'falhou', $msg, $id);
            return Response::json(['error' => $msg, 'module_disabled' => true], 503);
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
            return Response::json(['error' => $e->getMessage()], 500);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function saveHistory(
        string $subject,
        array $recipients,
        string $html,
        ?string $logoUrl,
        string $status,
        ?string $error,
        ?string $resentFrom = null
    ): array {
        $entry = compact('subject', 'recipients', 'html', 'logoUrl', 'status', 'error');
        $entry['logo_url'] = $logoUrl;
        unset($entry['logoUrl']);
        if ($resentFrom !== null) {
            $entry['resent_from'] = $resentFrom;
        }
        if (!$this->history) {
            error_log('[EmailModule] History not available, skipping save. Storage: ' . $this->storagePath);
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
        $host = trim((string) ($_ENV['EMAIL_HOST'] ?? $_ENV['MAILER_HOST'] ?? ''));
        $user = trim((string) ($_ENV['EMAIL_USERNAME'] ?? $_ENV['MAILER_USERNAME'] ?? ''));
        if ($host === '') return 'SMTP não configurado: MAILER_HOST está vazio no .env.';
        if ($user === '') return 'SMTP não configurado: MAILER_USERNAME está vazio no .env.';
        return null;
    }

    private function moduleEnabled(): bool
    {
        $stateFile = $this->storagePath . DIRECTORY_SEPARATOR . 'modules_state.json';
        if (!is_file($stateFile)) return true;
        $state   = json_decode((string) file_get_contents($stateFile), true) ?? [];
        $enabled = $state['Email'] ?? $state['email'] ?? null;
        return $enabled !== false;
    }

    private function resolveStorageDir(): string
    {
        // Walk up from __DIR__ to find the project root (contains storage/)
        $dir = __DIR__;
        for ($i = 0; $i < 10; $i++) {
            $candidate = $dir . DIRECTORY_SEPARATOR . 'storage';
            if (is_dir($candidate)) {
                return $candidate;
            }
            $parent = dirname($dir);
            if ($parent === $dir) break;
            $dir = $parent;
        }
        // Absolute fallback based on known vendor depth: vendor/sweflow/module-email/src/Controllers
        $fallback = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'storage';
        error_log('[EmailModule] storageDir fallback: ' . $fallback);
        return $fallback;
    }
}
