<?php

declare(strict_types=1);

namespace Ngandu\Flexpay\Tests;

use Ngandu\Flexpay\Client;
use Ngandu\Flexpay\Credential;
use Ngandu\Flexpay\Data\Currency;
use Ngandu\Flexpay\Data\Transaction;
use Ngandu\Flexpay\Data\Type;
use Ngandu\Flexpay\Exception\NetworkException;
use Ngandu\Flexpay\Request\CardRequest;
use Ngandu\Flexpay\Request\MobileRequest;
use Ngandu\Flexpay\Request\PayoutRequest;
use Ngandu\Flexpay\Response\CardResponse;
use Ngandu\Flexpay\Response\CheckResponse;
use Ngandu\Flexpay\Response\PaymentResponse;
use Ngandu\Flexpay\Response\PayoutResponse;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
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

    private function getFlexpay(callable|MockResponse $mock): Client
    {
        $flexpay = new Client(new Credential('token', 'ZONDO'));
        $this->setValue($flexpay, 'http', new MockHttpClient($mock));

        /** @var Client $flexpay */
        return $flexpay;
    }

    private function getResponse(string $file): MockResponse
    {
        return new MockResponse((string) file_get_contents(__DIR__ . '/fixtures/' . $file));
    }

    private function setValue(object &$object, string $propertyName, mixed $value): void
    {
        $reflectionClass = new ReflectionClass($object);
        $property = $reflectionClass->getProperty($propertyName);

        if ($property->isReadOnly()) {
            $mutable = $reflectionClass->newInstanceWithoutConstructor();

            foreach ($reflectionClass->getProperties() as $classProperty) {
                if ($classProperty->name === $propertyName) {
                    continue;
                }

                if ($classProperty->isInitialized($object)) {
                    $classProperty->setValue($mutable, $classProperty->getValue($object));
                }
            }

            $object = $mutable;
            $property = $reflectionClass->getProperty($propertyName);
        }

        $property->setValue($object, $value);
    }
}
