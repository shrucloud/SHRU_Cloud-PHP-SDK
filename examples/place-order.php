<?php

/**
 * Places one order for a given service ID and input, then prints the
 * resulting order ID and initial status.
 *
 * Usage: php place-order.php <service_id> <input>
 * Example: php place-order.php 10 356789101234567
 */

require __DIR__ . '/../src/ShruException.php';
require __DIR__ . '/../src/Client.php';
// Composer users: require __DIR__ . '/../vendor/autoload.php'; instead.

use ShruCloud\Client;
use ShruCloud\ShruException;

if ($argc < 3) {
    fwrite(STDERR, "Usage: php place-order.php <service_id> <input>\n");
    exit(1);
}

$serviceId = (int) $argv[1];
$input = (string) $argv[2];

$client = new Client(
    'https://reseller.example.com',
    'YOUR_KEY_ID',
    'YOUR_HMAC_SECRET'
);

try {
    // Re-running this exact command again for the same still-queued order
    // returns the same order_id instead of billing you twice - safe to
    // retry on a timeout.
    $order = $client->placeOrder($serviceId, $input);
} catch (ShruException $e) {
    fwrite(STDERR, 'Order failed: ' . $e->getMessage() . "\n");
    if ($e->isAuthError()) {
        fwrite(STDERR, "Check your Key ID/secret, and that this machine's IP is on that key's allowlist.\n");
    }
    exit(1);
}

echo "Order #" . $order['order_id'] . ' - status: ' . $order['status'] . "\n";
echo "Poll check-status.php " . $order['order_id'] . " to follow it through to completion.\n";
