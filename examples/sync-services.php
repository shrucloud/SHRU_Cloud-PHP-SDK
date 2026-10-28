<?php

/**
 * Fetches and prints your account balance plus the full service catalog -
 * every field placeOrder() can require (input label, quantity range, extra
 * fields), never truncated. Printed as JSON on purpose: a fixed-width
 * table forces long service names to get cut off, and JSON is what most
 * integrations actually consume this response as anyway.
 *
 * Usage: php sync-services.php
 */

require __DIR__ . '/../src/ShruException.php';
require __DIR__ . '/../src/Client.php';
// If you installed via Composer instead, use this one line in place of the
// two requires above:
// require __DIR__ . '/../vendor/autoload.php';

use ShruCloud\Client;
use ShruCloud\ShruException;

$client = new Client(
    'https://reseller.example.com', // your SHRU Cloud platform's base URL
    'YOUR_KEY_ID',
    'YOUR_HMAC_SECRET'
);

try {
    $catalog = $client->syncCatalog(); // ['balance' => ..., 'currency' => ..., 'services' => [...]]
} catch (ShruException $e) {
    fwrite(STDERR, 'Sync failed: ' . $e->getMessage() . "\n");
    exit(1);
}

echo json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

fwrite(STDERR, "\n" . count($catalog['services']) . ' service(s) - balance: ' . number_format($catalog['balance'], 2) . ' ' . $catalog['currency'] . "\n");
