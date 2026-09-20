<?php

declare(strict_types=1);

namespace Alphabees\Tutor\Client;

use Alphabees\Tutor\Store\Config;
use ilDBInterface;
use RuntimeException;

/**
 * Every call that leaves ILIAS.
 *
 * Only the cron plugin and the configuration screen use this. A page render
 * must never reach here — an ILIAS page may not wait on our availability.
 *
 * Errors come back as exceptions rather than silent nulls: the caller is
 * always a background job that has to decide between "retry later" and
 * "this batch is broken", and a null cannot carry that difference.
 */
final class BackendClient
{
    /** Generous, but far below any cron slot. A hung backend must not eat the run. */
    private const TIMEOUT_SECONDS = 30;
    private const CONNECT_TIMEOUT_SECONDS = 10;

    private Config $config;

    public function __construct(ilDBInterface $db)
    {
        $this->config = new Config($db);
    }

    public function config(): Config
    {
        return $this->config;
    }

    /**
     * Pair this installation with a portal tenant.
     *
     * The only unsigned call — the backend cannot know our key before we send
     * it. What carries the tenant is the one-time code the administrator
     * pasted in, and it is spent by this call.
     *
     * @return array<string,mixed>
     */
    public function pair(string $backendUrl, string $token, array $payload): array
    {
        $url = rtrim($backendUrl, '/') . '/al/tutors/v1/ilias/register';
        $body = json_encode(
            array_merge($payload, ['token' => $token]),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return $this->send('POST', $url, (string) $body, ['Content-Type' => 'application/json']);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function post(string $path, array $payload): array
    {
        $body = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->signedRequest('POST', $path, $body);
    }

    /**
     * @return array<string,mixed>
     */
    public function get(string $path): array
    {
        return $this->signedRequest('GET', $path, '');
    }

    public function sitePath(string $suffix): string
    {
        $registrationId = $this->config->get(Config::REGISTRATION_ID);
        if ($registrationId === null) {
            throw new RuntimeException('Site is not paired.');
        }

        return '/al/tutors/v1/ilias/sites/' . rawurlencode($registrationId) . '/' . ltrim($suffix, '/');
    }

    /**
     * @return array<string,mixed>
     */
    private function signedRequest(string $method, string $path, string $body): array
    {
        $backendUrl = $this->config->get(Config::BACKEND_URL);
        $privateKey = $this->config->get(Config::PRIVATE_KEY);
        $site = $this->config->get(Config::SITE_IDENTIFIER);
        if ($backendUrl === null || $privateKey === null || $site === null) {
            throw new RuntimeException('Site is not paired.');
        }

        $signer = new Signer($privateKey, $site, (int) $this->config->get(Config::KEY_ID, '1'));
        // The signature covers the PATH, not the full URL — the backend sees
        // `request.url.path`, which is what a reverse proxy leaves intact.
        $headers = $signer->headers($method, $path, $body);
        $headers['Content-Type'] = 'application/json';

        return $this->send($method, rtrim($backendUrl, '/') . $path, $body, $headers);
    }

    /**
     * @param array<string,string> $headers
     * @return array<string,mixed>
     */
    private function send(string $method, string $url, string $body, array $headers): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Could not initialise HTTP client.');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($body !== '') {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($response === false) {
            $this->recordFailure($error ?: 'connection failed');
            throw new RuntimeException('Backend unreachable: ' . ($error ?: 'connection failed'));
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            $this->recordFailure('HTTP ' . $status . ', unreadable answer');
            throw new RuntimeException('Backend answered with something that is not JSON (HTTP ' . $status . ').');
        }

        if ($status >= 400) {
            $detail = (string) ($decoded['detail'] ?? $decoded['message'] ?? 'HTTP ' . $status);
            $this->recordFailure($detail);
            throw new RuntimeException($detail, $status);
        }

        $this->config->set(Config::LAST_SUCCESS_AT, (string) time());
        $this->config->set(Config::LAST_ERROR, null);

        return $decoded;
    }

    private function recordFailure(string $message): void
    {
        $this->config->set(Config::LAST_ERROR, mb_substr($message, 0, 250));
        $this->config->set(Config::LAST_ERROR_AT, (string) time());
    }
}
