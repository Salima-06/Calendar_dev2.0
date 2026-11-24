// Data helper for Ducks In A Row. Talks to the PHP API (api/index.php) on the same website.
// Every request carries the signed-in person's Clerk token, so the server knows who is asking.
// Requires: clerk-config.js, auth.js
(function () {
  function endpoint() { return window.DUCKS_API || 'api/index.php'; }
  function configured() { return !!endpoint(); }

  // Pages call DucksDB.client(Clerk) once after Clerk loads and pass the result to every call.
  function client(Clerk) { return Promise.resolve({ Clerk: Clerk }); }

  // Clerk can briefly answer "too many requests" when a token is asked for again and again (many tabs, quick
  // retries). Wait a moment and ask again a couple of times before giving up, and mark the error as Clerk's own
  // so the message can say whose limit it was.
  function getToken(Clerk) {
    var tries = 0;
    function attempt() {
      return Promise.resolve(Clerk.session ? Clerk.session.getToken() : null).catch(function (err) {
        var st = err && (err.status || (err.clerkError && err.status));
        if (st === 429 && tries < 2) { tries += 1; return new Promise(function (r) { setTimeout(r, 1500 * tries); }).then(attempt); }
        if (st === 429) err.fromClerk = true;
        throw err;
      });
    }
    return attempt();
  }

  function call(db, action, opts) {
    opts = opts || {};
    var Clerk = db.Clerk;
    return getToken(Clerk).then(function (token) {
      if (!token) { var e = new Error('NOT_SIGNED_IN'); throw e; }
      var url = endpoint() + '?action=' + encodeURIComponent(action) + (opts.query || '');
      return fetch(url, {
        method: opts.body === undefined ? 'GET' : 'POST',
        headers: {
          'Authorization': 'Bearer ' + token,
          'X-Clerk-Token': token,            // backup copy: some hosts remove the Authorization header
          'Content-Type': 'application/json'
        },
        body: opts.body === undefined ? undefined : JSON.stringify(opts.body),
        cache: 'no-store'
      });
    }).then(function (res) {
      return res.text().then(function (text) {
        var data = null;
        try { data = JSON.parse(text); } catch (e) { /* not JSON */ }
        if (!res.ok || data === null) {
          var err = new Error((data && data.error) || ('SERVER_' + res.status));
          err.status = res.status;
          throw err;
        }
        return data;
      });
    });
  }

  function normalizeCode(code) {
    var m = String(code || '').trim().toUpperCase().match(/^([A-Z]{3,4})\s*-?\s*(\d{4})$/);
    return m ? m[1] + ' ' + m[2] : null;
  }

  var meCache = new WeakMap();
  function me(db) {
    if (!meCache.has(db)) meCache.set(db, call(db, 'me'));
    return meCache.get(db);
  }

  window.DucksDB = {
    configured: configured,
    client: client,
    normalizeCode: normalizeCode,

    getProfile: function (db) { return me(db).then(function (r) { return r.profile; }); },
    listCourses: function (db) { return me(db).then(function (r) { return r.courses; }); },

    // profile + courses + open tasks + classes + upcoming events, in one request.
    dashboard: function (db, fromDate) { return call(db, 'dashboard', { query: '&from=' + encodeURIComponent(fromDate) }); },

    saveOnboarding: function (db, data) { meCache.delete(db); return call(db, 'save_onboarding', { body: data }); },

    // Task Manager page: { profile, open: [...], done: [...] }
    tasks: function (db) { return call(db, 'tasks'); },
    updateTask: function (db, id, t) { return call(db, 'update_task', { body: { id: id, title: t.title, due_date: t.due_date, due_time: t.due_time } }); },

    createTask: function (db, t) { return call(db, 'create_task', { body: t }); },
    setTaskDone: function (db, id, done) { return call(db, 'set_task_done', { body: { id: id, done: done } }); },
    deleteTask: function (db, id) { return call(db, 'delete_task', { body: { id: id } }); },

    createClass: function (db, c) { return call(db, 'create_class', { body: c }); },
    deleteClass: function (db, id) { return call(db, 'delete_class', { body: { id: id } }); },

    // Calendar page: { profile, events: [...], classes: [...] }
    calendar: function (db) { return call(db, 'calendar'); },
    updateEvent: function (db, id, e) { return call(db, 'update_event', { body: Object.assign({ id: id }, e) }); },
    deleteEventSeries: function (db, id) { return call(db, 'delete_event_series', { body: { id: id } }); },
    importIcs: function (db, url) { return call(db, 'import_ics', { body: { url: url } }); },
    addCourse: function (db, c) { return call(db, 'add_course', { body: c }); },
    deleteCourse: function (db, key, term) { return call(db, 'delete_course', { body: { course_key: key, term: term || null } }); },

    // Grade Book page: { profile, courses: [{ id, name, target, scen, items: [{ id, name, weight, score, out }] }] }
    grades: function (db) { return call(db, 'grades'); },
    saveGradeCourse: function (db, c) { return call(db, 'save_grade_course', { body: c }); },
    deleteGradeCourse: function (db, id) { return call(db, 'delete_grade_course', { body: { id: id } }); },

    createEvent: function (db, e) { return call(db, 'create_event', { body: e }); },
    deleteEvent: function (db, id) { return call(db, 'delete_event', { body: { id: id } }); },

    // Turns an error into a message a person can act on.
    friendlyError: function (err) {
      var msg = (err && err.message) || '';
      var st = err && err.status;
      if (msg === 'NOT_SIGNED_IN') return 'Please sign in again.';
      if (/^SERVER_(404|403)$/.test(msg)) return 'The website could not find its database service. Check that the "api" folder was uploaded next to index.html.';
      if (/^SERVER_/.test(msg)) return 'The server did not answer correctly (' + msg.replace('SERVER_', '') + '). Check that PHP is on and api/config.php is filled in.';
      if (st === 429 && err.fromClerk) return 'The sign-in service (Clerk) is asking us to slow down. Wait about a minute, then try again.';
      if (st === 429) return 'Please wait a few seconds and try again.';
      if (st === 401) return 'Your sign-in could not be verified. Try signing out and back in.';
      if (st === 503) return 'Could not reach Clerk to verify your sign-in. Please try again in a moment.';
      if (/Failed to fetch|NetworkError|Load failed/i.test(msg)) return 'Could not reach the server. Check your internet connection.';
      return msg || 'Something went wrong. Please try again.';
    }
  };
})();
