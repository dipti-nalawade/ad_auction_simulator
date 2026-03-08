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

$auctionId = isset($body['auction_id']) ? filter_var($body['auction_id'], FILTER_VALIDATE_INT) : false;
$bidderId  = isset($body['bidder_id'])  ? filter_var($body['bidder_id'],  FILTER_VALIDATE_INT) : false;
$amount    = $body['amount'] ?? null;

if ($auctionId === false || $auctionId <= 0) {
    jsonResponse(['error' => 'auction_id must be a positive integer'], 422);
}

if ($bidderId === false || $bidderId <= 0) {
    jsonResponse(['error' => 'bidder_id must be a positive integer'], 422);
}

if (!is_numeric($amount) || (float) $amount <= 0) {
    jsonResponse(['error' => 'amount must be a positive number'], 422);
}

$amount = (float) $amount;

// Validate auction exists and is active
$stmtAuction = $pdo->prepare('SELECT id, status, reserve_price FROM auctions WHERE id = :id');
$stmtAuction->execute([':id' => $auctionId]);
$auction = $stmtAuction->fetch();

if ($auction === false) {
    jsonResponse(['error' => 'Auction not found'], 404);
}

if ($auction['status'] !== 'active') {
    jsonResponse(['error' => "Auction is not active (current status: {$auction['status']})"], 409);
}

if ($amount < (float) $auction['reserve_price']) {
    jsonResponse([
        'error' => "Bid amount ({$amount}) is below the reserve price ({$auction['reserve_price']})",
    ], 422);
}

// Validate bidder exists
$stmtBidder = $pdo->prepare('SELECT id FROM bidders WHERE id = :id');
$stmtBidder->execute([':id' => $bidderId]);

if ($stmtBidder->fetch() === false) {
    jsonResponse(['error' => 'Bidder not found'], 404);
}

// Check for an existing bid from this bidder in this auction
$stmtExisting = $pdo->prepare(
    'SELECT id FROM bids WHERE auction_id = :auction_id AND bidder_id = :bidder_id'
);
$stmtExisting->execute([':auction_id' => $auctionId, ':bidder_id' => $bidderId]);
$existing = $stmtExisting->fetch();

if ($existing !== false) {
    // Update existing bid
    $stmtUpsert = $pdo->prepare(
        'UPDATE bids SET amount = :amount, submitted_at = CURRENT_TIMESTAMP
         WHERE auction_id = :auction_id AND bidder_id = :bidder_id'
    );
    $stmtUpsert->execute([
        ':amount'     => $amount,
        ':auction_id' => $auctionId,
        ':bidder_id'  => $bidderId,
    ]);
    $action = 'updated';
} else {
    // Insert new bid
    $stmtUpsert = $pdo->prepare(
        'INSERT INTO bids (auction_id, bidder_id, amount) VALUES (:auction_id, :bidder_id, :amount)'
    );
    $stmtUpsert->execute([
        ':auction_id' => $auctionId,
        ':bidder_id'  => $bidderId,
        ':amount'     => $amount,
    ]);
    $action = 'created';
}

// Return all bids for this auction sorted descending by amount, with bidder names.
// Ranks are included for standings display, but the winner is not flagged explicitly.
$stmtStandings = $pdo->prepare(
    'SELECT
         bi.id         AS bid_id,
         b.id          AS bidder_id,
         b.name        AS bidder_name,
         bi.amount,
         bi.submitted_at,
         RANK() OVER (ORDER BY bi.amount DESC) AS position
     FROM bids bi
     JOIN bidders b ON b.id = bi.bidder_id
     WHERE bi.auction_id = :auction_id
     ORDER BY bi.amount DESC, bi.submitted_at ASC'
);
$stmtStandings->execute([':auction_id' => $auctionId]);
$standings = $stmtStandings->fetchAll();

foreach ($standings as &$row) {
    $row['bid_id']    = (int) $row['bid_id'];
    $row['bidder_id'] = (int) $row['bidder_id'];
    $row['amount']    = (float) $row['amount'];
    $row['position']  = (int) $row['position'];
}
unset($row);

jsonResponse([
    'action'     => $action,
    'auction_id' => (int) $auctionId,
    'standings'  => $standings,
], $action === 'created' ? 201 : 200);
