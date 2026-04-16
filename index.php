<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';

$requestPath = trim((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
if ($requestPath !== '' && $requestPath !== 'index.php') {
    $_GET['code'] = $requestPath;
    require __DIR__ . '/view.php';
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <title>Новая статья</title>
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
  <h1>Размести свою статью</h1>
  <form id="articleForm" action="submit.php" method="POST">
    <div class="mb-3">
      <label for="title" class="form-label">Заголовок статьи:</label>
      <input type="text" id="title" name="title" class="form-control" maxlength="200" required>
    </div>
    <div class="mb-3">
      <label class="form-label">Содержание:</label>
      <div id="editor" class="editor-container"></div>
      <textarea name="content" id="content" hidden></textarea>
    </div>
    <button type="submit" class="btn btn-secondary">Разместить</button>
  </form>
</div>

<script>
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
  }
});

document.getElementById('articleForm').addEventListener('submit', async (event) => {
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
