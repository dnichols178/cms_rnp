<div class="view-term">
    <?php if (!empty($term['description'])): ?>
        <p style="margin-bottom: 25px; color: #555;"><?= htmlspecialchars($term['description']) ?></p>
    <?php endif; ?>

    <?php if (empty($tagged_nodes)): ?>
        <p>No content has been filed under this topic yet.</p>
    <?php else: ?>
        <div class="tagged-items-list">
            <?php foreach ($tagged_nodes as $item): ?>
                <article style="margin-bottom: 25px; padding-bottom: 15px; border-bottom: 1px solid var(--frnp-border);">
                    <div style="font-size: 0.8rem; color: #003366; font-weight: 600; text-transform: uppercase;">
                        <?= htmlspecialchars($item['type_name']) ?> &bull; <?= date('M j, Y', strtotime($item['created_at'])) ?>
                    </div>
                    <h2 style="font-size: 1.3rem; margin: 6px 0;">
                        <a href="<?= base_path(htmlspecialchars($item['slug'])) ?>" style="color: var(--frnp-navy); text-decoration: none;">
                            <?= htmlspecialchars($item['title']) ?>
                        </a>
                    </h2>
                    <p style="color: #444; font-size: 0.95rem; line-height: 1.5;">
                        <?= htmlspecialchars($item['teaser']) ?>...
                    </p>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>