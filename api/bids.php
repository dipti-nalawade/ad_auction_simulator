<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

// ── Input validation ──────────────────────────────────────────────────────────
$auctionId = filter_input(INPUT_GET, 'auction_id', FILTER_VALIDATE_INT);
if ($auctionId === false || $auctionId === null || $auctionId <= 0) {
    jsonResponse(['error' => 'auction_id must be a positive integer'], 422);
}

try {
    $pdo = getDbConnection();
} catch (RuntimeException $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}

// ── Auction ───────────────────────────────────────────────────────────────────
$stmtAuction = $pdo->prepare(
    'SELECT id, slot_name, status, reserve_price, created_at FROM auctions WHERE id = :id'
);
$stmtAuction->execute([':id' => $auctionId]);
$auction = $stmtAuction->fetch();

if ($auction === false) {
    jsonResponse(['error' => 'Auction not found'], 404);
}

// ── Bids in chronological order (timeline view) ───────────────────────────────
$stmtBids = $pdo->prepare(
    'SELECT
         bi.id           AS bid_id,
         bi.bidder_id,
         b.name          AS bidder_name,
         bi.amount,
         bi.submitted_at
     FROM bids bi
     JOIN bidders b ON b.id = bi.bidder_id
     WHERE bi.auction_id = :auction_id
     ORDER BY bi.submitted_at ASC, bi.id ASC'
);
$stmtBids->execute([':auction_id' => $auctionId]);
$bids = $stmtBids->fetchAll();

foreach ($bids as &$bid) {
    $bid['bid_id']    = (int)   $bid['bid_id'];
    $bid['bidder_id'] = (int)   $bid['bidder_id'];
    $bid['amount']    = (float) $bid['amount'];
}
unset($bid);

// ── Result (only present when auction is closed) ──────────────────────────────
$result = null;
if ($auction['status'] === 'closed') {
    $stmtResult = $pdo->prepare(
        'SELECT ar.winner_bidder_id, b.name AS winner_name,
                ar.clearing_price, ar.winner_bid, ar.margin, ar.resolved_at
         FROM auction_results ar
         JOIN bidders b ON b.id = ar.winner_bidder_id
         WHERE ar.auction_id = :auction_id'
    );
    $stmtResult->execute([':auction_id' => $auctionId]);
    $row = $stmtResult->fetch();
    if ($row !== false) {
        $result = [
            'winner_bidder_id' => (int)   $row['winner_bidder_id'],
            'winner_name'      =>          $row['winner_name'],
            'clearing_price'   => (float) $row['clearing_price'],
            'winner_bid'       => (float) $row['winner_bid'],
            'margin'           => (float) $row['margin'],
            'resolved_at'      =>          $row['resolved_at'],
        ];
    }
}

jsonResponse([
    'auction' => [
        'id'            => (int)   $auction['id'],
        'slot_name'     =>          $auction['slot_name'],
        'status'        =>          $auction['status'],
        'reserve_price' => (float) $auction['reserve_price'],
        'created_at'    =>          $auction['created_at'],
    ],
    'bids'   => $bids,
    'result' => $result,
]);
