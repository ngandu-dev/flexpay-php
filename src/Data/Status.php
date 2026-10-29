<?php

declare(strict_types=1);

namespace Ngandu\Flexpay\Data;

/**
 * Preserves one numeric provider status without guessing the meaning of unknown values.
 *
 * Responses interpret code zero as request acceptance or completed collection according to their role.
 * Status values other than zero and one remain available to callers without a terminal-state assumption.
 *
 * @author bernard-ng <bernard@ngandu.dev>
 */
final readonly class Status
{
    public function __construct(
        public int $value
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->value === 0;
    }

    public function isFailure(): bool
    {
        return $this->value === 1;
    }
}
