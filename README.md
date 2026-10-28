# SHRU_Cloud-PHP-SDK

Official PHP client for the **SHRU Cloud** reseller API (S-SERVER) - a single dependency-free class that syncs a supplier's catalog, places orders, and checks their status over the HMAC-signed SHRU protocol.

Compatible with any SHRU Cloud platform that implements the protocol below - a production deployment or the reference [SHRU_Cloud-Provider-Starter](https://github.com/shrucloud/SHRU_Cloud-Provider-Starter). [SHRU_Cloud-Reseller-Starter](https://github.com/shrucloud/SHRU_Cloud-Reseller-Starter) is itself built on this SDK, as a worked example of using it.

**Contents:** [Requirements](#requirements) · [Install](#install) · [Quickstart](#quickstart) · [The base URL](#the-base-url) · [Getting your credentials](#getting-your-credentials) · [API reference](#api-reference) · [Error handling](#error-handling) · [Protocol reference](#protocol-reference) · [Security](#security)

## Requirements

- PHP 7.2+
- `ext-curl`, `ext-json` (both ship with PHP by default)

## Install

Via Composer, straight from this repository:

```bash
composer config repositories.shrucloud vcs https://github.com/shrucloud/SHRU_Cloud-PHP-SDK
composer require shrucloud/php-sdk
```

Or, with no dependency manager at all, just drop two files into your project:

```
src/Client.php
src/ShruException.php
```

```php
require 'path/to/ShruException.php';
require 'path/to/Client.php';
```

## Quickstart

```php
use ShruCloud\Client;
use ShruCloud\ShruException;

$client = new Client(
    'https://reseller.example.com', // the SHRU Cloud platform's own domain
    'YOUR_KEY_ID',
    'YOUR_HMAC_SECRET'
);

try {
    $services = $client->syncServices();
    $order    = $client->placeOrder($services[0]['id'], '356789101234567');
    $status   = $client->checkStatus($order['order_id']);
} catch (ShruException $e) {
    echo $e->getMessage();
}
```

Runnable versions of all three steps live in [`examples/`](examples/):

```bash
php examples/sync-services.php
php examples/place-order.php <service_id> <input>
php examples/check-status.php <order_id>
```

## The base URL

The constructor's `$baseUrl` is the address the platform gave you, usually just its domain. Any of these work:

```
https://reseller.example.com/
https://reseller.example.com
reseller.example.com
https://reseller.example.com/api/shru
https://reseller.example.com/any/page/of/the/site
```

On first use the client finds the SHRU endpoint itself, trying in this order:

1. the domain + `/api/shru` (where every SHRU Cloud platform has it)
2. the address exactly as given
3. the address as given + `/api/shru`

It asks each one for `account_info`, a read-only call, and uses the first that answers as a SHRU endpoint, including a refusal in SHRU's own format (a wrong key, an IP not allowed), since that still proves the address is right. The endpoint found is kept for the client's lifetime; `$client->endpoint()` returns it. Orders are only ever sent to that one endpoint, never retried at another address. Passing the full `https://reseller.example.com/api/shru` skips the check entirely.

The same goes for SHRU Cloud itself: when one SHRU Cloud platform adds another as a SHRU supplier, the admin only needs to paste the other platform's domain. It finds `/api/shru` on its own when the supplier is saved.

## Getting your credentials

Every SHRU Cloud platform issues credentials from its own admin panel: Reseller API Keys -> your account -> **SHRU** tab -> Enable SHRU Access (on [SHRU_Cloud-Provider-Starter](https://github.com/shrucloud/SHRU_Cloud-Provider-Starter), that's Credentials -> New Credential):

| | |
|---|---|
| **Key ID** | Identifies your account, sent on every request. |
| **Secret** | Shown once, at creation/regeneration time only. Store it like a database password. |
| **Allowed IPs** | The platform rejects every request from an IP not on this list. Get the IP(s) your integration actually calls from added *before* your first request, or you'll get a 403. |

## API reference

### `accountInfo(): array`

```php
['balance' => float, 'currency' => string, 'email' => string]
```

A lightweight credentials/connectivity check - useful before doing anything else.

### `syncServices(): array` / `syncCatalog(): array`

The full catalog of services you're allowed to order, priced exactly as your account is billed (any tier pricing already applied server-side - never compute or cache pricing yourself). `syncCatalog()` returns the same list alongside your current balance in one call, saving a round-trip:

```php
[
    'id'              => int,
    'name'            => string,
    'group'           => string,
    'type'            => 'fixed' | 'quantity',
    'price'           => float,
    'delivery'        => string,        // eg. "Instant", "1-24h"
    'input_label'     => ?string,       // null when this service takes no primary input at all
    'input_required'  => bool,
    'quantity'        => ?['min' => int, 'max' => int], // only for type: "quantity"
    'bulk'            => bool,          // only ever true for type: "fixed" - see placeOrder() below
    'fields'          => [
        ['label' => string, 'key' => string, 'type' => 'input' | 'textarea', 'required' => bool],
        // ...
    ],
]
```

Never assume `input_label` means "IMEI" - it's whatever this specific service actually asks for (SN, ECID, an account ID, ...). Each `fields[]` entry's `key` is exactly the name to use for it in `placeOrder()`'s own `$fields` array, not necessarily the same string as its human-readable `label`.

### `placeOrder(int $serviceId, string $input, int $quantity = 1, array $fields = []): array`

```php
['order_id' => int, 'status' => string]
```

- `$input` - the service's primary input (eg. an IMEI/serial). Pass `''` for a service whose `input_label` is `null`.
- `$quantity` - only meaningful for a `type: "quantity"` service; ignored otherwise.
- `$fields` - extra field values, keyed by each field's `key` from `syncServices()`.
- `bulk: true` means the service accepts several independent inputs - there's no separate bulk parameter, just call `placeOrder()` once per value.

Re-submitting the exact same `$input`/`$fields`/`$quantity` for a service you already have a still-queued order for returns that same order instead of creating (and billing) a duplicate - safe to retry after a network timeout without checking first. This applies to orders that carry an input or field values; an order with neither is always a new order. The exact same signed request sent twice within one second is refused with HTTP 409.

### `checkStatus(int $orderId): array`

```php
['order_id' => int, 'status' => string, 'reply' => string]
```

`status` is one of `queued`, `processing`, `completed`, `rejected`, `refunded`. `reply` is always plain text (any HTML the platform stores internally is stripped before it reaches you) and is `''` until the order finishes.

### `request(string $action, array $params = []): array`

Low-level escape hatch - calls any action by name with a raw params array and returns the decoded response (`success` key included). Use this for an action a platform has added since this SDK was last updated, without waiting on a new release.

## Error handling

Every failure - transport (network error, invalid JSON), auth (bad/expired signature, IP not allowlisted), or a normal API-level rejection (insufficient balance, service not found, ...) - throws a single `ShruException`:

```php
try {
    $client->placeOrder($serviceId, $input);
} catch (ShruException $e) {
    echo $e->getMessage();  // the server's own "message", almost always safe to show a user directly
    $e->getCode();          // the HTTP status the server returned (0 = never reached the server)
    $e->isAuthError();      // true for a 401/403 - bad Key ID/secret, or your IP isn't allowlisted
    $e->getResponse();      // the full decoded JSON body, when one exists
}
```

## Protocol reference

Implementing this in another language, or just want to see what's on the wire:

- **Endpoint** - `POST {domain}/api/shru` (the platform gives you its domain), JSON body, one `action` per request.
- **Headers** - `X-Key-Id`, `X-Timestamp` (Unix epoch seconds), `X-Signature`.
- **Actions** - `account_info`, `sync`, `submit_order`, `check_status`; request/response shapes exactly match the return types documented above for each corresponding SDK method.
- **Response envelope** - always `{"success": true, ...}` or `{"success": false, "message": "..."}`.

**Signing algorithm:**

```
signable  = "POST\n/api/shru\n{timestamp}\n{raw JSON request body}"
signature = lowercase hex HMAC-SHA256(signable, your secret)
```

`{timestamp}` is a Unix epoch value - inherently timezone-independent, so client and server always agree on "now" regardless of either machine's local timezone. A signature the server rejects almost always means clock drift (NTP), not a timezone mismatch. Servers reject a timestamp more than 5 minutes old.

## Security

- Plain HTTP is refused by default - the constructor throws immediately, before any request is sent. Pass `$allowInsecureHttp = true` only for a local/dev endpoint with no TLS, never in production.
- Redirects are never followed - a signed request has exactly one valid destination, and blindly following a redirect could silently send your Key ID and secret to a different host, or downgrade the connection to plain HTTP.
- Treat your HMAC secret like a database password: never commit it, never log it, and regenerate it immediately if you suspect it leaked.

## License

MIT - see [LICENSE](LICENSE).
