<?php

declare(strict_types=1);

namespace Ngandu\Flexpay\Tests;

use InvalidArgumentException;
use Ngandu\Flexpay\BaseUrl;
use Ngandu\Flexpay\Client;
use Ngandu\Flexpay\Credential;
use Ngandu\Flexpay\Data\Currency;
use Ngandu\Flexpay\Data\Status;
use Ngandu\Flexpay\Data\Transaction;
use Ngandu\Flexpay\Exception\NetworkException;
use Ngandu\Flexpay\Request\CardRequest;
use Ngandu\Flexpay\Request\MobileRequest;
use Ngandu\Flexpay\Request\PayoutRequest;
use Ngandu\Flexpay\Response\CallbackResponse;
use Ngandu\Flexpay\Response\CheckResponse;
use Ngandu\Flexpay\Response\MobileResponse;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CorporateContractTest extends TestCase
{
    public function testCollectionsUseCorporateJsonAndHeaderAuthentication(): void
    {
        $expected = [
            [
                'phone' => '243810000001',
                'type' => '1',
                'amount' => 12.5,
                'merchant' => 'TESTMERCHANT',
                'reference' => 'mobile-001',
                'currency' => 'USD',
                'callback_url' => 'http://localhost/callback',
            ],
            [
                'amount' => 12.5,
                'merchant' => 'TESTMERCHANT',
                'type' => '2',
                'reference' => 'card-001',
                'currency' => 'USD',
                'callback_url' => 'http://localhost/callback',
                'approve_url' => 'http://localhost/approve',
                'cancel_url' => 'http://localhost/cancel',
                'decline_url' => 'http://localhost/decline',
            ],
        ];
        $index = 0;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use ($expected, &$index): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://corporateapi.flexpay.cd/api/rest/v1/paymentService', $url);
            self::assertContains('Authorization: Bearer secret-token', $options['headers']);
            self::assertContains('Content-Type: application/json', $options['headers']);
            self::assertEquals($expected[$index++], json_decode((string) $options['body'], true, flags: JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString('secret-token', $options['body']);

            return new MockResponse('{"code":"0","orderNumber":"order-001","url":"https://checkout.example/card"}');
        });
        $client = new Client(new Credential(merchant: 'TESTMERCHANT', token: 'secret-token'), BaseUrl::production(), $http);

        self::assertTrue($client->mobile(new MobileRequest(12.5, 'mobile-001', Currency::USD, 'http://localhost/callback', '243810000001'))->isAccepted());
        self::assertTrue($client->card(new CardRequest(12.5, 'card-001', Currency::USD, 'http://localhost/callback', 'http://localhost/approve', 'http://localhost/cancel', 'http://localhost/decline'))->isAccepted());
        self::assertSame(2, $index);
    }

    public function testCorporateReferenceDoesNotInheritTheLegacyCardLimit(): void
    {
        $reference = 'invoice-' . str_repeat('a', 80);
        $request = new CardRequest(10, $reference, Currency::USD, 'http://localhost/callback', 'http://localhost/approve', 'http://localhost/cancel', 'http://localhost/decline');

        self::assertSame($reference, $request->toPayload()['reference']);
    }

    public function testIndependentEndpointOverrides(): void
    {
        $expected = [
            ['POST', 'http://localhost:8080/pay/api/rest/v1/paymentService'],
            ['POST', 'http://localhost:8080/pay/api/rest/v1/paymentService'],
            ['GET', 'http://localhost:8081/status/api/rest/v1/check/order%2Fwith%20spaces%3Fx%3D%23'],
            ['POST', 'http://localhost:8082/out/api/rest/v1/merchantPayOutService'],
        ];
        $index = 0;
        $http = new MockHttpClient(static function (string $method, string $url) use ($expected, &$index): MockResponse {
            self::assertSame($expected[$index++], [$method, $url]);

            return new MockResponse('{"code":0}');
        });
        $client = new Client(
            new Credential(merchant: 'TESTMERCHANT', token: 'token'),
            new BaseUrl(
                payment: ' http://localhost:8080/pay/api/rest/v1/// ',
                check: 'http://localhost:8081/status/api/rest/v1/',
                payout: 'http://localhost:8082/out/api/rest/v1',
            ),
            $http,
        );
        $client->mobile(new MobileRequest(10, 'mobile', Currency::USD, 'http://localhost/callback', '243810000001'));
        $client->card(new CardRequest(10, 'card', Currency::USD, 'http://localhost/callback', 'http://localhost/approve', 'http://localhost/cancel', 'http://localhost/decline'));
        $client->check('order/with spaces?x=#');
        $client->payout(new PayoutRequest(10, 'payout', Currency::USD, 'http://localhost/callback', '243810000001'));
        self::assertSame(4, $index);
    }

    public function testPaymentOverrideDoesNotRedirectProductionChecksOrPayouts(): void
    {
        $expected = [
            'https://apicheck.flexpaie.com/api/rest/v1/check/order',
            'https://backend.flexpay.cd/api/rest/v1/merchantPayOutService',
        ];
        $index = 0;
        $client = new Client(
            new Credential(merchant: 'TESTMERCHANT', token: 'token'),
            new BaseUrl('http://localhost/api/rest/v1', BaseUrl::production()->check, BaseUrl::production()->payout),
            new MockHttpClient(static function (string $method, string $url) use ($expected, &$index): MockResponse {
                self::assertSame($expected[$index++], $url);

                return new MockResponse('{"code":0}');
            }),
        );
        $client->check('order');
        $client->payout(new PayoutRequest(10, 'payout', Currency::USD, 'http://localhost/callback', '243810000001'));
        self::assertSame(2, $index);
    }

    public function testStatusSeedsPreserveUnknownCodesWithoutTreatingThemAsSuccessful(): void
    {
        foreach ([
            'succeeded' => true,
            'failed' => false,
            'pending' => false,
            'unknown' => false,
        ] as $name => $successful) {
            $seed = (string) file_get_contents(__DIR__ . '/fixtures/corporate/check_' . $name . '.json');
            $client = new Client(new Credential(merchant: 'TESTMERCHANT', token: 'token'), BaseUrl::production(), new MockHttpClient(new MockResponse($seed)));
            $response = $client->check('seed-' . $name);

            self::assertSame(0, $response->code->value, 'Lookup acceptance differs from collection status');
            self::assertSame($successful, $response->isSuccessful());
            self::assertSame($successful, $response->transaction?->isSuccessful());
            self::assertSame('seed-' . $name, $response->transaction->orderNumber);

            if ($name === 'pending') {
                self::assertSame(2, $response->transaction->status->value, '2 is a local simulator value, not an upstream guarantee');
            }

            if ($name === 'unknown') {
                self::assertSame(99, $response->transaction->status->value);
            }
        }
    }

    public function testNonzeroCallbackCodesRemainFailuresAndPreserveTheirValue(): void
    {
        foreach ([1, 2, 99, -1] as $code) {
            $response = CallbackResponse::fromPayload([
                'code' => (string) $code,
                'reference' => 'seed-callback',
            ]);
            self::assertFalse($response->isSuccessful());
            self::assertSame($code, $response->code->value);
        }
    }

    public function testAcceptedLookupWithoutTransactionIsNotSuccessful(): void
    {
        $client = new Client(new Credential(merchant: 'TESTMERCHANT', token: 'token'), BaseUrl::production(), new MockHttpClient(new MockResponse('{"code":"0"}')));
        self::assertFalse($client->check('missing-transaction')->isSuccessful());
    }

    public function testResultsSeparateAcceptanceFromPaymentCompletion(): void
    {
        $success = new Status(0);
        $transaction = new Transaction('reference', 10, 10, '29-10-2026 12:00:00', $success, Currency::USD);

        self::assertTrue(new MobileResponse($success)->isAccepted());
        self::assertTrue($transaction->isSuccessful());
        self::assertTrue(new CheckResponse($success, transaction: $transaction)->isSuccessful());
        self::assertFalse(new CheckResponse(new Status(1), transaction: $transaction)->isSuccessful());
    }

    public function testBlankOrderNumberIsRejectedWithoutHttp(): void
    {
        $client = new Client(new Credential(merchant: 'TESTMERCHANT', token: 'token'), BaseUrl::production(), new MockHttpClient(static function (): never {
            self::fail('Blank order numbers must not reach HTTP');
        }));
        $this->expectException(InvalidArgumentException::class);
        $client->check('  ');
    }

    public function testMalformedPayloadsDoNotBecomeSuccessfulOrLeakBody(): void
    {
        foreach (['{}', '{"code":false}', '{"code":0.5}', '{"code":"garbage"}', '{"code":"0","transaction":{"status":"0"}}', '{"code":"0","transaction":{"status":"0","reference":"ref","amount":10,"amountCustomer":10,"createdAt":"2026-10-29"}}'] as $body) {
            $client = new Client(new Credential(merchant: 'TESTMERCHANT', token: 'secret-token'), BaseUrl::production(), new MockHttpClient(new MockResponse($body)));

            try {
                $client->check('malformed');
                self::fail('Malformed provider data must be rejected');
            } catch (NetworkException $exception) {
                self::assertSame('invalid_response', $exception->type);
                self::assertSame(200, $exception->status);
                self::assertInstanceOf(RuntimeException::class, $exception->getPrevious());
                self::assertStringNotContainsString('secret-token', $exception->getMessage());
                self::assertStringNotContainsString($body, $exception->getMessage());
            }
        }
    }

    public function testNonIdempotentRequestsAreNeverRetried(): void
    {
        foreach ([429, 500, 502, 503] as $status) {
            foreach (['mobile', 'card', 'payout'] as $method) {
                $requests = 0;
                $client = new Client(new Credential(merchant: 'TESTMERCHANT', token: 'token'), BaseUrl::production(), new MockHttpClient(static function () use (&$requests, $status): MockResponse {
                    ++$requests;

                    return new MockResponse('{"message":"Try later"}', [
                        'http_code' => $status,
                    ]);
                }));
                $request = match ($method) {
                    'mobile' => new MobileRequest(10, 'ref', Currency::USD, 'http://localhost/callback', '243810000001'),
                    'card' => new CardRequest(10, 'ref', Currency::USD, 'http://localhost/callback', 'http://localhost/approve', 'http://localhost/cancel', 'http://localhost/decline'),
                    default => new PayoutRequest(10, 'ref', Currency::USD, 'http://localhost/callback', '243810000001'),
                };

                try {
                    match (true) {
                        $request instanceof MobileRequest => $client->mobile($request),
                        $request instanceof CardRequest => $client->card($request),
                        default => $client->payout($request),
                    };
                    self::fail('Expected a provider HTTP error');
                } catch (NetworkException $exception) {
                    self::assertSame($status, $exception->status);
                }

                self::assertSame(1, $requests, 'Collection and payout must not be resubmitted automatically');
            }
        }
    }

    public function testPostTransportFailuresNeverRetryEvenForDnsFailure(): void
    {
        foreach ([
            new MobileRequest(10, 'ref', Currency::USD, 'http://localhost/callback', '243810000001'),
            new CardRequest(10, 'ref', Currency::USD, 'http://localhost/callback', 'http://localhost/approve', 'http://localhost/cancel', 'http://localhost/decline'),
            new PayoutRequest(10, 'ref', Currency::USD, 'http://localhost/callback', '243810000001'),
        ] as $request) {
            $requests = 0;
            $client = new Client(new Credential('TESTMERCHANT', 'test-token'), BaseUrl::production(), new MockHttpClient(static function () use (&$requests): never {
                ++$requests;
                throw new TransportException('DNS lookup failed');
            }));

            try {
                match (true) {
                    $request instanceof MobileRequest => $client->mobile($request),
                    $request instanceof CardRequest => $client->card($request),
                    default => $client->payout($request),
                };
                self::fail('Transport failure must surface after one POST');
            } catch (NetworkException $exception) {
                self::assertSame('transport_error', $exception->type);
            }

            self::assertSame(1, $requests);
        }
    }

    public function testStatusLookupRetriesTransientFailure(): void
    {
        $requests = 0;
        $client = new Client(new Credential(merchant: 'TESTMERCHANT', token: 'token'), BaseUrl::production(), new MockHttpClient(static function () use (&$requests): MockResponse {
            ++$requests;

            return $requests === 1
                ? new MockResponse('{"message":"Try later"}', [
                    'http_code' => 503,
                    'response_headers' => ['retry-after: 0'],
                ])
                : new MockResponse('{"code":"1","message":"Order unavailable"}');
        }));

        self::assertFalse($client->check('retry-order')->isSuccessful());
        self::assertSame(2, $requests);
    }
}
