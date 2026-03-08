<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

// Allow indefinite execution; detect client disconnect
set_time_limit(0);
ignore_user_abort(true);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');   // disable nginx proxy buffering
header('Access-Control-Allow-Origin: *');

// Flush any output buffers left by the web server or PHP
while (ob_get_level()) ob_end_flush();
ob_implicit_flush(true);

try {
    $pdo = getDbConnection();
} catch (RuntimeException $e) {
    echo 'event: error' . "\n";
    echo 'data: ' . json_encode(['error' => $e->getMessage()]) . "\n\n";
    exit;
}

// ── Poll the DB every 2 s; send an "update" event only when state changes ────
$lastHash = '';

$stmt = $pdo->prepare(
    'SELECT a.id, a.status, COUNT(bi.id) AS bid_count
     FROM auctions a
     LEFT JOIN bids bi ON bi.auction_id = a.id
     GROUP BY a.id, a.status
     ORDER BY a.id'
);

while (!connection_aborted()) {
    $stmt->execute();
    $snapshot = $stmt->fetchAll();
    $hash     = md5(json_encode($snapshot));

    if ($hash !== $lastHash) {
        $lastHash = $hash;
        echo 'event: update' . "\n";
        echo 'data: ' . json_encode(['ts' => time()]) . "\n\n";
    } else {
        // Keep-alive comment so proxies don't time out the connection
        echo ": heartbeat\n\n";
    }

    sleep(2);
}
