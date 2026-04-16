<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/app.php';

$pdo->exec("CREATE TABLE IF NOT EXISTS admin_settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
)");

function setting_get(PDO $pdo, string $key): ?string
{
    $stmt = $pdo->prepare('SELECT value FROM admin_settings WHERE key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();

    return $value === false ? null : (string)$value;
}

function setting_set(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO admin_settings(key, value) VALUES(?, ?)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value'
    );
    $stmt->execute([$key, $value]);
}

function admin_table_exists(PDO $pdo): bool
{
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'admin_users'");

    return (bool)$stmt->fetchColumn();
}

function is_logged_in(): bool
{
    return ($_SESSION['admin_logged'] ?? false) === true;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }

    return (string)$_SESSION['csrf_token'];
}

function require_csrf(): void
{
    $posted = (string)($_POST['csrf_token'] ?? '');
    $stored = (string)($_SESSION['csrf_token'] ?? '');

    if ($posted === '' || $stored === '' || !hash_equals($stored, $posted)) {
        http_response_code(403);
        echo 'Ошибка проверки формы. Обновите страницу и попробуйте ещё раз.';
        exit;
    }
}

function collect_upload_urls(string $content): array
{
    $urls = [];
    $data = json_decode($content, true);

    if (!is_array($data)) {
        return [];
    }

    array_walk_recursive($data, static function (mixed $value) use (&$urls): void {
        if (!is_string($value)) {
            return;
        }

        if (preg_match_all('#(?:https?://[^/]+)?/uploads/[A-Za-z0-9/_\.-]+#', $value, $matches)) {
            foreach ($matches[0] as $match) {
                $urls[] = $match;
            }
        }
    });

    return array_values(array_unique($urls));
}

function delete_uploads_for_article(string $content): void
{
    $uploadsDir = realpath(__DIR__ . '/uploads');
    if (!$uploadsDir) {
        return;
    }

    foreach (collect_upload_urls($content) as $url) {
        $path = $url;
        if (preg_match('#https?://[^/]+(/.*)$#', $url, $match)) {
            $path = $match[1];
        }

        if (!str_starts_with($path, '/uploads/')) {
            continue;
        }

        $fullPath = realpath(__DIR__ . $path);
        if ($fullPath && str_starts_with($fullPath, $uploadsDir) && is_file($fullPath)) {
            @unlink($fullPath);
        }
    }
}

$adminTableExists = admin_table_exists($pdo);
$action = (string)($_POST['action'] ?? '');
$error = null;
$ok = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
}

