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

$days = filter_input(INPUT_GET, 'days', FILTER_VALIDATE_INT);
if ($days === false || $days === null || $days <= 0) {
    $days = 30;
}

try {
    $pdo = getDbConnection();
} catch (RuntimeException $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}

// ── 1. Win rates ──────────────────────────────────────────────────────────────
// Wins come from auction_results; total_bids is distinct auctions bid on,
// so win_rate reflects "auctions entered and won" not raw bid count.
$stmtWinRates = $pdo->query(
    'SELECT
         b.name                                          AS bidder_name,
         COUNT(DISTINCT ar.id)                           AS wins,
         COUNT(DISTINCT bi.auction_id)                   AS total_bids,
         ROUND(
             COUNT(DISTINCT ar.id) /
             NULLIF(COUNT(DISTINCT bi.auction_id), 0) * 100,
             2
         )                                               AS win_rate_percent
     FROM bidders b
     LEFT JOIN bids            bi ON bi.bidder_id        = b.id
     LEFT JOIN auction_results ar ON ar.winner_bidder_id = b.id
     GROUP BY b.id, b.name
     HAVING total_bids > 0
     ORDER BY win_rate_percent DESC, wins DESC'
);

$winRates = [];
foreach ($stmtWinRates->fetchAll() as $row) {
    $winRates[] = [
        'bidder_name'      => $row['bidder_name'],
        'wins'             => (int) $row['wins'],
        'total_bids'       => (int) $row['total_bids'],
        'win_rate_percent' => (float) $row['win_rate_percent'],
    ];
}

// ── 2. CPM trend (last N days, grouped by day) ────────────────────────────────
$stmtCpm = $pdo->prepare(
    'SELECT
         DATE(logged_at)          AS date,
         ROUND(AVG(cpm_value), 4) AS avg_cpm,
         ROUND(MIN(cpm_value), 4) AS min_cpm,
         ROUND(MAX(cpm_value), 4) AS max_cpm
     FROM cpm_log
     WHERE logged_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
     GROUP BY DATE(logged_at)
     ORDER BY date ASC'
);
$stmtCpm->execute([':days' => $days]);

$cpmTrend = [];
foreach ($stmtCpm->fetchAll() as $row) {
    $cpmTrend[] = [
        'date'    => $row['date'],
        'avg_cpm' => (float) $row['avg_cpm'],
        'min_cpm' => (float) $row['min_cpm'],
        'max_cpm' => (float) $row['max_cpm'],
    ];
}

// ── 3. Bid amount distribution (histogram buckets) ────────────────────────────
// Buckets: 0–5, 5–10, 10–20, 20–50, 50+
$stmtDist = $pdo->query(
    "SELECT
         CASE
             WHEN amount <  5  THEN '0-5'
             WHEN amount < 10  THEN '5-10'
             WHEN amount < 20  THEN '10-20'
             WHEN amount < 50  THEN '20-50'
             ELSE                   '50+'
         END      AS bucket,
         COUNT(*) AS count
     FROM bids
     GROUP BY bucket
     ORDER BY
         CASE bucket
             WHEN '0-5'   THEN 1
             WHEN '5-10'  THEN 2
             WHEN '10-20' THEN 3
             WHEN '20-50' THEN 4
             ELSE              5
         END"
);

// Ensure every bucket is present even if empty
$buckets = ['0-5' => 0, '5-10' => 0, '10-20' => 0, '20-50' => 0, '50+' => 0];
foreach ($stmtDist->fetchAll() as $row) {
    $buckets[$row['bucket']] = (int) $row['count'];
}

$bidDistribution = [];
foreach ($buckets as $bucket => $count) {
    $bidDistribution[] = ['bucket' => $bucket, 'count' => $count];
}

// ── 4. Summary ────────────────────────────────────────────────────────────────
$stmtSummary = $pdo->query(
    'SELECT
         COUNT(DISTINCT a.id)           AS total_auctions,
         COUNT(DISTINCT bi.id)          AS total_bids,
         ROUND(AVG(ar.clearing_price), 2) AS avg_clearing_price,
         ROUND(AVG(ar.margin), 2)       AS avg_margin,
         MAX(bi.amount)                 AS highest_single_bid
     FROM auctions a
     LEFT JOIN bids            bi ON bi.auction_id        = a.id
     LEFT JOIN auction_results ar ON ar.auction_id        = a.id'
);
$summary = $stmtSummary->fetch();

