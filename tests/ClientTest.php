<?php

declare(strict_types=1);

namespace Ngandu\Flexpay\Tests;

use Ngandu\Flexpay\BaseUrl;
use Ngandu\Flexpay\Client;
use Ngandu\Flexpay\Credential;
use Ngandu\Flexpay\Data\Currency;
use Ngandu\Flexpay\Data\Transaction;
use Ngandu\Flexpay\Exception\AccountException;
use Ngandu\Flexpay\Exception\ClientException;
use Ngandu\Flexpay\Exception\NetworkException;
use Ngandu\Flexpay\Exception\ServerException;
use Ngandu\Flexpay\Request\CardRequest;
use Ngandu\Flexpay\Request\MobileRequest;
use Ngandu\Flexpay\Request\PayoutRequest;
use Ngandu\Flexpay\Response\CallbackResponse;
use Ngandu\Flexpay\Response\CardResponse;
use Ngandu\Flexpay\Response\CheckResponse;
use Ngandu\Flexpay\Response\MobileResponse;
use Ngandu\Flexpay\Response\PayoutResponse;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Class ClientTest.
 *
 * @author bernard-ng <bernard@ngandu.dev>
 */
final class ClientTest extends TestCase
{
    public function testCard(): void
    {
        $flexpay = $this->getFlexpay($this->getResponse('card_success.json'));
        $request = new CardRequest(
            amount: 1,
            reference: 'ref',
            currency: Currency::USD,
            callbackUrl: 'http://localhost:8000/callback',
            approveUrl: 'http://localhost:8000/approve',
            cancelUrl: 'http://localhost:8000/cancel',
            declineUrl: 'http://localhost:8000/decline',
        );
        $response = $flexpay->card($request);

        $this->assertInstanceOf(CardResponse::class, $response);
        $this->assertTrue($response->isAccepted());
        $this->assertEquals('O42iABI27568020268434827', $response->orderNumber);
        $this->assertEquals('https://gwvisa.flexpay.cd/checkout/bbba6b699af8a70e9cfa010d6d12dba5_670d206b7defb', $response->url);
    }

    /**
     * @throws NetworkException
     */
    public function testPayout(): void
    {
        $flexpay = $this->getFlexpay($this->getResponse('payout_success.json'));

        $request = new PayoutRequest(
            amount: 10,
            reference: 'ref',
            currency: Currency::USD,
            callbackUrl: 'http://localhost:8000/callback',
            phone: '243123456789',
        );

        $response = $flexpay->payout($request);

        $this->assertInstanceOf(PayoutResponse::class, $response);
        $this->assertTrue($response->isAccepted());
        $this->assertEquals('Transaction envoyée avec succès.', $response->message);
        $this->assertEquals('SQeCGunXEGnr243815877848', $response->orderNumber);
    }

    public function testSuccessCheck(): void
    {
        $flexpay = $this->getFlexpay($this->getResponse('check_success.json'));
        $response = $flexpay->check('some_order_number');

        $this->assertInstanceOf(CheckResponse::class, $response);
        $this->assertFalse($response->isSuccessful());
        $this->assertInstanceOf(Transaction::class, $response->transaction);
        $this->assertFalse($response->transaction->isSuccessful());
        $this->assertEquals('test', $response->transaction->reference);
    }

    public function testErrorCheck(): void
    {
        $flexpay = $this->getFlexpay($this->getResponse('check_error.json'));
        $response = $flexpay->check('not_found');

        $this->assertInstanceOf(CheckResponse::class, $response);
        $this->assertFalse($response->isSuccessful());
        $this->assertNull($response->transaction);
    }

    public function testMobile(): void
    {
        $flexpay = $this->getFlexpay($this->getResponse('mobile_success.json'));
        $request = new MobileRequest(
            amount: 10,
            reference: 'ref',
            currency: Currency::USD,
            callbackUrl: 'http://localhost:8000/callback',
            phone: '243123456789',
        );
        $response = $flexpay->mobile($request);

        $this->assertInstanceOf(MobileResponse::class, $response);
        $this->assertTrue($response->isAccepted());
        $this->assertEquals('DtX9SmCYojWW243123456789', $response->orderNumber);
    }

