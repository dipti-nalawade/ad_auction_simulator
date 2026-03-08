'use strict';

/* ── RealtimeUpdater ───────────────────────────────────────────────────────────
   Connects to api/events.php via Server-Sent Events and calls onUpdate() each
   time the server reports a change.  Automatically falls back to 3-second
   polling if EventSource is unavailable or the connection fails permanently.   */

class RealtimeUpdater {
  constructor(onUpdate) {
    this._onUpdate  = onUpdate;
    this._es        = null;
    this._pollTimer = null;
    this._polling   = false;
  }

  start() {
    if (typeof EventSource === 'undefined') {
      this._startPolling();
      return;
    }
    this._connectSSE();
  }

  stop() {
    if (this._es)        { this._es.close(); this._es = null; }
    if (this._pollTimer) { clearInterval(this._pollTimer); this._pollTimer = null; }
    this._polling = false;
  }

  _connectSSE() {
    const es = new EventSource('../api/events.php');
    this._es  = es;

    es.addEventListener('update', () => this._onUpdate());

    es.onerror = () => {
      es.close();
      this._es = null;
      // Switch permanently to polling on the first connection error
      if (!this._polling) this._startPolling();
    };
  }

  _startPolling() {
    this._polling   = true;
    this._pollTimer = setInterval(() => this._onUpdate(), 3000);
  }
}
