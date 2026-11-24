// Shared Clerk helper for Ducks In A Row (plain HTML, no build step).
// Pages call: DucksAuth.load().then(function (Clerk) { ... })
(function () {
  var key = window.CLERK_PUBLISHABLE_KEY || '';
  var routes = window.DUCKS_ROUTES || {};

  function configured() {
    return /^pk_(test|live)_[A-Za-z0-9+/=]+$/.test(key);
  }

  // The Frontend API host is encoded inside the publishable key.
  function frontendApi() {
    return atob(key.split('_')[2]).slice(0, -1);
  }

  function addScript(src, attrs) {
    return new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      Object.keys(attrs || {}).forEach(function (k) { s.setAttribute(k, attrs[k]); });
      s.async = true;
      s.crossOrigin = 'anonymous';
      s.onload = resolve;
      s.onerror = function () { reject(new Error('Could not load ' + src)); };
      s.src = src;
      document.head.appendChild(s);
    });
  }

  var loading = null;
  function load() {
    if (!configured()) return Promise.reject(new Error('NO_KEY'));
    if (loading) return loading;
    var host;
    try { host = frontendApi(); } catch (e) { return Promise.reject(new Error('The Clerk key is not valid. Copy the full Publishable Key again.')); }

    // The same two scripts Clerk's dashboard gives you, loaded from your Clerk domain.
    loading = Promise.all([
      addScript('https://' + host + '/npm/@clerk/ui@1/dist/ui.browser.js'),
      addScript('https://' + host + '/npm/@clerk/clerk-js@6/dist/clerk.browser.js', {
        'data-clerk-publishable-key': key
      })
    ]).then(function () {
      var opts = {
        ui: { ClerkUI: window.__internal_ClerkUICtor },
        signInUrl: routes.signIn,
        signUpUrl: routes.signUp,
        signInFallbackRedirectUrl: routes.afterAuth,
        signUpFallbackRedirectUrl: routes.afterSignUp || routes.afterAuth,
        afterSignOutUrl: routes.afterSignOut
      };
      return window.Clerk.load(opts);
    }).then(function () { return window.Clerk; })
      .catch(function (err) { console.error('[Clerk]', err); throw err; });
    return loading;
  }

  // Match the Clerk forms to the site's colours.
  // Newer Clerk versions renamed some settings, so both the new and old names are set,
  // and the main pieces are also styled directly under `elements` as a safety net.
  var CREAM = '#FEF5DB', MUTED = '#A9B5BC', CARD = '#2F3A41', INPUT = '#262F35', GOLD = '#DFB230', LINE = 'rgba(254,245,219,.22)';
  var appearance = {
    variables: {
      colorPrimary: GOLD,
      colorPrimaryForeground: INPUT,
      colorBackground: CARD,
      colorForeground: CREAM,
      colorMutedForeground: MUTED,
      colorMuted: INPUT,
      colorInput: INPUT,
      colorInputForeground: CREAM,
      colorBorder: LINE,
      colorNeutral: CREAM,
      colorDanger: '#FF8A80',
      fontFamily: '"DM Sans", Arial, sans-serif',
      borderRadius: '14px',
      // older names
      colorTextOnPrimaryBackground: INPUT,
      colorText: CREAM,
      colorTextSecondary: MUTED,
      colorInputBackground: INPUT,
      colorInputText: CREAM
    },
    elements: {
      card: { backgroundColor: CARD, color: CREAM },
      headerTitle: { color: CREAM },
      headerSubtitle: { color: MUTED },
      formFieldLabel: { color: CREAM },
      formFieldInput: { backgroundColor: INPUT, color: CREAM, borderColor: LINE },
      formFieldInputShowPasswordButton: { color: MUTED },
      formFieldHintText: { color: MUTED },
      formFieldInfoText: { color: MUTED },
      formButtonPrimary: { backgroundColor: GOLD, color: INPUT, fontWeight: 700 },
      footer: { backgroundColor: 'transparent' },
      footerActionText: { color: MUTED },
      footerActionLink: { color: GOLD },
      footerPagesLink: { color: MUTED },
      dividerLine: { backgroundColor: LINE },
      dividerText: { color: MUTED },
      socialButtonsBlockButton: { backgroundColor: INPUT, color: CREAM, borderColor: LINE },
      socialButtonsBlockButtonText: { color: CREAM },
      identityPreviewText: { color: CREAM },
      identityPreviewEditButton: { color: GOLD },
      formResendCodeLink: { color: GOLD },
      backLink: { color: GOLD },
      otpCodeFieldInput: { backgroundColor: INPUT, color: CREAM, borderColor: LINE },
      alternativeMethodsBlockButton: { backgroundColor: INPUT, color: CREAM, borderColor: LINE },
      userButtonPopoverCard: { backgroundColor: CARD, color: CREAM },
      userButtonPopoverActionButton: { color: CREAM },
      userButtonPopoverActionButtonText: { color: CREAM },
      userPreviewMainIdentifier: { color: CREAM },
      userPreviewSecondaryIdentifier: { color: MUTED }
    }
  };

  function escapeHtml(t) {
    return String(t).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; });
  }

  // Friendly message shown in place of a form when something is not set up yet.
  function showProblem(el, err) {
    var noKey = err && err.message === 'NO_KEY';
    el.innerHTML =
      '<div class="notice" role="alert">' +
      '<h2>' + (noKey ? 'One more step: add your Clerk key' : 'Could not load sign-in') + '</h2>' +
      (noKey
        ? '<p>Open <code>clerk-config.js</code>, paste your Clerk <strong>Publishable Key</strong> between the quotes, and reload this page.</p>'
        : '<p>Clerk did not load. Common causes: the key in <code>clerk-config.js</code> is wrong or incomplete; an ad blocker or privacy extension is blocking Clerk; or a <code>pk_live_</code> key is being used before the production domain is set up in Clerk (use the <code>pk_test_</code> key until then).</p>' +
          '<p style="margin-top:10px">Technical detail: <code>' + escapeHtml((err && err.message) || 'unknown error') + '</code></p>' +
          '<p style="margin-top:10px">Address this page is running from: <code>' + escapeHtml(location.protocol + '//' + location.host) + '</code>' +
          (window.top !== window.self ? ' <strong>(inside a frame)</strong>' : '') + '</p>') +
      '</div>';
  }

  window.DucksAuth = {
    configured: configured,
    load: load,
    appearance: appearance,
    routes: routes,
    showProblem: showProblem
  };
})();
