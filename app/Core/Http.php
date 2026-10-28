<?php
declare(strict_types=1);

namespace App\Core;

/** cURL HTTP client with timeouts and retry/backoff on transient failures. */
final class Http
{
    /**
     * @return array{status:int, body:string, error:?string, json:mixed}
     */
    public static function request(string $method, string $url, array $opts = []): array
    {
        $timeout = $opts['timeout'] ?? Env::int('HTTP_TIMEOUT', 15);
        $retries = $opts['retries'] ?? Env::int('HTTP_RETRIES', 2);
        $headers = $opts['headers'] ?? [];
        $body = $opts['body'] ?? null;
        $attempt = 0;
        $result = ['status' => 0, 'body' => '', 'error' => null, 'json' => null];

        do {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT      => 'GoldSMCAIPro/' . APP_VERSION,
                CURLOPT_HTTPHEADER     => $headers,
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? json_encode($body) : $body);
            }
            $resp = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = $resp === false ? curl_error($ch) : null;
            curl_close($ch);

            $result = ['status' => $status, 'body' => is_string($resp) ? $resp : '', 'error' => $err, 'json' => null];
            if (is_string($resp)) {
                $result['json'] = json_decode($resp, true);
            }
            $transient = $err !== null || $status === 429 || $status >= 500;
            if (!$transient) {
                break;
            }
            $attempt++;
            if ($attempt <= $retries) {
                usleep((int)(250000 * (2 ** $attempt)));  // 0.5s, 1s, 2s ...
            }
        } while ($attempt <= $retries);

        if ($result['error'] !== null || $result['status'] >= 400) {
            Logger::warning('HTTP request failed', [
                'host' => parse_url($url, PHP_URL_HOST), 'status' => $result['status'], 'error' => $result['error'],
            ]);
        }
        return $result;
    }

    public static function get(string $url, array $opts = []): array
    {
        return self::request('GET', $url, $opts);
    }

    public static function postJson(string $url, array $payload, array $headers = [], array $opts = []): array
    {
        $opts['headers'] = array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers);
        $opts['body'] = json_encode($payload);
        return self::request('POST', $url, $opts);
    }
}
