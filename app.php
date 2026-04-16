<?php
declare(strict_types=1);

if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

require_once __DIR__ . '/db.php';

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_https(): bool
{
    return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
}

function site_base_url(): string
{
    $scheme = is_https() ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return rtrim($scheme . '://' . $host, '/');
}

function is_valid_article_code(string $code): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_-]{1,120}$/', $code);
}

function user_can_edit(array $article, string $code): bool
{
    $authorToken = $_COOKIE['edit_' . $code] ?? '';
    $storedToken = (string)($article['edit_token'] ?? '');

    return $authorToken !== '' && $storedToken !== '' && hash_equals($storedToken, (string)$authorToken);
}

function set_article_edit_cookie(string $code, string $editToken): void
{
    setcookie(
        'edit_' . $code,
        $editToken,
        [
            'expires' => time() + 10 * 365 * 24 * 60 * 60,
            'path' => '/',
            'secure' => is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]
    );
}

function editor_json_for_script(?string $json): string
{
    $decoded = json_decode((string)$json);

    if (!$decoded) {
        return '{"blocks":[]}';
    }

    return json_encode(
        $decoded,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ) ?: '{"blocks":[]}';
}

function utf8_substr(string $value, int $start, int $length): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($value, $start, $length);
    }

    return substr($value, $start, $length);
}

function utf8_strlen(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value);
    }

    return strlen($value);
}

function first_editor_image(?string $json): string
{
    $data = json_decode((string)$json, true);
    if (!is_array($data) || empty($data['blocks']) || !is_array($data['blocks'])) {
        return '';
    }

    foreach ($data['blocks'] as $block) {
        if (($block['type'] ?? '') === 'image') {
            $url = (string)($block['data']['file']['url'] ?? '');
            if ($url !== '') {
                return $url;
            }
        }
    }

    return '';
}

function plain_text_from_editor(?string $json, int $limit = 180): string
{
    $data = json_decode((string)$json, true);
    if (!is_array($data) || empty($data['blocks']) || !is_array($data['blocks'])) {
        return '';
    }

    $parts = [];
    foreach ($data['blocks'] as $block) {
        if (!is_array($block)) {
            continue;
        }

        $blockData = is_array($block['data'] ?? null) ? $block['data'] : [];
        foreach (['text', 'caption'] as $key) {
            $text = editor_text($blockData[$key] ?? '');
            if ($text !== '') {
                $parts[] = $text;
            }
        }
    }

    $text = trim(preg_replace('/\s+/', ' ', implode(' ', $parts)) ?? '');

    return utf8_substr($text, 0, $limit);
}

function absolute_public_url(string $url): string
{
    if ($url === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }

    return site_base_url() . '/' . ltrim($url, '/');
}

function editor_text(mixed $value): string
{
    return trim(strip_tags((string)$value));
}

function youtube_embed_url(string $source): ?string
{
    $parts = parse_url($source);
    if (!is_array($parts)) {
        return null;
    }

    $host = strtolower((string)($parts['host'] ?? ''));
    $path = trim((string)($parts['path'] ?? ''), '/');
    $id = null;

    if (in_array($host, ['youtu.be', 'www.youtu.be'], true)) {
        $id = strtok($path, '/');
    }

    if (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com'], true)) {
        if ($path === 'watch') {
            parse_str((string)($parts['query'] ?? ''), $query);
            $id = is_string($query['v'] ?? null) ? $query['v'] : null;
        } elseif (preg_match('#^(embed|shorts)/([A-Za-z0-9_-]{6,32})#', $path, $matches)) {
            $id = $matches[2];
        }
    }

    if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{6,32}$/', $id)) {
        return null;
    }

    return 'https://www.youtube-nocookie.com/embed/' . $id;
}

function vimeo_embed_url(string $source): ?string
{
    $parts = parse_url($source);
    if (!is_array($parts)) {
        return null;
    }

    $host = strtolower((string)($parts['host'] ?? ''));
    $path = trim((string)($parts['path'] ?? ''), '/');
    $id = null;

    if (in_array($host, ['vimeo.com', 'www.vimeo.com'], true) && preg_match('/^(\d{6,12})$/', $path, $matches)) {
        $id = $matches[1];
    }

    if ($host === 'player.vimeo.com' && preg_match('#^video/(\d{6,12})#', $path, $matches)) {
        $id = $matches[1];
    }

    if (!$id) {
        return null;
    }

    return 'https://player.vimeo.com/video/' . $id;
}

