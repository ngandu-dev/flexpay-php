<?php

declare(strict_types=1);

namespace Ngandu\Flexpay\Tests;

use Ngandu\Flexpay\Client;
use Ngandu\Flexpay\Credential;
use Ngandu\Flexpay\Data\Currency;
use Ngandu\Flexpay\Data\Transaction;
use Ngandu\Flexpay\Data\Type;
use Ngandu\Flexpay\Exception\AccountException;
use Ngandu\Flexpay\Exception\ClientException;
use Ngandu\Flexpay\Exception\NetworkException;
use Ngandu\Flexpay\Exception\ServerException;
use Ngandu\Flexpay\Request\CardRequest;
use Ngandu\Flexpay\Request\MobileRequest;
use Ngandu\Flexpay\Request\PayoutRequest;
use Ngandu\Flexpay\Response\CardResponse;
use Ngandu\Flexpay\Response\CheckResponse;
use Ngandu\Flexpay\Response\PaymentResponse;
use Ngandu\Flexpay\Response\PayoutResponse;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

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
            description: 'test',
            callbackUrl: 'http://localhost:8000/callback',
            approveUrl: 'http://localhost:8000/approve',
            cancelUrl: 'http://localhost:8000/cancel',
            declineUrl: 'http://localhost:8000/decline',
            homeUrl: 'http://localhost:8000/home',
        );
        $response = $flexpay->card($request);

        $this->assertInstanceOf(CardResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
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
            type: Type::MOBILE
        );

        $response = $flexpay->payout($request);

        $this->assertInstanceOf(PayoutResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertEquals('Transaction envoyée avec succès.', $response->message);
        $this->assertEquals('SQeCGunXEGnr243815877848', $response->orderNumber);
    }

    public function testSuccessCheck(): void
    {
        $flexpay = $this->getFlexpay($this->getResponse('check_success.json'));
        $response = $flexpay->check('some_order_number');

        $this->assertInstanceOf(CheckResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
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

        $this->assertInstanceOf(PaymentResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertEquals('DtX9SmCYojWW243123456789', $response->orderNumber);
    }

    public function testHandleCallback(): void
    {
        /** @var array $data */
        $data = json_decode((string) file_get_contents(__DIR__ . '/fixtures/response_success.json'), true);
        $flexpay = $this->getFlexpay($this->getResponse('response_success.json'));

        $response = $flexpay->handleCallback($data);
        $this->assertInstanceOf(PaymentResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertEquals('ZDN000003', $response->reference);
        $this->assertEquals('UBGC8s9L3VBm243815877848', $response->orderNumber);
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
                $this->getFlexpay($response)->check('order-number');
                $this->fail(sprintf('Expected %s to be thrown', $exceptionClass));
            } catch (NetworkException $exception) {
                $this->assertInstanceOf($exceptionClass, $exception);
                $this->assertSame($status, $exception->status);
                $this->assertSame('api_error', $exception->type);
                $this->assertSame(sprintf('Request failed (HTTP %d/api_error)', $status), $exception->getMessage());
                $this->assertInstanceOf(HttpExceptionInterface::class, $exception->getPrevious());
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

    public function testTransportErrorsAreRecastWithoutLosingTheCause(): void
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
            $this->assertSame('Connection failed', $networkException->getMessage());
            $this->assertSame($transportError, $networkException->getPrevious());
        }
    }

    private function getFlexpay(callable|MockResponse $mock): Client
    {
        return new Client(
            credential: new Credential('token', 'ZONDO'),
            http: new MockHttpClient($mock)
        );
    }

    private function getResponse(string $file): MockResponse
    {
        return new MockResponse((string) file_get_contents(__DIR__ . '/fixtures/' . $file));
    }
}
