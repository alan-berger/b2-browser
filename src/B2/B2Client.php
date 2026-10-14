<?php
declare(strict_types=1);

namespace B2Browser\B2;

use B2Browser\JsonFileStore;

/**
 * Minimal Backblaze B2 native API client (API v4).
 *
 * Changes from v1.x:
 *  - API v4; the authorisation response's `allowed` block is exposed so the
 *    app can verify the key is properly restricted (see KeyScope).
 *  - The account token is cached in a 0600 file shared by all sessions
 *    instead of in every user's PHP session, and is refreshed once
 *    automatically when B2 reports it expired or invalid.
 *  - Object names are percent-encoded per path segment when building URLs;
 *    v1.x concatenated raw names, which broke on spaces, '#' and '?'.
 *  - Raw B2 responses are kept out of exception messages shown to users.
 */
final class B2Client
{
    private const TOKEN_LIFETIME = 82800; // B2 tokens last 24 h; refresh after 23 h.

    /** @var array{token: string, apiUrl: string, downloadUrl: string, allowed: array<string, mixed>, expires: int}|null */
    private ?array $auth = null;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    public function __construct(
        private readonly string $keyId,
        private readonly string $applicationKey,
        private readonly string $bucketId,
        private readonly string $bucketName,
        private readonly JsonFileStore $tokenCache,
        private readonly string $apiBase = 'https://api.backblazeb2.com',
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    /** @return array<string, mixed> The key's restrictions: buckets, capabilities, namePrefix. */
    public function allowed(): array
    {
        return $this->authorization()['allowed'];
    }

    /** scheme://host of the download endpoint, for the Content-Security-Policy. */
    public function downloadOrigin(): string
    {
        $parts = parse_url($this->authorization()['downloadUrl']);
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
        return isset($parts['port']) ? $origin . ':' . $parts['port'] : $origin;
    }

    /**
     * @return array{files: list<array<string, mixed>>, nextFileName: ?string}
     */
    public function listFileNames(string $prefix, string $delimiter, ?string $startFileName, int $maxFileCount): array
    {
        $payload = ['bucketId' => $this->bucketId, 'prefix' => $prefix, 'maxFileCount' => $maxFileCount];
        if ($delimiter !== '') {
            $payload['delimiter'] = $delimiter;
        }
        if ($startFileName !== null && $startFileName !== '') {
            $payload['startFileName'] = $startFileName;
        }
        $result = $this->call('b2_list_file_names', $payload);
        $files = isset($result['files']) && is_array($result['files']) ? array_values($result['files']) : [];
        $next = isset($result['nextFileName']) && is_string($result['nextFileName']) ? $result['nextFileName'] : null;
        return ['files' => $files, 'nextFileName' => $next];
    }

    /**
     * A download authorisation token for every object whose name starts with
     * $namePrefix. B2 authorises by prefix only: a token for "a.mp4" also
     * covers "a.mp4.bak". Keep lifetimes short.
     */
    public function downloadAuthorization(string $namePrefix, int $ttl): string
    {
        $result = $this->call('b2_get_download_authorization', [
            'bucketId' => $this->bucketId,
            'fileNamePrefix' => $namePrefix,
            'validDurationInSeconds' => $ttl,
        ]);
        if (!isset($result['authorizationToken']) || !is_string($result['authorizationToken'])) {
            throw new B2Exception('b2_get_download_authorization returned no token');
        }
        return $result['authorizationToken'];
    }

    /** Download URL for an object, with each path segment percent-encoded. */
    public function objectUrl(string $fileName): string
    {
        return rtrim($this->authorization()['downloadUrl'], '/')
            . '/file/' . rawurlencode($this->bucketName) . '/' . self::encodePath($fileName);
    }

    /** A URL that works without credentials until the token expires. */
    public function signedUrl(string $fileName, int $ttl): string
    {
        return $this->withToken($this->objectUrl($fileName), $this->downloadAuthorization($fileName, $ttl));
    }

    public function withToken(string $objectUrl, string $token): string
    {
        return $objectUrl . '?Authorization=' . rawurlencode($token);
    }

    public static function encodePath(string $name): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $name)));
    }

    /**
     * Stream an object through PHP. $onStart(status, headers) is called once
     * the response headers are known and before any body bytes; return false
     * to abort. $onChunk receives body data. Returns the HTTP status.
     * Retries once on an expired account token, provided nothing was sent.
     *
     * @param callable(int, array<string, string>): bool $onStart
     * @param callable(string): void $onChunk
     */
    public function streamObject(string $fileName, callable $onStart, callable $onChunk): int
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $status = 0;
            $headers = [];
            $started = false;
            $errorBody = '';

            $ch = curl_init($this->objectUrl($fileName));
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER => ['Authorization: ' . $this->authorization()['token']],
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_LOW_SPEED_LIMIT => 1,
                CURLOPT_LOW_SPEED_TIME => 60,
                CURLOPT_BUFFERSIZE => 65536,
                CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$status, &$headers): int {
                    if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                        $status = (int) $m[1];
                        $headers = [];
                    } elseif (str_contains($line, ':')) {
                        [$name, $value] = explode(':', $line, 2);
                        $headers[strtolower(trim($name))] = trim($value);
                    }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static function ($ch, string $data) use (&$status, &$headers, &$started, &$errorBody, $onStart, $onChunk): int {
                    if ($status !== 200) {
                        $errorBody .= substr($data, 0, 4096 - strlen($errorBody));
                        return strlen($data);
                    }
                    if (!$started) {
                        $started = true;
                        if (!$onStart($status, $headers)) {
                            return 0; // abort transfer
                        }
                    }
                    $onChunk($data);
                    return strlen($data);
                },
            ]);
            $ok = curl_exec($ch);
            $curlError = curl_error($ch);

            if ($status === 200) {
                if (!$started) {
                    // Zero-length object: no body callback ever fired.
                    $onStart($status, $headers);
                }
                if ($ok === false && $curlError !== '') {
                    throw new B2Exception("Download interrupted: {$curlError}", 200);
                }
                return $status;
            }
            if ($status === 0) {
                throw new B2Exception("Download failed: {$curlError}");
            }
            $error = self::errorFrom($status, $errorBody);
            if ($attempt === 0 && $error->isAuthExpired()) {
                $this->invalidateToken();
                continue;
            }
            throw $error;
        }
        throw new B2Exception('Download failed after re-authorisation');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function call(string $operation, array $payload): array
    {
        try {
            return $this->post($operation, $payload);
        } catch (B2Exception $e) {
            if (!$e->isAuthExpired()) {
                throw $e;
            }
            $this->invalidateToken();
            return $this->post($operation, $payload);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function post(string $operation, array $payload): array
    {
        $auth = $this->authorization();
        [$status, $body] = $this->http(
            rtrim($auth['apiUrl'], '/') . '/b2api/v4/' . $operation,
            ['Authorization: ' . $auth['token'], 'Content-Type: application/json'],
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
        if ($status !== 200) {
            throw self::errorFrom($status, $body, $operation);
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new B2Exception("{$operation}: invalid JSON response", $status);
        }
        return $decoded;
    }

    /** @return array{token: string, apiUrl: string, downloadUrl: string, allowed: array<string, mixed>, expires: int} */
    private function authorization(): array
    {
        $now = ($this->clock)();
        if ($this->auth !== null && $this->auth['expires'] > $now) {
            return $this->auth;
        }
        $cacheId = hash('sha256', $this->keyId . '|' . $this->apiBase);
        $cached = $this->tokenCache->read();
        if (($cached['id'] ?? null) === $cacheId && ($cached['expires'] ?? 0) > $now
            && isset($cached['token'], $cached['apiUrl'], $cached['downloadUrl'], $cached['allowed'])) {
            return $this->auth = [
                'token' => (string) $cached['token'],
                'apiUrl' => (string) $cached['apiUrl'],
                'downloadUrl' => (string) $cached['downloadUrl'],
                'allowed' => (array) $cached['allowed'],
                'expires' => (int) $cached['expires'],
            ];
        }

        [$status, $body] = $this->http(
            rtrim($this->apiBase, '/') . '/b2api/v4/b2_authorize_account',
            ['Authorization: Basic ' . base64_encode($this->keyId . ':' . $this->applicationKey)],
            null,
        );
        if ($status !== 200) {
            throw self::errorFrom($status, $body, 'b2_authorize_account');
        }
        $data = json_decode($body, true);
        $storage = $data['apiInfo']['storageApi'] ?? null;
        if (!is_array($data) || !isset($data['authorizationToken']) || !is_array($storage)
            || !isset($storage['apiUrl'], $storage['downloadUrl'])) {
            throw new B2Exception('b2_authorize_account: unexpected response format', $status);
        }

        $this->auth = [
            'token' => (string) $data['authorizationToken'],
            'apiUrl' => (string) $storage['apiUrl'],
            'downloadUrl' => (string) $storage['downloadUrl'],
            'allowed' => is_array($storage['allowed'] ?? null) ? $storage['allowed'] : [],
            'expires' => $now + self::TOKEN_LIFETIME,
        ];
        $record = ['id' => $cacheId] + $this->auth;
        $this->tokenCache->transact(static fn(): array => [$record, null]);
        return $this->auth;
    }

    private function invalidateToken(): void
    {
        $this->auth = null;
        $this->tokenCache->transact(static fn(): array => [[], null]);
    }

    /**
     * @param list<string> $headers
     * @return array{0: int, 1: string}
     */
    private function http(string $url, array $headers, ?string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        if ($response === false) {
            throw new B2Exception("Request to B2 failed: {$error}");
        }
        return [$status, (string) $response];
    }

    private static function errorFrom(int $status, string $body, string $operation = 'download'): B2Exception
    {
        $data = json_decode($body, true);
        $code = is_array($data) && isset($data['code']) ? (string) $data['code'] : '';
        $message = is_array($data) && isset($data['message']) ? (string) $data['message'] : '';
        return new B2Exception(trim("{$operation} failed (HTTP {$status}) {$code}: {$message}"), $status, $code);
    }
}
