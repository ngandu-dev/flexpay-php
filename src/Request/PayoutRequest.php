<?php

declare(strict_types=1);

namespace Ngandu\Flexpay\Request;

use Ngandu\Flexpay\Data\Currency;
use Webmozart\Assert\Assert;

/**
 * Validates and encodes immutable mobile-money payout inputs.
 *
 * Client selects the operation. HttpTransport adds the merchant and bearer authorization without
 * modifying this request, so the same value can be used with separate clients safely.
 *
 * @author bernard-ng <bernard@ngandu.dev>
 */
final readonly class PayoutRequest
{
    public function __construct(
        public float $amount,
        public string $reference,
        public Currency $currency,
        public string $callbackUrl,
        public string $phone,
    ) {
        Assert::true(is_finite($amount) && $amount > 0, 'The amount must be finite and greater than zero');
        Assert::notEq(trim($reference), '', 'The transaction reference must not be blank');
        Assert::notEq(trim($callbackUrl), '', 'The callback URL must not be blank');
        Assert::regex($phone, '/^[0-9]{12}$/D', 'The phone must contain 12 digits, for example 243810000001');
    }

    /**
     * @return array<string, float|string|int>
     */
    public function toPayload(): array
    {
        return [
            'type' => 1,
            'amount' => $this->amount,
            'reference' => $this->reference,
            'currency' => $this->currency->value,
            'callbackUrl' => $this->callbackUrl,
            'phone' => $this->phone,
        ];
    }
}
