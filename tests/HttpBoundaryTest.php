<?php

declare(strict_types=1);

namespace Ngandu\Flexpay\Tests;

use InvalidArgumentException;
use Ngandu\Flexpay\BaseUrl;
use Ngandu\Flexpay\Client;
use Ngandu\Flexpay\Credential;
use Ngandu\Flexpay\Data\Currency;
use Ngandu\Flexpay\Exception\NetworkException;
use Ngandu\Flexpay\Request\MobileRequest;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class HttpBoundaryTest extends TestCase
{
    public function testEveryHttpAttemptHasBoundedTimeAndNoRedirects(): void
    {
        $requests = 0;
        $client = new Client(new Credential('TESTMERCHANT', 'test-token'), BaseUrl::production(), new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            ++$requests;
            self::assertSame(0, $options['max_redirects']);
            self::assertEquals(30, $options['timeout']);
            self::assertEquals(30, $options['max_duration']);

            return new MockResponse('{"code":"1"}');
        }));
        $client->mobile(new MobileRequest(10, 'ref', Currency::USD, 'https://example.com/callback', '243810000001'));
        $client->check('order-123');
        self::assertSame(2, $requests);
    }

    public function testRedirectIsRejectedBeforeForwardingCredentials(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->expects(self::once())->method('getStatusCode')->willReturn(307);
        $response->expects(self::once())->method('cancel');
        $response->expects(self::never())->method('getContent');
        $response->expects(self::never())->method('toArray');
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::once())->method('withOptions')->willReturnSelf();
        $http->expects(self::once())->method('request')->with('POST', 'https://corporateapi.flexpay.cd/api/rest/v1/paymentService', self::anything())->willReturn($response);
        $client = new Client(new Credential('TESTMERCHANT', 'secret-token'), BaseUrl::production(), $http);

        try {
            $client->mobile(new MobileRequest(10, 'ref', Currency::USD, 'https://example.com/callback', '243810000001'));
            self::fail('Redirects must not be followed');
        } catch (NetworkException $networkException) {
            self::assertSame(307, $networkException->status);
            self::assertSame('unexpected_redirect', $networkException->type);
        }
    }

    public function testRawTransportDetailsDoNotEscapeInExceptionCauses(): void
    {
        $client = new Client(new Credential('TESTMERCHANT', 'secret-token'), BaseUrl::production(), new MockHttpClient(static function (): never {
            throw new TransportException('Raw provider body and secret-token');
        }));

        try {
            $client->mobile(new MobileRequest(10, 'ref', Currency::USD, 'https://example.com/callback', '243810000001'));
            self::fail('Transport failure should be surfaced');
        } catch (NetworkException $networkException) {
            self::assertSame('transport_error', $networkException->type);
            self::assertInstanceOf(RuntimeException::class, $networkException->getPrevious());
            self::assertSame(TransportException::class, $networkException->getPrevious()->getMessage());
            self::assertNull($networkException->getPrevious()->getPrevious());
            self::assertStringNotContainsString('secret-token', $networkException->getMessage());
            self::assertStringNotContainsString('Raw provider body', $networkException->getPrevious()->getMessage());
        }
    }

    public function testProviderErrorMessagesRedactTheCredential(): void
    {
        $client = new Client(new Credential('TESTMERCHANT', 'secret-token'), BaseUrl::production(), new MockHttpClient(new MockResponse('{"message":"Bad secret-token","error":"secret-token"}', [
            'http_code' => 422,
        ])));

        try {
            $client->mobile(new MobileRequest(10, 'ref', Currency::USD, 'https://example.com/callback', '243810000001'));
            self::fail('HTTP error should be surfaced');
        } catch (NetworkException $networkException) {
            self::assertStringNotContainsString('secret-token', $networkException->getMessage());
            self::assertSame('[redacted]', $networkException->type);
            self::assertNull($networkException->getPrevious());
        }
    }

    public function testStatusChecksStopAfterThreeRetries(): void
    {
        $requests = 0;
        $client = new Client(new Credential('TESTMERCHANT', 'test-token'), BaseUrl::production(), new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            ++$requests;
            self::assertSame('GET', $method);
            self::assertEquals(30, $options['max_duration']);

            return new MockResponse('{"message":"Unavailable"}', [
                'http_code' => 503,
                'response_headers' => ['retry-after: 0'],
            ]);
        }));

        try {
            $client->check('retry-exhausted');
            self::fail('A persistent provider failure must surface');
        } catch (NetworkException $networkException) {
            self::assertSame(503, $networkException->status);
        }

        self::assertSame(4, $requests);
    }

    public function testUnboundedOrUnrecognizedRetryAfterDoesNotDelayAnotherRequest(): void
    {
        foreach (['999', 'Fri, 01 Jan 2099 00:00:00 GMT', 'invalid', '1e100', '-1'] as $retryAfter) {
            $requests = 0;
            $client = new Client(new Credential('TESTMERCHANT', 'test-token'), BaseUrl::production(), new MockHttpClient(static function () use (&$requests, $retryAfter): MockResponse {
                ++$requests;

                return new MockResponse('{"message":"Unavailable"}', [
                    'http_code' => 503,
                    'response_headers' => ['retry-after: ' . $retryAfter],
                ]);
            }));

            try {
                $client->check('retry-after');
                self::fail('The current failure must surface without waiting for Retry-After');
            } catch (NetworkException $exception) {
                self::assertSame(503, $exception->status);
            }

            self::assertSame(1, $requests);
        }
    }

    public function testBodyFailureCannotReenableSuppressedRetries(): void
    {
        foreach ([
            [503, ['retry-after: 120']],
            [503, ['retry-after: Fri, 01 Jan 2099 00:00:00 GMT']],
            [503, ['retry-after: invalid']],
            [429, []],
        ] as [$status, $headers]) {
            $requests = 0;
            $client = new Client(new Credential('TESTMERCHANT', 'test-token'), BaseUrl::production(), new MockHttpClient(static function () use (&$requests, $status, $headers): MockResponse {
                ++$requests;

                return new MockResponse([new TransportException('Body disconnected after headers')], [
                    'http_code' => $status,
                    'response_headers' => $headers,
                ]);
            }));

            try {
                $client->check('suppressed-retry');
                self::fail('A body failure must preserve the response retry decision');
            } catch (NetworkException $exception) {
                self::assertSame('transport_error', $exception->type);
                self::assertSame($status, $exception->status);
            }

            self::assertSame(1, $requests);
        }
    }

    public function testMalformedSuccessfulLookupAndClientErrorsAreNotRetried(): void
    {
        foreach ([[200, '{}'], [200, '<html>not-json</html>'], [429, '{"message":"Slow down"}']] as [$status, $body]) {
            $requests = 0;
            $client = new Client(new Credential('TESTMERCHANT', 'test-token'), BaseUrl::production(), new MockHttpClient(static function () use (&$requests, $status, $body): MockResponse {
                ++$requests;

                return new MockResponse($body, [
                    'http_code' => $status,
                ]);
            }));

            try {
                $client->check('not-retryable');
                self::fail('Invalid results must surface');
            } catch (NetworkException $exception) {
                self::assertSame($status, $exception->status);
            }

            self::assertSame(1, $requests);
        }
    }

    public function testGetTransportFailuresHaveTheSameRetryLimit(): void
    {
        $requests = 0;
        $client = new Client(new Credential('TESTMERCHANT', 'test-token'), BaseUrl::production(), new MockHttpClient(static function () use (&$requests): never {
            ++$requests;
            throw new TransportException('DNS lookup failed');
        }));

        try {
            $client->check('transport-retry');
            self::fail('Transport retry exhaustion must surface');
        } catch (NetworkException $networkException) {
            self::assertSame('transport_error', $networkException->type);
        }

        self::assertSame(4, $requests);
    }

    public function testDotOnlyOrderNumbersNeverReachHttp(): void
    {
        $client = new Client(new Credential('TESTMERCHANT', 'test-token'), BaseUrl::production(), new MockHttpClient(static function (): never {
            self::fail('Dot-only orders must be rejected before HTTP');
        }));

        foreach (['.', '..'] as $order) {
            try {
                $client->check($order);
                self::fail('Dot path segments must not be normalized by the HTTP client');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('dot path segment', $exception->getMessage());
            }
        }
    }
}
