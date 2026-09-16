<?php
declare(strict_types=1);

use MatrixChat\Auth;
use MatrixChat\Config;
use MatrixChat\I18n;
use MatrixChat\RateLimiter;

$app = require __DIR__ . '/src/bootstrap.php';
$auth = $app['auth'];
$chat = $app['chat'];

$lang = I18n::detect();
$t = I18n::all($lang);
$error = '';

if (isset($_GET['logout'])) {
    $auth->logout();
    header('Location: index.php');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['form_type'])) {
    Auth::verifyCsrf();

    if (!RateLimiter::hit('auth', 12, 60)) {
        http_response_code(429);
        $error = 'Too many attempts. Try again shortly.';
    } else {
        $type = (string)$_POST['form_type'];
        if ($type === 'guest') {
            $auth->guest();
            header('Location: index.php');
            exit;
        }

        $username = (string)($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $color = (string)($_POST['user_color'] ?? '#4cc9ff');

        if ($type === 'register') {
            $registerError = $auth->register($username, $password, $color);
            if ($registerError === null) {
                header('Location: index.php');
                exit;
            }
            $error = $t['register_error'];
        } elseif ($type === 'login') {
            if ($auth->login($username, $password)) {
                header('Location: index.php');
                exit;
            }
            $error = $t['auth_error'];
        }
    }
}

$user = Auth::user();
if ($user) {
    $chat->touchPresence($user['name']);
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="<?= e($lang) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="#050816">
    <meta name="csrf-token" content="<?= e(Auth::csrfToken()) ?>">
    <meta name="app-version" content="<?= e(Config::VERSION) ?>">
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" type="image/svg+xml" href="assets/matrix-chat.svg">
    <link rel="apple-touch-icon" href="assets/icon-192.png">
    <link rel="stylesheet" href="assets/app.css">
    <title><?= e($t['title']) ?> · v<?= e(Config::VERSION) ?></title>
</head>
<body data-lang="<?= e($lang) ?>">
<canvas id="matrix-rain" aria-hidden="true"></canvas>

<?php if (!$user): ?>
<main class="auth-shell">
    <section class="auth-card">
        <div class="brand-lockup">
            <img src="assets/matrix-chat.svg" width="72" height="72" alt="">
            <div>
                <h1><?= e($t['title']) ?></h1>
                <p><?= e($t['subtitle']) ?></p>
            </div>
        </div>

        <?php if ($error !== ''): ?>
            <div class="alert" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
            <label>
                <span><?= e($t['username']) ?></span>
                <input name="username" maxlength="24" autocomplete="username" required>
            </label>
            <label>
                <span><?= e($t['password']) ?></span>
                <input name="password" type="password" minlength="8" maxlength="200" autocomplete="current-password" required>
            </label>
            <label class="color-row">
                <span><?= e($t['color']) ?></span>
                <input name="user_color" type="color" value="#4cc9ff">
            </label>
            <div class="auth-actions">
                <button class="primary" type="submit" name="form_type" value="login"><?= e($t['login']) ?></button>
                <button type="submit" name="form_type" value="register"><?= e($t['register']) ?></button>
            </div>
        </form>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
            <button class="ghost wide" type="submit" name="form_type" value="guest"><?= e($t['guest']) ?></button>
        </form>

        <footer>
            <span>v<?= e(Config::VERSION) ?></span>
            <span>•</span>
            <a href="?lang=<?= $lang === 'pl' ? 'en' : 'pl' ?>"><?= $lang === 'pl' ? 'EN' : 'PL' ?></a>
            <span>•</span>
            <a href="https://github.com/Swir" rel="noreferrer">by Swir</a>
        </footer>
    </section>
</main>
<?php else: ?>
<div class="app-shell" id="app"
     data-current-user="<?= e($user['name']) ?>"
     data-global-label="<?= e($t['global']) ?>"
     data-empty-label="<?= e($t['empty']) ?>"
     data-invite-label="<?= e($t['invite']) ?>"
     data-pending-label="<?= e($t['pending']) ?>"
     data-accepted-label="<?= e($t['accepted']) ?>"
     data-declined-label="<?= e($t['declined']) ?>"
     data-accept-label="<?= e($t['accept']) ?>"
     data-decline-label="<?= e($t['decline']) ?>">
    <aside class="sidebar">
        <header class="brand">
            <img src="assets/matrix-chat.svg" width="44" height="44" alt="">
            <div>
                <strong><?= e($t['title']) ?></strong>
                <small>v<?= e(Config::VERSION) ?></small>
            </div>
        </header>

        <button id="global-button" class="channel active" type="button"><?= e($t['global']) ?></button>

        <div class="sidebar-title">
            <span><?= e($t['online']) ?></span>
            <span id="online-count" class="badge">0</span>
        </div>
        <div id="online-list" class="online-list" aria-live="polite"></div>

        <div class="sidebar-bottom">
            <div class="identity">
                <span class="status-dot"></span>
                <div>
                    <strong><?= e($user['name']) ?></strong>
                    <small id="connection-state">online</small>
                </div>
                <input id="profile-color" type="color" value="<?= e($user['color']) ?>" aria-label="<?= e($t['color']) ?>">
            </div>
            <div class="mini-actions">
                <a href="?lang=<?= $lang === 'pl' ? 'en' : 'pl' ?>"><?= $lang === 'pl' ? 'EN' : 'PL' ?></a>
                <a href="?logout=1"><?= e($t['logout']) ?></a>
            </div>
        </div>
    </aside>

    <main class="chat-pane">
        <header class="chat-header">
            <div>
                <span class="eyebrow">CHANNEL</span>
                <h1 id="channel-title"><?= e($t['global']) ?></h1>
            </div>
            <button id="back-global" type="button" class="ghost mobile-only"><?= e($t['back_global']) ?></button>
        </header>

        <section id="invite-banner" class="invite-banner hidden" aria-live="polite"></section>
        <section id="messages" class="messages" aria-live="polite" aria-label="Messages"></section>

        <form id="composer" class="composer" autocomplete="off">
            <input id="message-input" maxlength="<?= Config::MAX_MESSAGE_LENGTH ?>" placeholder="<?= e($t['message']) ?>" required>
            <label class="file-button" title="<?= e($t['attach']) ?>">
                <input id="file-input" type="file" hidden accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.zip,.txt">
                <span aria-hidden="true">＋</span>
                <span class="sr-only"><?= e($t['attach']) ?></span>
            </label>
            <button class="primary" type="submit"><?= e($t['send']) ?></button>
        </form>
    </main>
</div>
<script src="assets/app.js" defer></script>
<?php endif; ?>
</body>
</html>
