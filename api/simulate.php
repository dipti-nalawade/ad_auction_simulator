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

$body = json_decode(file_get_contents('php://input'), true);

$auctionId  = isset($body['auction_id'])  ? filter_var($body['auction_id'],  FILTER_VALIDATE_INT) : false;
$numBidders = isset($body['num_bidders']) ? filter_var($body['num_bidders'], FILTER_VALIDATE_INT) : null;

if ($auctionId === false || $auctionId <= 0) {
    jsonResponse(['error' => 'auction_id must be a positive integer'], 422);
}

if ($numBidders !== null && ($numBidders === false || $numBidders < 1)) {
    jsonResponse(['error' => 'num_bidders must be a positive integer when provided'], 422);
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

// ── 2. Select bidder pool ─────────────────────────────────────────────────────
$allBidders = $pdo->query('SELECT id, name, budget FROM bidders ORDER BY id')->fetchAll();

if (count($allBidders) === 0) {
    jsonResponse(['error' => 'No bidders exist — add at least one bidder first'], 422);
}

$pool = $allBidders;
if ($numBidders !== null && $numBidders < count($allBidders)) {
    shuffle($pool);
    $pool = array_slice($pool, 0, $numBidders);
}

// ── 3. Generate bid amounts ───────────────────────────────────────────────────
// Range: [reserve_price, reserve_price × 3].
// Bidders with a larger budget (relative to the pool maximum) are biased toward
// the upper end of the range, producing realistic competitive dynamics:
//   • Budget factor 1.0 (richest) → weighted toward reserve × 3
//   • Budget factor 0.0 (leanest) → weighted toward reserve × 1
// The blend uses two independent random samples; the factor controls which
// sample dominates, creating a smooth probability shift rather than a hard cap.
$maxBudget = (float) max(array_column($pool, 'budget'));

/**
 * Returns a float in [$min, $max] biased by $factor (0.0 = low, 1.0 = high).
 * Two random draws are blended: factor 0 → r1 (uniformly low-biased),
 * factor 1 → r2 (uniformly high-biased), intermediate → linear blend.
 */
function biasedRandom(float $min, float $max, float $factor): float
{
    $r1 = $min + (mt_rand() / mt_getrandmax()) * ($max - $min);
    $r2 = $min + (mt_rand() / mt_getrandmax()) * ($max - $min);
    return round($r1 + ($r2 - $r1) * $factor, 2);
}

$simulatedBids = [];

$stmtExisting = $pdo->prepare(
    'SELECT id FROM bids WHERE auction_id = :auction_id AND bidder_id = :bidder_id'
);
$stmtInsert = $pdo->prepare(
    'INSERT INTO bids (auction_id, bidder_id, amount)
     VALUES (:auction_id, :bidder_id, :amount)'
);
$stmtUpdate = $pdo->prepare(
    'UPDATE bids
     SET amount = :amount, submitted_at = CURRENT_TIMESTAMP
     WHERE auction_id = :auction_id AND bidder_id = :bidder_id'
);

foreach ($pool as $bidder) {
    $budgetFactor = $maxBudget > 0 ? ((float) $bidder['budget'] / $maxBudget) : 0.5;
    $amount       = biasedRandom($reservePrice, $reservePrice * 3.0, $budgetFactor);
    $amount       = max($amount, $reservePrice);   // guarantee >= reserve

    $stmtExisting->execute([':auction_id' => $auctionId, ':bidder_id' => $bidder['id']]);
    $exists = $stmtExisting->fetch();

    if ($exists !== false) {
        $stmtUpdate->execute([
            ':amount'     => $amount,
            ':auction_id' => $auctionId,
            ':bidder_id'  => $bidder['id'],
        ]);
        $action = 'updated';
    } else {
        $stmtInsert->execute([
            ':auction_id' => $auctionId,
            ':bidder_id'  => $bidder['id'],
            ':amount'     => $amount,
        ]);
        $action = 'created';
    }

    $simulatedBids[] = [
        'bidder_id'   => (int) $bidder['id'],
        'bidder_name' => $bidder['name'],
        'amount'      => $amount,
        'action'      => $action,
    ];
}

// ── 4. Fetch all bids ranked for this auction ─────────────────────────────────
// Includes any bids placed before simulate was called, not just the generated set.
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

// ── 5. Determine winner and clearing price (second-price logic) ───────────────
$winner        = $bids[0];
$winnerBid     = (float) $winner['amount'];
$clearingPrice = count($bids) === 1
    ? $reservePrice
    : (float) $bids[1]['amount'];
$margin        = $winnerBid - $clearingPrice;

// ── 6. Persist result and close auction — atomically ─────────────────────────
$pdo->beginTransaction();

try {
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

    $cpm = ($clearingPrice / 1000) * 1000;

    $stmtCpm = $pdo->prepare(
        'INSERT INTO cpm_log (auction_id, cpm_value) VALUES (:auction_id, :cpm_value)'
    );
    $stmtCpm->execute([':auction_id' => $auctionId, ':cpm_value' => $cpm]);

    $stmtClose = $pdo->prepare("UPDATE auctions SET status = 'closed' WHERE id = :id");
    $stmtClose->execute([':id' => $auctionId]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    jsonResponse(['error' => 'Failed to record simulation result: ' . $e->getMessage()], 500);
}

// ── 7. Build response ─────────────────────────────────────────────────────────
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

// Present generated bids in the same ranked order as final standings
usort($simulatedBids, static fn($a, $b) => $b['amount'] <=> $a['amount']);

jsonResponse([
    'auction_id'     => (int) $auctionId,
    'slot_name'      => $auction['slot_name'],
    'simulated_bids' => $simulatedBids,
    'result'         => [
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
    ],
]);
