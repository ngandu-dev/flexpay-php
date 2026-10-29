# Upgrading to version 4.0.0

This guide covers upgrading from version 3 to version 4.0.0 (2026-10-29). PHP 8.4 or later remains
required. See the [README](README.md) for current API usage and
[design notes](docs/design.md) for object responsibilities.

The corporate API changes collection endpoints and request fields. The SDK also makes endpoint
selection, request acceptance, and transaction outcome explicit. Removed APIs have no compatibility
aliases.

## Client configuration and credentials

Version 3 accepts an environment and optional URL overrides:

```php
use Ngandu\Flexpay\Client;
use Ngandu\Flexpay\Credential;
use Ngandu\Flexpay\Environment;

$flexpay = new Client(
    credential: new Credential('authorization_token', 'merchant_code'),
    environment: Environment::LIVE,
    apiBaseUrl: 'https://backend.flexpay.cd/api/rest/v1',
    cardBaseUrl: 'https://cardpayment.flexpay.cd/v1.1',
);
```

Version 4 requires a `BaseUrl` value:

```php
use Ngandu\Flexpay\BaseUrl;
use Ngandu\Flexpay\Client;
use Ngandu\Flexpay\Credential;

$flexpay = new Client(
    credential: new Credential(merchant: 'merchant_code', token: 'authorization_token'),
    baseUrl: BaseUrl::production(),
);
```

The constructor is `Client(Credential $credential, BaseUrl $baseUrl, ?HttpClientInterface $http = null)`.
The optional HTTP client remains the third argument, or the named `http` argument.

`Credential` positional arguments change from **token, merchant** to **merchant, token**. Existing
calls using named `token:` and `merchant:` arguments remain valid. Prefer named arguments when
updating positional calls. Blank-only credential values now throw `InvalidArgumentException`.

`Environment`, `apiBaseUrl`, and `cardBaseUrl` are removed. `Client::$credential` and
`Client::$environment` are no longer public properties. Keep configuration your application needs
in its own composition code.

`BaseUrl` has exactly three public readonly properties: `payment`, `check`, and `payout`. Its production
preset selects corporate collection, the separate check API, and the existing payout API:

| Property | Production prefix | Appended path |
| --- | --- | --- |
| `payment` | `https://corporateapi.flexpay.cd/api/rest/v1` | `/paymentService` |
| `check` | `https://apicheck.flexpaie.com/api/rest/v1` | `/check/{orderNumber}` |
| `payout` | `https://backend.flexpay.cd/api/rest/v1` | `/merchantPayOutService` |

For a simulator or custom deployment, supply all three prefixes:

```php
$flexpay = new Client(
    credential: new Credential(merchant: 'TESTMERCHANT', token: 'test-token'),
    baseUrl: new BaseUrl(
        payment: 'http://localhost:8080/api/rest/v1',
        check: 'http://localhost:8080/api/rest/v1',
        payout: 'http://localhost:8080/api/rest/v1',
    ),
);
```

A corporate sandbox address has not been supplied. There is no sandbox mode or automatic network
fallback. Prefixes must be absolute HTTP(S) URLs without credentials, query strings, fragments,
backslashes, or interior whitespace. Surrounding whitespace and trailing slashes are removed.

## Mobile and card collections

Version 3 dispatches through `pay()` and accepts legacy request fields:

```php
use Ngandu\Flexpay\Data\Currency;
use Ngandu\Flexpay\Data\Type;
use Ngandu\Flexpay\Request\CardRequest;
use Ngandu\Flexpay\Request\MobileRequest;

$mobile = $flexpay->pay(new MobileRequest(
    amount: 10,
    reference: 'invoice-123',
    currency: Currency::USD,
    callbackUrl: 'https://merchant.example/callback',
    phone: '243999999999',
    type: Type::MOBILE,
    description: 'Invoice 123',
));

$card = $flexpay->pay(new CardRequest(
    amount: 10,
    reference: 'invoice-124',
    currency: Currency::USD,
    description: 'Invoice 124',
    callbackUrl: 'https://merchant.example/callback',
    approveUrl: 'https://merchant.example/approved',
    cancelUrl: 'https://merchant.example/cancelled',
    declineUrl: 'https://merchant.example/declined',
    homeUrl: 'https://merchant.example',
));
```

Version 4 uses explicit operations and the corporate request fields:

