// Calendar page: FullCalendar 7 (breezy theme, amber palette) wired to the Ducks In A Row database.
// Requires: clerk-config.js, auth.js, db.js, app.js and the FullCalendar scripts loaded by calendar.html.
(function () {
  var routes = DucksAuth.routes, A = DucksApp, esc = A.esc;
  var $ = function (id) { return document.getElementById(id); };
  var Clerk, db, calendar = null;
  var state = { profile: null, events: [], classes: [] };

  /* ---------- the three urgency levels (names and colours as designed) ---------- */
  var URG = {
    urgent: { label: "Duckin' Urgent", color: '#DD2E44', text: '#FFFFFF' },
    soon: { label: 'Quack in a Bit', color: '#FDCB58', text: '#3A2A00' },
    chill: { label: 'No Need to Get Your Feathers Ruffled', color: '#78B159', text: '#10260A' }
  };
  var URG_ORDER = ['urgent', 'soon', 'chill'];
  var CLASS_LOOK = { label: 'Classes', color: '#4486BF', text: '#FFFFFF' };
  var DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
  var DAY_NAMES = { mon: 'Mon', tue: 'Tue', wed: 'Wed', thu: 'Thu', fri: 'Fri', sat: 'Sat', sun: 'Sun' };

  var hidden = {};
  try { hidden = JSON.parse(localStorage.getItem('ducks_cal_hidden') || '{}') || {}; } catch (e) { hidden = {}; }

  /* ---------- small helpers ---------- */
  function addDays(ymd, n) { var d = A.parseYmd(ymd); d.setDate(d.getDate() + n); return A.ymd(d); }
  function asDate(info, which) {                         // FullCalendar hands back Dates or ISO text: accept either
    var s = info[which + 'Str'];
    if (s) return new Date(s.length === 10 ? s + 'T00:00:00' : s);
    var v = info[which];
    return v instanceof Date ? v : new Date(String(v));
  }
  function note(msg) {
    var n = $('note'); n.textContent = msg; n.hidden = false;
    clearTimeout(note.t); note.t = setTimeout(function () { n.hidden = true; }, 9000);
  }
  function termLabel(code) {
    var m = /^(\d{4})(10|20|30)$/.exec(code || ''); if (!m) return code || '';
    return { '10': 'Winter', '20': 'Summer', '30': 'Fall' }[m[2]] + ' ' + m[1];
  }
  function termOptions(today) {                          // previous, current and the next two Carleton terms
    var y = +today.slice(0, 4), mo = +today.slice(5, 7);
    var cur = y * 3 + (mo <= 4 ? 0 : mo <= 8 ? 1 : 2), out = [];
    for (var i = cur - 1; i <= cur + 2; i++) {
      var yr = Math.floor(i / 3), part = ['10', '20', '30'][i % 3];
      out.push({ code: String(yr) + part, label: termLabel(String(yr) + part), current: i === cur });
    }
    return out;
  }
  function when(e) {                                     // "Wed, Nov 25 · 10:00 AM–11:30 AM"
    var d = A.parseYmd(e.event_date).toLocaleDateString('en-CA', { weekday: 'short', month: 'short', day: 'numeric' });
    if (e.all_day) {
      return d + ' · All day' + (e.end_date ? ' → ' + A.parseYmd(e.end_date).toLocaleDateString('en-CA', { weekday: 'short', month: 'short', day: 'numeric' }) : '');
    }
    if (e.end_date) return d + ', ' + A.fmtTime(e.start_time) + ' → ' + A.dateLabel(e.end_date) + ', ' + A.fmtTime(e.end_time);
    return d + ' · ' + A.fmtRange(e.start_time, e.end_time);
  }

  /* ---------- turning database rows into calendar events ---------- */
  function eventInput(e) {
    var u = URG[e.urgency] || URG.chill, start, end = null;
    if (e.all_day || !e.start_time) {
      start = e.event_date; if (e.end_date) end = addDays(e.end_date, 1);          // all-day ends are "up to, not including"
    } else {
      start = e.event_date + 'T' + e.start_time;
      if (e.end_time) end = (e.end_date || e.event_date) + 'T' + e.end_time;
    }
    return { id: 'e-' + e.id, title: e.title, start: start, end: end, allDay: !!(e.all_day || !e.start_time),
      color: u.color, contrastColor: u.text, extendedProps: { kind: 'event', dbId: e.id } };
  }
  function classInputs(from, to) {                       // weekly classes, spread over the dates being shown
    var out = [];
    for (var d = new Date(from.getFullYear(), from.getMonth(), from.getDate()); d < to; d.setDate(d.getDate() + 1)) {
      var ds = A.ymd(d), key = DAY_KEYS[d.getDay()];
      state.classes.forEach(function (c) {
        if (c.days.indexOf(key) === -1) return;
        if (c.start_date && ds < c.start_date) return;
        if (c.end_date && ds > c.end_date) return;
        var allDay = c.all_day || !c.start_time;
        out.push({ id: 'c-' + c.id + '-' + ds, title: c.title, allDay: allDay,
          start: allDay ? ds : ds + 'T' + c.start_time, end: allDay ? null : (c.end_time ? ds + 'T' + c.end_time : null),
          color: CLASS_LOOK.color, contrastColor: CLASS_LOOK.text, extendedProps: { kind: 'class', dbId: c.id } });
      });
    }
    return out;
  }
  function buildEvents(info) {
    var out = state.events.filter(function (e) { return !hidden[e.urgency]; }).map(eventInput);
    if (!hidden.classes) out = out.concat(classInputs(asDate(info, 'start'), asDate(info, 'end')));
    return out;
  }

  /* ---------- the legend (also the show/hide switches) and the course chips ---------- */
  function renderLegend() {
    var items = URG_ORDER.map(function (k) { return { key: k, label: URG[k].label, color: URG[k].color }; })
      .concat([{ key: 'classes', label: CLASS_LOOK.label, color: CLASS_LOOK.color }]);
    $('legend').innerHTML = items.map(function (it) {
      return '<button type="button" class="lg" data-lg="' + it.key + '" aria-pressed="' + (hidden[it.key] ? 'false' : 'true') + '" style="--c:' + it.color + '">' +
        '<span class="dot" aria-hidden="true"></span>' + esc(it.label) + '</button>';
    }).join('');
  }
  $('legend').addEventListener('click', function (e) {
    var b = e.target.closest('[data-lg]'); if (!b) return;
    var k = b.getAttribute('data-lg'); hidden[k] = !hidden[k];
    try { localStorage.setItem('ducks_cal_hidden', JSON.stringify(hidden)); } catch (x) {}
    b.setAttribute('aria-pressed', hidden[k] ? 'false' : 'true');
    calendar.refetchEvents();
  });

  function renderCourses() {
    var groups = {}, order = [];
    state.classes.forEach(function (c) {
      if (c.source !== 'carleton' || !c.course_key) return;
      var k = c.course_key + '|' + c.term;
      if (!groups[k]) { groups[k] = { key: c.course_key, term: c.term, sections: [] }; order.push(k); }
      if (c.section && groups[k].sections.indexOf(c.section) === -1) groups[k].sections.push(c.section);
    });
    $('courses').innerHTML = order.map(function (k) {
      var g = groups[k];
      return '<span class="cchip">' + esc(g.key) + ': ' + esc(g.sections.join(', ')) + ' <small>' + esc(termLabel(g.term)) + '</small>' +
        '<button type="button" data-rm-course="' + esc(g.key) + '" data-term="' + esc(g.term) + '" aria-label="Remove ' + esc(g.key) + ' (' + esc(termLabel(g.term)) + ')" title="Remove this course">✕</button></span>';
    }).join('');
  }
  $('courses').addEventListener('click', function (e) {
    var b = e.target.closest('[data-rm-course]'); if (!b) return;
    removeCourse(b.getAttribute('data-rm-course'), b.getAttribute('data-term'));
  });
  function removeCourse(key, term) {
    if (!window.confirm('Remove ' + key + ' (' + termLabel(term) + ') from your calendar?')) return Promise.resolve();
    return DucksDB.deleteCourse(db, key, term).then(function () { dlg.close(); return changed(); }).catch(A.toast);
  }

  /* ---------- loading and refreshing ---------- */
  function load() {
    return DucksDB.calendar(db).then(function (d) {
      if (!d.profile || !d.profile.onboarded) { location.replace(routes.onboarding || 'onboarding.html'); return false; }
      state.profile = d.profile; state.events = d.events; state.classes = d.classes;
      renderCourses();
      if (calendar) calendar.refetchEvents();
      return true;
    });
  }
  function changed() { return load().then(function () { A.changed(); }); }

  /* ---------- the dialog (one dialog, several jobs) ---------- */
  var dlg = $('dlg'), cur = null;
  function openDlg(o) {
    cur = o;
    $('dlg-title').textContent = o.title; $('dlg-err').textContent = '';
    $('dlg-fields').innerHTML = o.html;
    var save = $('dlg-save'); save.hidden = !o.saveLabel; save.textContent = o.saveLabel || ''; save.disabled = false;
    $('dlg-cancel').textContent = o.cancelLabel || 'Cancel';
    [['dlg-del', o.del], ['dlg-del2', o.del2]].forEach(function (p) {
      var b = $(p[0]); b.hidden = !p[1]; if (p[1]) b.textContent = p[1].label;
    });
    if (o.init) o.init();
    if (dlg.showModal) dlg.showModal(); else dlg.setAttribute('open', '');
    var first = $('dlg-fields').querySelector('input[type=text],input[type=url]'); if (first) first.focus();
  }
  function fail(msg) { $('dlg-err').textContent = msg; }
  function busy(on, label) { var s = $('dlg-save'); s.disabled = on; if (label) s.textContent = on ? label : cur.saveLabel; }
  $('dlg-cancel').addEventListener('click', function () { dlg.close(); });
  dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });
  dlg.addEventListener('close', function () { if (calendar) calendar.unselect(); });
  $('dlg-del').addEventListener('click', function () { if (cur && cur.del) cur.del.fn(); });
  $('dlg-del2').addEventListener('click', function () { if (cur && cur.del2) cur.del2.fn(); });
  $('dlg-form').addEventListener('submit', function (e) { e.preventDefault(); if (cur && cur.onSubmit) cur.onSubmit(); });

  /* ---------- add / edit an event ---------- */
  function urgencyHtml(sel) {
    return '<fieldset class="urg"><legend>How urgent is it?</legend>' + URG_ORDER.map(function (k) {
      return '<label class="urg-opt" style="--c:' + URG[k].color + '"><input type="radio" name="urgency" value="' + k + '"' + (k === sel ? ' checked' : '') + '>' +
        '<span class="dot" aria-hidden="true"></span>' + esc(URG[k].label) + '</label>';
    }).join('') + '</fieldset>';
  }
  function eventFormHtml(ev, repeatable) {
    return '<div class="field"><label for="f-title">Title</label><input type="text" id="f-title" maxlength="160" placeholder="e.g. COMP 1405 midterm" autocomplete="off"></div>' +
      urgencyHtml(ev ? ev.urgency : 'chill') +
      '<label class="check"><input type="checkbox" id="f-allday"> All day</label>' +
      '<div class="row2"><div class="field"><label for="f-date">Start date</label><input type="date" id="f-date"></div>' +
      '<div class="field"><label for="f-start">Start time</label><input type="time" id="f-start"></div></div>' +
      '<div class="row2"><div class="field"><label for="f-edate">End date <span class="opt">(optional)</span></label><input type="date" id="f-edate"></div>' +
      '<div class="field"><label for="f-end">End time <span class="opt">(optional)</span></label><input type="time" id="f-end"></div></div>' +
      (repeatable ? '<div class="row2"><div class="field"><label for="f-repeat">Repeat</label><select id="f-repeat"><option value="none">Does not repeat</option><option value="weekly">Every week</option></select></div>' +
        '<div class="field" id="f-until-wrap" hidden><label for="f-until">Repeat until</label><input type="date" id="f-until"></div></div>' : '') +
      '<div class="field"><label for="f-loc">Location <span class="opt">(optional)</span></label><input type="text" id="f-loc" maxlength="200" autocomplete="off"></div>' +
      '<div class="field"><label for="f-notes">Notes <span class="opt">(optional)</span></label><textarea id="f-notes" maxlength="1000"></textarea></div>';
  }
  function wireEventForm() {
    var all = $('f-allday');
    function sync() { $('f-start').disabled = $('f-end').disabled = all.checked; }
    all.addEventListener('change', sync); sync();
    var rep = $('f-repeat');
    if (rep) rep.addEventListener('change', function () { $('f-until-wrap').hidden = rep.value !== 'weekly'; });
  }
  function readEvent(repeatable) {
    var body = {
      title: $('f-title').value.trim(), urgency: (document.querySelector('input[name=urgency]:checked') || {}).value || 'chill',
      all_day: $('f-allday').checked, event_date: $('f-date').value, end_date: $('f-edate').value || null,
      start_time: $('f-start').value || null, end_time: $('f-end').value || null,
      location: $('f-loc').value.trim(), notes: $('f-notes').value.trim()
    };
    if (!body.title) return fail('Please give the event a title.');
    if (!body.event_date) return fail('Pick a start date.');
    if (body.end_date && body.end_date < body.event_date) return fail('The end date must be on or after the start date.');
    if (body.all_day) { body.start_time = body.end_time = null; }
    else {
      if (!body.start_time) return fail('Add a start time, or tick "All day".');
      if (body.end_date && !body.end_time) return fail('Add an end time, or clear the end date.');
      if (body.end_time && (body.end_date || body.event_date) + body.end_time <= body.event_date + body.start_time) return fail('The end must be after the start.');
    }
    if (repeatable && $('f-repeat').value === 'weekly') {
      body.repeat = 'weekly'; body.repeat_until = $('f-until').value;
      if (!body.repeat_until || body.repeat_until < body.event_date) return fail('Pick a "repeat until" date on or after the first date.');
    }
    return body;
  }
  function fill(ev, pre) {
    if (ev) {
      $('f-title').value = ev.title; $('f-allday').checked = !!ev.all_day; $('f-date').value = ev.event_date; $('f-edate').value = ev.end_date || '';
      $('f-start').value = ev.start_time || ''; $('f-end').value = ev.end_time || ''; $('f-loc').value = ev.location || ''; $('f-notes').value = ev.notes || '';
    } else if (pre) {
      $('f-allday').checked = !!pre.allDay; $('f-date').value = pre.date || ''; $('f-edate').value = pre.endDate || '';
      $('f-start').value = pre.time || ''; $('f-end').value = pre.endTime || '';
    }
    $('f-allday').dispatchEvent(new Event('change'));
  }
  function openAddEvent(pre) {
    openDlg({
      title: 'Add an event', saveLabel: 'Add event', html: eventFormHtml(null, true),
      init: function () { wireEventForm(); fill(null, pre || { date: A.nowInfo(state.profile && state.profile.timezone).date }); },
      onSubmit: function () {
        var body = readEvent(true); if (!body) return;
        busy(true, 'Saving…');
        DucksDB.createEvent(db, body).then(function (r) {
          dlg.close(); note(r.count > 1 ? 'Added ' + r.count + ' events.' + (r.capped ? ' (A repeat stops after 60 weeks.)' : '') : 'Event added.'); return changed();
        }).catch(function (ex) { fail(DucksDB.friendlyError(ex)); }).then(function () { busy(false); });
      }
    });
  }
  function openEditEvent(ev) {
    var inSeries = ev.series_id ? state.events.filter(function (x) { return x.series_id === ev.series_id; }).length : 0;
    openDlg({
      title: 'Edit event', saveLabel: 'Save', html: eventFormHtml(ev, false),
      init: function () { wireEventForm(); fill(ev); },
      onSubmit: function () {
        var body = readEvent(false); if (!body) return;
        busy(true, 'Saving…');
        DucksDB.updateEvent(db, ev.id, body).then(function () { dlg.close(); note('Event saved.'); return changed(); })
          .catch(function (ex) { fail(DucksDB.friendlyError(ex)); }).then(function () { busy(false); });
      },
      del: { label: 'Delete', fn: function () {
        DucksDB.deleteEvent(db, ev.id).then(function () { dlg.close(); return changed(); }).catch(function (ex) { fail(DucksDB.friendlyError(ex)); });
      } },
      del2: inSeries > 1 ? { label: 'Delete all ' + inSeries + ' in series', fn: function () {
        if (!window.confirm('Delete all ' + inSeries + ' events in this repeating series?')) return;
        DucksDB.deleteEventSeries(db, ev.id).then(function () { dlg.close(); return changed(); }).catch(function (ex) { fail(DucksDB.friendlyError(ex)); });
      } } : null
    });
  }

  /* ---------- look at a class ---------- */
  function openClass(c) {
    var days = c.days.map(function (d) { return DAY_NAMES[d] || d; }).join(', ');
    var rows = [['When', days + ' · ' + (c.all_day ? 'All day' : A.fmtRange(c.start_time, c.end_time))]];
    if (c.start_date && c.end_date) rows.push(['Runs', A.dateLabel(c.start_date) + ' to ' + A.dateLabel(c.end_date)]);
    if (c.kind) rows.push(['Type', c.kind]);
    if (c.instructor) rows.push(['Instructor', c.instructor]);
    if (c.crn) rows.push(['CRN', c.crn]);
    if (c.term) rows.push(['Term', termLabel(c.term)]);
    var carleton = c.source === 'carleton' && c.course_key;
    openDlg({
      title: c.title, saveLabel: null, cancelLabel: 'Close',
      html: '<dl class="facts">' + rows.map(function (r) { return '<div><dt>' + esc(r[0]) + '</dt><dd>' + esc(r[1]) + '</dd></div>'; }).join('') + '</dl>' +
        (carleton ? '<p class="lg-note">Removing a course removes all of its sections from your calendar and dashboard.</p>' : ''),
      del: carleton ? { label: 'Remove ' + c.course_key, fn: function () { removeCourse(c.course_key, c.term); } }
        : { label: 'Remove class', fn: function () { DucksDB.deleteClass(db, c.id).then(function () { dlg.close(); return changed(); }).catch(A.toast); } }
    });
  }

  /* ---------- add a Carleton course ---------- */
  function openAddCourse() {
    var today = A.nowInfo(state.profile && state.profile.timezone).date;
    var opts = termOptions(today).map(function (t) {
      return '<option value="' + t.code + '"' + (t.current ? ' selected' : '') + '>' + esc(t.label) + '</option>';
    }).join('');
    openDlg({
      title: 'Add a Carleton course', saveLabel: 'Add course',
      html: '<div class="field"><label for="c-term">Term</label><select id="c-term">' + opts + '</select></div>' +
        '<div class="field"><label for="c-code">Course code</label><input type="text" id="c-code" maxlength="12" placeholder="SYSC 1006" autocomplete="off"></div>' +
        '<div class="row2"><div class="field"><label for="c-sec">Lecture section</label><input type="text" id="c-sec" maxlength="6" placeholder="A" autocomplete="off"></div>' +
        '<div class="field"><label for="c-labs">Lab / tutorial <span class="opt">(optional)</span></label><input type="text" id="c-labs" maxlength="40" placeholder="A2 or A2, T1" autocomplete="off"></div></div>' +
        '<p class="hint">Looks the course up in Carleton’s public schedule and puts its weekly meeting times on your calendar. Seats and availability are never checked.</p>',
      onSubmit: function () {
        var code = $('c-code').value.trim(), lec = $('c-sec').value.trim();
        if (!/^[A-Za-z]{3,4}\s*-?\s*\d{4,5}$/.test(code)) return fail('Course code should look like SYSC 1006.');
        if (!lec) return fail('Enter the lecture section (for example A).');
        busy(true, 'Looking it up…');
        DucksDB.addCourse(db, { term: $('c-term').value, code: code, lecture: lec, labs: $('c-labs').value.trim() }).then(function (r) {
          dlg.close();
          var msg = 'Added ' + r.course + ' (' + r.sections.join(', ') + ').';
          if (r.missing && r.missing.length) msg += ' Couldn’t find: ' + r.missing.join(', ') + '.';
          if (r.note) msg += ' ' + r.note;
          note(msg); return changed();
        }).catch(function (ex) { fail(DucksDB.friendlyError(ex)); }).then(function () { busy(false); });
      }
    });
  }

  /* ---------- add a Brightspace calendar link ---------- */
  function openAddIcs() {
    openDlg({
      title: 'Add a Brightspace link', saveLabel: 'Sync link',
      html: '<div class="field"><label for="i-url">Brightspace calendar link</label><input type="url" id="i-url" placeholder="https://brightspace.carleton.ca/d2l/…" autocomplete="off" spellcheck="false"></div>' +
        '<p class="hint">In Brightspace, open the Calendar, choose <b>Subscribe</b>, and copy the link. Paste it here again any time to bring in new due dates. Events already added are updated, not doubled, and keep the urgency you chose.</p>',
      onSubmit: function () {
        var url = $('i-url').value.trim();
        if (!url) return fail('Paste your Brightspace link first.');
        busy(true, 'Fetching…');
        DucksDB.importIcs(db, url).then(function (r) {
          dlg.close();
          note(r.found ? 'Synced ' + r.saved + ' Brightspace event' + (r.saved === 1 ? '' : 's') + '.' + (r.skipped ? ' ' + r.skipped + ' were skipped because you reached the event limit.' : '') +
            ' They start as “' + URG.chill.label + '”. Click one to change it.' : 'No events were found at that link.');
          return changed();
        }).catch(function (ex) { fail(DucksDB.friendlyError(ex)); }).then(function () { busy(false); });
      }
    });
  }

  $('add-event').addEventListener('click', function () { openAddEvent(); });
  $('add-course').addEventListener('click', openAddCourse);
  $('add-ics').addEventListener('click', openAddIcs);

  /* ---------- the calendar itself ---------- */
  var narrow = window.matchMedia('(max-width:640px)');
  function toolbar() {
    return narrow.matches
      ? { left: 'prev,next', center: 'title', right: 'today' }
      : { left: 'today', center: 'prev title next', right: 'dayGridMonth,timeGridWeek,timeGridDay,listMonth' };
  }
  function splitStr(s) { return { date: s.slice(0, 10), time: s.length > 10 ? s.slice(11, 16) : null }; }
  function buildCalendar() {
    calendar = new FullCalendar.Calendar($('calendar'), {
      initialView: narrow.matches ? 'listMonth' : 'dayGridMonth',
      firstDay: state.profile && state.profile.week_starts_on === 'monday' ? 1 : 0,
      headerToolbar: toolbar(),
      buttons: { today: { text: 'Today' }, dayGridMonth: { text: 'Month' }, timeGridWeek: { text: 'Week' }, timeGridDay: { text: 'Day' }, listMonth: { text: 'List' } },
      height: narrow.matches ? 640 : 760,
      navLinks: true, nowIndicator: true, dayMaxEvents: 4, scrollTime: '08:00:00',
      selectable: true, selectMirror: true,
      events: function (info, ok) { ok(buildEvents(info)); },
      eventClick: function (info) {
        if (info.jsEvent && info.jsEvent.preventDefault) info.jsEvent.preventDefault();
        var p = info.event.extendedProps || {};
        if (p.kind === 'class') {
          var c = state.classes.filter(function (x) { return x.id === p.dbId; })[0]; if (c) openClass(c);
        } else {
          var e = state.events.filter(function (x) { return x.id === p.dbId; })[0]; if (e) openEditEvent(e);
        }
      },
      select: function (info) {
        var s = splitStr(info.startStr || A.ymd(asDate(info, 'start'))), en = splitStr(info.endStr || A.ymd(asDate(info, 'end')));
        var pre;
        if (info.allDay) {
          var last = addDays(en.date, -1);
          pre = { allDay: true, date: s.date, endDate: last > s.date ? last : '' };
        } else {
          pre = { allDay: false, date: s.date, time: s.time, endDate: en.date !== s.date ? en.date : '', endTime: en.time };
        }
        openAddEvent(pre);
      }
    });
    calendar.render();
    narrow.addEventListener('change', function () {
      calendar.setOption('headerToolbar', toolbar());
      calendar.setOption('height', narrow.matches ? 640 : 760);
      calendar.changeView(narrow.matches ? 'listMonth' : 'dayGridMonth');
    });
  }

  /* ---------- start up ---------- */
  function showProblem(err) {
    var g = $('gate');
    if (err && err.message === 'NO_KEY') { DucksAuth.showProblem(g, err); return; }
    g.innerHTML = '<div class="notice" role="alert"><h2>We hit a snag</h2><p>' + esc(DucksDB.friendlyError(err)) + '</p><button class="btn" type="button" onclick="location.reload()">Try again</button></div>';
  }
  DucksAuth.load().then(function (C) {
    Clerk = C;
    if (!Clerk.isSignedIn) { location.replace(routes.signIn || 'signin.html'); return; }
    A.initMenu(Clerk, routes);
    return DucksDB.client(Clerk).then(function (c) { db = c; return load(); }).then(function (ok) {
      if (!ok) return;
      if (typeof FullCalendar === 'undefined') throw new Error('The calendar could not load. Check your internet connection and reload.');
      renderLegend();
      $('gate').hidden = true; $('content').hidden = false;
      buildCalendar();
      A.onChanged(load);                    // the dashboard or another tab changed something: reload from the database
    });
  }).catch(showProblem);
})();
