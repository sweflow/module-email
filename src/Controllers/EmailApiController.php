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
    private EmailHistory $history;

    public function __construct(?EmailSenderInterface $sender = null)
    {
        $this->sender  = $sender ?? new EmailService();
        $this->history = new EmailHistory($this->storageDir());
    }

    // ── Envio ─────────────────────────────────────────────────────────────

    public function custom(Request $request): Response
    {
        if (!$this->moduleEnabled()) {
            return Response::json(['error' => 'Módulo de e-mail não está instalado ou está desabilitado.'], 503);
        }

        $body       = $request->body ?? [];
        $recipients = $body['recipients'] ?? [];
        $subject    = trim((string) ($body['subject'] ?? ''));
        $html       = trim((string) ($body['html'] ?? ''));
        $logoUrl    = trim((string) ($body['logo_url'] ?? '')) ?: null;

        if (empty($recipients) || $subject === '' || $html === '') {
            return Response::json(['error' => 'Campos obrigatórios: recipients, subject, html.'], 422);
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
            $saved = $this->history->save($entry);
            return Response::json(['error' => $e->getMessage(), 'id' => $saved['id']], 500);
        }

        $saved = $this->history->save($entry);
        return Response::json(['message' => 'E-mail enviado com sucesso.', 'id' => $saved['id']]);
    }

    // ── Histórico ─────────────────────────────────────────────────────────

    public function listarHistorico(Request $request): Response
    {
        return Response::json(['items' => $this->history->all()]);
    }

    public function detalheHistorico(Request $request, string $id): Response
    {
        $entry = $this->history->find($id);
        if (!$entry) {
            return Response::json(['error' => 'Registro não encontrado.'], 404);
        }
        return Response::json($entry);
    }

    public function deletarHistorico(Request $request, string $id): Response
    {
        if (!$this->history->delete($id)) {
            return Response::json(['error' => 'Registro não encontrado.'], 404);
        }
        return Response::json(['message' => 'Registro excluído.']);
    }

    public function reenviar(Request $request, string $id): Response
    {
        $entry = $this->history->find($id);
        if (!$entry) {
            return Response::json(['error' => 'Registro não encontrado.'], 404);
        }

        if (!$this->moduleEnabled()) {
            $this->history->save([
                'subject'     => $entry['subject'],
                'recipients'  => $entry['recipients'],
                'html'        => $entry['html'],
                'logo_url'    => $entry['logo_url'] ?? null,
                'status'      => 'falhou',
                'error'       => 'Módulo de e-mail não está instalado ou está desabilitado.',
                'resent_from' => $id,
            ]);
            return Response::json([
                'error'          => 'Módulo de e-mail não está instalado ou está desabilitado.',
                'module_disabled' => true,
            ], 503);
        }

        try {
            $this->sender->sendCustom(
                $entry['recipients'],
                $entry['subject'],
                $entry['html'],
                $entry['logo_url'] ?? null
            );
            $this->history->save([
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
            $this->history->save([
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

    private function moduleEnabled(): bool
    {
        $stateFile = $this->storageDir() . DIRECTORY_SEPARATOR . 'modules_state.json';
        if (!is_file($stateFile)) {
            // No state file = module system not initialized, allow by default
            return true;
        }
        $state   = json_decode((string) file_get_contents($stateFile), true) ?? [];
        $enabled = $state['Email'] ?? $state['email'] ?? null;
        // null = never registered in state (fresh install), treat as enabled
        return $enabled !== false;
    }

    private function storageDir(): string
    {
        // Works whether installed as vendor package or in src/Modules/Email
        // Walks up from src/Controllers/ to find the project root (where storage/ lives)
        $dir = __DIR__;
        for ($i = 0; $i < 6; $i++) {
            if (is_dir($dir . DIRECTORY_SEPARATOR . 'storage')) {
                return $dir . DIRECTORY_SEPARATOR . 'storage';
            }
            $parent = dirname($dir);
            if ($parent === $dir) break;
            $dir = $parent;
        }
        // Fallback: storage next to vendor/
        return dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'storage';
    }
}
