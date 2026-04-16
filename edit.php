<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';

$code = trim((string)($_GET['code'] ?? ''));
if ($code === '' || !is_valid_article_code($code)) {
    http_response_code(400);
    echo 'Некорректный код статьи.';
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
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <title>Редактировать статью</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/png" href="/uploads/favicon.png">
  <link href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.3/dist/darkly/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/@editorjs/editorjs@2.29.1"></script>
  <script src="https://cdn.jsdelivr.net/npm/@editorjs/header@latest"></script>
  <script src="https://cdn.jsdelivr.net/npm/@editorjs/image@2.8.1"></script>
  <script src="https://cdn.jsdelivr.net/npm/@editorjs/embed@latest"></script>
  <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="content">
  <h1>Редактирование статьи</h1>
  <form id="editForm" action="update.php" method="POST">
    <input type="hidden" name="code" value="<?= h($code) ?>">

    <div class="mb-3">
      <label for="title" class="form-label">Заголовок:</label>
      <input type="text" id="title" name="title" class="form-control" value="<?= h($article['title']) ?>" maxlength="200" required>
    </div>

    <div class="mb-3">
      <label class="form-label">Содержание:</label>
      <div id="editor" class="editor-container"></div>
      <textarea name="content" id="content" hidden></textarea>
    </div>

    <button type="submit" class="btn btn-secondary">Сохранить изменения</button>
  </form>
</div>

<script>
const savedData = <?= editor_json_for_script($article['content']) ?>;

const editor = new EditorJS({
  holder: 'editor',
  tools: {
    header: Header,
    embed: {
      class: Embed,
      config: {
        services: {
          youtube: true,
          vimeo: true
        }
      }
    },
    image: {
      class: ImageTool,
      config: {
        endpoints: {
          byFile: 'upload.php',
          byUrl: 'upload.php'
        }
      }
    }
  },
  data: savedData
});

document.getElementById('editForm').addEventListener('submit', async (event) => {
  event.preventDefault();

  try {
    const outputData = await editor.save();
    document.getElementById('content').value = JSON.stringify(outputData);
    event.target.submit();
  } catch (error) {
    console.error('Ошибка при сохранении данных:', error);
    alert('Не удалось подготовить статью к отправке.');
  }
});
</script>
</body>
</html>
