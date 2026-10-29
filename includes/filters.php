<?php
declare(strict_types=1);

require_once __DIR__ . '/tokens.php';

/**
 * Runs body content through the selected Input Format Filter Pipeline.
 *
 * Supported Formats:
 *  - 'filtered_html': Enforces allowed tags, auto-links URLs, replaces tokens.
 *  - 'full_html': Preserves full markup, replaces tokens.
 *  - 'plain_text': Escapes HTML, converts newlines to <p>/<br>, auto-links URLs.
 */
function check_markup(?string $text, string $format = 'filtered_html', array $context = []): string {
    if ($text === null || $text === '') {
        return '';
    }

    switch ($format) {
        case 'plain_text':
            $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $autolinked = filter_url_autolink($escaped);
            return nl2br($autolinked);

        case 'full_html':
            // Allow all HTML, run token replacement
            return token_replace($text, $context);

        case 'filtered_html':
        default:
            // 1. Strip all except whitelisted safe tags
            $allowed_tags = '<a><p><br><strong><em><b><i><u><ul><ol><li><h3><h4><h5><h6><blockquote><code><pre><img><table><thead><tbody><tr><th><td><hr>';
            $cleaned = strip_tags($text, $allowed_tags);

            // 2. Sanitize links and remove malicious script attributes
            $cleaned = preg_replace('/\s*on[a-z]+\s*=\s*(["\'][^"\']*["\']|[^\s>]+)/i', '', $cleaned);
            $cleaned = preg_replace('/href\s*=\s*["\']\s*javascript:[^"\']*["\']/i', 'href="#"', $cleaned);

            // 3. Autolink bare URLs
            $cleaned = filter_url_autolink($cleaned);

            // 4. Token substitutions
            $cleaned = token_replace($cleaned, $context);

            // Expand view embed shortcodes inside node content
            if (function_exists('replace_view_tags')) {
                $cleaned = replace_view_tags($cleaned);
            }

            return $cleaned;
    }
}

/**
 * Detects unlinked URLs in text and converts them into anchor tags.
 */
function filter_url_autolink(string $html): string {
    // Regex matches URLs not already enclosed within an <a> tag attribute
    return preg_replace_callback(
        '/(?<!href=["\'])(?<!src=["\'])(https?:\/\/[^\s<>"\'\)]+)/i',
        function ($matches) {
            $url = htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8');
            return '<a href="' . $url . '" rel="noopener noreferrer">' . $url . '</a>';
        },
        $html
    );
}