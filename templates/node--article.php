<article class="node-article">
    <header class="article-meta" style="margin-bottom: 24px;">
        <?php if (!empty($node['fields_data']['publication_date'])): ?>
            <time class="pub-date"><?= htmlspecialchars($node['fields_data']['publication_date']) ?></time>
        <?php else: ?>
            <time class="pub-date"><?= date('F j, Y', strtotime($node['created_at'])) ?></time>
        <?php endif; ?>
    </header>

    <!-- Renders title-first, followed by image, body, downloads, etc. in exact weight order -->
    <?= render_node_fields($node) ?>
</article>