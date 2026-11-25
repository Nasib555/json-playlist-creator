<?php
/**
 * JSON Playlist Creator admin: first-run setup, login, then the editor (app.js talks to api.php).
 */
require dirname(__DIR__) . '/app/core.php';
require dirname(__DIR__) . '/app/media.php';
require dirname(__DIR__) . '/app/auth.php';

jp_session_start();
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; img-src * data: blob:; media-src * blob:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; connect-src 'self'; frame-ancestors 'none'");

$setUp = jp_is_set_up();
$error = null;
$values = ['name' => 'My Playlist', 'timezone' => 'UTC'];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $form = (string)($_POST['form'] ?? '');
    $acceptedTerms = ($_POST['accept'] ?? null) === '1';
    if (!hash_equals($_SESSION['jp_csrf'], (string)($_POST['token'] ?? ''))) {
        $error = 'The form expired. Please try again.';
    } elseif ($form === 'setup' && !$setUp) {
        $values = ['name' => (string)($_POST['name'] ?? ''), 'timezone' => (string)($_POST['timezone'] ?? 'UTC')];
        try {
            if ((string)($_POST['password'] ?? '') !== (string)($_POST['password2'] ?? '')) throw new RuntimeException('The two passwords don\'t match.');
            jp_run_setup((string)($_POST['password'] ?? ''), $values['name'], $values['timezone'], $acceptedTerms);
            jp_login((string)$_POST['password']);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    } elseif ($form === 'login' && $setUp) {
        $error = jp_login($_POST['password'] ?? '');
    } elseif ($form === 'terms' && jp_logged_in()) {
        if (!$acceptedTerms) $error = 'Please accept the updated terms to continue.';
        else jp_record_terms();
    } elseif ($form === 'logout') {
        jp_logout();
    }
    if ($error === null) {
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'), true, 303);
        exit;
    }
}

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$v = fn($f) => (string)@filemtime(__DIR__ . "/$f");
$token = $_SESSION['jp_csrf'];
$brand = '<div class="brand"><span class="brand-mark">JP</span><div><b>JSON Playlist Creator</b><small>Open JSON Playlist generator</small></div></div>';
// Render the bundled legal documents as escaped text with simple formatting.
$legalBox = function (string $text) use ($h) {
    // Lines of one paragraph are joined; blank lines separate paragraphs.
    $html = $h(preg_replace("/(?<!\n)\n(?!\n)\s*/", ' ', str_replace("\r", '', $text)));
    $html = preg_replace('/^# (.+)$/m', '<b class="t-h">$1</b>', $html);
    $html = preg_replace('/\*\*(.+?)\*\*/s', '<b>$1</b>', $html);
    return '<div class="terms">' . preg_replace('/\n{2,}/', '<br><br>', trim($html)) . '</div>';
};
$licenseBox = function () use ($legalBox) {
    $text = file_get_contents(dirname(__DIR__) . '/LICENSE.md');
    return '<details><summary>MIT software license</summary>'
        . $legalBox($text === false ? 'The bundled LICENSE.md could not be read.' : $text)
        . '</details>';
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>JSON Playlist Creator</title>
    <link rel="stylesheet" href="app.css?v=<?= $v('app.css') ?>">
</head>
<body>
<?php if (!$setUp): ?>
    <main class="login">
        <form method="post" autocomplete="off" class="login-card setup-card">
            <?= $brand ?>
            <h2>Set up</h2>
            <p class="muted">Takes a minute. Everything is stored in files in this folder; there's no database.</p>
            <?php if ($error): ?><div class="banner banner-danger"><?= $h($error) ?></div><?php endif; ?>
            <div class="checks">
                <?php foreach (jp_requirements() as $r): ?>
                    <div class="check <?= $r['ok'] === null ? 'info' : ($r['ok'] ? 'ok' : ($r['required'] ? 'bad' : 'warn')) ?>">
                        <b><?= $h($r['label']) ?></b><span><?= $h($r['detail']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <input type="hidden" name="form" value="setup">
            <input type="hidden" name="token" value="<?= $h($token) ?>">
            <div class="grid-form">
                <div class="c-6"><label class="lbl" for="name">Playlist name</label><input class="inp" id="name" name="name" value="<?= $h($values['name']) ?>" required maxlength="80"></div>
                <div class="c-6"><label class="lbl" for="tz">Time zone</label><select class="sel" id="tz" name="timezone">
                    <?php foreach (timezone_identifiers_list() as $tz): ?><option <?= $tz === $values['timezone'] ? 'selected' : '' ?>><?= $h($tz) ?></option><?php endforeach; ?>
                </select></div>
                <div class="c-6"><label class="lbl" for="pw">Admin password</label><input class="inp" type="password" id="pw" name="password" required minlength="<?= JP_MIN_PASSWORD ?>" autocomplete="new-password"></div>
                <div class="c-6"><label class="lbl" for="pw2">Repeat it</label><input class="inp" type="password" id="pw2" name="password2" required minlength="<?= JP_MIN_PASSWORD ?>" autocomplete="new-password"></div>
            </div>
            <label class="lbl" style="margin-top:18px">Terms of use</label>
            <?= $legalBox(jp_terms_text()) ?>
            <?= $licenseBox() ?>
            <label class="agree"><input type="checkbox" name="accept" value="1" required>
                <span>I have read and accept the terms of use. I will only publish content that I own or am authorized by the rights holders to distribute.</span></label>
            <button class="btn btn-primary btn-block" type="submit">Finish setup</button>
            <p class="muted small" style="margin:12px 0 0">On a public server, finish setup right after installing, or run <code>php tools/setup.php</code> on the server instead.</p>
        </form>
    </main>
    <script>
        // Checks from the browser that the private data folder really is closed to the web.
        fetch('../data/index.html', { cache: 'no-store' }).then(r => {
            const el = document.querySelector('.check.info');
            if (!el) return;
            el.className = 'check ' + (r.ok ? 'bad' : 'ok');
            el.querySelector('span').textContent = r.ok
                ? 'OPEN: the data folder can be downloaded by anyone. Add the web server rules (docs/INSTALL.md) before using this.'
                : 'Checked: the data folder is not reachable from the web.';
        }).catch(() => {});
    </script>
<?php elseif (!jp_logged_in()): ?>
    <main class="login">
        <form method="post" autocomplete="off" class="login-card">
            <?= $brand ?>
            <?php if ($error): ?><div class="banner banner-danger"><?= $h($error) ?></div><?php endif; ?>
            <input type="hidden" name="form" value="login">
            <input type="hidden" name="token" value="<?= $h($token) ?>">
            <label class="lbl" for="pw">Admin password</label>
            <input class="inp" type="password" id="pw" name="password" required autofocus autocomplete="current-password">
            <button class="btn btn-primary btn-block" type="submit">Log in</button>
        </form>
    </main>
<?php elseif (!jp_terms_current()): ?>
    <main class="login">
        <form method="post" class="login-card setup-card">
            <?= $brand ?>
            <h2>Updated terms of use</h2>
            <?php if ($error): ?><div class="banner banner-danger"><?= $h($error) ?></div><?php endif; ?>
            <input type="hidden" name="form" value="terms">
            <input type="hidden" name="token" value="<?= $h($token) ?>">
            <?= $legalBox(jp_terms_text()) ?>
            <?= $licenseBox() ?>
            <label class="agree"><input type="checkbox" name="accept" value="1" required><span>I have read and accept the updated terms of use.</span></label>
            <button class="btn btn-primary btn-block" type="submit">Continue</button>
        </form>
    </main>
<?php else: ?>
    <div id="jp-app" class="app"><div class="loading">Loading…</div></div>
    <template id="jp-agreements">
        <details><summary>Read the Terms of Use</summary><?= $legalBox(jp_terms_text()) ?></details>
        <?= $licenseBox() ?>
    </template>
    <div id="jp-modal"></div>
    <div id="jp-dialog"></div>
    <div id="jp-toasts" class="toasts" aria-live="polite"></div>
    <form method="post" id="jp-logout" hidden>
        <input type="hidden" name="form" value="logout">
        <input type="hidden" name="token" value="<?= $h($token) ?>">
    </form>
    <script>window.JP_BOOT = <?= json_encode(['csrf' => $token, 'api' => 'api.php', 'version' => JP_VERSION], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
    <script src="app.js?v=<?= $v('app.js') ?>"></script>
<?php endif; ?>
</body>
</html>
