<?php

declare(strict_types=1);

namespace Ngandu\Flexpay\Response;

use Ngandu\Flexpay\Data\Status;

/**
 * Class CardResponse.
 *
 * @author bernard-ng <bernard@ngandu.dev>
 */
final class CardResponse extends FlexpayResponse
{
    public function __construct(
        public Status $code,
        public string $message = '',
        public ?string $orderNumber = null,
        public ?string $url = null
    ) {
    }
}
