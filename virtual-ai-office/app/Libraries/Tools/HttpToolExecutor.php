<?php

namespace App\Libraries\Tools;

use App\Exceptions\DomainRuleException;

/**
 * Calls a tenant HTTP tool. GET sends the input as query parameters, other methods as a JSON
 * body; {placeholders} in the URL are filled (URL-encoded) from the input and removed from it.
 * No redirects are followed and the response body is capped.
 */
class HttpToolExecutor
{
    public const MAX_RESPONSE_BYTES = 65536;
    public const RESULT_CHARS       = 8000;

    public function __construct(private readonly UrlGuard $guard, private readonly int $timeout = 15)
    {
    }

    /**
     * @param array{method: string, url: string, headers?: array<string,string>} $config
     *
     * @return array{status: int, body: string, is_error: bool, duration_ms: int}
     */
    public function execute(array $config, array $input, array $secretHeaders = []): array
    {
        $input = array_filter($input, static fn ($v) => $v !== null); // optional params come back as null under strict schemas
        $url = preg_replace_callback('/\{([a-z0-9_]+)\}/i', static function ($m) use (&$input) {
            $value = $input[$m[1]] ?? '';
            unset($input[$m[1]]);

            return rawurlencode(is_scalar($value) ? (string) $value : '');
        }, $config['url']) ?? $config['url'];

        $method = strtoupper($config['method'] ?? 'GET');
        if ($method === 'GET' && $input !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($input);
        }
        $target = $this->guard->resolve($url);

        $headers = ['Accept: application/json, text/plain;q=0.9, */*;q=0.5', 'User-Agent: VirtualAIOffice-Agent/1.0'];
        foreach (($config['headers'] ?? []) + $secretHeaders as $name => $value) {
            if (preg_match('/^[A-Za-z0-9-]{1,64}$/', (string) $name) && ! preg_match('/[\r\n]/', (string) $value)) {
                $headers[] = "{$name}: {$value}";
            }
        }

        $body = '';
        $ch   = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_RESOLVE        => ["{$target['host']}:{$target['port']}:{$target['ip']}"],
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_WRITEFUNCTION  => static function ($ch, string $chunk) use (&$body) {
                $room = self::MAX_RESPONSE_BYTES - strlen($body);
                $body .= substr($chunk, 0, max(0, $room));

                return $room > 0 ? strlen($chunk) : 0; // abort once the cap is reached
            },
        ];
        if ($method !== 'GET') {
            $opts[CURLOPT_POSTFIELDS] = json_encode((object) $input, JSON_UNESCAPED_UNICODE);
            $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }
        curl_setopt_array($ch, $opts);

        $started = microtime(true);
        $ok      = curl_exec($ch);
        $status  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error   = curl_error($ch);
        $errno   = curl_errno($ch);
        curl_close($ch);
        $ms = (int) round((microtime(true) - $started) * 1000);

        if ($ok === false && $errno !== CURLE_WRITE_ERROR) {
            throw new DomainRuleException('Permintaan HTTP gagal: ' . $error);
        }

        return [
            'status'      => $status,
            'body'        => mb_substr($body, 0, self::RESULT_CHARS) . (strlen($body) >= self::MAX_RESPONSE_BYTES ? "\n[respons dipotong]" : ''),
            'is_error'    => $status >= 400 || $status === 0,
            'duration_ms' => $ms,
        ];
    }
}
