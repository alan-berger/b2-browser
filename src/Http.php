<?php
declare(strict_types=1);

namespace B2Browser;

/** Request facts and response headers. */
final class Http
{
    /**
     * The TCP peer address. Forwarding headers are deliberately ignored: any
     * client can set them. Behind a reverse proxy, have the web server
     * rewrite REMOTE_ADDR from a trusted header (nginx real_ip, Apache
     * mod_remoteip) so rate limiting sees real client addresses.
     */
    public static function clientIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }

    /**
     * Whether the client connection is HTTPS. X-Forwarded-Proto is trusted
     * here only because the answer merely decides whether to send HSTS.
     */
    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    public static function isPost(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }

    /**
     * Security headers for HTML pages. Scripts, styles and fonts come only from
     * this origin (no inline code at all, so no nonce is needed); images and video
     * may additionally come from the B2 download host for this account.
     *
     * @param list<string> $mediaOrigins
     */
    public static function sendSecurityHeaders(array $mediaOrigins, bool $hsts): void
    {
        $media = implode(' ', array_map(static fn(string $o): string => preg_replace('/[^A-Za-z0-9:\/.\-]/', '', $o) ?? '', $mediaOrigins));
        $csp = [
            "default-src 'none'",
            "script-src 'self'",
            "style-src 'self'",
            "font-src 'self'",
            trim("img-src 'self' {$media}"),
            $media !== '' ? "media-src {$media}" : "media-src 'none'",
            "form-action 'self'",
            "base-uri 'none'",
            "frame-ancestors 'none'",
        ];
        header('Content-Security-Policy: ' . implode('; ', $csp));
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Permissions-Policy: accelerometer=(), autoplay=(self), camera=(), '
            . 'display-capture=(), encrypted-media=(self), fullscreen=(self), geolocation=(), gyroscope=(), '
            . 'magnetometer=(), microphone=(), midi=(), payment=(), picture-in-picture=(self), usb=()');
        header('Cache-Control: no-store');
        if ($hsts && self::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    /**
     * RFC 6266 Content-Disposition with an ASCII fallback and a UTF-8
     * filename* parameter. v1.x interpolated the raw name inside quotes.
     */
    public static function contentDisposition(string $fileName): string
    {
        // Object names use '/' only; a backslash is an ordinary character.
        $slash = strrpos($fileName, '/');
        $fileName = $slash === false ? $fileName : substr($fileName, $slash + 1);
        $ascii = preg_replace('/[^\x20-\x7E]/u', '_', $fileName) ?? 'download';
        $ascii = str_replace(['"', '\\'], '_', $ascii);
        if ($ascii === '' || $ascii === '.' || $ascii === '..') {
            $ascii = 'download';
        }
        return 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($fileName);
    }
}
