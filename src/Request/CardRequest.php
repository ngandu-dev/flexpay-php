<?php

declare(strict_types=1);

namespace Ngandu\Flexpay\Request;

use Ngandu\Flexpay\Data\Currency;
use Override;
use Webmozart\Assert\Assert;

/**
 * Class CardRequest.
 *
 * @author bernard-ng <bernard@ngandu.dev>
 */
final class CardRequest extends Request
{
    public function __construct(
        float $amount,
        string $reference,
        Currency $currency,
        string $description,
        string $callbackUrl,
        string $approveUrl,
        string $cancelUrl,
        string $declineUrl,
        public string $homeUrl,
    ) {
        Assert::notEmpty($description, 'The description must be provided');
        Assert::notEmpty($approveUrl, 'The approve url must be provided');
        Assert::notEmpty($cancelUrl, 'The cancel url must be provided');
        Assert::notEmpty($declineUrl, 'The decline url must be provided');
        Assert::notEmpty($homeUrl, 'The home url must be provided');
        Assert::lengthBetween($reference, 1, 25, 'The reference must be between 1 and 25 characters');

        parent::__construct($amount, $reference, $currency, $callbackUrl, $approveUrl, $description, $cancelUrl, $declineUrl);
    }

    /**
     * Yeah, I know this is weird
     * But I'm not responsible for the API design.
     * so don't blame :D
     * @return array<string, float|string|null>
     */
    #[Override]
    public function getPayload(): array
    {
        return [
            'amount' => $this->amount,
            'merchant' => $this->merchant,
            'authorization' => sprintf('Bearer %s', $this->authorization),
            'reference' => $this->reference,
            'currency' => $this->currency->value,
            'callback_url' => $this->callbackUrl,
            'description' => $this->description,
            'approve_url' => $this->approveUrl,
            'cancel_url' => $this->cancelUrl,
            'decline_url' => $this->declineUrl,
            'home_url' => $this->homeUrl,
        ];
    }
}
