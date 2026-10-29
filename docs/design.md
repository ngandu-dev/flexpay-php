# Object responsibilities

Version 4 makes the operation, network destination, and meaning of a result explicit at the call site.
The design uses concrete collaborators because HTTP is the only boundary that currently needs an
injected implementation. No environment mode or compatibility dispatch chooses an operation for callers.

| Object | Responsibility | Collaborators |
| --- | --- | --- |
| `BaseUrl` | Validate and normalize the three required service prefixes | `Client` |
| `Credential` | Hold the merchant and token as immutable values | `HttpTransport` |
| `MobileRequest`, `CardRequest`, `PayoutRequest` | Validate immutable operation inputs and encode their wire fields | `Client` |
| `Client` | Select the operation path and the matching response decoder | `BaseUrl`, requests, transport, decoder |
| `HttpTransport` | Add authentication, perform bounded HTTP attempts, decode JSON, classify failures | Symfony HTTP client, `Credential`, response decoder callbacks |
| `ResponseDecoder` | Validate provider structures and translate wire fields | `Status`, `Transaction`, response values |
| `Status` | Preserve one numeric provider code and recognize known values | Responses and `Transaction` |
| Response values | Interpret acceptance or a reported collection outcome | `Status`, and `Transaction` for checks |

Requests do not inherit from a shared data container. Shared field names do not give mobile, card,
and payout the same contract. Their small encoders keep the documented differences visible,
including corporate snake_case URL fields and the legacy payout format. Requests contain no
credentials and can be reused by separate clients without mutation.

Initiation results use `isAccepted()` because provider code zero acknowledges the request. The result
does not guarantee a populated order number or checkout URL. Completion checks require a successful
lookup and a successful nested transaction. A `Status` value retains unknown codes without calling
them pending or failed. This keeps one public type without discarding provider information.

## Collection path

1. Application code constructs a validated request and selects `Client::mobile()`.
2. `Client` joins `BaseUrl::$payment` with `/paymentService` and supplies the mobile decoder.
3. `HttpTransport` adds the merchant and bearer token, sends one POST, and reads JSON.
4. `ResponseDecoder` validates the response and constructs `MobileResponse` with a `Status` value.
5. The application tests `isAccepted()` and retains the order number for reconciliation.

## Failure path

Every HTTP attempt has a 30-second timeout and maximum duration. Redirects are disabled to prevent
credential forwarding or payment replay. A POST is never retried automatically. A GET may retry
transport failures and HTTP 5xx, with at most three retries. Delays are 500, 1000, then 2000 milliseconds.
An integer-seconds `Retry-After` can extend that delay up to two seconds; a longer, malformed, or HTTP-date
value stops retrying. HTTP 4xx and malformed successful JSON responses are not retried.

An HTTP or decoding failure becomes a `NetworkException` with the available status and classification.
The transport retains no original exception that could expose the response or request. A safe cause
records only its exception class. An ambiguous POST result remains the application's responsibility
to reconcile; the SDK cannot infer that a request failed before reaching FlexPay.

## Callback boundary

`CallbackResponse::fromPayload()` can be used without constructing a client or supplying credentials.
It shares the response decoder's numeric, currency, and reporting-field validation, then exposes the
callback's reported outcome. It does not authenticate the sender. Authentication, deduplication, and
fulfillment decisions belong to the receiving application.

## Verification

Public-client tests exercise exact wire payloads, independent prefixes, merchant injection without
request mutation, numeric status preservation, and observed provider rejections. Separate tests cover
redirect rejection, bounded HTTP options, retry limits, and safe failure diagnostics. Provider fixtures
record validation evidence only; successful production collections and callback delivery remain unverified.
