<?php

declare(strict_types=1);

namespace Ngandu\Flexpay\Tests;

use Ngandu\Flexpay\BaseUrl;
use Ngandu\Flexpay\Client;
use Ngandu\Flexpay\Credential;
use Ngandu\Flexpay\Data\Currency;
use Ngandu\Flexpay\Data\Status;
use Ngandu\Flexpay\Exception\ClientException;
use Ngandu\Flexpay\Request\CardRequest;
use Ngandu\Flexpay\Request\MobileRequest;
use Ngandu\Flexpay\Response\CardResponse;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Replays validation rejections and an existing-order lookup observed on 2026-10-29.
 *
 * Valid request objects exercise the public client mapping through mocked HTTP. No live credentials
 * or payment-service connection are used. The existing-order fixture replaces its reference and date.
 *
 * @author bernard-ng <bernard@ngandu.dev>
 */
final class ObservedCorporateResponseTest extends TestCase
{
    public function testObservedMobileRejectionPreservesEmptyOrderNumber(): void
    {
        $client = $this->client('observed_mobile_rejection_2026_10_29.json', 'POST', 'https://corporateapi.flexpay.cd/api/rest/v1/paymentService');
        $response = $client->mobile(new MobileRequest(10, 'observed-mobile', Currency::USD, 'https://example.com/callback', '243810000001'));

        self::assertSame(1, $response->code->value);
        self::assertFalse($response->isAccepted());
        self::assertSame('The callbackUrl parameter is required', $response->message);
        self::assertSame('', $response->orderNumber);
    }

    public function testObservedCardRejectionDoesNotRequireRedirectUrl(): void
    {
        $client = $this->client('observed_card_rejection_2026_10_29.json', 'POST', 'https://corporateapi.flexpay.cd/api/rest/v1/paymentService');
        $response = $client->card(new CardRequest(10, 'observed-card', Currency::USD, 'https://example.com/callback', 'https://example.com/approve', 'https://example.com/cancel', 'https://example.com/decline'));

        self::assertSame(1, $response->code->value);
        self::assertFalse($response->isAccepted());
        self::assertSame('The currency parameter is required', $response->message);
        self::assertSame('', $response->orderNumber);
        self::assertNull($response->url);
    }

    public function testObservedUnknownOrderPreservesNullTransaction(): void
    {
        $orderNumber = 'SMOKE-NONEXISTENT-4193e49901d2461e85eecb9e156f7ed1';
        $client = $this->client('observed_unknown_order_2026_10_29.json', 'GET', 'https://apicheck.flexpaie.com/api/rest/v1/check/' . $orderNumber);
        $response = $client->check($orderNumber);

        self::assertSame(1, $response->code->value);
        self::assertFalse($response->isSuccessful());
        self::assertSame('Transaction with code ' . $orderNumber . ' not found', $response->message);
        self::assertNull($response->transaction);
    }

    public function testObservedDeclinedCardLookupPreservesItsStatusAndOptionalFields(): void
    {
        $orderNumber = 'OBSERVED-CARD-ORDER';
        $client = $this->client('observed_card_declined_check_2026_10_29.json', 'GET', 'https://apicheck.flexpaie.com/api/rest/v1/check/' . $orderNumber);
        $response = $client->check($orderNumber);

        self::assertInstanceOf(Status::class, $response->code);
        self::assertSame(0, $response->code->value);
        self::assertSame('Transaction with code ' . $orderNumber . ' was declined by the operator', $response->message);
        self::assertFalse($response->isSuccessful());
        self::assertNotNull($response->transaction);
        self::assertInstanceOf(Status::class, $response->transaction->status);
        self::assertSame(4, $response->transaction->status->value);
        self::assertFalse($response->transaction->isSuccessful());
        self::assertFalse($response->transaction->status->isFailure(), 'One observed decline must not introduce a global status mapping');
        self::assertSame($orderNumber, $response->transaction->reference);
        self::assertSame(1.0, $response->transaction->amount);
        self::assertSame(1.02, $response->transaction->amountCustomer);
        self::assertSame(Currency::USD, $response->transaction->currency);
        self::assertSame('2026-01-02 03:04:05', $response->transaction->createdAt);
        self::assertSame('visa', $response->transaction->channel);
        self::assertNull($response->transaction->orderNumber);
    }

    public function testObservedAmountRejectionRemainsUnsuccessfulForBothMethods(): void
    {
        $requests = [
            new MobileRequest(10, 'observed-amount-mobile', Currency::USD, 'https://example.com/callback', '243810000001'),
            new CardRequest(10, 'observed-amount-card', Currency::USD, 'https://example.com/callback', 'https://example.com/approve', 'https://example.com/cancel', 'https://example.com/decline'),
        ];

        foreach ($requests as $request) {
            $client = $this->client('observed_amount_rejection_2026_10_29.json', 'POST', 'https://corporateapi.flexpay.cd/api/rest/v1/paymentService');
            $response = $request instanceof MobileRequest ? $client->mobile($request) : $client->card($request);

            self::assertSame(1, $response->code->value);
            self::assertFalse($response->isAccepted());
            self::assertSame('The amount value must be a positive number greater than 0', $response->message);
            self::assertSame('', $response->orderNumber);
            if ($response instanceof CardResponse) {
                self::assertNull($response->url);
            }
        }
    }

    public function testObservedHtmlForbiddenRetainsClassificationWithoutBodyLeakage(): void
    {
        $client = $this->client(
            'observed_callback_type_forbidden_2026_10_29.html',
            'POST',
            'https://corporateapi.flexpay.cd/api/rest/v1/paymentService',
            status: 403,
            contentType: 'text/html;charset=utf-8',
        );

        try {
            $client->card(new CardRequest(10, 'observed-forbidden', Currency::USD, 'https://example.com/callback', 'https://example.com/approve', 'https://example.com/cancel', 'https://example.com/decline'));
            self::fail('An HTML rejection must not be parsed as a payment response');
        } catch (ClientException $clientException) {
            self::assertSame(403, $clientException->status);
            self::assertSame('invalid_response', $clientException->type);
            self::assertInstanceOf(RuntimeException::class, $clientException->getPrevious());
            self::assertStringContainsString('Content-Type: text/html;charset=utf-8', $clientException->getMessage());
            self::assertStringNotContainsString('<html', $clientException->getMessage());
            self::assertStringNotContainsString('Apache Tomcat', $clientException->getMessage());
            self::assertStringNotContainsString('refuses to authorize', $clientException->getMessage());
            self::assertStringNotContainsString('test-token', $clientException->getMessage());
        }
    }

    private function client(string $fixture, string $expectedMethod, string $expectedUrl, int $status = 200, string $contentType = 'application/json'): Client
    {
        $body = (string) file_get_contents(__DIR__ . '/fixtures/corporate/' . $fixture);
        $http = new MockHttpClient(static function (string $method, string $url) use ($body, $expectedMethod, $expectedUrl, $status, $contentType): MockResponse {
            self::assertSame($expectedMethod, $method);
            self::assertSame($expectedUrl, $url);

            return new MockResponse($body, [
                'http_code' => $status,
                'response_headers' => ['content-type: ' . $contentType],
            ]);
        });

        return new Client(new Credential(merchant: 'TESTMERCHANT', token: 'test-token'), BaseUrl::production(), $http);
    }
}