// Most active bidder = most distinct auctions entered
$stmtActive = $pdo->query(
    'SELECT b.name, COUNT(DISTINCT bi.auction_id) AS auction_count
     FROM bids bi
     JOIN bidders b ON b.id = bi.bidder_id
     GROUP BY bi.bidder_id, b.name
     ORDER BY auction_count DESC
     LIMIT 1'
);
$mostActive = $stmtActive->fetch();

$summaryOut = [
    'total_auctions'      => (int) $summary['total_auctions'],
    'total_bids'          => (int) $summary['total_bids'],
    'avg_clearing_price'  => $summary['avg_clearing_price'] !== null
                                ? (float) $summary['avg_clearing_price']
                                : null,
    'avg_margin'          => $summary['avg_margin'] !== null
                                ? (float) $summary['avg_margin']
                                : null,
    'most_active_bidder'  => $mostActive !== false
                                ? $mostActive['name']
                                : null,
    'highest_single_bid'  => $summary['highest_single_bid'] !== null
                                ? (float) $summary['highest_single_bid']
                                : null,
];

// ── 5. Recent results (last 10 closed auctions) ───────────────────────────────
$stmtRecent = $pdo->query(
    'SELECT
         a.slot_name,
         b.name          AS winner_name,
         ar.clearing_price,
         ar.winner_bid,
         ar.margin,
         ar.resolved_at
     FROM auction_results ar
     JOIN auctions a  ON a.id  = ar.auction_id
     JOIN bidders  b  ON b.id  = ar.winner_bidder_id
     ORDER BY ar.resolved_at DESC
     LIMIT 10'
);

$recentResults = [];
foreach ($stmtRecent->fetchAll() as $row) {
    $recentResults[] = [
        'slot_name'     => $row['slot_name'],
        'winner_name'   => $row['winner_name'],
        'clearing_price'=> (float) $row['clearing_price'],
        'winner_bid'    => (float) $row['winner_bid'],
        'margin'        => (float) $row['margin'],
        'resolved_at'   => $row['resolved_at'],
    ];
}

// ── CSV export ────────────────────────────────────────────────────────────────
$export = isset($_GET['export']) && $_GET['export'] === 'csv';

if ($export) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="analytics-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-cache');

    $out = fopen('php://output', 'w');

    fputcsv($out, ['# Win Rates']);
    fputcsv($out, ['Bidder', 'Wins', 'Total Bids', 'Win Rate %']);
    foreach ($winRates as $r) {
        fputcsv($out, [$r['bidder_name'], $r['wins'], $r['total_bids'], $r['win_rate_percent']]);
    }

    fputcsv($out, []);
    fputcsv($out, ['# CPM Trend (' . $days . ' days)']);
    fputcsv($out, ['Date', 'Avg CPM', 'Min CPM', 'Max CPM']);
    foreach ($cpmTrend as $r) {
        fputcsv($out, [$r['date'], $r['avg_cpm'], $r['min_cpm'], $r['max_cpm']]);
    }

    fputcsv($out, []);
    fputcsv($out, ['# Recent Results']);
    fputcsv($out, ['Slot', 'Winner', 'Clearing Price', 'Winner Bid', 'Margin', 'Resolved At']);
    foreach ($recentResults as $r) {
        fputcsv($out, [
            $r['slot_name'], $r['winner_name'],
            $r['clearing_price'], $r['winner_bid'], $r['margin'], $r['resolved_at'],
        ]);
    }

    fclose($out);
    exit;
}

// ── JSON Response ─────────────────────────────────────────────────────────────
jsonResponse([
    'period_days'      => $days,
    'win_rates'        => $winRates,
    'cpm_trend'        => $cpmTrend,
    'bid_distribution' => $bidDistribution,
    'summary'          => $summaryOut,
    'recent_results'   => $recentResults,
]);
