<?php

declare(strict_types=1);

namespace Ngandu\Flexpay\Response;

use Ngandu\Flexpay\Data\Currency;
use Ngandu\Flexpay\Data\Status;

/**
 * Reports a collection outcome and optional provider reporting fields from a callback.
 *
 * ResponseDecoder preserves the raw status and provider reference. This value interprets the payload;
 * it does not authenticate its sender or authorize fulfillment.
 *
 * @author bernard-ng <bernard@ngandu.dev>
 */
final readonly class CallbackResponse
{
    public function __construct(
        public Status $code,
        public string $message = '',
        public ?string $reference = null,
        public ?string $providerReference = null,
        public ?string $orderNumber = null,
        public ?float $amount = null,
        public ?float $amountCustomer = null,
        public ?string $phone = null,
        public ?Currency $currency = null,
        public ?string $createdAt = null,
        public ?string $channel = null,
    ) {
    }

    public static function fromPayload(array $data): self
    {
        return new ResponseDecoder()->callback($data);
    }

    public function isSuccessful(): bool
    {
        return $this->code->isSuccessful();
    }
}
