<?php

declare(strict_types=1);

namespace Ngandu\Flexpay\Request;

use Ngandu\Flexpay\Data\Currency;
use Ngandu\Flexpay\Data\Type;
use Override;
use Webmozart\Assert\Assert;

/**
 * Class MobileRequest.
 *
 * @author bernard-ng <bernard@ngandu.dev>
 */
final class MobileRequest extends Request
{
    public function __construct(
        float $amount,
        string $reference,
        Currency $currency,
        string $callbackUrl,
        public readonly string $phone,
        public readonly Type $type = Type::MOBILE,
        ?string $description = null,
        ?string $approveUrl = null,
        ?string $cancelUrl = null,
        ?string $declineUrl = null
    ) {
        Assert::length($this->phone, 12, 'The phone number should be 12 characters long, eg: 243123456789');

        parent::__construct($amount, $reference, $currency, $callbackUrl, $approveUrl, $description, $cancelUrl, $declineUrl);
    }

    /**
     * @return array<string, float|string|int|null>
     */
    #[Override]
    public function getPayload(): array
    {
        return [
            'phone' => $this->phone,
            'type' => $this->type->value,
            'amount' => $this->amount,
            'merchant' => $this->merchant,
            'reference' => $this->reference,
            'currency' => $this->currency->value,
            'callbackUrl' => $this->callbackUrl,
            'description' => $this->description,
            'approveUrl' => $this->approveUrl,
            'cancelUrl' => $this->cancelUrl,
            'declineUrl' => $this->declineUrl,
        ];
    }
}