function allowed_embed_url(string $service, string $source, string $embed): ?string
{
    $candidate = $source !== '' ? $source : $embed;
    if ($candidate === '') {
        return null;
    }

    $service = strtolower($service);

    if ($service === 'youtube') {
        return youtube_embed_url($candidate);
    }

    if ($service === 'vimeo') {
        return vimeo_embed_url($candidate);
    }

    return youtube_embed_url($candidate) ?? vimeo_embed_url($candidate);
}

function render_embed_iframe(string $src, string $caption = ''): string
{
    $html = '<figure class="embed-block">';
    $html .= '<div class="embed-frame">';
    $html .= '<iframe src="' . h($src) . '" loading="lazy" allowfullscreen ';
    $html .= 'referrerpolicy="strict-origin-when-cross-origin" ';
    $html .= 'allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"></iframe>';
    $html .= '</div>';

    if ($caption !== '') {
        $html .= '<figcaption>' . h($caption) . '</figcaption>';
    }

    $html .= '</figure>';

    return $html;
}

function render_editor_blocks(?string $json): string
{
    $data = json_decode((string)$json, true);
    if (!is_array($data) || empty($data['blocks']) || !is_array($data['blocks'])) {
        return '<p class="article-empty">В статье пока нет текста.</p>';
    }

    $html = '';

    foreach ($data['blocks'] as $block) {
        if (!is_array($block)) {
            continue;
        }

        $type = (string)($block['type'] ?? '');
        $blockData = is_array($block['data'] ?? null) ? $block['data'] : [];

        if ($type === 'header') {
            $level = (int)($blockData['level'] ?? 2);
            $level = min(4, max(2, $level));
            $text = editor_text($blockData['text'] ?? '');
            if ($text !== '') {
                $html .= '<h' . $level . '>' . h($text) . '</h' . $level . '>';
            }
            continue;
        }

        if ($type === 'paragraph') {
            $text = editor_text($blockData['text'] ?? '');
            if ($text !== '') {
                $embedUrl = allowed_embed_url('', $text, '');
                if ($embedUrl) {
                    $html .= render_embed_iframe($embedUrl);
                    continue;
                }

                $html .= '<p>' . nl2br(h($text), false) . '</p>';
            }
            continue;
        }

        if ($type === 'embed') {
            $service = (string)($blockData['service'] ?? '');
            $source = (string)($blockData['source'] ?? '');
            $embed = (string)($blockData['embed'] ?? '');
            $caption = editor_text($blockData['caption'] ?? '');
            $embedUrl = allowed_embed_url($service, $source, $embed);

            if ($embedUrl) {
                $html .= render_embed_iframe($embedUrl, $caption);
            } elseif (filter_var($source, FILTER_VALIDATE_URL)) {
                $html .= '<p><a href="' . h($source) . '" rel="nofollow noopener" target="_blank">' . h($source) . '</a></p>';
            }

            continue;
        }

        if ($type === 'image') {
            $url = (string)($blockData['file']['url'] ?? '');
            $caption = editor_text($blockData['caption'] ?? '');
            if ($url !== '') {
                $html .= '<figure>';
                $html .= '<img src="' . h($url) . '" alt="' . h($caption) . '" loading="lazy">';
                if ($caption !== '') {
                    $html .= '<figcaption>' . h($caption) . '</figcaption>';
                }
                $html .= '</figure>';
            }
            continue;
        }

        if ($type === 'list' && !empty($blockData['items']) && is_array($blockData['items'])) {
            $style = (string)($blockData['style'] ?? 'unordered');
            $tag = $style === 'ordered' ? 'ol' : 'ul';
            $html .= '<' . $tag . '>';
            foreach ($blockData['items'] as $item) {
                $text = editor_text($item);
                if ($text !== '') {
                    $html .= '<li>' . h($text) . '</li>';
                }
            }
            $html .= '</' . $tag . '>';
            continue;
        }

        if ($type === 'quote') {
            $text = editor_text($blockData['text'] ?? '');
            $caption = editor_text($blockData['caption'] ?? '');
            if ($text !== '') {
                $html .= '<blockquote><p>' . h($text) . '</p>';
                if ($caption !== '') {
                    $html .= '<cite>' . h($caption) . '</cite>';
                }
                $html .= '</blockquote>';
            }
            continue;
        }

        if ($type === 'delimiter') {
            $html .= '<hr>';
        }
    }

    return $html !== '' ? $html : '<p class="article-empty">В статье пока нет текста.</p>';
}
