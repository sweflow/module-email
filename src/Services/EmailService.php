<?php
namespace SweflowModules\Email\Services;

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;
use Src\Kernel\Contracts\EmailSenderInterface;

class EmailService implements EmailSenderInterface
{
    public function sendCustom(array|string $recipients, string $subject, string $htmlBody, ?string $logoUrl = null): void
    {
        $normalized = $this->normalizeRecipients($recipients);
        $this->deliver($normalized, $subject, $htmlBody, $logoUrl);
    }

    public function sendConfirmation(string $toEmail, string $toName, string $confirmLink, ?string $logoUrl = null): void
    {
        $subject = 'Confirme seu e-mail';
        $body = "<p>Olá, {$this->escape($toName)}!</p><p>Clique no link abaixo para confirmar seu e-mail:</p><p><a href='{$this->escapeAttr($confirmLink)}'>{$this->escapeAttr($confirmLink)}</a></p>";
        $recipients = [['email' => $toEmail, 'name' => $toName]];
        $this->deliver($recipients, $subject, $body, $logoUrl);
    }

    public function sendPasswordReset(string $toEmail, string $toName, string $resetLink, ?string $logoUrl = null): void
    {
        $subject = 'Recuperação de senha';
        $body = "<p>Olá, {$this->escape($toName)}!</p><p>Use o link abaixo para redefinir sua senha:</p><p><a href='{$this->escapeAttr($resetLink)}'>{$this->escapeAttr($resetLink)}</a></p>";
        $recipients = [['email' => $toEmail, 'name' => $toName]];
        $this->deliver($recipients, $subject, $body, $logoUrl);
    }

    private function deliver(array $recipients, string $subject, string $body, ?string $logoUrl = null): void
    {
        if (count($recipients) === 0) {
            throw new \InvalidArgumentException('Destinatários não informados.');
        }

        $mail = $this->makeMailer();

        foreach ($recipients as $recipient) {
            $mail->addAddress($recipient['email'], $recipient['name'] ?? '');
        }

        $mail->Subject = $subject;
        $mail->isHTML(true);

        if (!empty($logoUrl)) {
            $cid = 'logo_' . md5($logoUrl);
            $logoAlt = 'Logo';
            $logoName = basename(parse_url($logoUrl, PHP_URL_PATH) ?: 'logo.png');
            $embedded = false;

            try {
                if (preg_match('#^https?://#i', $logoUrl)) {
                    $data = @file_get_contents($logoUrl);
                    if ($data !== false) {
                        $mail->addStringEmbeddedImage($data, $cid, $logoName);
                        $embedded = true;
                    }
                } elseif (file_exists($logoUrl)) {
                    $mail->addEmbeddedImage($logoUrl, $cid, $logoName);
                    $embedded = true;
                }
            } catch (MailException $e) {
            }

            if ($embedded) {
                $body = "<div style='margin-bottom:16px'><img src='cid:{$cid}' alt='{$this->escape($logoAlt)}' style='max-height:64px;'></div>" . $body;
            } else {
                // If it's a URL but we failed to embed (e.g. timeout), just link it
                $body = "<div style='margin-bottom:16px'><img src='{$this->escapeAttr($logoUrl)}' alt='{$this->escape($logoAlt)}' style='max-height:64px;'></div>" . $body;
            }
        }

        $mail->Body = $body;
        $mail->AltBody = strip_tags($body);

        $sent = $mail->send();
        if (!$sent) {
            throw new \RuntimeException('Falha ao enviar e-mail.');
        }
    }

    private function normalizeRecipients(array|string $recipients): array
    {
        $list = [];

        if (is_string($recipients)) {
            $emails = preg_split('/[;,\n]+/', $recipients) ?: [];
            foreach ($emails as $email) {
                $email = trim($email);
                if ($email !== '') {
                    $list[] = ['email' => $email, 'name' => $email];
                }
            }
        } else {
            foreach ($recipients as $rec) {
                $email = trim($rec['email'] ?? ($rec['to'] ?? ''));
                if ($email === '') {
                    continue;
                }
                $name = trim($rec['name'] ?? $email);
                $list[] = ['email' => $email, 'name' => $name];
            }
        }

        $unique = [];
        $seen = [];
        foreach ($list as $item) {
            if (isset($seen[$item['email']])) {
                continue;
            }
            $seen[$item['email']] = true;
            $unique[] = $item;
        }

        if (count($unique) === 0) {
            throw new \InvalidArgumentException('Destinatários não informados.');
        }

        return $unique;
    }

    private function makeMailer(): PHPMailer
    {
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host = $_ENV['EMAIL_HOST'] ?? $_ENV['MAILER_HOST'] ?? '';
        $mail->Port = (int) ($_ENV['EMAIL_PORT'] ?? $_ENV['MAILER_PORT'] ?? 587);
        $mail->SMTPAuth = true;
        $mail->Username = $_ENV['EMAIL_USERNAME'] ?? $_ENV['MAILER_USERNAME'] ?? '';
        $mail->Password = $_ENV['EMAIL_PASSWORD'] ?? $_ENV['MAILER_PASSWORD'] ?? '';
        $mail->SMTPSecure = $this->resolveEncryption($_ENV['EMAIL_ENCRYPTION'] ?? $_ENV['MAILER_ENCRYPTION'] ?? '') ?? PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';
        $debug = $this->boolEnv($_ENV['EMAIL_DEBUG'] ?? $_ENV['MAILER_DEBUG'] ?? 'false');
        $mail->SMTPDebug = $debug ? 2 : 0;
        $mail->Debugoutput = static function ($str, $level) {
            error_log('[MAILER][' . $level . '] ' . $str);
        };

        $fromEmail = $_ENV['EMAIL_FROM'] ?? $_ENV['MAILER_FROM_EMAIL'] ?? 'no-reply@example.com';
        $fromName = $_ENV['EMAIL_FROM_NAME'] ?? $_ENV['MAILER_FROM_NAME'] ?? 'API';
        $mail->setFrom($fromEmail, $fromName);

        $replyTo = $_ENV['EMAIL_REPLY_TO'] ?? $_ENV['MAILER_REPLY_TO'] ?? '';
        if ($replyTo) {
            $mail->addReplyTo($replyTo);
        }

        return $mail;
    }

    private function boolEnv(string $value): bool
    {
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    private function resolveEncryption(string $value): ?string
    {
        $v = strtolower(trim($value));
        return match ($v) {
            'tls', 'starttls' => PHPMailer::ENCRYPTION_STARTTLS,
            'ssl' => PHPMailer::ENCRYPTION_SMTPS,
            default => null,
        };
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    private function escapeAttr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
