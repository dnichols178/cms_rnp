<?php
declare(strict_types=1);

/**
 * Node Template: Request for Proposal (node-type-request_for_proposal)
 * Automatically appends download="<filename>" to PDF links rendered in the node.
 */

ob_start();
?>
<article class="node-request-for-proposal">
    <?php if (function_exists('render_node_fields')): ?>
        <?= render_node_fields($node) ?>
    <?php elseif (!empty($node['body'])): ?>
        <div class="node-body">
            <?= check_markup($node['body'], $node['format'] ?? 'filtered_html', ['node' => $node]) ?>
        </div>
    <?php endif; ?>
</article>
<?php
$rfp_html = ob_get_clean();

if (trim($rfp_html) !== '' && stripos($rfp_html, '<a') !== false) {
    $dom = new DOMDocument();
    $prev_errors = libxml_use_internal_errors(true);
    $dom->loadHTML(
        '<?xml encoding="utf-8" ?><div id="rfp-dom-wrap">' . $rfp_html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();
    libxml_use_internal_errors($prev_errors);

    foreach ($dom->getElementsByTagName('a') as $link) {
        if ($link->hasAttribute('download')) {
            continue;
        }

        $href = trim($link->getAttribute('href'));
        $path = (string) parse_url($href, PHP_URL_PATH);
        $text = trim($link->textContent);

        if (preg_match('/\.pdf$/i', $path)) {
            $link->setAttribute('download', basename($path));
        } elseif (preg_match('/\.pdf$/i', $text)) {
            $link->setAttribute('download', basename($text));
        }
    }

    $wrapper = $dom->getElementById('rfp-dom-wrap');
    if ($wrapper) {
        $output = '';
        foreach ($wrapper->childNodes as $child) {
            $output .= $dom->saveHTML($child);
        }
        echo $output;
    } else {
        echo $rfp_html;
    }
} else {
    echo $rfp_html;
}
