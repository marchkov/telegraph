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

    $code = bin2hex(random_bytes(6));
    $editToken = bin2hex(random_bytes(16));

    $stmt = $pdo->prepare(
        'INSERT INTO articles (code, title, content, edit_token, updated_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)'
    );
    $stmt->execute([$code, $title, $content, $editToken]);

    set_article_edit_cookie($code, $editToken);

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