    public function testCallbackParsesWithoutCredentials(): void
    {
        /** @var array $data */
        $data = json_decode((string) file_get_contents(__DIR__ . '/fixtures/response_success.json'), true);

        $response = CallbackResponse::fromPayload($data);
        $this->assertInstanceOf(CallbackResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertEquals('ZDN000003', $response->reference);
        $this->assertEquals('UBGC8s9L3VBm243815877848', $response->orderNumber);
        $this->assertSame('PROVIDER-001', $response->providerReference);
        $this->assertSame(100.0, $response->amount);
        $this->assertSame(102.0, $response->amountCustomer);
        $this->assertSame('243815877848', $response->phone);
        $this->assertSame(Currency::CDF, $response->currency);
        $this->assertSame('mpesa', $response->channel);
    }

    public function testHttpErrorsKeepTheirClassificationAndContext(): void
    {
        $cases = [
            401 => AccountException::class,
            422 => ClientException::class,
            501 => ServerException::class,
        ];

        foreach ($cases as $status => $exceptionClass) {
            $response = new MockResponse(
                json_encode([
                    'message' => 'Request failed',
                    'error' => 'api_error',
                ], JSON_THROW_ON_ERROR),
                [
                    'http_code' => $status,
                    'response_headers' => ['content-type: application/json'],
                ]
            );

            try {
                $this->getFlexpay($response)->mobile(new MobileRequest(10, 'ref', Currency::USD, 'https://example.com/callback', '243810000001'));
                $this->fail(sprintf('Expected %s to be thrown', $exceptionClass));
            } catch (NetworkException $exception) {
                $this->assertInstanceOf($exceptionClass, $exception);
                $this->assertSame($status, $exception->status);
                $this->assertSame('api_error', $exception->type);
                $this->assertSame(sprintf('Request failed (HTTP %d/api_error)', $status), $exception->getMessage());
                $this->assertNull($exception->getPrevious());
            }
        }
    }

    public function testHttpErrorWithoutApiDetailsKeepsItsStatus(): void
    {
        $response = new MockResponse('{}', [
            'http_code' => 404,
            'response_headers' => ['content-type: application/json'],
        ]);

        try {
            $this->getFlexpay($response)->check('missing-order');
            $this->fail('Expected a ClientException to be thrown');
        } catch (ClientException $clientException) {
            $this->assertSame(404, $clientException->status);
            $this->assertNull($clientException->type);
            $this->assertSame('No message was provided (HTTP 404)', $clientException->getMessage());
        }
    }

    public function testInvalidCardResponsesKeepTheirStatusAndCause(): void
    {
        $cases = [
            [200, '<html>Internal Server Error</html>', 'text/html', NetworkException::class],
            [200, '{"code":', 'application/json', NetworkException::class],
            [204, '', null, NetworkException::class],
            [200, 'null', 'application/json', NetworkException::class],
            [401, '<html>Unauthorized</html>', 'text/html', AccountException::class],
            [422, 'Invalid request', 'text/plain', ClientException::class],
            [500, '<html>Internal Server Error</html>', 'text/html; charset=UTF-8', ServerException::class],
        ];

        foreach ($cases as [$status, $body, $contentType, $exceptionClass]) {
            $requests = 0;
            $http = new MockHttpClient(static function (string $method, string $url) use ($status, $body, $contentType, &$requests): MockResponse {
                ++$requests;
                self::assertSame('POST', $method);
                self::assertSame('https://corporateapi.flexpay.cd/api/rest/v1/paymentService', $url);

                return new MockResponse($body, [
                    'http_code' => $status,
                    'response_headers' => $contentType === null ? [] : ['content-type: ' . $contentType],
                ]);
            });
            $client = new Client(new Credential(merchant: 'ZONDO', token: 'secret-token'), BaseUrl::production(), $http);

            try {
                $client->card(new CardRequest(10, 'ref', Currency::USD, 'https://example.com/callback', 'https://example.com/approve', 'https://example.com/cancel', 'https://example.com/decline'));
                $this->fail('Expected an invalid response exception');
            } catch (NetworkException $exception) {
                $this->assertInstanceOf($exceptionClass, $exception);
                $this->assertSame($status, $exception->status);
                $this->assertSame('invalid_response', $exception->type);
                $this->assertSame(sprintf(
                    'Flexpay returned an invalid JSON response (Content-Type: %s) (HTTP %d/invalid_response)',
                    $contentType ?? 'unknown',
                    $status
                ), $exception->getMessage());
                $this->assertInstanceOf(
                    RuntimeException::class,
                    $exception->getPrevious()
                );
                $this->assertStringNotContainsString('secret-token', $exception->getMessage());
            }

            $this->assertSame(1, $requests, 'Invalid payment responses must not trigger another payment request');
        }
    }

    public function testTransportErrorsRetainSafeCauseClassification(): void
    {
        $transportError = new TransportException('Connection failed');
        $flexpay = $this->getFlexpay(static fn () => throw $transportError);

        try {
            $flexpay->mobile(new MobileRequest(
                amount: 10,
                reference: 'ref',
                currency: Currency::USD,
                callbackUrl: 'http://localhost:8000/callback',
                phone: '243123456789',
            ));
            $this->fail('Expected a NetworkException to be thrown');
        } catch (NetworkException $networkException) {
            $this->assertSame('Unable to contact Flexpay', $networkException->getMessage());
            $this->assertSame('transport_error', $networkException->type);
            $this->assertInstanceOf(RuntimeException::class, $networkException->getPrevious());
            $this->assertSame(TransportException::class, $networkException->getPrevious()->getMessage());
        }
    }

    private function getFlexpay(callable|MockResponse $mock): Client
    {
        return new Client(
            credential: new Credential(merchant: 'ZONDO', token: 'token'),
            baseUrl: BaseUrl::production(),
            http: new MockHttpClient($mock)
        );
    }

    private function getResponse(string $file): MockResponse
    {
        return new MockResponse((string) file_get_contents(__DIR__ . '/fixtures/' . $file));
    }
}
