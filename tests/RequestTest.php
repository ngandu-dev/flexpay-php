<?php

declare(strict_types=1);

namespace Ngandu\Flexpay\Tests;

use InvalidArgumentException;
use Ngandu\Flexpay\BaseUrl;
use Ngandu\Flexpay\Client;
use Ngandu\Flexpay\Credential;
use Ngandu\Flexpay\Data\Currency;
use Ngandu\Flexpay\Request\CardRequest;
use Ngandu\Flexpay\Request\MobileRequest;
use Ngandu\Flexpay\Request\PayoutRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RequestTest extends TestCase
{
    public function testOneRequestCanBeSentBySeparateMerchantsWithoutCredentialMutation(): void
    {
        $request = new MobileRequest(10, 'shared-reference', Currency::USD, 'https://example.com/callback', '243810000001');
        $original = $request->toPayload();

        foreach (['FIRST', 'SECOND'] as $merchant) {
            $client = new Client(new Credential($merchant, 'test-token'), BaseUrl::production(), new MockHttpClient(static function (string $method, string $url, array $options) use ($merchant): MockResponse {
                $body = json_decode((string) $options['body'], true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($body);
                self::assertSame($merchant, $body['merchant']);
                self::assertSame('shared-reference', $body['reference']);
                self::assertArrayNotHasKey('authorization', $body);

                return new MockResponse('{"code":"0","orderNumber":"shared-order"}');
            }));

            self::assertTrue($client->mobile($request)->isAccepted());
            self::assertSame($original, $request->toPayload());
        }

        self::assertArrayNotHasKey('merchant', $request->toPayload());
    }

    public function testRequestsRejectNonfiniteOrNonpositiveAmounts(): void
    {
        foreach ([INF, -INF, NAN, 0, -10] as $amount) {
            foreach (['mobile', 'card', 'payout'] as $kind) {
                try {
                    match ($kind) {
                        'mobile' => new MobileRequest($amount, 'ref', Currency::USD, 'https://example.com/callback', '243810000001'),
                        'card' => new CardRequest($amount, 'ref', Currency::USD, 'https://example.com/callback', 'https://example.com/approve', 'https://example.com/cancel', 'https://example.com/decline'),
                        default => new PayoutRequest($amount, 'ref', Currency::USD, 'https://example.com/callback', '243810000001'),
                    };
                    self::fail('An invalid amount must fail before HTTP');
                } catch (InvalidArgumentException $exception) {
                    self::assertStringContainsString('amount', $exception->getMessage());
                }
            }
        }
    }

    public function testMobileRequestRejectsNonDigitsAndBlankFields(): void
    {
        foreach ([['ref', 'https://example.com/callback', 'abcdefghijkl'], [' ', 'https://example.com/callback', '243810000001'], ['ref', ' ', '243810000001']] as [$reference, $callback, $phone]) {
            try {
                new MobileRequest(10, $reference, Currency::USD, $callback, $phone);
                self::fail('Invalid request inputs must be rejected');
            } catch (InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function testPayoutKeepsItsDocumentedLegacyWireFields(): void
    {
        $request = new PayoutRequest(10, 'payout-ref', Currency::USD, 'https://example.com/callback', '243810000001');

        self::assertSame([
            'type' => 1,
            'amount' => 10.0,
            'reference' => 'payout-ref',
            'currency' => 'USD',
            'callbackUrl' => 'https://example.com/callback',
            'phone' => '243810000001',
        ], $request->toPayload());
    }
}
