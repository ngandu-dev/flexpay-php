<?php

declare(strict_types=1);

namespace Ngandu\Flexpay\Response;

use Ngandu\Flexpay\Data\Status;
use Ngandu\Flexpay\Data\Transaction;

/**
 * Class CheckResponse.
 *
 * @author bernard-ng <bernard@ngandu.dev>
 */
final class CheckResponse extends FlexpayResponse
{
    public function __construct(
        public Status $code,
        public string $message = '',
        public ?Transaction $transaction = null
    ) {
    }
}
