<?php

declare(strict_types=1);

namespace Ngandu\Flexpay\Response;

use Ngandu\Flexpay\Data\Status;

/**
 * Class FlexpayResponse.
 *
 * @author bernard-ng <bernard@ngandu.dev>
 */
abstract class FlexpayResponse
{
    public Status $code;

    public string $message;

    public function isSuccessful(): bool
    {
        return $this->code === Status::SUCCESS;
    }
}
