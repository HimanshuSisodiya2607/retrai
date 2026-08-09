(function () {
  const cfg = window.ORDERS_LIVE_CONFIG || window.ORDERS_SSE_CONFIG;
  if (!cfg || !cfg.enabled) return;

  const listEl = document.getElementById('ordersLiveList');
  if (!listEl) return;

  const statusLabel = cfg.statusLabel || {};
  const statusNext = cfg.statusNext || {};
  const tab = cfg.tab || 'active';
  const endpoint = cfg.endpoint || 'orders-poll.php';
  const sseEndpoint = cfg.sseEndpoint || 'sse-stream.php';
  const pollMs = cfg.pollMs || 5000;
  const useSSE = cfg.useSSE !== false && typeof window.EventSource !== 'undefined';

  const knownKeys = new Set();
  let lastRenderedSignature = '';
  let pollTimer = null;
  let sseSource = null;
  let inFlight = false;

  function esc(str) {
    const d = document.createElement('div');
    d.textContent = str == null ? '' : String(str);
    return d.innerHTML;
  }

  function timeAgo(datetime) {
    const ts = new Date(String(datetime).replace(' ', 'T')).getTime();
    if (Number.isNaN(ts)) return '';
    const mins = Math.max(0, Math.round((Date.now() - ts) / 60000));
    if (mins < 1) return 'just now';
    if (mins === 1) return '1m ago';
    if (mins < 60) return mins + 'm ago';
    return Math.round(mins / 60) + 'h ago';
  }

  function formatAmount(n) {
    return '₹' + Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 });
  }

  function renderAction(order) {
    if (order.status === 'completed' || !statusNext[order.status]) return '';
    if (order.status === 'served') {
      const returnPath = encodeURIComponent(cfg.returnPath || 'overview.php?tab=active');
      return '<a href="tables.php?checkout=' + encodeURIComponent(order.table_key) +
        '&return=' + returnPath + '" class="advance-btn">' + esc(statusNext.served) + '</a>';
    }
    return '<form method="post" action="' + esc(cfg.advanceFormAction) + '" style="display:inline;">' +
      '<input type="hidden" name="advance_order" value="' + esc(order.order_key) + '">' +
      '<button type="submit" class="advance-btn">' + esc(statusNext[order.status]) + '</button>' +
      '</form>';
  }

  function renderOrderRow(order, isNew) {
    const label = statusLabel[order.status] || order.status;
    const sub = tab === 'completed'
      ? 'Completed · ' + timeAgo(order.ordered_at)
      : esc(label) + ' · ' + timeAgo(order.ordered_at);
    return '<div class="order-row' + (isNew ? ' order-row-new' : '') + '" data-order-key="' + esc(order.order_key) + '">' +
      '<div class="table-id">' + esc(order.table_name) + '</div>' +
      '<div class="items">' + esc(order.items_summary) + '<span>' + sub + '</span></div>' +
      '<div><span class="status-pill status-' + esc(order.status) + '">' + esc(label) + '</span></div>' +
      '<div class="amount">' + formatAmount(order.total_amount) + '</div>' +
      '<div class="action-cell">' + renderAction(order) + '</div>' +
      '</div>';
  }

  function ordersSignature(orders) {
    return JSON.stringify((orders || []).map(function (o) {
      return [o.order_key, o.status, o.total_amount, o.items_summary];
    }));
  }

  function renderOrders(orders, newKeys) {
    const signature = ordersSignature(orders);
    if (signature === lastRenderedSignature) return;
    lastRenderedSignature = signature;

    const newSet = new Set(newKeys || []);
    if (!orders.length) {
      listEl.innerHTML = '<div class="empty-note">' + esc(
        tab === 'completed' ? cfg.emptyCompleted : cfg.emptyActive
      ) + '</div>';
      return;
    }
    listEl.innerHTML = orders.map(function (o) {
      return renderOrderRow(o, newSet.has(o.order_key));
    }).join('');

    listEl.querySelectorAll('.order-row-new').forEach(function (row) {
      setTimeout(function () { row.classList.remove('order-row-new'); }, 4000);
    });
  }

  function updateNavBadge(count) {
    document.querySelectorAll('a[href="orders.php"] .badge').forEach(function (el) {
      el.textContent = count;
    });
  }

  function updateStats(stats) {
    if (!stats || !cfg.updateStats) return;
    const ordersVal = document.getElementById('statOrdersToday');
    const revenueVal = document.getElementById('statRevenueToday');
    const avgVal = document.getElementById('statAvgOrder');
    if (ordersVal) ordersVal.textContent = stats.orders;
    if (revenueVal) revenueVal.textContent = formatAmount(stats.revenue);
    if (avgVal) avgVal.textContent = formatAmount(stats.avg);
  }

  function setLiveStatus(state, mode) {
    const pill = document.getElementById('ordersLivePill');
    if (!pill) return;
    if (state === 'live') {
      // The transport (stream vs poll) is an implementation detail —
      // the user only needs to know updates are live.
      pill.textContent = 'LIVE';
      pill.classList.add('live-on');
    } else if (state === 'error') {
      pill.textContent = 'OFFLINE';
      pill.classList.remove('live-on');
    } else {
      pill.textContent = 'CONNECTING';
      pill.classList.remove('live-on');
    }
  }

  function handlePayload(payload) {
    if (!payload || payload.tab !== tab) return;
    const newKeys = [];
    (payload.orders || []).forEach(function (o) {
      if (!knownKeys.has(o.order_key)) newKeys.push(o.order_key);
      knownKeys.add(o.order_key);
    });

    renderOrders(payload.orders || [], newKeys);
    if (typeof payload.nav_count === 'number') updateNavBadge(payload.nav_count);
    updateStats(payload.stats);
  }

  // --- SSE STREAM INITIALIZATION ---
  function startSSE() {
    if (sseSource) return;
    try {
      sseSource = new EventSource(sseEndpoint);

      sseSource.addEventListener('orders_update', function (e) {
        setLiveStatus('live', 'sse');
        try {
          const payload = JSON.parse(e.data);
          handlePayload(payload);
        } catch (err) {}
      });

      sseSource.addEventListener('table_requests_update', function (e) {
        if (typeof window.renderTableRequests === 'function') {
          try {
            const data = JSON.parse(e.data);
            if (data.ok) window.renderTableRequests(data.requests);
          } catch (err) {}
        }
      });

      sseSource.onerror = function () {
        setLiveStatus('error');
        // If SSE fails, fallback to polling
        stopSSE();
        startPolling();
      };
    } catch (err) {
      startPolling();
    }
  }

  function stopSSE() {
    if (sseSource) {
      sseSource.close();
      sseSource = null;
    }
  }

  // --- HTTP SHORT POLLING FALLBACK ---
  async function pollOnce() {
    if (inFlight || document.hidden) return;
    inFlight = true;
    try {
      const res = await fetch(endpoint + '?tab=' + encodeURIComponent(tab), {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' },
      });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const payload = await res.json();
      handlePayload(payload);
      setLiveStatus('live', 'poll');
    } catch (err) {
      setLiveStatus('error');
    } finally {
      inFlight = false;
    }
  }

  function startPolling() {
    if (pollTimer) clearInterval(pollTimer);
    pollOnce();
    pollTimer = setInterval(pollOnce, pollMs);
  }

  function stopPolling() {
    if (pollTimer) {
      clearInterval(pollTimer);
      pollTimer = null;
    }
  }

  function init() {
    if (useSSE) {
      startSSE();
    } else {
      startPolling();
    }
  }

  function stopAll() {
    stopSSE();
    stopPolling();
  }

  document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
      stopAll();
    } else {
      init();
    }
  });

  window.addEventListener('beforeunload', stopAll);
  init();
})();

