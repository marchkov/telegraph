<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$uploadDir = __DIR__ . '/uploads';
$publicPrefix = '/uploads';
$maxBytes = 10 * 1024 * 1024;
$maxMb = (int)($maxBytes / 1024 / 1024);

function upload_fail(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => 0, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function upload_ok(string $url): void
{
    echo json_encode(['success' => 1, 'file' => ['url' => $url]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function upload_error_message(int $error, int $maxMb): string
{
    return match ($error) {
        UPLOAD_ERR_INI_SIZE,
        UPLOAD_ERR_FORM_SIZE => "Файл слишком большой. Максимум: {$maxMb} МБ.",
        UPLOAD_ERR_PARTIAL => 'Файл загрузился не полностью. Попробуйте ещё раз.',
        UPLOAD_ERR_NO_FILE => 'Файл не был выбран.',
        UPLOAD_ERR_NO_TMP_DIR => 'На сервере не настроена временная папка для загрузок.',
        UPLOAD_ERR_CANT_WRITE => 'Сервер не смог записать загруженный файл.',
        UPLOAD_ERR_EXTENSION => 'PHP-расширение остановило загрузку файла.',
        default => 'Ошибка загрузки файла.',
    };
}

function detect_mime(string $path): ?string
{
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = finfo_file($finfo, $path);
            finfo_close($finfo);

            if ($mime) {
                return $mime;
            }
        }
    }

    $info = @getimagesize($path);
    return is_array($info) && !empty($info['mime']) ? (string)$info['mime'] : null;
}

function ensure_public_url_is_downloadable(string $url): void
{
    $parts = parse_url($url);
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = (string)($parts['host'] ?? '');

    if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
        upload_fail('Можно загружать изображения только по http/https URL.');
    }

    $records = @dns_get_record($host, DNS_A + DNS_AAAA);
    if (!$records) {
        upload_fail('Не удалось проверить адрес хоста.');
    }

    foreach ($records as $record) {
        $ip = $record['ip'] ?? $record['ipv6'] ?? null;
        if (!$ip || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            upload_fail('Нельзя загружать изображения с локальных или служебных адресов.');
        }
    }
}

function read_remote_image(string $url, int $maxBytes, int $maxMb): string
{
    ensure_public_url_is_downloadable($url);

    $context = stream_context_create([
        'http' => [
            'timeout' => 15,
            'follow_location' => 0,
            'header' => "User-Agent: ArticlePublisher/1.0\r\n",
        ],
    ]);

    $handle = @fopen($url, 'rb', false, $context);
    if (!$handle) {
        upload_fail('Не удалось скачать файл по URL.');
    }

    $data = '';
    while (!feof($handle)) {
        $data .= fread($handle, 8192);
        if (strlen($data) > $maxBytes) {
            fclose($handle);
            upload_fail("Файл слишком большой. Максимум: {$maxMb} МБ.");
        }
    }

    fclose($handle);

    if ($data === '') {
        upload_fail('По URL пришёл пустой файл.');
    }

    return $data;
}

function save_image(string $sourcePath, string $uploadDir, string $publicPrefix, array $allowed): void
{
    $mime = detect_mime($sourcePath);
    if (!$mime || !isset($allowed[$mime])) {
        upload_fail('Недопустимый тип изображения: ' . ($mime ?? 'unknown'));
    }

    $name = bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
    $target = $uploadDir . '/' . $name;

    if (!@rename($sourcePath, $target)) {
        upload_fail('Не удалось сохранить файл.', 500);
    }

    @chmod($target, 0644);
    upload_ok($publicPrefix . '/' . $name);
}

$allowed = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    upload_fail('Метод не поддерживается.', 405);
}

if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true)) {
    upload_fail('Не могу создать папку uploads.', 500);
}

if (!is_writable($uploadDir)) {
    upload_fail('Папка uploads недоступна для записи.', 500);
}

if (!empty($_FILES['image']['tmp_name'])) {
    $file = $_FILES['image'];

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        upload_fail(upload_error_message((int)($file['error'] ?? UPLOAD_ERR_OK), $maxMb));
    }

    if (($file['size'] ?? 0) > $maxBytes) {
        upload_fail("Файл слишком большой. Максимум: {$maxMb} МБ.");
    }

    if (!is_uploaded_file((string)$file['tmp_name'])) {
        upload_fail('Файл не был загружен через POST.');
    }

    $tmp = tempnam(sys_get_temp_dir(), 'edimg_');
    if (!$tmp || !@move_uploaded_file((string)$file['tmp_name'], $tmp)) {
        upload_fail('Не удалось обработать загруженный файл.', 500);
    }

    save_image($tmp, $uploadDir, $publicPrefix, $allowed);
}

if (!empty($_POST['url'])) {
    $url = trim((string)$_POST['url']);
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        upload_fail('Некорректный URL.');
    }

    $data = read_remote_image($url, $maxBytes, $maxMb);
    $tmp = tempnam(sys_get_temp_dir(), 'edimg_');
    if (!$tmp || @file_put_contents($tmp, $data) === false) {
        upload_fail('Не удалось подготовить файл из URL.', 500);
    }

    save_image($tmp, $uploadDir, $publicPrefix, $allowed);
}

upload_fail('Неверный запрос. Ожидаю файл image или поле url.');
