<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';

ini_set('display_errors', '0');

if (ob_get_length()) {
    ob_end_clean();
}

header('Content-Type: application/xml; charset=utf-8');

$base = site_base_url();
$stmt = $pdo->query(
    'SELECT code, created_at, updated_at FROM articles ORDER BY datetime(COALESCE(updated_at, created_at)) DESC'
);
$rows = $stmt->fetchAll();

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url>
    <loc><?= h($base . '/') ?></loc>
    <lastmod><?= h(date('c')) ?></lastmod>
    <changefreq>daily</changefreq>
    <priority>0.5</priority>
  </url>
<?php foreach ($rows as $row): ?>
  <url>
    <loc><?= h($base . '/' . $row['code']) ?></loc>
    <lastmod><?= h(date('c', strtotime((string)($row['updated_at'] ?: $row['created_at'])))) ?></lastmod>
    <changefreq>weekly</changefreq>
    <priority>0.6</priority>
  </url>
<?php endforeach; ?>
</urlset>