```php
use Ngandu\Flexpay\Data\Currency;
use Ngandu\Flexpay\Request\CardRequest;
use Ngandu\Flexpay\Request\MobileRequest;

$mobile = $flexpay->mobile(new MobileRequest(
    amount: 10,
    reference: 'invoice-123',
    currency: Currency::USD,
    callbackUrl: 'https://merchant.example/callback',
    phone: '243999999999',
));

$card = $flexpay->card(new CardRequest(
    amount: 10,
    reference: 'invoice-124',
    currency: Currency::USD,
    callbackUrl: 'https://merchant.example/callback',
    approveUrl: 'https://merchant.example/approved',
    cancelUrl: 'https://merchant.example/cancelled',
    declineUrl: 'https://merchant.example/declined',
));
```

Remove mobile `type`, `description`, `approveUrl`, `cancelUrl`, and `declineUrl` arguments. Remove card
`description` and `homeUrl`. Each request fixes its own collection type. Card references no longer
have the legacy 25-character limit. `pay()` is removed.

Both operations now POST to `/paymentService`. Mobile sends string `type: "1"`; card sends string
`type: "2"`. URL properties remain camelCase in PHP and encode as `callback_url`, `approve_url`,
`cancel_url`, and `decline_url` on the wire. The bearer token is sent only in the authorization header.
The legacy card `/pay` endpoint and body `authorization` field are no longer used.

## Payouts

Remove payout's optional `type` argument. Version 3:

```php
use Ngandu\Flexpay\Data\Currency;
use Ngandu\Flexpay\Data\Type;
use Ngandu\Flexpay\Request\PayoutRequest;

$payout = $flexpay->payout(new PayoutRequest(
    amount: 10,
    reference: 'payout-123',
    currency: Currency::USD,
    callbackUrl: 'https://merchant.example/payout-callback',
    phone: '243999999999',
    type: Type::MOBILE,
));

$payout->isSuccessful();
```

Version 4:

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
The request, including its phone property, is now readonly. Payout POSTs are sent once.

## Immutable requests and results

Requests no longer extend `Request` or contain `merchant` and `authorization` properties.
`setCredential()` is removed. The HTTP transport adds the merchant to JSON and the bearer token to
the header without mutating the request. `getPayload()` becomes `toPayload()`, which returns operation
inputs only. Update code that expected a complete authenticated payload from a request object.

All request and response values are final readonly classes. Create a new value instead of changing
properties after construction. Replace shared `Request` and `FlexpayResponse` type hints with the
specific operation types. Both base classes and the `Data\Type` and `Data\Method` enums are removed.

Validation now rejects blank-only references and URL fields, nonfinite or nonpositive amounts, and
phone numbers that do not contain exactly 12 digits. URL fields on requests are checked for nonblank
content; they are not checked for reachability. `check()` also rejects blank order numbers and the
exact dot path segments `.` and `..`.

## Acceptance and payment outcome

Mobile, card, and payout results replace `isSuccessful()` with `isAccepted()`. This method recognizes
provider code zero. It does not prove completed payment or guarantee an order number or checkout URL.
Check the optional fields before using them:

```php
if ($card->isAccepted() && $card->url !== null && $card->url !== '') {
    // Continue checkout under your application's redirect policy.
}

if ($mobile->orderNumber === null || trim($mobile->orderNumber) === '') {
    throw new RuntimeException('FlexPay did not return an order number');
}

$check = $flexpay->check($mobile->orderNumber);
$successful = $check->isSuccessful();
$lookupCode = $check->code->value;
$transactionCode = $check->transaction?->status->value;
```

`CheckResponse::isSuccessful()` previously tested the lookup code alone. It now requires both lookup
code zero and transaction status zero. A missing transaction returns false. A false result alone does
not identify a terminal failure. Use the provider's order number for the lookup, not your merchant
reference. The client encodes it as one path segment.

`Transaction` requires an explicit `Currency` when constructed or decoded; the CDF default is removed.
Missing required transaction fields are invalid responses. The nested order number remains optional.

| Version 3 result | Version 4 result |
| --- | --- |
| `PaymentResponse` from `mobile()` | `MobileResponse` containing `code`, `message`, and `orderNumber` |
| `PaymentResponse` from `handleCallback()` | `CallbackResponse` with callback reporting fields |
| `CardResponse` | Same basic fields; readonly and exposes `isAccepted()` |
| `PayoutResponse` | Same basic fields; readonly and exposes `isAccepted()` |
| `CheckResponse` | Readonly and reports the nested transaction outcome |

