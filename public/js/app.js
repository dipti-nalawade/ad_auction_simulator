'use strict';

/* ── State ────────────────────────────────────────────────────────────────────
   Module-level variables; never mutated by api.js or charts.js.              */
let _bidders         = [];
let _auctions        = [];
let _realtime        = null;   // RealtimeUpdater instance (replaces setInterval)
// bid.php standings are returned as a side-effect of POST; we cache the last
// response and redisplay it on every 3-second poll tick.
let _cachedStandings = null;
let _cachedAuId      = null;

/* ── DOM helpers ──────────────────────────────────────────────────────────────*/
const $ = id => document.getElementById(id);

function esc(s) {
  return String(s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function fmt(n, decimals = 2)  { return Number(n).toFixed(decimals); }
function fmtTime(ts)           { return new Date(ts).toLocaleTimeString('en-GB', { hour12: false }); }
function spin(id, on)          { const el = $(id); if (el) el.style.display = on ? 'inline-block' : 'none'; }

/* ── Clock ────────────────────────────────────────────────────────────────────*/
function _startClock() {
  setInterval(() => {
    $('clock').textContent = new Date().toLocaleTimeString('en-GB', { hour12: false });
  }, 1000);
}

/* ── Connection status ────────────────────────────────────────────────────────
   Pings the bidders endpoint; updates the header indicator.                  */
async function _checkConnection() {
  const dot   = $('conn-dot');
  const label = $('conn-label');
  try {
    await getBidders();
    dot.className     = 'conn-dot online';
    label.textContent = 'connected';
  } catch {
    dot.className     = 'conn-dot offline';
    label.textContent = 'offline';
  }
}

/* ── Bidders ──────────────────────────────────────────────────────────────────*/
async function loadBidders() {
  spin('sp-bidders', true);
  try {
    _bidders = await getBidders();
    _renderBidders();
    _renderBidderSelect();
  } catch (e) {
    notify(e.message, 'error');
  } finally {
    spin('sp-bidders', false);
  }
}

function _renderBidders() {
  const wrap = $('bidders-wrap');
  if (!_bidders.length) {
    wrap.innerHTML = '<div class="empty">No bidders yet</div>';
    return;
  }
  wrap.innerHTML = `
    <table>
      <thead><tr><th>Name</th><th>Budget</th><th>W</th><th>B</th><th></th></tr></thead>
      <tbody>
        ${_bidders.map(b => `
          <tr>
            <td>${esc(b.name)}</td>
            <td class="green">$${fmt(b.budget)}</td>
            <td>${b.total_wins}</td>
            <td>${b.total_bids}</td>
            <td><button class="btn-danger" onclick="handleDeleteBidder(${b.id})">✕</button></td>
          </tr>`).join('')}
      </tbody>
    </table>`;
}

function _renderBidderSelect() {
  const sel = $('bidder-select');
  const cur = sel.value;
  sel.innerHTML = '<option value="">— bidder —</option>' +
    _bidders.map(b => `<option value="${b.id}">${esc(b.name)}</option>`).join('');
  if (cur) sel.value = cur;
}

// Exposed globally for onclick attribute in dynamically-generated HTML
async function handleDeleteBidder(id) {
  const b = _bidders.find(x => x.id === id);
  if (!confirm(`Delete "${b?.name}" and all their bids?`)) return;
  try {
    await deleteBidder(id);
    notify('Bidder deleted', 'success');
    await Promise.all([loadBidders(), loadAuctions()]);
  } catch (e) {
    notify(e.message, 'error');
  }
}

/* ── Auctions ─────────────────────────────────────────────────────────────────*/
async function loadAuctions() {
  spin('sp-auctions', true);
  try {
    _auctions = await getAuctions();
    _renderAuctionList();
    _syncAuctionSelect();
  } catch (e) {
    notify(e.message, 'error');
  } finally {
    spin('sp-auctions', false);
  }
}

function _renderAuctionList() {
  const wrap = $('auctions-wrap');
  if (!_auctions.length) {
    wrap.innerHTML = '<div class="empty">No auctions yet</div>';
    return;
  }
  wrap.innerHTML = _auctions.map(a => `
    <div class="auction-item" onclick="openBidHistoryModal(${a.id})" style="cursor:pointer" title="Click to view bid history">
      <div class="auction-item-hd">
        <span class="auction-slot-name">${esc(a.slot_name)}</span>
        <span class="badge badge-${a.status}">${a.status}</span>
      </div>
      <div class="auction-meta">
        <span>Reserve: $${fmt(a.reserve_price)}</span>
        <span>Bids: ${a.bid_count}</span>
        ${a.top_bid !== null ? `<span>Top: <span class="green">$${fmt(a.top_bid)}</span></span>` : ''}
      </div>
      <div class="auction-actions">
        ${a.status === 'pending' ? `<button class="btn-sm"   onclick="event.stopPropagation();handleSetStatus(${a.id},'active')">Activate</button>` : ''}
        ${a.status === 'active'  ? `<button class="btn-warn" onclick="event.stopPropagation();handleSetStatus(${a.id},'closed')">Close</button>`    : ''}
      </div>
    </div>`).join('');
}

function _syncAuctionSelect() {
  const sel    = $('auction-select');
  const curId  = sel.value;
  const active = _auctions.filter(a => a.status === 'active');

  sel.innerHTML = '<option value="">— select an active auction —</option>' +
    active.map(a =>
      `<option value="${a.id}">${esc(a.slot_name)} · reserve $${fmt(a.reserve_price)}</option>`
    ).join('');

  // Restore selection if the auction is still active
  if (curId && active.find(a => String(a.id) === curId)) sel.value = curId;

  onAuctionSelected();
}

// Exposed globally for onclick in dynamic HTML
async function handleSetStatus(id, status) {
  try {
    await updateAuctionStatus(id, status);
    notify(`Auction set to ${status}`, 'success');
    await loadAuctions();
  } catch (e) {
    notify(e.message, 'error');
  }
}

/* ── Bid board ────────────────────────────────────────────────────────────────
   Selection change → start RealtimeUpdater (SSE with polling fallback).
   Poll fetches auctions.php (GET) for liveness; full standings come from the
   last bid POST response (cached in _cachedStandings).                       */
function onAuctionSelected() {
  const id = $('auction-select').value;

  if (_realtime) { _realtime.stop(); _realtime = null; }
  $('auction-result').classList.add('hidden');

  $('place-bid-btn').disabled   = !id;
  $('run-auction-btn').disabled = !id;

  if (!id) {
    $('bid-board').innerHTML        = '<div class="empty">Select an active auction</div>';
    $('last-refreshed').textContent = '—';
    return;
  }

  // Clear cached standings when switching to a different auction
  if (_cachedAuId !== id) {
    _cachedStandings = null;
    _cachedAuId      = id;
  }

  _refreshBidBoard(id);
  _realtime = new RealtimeUpdater(() => _refreshBidBoard(id));
  _realtime.start();
}

async function _refreshBidBoard(auctionId) {
  try {
    _auctions = await getAuctions();
    _renderAuctionList();

    const auction = _auctions.find(a => String(a.id) === String(auctionId));
    const board   = $('bid-board');

    // Auction closed externally — stop polling and disable controls
    if (!auction || auction.status !== 'active') {
      clearInterval(_bidTimer);
      board.innerHTML               = '<div class="empty dim">Auction is no longer active</div>';
      $('place-bid-btn').disabled   = true;
      $('run-auction-btn').disabled = true;
      return;
    }

    $('last-refreshed').textContent = `updated ${new Date().toLocaleTimeString()}`;

    if (_cachedStandings && _cachedAuId === String(auctionId)) {
      board.innerHTML = _buildBidTable(_cachedStandings);
    } else if (auction.bid_count === 0) {
      board.innerHTML = '<div class="empty">No bids yet — be first!</div>';
    } else {
      // Partial info before a bid has been placed this session
      board.innerHTML = `
        <div class="empty">
          ${auction.bid_count} bid(s) &middot; top <span class="green">$${fmt(auction.top_bid)}</span>
          <br><span class="dim" style="font-size:10px">Place or update a bid to see full standings</span>
        </div>`;
    }
  } catch {
    // Suppress — avoids toast spam on every failed poll tick
  }
}

function _buildBidTable(standings) {
  return `
    <table>
      <thead><tr><th>#</th><th>Bidder</th><th>Amount</th><th>Time</th></tr></thead>
      <tbody>
        ${standings.map(s => `
          <tr>
            <td><span class="pos-badge ${s.position === 1 ? 'p1' : ''}">${s.position}</span></td>
            <td>${esc(s.bidder_name)}</td>
            <td class="green">$${fmt(s.amount)}</td>
            <td class="dim">${fmtTime(s.submitted_at)}</td>
          </tr>`).join('')}
      </tbody>
    </table>`;
}

async function handlePlaceBid() {
  const auctionId = $('auction-select').value;
  const bidderId  = $('bidder-select').value;
  const amount    = $('bid-amount').value;

  if (!auctionId || !bidderId || !amount) {
    notify('Select auction, bidder, and enter an amount', 'error');
    return;
  }

  try {
    const data = await placeBid(
      parseInt(auctionId, 10),
      parseInt(bidderId, 10),
      parseFloat(amount),
    );
    notify(`Bid ${data.action} — $${fmt(amount)}`, 'success');
    _cachedStandings = data.standings;
    _cachedAuId      = String(auctionId);
    $('bid-board').innerHTML = _buildBidTable(data.standings);
    $('bid-amount').value = '';
  } catch (e) {
    notify(e.message, 'error');
  }
}

/* ── Run auction ──────────────────────────────────────────────────────────────
   Resolves the auction, reveals the result card with animation, then refreshes
   all panels and resets the center panel.                                     */
async function handleRunAuction() {
  const auctionId = $('auction-select').value;
  if (!auctionId) return;
  if (!confirm('Resolve this auction now? This cannot be undone.')) return;

  if (_realtime) { _realtime.stop(); _realtime = null; }

  try {
    const result = await runAuction(parseInt(auctionId, 10));
    _showResult(result);
    notify('Auction resolved!', 'success');

    _cachedStandings = null;
    await Promise.all([loadBidders(), loadAuctions(), loadAnalytics()]);
    $('auction-select').value = '';
    onAuctionSelected();
  } catch (e) {
    notify(e.message, 'error');
  }
}

/* Animate the result card by forcing a reflow before re-adding the class.    */
function _showResult(r) {
  const el = $('auction-result');
  el.classList.add('hidden');
  void el.offsetWidth;           // force reflow to restart CSS animation
  el.classList.remove('hidden');

  el.innerHTML = `
    <div class="result-winner">&#x1F3C6; ${esc(r.winner.bidder_name)}</div>
    <div class="result-subtitle">WINNER &mdash; ${esc(r.slot_name)}</div>
    <div class="stat-grid">
      <div class="stat-box">
        <div class="stat-val">$${fmt(r.clearing_price)}</div>
        <div class="stat-label">Clearing</div>
      </div>
      <div class="stat-box">
        <div class="stat-val">$${fmt(r.winner.bid)}</div>
        <div class="stat-label">Winner Bid</div>
      </div>
      <div class="stat-box">
        <div class="stat-val">$${fmt(r.margin)}</div>
        <div class="stat-label">Margin</div>
      </div>
    </div>
    <table>
      <thead><tr><th>#</th><th>Bidder</th><th>Bid</th><th>Result</th></tr></thead>
      <tbody>
        ${(r.participants ?? []).map(p => `
          <tr class="${p.is_winner ? 'winner-row' : ''}">
            <td>${p.position}</td>
            <td>${esc(p.bidder_name)}</td>
            <td>$${fmt(p.amount)}</td>
            <td>${p.is_winner ? '&#x2713; WON' : '&mdash;'}</td>
          </tr>`).join('')}
      </tbody>
    </table>`;
}

/* ── Analytics ────────────────────────────────────────────────────────────────
   Fetches all analytics data, updates the three Chart.js charts, renders the
   summary strip and recent-results table.                                     */
async function loadAnalytics() {
  const btn = $('refresh-analytics-btn');
  btn.textContent = '↺ Loading…';
  btn.disabled    = true;
  try {
    const d = await getAnalytics(30);
    _renderSummary(d.summary);
    updateCpmChart(d.cpm_trend);
    updateWinRateChart(d.win_rates);
    updateDistributionChart(d.bid_distribution);
    _renderRecentResults(d.recent_results);
  } catch (e) {
    notify('Analytics: ' + e.message, 'error');
  } finally {
    btn.textContent = '↺ Refresh';
    btn.disabled    = false;
  }
}

function _renderSummary(s) {
  $('summary-strip').innerHTML = `
    <div class="summary-cell">
      <div class="summary-val">${s.total_auctions}</div>
      <div class="summary-label">Auctions</div>
    </div>
    <div class="summary-cell">
      <div class="summary-val">${s.total_bids}</div>
      <div class="summary-label">Total Bids</div>
    </div>
    <div class="summary-cell">
      <div class="summary-val">${s.avg_clearing_price !== null ? '$' + fmt(s.avg_clearing_price) : '—'}</div>
      <div class="summary-label">Avg Clearing</div>
    </div>`;
}

function _renderRecentResults(results) {
  const el = $('recent-results');
  if (!results?.length) {
    el.innerHTML = '<div class="empty">No closed auctions yet</div>';
    return;
  }
  el.innerHTML = `
    <table>
      <thead><tr><th>Slot</th><th>Winner</th><th>Clearing</th><th>Margin</th></tr></thead>
      <tbody>
        ${results.map(r => `
          <tr>
            <td>${esc(r.slot_name)}</td>
            <td>${esc(r.winner_name)}</td>
            <td class="green">$${fmt(r.clearing_price)}</td>
            <td class="dim">$${fmt(r.margin)}</td>
          </tr>`).join('')}
      </tbody>
    </table>`;
}

/* ── Auto-Simulate ────────────────────────────────────────────────────────────
   Full-pipeline demo: creates a fresh auction, activates it, generates random
   bids for all bidders, resolves it, and reveals the animated result card.    */

const _SIM_SLOTS = [
  'Leaderboard 728×90',  'Half Page 300×600',   'Billboard 970×250',
  'Interstitial Full',   'Native Feed Unit',     'Sticky Footer 320×50',
  'Pre-roll 15s',        'Mid-roll 30s',         'Rewarded Video 30s',
  'Sidebar 160×600',     'Mobile Banner 320×50', 'Inline Article Unit',
];

const _SIM_STEPS = [
  'Creating auction slot…',
  'Activating auction…',
  'Generating bids…',
  'Resolving winner…',
];

// Advances one step in the simulation progress UI.
function _simStep(index, state) {
  const items = $('sim-steps').querySelectorAll('.sim-step');
  if (items[index]) {
    items[index].className = `sim-step ${state}`;
    items[index].querySelector('.sim-icon').textContent =
      state === 'done' ? '✓' : state === 'active' ? '●' : '○';
  }
}

// Render the progress overlay and return a small promise-based delay helper.
function _startSimUI() {
  const overlay = $('sim-overlay');
  overlay.classList.remove('hidden');
  $('auction-result').classList.add('hidden');
  $('bid-board').innerHTML = '<div class="empty dim">Simulation running…</div>';

  $('sim-steps').innerHTML = _SIM_STEPS.map((label, i) => `
    <div class="sim-step ${i === 0 ? 'active' : 'pending'}">
      <span class="sim-icon">${i === 0 ? '●' : '○'}</span>
      <span>${label}</span>
    </div>`).join('');
}

function _delay(ms) { return new Promise(r => setTimeout(r, ms)); }

async function handleAutoSimulate() {
  if (_bidders.length === 0) {
    notify('Add at least one bidder before simulating', 'error');
    return;
  }

  const btn = $('auto-sim-btn');
  btn.disabled = true;
  if (_realtime) { _realtime.stop(); _realtime = null; }

  // Reset auction selector so polling doesn't interfere
  $('auction-select').value = '';
  onAuctionSelected();

  _startSimUI();

  try {
    // ── Step 1: Create auction ─────────────────────────────────────────────
    _simStep(0, 'active');
    const slotName     = _SIM_SLOTS[Math.floor(Math.random() * _SIM_SLOTS.length)];
    const reservePrice = +(Math.random() * 12 + 3).toFixed(2);   // $3–$15
    const auction      = await createAuction(slotName, reservePrice);
    await _delay(380);
    _simStep(0, 'done');

    // ── Step 2: Activate ───────────────────────────────────────────────────
    _simStep(1, 'active');
    await updateAuctionStatus(auction.id, 'active');
    await _delay(320);
    _simStep(1, 'done');

    // ── Step 3: Generate bids (all bidders) ────────────────────────────────
    _simStep(2, 'active');
    const sim = await simulateAuction(auction.id);
    await _delay(420);
    _simStep(2, 'done');

    // ── Step 4: Reveal result ──────────────────────────────────────────────
    _simStep(3, 'active');
    await _delay(300);
    _simStep(3, 'done');

    // Brief pause so the user can read "done" on all four steps
    await _delay(450);
    $('sim-overlay').classList.add('hidden');

    // Show the generated bids in the bid board before revealing winner
    $('bid-board').innerHTML = _buildBidTable(
      sim.simulated_bids.map((b, i) => ({
        bid_id:       b.bidder_id,
        bidder_id:    b.bidder_id,
        bidder_name:  b.bidder_name,
        amount:       b.amount,
        submitted_at: new Date().toISOString(),
        position:     i + 1,
      }))
    );

    _showResult(sim.result);
    notify(`Simulated: ${slotName} — winner: ${sim.result.winner.bidder_name}`, 'success');

    // Refresh all panels after a short pause so the animation is visible first
    await _delay(600);
    await Promise.all([loadBidders(), loadAuctions(), loadAnalytics()]);
  } catch (e) {
    $('sim-overlay').classList.add('hidden');
    notify('Simulation failed: ' + e.message, 'error');
  } finally {
    btn.disabled = false;
  }
}

/* ── Inline form validation ───────────────────────────────────────────────────
   Shows an error message below the form and highlights the offending input.
   Returns true when valid so callers can proceed.                            */
function _fieldError(inputId, errId, message) {
  const inp = $(inputId);
  const err = $(errId);
  if (inp) inp.classList.add('input-error');
  if (err) err.textContent = message;
  return false;
}

function _clearErrors(...pairs) {
  for (const [inputId, errId] of pairs) {
    const inp = $(inputId); if (inp) inp.classList.remove('input-error');
    const err = $(errId);   if (err) err.textContent = '';
  }
}

function _validateBidderForm() {
  _clearErrors(['b-name', 'b-name-err'], ['b-budget', 'b-budget-err']);
  const name   = $('b-name').value.trim();
  const budget = parseFloat($('b-budget').value);
  if (!name)         return _fieldError('b-name',   'b-name-err',   'Name is required');
  if (name.length > 100) return _fieldError('b-name', 'b-name-err', 'Name too long (max 100 chars)');
  if (isNaN(budget) || budget <= 0)
                     return _fieldError('b-budget', 'b-budget-err', 'Budget must be > 0');
  return true;
}

function _validateAuctionForm() {
  _clearErrors(['a-slot', 'a-slot-err'], ['a-reserve', 'a-reserve-err']);
  const slot    = $('a-slot').value.trim();
  const reserve = parseFloat($('a-reserve').value);
  if (!slot)          return _fieldError('a-slot',    'a-slot-err',    'Slot name is required');
  if (slot.length > 120) return _fieldError('a-slot', 'a-slot-err', 'Slot name too long (max 120 chars)');
  if (isNaN(reserve) || reserve < 0)
                      return _fieldError('a-reserve', 'a-reserve-err', 'Reserve price must be ≥ 0');
  return true;
}

function _validateBidForm() {
  _clearErrors(['bid-amount', 'bid-amount-err']);
  const auctionId = $('auction-select').value;
  const bidderId  = $('bidder-select').value;
  const amount    = parseFloat($('bid-amount').value);
  if (!auctionId) { notify('Select an active auction', 'error'); return false; }
  if (!bidderId)  { notify('Select a bidder', 'error');          return false; }
  if (isNaN(amount) || amount <= 0)
    return _fieldError('bid-amount', 'bid-amount-err', 'Amount must be > 0');
  return true;
}

/* ── Bid History modal ────────────────────────────────────────────────────────
   Fetches bid timeline + result for any auction and renders in an overlay.   */
async function openBidHistoryModal(auctionId) {
  const backdrop = $('modal-backdrop');
  const title    = $('modal-title');
  const body     = $('modal-body');

  title.textContent = 'Loading…';
  body.innerHTML    = '<div class="empty"><span class="spinner"></span></div>';
  backdrop.classList.remove('hidden');

  try {
    const data = await getAuctionBids(auctionId);
    title.textContent = `Bid History — ${esc(data.auction.slot_name)}`;
    body.innerHTML    = _renderBidHistoryContent(data);
  } catch (e) {
    body.innerHTML = `<div class="empty" style="color:var(--danger)">${esc(e.message)}</div>`;
  }
}

function closeModal() {
  $('modal-backdrop').classList.add('hidden');
}

function _renderBidHistoryContent(data) {
  const { auction, bids, result } = data;

  const metaRow = `
    <div style="display:flex;gap:16px;margin-bottom:14px;font-size:11px;color:var(--text-dim)">
      <span>Reserve: <span class="green">$${fmt(auction.reserve_price)}</span></span>
      <span>Status: <span class="badge badge-${auction.status}">${auction.status}</span></span>
      <span>Created: ${fmtTime(auction.created_at)}</span>
    </div>`;

  const bidsTable = bids.length === 0
    ? '<div class="empty">No bids placed</div>'
    : `<table>
        <thead><tr><th>#</th><th>Bidder</th><th>Amount</th><th>Time</th></tr></thead>
        <tbody>
          ${bids.map((b, i) => `
            <tr>
              <td class="dim">${i + 1}</td>
              <td>${esc(b.bidder_name)}</td>
              <td class="green">$${fmt(b.amount)}</td>
              <td class="dim">${fmtTime(b.submitted_at)}</td>
            </tr>`).join('')}
        </tbody>
      </table>`;

  const resultSection = result ? `
    <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border)">
      <div class="section-title" style="margin-bottom:8px">Result</div>
      <div class="stat-grid">
        <div class="stat-box"><div class="stat-val green">${esc(result.winner_name)}</div><div class="stat-label">Winner</div></div>
        <div class="stat-box"><div class="stat-val">$${fmt(result.clearing_price)}</div><div class="stat-label">Clearing</div></div>
        <div class="stat-box"><div class="stat-val">$${fmt(result.margin)}</div><div class="stat-label">Margin</div></div>
      </div>
    </div>` : '';

  return metaRow + bidsTable + resultSection;
}

/* ── Event wiring ─────────────────────────────────────────────────────────────*/
function _wireEvents() {
  $('add-bidder-form').addEventListener('submit', e => {
    e.preventDefault();
    if (!_validateBidderForm()) return;
    const name   = $('b-name').value.trim();
    const budget = parseFloat($('b-budget').value);
    createBidder(name, budget)
      .then(() => {
        notify(`Bidder "${name}" added`, 'success');
        _clearErrors(['b-name', 'b-name-err'], ['b-budget', 'b-budget-err']);
        e.target.reset();
        return loadBidders();
      })
      .catch(err => notify(err.message, 'error'));
  });

  $('create-auction-form').addEventListener('submit', e => {
    e.preventDefault();
    if (!_validateAuctionForm()) return;
    const slotName     = $('a-slot').value.trim();
    const reservePrice = parseFloat($('a-reserve').value);
    createAuction(slotName, reservePrice)
      .then(() => {
        notify(`Auction "${slotName}" created`, 'success');
        _clearErrors(['a-slot', 'a-slot-err'], ['a-reserve', 'a-reserve-err']);
        e.target.reset();
        return loadAuctions();
      })
      .catch(err => notify(err.message, 'error'));
  });

  $('auction-select').addEventListener('change', onAuctionSelected);

  $('place-bid-btn').addEventListener('click', () => {
    if (_validateBidForm()) handlePlaceBid();
  });

  $('run-auction-btn').addEventListener('click', handleRunAuction);
  $('auto-sim-btn').addEventListener('click', handleAutoSimulate);
  $('refresh-analytics-btn').addEventListener('click', loadAnalytics);

  // Close modal on backdrop click
  $('modal-backdrop').addEventListener('click', e => {
    if (e.target === $('modal-backdrop')) closeModal();
  });

  // CSV export
  $('export-csv-btn').addEventListener('click', () => downloadAnalyticsCsv(30));
}

/* ── Expose onclick handlers to global scope ──────────────────────────────────
   Needed because onclick="..." attributes in dynamically-generated HTML look
   up names on window rather than the module or script scope.                  */
window.handleDeleteBidder  = handleDeleteBidder;
window.handleSetStatus     = handleSetStatus;
window.openBidHistoryModal = openBidHistoryModal;
window.closeModal          = closeModal;

/* ── Boot ─────────────────────────────────────────────────────────────────────
   Guards against DOMContentLoaded having already fired when scripts load at
   the bottom of <body>.                                                        */
function _init() {
  initCharts();
  _startClock();
  _checkConnection();
  setInterval(_checkConnection, 30_000);
  _wireEvents();
  Promise.all([loadBidders(), loadAuctions(), loadAnalytics()]);
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', _init);
} else {
  _init();
}
