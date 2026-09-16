<?php
require_once dirname(__DIR__, 2) . '/includes/session.php';
kape_start_session();

if (isset($_POST['cancel_logout'])) {
    if (isset($_COOKIE['last_user'])) {
        $_SESSION['user'] = json_decode($_COOKIE['last_user'], true);
        setcookie('last_user', '', time() - 3600, '/');

        if (isset($_SESSION['user']['id'])) {
            require_once dirname(__DIR__, 2) . '/includes/db.php';
            if (isset($pdo)) {
                $userId = $_SESSION['user']['id'];
                $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($user) {
                    $_SESSION['user'] = $user;
                }
            }
        }

        if (isset($_SESSION['user']['username'])) {
            $_SESSION['username'] = $_SESSION['user']['username'];
        }

        header('Location: dashboard.php');
        exit;
    }

    header('Location: login.php');
    exit;
}

$username = $_SESSION['user']['username'] ?? '';

if (isset($_SESSION['user'])) {
    setcookie('last_user', json_encode($_SESSION['user']), time() + 60, '/');
}

require_once dirname(__DIR__, 2) . '/includes/auth.php';
logout_user();

$redirectUrl = 'welcome.php' . ($username ? '?user=' . urlencode($username) : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="30;url=<?= htmlspecialchars($redirectUrl) ?>">
    <title>Kape Agosto - Logged Out</title>
    <link rel="preload" href="img/bg.jpg.png" as="image">
    <?php include dirname(__DIR__) . '/partials/stylesheets.php'; ?>
    <script>
        let seconds = 30;

        function updateCountdown() {
            const countdown = document.getElementById('countdown');
            if (!countdown) return;
            countdown.textContent = seconds;
            if (seconds > 0) {
                seconds--;
                setTimeout(updateCountdown, 1000);
            }
        }

        window.addEventListener('load', updateCountdown);
    </script>
</head>
<body class="kape-auth-logout">
    <main class="wrap">
        <section class="card" aria-labelledby="logout-title">
            <div class="brand">
                <img src="img/NEWLOGO_BLACK.png" alt="Kape Agosto logo">
            </div>
            <h1 class="title" id="logout-title">You have been logged out.</h1>
            <p class="countdown">Redirecting to welcome page<br>in <span id="countdown">30</span> seconds...</p>
            <form method="post">
                <button type="submit" name="cancel_logout" class="cancel-btn">Cancel Logout</button>
            </form>
            <p class="helper">
                If you are not redirected, <a href="<?= htmlspecialchars($redirectUrl) ?>">click here</a>.
            </p>
        </section>
    </main>
</body>
</html>
