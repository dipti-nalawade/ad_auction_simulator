# Ad Auction Simulator — Walkthrough & Technical Guide

A hands-on guide to the application's features, data flow, and implementation details.

---

## Table of Contents

1. [Overview](#overview)
2. [What Is a Second-Price Auction?](#what-is-a-second-price-auction)
3. [Application Layout](#application-layout)
4. [Panel-by-Panel Walkthrough](#panel-by-panel-walkthrough)
   - [Bidders Panel](#bidders-panel)
   - [Auctions Panel](#auctions-panel)
   - [Live Bid Board](#live-bid-board)
   - [Analytics Dashboard](#analytics-dashboard)
5. [Auction Lifecycle](#auction-lifecycle)
6. [Auto-Simulate Feature](#auto-simulate-feature)
7. [Real-Time Updates](#real-time-updates)
8. [Bid History Modal](#bid-history-modal)
9. [Analytics & CSV Export](#analytics--csv-export)
10. [Data Flow Diagrams](#data-flow-diagrams)
11. [Backend Deep-Dive](#backend-deep-dive)
12. [Frontend Architecture](#frontend-architecture)
13. [Database Schema Walkthrough](#database-schema-walkthrough)

---

## Overview

The Ad Auction Simulator is a full-stack web application that models how programmatic advertising auctions work in the real world. Advertisers (bidders) compete for ad slots. The highest bidder wins but pays only the second-highest bid — the **clearing price**. This is how Google Ads, The Trade Desk, and most major ad exchanges operate.

The simulator lets you:
- Create bidders with budgets and auctions with reserve prices
- Place manual bids and watch a live standings board update in real time
- Run the second-price resolution logic and see the winner revealed with animation
- Use Auto-Simulate to run a full auction pipeline in one click
- Explore an analytics dashboard with CPM trends, win rates, and bid distribution charts

---

## What Is a Second-Price Auction?

In a **first-price auction** the winner pays exactly what they bid. This creates incentives to shade bids — bid less than your true value to preserve margin.

In a **second-price (Vickrey) auction** the winner pays the second-highest bid. This means your dominant strategy is always to bid your true value: bidding higher can't lower your price, and bidding lower risks losing an auction you would have won.

```
Example:
  Bidder A bids $45.00  ← winner
  Bidder B bids $38.00  ← sets the price
  Bidder C bids $22.00

  Winner: Bidder A
  Clearing price: $38.00  (not $45.00)
  Margin: $7.00
```

Edge case — if only one bid is placed, the winner pays the **reserve price** (the floor set by the publisher).

---

## Application Layout

The dashboard is a single page divided into four panels arranged in a CSS Grid:

```
┌─────────────────────────────────────────────────────────────────┐
│  HEADER — Live clock · connection status · Auto-Simulate button │
├──────────────────┬──────────────────────┬───────────────────────┤
│   BIDDERS        │   LIVE BID BOARD     │   AUCTIONS            │
│                  │                      │                        │
│  • Bidder table  │  • Active standings  │  • Auction list        │
│  • Add form      │  • Place Bid form    │  • Create form         │
│                  │  • Run Auction btn   │                        │
│                  │  • Result card       │                        │
├──────────────────┴──────────────────────┴───────────────────────┤
│   ANALYTICS                                                      │
│   • Summary KPIs · CPM trend · Win rates · Bid distribution      │
│   • Recent results table · CSV Export                           │
└─────────────────────────────────────────────────────────────────┘
```

At 768 px and below the panels stack vertically — all functionality remains accessible on mobile.

---

## Panel-by-Panel Walkthrough

### Bidders Panel

Displays all registered bidders in a table with:

| Column | Description |
|--------|-------------|
| Name   | Bidder display name |
| Budget | Total advertising budget (displayed in green) |
| W      | Total auction wins |
| B      | Total auctions bid on |
| ✕      | Delete button — cascades to all bids |

**Adding a bidder** — fill in Name and Budget, then submit. Client-side validation fires before the API call:
- Name is required and capped at 100 characters
- Budget must be a positive number

On success a toast notification appears and the table refreshes.

**Deleting a bidder** — prompts for confirmation, then calls `DELETE /api/bidders.php?id=X`. Because the `bids` table has a `CASCADE` foreign key, all of the bidder's bids are removed automatically.

---

### Auctions Panel

Lists every auction with its current status badge, reserve price, bid count, and top bid. Each row is clickable — clicking opens the [Bid History Modal](#bid-history-modal).

Status badges:

| Badge    | Meaning |
|----------|---------|
| `pending`  | Created but not yet accepting bids |
| `active`   | Open for bidding |
| `closed`   | Resolved — has a winner |

**Lifecycle buttons appear inline:**
- `Activate` — transitions `pending → active`
- `Close` — transitions `active → closed` (without resolving — use "Run Auction" to resolve with second-price logic)

**Creating an auction** — requires a Slot Name (the ad placement label, e.g. "Leaderboard 728×90") and a Reserve Price. The reserve price is the minimum the winner will pay even with a single bid.

---

### Live Bid Board

The center panel is the interactive core of the application.

1. **Select an active auction** from the dropdown.
2. The `RealtimeUpdater` starts — it opens an SSE stream to `api/events.php` and calls the refresh function whenever the server signals a change. If SSE is unavailable it falls back to 3-second polling.
3. **Choose a bidder** and enter a bid amount, then click **Place Bid**.
   - If the bidder has already bid on this auction, the bid is updated (UPSERT).
   - The API response includes the full live standings (all bids ranked), which are immediately rendered in the board table.
4. The standings table shows position, bidder name, amount, and submission time. The current leader's position badge is highlighted.
5. **Run Auction** — resolves the auction using second-price logic. A confirmation dialog prevents accidental resolution. On success:
   - The result card animates in showing the winner, clearing price, winner's bid, margin, and full participant table.
   - The auction status changes to `closed`.
   - Bidders, auctions, and analytics panels all refresh.

---

### Analytics Dashboard

Four data visualizations powered by Chart.js 4:

| Chart | Data | Type |
|-------|------|------|
| CPM Trend | Daily average/min/max clearing price over the last 30 days | Line |
| Win Rates | Win percentage per bidder | Bar (horizontal) |
| Bid Distribution | Bid count per price bucket ($0–5, $5–10, $10–20, $20–50, $50+) | Bar |
| Recent Results | Last 10 resolved auctions — slot, winner, clearing price, margin | Table |

**Summary KPI strip** shows total auctions, total bids placed, and average clearing price across all time.

The **Refresh** button reloads all chart data on demand. **Export CSV** downloads a multi-section spreadsheet containing win rates, CPM trend data, and recent results.

---

## Auction Lifecycle

```
                  POST /api/auctions.php
                  {slot_name, reserve_price}
                          │
                          ▼
                    status: pending
                          │
          PATCH /api/auctions.php {id, status: "active"}
                          │
                          ▼
                    status: active  ◄──────────────────────┐
                          │                                 │
          POST /api/bid.php                           (bids update
          {auction_id, bidder_id, amount}              via UPSERT)
                          │                                 │
                          └─────────────────────────────────┘
                          │
          POST /api/run_auction.php  (or via simulate.php)
          {auction_id}
                          │
                          ▼
             Second-price logic executes:
             • bids ranked by amount DESC
             • winner = bids[0]
             • clearing = bids[1].amount  (or reserve if 1 bid)
             • margin = winner_bid − clearing_price
             • atomic transaction writes auction_results + cpm_log
             • auction status → "closed"
                          │
                          ▼
                    status: closed
                  (result card rendered)
```

---

## Auto-Simulate Feature

Auto-Simulate runs the full pipeline in one click — useful for demos and seeding analytics data quickly.

**Steps executed sequentially:**

1. **Create auction** — picks a random slot name from 12 real-world ad format names (e.g. "Pre-roll 15s", "Half Page 300×600") and a random reserve price between $3 and $15.
2. **Activate** — transitions the new auction to `active`.
3. **Generate bids** (`POST /api/simulate.php`) — for every bidder in the system, generates a budget-weighted random bid:
   - Bid range: `[reserve_price, reserve_price × 3]`
   - Bidders with larger budgets are biased toward the upper end of the range using `biasedRandom()` — two random samples blended by the bidder's budget fraction relative to the pool maximum.
   - This produces realistic competitive dynamics: richer bidders tend to win more, but anyone can win on a given auction.
4. **Resolve** — second-price logic runs inside `simulate.php` (no separate call to `run_auction.php` needed).

A progress overlay shows four animated steps with live status indicators (pending → active → done). After resolution the bid board and winner card animate in before all panels refresh.

---

## Real-Time Updates

The `RealtimeUpdater` class in `realtime.js` abstracts away the transport layer:

```
Browser                          Server
  │                                │
  │── GET /api/events.php ────────►│  (SSE connection kept open)
  │                                │
  │◄── event: update ─────────────│  (server signals DB change)
  │                                │
  │── GET /api/auctions.php ──────►│  (refresh auction list)
  │◄── JSON ──────────────────────│
  │                                │
  │  [render updated bid board]    │
```

If `EventSource` is unavailable (some corporate proxies strip SSE) or the connection errors, `RealtimeUpdater` switches permanently to 3-second polling — same refresh function, different trigger mechanism.

The `RealtimeUpdater` instance is scoped to a single auction selection. When the user switches auctions or navigates away, `.stop()` closes the SSE connection and clears any polling timer, preventing ghost updates.

---

## Bid History Modal

Clicking any auction row (pending, active, or closed) opens a modal overlay showing:

- Auction metadata: reserve price, status badge, creation time
- Full chronological bid timeline: sequence number, bidder, amount, submission time
- Result section (closed auctions only): winner name, clearing price, margin

This is distinct from the live bid board — it shows the historical record from `api/bids.php` rather than live standings. The modal closes by clicking the backdrop.

---

## Analytics & CSV Export

`GET /api/analytics.php?days=30` runs five independent SQL queries:

1. **Win rates** — LEFT JOIN from `bidders` → `bids` → `auction_results`, grouped by bidder, calculating `wins / distinct_auctions_entered`
2. **CPM trend** — `cpm_log` grouped by day, with avg/min/max per day, filtered to the last N days
3. **Bid distribution** — `bids` bucketed with a `CASE` statement into five price ranges; all five buckets always appear in the response even if empty
4. **Summary** — single-row aggregate across `auctions`, `bids`, `auction_results`
5. **Recent results** — last 10 rows from `auction_results` joined to auction and bidder names

`GET /api/analytics.php?export=csv` runs the same queries then writes a multi-section CSV directly to `php://output` with appropriate download headers. The filename includes today's date.

---

## Data Flow Diagrams

### Placing a Bid

```
User fills bid form
       │
       ▼
_validateBidForm()  ──► error shown inline if invalid
       │ valid
       ▼
POST /api/bid.php
{auction_id, bidder_id, amount}
       │
       ▼  (PHP)
SELECT existing bid for (auction_id, bidder_id)
       │
   exists? ──NO──► INSERT INTO bids
       │
      YES
       │
       ▼
UPDATE bids SET amount=... WHERE auction_id=... AND bidder_id=...
       │
       ▼
SELECT all bids for auction, ranked by amount DESC
       │
       ▼
Return {action: "created"|"updated", standings: [...]}
       │
       ▼  (JS)
_cachedStandings = data.standings
Render bid board table
Show toast notification
```

### Running an Auction

```
User clicks "Run Auction" → confirmation dialog
       │ confirmed
       ▼
_realtime.stop()
POST /api/run_auction.php {auction_id}
       │
       ▼  (PHP)
Verify auction is active
Fetch all bids ORDER BY amount DESC with RANK() window function
       │
       ▼
winner       = bids[0]
clearingPrice = (count == 1) ? reserve_price : bids[1].amount
margin        = winner.amount − clearingPrice
cpm           = clearingPrice
       │
       ▼
BEGIN TRANSACTION
  INSERT auction_results (winner, clearing_price, winner_bid, margin)
  INSERT cpm_log (auction_id, cpm_value)
  UPDATE auctions SET status = 'closed'
COMMIT
       │
       ▼
Return full result object
       │
       ▼  (JS)
_showResult(result) → animate result card
loadBidders() + loadAuctions() + loadAnalytics()
```

---

## Backend Deep-Dive

### `config/db.php`

PDO factory that reads credentials from environment variables (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`). No credentials are hardcoded. Connection uses `PDO::ATTR_ERRMODE = EXCEPTION` and `PDO::FETCH_ASSOC`.

### `api/bid.php`

UPSERT logic without a MySQL `ON DUPLICATE KEY` clause — PHP checks for an existing row first, then branches to INSERT or UPDATE. This is explicit and easy to follow; the tradeoff is two queries instead of one, which is acceptable for a single-user simulation.

Returns `standings` — a ranked snapshot of all current bids — as a side effect of every POST. This avoids a separate GET call from the client to refresh the board.

### `api/run_auction.php`

Uses a MySQL window function (`RANK() OVER (ORDER BY amount DESC)`) to assign positions in one query. The entire result persistence — inserting into `auction_results`, inserting into `cpm_log`, and closing the auction — is wrapped in a single `beginTransaction() / commit()` block. If any step fails, `rollBack()` prevents a partial write.

### `api/simulate.php`

Combines bid generation and auction resolution in one endpoint. The `biasedRandom()` function takes `$min`, `$max`, and `$factor` (0.0–1.0):

```php
function biasedRandom(float $min, float $max, float $factor): float
{
    $r1 = $min + (mt_rand() / mt_getrandmax()) * ($max - $min);  // uniform
    $r2 = $min + (mt_rand() / mt_getrandmax()) * ($max - $min);  // uniform
    return round($r1 + ($r2 - $r1) * $factor, 2);               // blend
}
```

`$factor = bidder_budget / max_budget_in_pool`. A bidder with the highest budget gets `$factor = 1.0` → pure `$r2` draw, uniformly distributed across the full range. The leanest bidder gets `$factor ≈ 0.0` → pure `$r1`. Intermediate factors blend the two draws, shifting the probability mass smoothly.

### `api/events.php`

Implements SSE by:
1. Setting `Content-Type: text/event-stream` and disabling output buffering
2. Polling `MAX(created_at)` across `bids` and `auctions` in a loop
3. Sending `event: update\ndata: 1\n\n` when the timestamp changes
4. Sleeping 1 second between polls

This keeps the SSE stream stateless — no pub/sub infrastructure needed.

---

## Frontend Architecture

Four JavaScript modules loaded in order in `index.html`:

| File | Responsibility |
|------|---------------|
| `api.js` | `fetch` wrapper with JSON error handling; one function per API endpoint |
| `realtime.js` | `RealtimeUpdater` class — SSE connection with polling fallback |
| `charts.js` | Chart.js initialization and update helpers; destroy-before-recreate pattern prevents canvas reuse errors |
| `app.js` | All UI state, DOM rendering, form validation, event wiring, boot sequence |

`app.js` is the coordinator. It holds two module-level arrays (`_bidders`, `_auctions`) that are the single source of truth for rendered state. All render functions read from these arrays rather than the DOM.

Functions exposed to the global `window` object (e.g. `handleDeleteBidder`, `handleSetStatus`, `openBidHistoryModal`) are necessary because `onclick` attributes in dynamically-generated HTML resolve names on `window` rather than module scope.

The `notify()` function (defined in `api.js`) creates a toast notification element, appends it to the body, and removes it after 3 seconds via a CSS transition.

---

## Database Schema Walkthrough

```
bidders
  id            PK, auto-increment
  name          VARCHAR(255)
  budget        DECIMAL(12,2)
  created_at    TIMESTAMP

auctions
  id            PK
  slot_name     VARCHAR(255)      — the ad placement label
  reserve_price DECIMAL(12,2)     — minimum clearing price
  status        ENUM(pending, active, closed)
  created_at    TIMESTAMP

bids
  id            PK
  auction_id    FK → auctions(id) CASCADE DELETE
  bidder_id     FK → bidders(id)  CASCADE DELETE
  amount        DECIMAL(12,2)
  submitted_at  TIMESTAMP         — updated on each UPSERT

  Note: no UNIQUE constraint on (auction_id, bidder_id) —
  UPSERT is handled in PHP by checking for an existing row first.

auction_results
  id                PK
  auction_id        FK UNIQUE → auctions(id)   — one result per auction
  winner_bidder_id  FK → bidders(id)
  clearing_price    DECIMAL(12,2)
  winner_bid        DECIMAL(12,2)
  margin            DECIMAL(12,2)   = winner_bid − clearing_price
  resolved_at       TIMESTAMP

cpm_log
  id          PK
  auction_id  FK → auctions(id) CASCADE DELETE
  cpm_value   DECIMAL(12,4)     — equals clearing_price (stored for trend queries)
  logged_at   TIMESTAMP
```

The `cpm_log` table is separate from `auction_results` to allow multiple CPM entries per auction in future scenarios (e.g. floor price experiments) without altering the results schema. Currently one row is written per resolved auction.

Cascade deletes propagate cleanly: deleting a bidder removes their bids; deleting an auction removes its bids, result, and CPM log entry.

---

## Running Locally

```bash
# 1. Clone and configure
cp .env.example .env
export DB_HOST=localhost DB_NAME=ad_auction_simulator DB_USER=root DB_PASS=secret

# 2. Set up database (schema + seed data)
chmod +x setup.sh && ./setup.sh

# 3. Start the PHP dev server
php -S localhost:8080 router.php

# 4. Open
open http://localhost:8080
```

The seed data provides 6 bidders, 3 pre-resolved auctions, and 28 days of CPM history so charts render immediately without needing to run auctions first.

`router.php` handles the dev-server routing — mapping `/api/*` requests to the correct PHP files from the project root, since `public/` is the document root.
