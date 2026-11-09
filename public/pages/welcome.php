<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Welcome to Kape Agosto</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="preload" href="img/wel/STR1.jpg" as="image" fetchpriority="high">
  <link rel="preload" href="img/NEWLOGO_WHITE.png" as="image">
  <link rel="preload" href="img/bg.jpg.png" as="image">
  <?php include dirname(__DIR__) . '/partials/stylesheets.php'; ?>
</head>
<body class="kape-pages-welcome">
  <div class="slideshow">
    <img src="img/wel/STR1.jpg" alt="Cafe Image 1" loading="eager" fetchpriority="high" decoding="async">
    <img src="img/wel/STR2.jpg" alt="Cafe Image 2" loading="lazy" fetchpriority="low" decoding="async">
    <img src="img/wel/STR3.jpg" alt="Cafe Image 3" loading="lazy" fetchpriority="low" decoding="async">
    <img src="img/wel/STR4.jpg" alt="Cafe Image 4" loading="lazy" fetchpriority="low" decoding="async">
  </div>
  <div class="overlay" aria-hidden="true"></div>

  <div class="page">
    <div class="welcome-content">
      <main class="hero" role="main">
        <h1>
          <span>Crafting moments,</span>
          <span class="headline-accent">one cup at a time.</span>
        </h1>
        <p class="eyebrow">Coffee Shop.</p>
        <p class="subtitle">Where every date starts with a perfect cup.</p>

        <div class="social-links" aria-label="Social links">
          <a href="https://www.facebook.com/kapeagosto" target="_blank" rel="noopener noreferrer" aria-label="Facebook">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
              <path d="M13.5 22v-8.2h2.8l.4-3.2h-3.2V8.6c0-.9.3-1.5 1.6-1.5h1.7V4.2c-.3 0-1.3-.1-2.5-.1-2.5 0-4.2 1.5-4.2 4.3v2.2H7.3v3.2h2.8V22h3.4z"/>
            </svg>
          </a>
          <a href="https://www.instagram.com/kapeAgosto" target="_blank" rel="noopener noreferrer" aria-label="Instagram">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
              <path d="M12 2.2c3.2 0 3.6 0 4.9.1 1.2.1 1.9.3 2.3.5.6.2 1 .5 1.5 1 .5.5.8.9 1 1.5.2.4.4 1.1.5 2.3.1 1.3.1 1.7.1 4.9s0 3.6-.1 4.9c-.1 1.2-.3 1.9-.5 2.3-.2.6-.5 1-1 1.5-.5.5-.9.8-1.5 1-.4.2-1.1.4-2.3.5-1.3.1-1.7.1-4.9.1s-3.6 0-4.9-.1c-1.2-.1-1.9-.3-2.3-.5-.6-.2-1-.5-1.5-1-.5-.5-.8-.9-1-1.5-.2-.4-.4-1.1-.5-2.3C2.2 15.6 2.2 15.2 2.2 12s0-3.6.1-4.9c.1-1.2.3-1.9.5-2.3.2-.6.5-1 1-1.5.5-.5.9-.8 1.5-1 .4-.2 1.1-.4 2.3-.5C8.4 2.2 8.8 2.2 12 2.2zm0 2.2c-3.1 0-3.5 0-4.8.1-1 .1-1.5.2-1.8.3-.4.2-.7.3-1 .7-.3.3-.5.6-.7 1-.1.3-.3.8-.3 1.8-.1 1.2-.1 1.6-.1 4.8s0 3.5.1 4.8c.1 1 .2 1.5.3 1.8.2.4.3.7.7 1 .3.3.6.5 1 .7.3.1.8.3 1.8.3 1.2.1 1.6.1 4.8.1s3.5 0 4.8-.1c1-.1 1.5-.2 1.8-.3.4-.2.7-.3 1-.7.3-.3.5-.6.7-1 .1-.3.3-.8.3-1.8.1-1.2.1-1.6.1-4.8s0-3.5-.1-4.8c-.1-1-.2-1.5-.3-1.8-.2-.4-.3-.7-.7-1-.3-.3-.6-.5-1-.7-.3-.1-.8-.3-1.8-.3-1.2-.1-1.6-.1-4.8-.1zm0 3.8a3.8 3.8 0 1 1 0 7.6 3.8 3.8 0 0 1 0-7.6zm0 2.2a1.6 1.6 0 1 0 0 3.2 1.6 1.6 0 0 0 0-3.2zm4.9-2.4a.9.9 0 1 1 0 1.8.9.9 0 0 1 0-1.8z"/>
            </svg>
          </a>
          <a href="https://www.tiktok.com/@KapeAgosto" target="_blank" rel="noopener noreferrer" aria-label="TikTok">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
              <path d="M17 3c.7 1.7 2.1 3.1 3.8 3.8v3.1a7.2 7.2 0 0 1-3.8-1.1v6.5a6.3 6.3 0 1 1-6.3-6.3c.4 0 .8 0 1.2.1v3.1a3.2 3.2 0 1 0 2 3v-12H17z"/>
            </svg>
          </a>
        </div>

        <div class="actions">
          <a href="login.php" class="login-btn">Proceed to Login</a>
          <p class="countdown">Redirecting in <span id="timer">60</span> seconds...</p>
        </div>
      </main>

      <div class="welcome-rail">
        <img src="img/NEWLOGO_WHITE.png" alt="Kape Agosto Logo" class="brand-logo">

      </div>
    </div>

    <footer class="contact-strip" aria-label="Contact details">
      <span class="contact-label">Contact</span>
      <a href="tel:+639152926848" class="contact-item">0915-292-6848</a>
      <span class="divider" aria-hidden="true">|</span>
      <a href="mailto:kape.agosto@gmail.com" class="contact-item">kape.agosto@gmail.com</a>
      <span class="divider" aria-hidden="true">|</span>
      <a href="https://maps.app.goo.gl/6JhoL7kvme5pWtWz5" target="_blank" rel="noopener noreferrer" class="contact-item map-link">Find us on Google Maps</a>
    </footer>
  </div>

  <script>
    let counter = 60;
    const timerEl = document.getElementById("timer");
    let interval = setInterval(() => {
      counter--;
      timerEl.textContent = counter;
      if (counter <= 0) {
        clearInterval(interval);
        window.location.href = "login.php";
      }
    }, 1000);
  </script>
  <script src="scripts/perf.js" defer></script>
</body>
</html>
