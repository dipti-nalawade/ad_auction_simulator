<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
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
             b.id,
             b.name,
             b.budget,
             COUNT(DISTINCT ar.id)  AS total_wins,
             COUNT(DISTINCT bi.id)  AS total_bids
         FROM bidders b
         LEFT JOIN auction_results ar ON ar.winner_bidder_id = b.id
         LEFT JOIN bids            bi ON bi.bidder_id        = b.id
         GROUP BY b.id, b.name, b.budget
         ORDER BY b.id'
    );

    $bidders = $stmt->fetchAll();

    foreach ($bidders as &$row) {
        $row['id']         = (int) $row['id'];
        $row['budget']     = (float) $row['budget'];
        $row['total_wins'] = (int) $row['total_wins'];
        $row['total_bids'] = (int) $row['total_bids'];
    }
    unset($row);

    jsonResponse($bidders);
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);

    $name   = trim((string) ($body['name']   ?? ''));
    $budget = $body['budget'] ?? null;

    if ($name === '') {
        jsonResponse(['error' => 'name must not be empty'], 422);
    }

    if (!is_numeric($budget) || (float) $budget <= 0) {
        jsonResponse(['error' => 'budget must be a number greater than 0'], 422);
    }

    $stmt = $pdo->prepare('INSERT INTO bidders (name, budget) VALUES (:name, :budget)');
    $stmt->execute([':name' => $name, ':budget' => (float) $budget]);
    $id = (int) $pdo->lastInsertId();

    jsonResponse([
        'id'         => $id,
        'name'       => $name,
        'budget'     => (float) $budget,
        'total_wins' => 0,
        'total_bids' => 0,
    ], 201);
}

if ($method === 'DELETE') {
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

    if ($id === false || $id === null || $id <= 0) {
        jsonResponse(['error' => 'A valid ?id=X query parameter is required'], 422);
    }

    $check = $pdo->prepare('SELECT id FROM bidders WHERE id = :id');
    $check->execute([':id' => $id]);

    if ($check->fetch() === false) {
        jsonResponse(['error' => 'Bidder not found'], 404);
    }

    // bids rows cascade-delete via FK; auction_results also cascade
    $stmt = $pdo->prepare('DELETE FROM bidders WHERE id = :id');
    $stmt->execute([':id' => $id]);

    jsonResponse(['success' => true, 'message' => "Bidder {$id} deleted successfully"]);
}

jsonResponse(['error' => 'Method not allowed'], 405);
