-- =============================================================================
-- Seed data for Ad Auction Simulator
-- Safe to re-run: wipes existing rows before inserting.
-- All timestamps use DATE_SUB(NOW(), …) so data stays within the 30-day
-- analytics window regardless of when the script is executed.
-- =============================================================================

USE ad_auction_simulator;

-- ── Clear in FK-safe order ────────────────────────────────────────────────────
SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE cpm_log;
TRUNCATE TABLE auction_results;
TRUNCATE TABLE bids;
TRUNCATE TABLE auctions;
TRUNCATE TABLE bidders;
SET FOREIGN_KEY_CHECKS = 1;

-- ── Bidders ───────────────────────────────────────────────────────────────────
-- IDs are explicit so FK references below are deterministic.
INSERT INTO bidders (id, name, budget) VALUES
    (1, 'AdTech Corp',     500.00),
    (2, 'MediaBuy Inc',    400.00),
    (3, 'ProgrammaticPro', 600.00),
    (4, 'RetargetKing',    350.00),
    (5, 'BrandBoost',      450.00),
    (6, 'ClickFarm',       300.00);

-- ── Closed auctions ───────────────────────────────────────────────────────────
-- Three distinct days so the CPM trend chart plots a visible line.
INSERT INTO auctions (id, slot_name, reserve_price, status, created_at) VALUES
    (1, 'Homepage Hero Banner', 5.00, 'closed', DATE_SUB(NOW(), INTERVAL 28 DAY)),
    (2, 'Sidebar 300x250',      3.00, 'closed', DATE_SUB(NOW(), INTERVAL 14 DAY)),
    (3, 'Pre-roll Video 30s',   8.00, 'closed', DATE_SUB(NOW(), INTERVAL  5 DAY));

-- ── Bids — Auction 1: Homepage Hero Banner ────────────────────────────────────
-- Five bidders compete. ProgrammaticPro (id 3) submits the highest at $25.00.
-- Second-highest is AdTech Corp (id 1) at $22.50 → becomes the clearing price.
INSERT INTO bids (auction_id, bidder_id, amount, submitted_at) VALUES
    (1, 1, 22.50, DATE_SUB(NOW(), INTERVAL 28 DAY) + INTERVAL 10 MINUTE),
    (1, 2, 18.00, DATE_SUB(NOW(), INTERVAL 28 DAY) + INTERVAL 16 MINUTE),
    (1, 3, 25.00, DATE_SUB(NOW(), INTERVAL 28 DAY) + INTERVAL 23 MINUTE),
    (1, 4, 15.00, DATE_SUB(NOW(), INTERVAL 28 DAY) + INTERVAL 31 MINUTE),
    (1, 5, 19.50, DATE_SUB(NOW(), INTERVAL 28 DAY) + INTERVAL 39 MINUTE);

-- ── Bids — Auction 2: Sidebar 300x250 ────────────────────────────────────────
-- Four bidders. MediaBuy Inc (id 2) wins at $14.50.
-- AdTech Corp (id 1) at $12.00 is second → clearing price.
INSERT INTO bids (auction_id, bidder_id, amount, submitted_at) VALUES
    (2, 1, 12.00, DATE_SUB(NOW(), INTERVAL 14 DAY) + INTERVAL  7 MINUTE),
    (2, 2, 14.50, DATE_SUB(NOW(), INTERVAL 14 DAY) + INTERVAL 18 MINUTE),
    (2, 4, 11.00, DATE_SUB(NOW(), INTERVAL 14 DAY) + INTERVAL 25 MINUTE),
    (2, 6,  9.50, DATE_SUB(NOW(), INTERVAL 14 DAY) + INTERVAL 34 MINUTE);

-- ── Bids — Auction 3: Pre-roll Video 30s ─────────────────────────────────────
-- Five bidders. ProgrammaticPro (id 3) wins at $42.00.
-- BrandBoost (id 5) at $38.00 is second → clearing price.
INSERT INTO bids (auction_id, bidder_id, amount, submitted_at) VALUES
    (3, 1, 35.00, DATE_SUB(NOW(), INTERVAL 5 DAY) + INTERVAL 11 MINUTE),
    (3, 3, 42.00, DATE_SUB(NOW(), INTERVAL 5 DAY) + INTERVAL 20 MINUTE),
    (3, 4, 28.00, DATE_SUB(NOW(), INTERVAL 5 DAY) + INTERVAL 28 MINUTE),
    (3, 5, 38.00, DATE_SUB(NOW(), INTERVAL 5 DAY) + INTERVAL 36 MINUTE),
    (3, 6, 31.00, DATE_SUB(NOW(), INTERVAL 5 DAY) + INTERVAL 43 MINUTE);

-- ── Auction results ───────────────────────────────────────────────────────────
-- margin = winner_bid − clearing_price (verified per auction above).
-- resolved_at is a few minutes after the last bid in each auction.
INSERT INTO auction_results
    (auction_id, winner_bidder_id, clearing_price, winner_bid, margin, resolved_at)
VALUES
    --  ProgrammaticPro wins; clears at $22.50; saves $2.50
    (1, 3, 22.50, 25.00,  2.50, DATE_SUB(NOW(), INTERVAL 28 DAY) + INTERVAL 45 MINUTE),
    --  MediaBuy Inc wins;    clears at $12.00; saves $2.50
    (2, 2, 12.00, 14.50,  2.50, DATE_SUB(NOW(), INTERVAL 14 DAY) + INTERVAL 42 MINUTE),
    --  ProgrammaticPro wins; clears at $38.00; saves $4.00
    (3, 3, 38.00, 42.00,  4.00, DATE_SUB(NOW(), INTERVAL  5 DAY) + INTERVAL 50 MINUTE);

-- ── CPM log ───────────────────────────────────────────────────────────────────
-- cpm_value = (clearing_price / 1000) * 1000
-- Each entry is logged at the same moment its auction resolved.
INSERT INTO cpm_log (auction_id, cpm_value, logged_at) VALUES
    (1, 22.5000, DATE_SUB(NOW(), INTERVAL 28 DAY) + INTERVAL 45 MINUTE),
    (2, 12.0000, DATE_SUB(NOW(), INTERVAL 14 DAY) + INTERVAL 42 MINUTE),
    (3, 38.0000, DATE_SUB(NOW(), INTERVAL  5 DAY) + INTERVAL 50 MINUTE);
