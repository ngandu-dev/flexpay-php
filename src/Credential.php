<?php

declare(strict_types=1);

namespace Ngandu\Flexpay;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * Keeps the merchant identity and bearer token used by HttpTransport.
 *
 * Requests contain no credentials. This immutable pair binds their merchant payload to the
 * authorization header at the HTTP boundary.
 *
 * @author bernard-ng <bernard@ngandu.dev>
 */
final readonly class Credential
{
    public function __construct(
        #[SensitiveParameter]
        public string $merchant,
        #[SensitiveParameter]
        public string $token,
    ) {
        if (trim($merchant) === '' || trim($token) === '') {
            throw new InvalidArgumentException('Merchant and authorization token must not be blank');
        }
    }
}
