# Changelog

All notable changes to this project are documented in this file. The project follows [Semantic Versioning](https://semver.org/).

## 4.0.0 - 2026-10-29

### Changed

- Use the corporate pay-in API. Mobile and card collections share `paymentService`, use bearer-header authentication, and send the documented snake_case URL fields. Production checks use `apicheck.flexpaie.com`; payout retains the existing endpoint and wire format.
- Require `Client(Credential, BaseUrl, ?HttpClientInterface)` with an immutable `BaseUrl` containing exactly `payment`, `check`, and `payout`. Supply every prefix or choose `BaseUrl::production()` explicitly. Remove `Environment` and loose URL constructor options.
- Order `Credential` arguments as merchant, token and reject blank-only values. Remove public client credential and environment properties.
- Replace request inheritance and credential mutation with standalone readonly request values. Remove `pay()`, `Request`, `Data\Type`, `Data\Method`, `setCredential()`, and `getPayload()`; use explicit operations and credential-free `toPayload()`.
- Remove card `description`, `homeUrl`, and the legacy reference-length cap. Remove mobile type, description, and browser-return arguments and payout's optional type. Validate finite positive amounts, nonblank fields, and 12-digit phone numbers.
- Replace `PaymentResponse` with `MobileResponse` and a separate `CallbackResponse`. Remove `FlexpayResponse` inheritance and make results readonly. Initiation results expose `isAccepted()`; checks and callbacks expose `isSuccessful()`.
- Replace the `Status` enum with an immutable numeric value object. Codes and transaction statuses always use `Status`, including unknown values. Check success requires lookup code zero and transaction status zero; transaction currency is required.
- JSON serialization of `Status` now produces an object containing `value`. Consumers that persist or forward numeric codes must map `->value` explicitly.
- Move callback parsing to credential-independent `CallbackResponse::fromPayload()` and remove `Client::handleCallback()`.
- Send POST requests once and retry only GET status lookups. Reject redirects and bound each HTTP attempt to 30 seconds. Bound retry delays to two seconds and stop retrying when `Retry-After` exceeds that budget or is not an integer delay. Preserve safe error classification without retaining raw HTTP-library exceptions as causes.
- Replace serializer hydration with an explicit response decoder and remove unused serializer and property-access dependencies.

### Fixed

- Cancel unused redirect responses before reporting `unexpected_redirect`.
- Preserve retry suppression after a long or invalid `Retry-After` header or an HTTP 4xx response, even if the response body then disconnects.
- Preserve `provider_reference` and callback reporting fields, including amounts, phone, currency, date, and channel.
- Classify structurally invalid provider payloads as `invalid_response` with HTTP status and a safe cause. Redact the configured token from provider error diagnostics.

### Added

- Deterministic success, failure, pending, and unknown fixtures; tests for exact corporate JSON, independent endpoints, immutable request reuse, callback parsing, malformed responses, redirects, deadlines, and retry behavior.
- An anonymized regression fixture from an authorized existing-order lookup on 2026-10-29: lookup code zero, transaction status four, numeric-string amounts, and no nested order number. Both SDKs parsed the response and reported no payment success; the provider message identified this transaction as declined without establishing a global status-four mapping.
- Regression fixtures from no-charge production probes on 2026-10-29: HTTP 200 JSON rejections with empty order numbers, an unknown-order check with a null transaction, and an HTML 403 response classified without exposing its body.
- Document validation evidence that both `callback_url` and `callbackUrl` are recognized for mobile and card input. Keep the documented `callback_url` in requests; payment acceptance and callback delivery remain unverified.
- [Version 4 upgrade guide](UPGRADE_4.0.0.md) with breaking changes and before/after examples. Keep the README focused on current API usage.
- [Object responsibility and collaboration notes](docs/design.md), including collection and failure paths.

### Migration

- Follow [the version 4 upgrade guide](UPGRADE_4.0.0.md). An existing declined card lookup has been verified with both SDKs. Successful-payment status zero and the pending status mapping still need confirmation from FlexPay; no successful live collection or callback delivery has been verified for this release.

## 3.0.5 - 2026-10-28

### Fixed

- Report malformed, empty, and non-JSON API responses as `NetworkException` with type `invalid_response`, HTTP status, Content-Type, and the original exception. Preserve HTTP error classification without including response bodies in the diagnostic message.

## 3.0.4 - 2026-10-21

### Changed

- Create GitHub releases with generated notes when stable tags are pushed. Leave prereleases to manual publishing.

## 3.0.3 - 2026-10-20

### Added

- Optional `apiBaseUrl` and `cardBaseUrl` client constructor arguments for local simulators and custom versioned endpoints. Existing environment defaults and HTTP-client injection remain compatible.

## 3.0.1

### Fixed

- Published the finalized migration with immutable source and distribution references for Packagist.

## 3.0.0

### Changed

- Moved the Composer package to `ngandu-dev/flexpay`.
- Adopted the `Ngandu\\Flexpay` PHP namespace.
- Standardized project documentation, quality commands, and continuous integration.

### Migration

- Require `ngandu-dev/flexpay` and update application imports to begin with `Ngandu\\Flexpay`.


## 2.0.x
- Rename: vpos to card 
- Fixed: vpos urls for production and test environments in `Environment`
- Added: `isSuccessful` to `Transaction` class
- Breaking Change: `pay` supports both `vpos` and `mobile` request
- Added: `vpos` and `mobile` method to `Client`
- Added: `VposResponse`, `Request`, `VposRequest`, `MobileRequest` class
- Fixed: allow `$message` to be an empty string in `CheckResponse`
- Fixed: `token` and `merchant` are now SensitiveParameters
- Added: Support for `check`, `vpos`, `mobile` urls in `Environment`,
- Removed: `getPaymentBaseUrl` method in `Environment`

## 1.0.x
- Fixed: allow `$message` to be an empty string in `PaymentResponse`
- Initial release
