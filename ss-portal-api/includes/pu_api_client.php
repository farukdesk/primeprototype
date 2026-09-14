<?php
/**
 * SS Portal – HTTP client for the Prime University Student API v1.
 *
 * Wraps the endpoints the portal uses (see admin/api/v1/API-GUIDE.md):
 *   GET  /reference-data.php      (scope reference:read)
 *   POST /students/create.php     (scope students:create [+ results:create])
 *   POST /students/update.php     (scope students:update)
 *   POST /students/delete.php     (scope students:delete)
 *   POST /results/create.php      (scope results:create)
 *
 * The API key never leaves the server: every call is made from PHP with cURL.
 * Every method returns a normalised array:
 *   ok          bool    2xx and body.ok === true
 *   status      int     HTTP status (0 = network / transport failure)
 *   code        string  body.code, or transport_error / invalid_response
 *   message     string  human-readable message from the API (or transport error)
 *   body        ?array  decoded JSON body
 *   headers     array   lower-cased response headers (x-ratelimit-remaining, retry-after, ...)
 *   duration_ms int
 */

require_once __DIR__ . '/bootstrap.php';

final class PuApiClient
{
    private string $baseUrl;
    private string $apiKey;
    private int $timeout;
    private bool $verifySsl;

    public function __construct(array $cfg)
    {
        $this->baseUrl   = rtrim((string)($cfg['base_url'] ?? ''), '/');
        $this->apiKey    = trim((string)($cfg['api_key'] ?? ''));
        $this->timeout   = max(5, (int)($cfg['timeout'] ?? 30));
        $this->verifySsl = (bool)($cfg['verify_ssl'] ?? true);
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->apiKey !== '';
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /** Key shown in the UI: first 6 and last 4 characters only. */
    public function maskedKey(): string
    {
        if ($this->apiKey === '') {
            return '(not set)';
        }
        if (strlen($this->apiKey) <= 12) {
            return str_repeat('•', strlen($this->apiKey));
        }
        return substr($this->apiKey, 0, 6) . str_repeat('•', 10) . substr($this->apiKey, -4);
    }

    public function referenceData(): array
    {
        return $this->request('GET', '/reference-data.php');
    }

    public function createStudent(array $payload, string $idempotencyKey): array
    {
        return $this->request('POST', '/students/create.php', $payload, ['X-Idempotency-Key: ' . $idempotencyKey]);
    }

    public function publishResult(array $payload): array
    {
        return $this->request('POST', '/results/create.php', $payload);
    }

    /** Partial update of a registered student (API guide §6.9). */
    public function updateStudent(array $payload): array
    {
        return $this->request('POST', '/students/update.php', $payload);
    }

    /** Permanent delete at the university (API guide §6.10). */
    public function deleteStudent(array $payload): array
    {
        return $this->request('POST', '/students/delete.php', $payload);
    }

    /** True when the API guide says the same request may simply be retried later. */
    public static function isRetryable(array $resp): bool
    {
        return (int)$resp['status'] === 0
            || (int)$resp['status'] >= 500
            || (int)$resp['status'] === 429
            || ($resp['code'] ?? '') === 'request_in_progress';
    }

    private function request(string $method, string $path, ?array $json = null, array $extraHeaders = []): array
    {
        $headers = [
            'X-API-Key: ' . $this->apiKey,
            'Accept: application/json',
            'User-Agent: SS-Portal/' . SSP_VERSION . ' (+php-curl)',
        ];
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        $headers = array_merge($headers, $extraHeaders);

        $respHeaders = [];
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($json !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE));
        }

        $start    = microtime(true);
        $raw      = curl_exec($ch);
        $duration = (int)round((microtime(true) - $start) * 1000);
        $errno    = curl_errno($ch);
        $error    = curl_error($ch);
        $status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            return [
                'ok' => false, 'status' => 0, 'code' => 'transport_error',
                'message' => 'Could not reach the university API: ' . ($error !== '' ? $error : 'cURL error ' . $errno),
                'body' => null, 'headers' => $respHeaders, 'duration_ms' => $duration,
            ];
        }

        $body = json_decode((string)$raw, true);
        if (!is_array($body)) {
            return [
                'ok' => false, 'status' => $status, 'code' => 'invalid_response',
                'message' => 'The university API returned a non-JSON response (HTTP ' . $status . ').',
                'body' => ['raw' => mb_substr((string)$raw, 0, 2000)], 'headers' => $respHeaders, 'duration_ms' => $duration,
            ];
        }

        $is2xx = $status >= 200 && $status < 300;
        return [
            'ok'          => $is2xx && (bool)($body['ok'] ?? false),
            'status'      => $status,
            'code'        => (string)($body['code'] ?? ($is2xx ? 'ok' : 'http_' . $status)),
            'message'     => (string)($body['message'] ?? ''),
            'body'        => $body,
            'headers'     => $respHeaders,
            'duration_ms' => $duration,
        ];
    }
}

/** Shared client instance built from config.php → pu_api. */
function ssp_api(): PuApiClient
{
    static $client = null;
    if ($client === null) {
        $client = new PuApiClient((array)ssp_config('pu_api', []));
    }
    return $client;
}
