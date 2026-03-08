<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS');
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

try {
    $pdo = getDbConnection();
} catch (RuntimeException $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->query(
        'SELECT
             a.id,
             a.slot_name,
             a.reserve_price,
             a.status,
             a.created_at,
             COUNT(b.id)  AS bid_count,
             MAX(b.amount) AS top_bid
         FROM auctions a
         LEFT JOIN bids b ON b.auction_id = a.id
         GROUP BY a.id, a.slot_name, a.reserve_price, a.status, a.created_at
         ORDER BY a.id'
    );

    $auctions = $stmt->fetchAll();

    foreach ($auctions as &$row) {
        $row['id']            = (int) $row['id'];
        $row['reserve_price'] = (float) $row['reserve_price'];
        $row['bid_count']     = (int) $row['bid_count'];
        $row['top_bid']       = $row['top_bid'] !== null ? (float) $row['top_bid'] : null;
    }
    unset($row);

    jsonResponse($auctions);
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);

    $slotName     = trim((string) ($body['slot_name']     ?? ''));
    $reservePrice = $body['reserve_price'] ?? null;

    if ($slotName === '') {
        jsonResponse(['error' => 'slot_name must not be empty'], 422);
    }

    if (!is_numeric($reservePrice) || (float) $reservePrice < 0) {
        jsonResponse(['error' => 'reserve_price must be a non-negative number'], 422);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO auctions (slot_name, reserve_price, status) VALUES (:slot_name, :reserve_price, :status)'
    );
    $stmt->execute([
        ':slot_name'     => $slotName,
        ':reserve_price' => (float) $reservePrice,
        ':status'        => 'pending',
    ]);
    $id = (int) $pdo->lastInsertId();

    jsonResponse([
        'id'            => $id,
        'slot_name'     => $slotName,
        'reserve_price' => (float) $reservePrice,
        'status'        => 'pending',
        'bid_count'     => 0,
        'top_bid'       => null,
    ], 201);
}

if ($method === 'PATCH') {
    $body = json_decode(file_get_contents('php://input'), true);

    $id     = isset($body['id']) ? filter_var($body['id'], FILTER_VALIDATE_INT) : false;
    $status = trim((string) ($body['status'] ?? ''));

    if ($id === false || $id === null || $id <= 0) {
        jsonResponse(['error' => 'id must be a positive integer'], 422);
    }

    if (!in_array($status, ['active', 'closed'], true)) {
        jsonResponse(['error' => "status must be 'active' or 'closed'"], 422);
    }

    $check = $pdo->prepare('SELECT id, status FROM auctions WHERE id = :id');
    $check->execute([':id' => $id]);
    $auction = $check->fetch();

    if ($auction === false) {
        jsonResponse(['error' => 'Auction not found'], 404);
    }

    // Prevent reopening a closed auction
    if ($auction['status'] === 'closed') {
        jsonResponse(['error' => 'A closed auction cannot be reopened'], 409);
    }

    $stmt = $pdo->prepare('UPDATE auctions SET status = :status WHERE id = :id');
    $stmt->execute([':status' => $status, ':id' => $id]);

    jsonResponse(['success' => true, 'id' => (int) $id, 'status' => $status]);
}

jsonResponse(['error' => 'Method not allowed'], 405);
