/**
 * Topbar search. Debounced lookup against search.php, results in a
 * dropdown, full keyboard support (arrows, Enter, Escape).
 */
(function () {
  var input = document.getElementById('topbarSearch');
  var box = document.getElementById('topbarSearchBox');
  if (!input || !box) return;

  var panel = document.createElement('div');
  panel.className = 'search-results';
  panel.setAttribute('role', 'listbox');
  box.appendChild(panel);

  var timer = null;
  var controller = null;
  var links = [];
  var active = -1;
  var lastQuery = '';

  function hide() {
    panel.classList.remove('open');
    links = [];
    active = -1;
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  function highlight(text, q) {
    var safe = esc(text);
    if (!q) return safe;
    var i = safe.toLowerCase().indexOf(q.toLowerCase());
    if (i === -1) return safe;
    return safe.slice(0, i) + '<mark>' + safe.slice(i, i + q.length) + '</mark>' + safe.slice(i + q.length);
  }

  function setActive(n) {
    if (!links.length) return;
    if (active >= 0) links[active].classList.remove('is-active');
    active = (n + links.length) % links.length;
    links[active].classList.add('is-active');
    links[active].scrollIntoView({ block: 'nearest' });
  }

  function render(groups, q) {
    if (!groups.length) {
      panel.innerHTML = '<div class="search-empty">No matches for “' + esc(q) + '”</div>';
      panel.classList.add('open');
      links = [];
      active = -1;
      return;
    }

    var html = '';
    groups.forEach(function (g) {
      html += '<div class="search-group">' + esc(g.label) + '</div>';
      g.items.forEach(function (it) {
        html += '<a class="search-item" href="' + esc(it.url) + '" role="option">' +
                  '<span class="si-title">' + highlight(it.title, q) + '</span>' +
                  '<span class="si-sub">' + highlight(it.sub, q) + '</span>' +
                '</a>';
      });
    });
    panel.innerHTML = html;
    panel.classList.add('open');
    links = Array.prototype.slice.call(panel.querySelectorAll('.search-item'));
    active = -1;
  }

  function run(q) {
    // Abort the previous lookup so a slow response can't overwrite a
    // newer one and show stale results.
    if (controller) controller.abort();
    controller = new AbortController();

    fetch('search.php?q=' + encodeURIComponent(q), {
      credentials: 'same-origin',
      signal: controller.signal
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.ok || data.q !== lastQuery) return;
        render(data.groups || [], data.q);
      })
      .catch(function (err) {
        if (err.name === 'AbortError') return;
        panel.innerHTML = '<div class="search-empty">Search is unavailable right now.</div>';
        panel.classList.add('open');
      });
  }

  input.addEventListener('input', function () {
    var q = input.value.trim();
    lastQuery = q;
    clearTimeout(timer);

    if (q.length < 2) {
      hide();
      return;
    }
    panel.innerHTML = '<div class="search-empty">Searching…</div>';
    panel.classList.add('open');
    timer = setTimeout(function () { run(q); }, 220);
  });

  input.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { hide(); input.blur(); return; }
    if (!links.length) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); setActive(active + 1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(active - 1); }
    else if (e.key === 'Enter' && active >= 0) { e.preventDefault(); links[active].click(); }
  });

  input.addEventListener('focus', function () {
    if (input.value.trim().length >= 2 && panel.innerHTML) panel.classList.add('open');
  });

  document.addEventListener('click', function (e) {
    if (!box.contains(e.target)) hide();
  });

  // "/" focuses search, the way most dashboards behave.
  document.addEventListener('keydown', function (e) {
    var tag = (e.target.tagName || '').toLowerCase();
    if (e.key === '/' && tag !== 'input' && tag !== 'textarea' && !e.target.isContentEditable) {
      e.preventDefault();
      input.focus();
    }
  });
})();
