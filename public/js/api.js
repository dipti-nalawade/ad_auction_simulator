'use strict';

/* ── Base URL ─────────────────────────────────────────────────────────────────
   Resolved relative to the page URL (public/index.html → ../api/).          */
const API_BASE = '../api';

/* ── Toast notifications ──────────────────────────────────────────────────────
   Injects a self-removing toast into #toasts.
   type: 'info' | 'success' | 'error'                                         */
function notify(message, type = 'info') {
  const container = document.getElementById('toasts');
  if (!container) return;
  const el = document.createElement('div');
  el.className   = `toast ${type}`;
  el.textContent = message;
  container.appendChild(el);
  setTimeout(() => el.remove(), 3600);
}

/* ── Core fetch wrapper ───────────────────────────────────────────────────────
   Attaches Content-Type, parses JSON, and throws on non-2xx status.          */
async function apiFetch(path, options = {}) {
  const res  = await fetch(`${API_BASE}/${path}`, {
    headers: { 'Content-Type': 'application/json' },
    ...options,
  });
  const data = await res.json();
  if (!res.ok) throw new Error(data.error || `HTTP ${res.status}`);
  return data;
}

/* ── Bidders ──────────────────────────────────────────────────────────────────
   GET  /bidders.php              → array of bidder objects
   POST /bidders.php              → created bidder object
   DEL  /bidders.php?id=X        → {success, message}                         */

async function getBidders() {
  return apiFetch('bidders.php');
}

async function createBidder(name, budget) {
  return apiFetch('bidders.php', {
    method: 'POST',
    body:   JSON.stringify({ name, budget }),
  });
}

async function deleteBidder(id) {
  return apiFetch(`bidders.php?id=${id}`, { method: 'DELETE' });
}

/* ── Auctions ─────────────────────────────────────────────────────────────────
   GET   /auctions.php            → array of auction objects
   POST  /auctions.php            → created auction object
   PATCH /auctions.php            → {success, id, status}                     */

async function getAuctions() {
  return apiFetch('auctions.php');
}

async function createAuction(slotName, reservePrice) {
  return apiFetch('auctions.php', {
    method: 'POST',
    body:   JSON.stringify({ slot_name: slotName, reserve_price: reservePrice }),
  });
}

async function updateAuctionStatus(id, status) {
  return apiFetch('auctions.php', {
    method: 'PATCH',
    body:   JSON.stringify({ id, status }),
  });
}

/* ── Bids ─────────────────────────────────────────────────────────────────────
   POST /bid.php   → {action, auction_id, standings[]}                        */

async function placeBid(auctionId, bidderId, amount) {
  return apiFetch('bid.php', {
    method: 'POST',
    body:   JSON.stringify({ auction_id: auctionId, bidder_id: bidderId, amount }),
  });
}

/* ── Auction resolution ───────────────────────────────────────────────────────
   POST /run_auction.php → full result object with winner, participants, CPM   */

async function runAuction(auctionId) {
  return apiFetch('run_auction.php', {
    method: 'POST',
    body:   JSON.stringify({ auction_id: auctionId }),
  });
}

/* ── Results ──────────────────────────────────────────────────────────────────
   No dedicated GET /results endpoint exists. This function fetches analytics
   and returns recent_results; the auctionId parameter is accepted for
   forward-compatibility when a dedicated endpoint is added.                   */

async function getResults(auctionId) { // eslint-disable-line no-unused-vars
  const data = await getAnalytics();
  return data.recent_results ?? [];
}

/* ── Simulate ─────────────────────────────────────────────────────────────────
   POST /simulate.php → {auction_id, slot_name, simulated_bids[], result{}}
   num_bidders is optional; omitting it uses every bidder in the database.    */

async function simulateAuction(auctionId, numBidders = null) {
  const body = { auction_id: auctionId };
  if (numBidders !== null) body.num_bidders = numBidders;
  return apiFetch('simulate.php', {
    method: 'POST',
    body:   JSON.stringify(body),
  });
}

/* ── Bid history ──────────────────────────────────────────────────────────────
   GET /bids.php?auction_id=X → {auction, bids[], result|null}                */

async function getAuctionBids(auctionId) {
  return apiFetch(`bids.php?auction_id=${encodeURIComponent(auctionId)}`);
}

/* ── Analytics ────────────────────────────────────────────────────────────────
   GET /analytics.php?days=N → {win_rates, cpm_trend, bid_distribution,
                                 summary, recent_results}                      */

async function getAnalytics(days = 30) {
  return apiFetch(`analytics.php?days=${encodeURIComponent(days)}`);
}

/* Triggers a CSV download by navigating to the export URL.                   */
function downloadAnalyticsCsv(days = 30) {
  window.location.href = `${API_BASE}/analytics.php?export=csv&days=${encodeURIComponent(days)}`;
}
