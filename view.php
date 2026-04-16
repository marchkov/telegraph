<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';

$code = trim((string)($_GET['code'] ?? ''));
if ($code === '' || !is_valid_article_code($code)) {
    http_response_code(404);
    echo 'Статья не найдена.';
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM articles WHERE code = ?');
$stmt->execute([$code]);
$article = $stmt->fetch();

if (!$article) {
    http_response_code(404);
    echo 'Статья не найдена.';
    exit;
}

$isAuthor = user_can_edit($article, $code);
$pageTitle = (string)$article['title'];
$description = plain_text_from_editor((string)$article['content']);
if ($description === '') {
    $description = (string)$article['title'];
}
$socialImage = first_editor_image((string)$article['content']);
$absoluteCover = absolute_public_url($socialImage);
$canonicalUrl = site_base_url() . '/' . $code;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <title><?= h($pageTitle) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="<?= h($description) ?>">
  <meta property="og:type" content="article">
  <meta property="og:title" content="<?= h($pageTitle) ?>">
  <meta property="og:description" content="<?= h($description) ?>">
  <meta property="og:url" content="<?= h($canonicalUrl) ?>">
  <?php if ($absoluteCover !== ''): ?>
    <meta property="og:image" content="<?= h($absoluteCover) ?>">
    <meta name="twitter:card" content="summary_large_image">
  <?php else: ?>
    <meta name="twitter:card" content="summary">
  <?php endif; ?>
  <link rel="canonical" href="<?= h($canonicalUrl) ?>">
  <link rel="icon" type="image/png" href="/uploads/favicon.png">
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <div class="content">
    <?php if ($isAuthor): ?>
      <a class="edit-link" href="/edit.php?code=<?= h($code) ?>">Редактировать</a>
    <?php endif; ?>

    <h1><?= h($article['title']) ?></h1>
    <article id="viewer" class="article-body">
      <?= render_editor_blocks($article['content']) ?>
    </article>
  </div>
</body>
</html>
