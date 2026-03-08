# Ad Auction Simulator

A full-stack simulation of a **second-price (Vickrey-style) programmatic ad auction**, built with vanilla PHP, MySQL, and JavaScript — no frameworks, no ORMs, no bundlers.

Bidders compete for ad slots in real time. The winner pays the second-highest bid (the clearing price), not their own bid — exactly how RTB (Real-Time Bidding) works in production ad exchanges.

---

## Features

- **Second-price auction engine** — winner pays clearing price, not their own bid
- **Live bid board** powered by Server-Sent Events (SSE) with automatic polling fallback
- **Auto-Simulate** — one click creates an auction, generates budget-weighted random bids for all bidders, resolves the winner, and plays an animated reveal
- **Analytics dashboard** — CPM trend (line chart), win rates (bar chart), bid distribution (bar chart), summary KPIs
- **CSV export** — download all analytics data in one click
- **Bid history modal** — click any auction row to see the full chronological bid timeline and final result
- **Inline form validation** — client-side field errors before any API call reaches the server
- **Mobile responsive** — panels stack vertically at 768 px; all functionality preserved
- **Seed data** — 6 bidders, 3 pre-resolved auctions, and 28 days of CPM data so charts render immediately

---

## Tech Stack

| Layer      | Choice                                                        |
|------------|---------------------------------------------------------------|
| Backend    | PHP 8.1+ · `declare(strict_types=1)` · PDO (no ORM)         |
| Database   | MySQL 8 · window functions · atomic transactions             |
| Frontend   | Vanilla JS (ES2022) · no frameworks                          |
| Charts     | Chart.js 4                                                    |
| Real-time  | Server-Sent Events (`EventSource`) → polling fallback         |
| Styling    | CSS custom properties · CSS Grid · `@keyframes` animations    |
| Font       | JetBrains Mono (Google Fonts)                                 |

---

## Architecture

```
ad_auction_simulator/
├── api/
│   ├── analytics.php     # GET  analytics (win rates, CPM trend, distribution) + ?export=csv
│   ├── auctions.php      # GET list / POST create / PATCH status
│   ├── bid.php           # POST place or update a bid → returns live standings
│   ├── bidders.php       # GET list / POST create / DELETE
│   ├── bids.php          # GET bid history for one auction (modal)
│   ├── events.php        # SSE stream — pushes "update" events on DB changes
│   ├── run_auction.php   # POST resolve auction (second-price logic + CPM log)
│   └── simulate.php      # POST auto-generate budget-weighted bids + resolve
├── config/
│   └── db.php            # PDO factory — reads env vars, no hardcoded credentials
├── public/
│   ├── index.html        # Single-page dashboard (CSS Grid, dark theme)
│   └── js/
│       ├── api.js        # fetch wrapper + all API calls
│       ├── app.js        # UI state, event wiring, modal, form validation
│       ├── charts.js     # Chart.js helpers (destroy-before-recreate pattern)
│       └── realtime.js   # RealtimeUpdater class (SSE → polling fallback)
├── sql/
│   ├── schema.sql        # CREATE TABLE statements (idempotent)
│   └── seed.sql          # Sample bidders, auctions, results, CPM history
├── .env.example
├── setup.sh              # One-shot: schema + seed + connectivity check
└── LICENSE
```

---

## Auction Logic

```
1.  Auction created   →  status: pending
2.  Auction activated →  status: active   (bids accepted)
3.  Bids submitted    →  stored with UPSERT (each bidder has one live bid)
4.  Auction resolved  →  status: closed

    if bids == 1:   clearing_price = reserve_price
    if bids >= 2:   clearing_price = second_highest_bid

    winner pays clearing_price  (not their own bid)
    margin = winner_bid − clearing_price
    CPM    = clearing_price  (logged to cpm_log)
```

---

## API Reference

| Method | Endpoint                            | Description                               |
|--------|-------------------------------------|-------------------------------------------|
| GET    | `/api/bidders.php`                  | List all bidders with win/bid counts      |
| POST   | `/api/bidders.php`                  | Create bidder `{name, budget}`            |
| DELETE | `/api/bidders.php?id=X`             | Delete bidder (cascades bids)             |
| GET    | `/api/auctions.php`                 | List auctions with bid count + top bid    |
| POST   | `/api/auctions.php`                 | Create auction `{slot_name, reserve_price}` |
| PATCH  | `/api/auctions.php`                 | Update status `{id, status}`              |
| POST   | `/api/bid.php`                      | Place/update bid → returns standings      |
| GET    | `/api/bids.php?auction_id=X`        | Full bid timeline + result                |
| POST   | `/api/run_auction.php`              | Resolve auction, record winner + CPM      |
| POST   | `/api/simulate.php`                 | Auto-generate bids + resolve              |
| GET    | `/api/analytics.php?days=30`        | Charts data + summary KPIs               |
| GET    | `/api/analytics.php?export=csv`     | Download analytics as CSV                 |
| GET    | `/api/events.php`                   | SSE stream for real-time updates          |

---

## Quick Start

### Requirements

- PHP 8.1+
- MySQL 8.0+

### 1. Clone

```bash
git clone https://github.com/dipti-nalawade/ad_auction_simulator.git
cd ad_auction_simulator
```

### 2. Configure credentials

```bash
cp .env.example .env
# Edit .env with your MySQL credentials, then:
export DB_HOST=localhost
export DB_NAME=ad_auction_simulator
export DB_USER=your_user
export DB_PASS=your_password
```

### 3. Run setup (schema + seed data)

```bash
chmod +x setup.sh
./setup.sh
```

Creates the database, applies the schema, and loads seed data with 28 days of pre-built auction history so the charts render immediately on first load.

### 4. Start the dev server

```bash
php -S localhost:8080 -t public
```

Open **http://localhost:8080**

---

## Design Decisions

**Why no framework?**
The goal was to demonstrate full-stack fundamentals — raw SQL with window functions, PDO prepared statements, PHP strict types, and DOM manipulation — without abstraction layers hiding the mechanics.

**Why second-price auctions?**
Second-price (Vickrey) auctions are the dominant model in programmatic advertising (Google Ads, The Trade Desk, etc.) because they incentivise truthful bidding: your dominant strategy is always to bid your true value.

**Why SSE over WebSockets?**
The data flow is one-directional (server → clients). SSE is simpler, works over standard HTTP, auto-reconnects natively, and doesn't require a persistent socket server. The `RealtimeUpdater` class falls back to polling for environments where SSE is blocked by a proxy.

**Why budget-weighted bid simulation?**
Real advertisers with larger budgets typically bid more aggressively. The `biasedRandom()` function blends two uniform random draws weighted by each bidder's budget fraction relative to the pool maximum, producing realistic competitive dynamics without hard caps.

---

## Database Schema

| Table             | Purpose                                           |
|-------------------|---------------------------------------------------|
| `bidders`         | Participants with name and budget                 |
| `auctions`        | Ad slots with reserve price and lifecycle status  |
| `bids`            | One active bid per bidder per auction (UPSERT)    |
| `auction_results` | Winner, clearing price, margin (UNIQUE auction)   |
| `cpm_log`         | CPM value recorded per resolved auction           |

---

## License

[MIT](LICENSE)
