# Changelog

All notable changes to this project are documented here.

## [1.0.0]

Initial public release.

- `Client`: `accountInfo()`, `syncServices()`, `syncCatalog()`, `placeOrder()`, `checkStatus()`, plus the low-level `request()` escape hatch.
- `ShruException`: one exception type for every failure, with `isAuthError()`, `getResponse()`, and an HTTP-status-carrying `getCode()`.
- The client finds the SHRU endpoint itself on first use: the domain + `/api/shru`, then the address exactly as given, then the address as given + `/api/shru`, each asked a read-only `account_info`. `endpoint()` returns the one in use. Passing the full `.../api/shru` address skips the check, and orders are only ever sent to that one endpoint.
- Plain HTTP is refused by default (the constructor throws before any request) unless explicitly allowed for local testing; redirects are never followed.
- Every request carries `Accept: application/json` and a `User-Agent` naming the SDK version.
- Dependency-free (`ext-curl` + `ext-json` only): works via Composer or as two files dropped straight into a project.
- Examples: `sync-services.php`, `place-order.php`, `check-status.php`.
