<?php

declare(strict_types=1);

namespace Ngandu\Flexpay\Exception;

use Exception;
use Throwable;

/**
 * Class NetworkException.
 *
 * @author bernard-ng <bernard@ngandu.dev>
 */
class NetworkException extends Exception
{
    public function __construct(
        string $message,
        public readonly ?string $type = null,
        public readonly ?int $status = null,
        ?Throwable $previous = null,
    ) {
        $message = $message === '' || $message === '0' ? 'No message was provided' : $message;

        if ($this->status !== null) {
            $context = $this->type === null ? (string) $this->status : sprintf('%d/%s', $this->status, $this->type);
            parent::__construct(sprintf('%s (HTTP %s)', $message, $context), previous: $previous);
        } else {
            parent::__construct($message, previous: $previous);
        }
    }

    public static function create(string $message, ?string $type, int $status, ?Throwable $previous = null): self
    {
        return match (true) {
            $status === 401 || $status === 429 => new AccountException($message, $type, $status, $previous),
            $status >= 400 && $status <= 499 => new ClientException($message, $type, $status, $previous),
            $status >= 500 && $status <= 599 => new ServerException($message, $type, $status, $previous),
            default => new self($message, $type, $status, $previous)
        };
    }
}
