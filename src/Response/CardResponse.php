<?php

declare(strict_types=1);

namespace Ngandu\Flexpay\Response;

use Ngandu\Flexpay\Data\Status;

/**
 * Reports whether FlexPay accepted a card request for processing.
 *
 * ResponseDecoder constructs this immutable result. Acceptance does not establish completed payment;
 * callers use callbacks or status checks to determine the eventual outcome.
 *
 * @author bernard-ng <bernard@ngandu.dev>
 */
final readonly class CardResponse
{
    public function __construct(
        public Status $code,
        public string $message = '',
        public ?string $orderNumber = null,
        public ?string $url = null,
    ) {
    }

    public function isAccepted(): bool
    {
        return $this->code->isSuccessful();
    }
}
