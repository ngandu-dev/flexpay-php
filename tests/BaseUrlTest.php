<?php

declare(strict_types=1);

namespace Ngandu\Flexpay\Tests;

use InvalidArgumentException;
use Ngandu\Flexpay\BaseUrl;
use PHPUnit\Framework\TestCase;

final class BaseUrlTest extends TestCase
{
    public function testProductionDefinesAllThreePrefixes(): void
    {
        self::assertSame([
            'payment' => 'https://corporateapi.flexpay.cd/api/rest/v1',
            'check' => 'https://apicheck.flexpaie.com/api/rest/v1',
            'payout' => 'https://backend.flexpay.cd/api/rest/v1',
        ], get_object_vars(BaseUrl::production()));
    }

    public function testNormalizesEveryExplicitPrefix(): void
    {
        $urls = new BaseUrl(' http://localhost:8080/pay/// ', 'http://[::1]:8081/check/', 'https://payout.example/api/rest/v1/');

        self::assertSame('http://localhost:8080/pay', $urls->payment);
        self::assertSame('http://[::1]:8081/check', $urls->check);
        self::assertSame('https://payout.example/api/rest/v1', $urls->payout);
    }

    public function testInvalidPrefixIsRejectedRegardlessOfOperation(): void
    {
        $invalid = ['', ' ', '/relative', 'localhost:8080', 'http:localhost', 'ftp://localhost/api',
            'http://localhost:invalid', 'http://user:password@localhost/api', 'http://localhost/api?token=1',
            'http://localhost/a b', 'http://local host/api', "http://localhost/a\nb", 'http://localhost/api#fragment', 'http://localhost/api?', 'http://localhost/api#', 'http://localhost/\\path'];

        foreach ($invalid as $value) {
            foreach (['payment', 'check', 'payout'] as $field) {
                $values = [
                    'payment' => 'http://localhost/pay',
                    'check' => 'http://localhost/check',
                    'payout' => 'http://localhost/payout',
                ];
                $values[$field] = $value;

                try {
                    new BaseUrl(...$values);
                    self::fail('Invalid ' . $field . ' prefix should fail before a client can be constructed');
                } catch (InvalidArgumentException $exception) {
                    self::assertStringContainsString('Base URLs', $exception->getMessage());
                }
            }
        }
    }
}
