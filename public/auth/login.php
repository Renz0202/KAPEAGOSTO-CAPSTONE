<?php
require_once dirname(__DIR__, 2) . '/includes/session.php';
kape_start_session();
require_once dirname(__DIR__, 2) . '/config/recaptcha.php';
$flash = $_SESSION['flash'] ?? null;
$flash_type = $_SESSION['flash_type'] ?? null; // <-- Add this line
unset($_SESSION['flash']);
unset($_SESSION['flash_type']); // <-- Add this line

$isReturningUser = ($_COOKIE['kape_returning_user'] ?? '') === '1';
$loginGreeting = $isReturningUser ? 'Welcome Back' : 'WELCOME';
$loginSubtitle = $isReturningUser
  ? 'Log in again to continue your account.'
  : 'Log in to access your account.';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Kape Agosto - Login</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preconnect" href="https://www.google.com" crossorigin>
<link rel="preconnect" href="https://www.gstatic.com" crossorigin>
<link rel="dns-prefetch" href="//fonts.gstatic.com">
<link rel="dns-prefetch" href="//www.google.com">
<link rel="dns-prefetch" href="//www.gstatic.com">
<link rel="preload" href="img/bg.jpg.png" as="image">
<?php include dirname(__DIR__) . '/partials/stylesheets.php'; ?>
</head>

<body class="kape-auth-login">
<div class="wrap">
  <form class="card" method="post" action="auth_login.php" novalidate id="loginForm">
    <div class="brand">
      <img src="img/NEWLOGO_BLACK.png" alt="Kape Agosto logo">
      <h1 class="title" id="loginGreeting"><?= htmlspecialchars($loginGreeting) ?></h1>
      <p class="subtitle" id="loginSubtitle"><?= htmlspecialchars($loginSubtitle) ?></p>
    </div>

    <?php if ($flash): ?>
      <div class="flash <?= $flash_type === 'error' ? 'error' : ($flash_type === 'success' ? 'success' : '') ?>">
        <?= htmlspecialchars($flash) ?>
      </div>
    <?php endif; ?>

    <div class="row">
      <div class="field">
        <label class="sr-only" for="username">Username</label>
        <div class="input-wrap">
          <input id="username" name="username" type="text" required autofocus autocomplete="username" placeholder="Username">
        </div>
      </div>
      <div class="field">
        <label class="sr-only" for="password">Password</label>
        <div class="input-wrap">
          <input id="password" name="password" type="password" required autocomplete="current-password" placeholder="Password">
          <button type="button" class="toggle-pass" id="togglePass" aria-controls="password" aria-label="Show password">show</button>
        </div>
      </div>
    </div>

    <div class="links">
      <a href="forgot_password.php">Forgot Password?</a>
    </div>

    <div class="recap" id="recap-container">
      <div id="recaptcha-widget"></div>
    </div>

    <button class="btn" id="loginButton" type="submit" disabled>Login</button>

      </form>
</div>
<script>
  // Lazy-load reCAPTCHA only when needed for faster first paint + reuse perf.js patterns
  (function(){
    var loaded = false;
    var captchaReady = false;
    var form = document.getElementById('loginForm');
    var loginButton = document.getElementById('loginButton');

    function updateLoginAvailability(){
      var online = navigator.onLine;
      if (loginButton) loginButton.disabled = !online || !captchaReady;
    }

    function loadRecaptcha(){
      if (loaded || !navigator.onLine) return;
      loaded = true;
      var target = document.getElementById('recaptcha-widget');
      if (target) target.textContent = '';
      updateLoginAvailability();
      var s = document.createElement('script');
      s.src = 'https://www.google.com/recaptcha/api.js?render=explicit';
      s.async = true;
      s.defer = true;
      s.onload = function(){
        try {
          if (window.grecaptcha) {
            // Ensure API is ready then render explicitly
            var renderWidget = function(){
              var target = document.getElementById('recaptcha-widget');
              if (target && !target.hasChildNodes()) {
                window.grecaptcha.render(target, { sitekey: '<?= htmlspecialchars(RECAPTCHA_SITE_KEY) ?>' });
                captchaReady = true;
                updateLoginAvailability();
              }
            };
            if (window.grecaptcha.ready) {
              window.grecaptcha.ready(renderWidget);
            } else {
              renderWidget();
            }
          }
        } catch(e) { /* noop */ }
      };
      s.onerror = function(){
        loaded = false;
        captchaReady = false;
        var target = document.getElementById('recaptcha-widget');
        if (target) target.textContent = 'Unable to load reCAPTCHA. Check the internet connection and reload this page.';
        updateLoginAvailability();
        s.remove();
      };
      document.head.appendChild(s);
    }

    window.addEventListener('online', function(){
      updateLoginAvailability();
      loadRecaptcha();
    });
    window.addEventListener('offline', updateLoginAvailability);
    updateLoginAvailability();

    // Triggers: first interaction, seeing the widget, or short delay
    var username = document.getElementById('username');
    var password = document.getElementById('password');
    if (username) username.addEventListener('focus', loadRecaptcha, {once:true});
    if (password) password.addEventListener('focus', loadRecaptcha, {once:true});
    window.addEventListener('mousemove', loadRecaptcha, {once:true});
    window.addEventListener('touchstart', loadRecaptcha, {once:true});
    setTimeout(loadRecaptcha, 1500);

    // Visibility trigger using IntersectionObserver
    var container = document.getElementById('recap-container');
    if ('IntersectionObserver' in window && container) {
      var io = new IntersectionObserver(function(entries){
        entries.forEach(function(entry){ if (entry.isIntersecting) { loadRecaptcha(); io.disconnect(); } });
      });
      io.observe(container);
    }

    if (form) {
      form.addEventListener('submit', function(event){
        if (!navigator.onLine || !captchaReady) {
          event.preventDefault();
          updateLoginAvailability();
        }
      });
    }

    var passwordInput = document.getElementById('password');
    var togglePassBtn = document.getElementById('togglePass');
    if (passwordInput && togglePassBtn) {
      togglePassBtn.addEventListener('click', function(){
        var hidden = passwordInput.type === 'password';
        passwordInput.type = hidden ? 'text' : 'password';
        togglePassBtn.textContent = hidden ? 'hide' : 'show';
        togglePassBtn.setAttribute('aria-label', hidden ? 'Hide password' : 'Show password');
      });
    }
  })();
</script>
<script src="scripts/live-reload.js"></script>
<script src="scripts/perf.js" defer></script>
</body>
</html>
