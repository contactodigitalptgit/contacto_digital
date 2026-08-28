<?php

namespace App\Services\ZoneSoft;

use App\Models\ZoneSoftApplication;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class ZoneSoftApiClient
{
    private const REQUEST_RETRY_DELAYS_MS = [500, 1000, 2000];

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
        bool $retryUnauthorized = false,
        ?int $requestTimeoutSeconds = null,
        ?int $requestRetryAttempts = null,
    ): array {
        $body = $this->encodeBody($entityName, $entityPayload);

        $retryAttempt = 0;

        while (true) {
            try {
                $response = $this->pendingRequest(
                    $application,
                    $zsClientId,
                    $body,
                    requestTimeoutSeconds: $requestTimeoutSeconds,
                )
                    ->send('POST', $this->resolveEndpoint($application, $interface, $action));
            } catch (ConnectionException $connectionException) {
                $exception = new ZoneSoftApiException(
                    'A ligacao a ZoneSoft falhou temporariamente: '.$connectionException->getMessage(),
                    0,
                    $connectionException,
                );

                if ($this->shouldRetryRequest(
                    $exception,
                    $retryAttempt,
                    $retryUnauthorized,
                    $requestRetryAttempts,
                )) {
                    $this->pauseForRetry($retryAttempt);
                    $retryAttempt++;

                    continue;
                }

                throw $exception;
            }

            try {
                return $this->normalizeResponse($response);
            } catch (ZoneSoftApiException $exception) {
                if ($this->shouldRetryRequest(
                    $exception,
                    $retryAttempt,
                    $retryUnauthorized,
                    $requestRetryAttempts,
                )) {
                    $this->pauseForRetry($retryAttempt, $response);
                    $retryAttempt++;

                    continue;
                }

                throw $exception;
            }
        }
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $entityPayloads
     * @return array<int|string, array<string, mixed>|ZoneSoftApiException>
     */
    public function postMany(
        ZoneSoftApplication $application,
        string $zsClientId,
        string $interface,
        string $action,
        string $entityName,
        array $entityPayloads,
        int $concurrency = 2,
    ): array {
        if ($entityPayloads === []) {
            return [];
        }

        $bodies = [];

        foreach ($entityPayloads as $key => $entityPayload) {
            $bodies[$key] = $this->encodeBody($entityName, $entityPayload);
        }

        $endpoint = $this->resolveEndpoint($application, $interface, $action);
        $responses = Http::pool(function (Pool $pool) use ($application, $zsClientId, $bodies, $endpoint): void {
            foreach ($bodies as $key => $body) {
                $this->pendingRequest($application, $zsClientId, $body, $pool->as((string) $key))
                    ->send('POST', $endpoint);
            }
        }, max(1, $concurrency));
        $results = [];

        foreach ($entityPayloads as $key => $entityPayload) {
            $response = $responses[(string) $key] ?? $responses[$key] ?? null;

            try {
                if ($response instanceof Response) {
                    $results[$key] = $this->normalizeResponse($response);

                    continue;
                }

                if ($response instanceof \Throwable) {
                    throw new ZoneSoftApiException(
                        'A ligacao a ZoneSoft falhou temporariamente: '.$response->getMessage(),
                        0,
                        $response,
                    );
                }

                throw new ZoneSoftApiException('A ZoneSoft nao devolveu uma resposta valida.', 0);
            } catch (ZoneSoftApiException $exception) {
                if (! $exception->isTransient() && $exception->statusCode() !== 401) {
                    $results[$key] = $exception;

                    continue;
                }

                try {
                    $results[$key] = $this->post(
                        $application,
                        $zsClientId,
                        $interface,
                        $action,
                        $entityName,
                        $entityPayload,
                        true,
                    );
                } catch (ZoneSoftApiException $retryException) {
                    $results[$key] = $retryException;
                }
            }
        }

        return $results;
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

    private function shouldRetryRequest(
        ZoneSoftApiException $exception,
        int $retryAttempt,
        bool $retryUnauthorized,
        ?int $requestRetryAttempts = null,
    ): bool {
        if ($retryUnauthorized && $exception->statusCode() === 401) {
            return $retryAttempt < 2;
        }

        return $exception->isTransient()
            && $retryAttempt < $this->requestRetryAttempts($requestRetryAttempts);
    }

    private function pauseForRetry(int $retryAttempt, ?Response $response = null): void
    {
        $retryAfterHeader = $response?->header('Retry-After');

        if (is_string($retryAfterHeader) && is_numeric($retryAfterHeader) && (int) $retryAfterHeader > 0) {
            usleep((int) $retryAfterHeader * 1_000_000);

            return;
        }

        $delayMs = self::REQUEST_RETRY_DELAYS_MS[$retryAttempt]
            ?? self::REQUEST_RETRY_DELAYS_MS[count(self::REQUEST_RETRY_DELAYS_MS) - 1];

        usleep((int) $delayMs * 1000);
    }

    private function encodeBody(string $entityName, array $entityPayload): string
    {
        $body = json_encode(
            [$entityName => $entityPayload],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        if ($body === false) {
            throw new ZoneSoftApiException('Nao foi possivel serializar o payload da ZoneSoft.');
        }

        return $body;
    }

    private function pendingRequest(
        ZoneSoftApplication $application,
        string $zsClientId,
        string $body,
        ?PendingRequest $request = null,
        ?int $requestTimeoutSeconds = null,
    ): PendingRequest {
        return ($request ?? Http::withHeaders([]))
            ->withHeaders([
                'Content-Type' => 'application/json',
                'X-ZS-APP-KEY' => $application->app_key,
                'X-ZS-CLIENT-ID' => $zsClientId,
                'X-ZS-SIGNATURE' => hash_hmac('sha256', $body, $application->app_secret),
            ])
            ->connectTimeout($this->connectTimeoutSeconds())
            ->timeout($this->requestTimeoutSeconds($requestTimeoutSeconds))
            ->withBody($body, 'application/json');
    }

    private function connectTimeoutSeconds(): int
    {
        return max(1, min(
            60,
            (int) config('event-reports.zonesoft.connect_timeout_seconds', 5),
        ));
    }

    private function requestTimeoutSeconds(?int $requestTimeoutSeconds = null): int
    {
        return max(5, min(
            120,
            $requestTimeoutSeconds
                ?? (int) config('event-reports.zonesoft.request_timeout_seconds', 30),
        ));
    }

    private function requestRetryAttempts(?int $requestRetryAttempts = null): int
    {
        return max(0, min(
            count(self::REQUEST_RETRY_DELAYS_MS),
            $requestRetryAttempts
                ?? (int) config('event-reports.zonesoft.request_retry_attempts', 1),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeResponse(Response $response): array
    {
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

    private function buildErrorMessage(mixed $json, string $fallbackBody): string
    {
        if (is_array($json)) {
            foreach (['message', 'error', 'detail'] as $key) {
                $value = $json[$key] ?? null;

                if (is_string($value) && trim($value) !== '') {
                    return $this->normalizeProviderMessage($value);
                }
            }
        }

        $fallbackBody = trim($fallbackBody);

        if ($fallbackBody !== '') {
            return $this->normalizeProviderMessage($fallbackBody);
        }

        return 'A API da ZoneSoft retornou um erro inesperado.';
    }

    private function normalizeProviderMessage(string $message): string
    {
        $normalized = trim($message);
        $lowerMessage = mb_strtolower($normalized);

        if (
            str_contains($lowerMessage, 'rate limit')
            || str_contains($lowerMessage, 'too many requests')
            || str_contains($lowerMessage, 'exceeded the rate limit')
        ) {
            return 'A ZoneSoft limitou temporariamente os pedidos. Tente novamente dentro de alguns instantes.';
        }

        return $normalized;
    }
}
