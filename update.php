<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';

header('X-Content-Type-Options: nosniff');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo 'Метод не поддерживается.';
        exit;
    }

    $code = trim((string)($_POST['code'] ?? ''));

    if ($code === '' || !is_valid_article_code($code)) {
        http_response_code(400);
        echo 'Ошибка: некорректный код статьи.';
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

    if (!user_can_edit($article, $code)) {
        http_response_code(403);
        echo 'У вас нет прав для редактирования этой статьи.';
        exit;
    }

    $title = isset($_POST['title']) ? trim((string)$_POST['title']) : '';
    $contentRaw = $_POST['content'] ?? '';

    if ($title === '') {
        http_response_code(400);
        echo 'Ошибка: пустой заголовок.';
        exit;
    }

    if ($contentRaw === '') {
        http_response_code(400);
        echo 'Ошибка: пустой контент.';
        exit;
    }

    $decoded = json_decode((string)$contentRaw, true, 512, JSON_THROW_ON_ERROR);
    $content = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $stmt = $pdo->prepare('UPDATE articles SET title = ?, content = ?, updated_at = CURRENT_TIMESTAMP WHERE code = ?');
    $stmt->execute([$title, $content, $code]);

    header('Location: /' . $code, true, 302);
    exit;
} catch (JsonException $e) {
    http_response_code(400);
    echo 'Ошибка: некорректный JSON контента.';
    exit;
} catch (PDOException $e) {
    http_response_code(500);
    echo 'Ошибка базы данных.';
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Непредвиденная ошибка.';
    exit;
}
