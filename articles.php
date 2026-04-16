<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';

$stmt = $pdo->query(
    'SELECT code, title, created_at, updated_at FROM articles ORDER BY datetime(COALESCE(updated_at, created_at)) DESC'
);
$rows = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <title>Список статей</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="style.css">
  <style>
    .list { margin: 1rem 0; padding: 0; list-style: none; }
    .list li { margin: .35rem 0; }
    .meta { color: var(--muted); font-size: .9rem; margin-left: .3rem; }
    .tools { margin-top: 1rem; font-size: .95rem; }
    .tools a { text-decoration: none; }
  </style>
</head>
<body>
  <div class="content">
    <h1>Список статей</h1>

    <?php if (!$rows): ?>
      <p>Пока нет статей.</p>
    <?php else: ?>
      <ul class="list">
        <?php foreach ($rows as $row): ?>
          <li>
            <a href="/<?= h($row['code']) ?>"><?= h($row['title'] ?: $row['code']) ?></a>
            <span class="meta">- <?= h(date('Y-m-d H:i', strtotime((string)$row['created_at']))) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <div class="tools">
      Экспорт в карту сайта: <a href="/sitemap.xml">/sitemap.xml</a>
    </div>
  </div>
</body>
</html>
