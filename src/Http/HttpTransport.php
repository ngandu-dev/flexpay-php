<?php

declare(strict_types=1);

namespace Ngandu\Flexpay\Http;

use InvalidArgumentException;
use Ngandu\Flexpay\Credential;
use Ngandu\Flexpay\Exception\NetworkException;
use RuntimeException;
use SensitiveParameter;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Owns authenticated provider I/O, bounded retries, JSON decoding, and HTTP failure context.
 *
 * Client supplies operation URLs and request payloads. The transport injects the merchant and bearer
 * token, sends POST once, and retries GET only. Decoder callbacks validate JSON without seeing HTTP.
 * Raw provider responses and library exceptions never escape through exception causes.
 *
 * @internal
 * @author bernard-ng <bernard@ngandu.dev>
 */
final readonly class HttpTransport
{
    private HttpClientInterface $http;

    public function __construct(
        private Credential $credential,
        ?HttpClientInterface $http = null
    ) {
        $this->http = ($http ?? HttpClient::create())->withOptions([
            'auth_bearer' => $credential->token,
            'headers' => [
                'Accept' => 'application/json',
            ],
            'max_redirects' => 0,
            'timeout' => 30,
            'max_duration' => 30,
        ]);
    }

    /**
     * @template T
     * @param array<string, float|string|int> $payload
     * @param callable(array<array-key, mixed>): T $decode
     * @return T
     */
    public function post(string $url, array $payload, callable $decode): mixed
    {
        return $this->request('POST', $url, $decode, [
            'json' => [
                ...$payload,
                'merchant' => $this->credential->merchant,
            ],
        ]);
    }

    /**
     * @template T
     * @param callable(array<array-key, mixed>): T $decode
     * @return T
     */
    public function get(string $url, callable $decode): mixed
    {
        return $this->request('GET', $url, $decode);
    }

    /**
     * @template T
     * @param callable(array<array-key, mixed>): T $decode
     * @param array<string, mixed> $options
     * @return T
     */
    private function request(string $method, string $url, callable $decode, array $options = []): mixed
    {
        $maxRetries = $method === 'GET' ? 3 : 0;

        for ($attempt = 0; ; ++$attempt) {
            $status = null;
            $response = null;
            $retryAllowed = $attempt < $maxRetries;

            try {
                $response = $this->http->request($method, $url, $options);
                $status = $response->getStatusCode();

                if ($status >= 400 && $status <= 499) {
                    $retryAllowed = false;
                }

                if ($retryAllowed && $status >= 500 && $status <= 599) {
                    $delay = $this->retryDelay($response->getHeaders(throw: false), $attempt);

                    if ($delay !== null) {
                        $response->cancel();
                        usleep($delay * 1000);
                        continue;
                    }

                    $retryAllowed = false;
                }

                if ($status >= 300 && $status < 400) {
                    $response->cancel();
                    throw NetworkException::create('Flexpay redirects are not followed', 'unexpected_redirect', $status);
                }

                try {
                    $data = $response->toArray(throw: false);
                } catch (DecodingExceptionInterface $exception) {
                    $contentType = $response->getHeaders(throw: false)['content-type'][0] ?? 'unknown';

                    throw NetworkException::create(
                        message: $this->redact(sprintf('Flexpay returned an invalid JSON response (Content-Type: %s)', $contentType)),
                        type: 'invalid_response',
                        status: $status,
                        previous: $this->safeCause($exception),
                    );
                }

                if ($status >= 400) {
                    throw NetworkException::create(
                        message: $this->redact(is_string($data['message'] ?? null) ? $data['message'] : ''),
                        type: is_string($data['error'] ?? null) ? $this->redact($data['error']) : null,
                        status: $status,
                    );
                }

                try {
                    return $decode($data);
                } catch (InvalidArgumentException $exception) {
                    throw NetworkException::create(
                        message: 'Flexpay returned an invalid response payload',
                        type: 'invalid_response',
                        status: $status,
                        previous: $this->safeCause($exception),
                    );
                }
            } catch (HttpClientExceptionInterface $httpClientException) {
                if ($retryAllowed) {
                    $response?->cancel();
                    usleep(500_000 * 2 ** $attempt);
                    continue;
                }

                throw new NetworkException('Unable to contact Flexpay', 'transport_error', $status, $this->safeCause($httpClientException));
            }
        }
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function retryDelay(array $headers, int $attempt): ?int
    {
        $retryAfter = $headers['retry-after'][0] ?? null;
        $delay = 500 * 2 ** $attempt;

        if ($retryAfter !== null) {
            if (preg_match('/^\d+$/D', $retryAfter) !== 1) {
                return null;
            }

            $delay = max($delay, (float) $retryAfter * 1000);
        }

        if (! is_finite((float) $delay) || $delay > 2000) {
            return null;
        }

        return (int) max(0, $delay);
    }

    private function redact(string $message): string
    {
        return str_replace($this->credential->token, '[redacted]', $message);
    }

    private function safeCause(#[SensitiveParameter] Throwable $exception): RuntimeException
    {
        return new RuntimeException($exception::class);
    }
}
