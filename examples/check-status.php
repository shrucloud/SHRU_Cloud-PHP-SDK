<?php

/**
 * Polls one order until it leaves the queued/processing state, then prints
 * its final status and reply. In a real integration you'd normally poll
 * this on a schedule (eg. a cron job) rather than block like this example
 * does - shown this way just to keep the example self-contained.
 *
 * Usage: php check-status.php <order_id>
 */

require __DIR__ . '/../src/ShruException.php';
require __DIR__ . '/../src/Client.php';
// Composer users: require __DIR__ . '/../vendor/autoload.php'; instead.

use ShruCloud\Client;
use ShruCloud\ShruException;

if ($argc < 2) {
    fwrite(STDERR, "Usage: php check-status.php <order_id>\n");
    exit(1);
}

$orderId = (int) $argv[1];

$client = new Client(
    'https://reseller.example.com',
    'YOUR_KEY_ID',
    'YOUR_HMAC_SECRET'
);

$pending = ['queued', 'processing'];
$pollSeconds = 5;
$maxAttempts = 60; // 5 minutes at the default poll interval

for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
    try {
        $status = $client->checkStatus($orderId);
    } catch (ShruException $e) {
        fwrite(STDERR, 'Status check failed: ' . $e->getMessage() . "\n");
        exit(1);
    }

    if (!in_array($status['status'], $pending, true)) {
        echo "Order #{$orderId} finished as: {$status['status']}\n";
        if ($status['reply'] !== '') {
            echo "Reply:\n{$status['reply']}\n";
        }
        exit(0);
    }

    echo "[{$attempt}/{$maxAttempts}] Order #{$orderId} is still {$status['status']} - checking again in {$pollSeconds}s...\n";
    sleep($pollSeconds);
}

fwrite(STDERR, "Gave up waiting after {$maxAttempts} attempts - order #{$orderId} is still in progress.\n");
exit(1);
