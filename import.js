/* import.js — the spreadsheet-to-Datafort wizard.
 *
 * Five steps, and the fifth is the one that matters. Steps 1-4 move the
 * data in; step 5 gets the original spreadsheet out of the world. An
 * import that stops at step 4 leaves Datafort protecting a copy while
 * the source file is still forwardable — requirements section 6.
 *
 * CSV is parsed here only to build the column mapper without a round
 * trip. The real parse happens in api/import-commit.php. XLSX is not
 * supported yet — it needs PhpSpreadsheet, and a spreadsheet from an
 * unknown source should be opened by a hardened server parser rather
 * than by the administrator's browser.
 */
(function () {
  'use strict';

  var D = window.Datafort;
  var API = window.DatafortAPI;

  /* The canonical schema from requirements section 6. */
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

  /* Header guesses — saves mapping 14 dropdowns by hand on the common
   * case, which is a list exported from a marketplace. */
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
    // For CSV: every data row. For XLSX: an 8-row sample from the server,
    // because the workbook is never opened in the browser.
    rows: [],
    // Real row count when `rows` is only a sample; null when it is not.
    totalRows: null,
    mapping: {},
    sourceId: null
  };


  /* ══ Steps ═════════════════════════════════════════════════════ */

  function go(step) {
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

    /* Reset the sample marker. Choosing an .xlsx and then a .csv would
     * otherwise leave totalRows set from the workbook, and the review
     * step would report the CSV's exact counts as estimates. */
    state.totalRows = null;
    state.headers = [];
    state.rows = [];

    document.getElementById('fileNameEcho').textContent = file.name;

    fileInfo.hidden = false;
    fileInfo.className = 'alert alert--info';
    fileInfo.textContent = file.name + ' · ' + (file.size / 1024).toFixed(0) + ' KB';

    if (/\.xls$/i.test(file.name)) {
      /* The old binary .xls is a different container entirely and needs
       * a real library to read. Excel converts it in two clicks, which
       * is a better trade than carrying that code. */
      fileInfo.className = 'alert alert--error';
      fileInfo.textContent =
        'The older .xls format is not supported. Open ' + file.name +
        ' in Excel and use Save As to produce .xlsx or CSV.';
      state.file = null;
      return;
    }

    if (/\.xlsx$/i.test(file.name)) {
      /* Excel goes straight to the server, which parses it with
       * api/xlsx.php. Nothing is read here: a workbook from an unknown
       * source is exactly the file that should be opened by a hardened
       * server parser rather than in the administrator's browser.
       *
       * The consequence is that the column mapper cannot be pre-filled
       * from the file — so the headers are fetched in a preview pass. */
      fileInfo.className = 'alert alert--info';
      fileInfo.textContent = file.name + ' · reading columns on the server…';
      loadXlsxHeaders(file);
      return;
    }

    if (!/\.csv$/i.test(file.name)) {
      fileInfo.className = 'alert alert--error';
      fileInfo.textContent = 'Import accepts .csv and .xlsx files only.';
      state.file = null;
      return;
    }

    var reader = new FileReader();
    reader.onload = function () {
      parseCsv(String(reader.result));

      if (!state.headers.length || !state.rows.length) {
        fileInfo.className = 'alert alert--error';
        fileInfo.textContent = 'That file has no readable rows. Check it has a header row and data.';
        state.file = null;
        return;
      }

      buildMapper();
      go(2);
    };
    reader.onerror = function () {
      fileInfo.className = 'alert alert--error';
      fileInfo.textContent = 'Could not read that file.';
      state.file = null;
    };
    reader.readAsText(file);
  }

  /* Asks the server for the header row and a few sample rows so the
   * mapper and the review step can be built without the browser ever
   * opening the workbook. */
  function loadXlsxHeaders(file) {
    var form = new FormData();
    form.append('file', file);
    form.append('preview', '1');

    API.importCommit(form).then(function (res) {
      state.headers = res.headers || [];
      state.rows    = res.sample  || [];
      state.totalRows = res.totalRows || state.rows.length;

      if (!state.headers.length) {
        fileInfo.className = 'alert alert--error';
        fileInfo.textContent = 'That workbook has no header row.';
        state.file = null;
        return;
      }

      fileInfo.className = 'alert alert--info';
      fileInfo.textContent = file.name + ' · ' +
        state.totalRows.toLocaleString() + ' rows, ' + state.headers.length + ' columns';

      buildMapper();
      go(2);

    }).catch(function (err) {
      fileInfo.className = 'alert alert--error';
      fileInfo.textContent = err.message || 'That workbook could not be read.';
      state.file = null;
    });
  }

  /* Minimal CSV reader: quoted fields, embedded commas, doubled quotes.
   * Not a full RFC 4180 implementation — import-commit.php re-parses
   * server-side and is the authority. This only has to be good enough to
   * name the columns. */
  function parseCsv(text) {
    var rows = [], row = [], field = '', inQuotes = false;

    for (var i = 0; i < text.length; i++) {
      var c = text[i];

      if (inQuotes) {
        if (c === '"') {
          if (text[i + 1] === '"') { field += '"'; i++; }
          else inQuotes = false;
        } else field += c;
        continue;
      }

      if (c === '"') inQuotes = true;
      else if (c === ',') { row.push(field); field = ''; }
      else if (c === '\n') { row.push(field); rows.push(row); row = []; field = ''; }
      else if (c !== '\r') field += c;
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

    state.mapping = {};

    document.getElementById('mapRows').innerHTML = state.headers.map(function (h, idx) {
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

  // Delegated once — choosing a second file would otherwise stack listeners.
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
        ? value.slice(0, Math.max(0, value.length - 6)) + '****' + digits.slice(-2)
        : '****';
    }
    var at = value.indexOf('@');
    if (at < 1) return '****';
    return value.slice(0, 2) + '****' + value.slice(at);
  }

  function buildReview() {
    var rows = mappedRows();
    var cost = parseFloat(document.getElementById('sourceCost').value) || 0;

    /* For CSV the browser holds every row, so the dedup counts here are
     * exact. For XLSX it holds only the 8-row sample the server returned
     * — counting duplicates across 8 of 40,000 rows and presenting the
     * result as a total would be a plain lie, so those tiles say so and
     * the real numbers come back from the import itself. */
    var sampled = state.totalRows !== null && state.totalRows > rows.length;
    var totalRows = sampled ? state.totalRows : rows.length;

    var tiles;

    if (sampled) {
      tiles = [
        { label: 'Rows in file',  value: totalRows.toLocaleString(), note: 'after the header' },
        { label: 'Will import',   value: '—', note: 'counted during import' },
        { label: 'Duplicates',    value: '—', note: 'removed by the server' },
        { label: 'No contact',    value: '—', note: 'counted during import' },
        { label: 'Cost per lead', value: cost > 0 ? '≈ ' + D.money(cost / totalRows) : '—',
          note: D.money(cost) + ' total, before dedup' }
      ];
    } else {
      // Same dedup rule the server applies: phone digits first, else email.
      var seen = {}, dupes = 0, noContact = 0;

      rows.forEach(function (r) {
        var key = (r.phone || '').replace(/\D/g, '') || (r.email || '').toLowerCase();
        if (!key) { noContact++; return; }
        if (seen[key]) dupes++;
        seen[key] = true;
      });

      var usable = rows.length - dupes - noContact;

      tiles = [
        { label: 'Rows in file',  value: rows.length.toLocaleString(), note: 'after the header' },
        { label: 'Will import',   value: usable.toLocaleString(),      note: 'unique, with a contact' },
        { label: 'Duplicates',    value: dupes.toLocaleString(),       note: 'skipped' },
        { label: 'No contact',    value: noContact.toLocaleString(),   note: 'no phone or email' },
        { label: 'Cost per lead', value: usable > 0 && cost > 0 ? D.money(cost / usable) : '—',
          note: D.money(cost) + ' total' }
      ];
    }

    document.getElementById('reviewTiles').innerHTML = tiles.map(function (t) {
      return '<div class="tile"><div class="tile__label">' + t.label + '</div>' +
        '<div class="tile__value">' + t.value + '</div>' +
        '<div class="tile__note">' + t.note + '</div></div>';
    }).join('');

    /* The preview shows rows AS STORED — already masked. Otherwise an
     * admin builds a mental model where plain contact values are normal
     * in this UI, which is the habit the product exists to break. */
    var cols = ['name', 'company', 'phone', 'email', 'city'];
    document.getElementById('previewTable').innerHTML =
      '<table class="table import-preview-table"><thead><tr>' +
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
    var mapped = Object.keys(state.mapping).map(function (k) { return state.mapping[k]; });

    if (mapped.indexOf('name') === -1) problems.push('No column is mapped to Contact name.');
    if (mapped.indexOf('phone') === -1 && mapped.indexOf('email') === -1) {
      problems.push('No column is mapped to Phone or Email — these leads would be unusable, and the import will be refused.');
    }
    if (!document.getElementById('sourceName').value.trim()) {
      problems.push('No source name given. Cost per source will not be reportable for this batch.');
    }

    var box = document.getElementById('reviewProblems');
    box.hidden = problems.length === 0;
    box.innerHTML = problems.map(D.escape).join('<br>');
  }


  /* ══ Step 4 — run ══════════════════════════════════════════════ */

  document.getElementById('runImport').addEventListener('click', function () {
    if (!state.file) { D.toast('Choose a CSV file first.', 'error'); return; }

    go(4);

    var fill  = document.getElementById('runFill');
    var label = document.getElementById('runLabel');
    var pctEl = document.getElementById('runPct');

    label.textContent = 'Uploading and parsing…';

    var form = new FormData();
    form.append('file', state.file);
    form.append('mapping', JSON.stringify(state.mapping));
    form.append('sourceName', document.getElementById('sourceName').value.trim());
    form.append('sourceCost', document.getElementById('sourceCost').value || '0');

    /* Indeterminate progress. The server does the work in one request
     * and reports nothing until it finishes, so an accurate percentage
     * is not available — this animates to 90% and waits rather than
     * claiming a precision it does not have. */
    var pct = 0;
    var timer = setInterval(function () {
      pct = Math.min(90, pct + 3);
      fill.style.width = pct + '%';
      pctEl.textContent = pct + '%';
    }, 200);

    API.importCommit(form).then(function (res) {
      clearInterval(timer);
      fill.style.width = '100%';
      pctEl.textContent = '100%';

      state.sourceId = res.sourceId;

      document.getElementById('doneSub').textContent =
        res.imported.toLocaleString() + ' leads imported from ' + state.file.name +
        ' · ' + res.duplicates.toLocaleString() + ' duplicates skipped' +
        (res.noContact ? ' · ' + res.noContact.toLocaleString() + ' had no contact details' : '');

      setTimeout(function () { go(5); }, 500);

    }).catch(function (err) {
      clearInterval(timer);
      D.fail(err);
      go(3);
    });
  });


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
    var btn = this;
    btn.disabled = true;

    /* Recorded as an attributable, timestamped statement — not as proof.
     * Nobody can verify from a web server that a file was deleted from
     * someone's machine. What this gives you is a named person saying
     * so, and a dashboard list of imports where nobody has. */
    API.importDestroy(state.sourceId)
      .then(function () {
        D.toast('Recorded. Import closed out.', 'ok');
        setTimeout(function () { location.href = 'leads.html'; }, 900);
      })
      .catch(function (err) {
        btn.disabled = false;
        D.fail(err);
      });
  });


  D.ready(function () { go(1); });
})();
