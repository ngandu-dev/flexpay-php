# FlexPay PHP

[![Latest Stable Version](https://poser.pugx.org/ngandu-dev/flexpay/version)](https://packagist.org/packages/ngandu-dev/flexpay)
[![Quality](https://github.com/ngandu-dev/flexpay-php/actions/workflows/quality.yml/badge.svg)](https://github.com/ngandu-dev/flexpay-php/actions/workflows/quality.yml)
[![Tests](https://github.com/ngandu-dev/flexpay-php/actions/workflows/test.yml/badge.svg)](https://github.com/ngandu-dev/flexpay-php/actions/workflows/test.yml)
[![License](https://poser.pugx.org/ngandu-dev/flexpay/license)](https://packagist.org/packages/ngandu-dev/flexpay)

A PHP client for FlexPay corporate mobile and card collections, transaction checks, callback parsing, and legacy payouts.
Contact FlexPay for merchant credentials and their API documentation.

These examples use the version 4 API. Read the [version 4 upgrade guide](UPGRADE_4.0.0.md)
when upgrading an existing integration.

## Requirements and installation

PHP 8.4 or later and Composer 2 are required. Install version 4 with:

```bash
composer require "ngandu-dev/flexpay:^4.0"
```

## Configuration

A client requires credentials and a `BaseUrl` value with exactly three service prefixes: `payment`,
`check`, and `payout`. Choose the production preset explicitly:

```php
require __DIR__ . '/vendor/autoload.php';

use Ngandu\Flexpay\BaseUrl;
use Ngandu\Flexpay\Client;
use Ngandu\Flexpay\Credential;

$flexpay = new Client(
    credential: new Credential(merchant: 'merchant_code', token: 'authorization_token'),
    baseUrl: BaseUrl::production(),
);
```

`BaseUrl::production()` selects these versioned prefixes:

| Property | Prefix | Operation path |
| --- | --- | --- |
| `payment` | `https://corporateapi.flexpay.cd/api/rest/v1` | `/paymentService` for mobile and card |
| `check` | `https://apicheck.flexpaie.com/api/rest/v1` | `/check/{orderNumber}` |
| `payout` | `https://backend.flexpay.cd/api/rest/v1` | `/merchantPayOutService` |

The corporate documentation does not supply a sandbox URL or a replacement payout endpoint. Supply
all three prefixes when using a local simulator or another deployment:

```php
$localFlexpay = new Client(
    credential: new Credential(merchant: 'TESTMERCHANT', token: 'test-token'),
    baseUrl: new BaseUrl(
        payment: 'http://localhost:8080/api/rest/v1',
        check: 'http://localhost:8080/api/rest/v1',
        payout: 'http://localhost:8080/api/rest/v1',
    ),
);
```

Prefixes must be absolute HTTP(S) URLs without credentials, query strings, fragments, backslashes,
or interior whitespace. Surrounding whitespace and trailing slashes are removed. Invalid prefixes
throw `InvalidArgumentException` before HTTP. There is no environment flag or default network choice.
An optional third constructor argument accepts Symfony's `HttpClientInterface` for HTTP integration
and tests. An injected HTTP client must not add its own retries or redirect behavior. The client sets
bearer authentication, disables redirects, and bounds each HTTP attempt to 30 seconds. The bearer token never enters payment JSON.

## Mobile collection

```php
use Ngandu\Flexpay\Data\Currency;
use Ngandu\Flexpay\Request\MobileRequest;

$mobile = $flexpay->mobile(new MobileRequest(
    amount: 10,
    reference: 'invoice-123',
    currency: Currency::USD,
    callbackUrl: 'https://merchant.example/callback',
    phone: '243999999999',
));

if (!$mobile->isAccepted()) {
    // Record $mobile->message and $mobile->code->value for reconciliation.
}
```

`isAccepted()` reports provider code zero. It does not confirm that the customer paid or guarantee
that an order number was supplied. Preserve the returned `orderNumber` for status checks.

## Card collection

```php
use Ngandu\Flexpay\Data\Currency;
use Ngandu\Flexpay\Request\CardRequest;

$card = $flexpay->card(new CardRequest(
    amount: 10,
    reference: 'invoice-124',
    currency: Currency::USD,
    callbackUrl: 'https://merchant.example/callback',
    approveUrl: 'https://merchant.example/approved',
    cancelUrl: 'https://merchant.example/cancelled',
    declineUrl: 'https://merchant.example/declined',
));

if ($card->isAccepted() && $card->url !== null && $card->url !== '') {
    // Use $card->url to continue checkout under your application's redirect policy.
}
```

Mobile and card requests send the corporate `callback_url` field. Card return URLs use `approve_url`,
`cancel_url`, and `decline_url`. Immutable request objects contain no merchant or token. The transport
adds the merchant during HTTP encoding. Amounts must be finite and positive; references and URL fields
must be nonblank. Mobile and payout phone numbers must contain exactly 12 digits. Request validation
checks URL fields for nonblank content; it does not validate reachability or callback delivery.

## Payout

```php
use Ngandu\Flexpay\Data\Currency;
use Ngandu\Flexpay\Request\PayoutRequest;

$payout = $flexpay->payout(new PayoutRequest(
    amount: 10,
    reference: 'payout-123',
    currency: Currency::USD,
    callbackUrl: 'https://merchant.example/payout-callback',
    phone: '243999999999',
));

$payout->isAccepted();
```

Payout retains the existing endpoint and JSON format, including numeric `type: 1` and `callbackUrl`.
All POST operations are sent once. Only GET transaction checks retry transient failures, at most
three retries after the first attempt, for transport failures and HTTP 5xx. Backoff is 500, 1000,
then 2000 milliseconds. An integer-seconds `Retry-After` can extend that delay up to two seconds.
Longer delays, HTTP dates, and malformed values stop automatic retries. HTTP 4xx and malformed HTTP 2xx responses
are not retried. Reconcile an ambiguous POST result before resubmitting it.

## Transaction checks and callbacks

```php
if ($mobile->orderNumber === null || trim($mobile->orderNumber) === '') {
    throw new RuntimeException('FlexPay did not return an order number');
}

$check = $flexpay->check($mobile->orderNumber);
$successful = $check->isSuccessful();
$lookupCode = $check->code->value;
$transactionCode = $check->transaction?->status->value;
```

Use the provider's order number for checks. The client encodes it as one path segment and rejects
blank values and dot-only path segments. `CheckResponse::isSuccessful()` requires both response code
zero and nested transaction status zero. A missing transaction returns false. Malformed nested
transactions, including a missing currency, throw `NetworkException` with type `invalid_response`.

Callback parsing does not require a client or credentials:

```php
use Ngandu\Flexpay\Response\CallbackResponse;

$payload = json_decode(file_get_contents('php://input'), true, flags: JSON_THROW_ON_ERROR);
if (!is_array($payload)) {
    throw new InvalidArgumentException('Expected a callback object');
}

$callback = CallbackResponse::fromPayload($payload);
$callback->isSuccessful();
$callback->providerReference; // provider_reference, when supplied
```

The callback parser validates the supplied data; it does not authenticate the sender or authorize
fulfillment. Invalid callback data throws `InvalidArgumentException`. Reporting fields include
`reference`, `orderNumber`, `providerReference`, `amount`, `amountCustomer`, `phone`, `currency`,
`createdAt`, and `channel` when present.

Response codes and transaction statuses are always immutable `Status` values. Read their numeric
`value`; `isSuccessful()` recognizes zero and `isFailure()` recognizes one. Other codes satisfy
neither predicate and remain observable without assigning them a pending or terminal meaning.
Callback success requires code zero.

## Development

```bash
composer install
composer format
composer quality
composer validate --strict
composer audit --locked --no-interaction
```

## Contributing and security

See [CONTRIBUTING.md](CONTRIBUTING.md) for the contribution workflow and
[SECURITY.md](SECURITY.md) for private vulnerability reporting. Contributions follow the
[Code of Conduct](CODE_OF_CONDUCT.md). Released under the [MIT License](LICENSE).