Mobile results no longer expose `reference`, `providerReference`, or `url`. Callback reporting belongs
to `CallbackResponse`; checkout `url` belongs to `CardResponse`. When directly constructing the new
`MobileResponse`, its third argument is `orderNumber`, unlike the old `PaymentResponse`'s `reference`.
Use named arguments to avoid carrying that positional assumption forward.

## Status values and JSON serialization

Every response `code` and transaction `status` is a `Status` object. The class replaces the backed enum
and preserves any integer, including unknown provider codes. There is no `Status|int` union.

Version 3:

```php
use Ngandu\Flexpay\Data\Status;

$status = Status::SUCCESS;
$knownSuccess = $response->code === Status::SUCCESS;
```

Version 4:

```php
use Ngandu\Flexpay\Data\Status;

$status = new Status(0);
$knownSuccess = $response->code->isSuccessful();
$numericCode = $response->code->value;
```

Use the result's `isAccepted()` or `isSuccessful()` method for operation outcomes. On `Status` itself,
`isSuccessful()` recognizes zero and `isFailure()` recognizes one. Other values satisfy neither
predicate. Preserve unknown codes without assigning them a pending or terminal meaning.

Remove enum `SUCCESS`/`FAILURE` constants, `from()`, `tryFrom()`, `cases()`, `name` access, enum type
checks, and identity comparisons. Use the constructor, predicates, or numeric `value` as appropriate.

JSON serialization changes too:

```php
// Version 3: json_encode(Status::SUCCESS) produces 0.
// Version 4:
json_encode(new Status(0), JSON_THROW_ON_ERROR); // {"value":0}

// Preserve a numeric application contract explicitly when needed:
json_encode(['code' => $response->code->value], JSON_THROW_ON_ERROR); // {"code":0} for code zero
```

Serializing a whole response or transaction object therefore changes its nested `code` or `status`
shape. Update persistence and application response mappings that require numeric codes.

## Callback parsing

Replace client-based callback handling:

```php
// Version 3
$callback = $flexpay->handleCallback($payload);
```

With credential-independent parsing:

```php
use Ngandu\Flexpay\Response\CallbackResponse;

$callback = CallbackResponse::fromPayload($payload);
$successful = $callback->isSuccessful();
$providerReference = $callback->providerReference;
```

The callback result preserves `provider_reference` as `providerReference`, plus optional reference,
order number, amounts, phone, currency, creation date, and channel. It does not expose a checkout URL.
Invalid callback data throws `InvalidArgumentException`. Parsing does not authenticate the sender or
authorize fulfillment; those decisions remain with the receiving application.

## HTTP behavior and errors

Payment and payout POSTs now have one attempt. Only GET checks retry transport failures and HTTP 5xx,
with at most three retries after the first attempt. HTTP 4xx and malformed HTTP 2xx responses are not
retried. Backoff is 500, 1000, then 2000 milliseconds. An integer-seconds `Retry-After` can extend the
delay up to two seconds. Longer delays, HTTP dates, and malformed values suppress retries, including
when the response body subsequently disconnects.

Redirects are canceled and rejected. Each HTTP attempt has a 30-second timeout and maximum duration.
An injected HTTP client must not add retries or redirect behavior of its own. Reconcile an ambiguous
POST result before sending another request; a transport or decoding failure does not establish that
the provider rejected the payment.

HTTP exception subclasses and status remain available: `AccountException` for 401/429,
`ClientException` for other 4xx, and `ServerException` for 5xx. Malformed or structurally invalid
responses use type `invalid_response`; redirects use `unexpected_redirect`; transport failures use
`transport_error`. Decode and transport failures retain only a safe cause naming the exception class.
Original HTTP-library exceptions, their response objects, and their messages are no longer available
through `getPrevious()`. Remove assumptions about those original cause types. The configured token
is redacted from provider error diagnostics.

## Removed dependencies

Symfony serializer and property-access packages are no longer runtime dependencies. Their unused
transitive dependencies are removed from the lockfile too. Require any package directly if your
application relied on it through this SDK's dependency tree.

An authorized production lookup has confirmed parsing of one declined card transaction. Its lookup
code was zero and transaction status was four; that single observation does not define a general
status-four mapping. Successful-payment and pending outcomes remain unverified against the corporate
check endpoint. See [provider evidence](README.md#fixtures-and-provider-evidence) for the scope of
verification.
