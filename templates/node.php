<article class="node-default">
    <?php if (!empty($node['body'])): ?>
        <div class="node-body">
            <?= check_markup($node['body'] ?? '', $node['format'] ?? 'filtered_html', ['node' => $node]) ?>
        </div>
    <?php endif; ?>
    <?= render_custom_fields($node) ?>
</article>