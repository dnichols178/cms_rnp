<div class="view-articles">
    <p style="margin-bottom: 25px; color: #555;">Official updates, media statements, and project milestones from the Paducah Site.</p>
    
    <?php if (empty($articles)): ?>
        <p>No press releases currently published.</p>
    <?php else: ?>
        <div class="articles-list">
            <?php foreach ($articles as $art): ?>
                <article class="article-summary-card" style="margin-bottom: 30px; padding-bottom: 20px; border-bottom: 1px solid var(--frnp-border, #cbd5e1);">
                    <div style="font-size: 0.85rem; color: #777; margin-bottom: 4px;">
                        <?= htmlspecialchars($art['fields_data']['publication_date'] ?? date('F j, Y', strtotime($art['created_at']))) ?>
                    </div>
                    <h2 style="font-size: 1.35rem; margin-bottom: 8px;">
                        <a href="<?= base_path(htmlspecialchars($art['slug'])) ?>" style="color: var(--frnp-navy, #0b2545); text-decoration: none;">
                            <?= htmlspecialchars($art['title']) ?>
                        </a>
                    </h2>
                    <?php if (!empty($art['fields_data']['subtitle'])): ?>
                        <div style="font-style: italic; color: #444; margin-bottom: 10px; font-size: 0.95rem;">
                            <?= htmlspecialchars($art['fields_data']['subtitle']) ?>
                        </div>
                    <?php endif; ?>
                    <p style="color: #333; font-size: 0.95rem; line-height: 1.5;">
                        <?= htmlspecialchars($art['teaser']) ?>...
                    </p>
                    <div style="margin-top: 10px;">
                        <a href="<?= base_path(htmlspecialchars($art['slug'])) ?>" style="color: var(--frnp-navy, #0b2545); font-weight: 600; font-size: 0.85rem; text-decoration: underline;">
                            Read Full Release &raquo;
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <!-- Pagination Pager -->
        <?php if (!empty($pager) && $pager['total_pages'] > 1): ?>
            <?php
                $cur = (int)$pager['current_page'];
                $tot = (int)$pager['total_pages'];
                $window = 10;

                // Relative window starting from viewer's current page
                $start = $cur;
                $end   = min($tot, $start + $window - 1);

                // Near the end of the collection, shift the start back so 10 pages remain visible
                if (($end - $start + 1) < $window) {
                    $start = max(1, $end - $window + 1);
                }

                $page_url = function(int $p): string {
                    return base_path('news') . ($p > 1 ? '?page=' . $p : '');
                };
            ?>
            <nav class="pagination-wrapper" aria-label="News page navigation" style="display: flex; justify-content: center; align-items: center; gap: 8px; margin: 35px 0 20px;">
                <!-- Previous Button -->
                <?php if ($cur > 1): ?>
                    <a href="<?= htmlspecialchars($page_url($cur - 1)) ?>" class="pager-btn" style="padding: 8px 14px; border: 1px solid #cbd5e1; border-radius: 4px; text-decoration: none; font-size: 0.88rem; font-weight: 600; color: var(--frnp-navy, #0b2545); background: #ffffff;">
                        &laquo; Previous
                    </a>
                <?php endif; ?>

                <!-- Relative Page Number Links -->
                <?php for ($p = $start; $p <= $end; $p++): ?>
                    <?php $is_current = ($p === $cur); ?>
                    <a href="<?= htmlspecialchars($page_url($p)) ?>" 
                       style="padding: 8px 14px; border-radius: 4px; text-decoration: none; font-size: 0.88rem; font-weight: 700; <?= $is_current ? 'background: var(--frnp-navy, #0b2545); color: #ffffff; border: 1px solid var(--frnp-navy, #0b2545);' : 'background: #ffffff; color: #334155; border: 1px solid #cbd5e1;' ?>">
                        <?= $p ?>
                    </a>
                <?php endfor; ?>

                <!-- Trailing Ellipsis -->
                <?php if ($end < $tot): ?>
                    <span class="pager-ellipsis" style="padding: 8px 6px; font-weight: 700; color: #64748b; font-size: 1rem; user-select: none;">...</span>
                <?php endif; ?>

                <!-- Next Button -->
                <?php if ($cur < $tot): ?>
                    <a href="<?= htmlspecialchars($page_url($cur + 1)) ?>" class="pager-btn" style="padding: 8px 14px; border: 1px solid #cbd5e1; border-radius: 4px; text-decoration: none; font-size: 0.88rem; font-weight: 600; color: var(--frnp-navy, #0b2545); background: #ffffff;">
                        Next &raquo;
                    </a>
                <?php endif; ?>
            </nav>
            <div style="text-align: center; font-size: 0.8rem; color: #64748b; margin-top: 4px;">
                Showing Page <?= $cur ?> of <?= $tot ?> (<?= $pager['total_items'] ?> total releases)
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>