<?php

declare(strict_types=1);

namespace Ngandu\Flexpay\Response;

use Ngandu\Flexpay\Data\Status;
use Ngandu\Flexpay\Data\Transaction;

/**
 * Reports a status lookup and its optional transaction outcome.
 *
 * ResponseDecoder builds the result. Successful collection requires an accepted lookup and a
 * successful transaction; a false result alone does not establish a terminal failure.
 *
 * @author bernard-ng <bernard@ngandu.dev>
 */
final readonly class CheckResponse
{
    public function __construct(
        public Status $code,
        public string $message = '',
        public ?Transaction $transaction = null,
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->code->isSuccessful() && $this->transaction?->isSuccessful() === true;
    }
}
