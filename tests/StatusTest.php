<?php

declare(strict_types=1);

namespace Ngandu\Flexpay\Tests;

use Ngandu\Flexpay\Data\Status;
use Ngandu\Flexpay\Response\CallbackResponse;
use PHPUnit\Framework\TestCase;

final class StatusTest extends TestCase
{
    public function testKnownAndUnknownValuesRetainTheirMeaning(): void
    {
        foreach ([0, 1, 2, 99, -1] as $value) {
            $status = new Status($value);

            self::assertSame($value, $status->value);
            self::assertSame($value === 0, $status->isSuccessful());
            self::assertSame($value === 1, $status->isFailure());
        }
    }

    public function testCallbackParsingNeedsNoClientAndAlwaysReturnsStatus(): void
    {
        foreach (['0', '1', '2', '99', '-1'] as $value) {
            $callback = CallbackResponse::fromPayload([
                'code' => $value,
                'provider_reference' => 'provider-123',
            ]);

            self::assertInstanceOf(Status::class, $callback->code);
            self::assertSame((int) $value, $callback->code->value);
            self::assertSame($value === '0', $callback->isSuccessful());
            self::assertSame('provider-123', $callback->providerReference);
        }
    }
}
