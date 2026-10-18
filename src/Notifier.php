<?php
declare(strict_types=1);

namespace B2Browser;

/**
 * Sends security alerts by email and/or to an ntfy topic.
 *
 * Alerts are structured (title, severity, field table, note) and rendered per
 * channel, with every value escaped for HTML email. Delivery failures are
 * logged and never interrupt the request.
 */
final class Notifier
{
    public function __construct(private readonly Config $config, private readonly Logger $logger)
    {
    }

    /**
     * @param 'info'|'warning'|'danger' $severity
     * @param array<string, string> $fields
     */
    public function send(string $title, string $severity, array $fields, string $note = ''): void
    {
        if ($this->config->bool('alerts.email.enabled')) {
            $this->sendEmail($title, $severity, $fields, $note);
        }
        if ($this->config->bool('alerts.ntfy.enabled')) {
            $this->sendNtfy($title, $severity, $fields, $note);
        }
    }

    /** @param array<string, string> $fields */
    private function sendEmail(string $title, string $severity, array $fields, string $note): void
    {
        $from = $this->config->string('alerts.email.from');
        $replyTo = $this->config->string('alerts.email.reply_to');
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $this->logger->error('alerts.email.from is not a valid address; email alert skipped');
            return;
        }
        $siteTitle = $this->config->string('site.title');
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $colour = ['info' => '#28a745', 'warning' => '#ffc107', 'danger' => '#dc3545'][$severity] ?? '#6c757d';

        $rows = '';
        foreach ($fields as $label => $value) {
            $rows .= '<tr><th style="text-align:left;padding:8px;background:#f8f9fa;border-bottom:1px solid #dee2e6">'
                . $e($label) . '</th><td style="padding:8px;border-bottom:1px solid #dee2e6">' . $e($value) . '</td></tr>';
        }
        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>' . $e($title) . '</title></head>'
            . '<body style="font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#333;background:#f4f4f4;margin:0">'
            . '<div style="max-width:600px;margin:20px auto;background:#fff">'
            . '<div style="padding:20px;border-left:6px solid ' . $colour . '"><h1 style="margin:0;font-size:20px">' . $e($title) . '</h1></div>'
            . '<table style="width:100%;border-collapse:collapse">' . $rows . '</table>'
            . ($note !== '' ? '<p style="padding:0 20px">' . $e($note) . '</p>' : '')
            . '<p style="padding:20px;font-size:12px;color:#6c757d;border-top:1px solid #dee2e6">Automated security notification from '
            . $e($siteTitle) . '.</p></div></body></html>';

        $text = $title . "\n\n";
        foreach ($fields as $label => $value) {
            $text .= "{$label}: {$value}\n";
        }
        $text .= $note !== '' ? "\n{$note}\n" : '';

        $boundary = 'b2b-' . bin2hex(random_bytes(12));
        $body = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text))
            . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html))
            . "--{$boundary}--\r\n";

        $headers = [
            'From: =?UTF-8?B?' . base64_encode($this->headerSafe($siteTitle)) . '?= <' . $from . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        if (filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $headers[] = 'Reply-To: ' . $replyTo;
        }
        $subject = '=?UTF-8?B?' . base64_encode($this->headerSafe($title)) . '?=';

        foreach ($this->config->stringList('alerts.email.recipients') as $recipient) {
            if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            if (!@mail($recipient, $subject, $body, implode("\r\n", $headers), '-f' . $from)) {
                $this->logger->error("email alert to {$recipient} failed");
            }
        }
    }

    /** @param array<string, string> $fields */
    private function sendNtfy(string $title, string $severity, array $fields, string $note): void
    {
        $server = rtrim($this->config->string('alerts.ntfy.server'), '/');
        $topic = $this->config->string('alerts.ntfy.topic');
        if ($topic === '' || !str_starts_with($server, 'https://') && !str_starts_with($server, 'http://')) {
            $this->logger->error('ntfy alert skipped: server or topic not configured');
            return;
        }
        $message = '';
        foreach ($fields as $label => $value) {
            $message .= "{$label}: {$value}\n";
        }
        $message .= $note;

        // JSON publishing (POST to the server root) avoids header-encoding
        // issues with non-ASCII titles.
        $payload = json_encode([
            'topic' => $topic,
            'title' => $title,
            'message' => trim($message),
            'priority' => ['info' => 3, 'warning' => 4, 'danger' => 5][$severity] ?? 3,
            'tags' => [['info' => 'white_check_mark', 'warning' => 'warning', 'danger' => 'rotating_light'][$severity] ?? 'bell'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        $headers = ['Content-Type: application/json'];
        $token = $this->config->string('alerts.ntfy.token');
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->headerSafe($token);
        }
        $ch = curl_init($server . '/');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        if ($ok === false || $status >= 300) {
            $this->logger->error("ntfy alert failed (HTTP {$status}) {$error}");
        }
    }

    private function headerSafe(string $value): string
    {
        return str_replace(["\r", "\n"], ' ', $value);
    }
}
