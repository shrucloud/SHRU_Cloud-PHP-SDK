<?php

namespace ShruCloud;

/**
 * Official PHP client for the SHRU Cloud reseller API - the HMAC-signed
 * protocol any SHRU Cloud platform (S-SERVER) speaks.
 *
 * One class, no dependencies beyond cURL and json - drop this file (plus
 * ShruException.php) straight into an existing project, or install via
 * Composer. Every call either returns the server's decoded response body
 * as a plain associative array, or throws ShruException.
 *
 * Quickstart:
 *
 *   $client = new \ShruCloud\Client('https://reseller.example.com', 'YOUR_KEY_ID', 'YOUR_HMAC_SECRET');
 *
 *   $services = $client->syncServices();
 *   $order    = $client->placeOrder($services[0]['id'], '356789101234567');
 *   $status   = $client->checkStatus($order['order_id']);
 *
 * See README.md for the full protocol reference (signing algorithm,
 * headers, every action's request/response shape) and examples/ for
 * complete runnable scripts.
 */
class Client
{
    /** This SDK's version, sent in the User-Agent header. */
    public const VERSION = '1.0.0';

    /** @var string scheme://host(:port) of the platform, used for the HTTPS check. */
    private $baseUrl;

    /** @var string[] Where the SHRU endpoint may be, in the order they are tried. */
    private $candidates;

    /** @var string|null The endpoint in use, found on first use and kept for this client's lifetime. */
    private $endpoint = null;

    /** @var string */
    private $keyId;

    /** @var string */
    private $hmacSecret;

    /** @var int Seconds before a request gives up (connect + transfer). */
    private $timeoutSeconds;

    /** @var bool Verify the server's TLS certificate. Only ever disable this against a local/dev endpoint. */
    private $verifySsl;

    /**
     * @param string $baseUrl           The reseller site's address, in whatever form is easiest to paste -
     *                                  just the domain ("https://reseller.example.com/", even a bare
     *                                  "reseller.example.com"), the full endpoint
     *                                  ("https://reseller.example.com/api/shru"), or any page of the site.
     *                                  The endpoint is found on first use, trying in order: the domain +
     *                                  /api/shru, the address as given, the address as given + /api/shru.
     *                                  See endpoint().
     * @param string $keyId             The Key ID shown when the reseller admin created your API key
     *                                  (Reseller API Keys, SHRU tab).
     * @param string $hmacSecret        The matching secret, shown once at creation/regeneration time only -
     *                                  store it the same way you'd store a database password.
     * @param bool   $allowInsecureHttp Refuses to connect over plain HTTP by default (throws immediately,
     *                                  before any request is sent) - your Key ID, order details, and every
     *                                  reply would otherwise travel in the clear. Only set this true against
     *                                  a local/dev endpoint that genuinely has no TLS; never in production.
     *
     * @throws \InvalidArgumentException if any required value is missing, $timeoutSeconds is below 1,
     *                                   $baseUrl has no resolvable host, or $baseUrl is plain HTTP
     *                                   without $allowInsecureHttp set.
     */
    public function __construct(
        string $baseUrl,
        string $keyId,
        string $hmacSecret,
        int $timeoutSeconds = 15,
        bool $verifySsl = true,
        bool $allowInsecureHttp = false
    ) {
        if ($baseUrl === '' || $keyId === '' || $hmacSecret === '') {
            throw new \InvalidArgumentException('baseUrl, keyId, and hmacSecret are all required.');
        }
        if ($timeoutSeconds < 1) {
            // cURL reads 0 as "wait forever".
            throw new \InvalidArgumentException('timeoutSeconds must be at least 1.');
        }
        $this->baseUrl = self::normalizeBaseUrl($baseUrl);
        $this->candidates = self::endpointCandidates($baseUrl, $this->baseUrl);
        if (strpos($this->baseUrl, 'https://') !== 0 && !$allowInsecureHttp) {
            throw new \InvalidArgumentException(
                'Refusing to connect to "' . $this->baseUrl . '" over plain HTTP - your Key ID, order ' .
                'details, and every reply would travel unencrypted. Pass $allowInsecureHttp = true only ' .
                'for local/dev testing against an endpoint with no TLS, never in production.'
            );
        }
        $this->keyId = $keyId;
        $this->hmacSecret = $hmacSecret;
        $this->timeoutSeconds = $timeoutSeconds;
        $this->verifySsl = $verifySsl;
    }

    /**
     * Reduces any of the accepted $baseUrl forms down to just scheme://host(:port),
     * the origin the endpoint candidates (endpointCandidates()) start from.
     */
    private static function normalizeBaseUrl(string $url): string
    {
        $trimmed = trim($url);
        $parts = parse_url($trimmed);
        if ($parts === false || empty($parts['host'])) {
            // No scheme at all (eg. just "reseller.example.com") - parse_url()
            // treats a bare host with no "://" as a path, not a host, so retry
            // once assuming https.
            $parts = parse_url('https://' . ltrim($trimmed, '/'));
        }
        if (!$parts || empty($parts['host'])) {
            throw new \InvalidArgumentException('Could not determine a valid host from base URL: "' . $url . '"');
        }
        $scheme = strtolower($parts['scheme'] ?? 'https');
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Unsupported URL scheme "' . $scheme . '" in base URL: "' . $url . '"');
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        return $scheme . '://' . $parts['host'] . $port;
    }

