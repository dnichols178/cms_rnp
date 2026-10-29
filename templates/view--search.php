<div class="view-search">
    <form method="GET" action="<?= base_path('search') ?>" style="margin-bottom: 30px; display: flex; gap: 10px;">
        <input type="text" name="keyword" value="<?= htmlspecialchars($_GET['keyword'] ?? '') ?>" 
               placeholder="Search site content, releases, and documentation..." 
               style="flex: 1; padding: 10px; font-size: 1rem; border: 1px solid #ccd0d5; border-radius: 4px;">
        <button type="submit" class="btn-primary" style="padding: 10px 20px;">Search</button>
    </form>

    <?php if (isset($_GET['keyword'])): ?>
        <?php if (empty($results)): ?>
            <p style="color: #666;">No results found matching "<strong><?= htmlspecialchars($_GET['keyword']) ?></strong>".</p>
        <?php else: ?>
            <p style="margin-bottom: 20px; color: #666;">Showing <?= count($results) ?> result(s) for "<strong><?= htmlspecialchars($_GET['keyword']) ?></strong>":</p>
            <div class="search-results-list">
                <?php foreach ($results as $r): ?>
                    <div style="margin-bottom: 25px; padding-bottom: 18px; border-bottom: 1px solid var(--frnp-border);">
                        <div style="font-size: 0.8rem; color: #003366; font-weight: 600; text-transform: uppercase;">
                            <?= htmlspecialchars($r['type_name']) ?> &bull; <?= date('M j, Y', strtotime($r['created_at'])) ?>
                        </div>
                        <h3 style="margin: 5px 0;">
                            <a href="<?= base_path(htmlspecialchars($r['slug'])) ?>" style="color: var(--frnp-navy); text-decoration: none;">
                                <?= htmlspecialchars($r['title']) ?>
                            </a>
                        </h3>
                        <p style="color: #444; font-size: 0.95rem; line-height: 1.5; margin-bottom: 8px;">
                            <?= htmlspecialchars($r['teaser']) ?>...
                        </p>

                        <?php if (!empty($r['keywords'])): ?>
                            <div style="margin-top: 6px; font-size: 0.82rem; color: #64748b;">
                                <strong style="color: #0b2545;">Keywords:</strong>
                                <?php 
                                    $kw_list = array_filter(array_map('trim', explode(',', $r['keywords'])));
                                    foreach ($kw_list as $kw): 
                                ?>
                                    <span class="tag-pill" style="font-size:0.75rem; padding: 2px 7px;"><?= htmlspecialchars($kw) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>