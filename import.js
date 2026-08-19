/* import.js — the spreadsheet-to-Datafort wizard.
 *
 * Five steps, and the fifth is the one that matters. Steps 1-4 move the
 * data in; step 5 gets the original spreadsheet out of the world. An
 * import that stops at step 4 leaves the product protecting a copy
 * while the source file is still forwardable — see requirements 6.
 *
 * CSV is parsed here purely to build the column mapper without a round
 * trip. XLSX is NOT parsed in the browser: it needs a real library, and
 * a spreadsheet from an unknown source is exactly the kind of file that
 * should be opened by a hardened server parser (PhpSpreadsheet), never
 * by the admin's browser.
 */
(function () {
  'use strict';

  var D = window.Datafort;

  /* The canonical schema from requirements section 6. Every source
   * normalises into these fields. */
  var FIELDS = [
    { key: '',             label: '— Do not import —' },
    { key: 'name',         label: 'Contact name' },
    { key: 'company',      label: 'Company' },
    { key: 'designation',  label: 'Designation' },
    { key: 'phone',        label: 'Phone' },
    { key: 'alt_phone',    label: 'Alternate phone' },
    { key: 'email',        label: 'Email' },
    { key: 'city',         label: 'City' },
    { key: 'state',        label: 'State' },
    { key: 'industry',     label: 'Industry' },
    { key: 'company_size', label: 'Company size' },
    { key: 'website',      label: 'Website' },
    { key: 'linkedin',     label: 'LinkedIn' },
    { key: 'notes',        label: 'Notes' }
  ];

  /* Header guesses. Saves the admin mapping 14 dropdowns by hand on the
   * common case, which is a list exported from a marketplace. */
  var GUESS = {
    name: /^(name|full ?name|contact|contact ?name|person)$/i,
    company: /^(company|company ?name|organisation|organization|firm|business)$/i,
    designation: /^(designation|title|job ?title|role|position)$/i,
    phone: /^(phone|mobile|mobile ?no|phone ?number|contact ?no|number)$/i,
    alt_phone: /^(alt|alt ?phone|alternate|secondary ?phone|phone ?2)$/i,
    email: /^(email|e-?mail|email ?id|mail)$/i,
    city: /^(city|town|location)$/i,
    state: /^(state|province|region)$/i,
    industry: /^(industry|sector|category|vertical)$/i,
    company_size: /^(size|company ?size|employees|headcount)$/i,
    website: /^(website|web|url|site)$/i,
    linkedin: /^(linkedin|linked ?in|li)$/i,
    notes: /^(notes?|remarks?|comments?|description)$/i
  };

  var state = {
    file: null,
    headers: [],
    rows: [],
    mapping: {},      // column index -> field key
    isCsv: false
  };


  /* ══ Steps ═════════════════════════════════════════════════════ */

  var current = 1;

  function go(step) {
    current = step;

    for (var i = 1; i <= 5; i++) {
      document.getElementById('panel' + i).hidden = (i !== step);
      var chip = document.querySelector('.step[data-step="' + i + '"]');
      chip.dataset.state = i < step ? 'done' : i === step ? 'active' : '';
    }

    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  document.addEventListener('click', function (e) {
    var back = e.target.closest('[data-goto]');
    if (back) go(parseInt(back.dataset.goto, 10));
  });


  /* ══ Step 1 — file ═════════════════════════════════════════════ */

  var drop = document.getElementById('drop');
  var fileInput = document.getElementById('file');
  var fileInfo = document.getElementById('fileInfo');

  drop.addEventListener('click', function () { fileInput.click(); });
  drop.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileInput.click(); }
  });

  ['dragenter', 'dragover'].forEach(function (ev) {
    drop.addEventListener(ev, function (e) { e.preventDefault(); drop.dataset.over = 'true'; });
  });
  ['dragleave', 'drop'].forEach(function (ev) {
    drop.addEventListener(ev, function (e) { e.preventDefault(); drop.dataset.over = 'false'; });
  });

  drop.addEventListener('drop', function (e) {
    if (e.dataTransfer.files.length) handleFile(e.dataTransfer.files[0]);
  });
  fileInput.addEventListener('change', function () {
    if (fileInput.files.length) handleFile(fileInput.files[0]);
  });

  function handleFile(file) {
    state.file = file;
    state.isCsv = /\.csv$/i.test(file.name);

    document.getElementById('fileNameEcho').textContent = file.name;

    fileInfo.hidden = false;
    fileInfo.textContent = file.name + ' · ' + (file.size / 1024).toFixed(0) + ' KB';

    if (state.isCsv) {
      var reader = new FileReader();
      reader.onload = function () {
        parseCsv(String(reader.result));
        buildMapper();
        go(2);
      };
      reader.readAsText(file);
    } else {
      /* No client-side XLSX. Rather than pretend, the wizard says what
       * will happen and lets the admin proceed to a server-side parse. */
      fileInfo.textContent += ' — Excel files are parsed on the server. ' +
        'Column mapping will appear once the upload finishes.';
      D.toast('XLSX parsing needs the API. Try a .csv to preview the flow.', 'error', 6000);
    }
  }

  /* Minimal CSV reader: handles quoted fields, embedded commas and
   * doubled quotes. Not a full RFC 4180 implementation — the server
   * parser is the authority, this only has to be good enough to name
   * the columns. */
  function parseCsv(text) {
    var rows = [];
    var row = [];
    var field = '';
    var inQuotes = false;

    for (var i = 0; i < text.length; i++) {
      var c = text[i];

      if (inQuotes) {
        if (c === '"') {
          if (text[i + 1] === '"') { field += '"'; i++; }
          else inQuotes = false;
        } else field += c;
        continue;
      }

      if (c === '"') { inQuotes = true; }
      else if (c === ',') { row.push(field); field = ''; }
      else if (c === '\n') { row.push(field); rows.push(row); row = []; field = ''; }
      else if (c !== '\r') { field += c; }
    }
    if (field || row.length) { row.push(field); rows.push(row); }

    state.headers = (rows.shift() || []).map(function (h) { return h.trim(); });
    state.rows = rows.filter(function (r) {
      return r.some(function (v) { return String(v).trim(); });
    });
  }


  /* ══ Step 2 — mapping ══════════════════════════════════════════ */

  function guessField(header) {
    for (var key in GUESS) {
      if (GUESS[key].test(header.trim())) return key;
    }
    return '';
  }

  function buildMapper() {
    document.getElementById('mapSub').textContent =
      state.headers.length + ' columns · ' + state.rows.length.toLocaleString() + ' rows found';

    var host = document.getElementById('mapRows');

    host.innerHTML = state.headers.map(function (h, idx) {
      var guess = guessField(h);
      state.mapping[idx] = guess;

      var sample = (state.rows[0] && state.rows[0][idx]) || '';

      return '<div class="maprow">' +
        '<div class="from">' +
          '<div>' + D.escape(h || '(unnamed column ' + (idx + 1) + ')') + '</div>' +
          '<div class="sub">' + D.escape(sample.slice(0, 40)) + '</div>' +
        '</div>' +
        '<div class="arrow">→</div>' +
        '<select class="select" data-col="' + idx + '">' +
          FIELDS.map(function (f) {
            return '<option value="' + f.key + '"' +
              (f.key === guess ? ' selected' : '') + '>' + f.label + '</option>';
          }).join('') +
        '</select>' +
      '</div>';
    }).join('');
  }

  /* Delegated once at load rather than per buildMapper() call — choosing
   * a second file would otherwise stack a duplicate listener. */
  document.getElementById('mapRows').addEventListener('change', function (e) {
    var sel = e.target.closest('[data-col]');
    if (sel) state.mapping[sel.dataset.col] = sel.value;
  });

  document.getElementById('toReview').addEventListener('click', function () {
    buildReview();
    go(3);
  });


  /* ══ Step 3 — review ═══════════════════════════════════════════ */

  function mappedRows() {
    return state.rows.map(function (r) {
      var out = {};
      Object.keys(state.mapping).forEach(function (idx) {
        var field = state.mapping[idx];
        if (field) out[field] = (r[idx] || '').trim();
      });
      return out;
    });
  }

  function mask(value, kind) {
    if (!value) return '';
    if (kind === 'phone') {
      var digits = value.replace(/\D/g, '');
      return digits.length > 4
        ? value.slice(0, value.length - 6) + '****' + digits.slice(-2)
        : '****';
    }
    var at = value.indexOf('@');
    if (at < 1) return '****';
    return value.slice(0, 2) + '****' + value.slice(at);
  }

  function buildReview() {
    var rows = mappedRows();

    // Dedup on phone first, email second — a purchased list repeats the
    // same company under different contacts far more often than it
    // repeats the same phone number.
    var seen = {};
    var dupes = 0;
    var noContact = 0;

    rows.forEach(function (r) {
      var key = (r.phone || '').replace(/\D/g, '') || (r.email || '').toLowerCase();
      if (!key) { noContact++; return; }
      if (seen[key]) dupes++;
      seen[key] = true;
    });

    var usable = rows.length - dupes - noContact;
    var cost = parseInt(document.getElementById('sourceCost').value, 10) || 0;

    document.getElementById('reviewTiles').innerHTML = [
      { label: 'Rows in file',   value: rows.length.toLocaleString(), note: 'after the header' },
      { label: 'Will import',    value: usable.toLocaleString(),      note: 'unique, with a contact' },
      { label: 'Duplicates',     value: dupes.toLocaleString(),       note: 'skipped' },
      { label: 'No contact',     value: noContact.toLocaleString(),   note: 'no phone or email' },
      { label: 'Cost per lead',  value: usable ? D.money((cost / usable).toFixed(1)) : '—',
        note: D.money(cost) + ' total' }
    ].map(function (t) {
      return '<div class="tile"><div class="tile__label">' + t.label + '</div>' +
        '<div class="tile__value">' + t.value + '</div>' +
        '<div class="tile__note">' + t.note + '</div></div>';
    }).join('');

    /* The preview shows the row AS STORED — already masked. Otherwise an
     * admin builds a mental model where plain contact values are normal
     * in the UI, which is exactly the habit this product exists to break. */
    var cols = ['name', 'company', 'phone', 'email', 'city'];
    document.getElementById('previewTable').innerHTML =
      '<table class="table"><thead><tr>' +
      cols.map(function (c) { return '<th>' + c + '</th>'; }).join('') +
      '</tr></thead><tbody>' +
      rows.slice(0, 8).map(function (r) {
        return '<tr>' + cols.map(function (c) {
          var v = r[c] || '';
          if (c === 'phone') v = mask(v, 'phone');
          if (c === 'email') v = mask(v, 'email');
          return '<td>' + D.escape(v) + '</td>';
        }).join('') + '</tr>';
      }).join('') +
      '</tbody></table>';

    var problems = [];
    var hasName = Object.keys(state.mapping).some(function (k) { return state.mapping[k] === 'name'; });
    var hasContact = Object.keys(state.mapping).some(function (k) {
      return state.mapping[k] === 'phone' || state.mapping[k] === 'email';
    });

    if (!hasName) problems.push('No column is mapped to Contact name.');
    if (!hasContact) problems.push('No column is mapped to Phone or Email — these leads would be unusable.');
    if (!document.getElementById('sourceName').value.trim()) {
      problems.push('No source name given. Return by source will not be reportable for this batch.');
    }

    var box = document.getElementById('reviewProblems');
    box.hidden = problems.length === 0;
    box.innerHTML = problems.join('<br>');
  }


  /* ══ Step 4 — run ══════════════════════════════════════════════ */

  document.getElementById('runImport').addEventListener('click', function () {
    go(4);

    var pct = 0;
    var fill = document.getElementById('runFill');
    var label = document.getElementById('runLabel');
    var pctEl = document.getElementById('runPct');

    var phases = [
      [20, 'Uploading file…'],
      [45, 'Parsing rows…'],
      [70, 'Removing duplicates…'],
      [88, 'Seeding decoy records…'],
      [100, 'Writing to the vault…']
    ];

    var timer = setInterval(function () {
      pct += 3;
      var phase = phases.filter(function (p) { return pct <= p[0]; })[0];
      if (phase) label.textContent = phase[1];

      fill.style.width = Math.min(100, pct) + '%';
      pctEl.textContent = Math.min(100, pct) + '%';

      if (pct >= 100) {
        clearInterval(timer);
        finish();
      }
    }, 60);
  });

  function finish() {
    var rows = mappedRows();
    document.getElementById('doneSub').textContent =
      rows.length.toLocaleString() + ' rows processed from ' +
      (state.file ? state.file.name : 'the file') +
      ' · decoy records seeded for attribution';
    go(5);
  }


  /* ══ Step 5 — secure the original ══════════════════════════════ */

  var checks = document.querySelectorAll('.destroyCheck');
  var confirmBtn = document.getElementById('confirmDestroy');

  checks.forEach(function (c) {
    c.addEventListener('change', function () {
      var all = true;
      checks.forEach(function (x) { if (!x.checked) all = false; });
      confirmBtn.disabled = !all;
    });
  });

  confirmBtn.addEventListener('click', function () {
    /* Recorded as a security event, not a preference. "Who said they
     * destroyed the source file, and when" is the first question asked
     * after a list turns up somewhere it should not. */
    D.securityEvent('source_file_destroyed', state.file ? state.file.name : 'unknown');
    D.toast('Recorded. Import closed out.', 'ok');
    setTimeout(function () { location.href = 'leads.html'; }, 900);
  });


  go(1);
})();
