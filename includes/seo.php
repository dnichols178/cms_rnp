<?php
declare(strict_types=1);

/**
 * Real-Time SEO Analysis Engine (Yoast-style Scoring)
 */

function calculate_seo_score(string $title, string $description, string $keyword, string $body, string $slug): array {
    $checks = [];
    $score = 100;

    $keyword = trim(mb_strtolower($keyword));
    $title_len = mb_strlen(trim($title));
    $desc_len = mb_strlen(trim($description));
    
    // Strip HTML from body for word and keyword counts
    $plain_body = strip_html_and_decode($body);
    $word_count = str_word_count($plain_body);

    // 1. Focus Keyword Checks
    if ($keyword === '') {
        $checks[] = ['type' => 'error', 'message' => 'No focus keyword was set. Set a focus keyword to calculate SEO score.'];
        $score -= 30;
    } else {
        // Keyword in Title
        if (!str_contains(mb_strtolower($title), $keyword)) {
            $checks[] = ['type' => 'error', 'message' => "The focus keyword ('{$keyword}') does not appear in the SEO title."];
            $score -= 15;
        } else {
            $checks[] = ['type' => 'success', 'message' => 'Focus keyword appears in the SEO title.'];
        }

        // Keyword in Meta Description
        if (!str_contains(mb_strtolower($description), $keyword)) {
            $checks[] = ['type' => 'warning', 'message' => "The focus keyword ('{$keyword}') does not appear in the meta description."];
            $score -= 10;
        } else {
            $checks[] = ['type' => 'success', 'message' => 'Focus keyword appears in the meta description.'];
        }

        // Keyword in Slug / URL
        if (!str_contains(mb_strtolower($slug), sanitize_keyword_slug($keyword))) {
            $checks[] = ['type' => 'warning', 'message' => "The focus keyword ('{$keyword}') does not appear in the URL slug."];
            $score -= 5;
        } else {
            $checks[] = ['type' => 'success', 'message' => 'Focus keyword appears in the URL slug.'];
        }

        // Keyword Density in Body
        if ($word_count > 0) {
            $keyword_count = mb_substr_count(mb_strtolower($plain_body), $keyword);
            $density = ($keyword_count / $word_count) * 100;
            if ($density < 0.5) {
                $checks[] = ['type' => 'warning', 'message' => "Keyword density is low ({$density}%). Try to mention the keyword a bit more often."];
                $score -= 5;
            } elseif ($density > 3.0) {
                $checks[] = ['type' => 'warning', 'message' => "Keyword density is high ({$density}%). This may be flagged as keyword stuffing."];
                $score -= 10;
            } else {
                $checks[] = ['type' => 'success', 'message' => "Good keyword density ({$density}%)."];
            }
        }
    }

    // 2. Title Length Checks
    if ($title_len === 0) {
        $checks[] = ['type' => 'error', 'message' => 'SEO title is empty.'];
        $score -= 20;
    } elseif ($title_len < 30) {
        $checks[] = ['type' => 'warning', 'message' => "SEO title is too short ({$title_len} chars). Aim for 40-60 characters."];
        $score -= 8;
    } elseif ($title_len > 65) {
        $checks[] = ['type' => 'warning', 'message' => "SEO title is too long ({$title_len} chars). It may be truncated in search results."];
        $score -= 8;
    } else {
        $checks[] = ['type' => 'success', 'message' => "SEO title length is optimal ({$title_len} chars)."];
    }

    // 3. Meta Description Length Checks
    if ($desc_len === 0) {
        $checks[] = ['type' => 'error', 'message' => 'Meta description is empty. Search engines will generate snippets automatically.'];
        $score -= 15;
    } elseif ($desc_len < 120) {
        $checks[] = ['type' => 'warning', 'message' => "Meta description is a bit short ({$desc_len} chars). Aim for 120-155 characters."];
        $score -= 6;
    } elseif ($desc_len > 160) {
        $checks[] = ['type' => 'warning', 'message' => "Meta description is too long ({$desc_len} chars). It will be truncated."];
        $score -= 6;
    } else {
        $checks[] = ['type' => 'success', 'message' => "Meta description length is optimal ({$desc_len} chars)."];
    }

    // 4. Content Length Check
    if ($word_count < 150) {
        $checks[] = ['type' => 'warning', 'message' => "Content contains only {$word_count} words. Aim for at least 300 words for better ranking."];
        $score -= 10;
    } else {
        $checks[] = ['type' => 'success', 'message' => "Good content length ({$word_count} words)."];
    }

    return [
        'score' => max(0, min(100, $score)),
        'checks' => $checks,
        'word_count' => $word_count
    ];
}

function strip_html_and_decode(string $html): string {
    $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', strip_tags($text)));
}

function sanitize_keyword_slug(string $keyword): string {
    $clean = preg_replace('/[^a-zA-Z0-9\s-]/', '', strtolower($keyword));
    return trim(preg_replace('/[\s-]+/', '-', $clean), '-');
}
