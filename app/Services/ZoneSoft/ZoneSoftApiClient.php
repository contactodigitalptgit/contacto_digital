<?php

namespace App\Services\ZoneSoft;

use App\Models\ZoneSoftApplication;
use Illuminate\Support\Facades\Http;

class ZoneSoftApiClient
{
    /**
     * @param  array<string, mixed>  $entityPayload
     * @return array<string, mixed>
     */
    public function post(
        ZoneSoftApplication $application,
        string $zsClientId,
        string $interface,
        string $action,
        string $entityName,
        array $entityPayload,
    ): array {
        $body = json_encode(
            [$entityName => $entityPayload],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        if ($body === false) {
            throw new ZoneSoftApiException('Nao foi possivel serializar o payload da ZoneSoft.');
        }

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-ZS-APP-KEY' => $application->app_key,
            'X-ZS-CLIENT-ID' => $zsClientId,
            'X-ZS-SIGNATURE' => hash_hmac('sha256', $body, $application->app_secret),
        ])
            ->connectTimeout(15)
            ->timeout(120)
            ->withBody($body, 'application/json')
            ->send('POST', $this->resolveEndpoint($application, $interface, $action));

        if (in_array($response->status(), [204, 304], true)) {
            return [];
        }

        if (! $response->successful()) {
            throw new ZoneSoftApiException(
                $this->buildErrorMessage($response->json(), $response->body()),
                $response->status(),
            );
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new ZoneSoftApiException('A resposta da ZoneSoft nao retornou um JSON valido.');
        }

        return $this->normalizePayload($payload);
    }

    private function resolveEndpoint(ZoneSoftApplication $application, string $interface, string $action): string
    {
        $baseUrl = rtrim($application->base_url, '/');

        if (! str_ends_with($baseUrl, '/v3')) {
            $baseUrl .= '/v3';
        }

        return sprintf('%s/%s/%s', $baseUrl, trim($interface, '/'), trim($action, '/'));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(array $payload): array
    {
        $response = $payload['Response'] ?? null;

        if (! is_array($response)) {
            return $payload;
        }

        $statusCode = $response['StatusCode'] ?? null;

        if (is_numeric($statusCode) && (int) $statusCode >= 400) {
            $message = $response['StatusMessage'] ?? null;
            $content = $response['Content'] ?? null;

            throw new ZoneSoftApiException(
                is_string($message) && trim($message) !== ''
                    ? $message
                    : $this->buildErrorMessage(
                        is_array($content) ? $content : null,
                        is_string($content) ? $content : '',
                    ),
                (int) $statusCode,
            );
        }

        $content = $response['Content'] ?? null;

        return is_array($content) ? $content : $payload;
    }

    /**
     * @param  mixed  $json
     */
    private function buildErrorMessage(mixed $json, string $fallbackBody): string
    {
        if (is_array($json)) {
            foreach (['message', 'error', 'detail'] as $key) {
                $value = $json[$key] ?? null;

                if (is_string($value) && trim($value) !== '') {
                    return $value;
                }
            }
        }

        $fallbackBody = trim($fallbackBody);

        if ($fallbackBody !== '') {
            return $fallbackBody;
        }

        return 'A API da ZoneSoft retornou um erro inesperado.';
    }
}
