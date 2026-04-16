<?php
declare(strict_types=1);

$pdo = new PDO('sqlite:' . __DIR__ . '/database.db');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$pdo->exec("CREATE TABLE IF NOT EXISTS articles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT UNIQUE NOT NULL,
    title TEXT NOT NULL,
    content TEXT NOT NULL,
    edit_token TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$columns = $pdo->query("PRAGMA table_info(articles)")->fetchAll();
$columnNames = array_map(static fn(array $column): string => (string)$column['name'], $columns ?: []);

if (!in_array('updated_at', $columnNames, true)) {
    $pdo->exec("ALTER TABLE articles ADD COLUMN updated_at DATETIME");
    $pdo->exec("UPDATE articles SET updated_at = created_at WHERE updated_at IS NULL");
}

$pdo->exec("CREATE INDEX IF NOT EXISTS idx_articles_created_at ON articles(created_at)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_articles_updated_at ON articles(updated_at)");
