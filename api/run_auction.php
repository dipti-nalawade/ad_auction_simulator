<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function jsonResponse(mixed $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

try {
    $pdo = getDbConnection();
} catch (RuntimeException $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}

$body      = json_decode(file_get_contents('php://input'), true);
$auctionId = isset($body['auction_id']) ? filter_var($body['auction_id'], FILTER_VALIDATE_INT) : false;

if ($auctionId === false || $auctionId <= 0) {
    jsonResponse(['error' => 'auction_id must be a positive integer'], 422);
}

// ── 1. Verify auction exists and is active ────────────────────────────────────
$stmtAuction = $pdo->prepare(
    'SELECT id, slot_name, status, reserve_price FROM auctions WHERE id = :id'
);
$stmtAuction->execute([':id' => $auctionId]);
$auction = $stmtAuction->fetch();

if ($auction === false) {
    jsonResponse(['error' => 'Auction not found'], 404);
}

if ($auction['status'] !== 'active') {
    jsonResponse(['error' => "Auction is not active (current status: {$auction['status']})"], 409);
}

$reservePrice = (float) $auction['reserve_price'];

// ── 2. Fetch all bids ordered by amount DESC ──────────────────────────────────
$stmtBids = $pdo->prepare(
    'SELECT
         bi.id         AS bid_id,
         bi.bidder_id,
         b.name        AS bidder_name,
         bi.amount,
         bi.submitted_at,
         RANK() OVER (ORDER BY bi.amount DESC) AS position
     FROM bids bi
     JOIN bidders b ON b.id = bi.bidder_id
     WHERE bi.auction_id = :auction_id
     ORDER BY bi.amount DESC, bi.submitted_at ASC'
);
$stmtBids->execute([':auction_id' => $auctionId]);
$bids = $stmtBids->fetchAll();

// ── 3. Require at least one bid ───────────────────────────────────────────────
if (count($bids) === 0) {
    jsonResponse(['error' => 'No bids placed'], 422);
}

// ── 4 & 5. Determine winner and clearing price ────────────────────────────────
$winner       = $bids[0];
$winnerBid    = (float) $winner['amount'];

// One bid → winner pays reserve_price; two or more → second-highest bid amount
$clearingPrice = count($bids) === 1
    ? $reservePrice
    : (float) $bids[1]['amount'];

$margin = $winnerBid - $clearingPrice;

// ── 6–8. Persist results atomically ──────────────────────────────────────────
$pdo->beginTransaction();

try {
    // Insert auction result
    $stmtResult = $pdo->prepare(
        'INSERT INTO auction_results
             (auction_id, winner_bidder_id, clearing_price, winner_bid, margin)
         VALUES
             (:auction_id, :winner_bidder_id, :clearing_price, :winner_bid, :margin)'
    );
    $stmtResult->execute([
        ':auction_id'       => $auctionId,
        ':winner_bidder_id' => (int) $winner['bidder_id'],
        ':clearing_price'   => $clearingPrice,
        ':winner_bid'       => $winnerBid,
        ':margin'           => $margin,
    ]);

    // ── 7. CPM = clearing_price / 1000 * 1000 simplifies to clearing_price,
    //    but we store the explicit calculation result as specified
    $cpm = ($clearingPrice / 1000) * 1000;

    $stmtCpm = $pdo->prepare(
        'INSERT INTO cpm_log (auction_id, cpm_value) VALUES (:auction_id, :cpm_value)'
    );
    $stmtCpm->execute([':auction_id' => $auctionId, ':cpm_value' => $cpm]);

    // ── 8. Close the auction
    $stmtClose = $pdo->prepare("UPDATE auctions SET status = 'closed' WHERE id = :id");
    $stmtClose->execute([':id' => $auctionId]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    jsonResponse(['error' => 'Failed to record auction result: ' . $e->getMessage()], 500);
}

// ── 9. Build and return the full result object ────────────────────────────────
$participants = [];
foreach ($bids as $bid) {
    $participants[] = [
        'bid_id'       => (int) $bid['bid_id'],
        'bidder_id'    => (int) $bid['bidder_id'],
        'bidder_name'  => $bid['bidder_name'],
        'amount'       => (float) $bid['amount'],
        'submitted_at' => $bid['submitted_at'],
        'position'     => (int) $bid['position'],
        'is_winner'    => (int) $bid['bidder_id'] === (int) $winner['bidder_id'],
    ];
}

jsonResponse([
    'auction_id'     => (int) $auctionId,
    'slot_name'      => $auction['slot_name'],
    'winner'         => [
        'bidder_id'   => (int) $winner['bidder_id'],
        'bidder_name' => $winner['bidder_name'],
        'bid'         => $winnerBid,
    ],
    'clearing_price' => $clearingPrice,
    'margin'         => $margin,
    'cpm'            => $cpm,
    'participants'   => $participants,
]);
