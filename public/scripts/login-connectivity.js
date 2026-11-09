(function () {
  'use strict';
  var form = document.getElementById('loginForm');
  if (!form) return;
  var localLogin = form.dataset.localLogin === 'true';
  var loginButton = document.getElementById('loginButton');
  var container = document.getElementById('recap-container');
  var target = document.getElementById('recaptcha-widget');
  var status = document.getElementById('loginConnectionStatus');
  var widgetId = null;
  var mode = 'loading';
  var solved = false;
  var loading = false;
  var script = null;
  var loadTimer = null;
  var retryTimer = null;
  var generation = 0;

  function online() { return navigator.onLine !== false; }
  function update() {
    var fallback = localLogin && mode === 'fallback';
    loginButton.disabled = !fallback && !(online() && mode === 'captcha' && solved);
    container.hidden = mode === 'fallback' || mode === 'unavailable';
    status.hidden = mode === 'captcha';
    if (mode === 'fallback') {
      status.textContent = 'Internet unavailable. Try signing in through the local server.';
    } else if (mode === 'unavailable') {
      status.textContent = 'Unable to load reCAPTCHA. Check your internet connection.';
    } else if (mode === 'captcha') {
      status.textContent = '';
    } else {
      status.textContent = 'Loading reCAPTCHA...';
    }
  }
  function cancelRetry() {
    clearTimeout(retryTimer);
    retryTimer = null;
  }
  function cancelLoading() {
    generation++;
    clearTimeout(loadTimer);
    loadTimer = null;
    loading = false;
    if (script) script.remove();
    script = null;
  }
  function unavailable(expectedGeneration) {
    if (expectedGeneration !== undefined && expectedGeneration !== generation) return;
    cancelLoading();
    solved = false;
    form.querySelectorAll('[name="g-recaptcha-response"]').forEach(function (field) { field.value = ''; });
    mode = localLogin ? 'fallback' : 'unavailable';
    update();
    cancelRetry();
    if (online()) retryTimer = setTimeout(loadCaptcha, 10000);
  }
  function renderCaptcha() {
    if (!online() || (!loading && mode === 'captcha')) return;
    if (!window.grecaptcha || typeof window.grecaptcha.render !== 'function') return;
    clearTimeout(loadTimer);
    loadTimer = null;
    loading = false;
    script = null;
    cancelRetry();
    solved = false;
    mode = 'captcha';
    try {
      if (widgetId !== null) {
        window.grecaptcha.reset(widgetId);
      } else {
        widgetId = window.grecaptcha.render(target, {
          sitekey: form.dataset.recaptchaSiteKey,
          callback: function (response) {
            if (!online() || mode !== 'captcha') return;
            solved = Boolean(response);
            update();
          },
          'expired-callback': function () { solved = false; update(); },
          'error-callback': function () { unavailable(); }
        });
      }
      update();
    } catch (_) {
      unavailable();
    }
  }
  // Google's callback runs after the reCAPTCHA API and its dependencies are ready.
  window.kapeLoginCaptchaReady = renderCaptcha;
  function loadCaptcha() {
    if (loading || mode === 'captcha') return;
    if (!online()) { unavailable(); return; }
    cancelRetry();
    loading = true;
    var currentGeneration = ++generation;
    if (mode !== 'fallback') mode = 'loading';
    update();
    loadTimer = setTimeout(function () { unavailable(currentGeneration); }, 4000);
    if (window.grecaptcha && typeof window.grecaptcha.render === 'function') {
      renderCaptcha();
      return;
    }
    script = document.createElement('script');
    script.src = 'https://www.google.com/recaptcha/api.js?onload=kapeLoginCaptchaReady&render=explicit';
    script.async = true;
    script.defer = true;
    script.onerror = function () { unavailable(currentGeneration); };
    document.head.appendChild(script);
  }

  window.addEventListener('offline', function () { cancelRetry(); unavailable(); });
  window.addEventListener('online', function () {
    cancelLoading();
    cancelRetry();
    solved = false;
    mode = 'loading';
    loadCaptcha();
  });
  form.addEventListener('submit', function (event) {
    update();
    if (loginButton.disabled) event.preventDefault();
  });
  var password = document.getElementById('password');
  var toggle = document.getElementById('togglePass');
  if (password && toggle) {
    toggle.addEventListener('click', function () {
      var hidden = password.type === 'password';
      password.type = hidden ? 'text' : 'password';
      toggle.textContent = hidden ? 'hide' : 'show';
      toggle.setAttribute('aria-label', hidden ? 'Hide password' : 'Show password');
    });
  }
  loadCaptcha();
})();
