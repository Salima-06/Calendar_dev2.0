// Shared helpers for the signed-in pages (dashboard, task manager, ...).
// Requires: clerk-config.js, auth.js, db.js. Optional: greetings.js
(function () {
  var A = {};

  A.esc = function (s) {
    return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  };

  /* ---------- dates & times (always in the person's own time zone) ---------- */
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  A.ymd = function (d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); };
  A.parseYmd = function (s) { var a = s.split('-'); return new Date(+a[0], +a[1] - 1, +a[2]); };
  A.dayDiff = function (a, b) { return Math.round((A.parseYmd(a) - A.parseYmd(b)) / 86400000); };   // a - b in days
  A.dateLabel = function (s) { return A.parseYmd(s).toLocaleDateString('en-CA', { month: 'short', day: 'numeric' }); };
  A.fmtTime = function (t) {
    if (!t) return '';
    var h = +t.slice(0, 2), m = t.slice(3, 5), ap = h >= 12 ? 'PM' : 'AM';
    return ((h % 12) || 12) + ':' + m + ' ' + ap;
  };
  A.fmtRange = function (a, b) {
    if (!a) return 'All day';
    if (!b) return A.fmtTime(a);
    var sa = A.fmtTime(a), sb = A.fmtTime(b);
    return sa.slice(-2) === sb.slice(-2) ? sa.slice(0, -3) + '–' + sb : sa + '–' + sb;
  };

  // The wall clock in a time zone: { date:'YYYY-MM-DD', dayKey:'mon', hour, minute, wall: ms }.
  // "wall" is that clock reading expressed as a UTC timestamp, so two wall values can be subtracted.
  A.nowInfo = function (tz) {
    var opts = { year: 'numeric', month: '2-digit', day: '2-digit', weekday: 'short', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' };
    var parts;
    try { parts = new Intl.DateTimeFormat('en-CA', Object.assign({ timeZone: tz || undefined }, opts)).formatToParts(new Date()); }
    catch (e) { parts = new Intl.DateTimeFormat('en-CA', opts).formatToParts(new Date()); }
    var p = {}; parts.forEach(function (x) { p[x.type] = x.value; });
    var hour = parseInt(p.hour, 10) % 24;
    return {
      date: p.year + '-' + p.month + '-' + p.day, dayKey: p.weekday.slice(0, 3).toLowerCase(),
      hour: hour, minute: parseInt(p.minute, 10),
      wall: Date.UTC(+p.year, +p.month - 1, +p.day, hour, +p.minute, +p.second)
    };
  };

  /* ---------- tasks: how a task is described, and the order they are listed in ---------- */
  A.taskOrder = function (a, b) {
    if (!a.due_date !== !b.due_date) return a.due_date ? -1 : 1;                    // undated tasks last
    return (a.due_date || '').localeCompare(b.due_date || '') || (a.due_time || '99').localeCompare(b.due_time || '99') ||
      a.title.localeCompare(b.title);
  };
  function span(ms) {                       // 5h 20m, 42m, 3h
    var m = Math.max(0, Math.floor(ms / 60000)), h = Math.floor(m / 60); m = m % 60;
    return h ? (m ? h + 'h ' + m + 'm' : h + 'h') : (m + 'm');
  }
  // Returns { state: 'overdue' | 'soon' | 'later' | 'none', text }.
  //   soon    = due within the next 24 hours  -> "Due in 5h 20m"   (time left)
  //   overdue = past its due time             -> "Overdue · 3h ago" (time since)
  //   later   = further away                  -> "Nov 25, 3:00 PM"
  A.describeTask = function (t, info) {
    if (!t.due_date) return { state: 'none', text: '' };
    var timed = !!t.due_time;
    var p = t.due_date.split('-'), hm = (t.due_time || '23:59').split(':');
    var diff = Date.UTC(+p[0], +p[1] - 1, +p[2], +hm[0], +hm[1]) - info.wall;
    var days = A.dayDiff(t.due_date, info.date);
    if (diff < 0) {
      var ago;
      if (!timed) ago = days === -1 ? 'yesterday' : (-days) + ' days ago';          // date-only: count whole days
      else if (-diff < 60000) ago = 'just now';
      else if (-diff < 86400000) ago = span(-diff) + ' ago';
      else ago = Math.floor(-diff / 86400000) + (Math.floor(-diff / 86400000) === 1 ? ' day ago' : ' days ago');
      return { state: 'overdue', text: 'Overdue · ' + ago };
    }
    if (diff < 86400000) return { state: 'soon', text: 'Due in ' + (diff < 60000 ? 'under a minute' : span(diff)) };
    var label = days === 1 ? 'Tomorrow' : A.dateLabel(t.due_date);
    return { state: 'later', text: label + (timed ? ', ' + A.fmtTime(t.due_time) : '') };
  };

  /* ---------- who is signed in ---------- */
  A.firstName = function (Clerk, profile) {
    var n = (profile && profile.full_name) || (Clerk.user && (Clerk.user.firstName || Clerk.user.fullName)) || '';
    return n.trim().split(/\s+/)[0] || 'friend';
  };

  /* ---------- greeting (new one each sign-in, chosen from greetings.js) ---------- */
  function greetingPool(info) {
    var G = window.DUCKS_GREETINGS || {}, H = window.DUCKS_GREETING_HOURS || { earlyMorningBefore: 8, lateNightFrom: 22 };
    var names = { mon: 'monday', tue: 'tuesday', wed: 'wednesday', thu: 'thursday', fri: 'friday', sat: 'saturday', sun: 'sunday' };
    var key = names[info.dayKey];
    if (info.hour < H.earlyMorningBefore && (G.earlyMorning || []).length) key = 'earlyMorning';
    else if (info.hour >= H.lateNightFrom && (G.lateNight || []).length) key = 'lateNight';
    if (!(G[key] || []).length) key = 'fallback';
    return { key: key, list: (G[key] && G[key].length) ? G[key] : ['Hello'] };
  }
  function pickGreeting(Clerk, info) {
    var pool = greetingPool(info);
    var sid = (Clerk.session && Clerk.session.id) || 'anon', saved = null;
    try { saved = JSON.parse(sessionStorage.getItem('ducks_greeting') || 'null'); } catch (e) {}
    // Same sign-in + same time slot (+ text still in the list)? Keep it, so changing pages doesn't change it.
    if (saved && saved.sid === sid && saved.key === pool.key && pool.list.indexOf(saved.text) !== -1) return saved.text;
    var choices = pool.list.filter(function (t) { return !saved || t !== saved.text; });
    if (!choices.length) choices = pool.list;
    var text = choices[Math.floor(Math.random() * choices.length)];
    try { sessionStorage.setItem('ducks_greeting', JSON.stringify({ sid: sid, key: pool.key, text: text })); } catch (e) {}
    return text;
  }
  A.renderGreeting = function (el, Clerk, profile, info) {
    var text = pickGreeting(Clerk, info), name = A.firstName(Clerk, profile);
    el.textContent = '';
    var top = document.createElement('span'); top.className = 'g-top';
    top.textContent = text.split('{name}').join(name);                 // time-of-day greeting
    var sub = document.createElement('span'); sub.className = 'g-sub';
    sub.appendChild(document.createTextNode('Hello, '));
    var n = document.createElement('span'); n.className = 'name'; n.textContent = name;
    sub.appendChild(n);
    el.appendChild(sub); el.appendChild(top);                           // "Hello, name" first, greeting second
  };

  /* ---------- account menu (the gold button in the corner) ---------- */
  A.initMenu = function (Clerk, routes) {
    var $ = function (id) { return document.getElementById(id); };
    var btn = $('acct-btn'), menu = $('acct-menu');
    function items() { return Array.prototype.slice.call(menu.querySelectorAll('[role=menuitem]')); }
    function setMenu(open, focusFirst) {
      menu.hidden = !open; btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open && focusFirst) items()[0].focus();
    }
    btn.addEventListener('click', function () { setMenu(menu.hidden, false); });
    document.addEventListener('click', function (e) { if (!menu.hidden && !$('account').contains(e.target)) setMenu(false); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !menu.hidden) { setMenu(false); btn.focus(); return; }
      if (menu.hidden && document.activeElement === btn && e.key === 'ArrowDown') { e.preventDefault(); setMenu(true, true); return; }
      if (!menu.hidden && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
        e.preventDefault();
        var list = items(), i = list.indexOf(document.activeElement);
        i = e.key === 'ArrowDown' ? (i + 1) % list.length : (i - 1 + list.length) % list.length;
        list[i].focus();
      }
    });
    $('signout').addEventListener('click', function () {
      try { sessionStorage.removeItem('ducks_greeting'); sessionStorage.removeItem('ducks_quote'); } catch (e) {}
      Clerk.signOut({ redirectUrl: routes.afterSignOut || 'index.html' });
    });
  };

  /* ---------- error toast ---------- */
  A.toast = function (err) {
    var t = document.getElementById('toast'); if (!t) return;
    t.textContent = DucksDB.friendlyError(err); t.hidden = false;
    clearTimeout(A.toast.t); A.toast.t = setTimeout(function () { t.hidden = true; }, 8000);
  };

  /* ---------- keeping pages in step ----------
     Anything that changes a task calls DucksApp.changed(). Every other open page (another tab, the
     dashboard, the task manager) hears it and reloads from the database. Pages also reload when you
     come back to them, so what you see always matches the database for the person who is signed in. */
  var chan = null;
  try { chan = window.BroadcastChannel ? new BroadcastChannel('ducks-data') : null; } catch (e) {}
  A.changed = function () {
    try { if (chan) chan.postMessage({ t: Date.now() }); } catch (e) {}
    try { localStorage.setItem('ducks_changed', String(Date.now())); } catch (e) {}   // fallback for browsers without BroadcastChannel
  };
  A.onChanged = function (reload) {
    var busy = false, again = false;
    function run() {
      if (busy) { again = true; return; }
      busy = true;
      Promise.resolve().then(reload).catch(function () {}).then(function () { busy = false; if (again) { again = false; run(); } });
    }
    if (chan) chan.onmessage = run;
    window.addEventListener('storage', function (e) { if (e.key === 'ducks_changed') run(); });
    document.addEventListener('visibilitychange', function () { if (!document.hidden) run(); });
    window.addEventListener('focus', run);
  };
  // Calls fn once a minute while the page is visible (used to keep "due in 5h 20m" ticking).
  A.everyMinute = function (fn) {
    setInterval(function () { if (!document.hidden) fn(); }, 60000);
  };

  window.DucksApp = A;
})();
