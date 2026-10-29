<?php

declare(strict_types=1);

namespace Ngandu\Flexpay;

use InvalidArgumentException;

/**
 * Defines the three explicit service prefixes used by Client.
 *
 * Each prefix is validated and normalized once. There is no environment flag or implicit fallback;
 * callers choose production() or supply all three addresses for their own deployment.
 *
 * @author bernard-ng <bernard@ngandu.dev>
 */
final readonly class BaseUrl
{
    public string $payment;

    public string $check;

    public string $payout;

    public function __construct(string $payment, string $check, string $payout)
    {
        $this->payment = $this->normalize($payment);
        $this->check = $this->normalize($check);
        $this->payout = $this->normalize($payout);
    }

    public static function production(): self
    {
        return new self(
            payment: 'https://corporateapi.flexpay.cd/api/rest/v1',
            check: 'https://apicheck.flexpaie.com/api/rest/v1',
            payout: 'https://backend.flexpay.cd/api/rest/v1',
        );
    }

    private function normalize(string $value): string
    {
        $url = rtrim(trim($value), '/');
        $parts = parse_url($url);

        if (
            filter_var($url, FILTER_VALIDATE_URL) === false
            || $parts === false
            || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)
            || str_contains($url, '\\')
        ) {
            throw new InvalidArgumentException('Base URLs must be absolute HTTP(S) URLs without credentials, query, or fragment');
        }

        return $url;
    }
}