if ($action === 'setup' && !$adminTableExists) {
    $login = trim((string)($_POST['login'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($login === '' || $password === '') {
        $error = 'Логин и пароль обязательны.';
    } elseif (utf8_strlen($password) < 8) {
        $error = 'Пароль должен быть не короче 8 символов.';
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS admin_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            pass_hash TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $stmt = $pdo->prepare('INSERT INTO admin_users (username, pass_hash) VALUES (?, ?)');
        $stmt->execute([$login, password_hash($password, PASSWORD_DEFAULT)]);

        $_SESSION['admin_logged'] = true;
        session_regenerate_id(true);
        header('Location: admin.php');
        exit;
    }
}

if ($action === 'login' && $adminTableExists && !is_logged_in()) {
    $login = trim((string)($_POST['login'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    $stmt = $pdo->prepare('SELECT * FROM admin_users WHERE username = ?');
    $stmt->execute([$login]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, (string)$user['pass_hash'])) {
        $_SESSION['admin_logged'] = true;
        session_regenerate_id(true);
        header('Location: admin.php');
        exit;
    }

    $error = 'Неверный логин или пароль.';
}

if ($action === 'logout' && is_logged_in()) {
    $_SESSION = [];
    session_destroy();
    header('Location: admin.php');
    exit;
}

if ($action === 'delete' && is_logged_in()) {
    $code = trim((string)($_POST['code'] ?? ''));

    if ($code !== '' && is_valid_article_code($code)) {
        $stmt = $pdo->prepare('SELECT content FROM articles WHERE code = ?');
        $stmt->execute([$code]);
        $article = $stmt->fetch();

        if ($article) {
            delete_uploads_for_article((string)$article['content']);

            $stmt = $pdo->prepare('DELETE FROM articles WHERE code = ?');
            $stmt->execute([$code]);
        }
    }

    header('Location: admin.php');
    exit;
}

$base = site_base_url();
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$sitemapUrl = $base . '/sitemap.xml';

if ($action === 'save_indexnow' && is_logged_in()) {
    $key = trim((string)($_POST['indexnow_key'] ?? ''));

    if (!preg_match('/^[A-Za-z0-9_-]{8,128}$/', $key)) {
        $error = 'Введите корректный IndexNow API key: 8-128 символов, буквы, цифры, дефис или подчёркивание.';
    } else {
        setting_set($pdo, 'indexnow_key', $key);
        @file_put_contents(__DIR__ . '/' . $key . '.txt', $key);
        $ok = 'Ключ сохранён, key-файл создан в корне сайта.';
    }
}

if ($action === 'ping_indexnow' && is_logged_in()) {
    $key = setting_get($pdo, 'indexnow_key');

    if (!$key) {
        $error = 'Сначала сохраните IndexNow key.';
    } elseif (!function_exists('curl_init')) {
        $error = 'На сервере недоступен cURL, поэтому отправить IndexNow-запрос не получилось.';
    } else {
        $payload = json_encode([
            'host' => $host,
            'key' => $key,
            'keyLocation' => $base . '/' . $key . '.txt',
            'urlList' => [$sitemapUrl],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $ch = curl_init('https://api.indexnow.org/indexnow');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $error = 'Ошибка cURL: ' . $curlError;
        } elseif (in_array($httpCode, [200, 202], true)) {
            $ok = 'Sitemap отправлен в IndexNow. HTTP ' . $httpCode . '.';
        } else {
            $error = 'IndexNow ответил HTTP ' . $httpCode . '. Ответ: ' . (string)$response;
        }
    }
}

if ($action === 'open_gsc' && is_logged_in()) {
    header('Location: https://search.google.com/search-console/sitemaps');
    exit;
}

$storedKey = setting_get($pdo, 'indexnow_key') ?? '';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <title>Админка</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=PT+Mono&family=PT+Sans:ital,wght@0,400;0,700;1,400;1,700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root {
      color-scheme: dark;
      --page-bg: #11100f;
      --panel-bg: #191817;
      --panel-soft: #211f1d;
      --text: #f3f0ea;
      --muted: #aaa39a;
      --line: #3b3834;
      --accent: #49c6a1;
      --accent-strong: #71dfbc;
      --danger: #ff7474;
    }
    body {
      min-height: 100vh;
      padding: 2rem;
      background:
        radial-gradient(circle at top left, rgba(73, 198, 161, 0.08), transparent 34rem),
        linear-gradient(180deg, #11100f 0%, #151413 100%);
      color: var(--text);
      font-family: 'PT Mono', ui-monospace, SFMono-Regular, Consolas, 'Liberation Mono', monospace;
    }
    h1, h2, h3, h4, h5, h6 {
      font-family: 'PT Sans', Arial, sans-serif;
    }
    .container { max-width: 960px; }
    .table td, .table th { vertical-align: middle; }
    .table form { display: inline; }
    .code { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
    .card {
      color: var(--text);
      background: var(--panel-bg);
      border-color: var(--line);
      border-radius: 8px;
    }
    .table {
      --bs-table-bg: var(--panel-bg);
      --bs-table-striped-bg: var(--panel-soft);
      --bs-table-color: var(--text);
      --bs-table-striped-color: var(--text);
      --bs-table-border-color: var(--line);
      color: var(--text);
      border-color: var(--line);
    }
    .text-muted,
    .form-text {
      color: var(--muted) !important;
    }
    .form-control {
      color: var(--text);
      background-color: #141312;
      border-color: var(--line);
      border-radius: 6px;
    }
    .form-control:focus {
      color: var(--text);
      background-color: #141312;
      border-color: var(--accent);
      box-shadow: 0 0 0 0.2rem rgba(73, 198, 161, 0.18);
    }
    .btn-primary,
    .btn-success,
    .btn-outline-primary:hover {
      background-color: var(--accent);
      border-color: var(--accent);
      color: #0d1411;
    }
    .btn-primary:hover,
    .btn-success:hover {
      background-color: var(--accent-strong);
      border-color: var(--accent-strong);
      color: #0d1411;
    }
    .btn-outline-primary {
      border-color: var(--accent);
      color: var(--accent-strong);
    }
    .btn-secondary,
    .btn-outline-secondary {
      border-color: var(--line);
    }
    a {
      color: var(--accent-strong);
    }
    a:hover {
      color: #9df0d2;
    }
    code {
      color: #f0b7c8;
    }
    .alert-danger {
      color: #ffd9d9;
      background: rgba(255, 116, 116, 0.12);
      border-color: rgba(255, 116, 116, 0.35);
    }
    .alert-success {
      color: #d8fff0;
      background: rgba(73, 198, 161, 0.12);
      border-color: rgba(73, 198, 161, 0.35);
    }
    .alert-info {
      color: #e9e0ff;
      background: rgba(173, 139, 255, 0.12);
      border-color: rgba(173, 139, 255, 0.35);
    }
  </style>
</head>
<body>
<div class="container">
  <h1 class="mb-4">Админка</h1>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
  <?php endif; ?>

  <?php if ($ok): ?>
    <div class="alert alert-success"><?= h($ok) ?></div>
  <?php endif; ?>

  <?php if (!$adminTableExists): ?>
    <div class="card">
      <div class="card-body">
        <h5 class="card-title">Первичная настройка</h5>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="setup">
          <div class="mb-3">
            <label class="form-label">Логин</label>
            <input type="text" name="login" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Пароль</label>
            <input type="password" name="password" class="form-control" minlength="8" required>
          </div>
          <button type="submit" class="btn btn-primary">Создать администратора</button>
        </form>
      </div>
    </div>
  <?php elseif (!is_logged_in()): ?>
    <div class="card">
      <div class="card-body">
        <h5 class="card-title">Вход</h5>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="login">
          <div class="mb-3">
            <label class="form-label">Логин</label>
            <input type="text" name="login" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Пароль</label>
            <input type="password" name="password" class="form-control" required>
          </div>
          <button type="submit" class="btn btn-primary">Войти</button>
        </form>
      </div>
    </div>
  <?php else: ?>
    <form method="post" class="mb-3">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="logout">
      <button type="submit" class="btn btn-outline-secondary btn-sm">Выйти</button>
    </form>

    <?php
      $stmt = $pdo->query(
          'SELECT code, title, created_at, updated_at FROM articles ORDER BY datetime(COALESCE(updated_at, created_at)) DESC'
      );
      $rows = $stmt->fetchAll();
    ?>

    <?php if (!$rows): ?>
      <div class="alert alert-info">Пока нет статей.</div>
    <?php else: ?>
      <div class="table-responsive mb-4">
        <table class="table table-striped align-middle">
          <thead>
            <tr>
              <th>Статья</th>
              <th class="text-nowrap">Опубликовано</th>
              <th class="text-nowrap">Правки</th>
              <th class="text-end">Действия</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td>
                <a href="/<?= h($row['code']) ?>" target="_blank"><?= h($row['title'] ?: $row['code']) ?></a>
                <div class="small text-muted code">/<?= h($row['code']) ?></div>
              </td>
              <td class="text-nowrap"><?= h(date('Y-m-d H:i', strtotime((string)$row['created_at']))) ?></td>
              <td class="text-nowrap">
                <?= $row['updated_at'] ? h(date('Y-m-d H:i', strtotime((string)$row['updated_at']))) : '-' ?>
              </td>
              <td class="text-end">
                <a class="btn btn-outline-primary btn-sm me-1" href="/edit.php?code=<?= h($row['code']) ?>">Править</a>
                <form method="post" onsubmit="return confirm('Удалить статью без возможности восстановления?');">
                  <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="code" value="<?= h($row['code']) ?>">
                  <button type="submit" class="btn btn-danger btn-sm">Удалить</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <div class="card mb-4">
      <div class="card-body">
        <h5 class="card-title">Поисковые системы</h5>
        <p class="text-muted mb-3">
          Для Google используйте Search Console и корректный <code>&lt;lastmod&gt;</code> в sitemap.
          Яндекс и Bing поддерживают IndexNow.
        </p>

        <form method="post" class="row gy-2 gx-2 align-items-end">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="save_indexnow">
          <div class="col-sm-6">
            <label class="form-label">IndexNow API key</label>
            <input type="text" name="indexnow_key" class="form-control" value="<?= h($storedKey) ?>" placeholder="a1b2c3d4...">
            <div class="form-text">Key-файл: <code>/<?= h($storedKey ?: 'KEY') ?>.txt</code></div>
          </div>
          <div class="col-sm-6">
            <button type="submit" class="btn btn-outline-primary">Сохранить ключ</button>
          </div>
        </form>

        <hr>

        <div class="d-flex flex-wrap gap-2">
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="ping_indexnow">
            <button type="submit" class="btn btn-success" <?= $storedKey ? '' : 'disabled' ?>>
              Отправить sitemap в IndexNow
            </button>
          </form>

          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="open_gsc">
            <button type="submit" class="btn btn-secondary">Открыть Google Search Console</button>
          </form>

          <div class="ms-auto small text-muted">
            Sitemap: <code><?= h($sitemapUrl) ?></code>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
