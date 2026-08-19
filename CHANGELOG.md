# Changelog

All notable changes to this project are documented in this file. The project follows [Semantic Versioning](https://semver.org/).

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
