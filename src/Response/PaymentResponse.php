<?php

declare(strict_types=1);

namespace Ngandu\Flexpay\Response;

use Ngandu\Flexpay\Data\Status;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Class PaymentResponse.
 *
 * @author bernard-ng <bernard@ngandu.dev>
 */
final class PaymentResponse extends FlexpayResponse
{
    public function __construct(
        public Status $code,
        public string $message = '',
        public ?string $reference = null,
        #[SerializedName('provider_reference')]
        public ?string $providerReference = null,
        public ?string $orderNumber = null,
        public ?string $url = null
    ) {
    }
}
