// Grade Book page. Courses and their assessments live in the database (table grade_courses) and
// follow the signed-in person. Needs: clerk-config.js, auth.js, db.js, app.js, grade-greetings.js
(function () {
  var routes = DucksAuth.routes, A = DucksApp, esc = A.esc;
  var $ = function (id) { return document.getElementById(id); };
  var Clerk, db, profile = null, courses = [], active = 0;

  /* Letter scale (Carleton's). Each line is [lowest percent, letter]. */
  var SCALE = [[90, 'A+'], [85, 'A'], [80, 'A-'], [77, 'B+'], [73, 'B'], [70, 'B-'], [67, 'C+'], [63, 'C'], [60, 'C-'], [57, 'D+'], [53, 'D'], [50, 'D-'], [0, 'F']];
  var CHIPS = [100, 90, 80, 70, 60];
  var MAX_COURSES = 30, MAX_ITEMS = 60;

  function letter(p) { for (var i = 0; i < SCALE.length; i++) if (p >= SCALE[i][0]) return SCALE[i][1]; return 'F'; }
  function num(v) { if (v === '' || v == null) return null; var x = parseFloat(v); return isFinite(x) ? x : null; }
  function f1(x) { return (Math.round(x * 10) / 10).toFixed(1); }
  function pct(x) { return f1(x).replace(/\.0$/, ''); }
  var seq = 0;
  function newId() { seq += 1; return 'i' + Date.now().toString(36) + seq; }
  function blankItem() { return { id: newId(), name: '', weight: '', score: '', out: '100' }; }
  function cleanItem(it) { return { id: it.id, name: it.name || '', weight: it.weight == null ? '' : String(it.weight), score: it.score == null ? '' : String(it.score), out: it.out == null ? '' : String(it.out) }; }
  function cur() { return courses[active] || null; }

  function greeting(p) {
    var G = window.DUCKS_GRADE_GREETINGS || {};
    if (p === null) return G.empty || '...add a grade and the duck will weigh in.';
    var t = G.tiers || [];
    for (var i = 0; i < t.length; i++) if (p >= t[i].min) return t[i].text;
    return '';
  }

  /* The maths. Only rows with a weight, a score and an "out of" count as graded. */
  function calc(items) {
    var gw = 0, ep = 0, total = 0;
    items.forEach(function (it) {
      var w = num(it.weight) || 0, s = num(it.score), o = num(it.out);
      total += w;
      if (s !== null && o !== null && o > 0 && w > 0) { gw += w; ep += w * s / o; }
    });
    var remaining = Math.max(0, 100 - gw);
    return { gw: gw, ep: ep, total: total, remaining: remaining, standing: gw > 0 ? ep / gw * 100 : null };
  }
  /* "What do I need?" sentence (HTML) and its tone, shared by the page and the popup. */
  function needAnswer(r, t) {
    var T = pct(t) + '%';
    if (r.remaining < 0.0001) {
      return { cls: r.ep >= t ? 'good' : 'bad', html: 'No work left. Your final is <b>' + f1(r.ep) + '%</b> (' + letter(r.ep) + '), ' + (r.ep >= t ? 'which meets' : 'which misses') + ' the ' + T + ' target.' };
    }
    var req = (t - r.ep) / r.remaining * 100;
    if (req <= 0) return { cls: 'good', html: 'Already secured. Even <b>0%</b> on the remaining ' + f1(r.remaining) + '% keeps you at or above ' + T + '.' };
    if (req > 100) return { cls: 'bad', html: 'Out of reach. You would need <b>' + f1(req) + '%</b> on the rest. The best possible final is <b>' + f1(r.ep + r.remaining) + '%</b>.' };
    return { cls: '', html: 'Average of <b>' + f1(req) + '%</b> (' + letter(req) + ') across the remaining ' + f1(r.remaining) + '% of the course.' };
  }

  /* ---------- saving (quietly, a moment after you stop typing) ---------- */
  var timers = {}, inflight = {}, dirty = {};
  function payload(c) {
    return { id: c.id, name: c.name, target: c.target, scen: c.scen, items: c.items.map(function (i) { return { id: i.id, name: i.name, weight: i.weight, score: i.score, out: i.out }; }) };
  }
  function queueSave(c, now) {
    if (!c || !c.id) return;
    clearTimeout(timers[c.id]);
    timers[c.id] = setTimeout(function () { doSave(c); }, now ? 0 : 700);
  }
  function doSave(c) {
    if (inflight[c.id]) { dirty[c.id] = true; return; }
    inflight[c.id] = true; dirty[c.id] = false;
    DucksDB.saveGradeCourse(db, payload(c)).then(function () { A.changed(); })
      .catch(function (err) { if (err && err.status === 404) return; A.toast(err); })
      .then(function () { inflight[c.id] = false; if (dirty[c.id] && courses.indexOf(c) >= 0) doSave(c); });
  }
  function flushAll() { courses.forEach(function (c) { if (timers[c.id]) { clearTimeout(timers[c.id]); timers[c.id] = 0; doSave(c); } }); }
  document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') flushAll(); });
  window.addEventListener('pagehide', flushAll);

  /* ---------- drawing ---------- */
  function rowHTML(it) {
    var p = it.id;
    return '<div class="lrow" data-id="' + esc(p) + '">' +
      '<div class="name"><span class="lbl">Assessment</span><input class="f txt" data-k="name" type="text" maxlength="80" aria-label="Assessment name" placeholder="e.g. Midterm" value="' + esc(it.name) + '"></div>' +
      '<div><span class="lbl">Weight %</span><input class="f" data-k="weight" type="number" inputmode="decimal" min="0" step="any" aria-label="Weight in percent" value="' + esc(it.weight) + '"></div>' +
      '<div><span class="lbl">Score</span><input class="f" data-k="score" type="number" inputmode="decimal" min="0" step="any" aria-label="Score" placeholder="—" value="' + esc(it.score) + '"></div>' +
      '<div><span class="lbl">Out of</span><input class="f" data-k="out" type="number" inputmode="decimal" min="0" step="any" aria-label="Out of" value="' + esc(it.out) + '"></div>' +
      '<div class="resc"><span class="lbl">Result</span><div class="res none" data-res>—</div></div>' +
      '<button class="xbtn" type="button" data-del aria-label="Remove assessment" title="Remove">&times;</button></div>';
  }
  function renderSwitch() {
    var sel = $('switch');
    sel.innerHTML = courses.map(function (c, i) { return '<option value="' + i + '"' + (i === active ? ' selected' : '') + '>' + esc(c.name || 'Untitled') + '</option>'; }).join('');
    $('switch-wrap').hidden = !courses.length;
  }
  function renderAll() {
    var has = courses.length > 0;
    $('empty').hidden = has; $('card').hidden = !has;
    $('add-course').hidden = !has;
    renderSwitch();
    if (!has) return;
    var c = cur();
    $('c-name').textContent = c.name;
    $('rows').innerHTML = c.items.length ? c.items.map(rowHTML).join('') : '<p class="hint" style="padding:8px 0">No assessments yet. Use “Add Grades” to add one.</p>';
    disarm();
    update();
  }

  function update() {
    var c = cur(); if (!c) return;
    var r = calc(c.items), t = num(c.target); if (t === null) t = 0;

    var rows = document.querySelectorAll('#rows .lrow');
    c.items.forEach(function (it, i) {
      var el = rows[i] && rows[i].querySelector('[data-res]'); if (!el) return;
      var s = num(it.score), o = num(it.out);
      if (s !== null && o !== null && o > 0) { var p = s / o * 100; el.className = 'res'; el.innerHTML = f1(p) + '% <span class="l">' + letter(p) + '</span>'; }
      else { el.className = 'res none'; el.textContent = '—'; }
    });

    $('msg').textContent = greeting(r.standing);
    if (r.standing === null) {
      $('bignum').innerHTML = '—'; $('letter').textContent = '';
      $('curnote').textContent = 'Add a score and weight to see your current grade.';
    } else {
      $('bignum').innerHTML = f1(r.standing) + ' <span class="pc">%</span>'; $('letter').textContent = letter(r.standing);
      $('curnote').textContent = 'Average across the ' + f1(r.gw) + '% of the course that is graded.';
    }

    var tot = Math.max(100, r.ep + r.remaining);
    $('b-earned').style.width = (r.ep / tot * 100) + '%';
    $('b-left').style.width = (r.remaining / tot * 100) + '%';
    var tp = Math.min(100, Math.max(0, t)) / tot * 100, mk = $('mark'), ml = $('mark-lbl');
    mk.style.left = tp + '%'; ml.textContent = pct(t) + '%';
    ml.style.transform = tp < 8 ? 'none' : tp > 92 ? 'translateX(-100%)' : 'translateX(-50%)';
    ml.style.left = tp < 8 ? '-1px' : tp > 92 ? '2px' : '0';

    $('s-earned').textContent = f1(r.ep) + ' / 100';
    $('s-graded').textContent = f1(r.gw) + '%';
    $('s-left').textContent = f1(r.remaining) + '%';
    $('s-range').textContent = f1(r.ep) + ' to ' + f1(r.ep + r.remaining) + '%';

    var w = $('wwarn');
    if (Math.abs(r.total - 100) > 0.01 && r.total > 0) {
      w.hidden = false;
      w.textContent = r.total < 100 ? 'Weights add up to ' + f1(r.total) + '%. The missing ' + f1(100 - r.total) + '% is counted as work still to come.'
        : 'Weights add up to ' + f1(r.total) + '%, which is over 100%. Check each weight.';
    } else w.hidden = true;
  }

  /* ---------- ledger events ---------- */
  $('rows').addEventListener('input', function (e) {
    var k = e.target.getAttribute('data-k'); if (!k) return;
    var id = e.target.closest('.lrow').getAttribute('data-id'), c = cur();
    var it = c.items.filter(function (x) { return x.id === id; })[0]; if (!it) return;
    it[k] = e.target.value; queueSave(c); update();
  });
  $('rows').addEventListener('click', function (e) {
    if (!e.target.closest('[data-del]')) return;
    var id = e.target.closest('.lrow').getAttribute('data-id'), c = cur();
    c.items = c.items.filter(function (x) { return x.id !== id; });
    queueSave(c, true); renderAll();
  });
  $('add-row').addEventListener('click', function () {
    var c = cur(); if (!c) return;
    if (c.items.length >= MAX_ITEMS) { A.toast(new Error('A course can have up to ' + MAX_ITEMS + ' assessments.')); return; }
    var it = blankItem(); c.items.push(it); queueSave(c); renderAll();
    var el = document.querySelector('#rows .lrow[data-id="' + it.id + '"] input'); if (el) el.focus();
  });
  $('switch').addEventListener('change', function (e) { active = +e.target.value; renderAll(); });

  var armed = false, armTimer = null;
  function disarm() { armed = false; clearTimeout(armTimer); var b = $('del-course'); if (b) { b.classList.remove('armed'); $('del-lbl').textContent = 'Delete Course'; } }
  $('del-course').addEventListener('click', function () {
    var c = cur(); if (!c) return;
    if (!armed) { armed = true; $('del-course').classList.add('armed'); $('del-lbl').textContent = 'Click again to delete'; armTimer = setTimeout(disarm, 3500); return; }
    disarm();
    clearTimeout(timers[c.id]);
    DucksDB.deleteGradeCourse(db, c.id).then(function () {
      courses.splice(active, 1); active = Math.max(0, Math.min(active, courses.length - 1)); renderAll(); A.changed();
    }).catch(function (err) { if (err && err.status === 404) { courses.splice(active, 1); active = Math.max(0, Math.min(active, courses.length - 1)); renderAll(); } else A.toast(err); });
  });

  $('scale').innerHTML = SCALE.map(function (s, i) {
    var hi = i === 0 ? 100 : SCALE[i - 1][0] - 1;
    return '<span><b>' + s[1] + '</b>' + s[0] + '–' + hi + '</span>';
  }).join('');

  /* ---------- the popup: "Adding a Course" (and "Target & what-if" for an existing course) ---------- */
  var dlg = $('gdlg'), mode = 'add';
  function popItems() { return mode === 'edit' && cur() ? cur().items : []; }
  function popUpdate() {
    var r = calc(popItems()), t = num($('g-target').value);
    var need = $('g-need');
    if (t === null) { need.className = 'ans'; need.innerHTML = 'Enter the grade you are aiming for.'; }
    else { var a = needAnswer(r, t); need.className = 'ans ' + a.cls; need.innerHTML = a.html; }
    var sc = num($('g-scen').value); if (sc === null) sc = 0;
    $('g-scenv').textContent = sc + '%';
    var fin = r.ep + r.remaining * sc / 100;
    $('g-proj').innerHTML = 'Projected Final: <b>' + f1(fin) + '%</b> (' + letter(fin) + ').';
    document.querySelectorAll('#g-chips button').forEach(function (b) { b.setAttribute('aria-pressed', String(t !== null && +b.getAttribute('data-v') === t)); });
  }
  var known = [];
  var OTHER = '__other__';
  // Add mode: pick one of your own courses (not already in the Grade Book). "Other" lets you type a name.
  function buildPick() {
    var pick = $('g-pick'), have = {};
    courses.forEach(function (c) { have[String(c.name).toLowerCase()] = true; });
    var opts = known.filter(function (n) { return !have[n.toLowerCase()]; });
    if (!opts.length) { pick.hidden = true; $('g-name').hidden = false; return; }
    pick.innerHTML = '<option value="">Choose one of your courses…</option>' +
      opts.map(function (n) { return '<option value="' + esc(n) + '">' + esc(n) + '</option>'; }).join('') +
      '<option value="' + OTHER + '">Other (type a name)…</option>';
    pick.value = '';
    pick.hidden = false; $('g-name').hidden = true; $('g-name').value = '';
  }
  function pickChanged() {
    var v = $('g-pick').value;
    if (v === OTHER) { $('g-name').hidden = false; $('g-name').value = ''; $('g-name').focus(); }
    else { $('g-name').hidden = true; $('g-name').value = v; }
  }
  $('g-pick').addEventListener('change', pickChanged);
  function openPopup(m) {
    mode = m; var c = cur();
    $('g-title').textContent = m === 'add' ? 'Adding a Course:' : 'Target & What-if:';
    $('g-save').textContent = m === 'add' ? 'Add the Course' : 'Save';
    $('g-err').textContent = '';
    $('g-name').value = m === 'add' ? '' : c.name;
    if (m === 'add') buildPick(); else { $('g-pick').hidden = true; $('g-name').hidden = false; }
    $('g-target').value = m === 'add' ? 80 : c.target;
    $('g-scen').value = m === 'add' ? 75 : c.scen;
    popUpdate();
    if (dlg.showModal) dlg.showModal(); else dlg.setAttribute('open', '');
    (m === 'add' && !$('g-pick').hidden ? $('g-pick') : $('g-name')).focus();
    if (m === 'add') {                  // courses you added to the calendar a moment ago
      DucksDB.grades(db).then(function (d) { known = d.known_courses || known; if (dlg.open && mode === 'add' && !$('g-pick').hidden && !$('g-pick').value) buildPick(); }).catch(function () {});
    }
  }
  $('add-course').addEventListener('click', function () { openPopup('add'); });
  $('add-first').addEventListener('click', function () { openPopup('add'); });
  $('goals').addEventListener('click', function () { if (cur()) openPopup('edit'); });
  $('g-close').addEventListener('click', function () { dlg.close(); });
  $('g-cancel').addEventListener('click', function () { dlg.close(); });
  dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });
  $('g-target').addEventListener('input', popUpdate);
  $('g-scen').addEventListener('input', popUpdate);
  $('g-chips').innerHTML = CHIPS.map(function (v) { return '<button type="button" data-v="' + v + '" aria-pressed="false">' + v + ' %</button>'; }).join('');
  $('g-chips').addEventListener('click', function (e) {
    var b = e.target.closest('button'); if (!b) return;
    $('g-target').value = b.getAttribute('data-v'); popUpdate();
  });
  $('gform').addEventListener('submit', function (e) {
    e.preventDefault();
    var err = $('g-err'), save = $('g-save'), name = $('g-name').value.trim(), t = num($('g-target').value), sc = num($('g-scen').value);
    err.textContent = '';
    if (!name) {
      err.textContent = mode === 'add' && !$('g-pick').hidden && !$('g-pick').value ? 'Please choose a course.' : 'Please give the course a name.';
      (mode === 'add' && !$('g-pick').hidden && !$('g-pick').value ? $('g-pick') : $('g-name')).focus(); return;
    }
    if (t === null || t < 0 || t > 100) { err.textContent = 'Target grade must be between 0 and 100.'; $('g-target').focus(); return; }
    if (sc === null) sc = 75;
    if (mode === 'edit') {
      var c = cur(); c.name = name; c.target = t; c.scen = sc;
      queueSave(c, true); dlg.close(); renderAll(); return;
    }
    if (courses.length >= MAX_COURSES) { err.textContent = 'You have reached the limit of ' + MAX_COURSES + ' courses.'; return; }
    save.disabled = true;
    DucksDB.saveGradeCourse(db, { name: name, target: t, scen: sc, items: [] }).then(function (r) {
      r.items = (r.items || []).map(cleanItem); courses.push(r); active = courses.length - 1;
      dlg.close(); renderAll(); A.changed();
      var b = $('add-row'); if (b) b.focus();
    }).catch(function (ex) { err.textContent = DucksDB.friendlyError(ex); })
      .then(function () { save.disabled = false; });
  });

  /* ---------- start up ---------- */
  function load(d) {
    var keep = cur() && cur().id;
    profile = d.profile || profile;
    known = d.known_courses || known;
    courses = d.courses.map(function (c) { c.items = (c.items || []).map(cleanItem); return c; });
    active = 0;
    for (var i = 0; i < courses.length; i++) if (courses[i].id === keep) active = i;
  }
  function showProblem(err) {
    var g = $('gate');
    if (err && err.message === 'NO_KEY') { DucksAuth.showProblem(g, err); return; }
    g.hidden = false; $('content').hidden = true;
    g.innerHTML = '<div class="notice" role="alert"><h2>We hit a snag</h2><p>' + esc(DucksDB.friendlyError(err)) + '</p><button class="btn" type="button" onclick="location.reload()">Try again</button></div>';
  }
  DucksAuth.load().then(function (C) {
    Clerk = C;
    if (!Clerk.isSignedIn) { location.replace(routes.signIn || 'signin.html'); return; }
    A.initMenu(Clerk, routes);
    return DucksDB.client(Clerk).then(function (c) { db = c; return DucksDB.grades(db); }).then(function (d) {
      if (!d.profile || !d.profile.onboarded) { location.replace(routes.onboarding || 'onboarding.html'); return; }
      load(d); renderAll();
      $('gate').hidden = true; $('content').hidden = false;
      // Another tab changed your courses: reload them, unless you are in the middle of typing here.
      A.onChanged(function () {
        var ae = document.activeElement;
        if (dlg.open || (ae && /INPUT|SELECT/.test(ae.tagName)) || Object.keys(inflight).some(function (k) { return inflight[k]; })) return;
        DucksDB.grades(db).then(function (d2) { load(d2); renderAll(); }).catch(function () {});
      });
    });
  }).catch(showProblem);
})();
