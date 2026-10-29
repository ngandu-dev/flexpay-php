<?php

declare(strict_types=1);

namespace Ngandu\Flexpay\Request;

use Ngandu\Flexpay\Data\Currency;
use Webmozart\Assert\Assert;

/**
 * Validates and encodes immutable card collection inputs.
 *
 * Client selects the operation. HttpTransport adds the merchant and bearer authorization without
 * modifying this request, so the same value can be used with separate clients safely.
 *
 * @author bernard-ng <bernard@ngandu.dev>
 */
final readonly class CardRequest
{
    public function __construct(
        public float $amount,
        public string $reference,
        public Currency $currency,
        public string $callbackUrl,
        public string $approveUrl,
        public string $cancelUrl,
        public string $declineUrl,
    ) {
        Assert::true(is_finite($amount) && $amount > 0, 'The amount must be finite and greater than zero');
        Assert::notEq(trim($reference), '', 'The transaction reference must not be blank');
        Assert::notEq(trim($callbackUrl), '', 'The callback URL must not be blank');
        Assert::notEq(trim($approveUrl), '', 'The approve URL must not be blank');
        Assert::notEq(trim($cancelUrl), '', 'The cancel URL must not be blank');
        Assert::notEq(trim($declineUrl), '', 'The decline URL must not be blank');
    }

    /**
     * @return array<string, float|string|int>
     */
    public function toPayload(): array
    {
        return [
            'type' => '2',
            'amount' => $this->amount,
            'reference' => $this->reference,
            'currency' => $this->currency->value,
            'callback_url' => $this->callbackUrl,
            'approve_url' => $this->approveUrl,
            'cancel_url' => $this->cancelUrl,
            'decline_url' => $this->declineUrl,
        ];
    }
}