    /**
     * Where the SHRU endpoint may be, in the order endpoint() tries them, without
     * duplicates: the domain + /api/shru (where every SHRU Cloud platform has it),
     * the address exactly as given, and the address as given + /api/shru.
     *
     * @return string[]
     */
    private static function endpointCandidates(string $given, string $origin): array
    {
        $given = trim($given);
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $given)) {
            $given = 'https://' . ltrim($given, '/');
        }
        $given = preg_replace('/#.*$/', '', $given);
        if (rtrim($given, '/') === $origin . '/api/shru') {
            return [$origin . '/api/shru'];
        }
        $asGiven = rtrim($origin, '/') === rtrim($given, '/') ? $origin . '/' : $given;
        return array_values(array_unique([
            $origin . '/api/shru',
            $asGiven,
            rtrim($given, '/') . '/api/shru',
        ]));
    }

    /**
     * The SHRU endpoint this client talks to. Found once, on first use, by
     * asking each candidate (see endpointCandidates()) for account_info - a
     * read-only call - until one answers as a SHRU Cloud endpoint: a successful
     * reply, or a refusal in the SHRU format (wrong key, IP not allowed), which
     * still proves the address is right. A candidate that is unreachable or
     * answers with something else (a 404 page) is skipped. When none answers,
     * the first candidate is used, so the real call reports its own error.
     * Orders are only ever sent to the one endpoint found here, never retried
     * at another address.
     */
    public function endpoint(): string
    {
        if ($this->endpoint !== null) {
            return $this->endpoint;
        }
        if (count($this->candidates) === 1) {
            return $this->endpoint = $this->candidates[0];
        }
        $body = json_encode(['action' => 'account_info']);
        foreach ($this->candidates as $url) {
            $reply = $this->send($url, (string) $body);
            if ($reply['errno'] === 0 && is_array($reply['decoded']) && is_bool($reply['decoded']['success'] ?? null)) {
                return $this->endpoint = $url;
            }
        }
        return $this->endpoint = $this->candidates[0];
    }

    /**
     * Your reseller account's current standing, in the currency the
     * platform bills in. Useful as a lightweight credentials/connectivity
     * check before doing anything else.
     *
     * @return array{balance: float, currency: string, email: string}
     */
    public function accountInfo(): array
    {
        return $this->request('account_info');
    }

    /**
     * The full catalog of services you're allowed to order, priced exactly
     * as your account is billed (any tier pricing you're assigned already
     * applied server-side - never compute or cache pricing yourself).
     *
     * Every entry carries everything placeOrder() can require, so you never
     * have to guess or discover it the hard way from a rejected order:
     * `input_label`/`input_required` describe the primary $input argument -
     * never assume it's always an IMEI, it's whatever this specific service
     * actually asks for (SN, ECID, an account ID, ...); `input_label` is
     * null when the service doesn't take one at all. `quantity` is `null`
     * for a `type: "fixed"` service or a `{min, max}` range for a
     * `type: "quantity"` one. `bulk` (only ever true for a `"fixed"`
     * service) means it accepts several independent inputs - just call
     * placeOrder() once per value, there's no separate bulk parameter.
     * `fields` lists every extra field this specific service needs - each
     * entry's `key` is exactly the name to use for it in placeOrder()'s own
     * `$fields` array (not necessarily the same string as its human-readable
     * `label`).
     *
     * Only the service list itself - see syncCatalog() for this same data
     * alongside your current account balance in one call.
     *
     * @return array<int, array{
     *     id: int, name: string, group: string, type: string, price: float, delivery: string,
     *     input_label: ?string, input_required: bool, quantity: ?array{min: int, max: int}, bulk: bool,
     *     fields: array<int, array{label: string, key: string, type: string, required: bool}>
     * }>
     */
    public function syncServices(): array
    {
        return $this->syncCatalog()['services'];
    }

    /**
     * Same catalog as syncServices(), plus your current account balance
     * alongside it in the one call - useful the moment you want to show
     * both without a second round-trip (eg. a fresh integration's very
     * first screen).
     *
     * @return array{balance: float, currency: string, services: array}
     */
    public function syncCatalog(): array
    {
        $response = $this->request('sync');
        return [
            'balance' => (float) ($response['balance'] ?? 0),
            'currency' => (string) ($response['currency'] ?? 'USD'),
            'services' => $response['services'] ?? [],
        ];
    }

    /**
     * Places an order. Re-submitting the exact same $input/$fields/$quantity
     * for a service you already have a still-queued order for returns that
     * same order rather than creating (and billing) a duplicate - safe to
     * retry on a network timeout without checking first. This applies to
     * orders that carry an input or field values; an order with neither is
     * always a new order. The exact same signed request sent twice within
     * the same second is refused (HTTP 409), so a retry is always re-signed.
     *
     * @param int      $serviceId One of the `id` values from syncServices().
     * @param string   $input     The service's primary input (eg. an IMEI/serial) - pass ''
     *                            for a service that doesn't require one.
     * @param int      $quantity  Only meaningful for a `type: "quantity"` service from
     *                            syncServices() - ignored otherwise.
     * @param string[] $fields    Extra field values the service requires, keyed by field
     *                            name as documented for that service.
     *
     * @return array{order_id: int, status: string}
     */
    public function placeOrder(int $serviceId, string $input, int $quantity = 1, array $fields = []): array
    {
        return $this->request('submit_order', [
            'service_id' => $serviceId,
            'input' => $input,
            'quantity' => $quantity,
            'fields' => $fields,
        ]);
    }

    /**
     * Current status and (once available) delivered result for an order
     * this same API key placed. `reply` is always plain text - any HTML
     * the platform stores internally is already stripped.
     *
     * status is one of: queued, processing, completed, rejected, refunded.
     *
     * @return array{order_id: int, status: string, reply: string}
     */
    public function checkStatus(int $orderId): array
    {
        return $this->request('check_status', ['order_id' => $orderId]);
    }

    /**
     * Low-level escape hatch - calls any action by name with a raw params
     * array and returns the decoded response, "success" key included. Use
     * this for an action the platform has added since this SDK was last
     * updated, without waiting on a new release.
     *
     * @throws ShruException on any transport, auth, or API-level failure.
     */
    public function request(string $action, array $params = []): array
    {
        $body = json_encode(array_merge(['action' => $action], $params));
        if ($body === false) {
            throw new ShruException('Failed to encode request body as JSON: ' . json_last_error_msg());
        }

        $reply = $this->send($this->endpoint(), $body);

        if ($reply['errno'] !== 0) {
            throw new ShruException('Connection to SHRU Cloud failed: ' . $reply['error'], 0, null);
        }

        $decoded = $reply['decoded'];
        if (!is_array($decoded)) {
            throw new ShruException('SHRU Cloud returned a non-JSON response (HTTP ' . $reply['status'] . ')', $reply['status'], null);
        }

        if (empty($decoded['success'])) {
            $message = (string) ($decoded['message'] ?? 'SHRU Cloud rejected the request');
            throw new ShruException($message, $reply['status'], $decoded);
        }

        return $decoded;
    }

    /**
     * One signed POST to $url. Never throws: returns the cURL error number and
     * message, the HTTP status and the decoded JSON body (null when the body is
     * not JSON), for request() and endpoint() to judge.
     *
     * @return array{errno: int, error: string, status: int, decoded: ?array}
     */
    private function send(string $url, string $body): array
    {
        // time() is a Unix epoch value - seconds since 1970-01-01 UTC - which
        // is timezone-independent by definition, not just by convention. It
        // returns the same value no matter what timezone this machine (or
        // the server) is configured to, so there is nothing to set or align
        // here - never swap this for a timezone-aware date/time call.
        $timestamp = (string) time();
        $signature = $this->sign($timestamp, $body);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            // Never follow a redirect - this request has exactly one valid
            // destination, and a redirect could otherwise silently send the
            // signed request (Key ID included) to a different host, or
            // downgrade it to plain HTTP.
            CURLOPT_FOLLOWLOCATION => false,
            // Matches whatever scheme the constructor already validated
            // baseUrl against - cURL itself can't be tricked into HTTP by a
            // redirect or a malformed URL once HTTPS was required.
            CURLOPT_PROTOCOLS => strpos($this->baseUrl, 'https://') === 0 ? CURLPROTO_HTTPS : (CURLPROTO_HTTP | CURLPROTO_HTTPS),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: SHRU_Cloud-PHP-SDK/' . self::VERSION . ' (PHP ' . PHP_VERSION . ')',
                'X-Key-Id: ' . $this->keyId,
                'X-Timestamp: ' . $timestamp,
                'X-Signature: ' . $signature,
            ],
        ]);
        $raw = curl_exec($ch);
        $reply = [
            'errno' => curl_errno($ch),
            'error' => curl_error($ch),
            'status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'decoded' => null,
        ];
        if ($reply['errno'] === 0) {
            $decoded = json_decode((string) $raw, true);
            $reply['decoded'] = is_array($decoded) ? $decoded : null;
        }
        return $reply;
    }

    /**
     * The exact signing algorithm the server verifies against - see
     * README.md's "Protocol reference" section for the full spec and a
     * worked example if you're implementing this in another language.
     *
     *   signable  = "POST\n/api/shru\n{timestamp}\n{raw JSON body}"
     *   signature = lowercase hex HMAC-SHA256(signable, your secret)
     *
     * {timestamp} is a Unix epoch value (seconds since 1970-01-01 UTC) -
     * inherently timezone-independent, so client and server always agree on
     * "now" regardless of either machine's local timezone configuration.
     */
    private function sign(string $timestamp, string $body): string
    {
        $signable = "POST\n/api/shru\n" . $timestamp . "\n" . $body;
        return hash_hmac('sha256', $signable, $this->hmacSecret);
    }
}
