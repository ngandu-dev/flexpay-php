<?php

declare(strict_types=1);

namespace Ngandu\Flexpay\Response;

use InvalidArgumentException;
use Ngandu\Flexpay\Data\Currency;
use Ngandu\Flexpay\Data\Status;
use Ngandu\Flexpay\Data\Transaction;

/**
 * Validates provider data and constructs response values for Client.
 *
 * Status always wraps the original numeric value. Unknown values remain observable without an invented
 * pending-state mapping. Each result owns its acceptance or completed-collection interpretation.
 *
 * @internal
 * @author bernard-ng <bernard@ngandu.dev>
 */
final readonly class ResponseDecoder
{
    /**
     * @param array<string, mixed> $data
     */
    public function mobile(array $data): MobileResponse
    {
        return new MobileResponse($this->status($data['code'] ?? null), $this->string($data, 'message') ?? '', $this->string($data, 'orderNumber'));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function card(array $data): CardResponse
    {
        return new CardResponse($this->status($data['code'] ?? null), $this->string($data, 'message') ?? '', $this->string($data, 'orderNumber'), $this->string($data, 'url'));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function payout(array $data): PayoutResponse
    {
        return new PayoutResponse($this->status($data['code'] ?? null), $this->string($data, 'message') ?? '', $this->string($data, 'orderNumber'));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function check(array $data): CheckResponse
    {
        return new CheckResponse($this->status($data['code'] ?? null), $this->string($data, 'message') ?? '', $this->transaction($data['transaction'] ?? null));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function callback(array $data): CallbackResponse
    {
        return new CallbackResponse(
            code: $this->status($data['code'] ?? null),
            message: $this->string($data, 'message') ?? '',
            reference: $this->string($data, 'reference'),
            providerReference: $this->string($data, 'provider_reference'),
            orderNumber: $this->string($data, 'orderNumber'),
            amount: $this->optionalAmount($data, 'amount'),
            amountCustomer: $this->optionalAmount($data, 'amountCustomer'),
            phone: $this->string($data, 'phone'),
            currency: $this->currency($data['currency'] ?? null),
            createdAt: $this->string($data, 'createdAt'),
            channel: $this->string($data, 'channel'),
        );
    }

    private function transaction(mixed $data): ?Transaction
    {
        if ($data === null) {
            return null;
        }

        if (! is_array($data)) {
            throw new InvalidArgumentException('Invalid transaction');
        }

        return new Transaction(
            reference: $this->string($data, 'reference') ?? throw new InvalidArgumentException('Missing reference'),
            amount: $this->amount($data['amount'] ?? null),
            amountCustomer: $this->amount($data['amountCustomer'] ?? null),
            createdAt: $this->string($data, 'createdAt') ?? throw new InvalidArgumentException('Missing createdAt'),
            status: $this->status($data['status'] ?? null),
            currency: $this->currency($data['currency'] ?? null) ?? throw new InvalidArgumentException('Missing currency'),
            orderNumber: $this->string($data, 'orderNumber'),
            channel: $this->string($data, 'channel'),
        );
    }

    private function status(mixed $value): Status
    {
        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            $value = filter_var($value, FILTER_VALIDATE_INT);
        }

        if (! is_int($value)) {
            throw new InvalidArgumentException('Invalid numeric status code');
        }

        return new Status($value);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if ($value !== null && ! is_string($value)) {
            throw new InvalidArgumentException('Invalid ' . $key);
        }

        return $value;
    }

    private function currency(mixed $value): ?Currency
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || Currency::tryFrom($value) === null) {
            throw new InvalidArgumentException('Invalid currency');
        }

        return Currency::from($value);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function optionalAmount(array $data, string $key): ?float
    {
        return isset($data[$key]) ? $this->amount($data[$key]) : null;
    }

    private function amount(mixed $value): float
    {
        if ((! is_int($value) && ! is_float($value) && ! is_string($value)) || ! is_numeric($value) || ! is_finite((float) $value)) {
            throw new InvalidArgumentException('Invalid amount');
        }

        return (float) $value;
    }
}
