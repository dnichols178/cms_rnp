<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content.php';
require_once __DIR__ . '/../includes/tokens.php';
require_once __DIR__ . '/../includes/cache.php';

require_permission('administer_content_types');

$db = get_db();
$content_types = get_content_types();

// Handle Form Submission: Update URL Patterns
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_patterns'])) {
    validate_csrf();
    $patterns = $_POST['patterns'] ?? [];

    $stmt = $db->prepare('UPDATE content_types SET url_pattern = :pattern WHERE type = :type');
    $updated_count = 0;

    foreach ($content_types as $ct) {
        $type_key = $ct['type'];
        $pattern = trim($patterns[$type_key] ?? '');
        
        // Fallback to default if blank
        if ($pattern === '') {
            $pattern = ($type_key === 'article') ? 'news/[node:created:Y]/[node:title]' : '[node:title]';
        }

        $stmt->execute([
            'pattern' => $pattern,
            'type'    => $type_key,
        ]);
        $updated_count++;
    }

    cache_clear();
    watchdog('pathauto', "Updated Pathauto URL alias patterns for {$updated_count} content types.", 'notice');
    set_flash('success', 'URL alias patterns updated successfully.');
    header('Location: ' . base_path('admin/pathauto.php'));
    exit;
}

// Dynamically compile all custom fields across all content type schemas
$custom_field_tokens = [];
foreach ($content_types as $ct) {
    $schema = json_decode($ct['fields_schema'] ?? '[]', true);
    if (!is_array($schema)) {
        continue;
    }
    foreach ($schema as $fld) {
        $name = trim((string)($fld['name'] ?? ''));
        $type = trim((string)($fld['type'] ?? 'text'));
        $label = trim((string)($fld['label'] ?? $name));

        if ($name === '' || $name === 'body') {
            continue;
        }

        if (!isset($custom_field_tokens[$name])) {
            $custom_field_tokens[$name] = [
                'name'         => $name,
                'label'        => $label,
                'type'         => $type,
                'content_type' => $ct['name'],
            ];
        }
    }
}
ksort($custom_field_tokens);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>URL Alias Patterns (Pathauto) - FRNP CMS</title>
    <link rel="stylesheet" href="<?= base_path('assets/css/admin.css') ?>">
</head>
<body>
    <div class="admin-wrapper">
        <aside class="admin-nav">
            <?php require __DIR__ . '/nav.php'; ?>
        </aside>

        <main class="admin-main" style="max-width: 1100px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <div>
                    <h1 style="margin: 0 0 4px 0;">Structure &raquo; URL Patterns (Pathauto)</h1>
                    <p style="color: #64748b; font-size: 0.88rem; margin: 0;">
                        Define automatic clean URL alias structures generated upon publishing or saving content.
                    </p>
                </div>
                <div>
                    <a href="<?= base_path('admin/content_types.php') ?>" class="btn-secondary" style="font-size: 0.84rem;">&laquo; Content Types</a>
                </div>
            </div>

            <?php render_flash_messages(); ?>

            <div class="single-col-layout">
                <!-- 1. PATTERN CONFIGURATION (1 COLUMN FULL-WIDTH) -->
                <section class="pattern-card">
                    <form method="POST" action="<?= base_path('admin/pathauto.php') ?>">
                        <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                        <input type="hidden" name="save_patterns" value="1">

                        <?php foreach ($content_types as $ct): ?>
                            <?php 
                                $pattern_val = $ct['url_pattern'] ?? '';
                                if ($pattern_val === '') {
                                    $pattern_val = ($ct['type'] === 'article') ? 'news/[node:created:Y]/[node:title]' : '[node:title]';
                                }
                            ?>
                            <div class="pattern-row">
                                <label style="display: block; font-weight: 700; color: #0b2545; font-size: 0.95rem; margin-bottom: 2px;">
                                    <?= htmlspecialchars($ct['name']) ?> Pattern
                                    <span style="font-weight: normal; font-size: 0.82rem; color: #64748b;">(<?= htmlspecialchars($ct['type']) ?>)</span>
                                </label>
                                <?php if (!empty($ct['description'])): ?>
                                    <small style="color: #64748b; display: block; margin-bottom: 6px;">
                                        <?= htmlspecialchars($ct['description']) ?>
                                    </small>
                                <?php endif; ?>

                                <div class="pattern-input-group">
                                    <span class="pattern-prefix"><?= base_path() ?>/</span>
                                    <input type="text" 
                                           name="patterns[<?= htmlspecialchars($ct['type']) ?>]" 
                                           value="<?= htmlspecialchars($pattern_val) ?>" 
                                           class="pattern-input" 
                                           required>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <div style="margin-top: 25px; padding-top: 18px; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end;">
                            <button type="submit" class="btn-primary" style="padding: 10px 24px; font-size: 0.95rem;">Save URL Patterns</button>
                        </div>
                    </form>
                </section>

                <!-- 2. AVAILABLE REPLACEMENT TOKENS (SPLIT IN 2 COLUMNS) -->
                <section class="pattern-card">
                    <div style="border-bottom: 1px solid #e2e8f0; padding-bottom: 10px; margin-bottom: 12px;">
                        <h2 style="margin: 0 0 4px 0; color: #0b2545; font-size: 1.15rem;">Available Replacement Tokens</h2>
                        <p style="color: #64748b; font-size: 0.86rem; margin: 0;">
                            Click any token below to copy it, then paste it directly into the URL pattern fields above.
                        </p>
                    </div>

                    <div class="token-columns-grid">
                        <!-- LEFT COLUMN: Core Tokens, Site Tokens & Dates -->
                        <div>
                            <div class="token-group" style="margin-bottom: 16px;">
                                <h3>Node Core Tokens</h3>
                                <table class="token-table">
                                    <tr>
                                        <td><code class="token-code">[node:title]</code></td>
                                        <td class="token-desc">Sanitized, URL-safe title slug</td>
                                    </tr>
                                    <tr>
                                        <td><code class="token-code">[node:id]</code></td>
                                        <td class="token-desc">Unique numeric node identifier</td>
                                    </tr>
                                    <tr>
                                        <td><code class="token-code">[node:type]</code></td>
                                        <td class="token-desc">Machine name of content type</td>
                                    </tr>
                                    <tr>
                                        <td><code class="token-code">[node:author]</code></td>
                                        <td class="token-desc">Author's username</td>
                                    </tr>
                                    <tr>
                                        <td><code class="token-code">[term:slug]</code></td>
                                        <td class="token-desc">Primary taxonomy term slug</td>
                                    </tr>
                                    <tr>
                                        <td><code class="token-code">[node:terms]</code></td>
                                        <td class="token-desc">Comma-separated term names</td>
                                    </tr>
                                </table>
                            </div>

                            <div class="token-group" style="margin-bottom: 16px;">
                                <h3>Date & Creation Tokens</h3>
                                <table class="token-table">
                                    <tr>
                                        <td><code class="token-code">[node:created:Y]</code></td>
                                        <td class="token-desc">Creation 4-digit year (e.g. 2026)</td>
                                    </tr>
                                    <tr>
                                        <td><code class="token-code">[node:created:m]</code></td>
                                        <td class="token-desc">Creation 2-digit month (01-12)</td>
                                    </tr>
                                    <tr>
                                        <td><code class="token-code">[node:created:d]</code></td>
                                        <td class="token-desc">Creation 2-digit day of month</td>
                                    </tr>
                                </table>
                            </div>

                            <div class="token-group">
                                <h3>Site & Global Tokens</h3>
                                <table class="token-table">
                                    <tr>
                                        <td><code class="token-code">[site:name]</code></td>
                                        <td class="token-desc">Portal / Organization name</td>
                                    </tr>
                                    <tr>
                                        <td><code class="token-code">[site:slogan]</code></td>
                                        <td class="token-desc">Site slogan or subtitle</td>
                                    </tr>
                                    <tr>
                                        <td><code class="token-code">[current-date:Y]</code></td>
                                        <td class="token-desc">Current calendar year</td>
                                    </tr>
                                    <tr>
                                        <td><code class="token-code">[current-date:m]</code></td>
                                        <td class="token-desc">Current calendar month</td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <!-- RIGHT COLUMN: Dynamic Custom Schema Fields -->
                        <div>
                            <div class="token-group">
                                <h3>Custom Field Tokens (From Content Schemas)</h3>
                                <?php if (empty($custom_field_tokens)): ?>
                                    <p style="color: #64748b; font-size: 0.85rem; margin: 8px 0;">
                                        No custom fields found. Add custom fields under <a href="<?= base_path('admin/content_types.php') ?>" style="color: #0b2545; font-weight: 600;">Content Types</a>.
                                    </p>
                                <?php else: ?>
                                    <table class="token-table">
                                        <?php foreach ($custom_field_tokens as $cf): ?>
                                            <tr>
                                                <td style="white-space: nowrap;">
                                                    <code class="token-code">[node:<?= htmlspecialchars($cf['name']) ?>]</code>
                                                    <?php if (str_starts_with($cf['name'], 'field_')): ?>
                                                        <br><code class="token-code">[node:<?= htmlspecialchars(substr($cf['name'], 6)) ?>]</code>
                                                    <?php endif; ?>

                                                    <?php if ($cf['type'] === 'date'): ?>
                                                        <br><code class="token-code">[node:<?= htmlspecialchars($cf['name']) ?>:Y]</code>
                                                        <br><code class="token-code">[node:<?= htmlspecialchars($cf['name']) ?>:m]</code>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="token-desc">
                                                    <strong><?= htmlspecialchars($cf['label']) ?></strong>
                                                    <span style="font-size: 0.75rem; color: #64748b; display: block;">
                                                        Type: <code><?= htmlspecialchars($cf['type']) ?></code> &bull; Source: <?= htmlspecialchars($cf['content_type']) ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </table>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </main>
    </div>

    <script>
        // Click-to-copy token handler
        document.querySelectorAll('.token-code').forEach(function(el) {
            el.addEventListener('click', function() {
                const token = this.innerText.trim();
                navigator.clipboard.writeText(token).then(function() {
                    const originalBg = el.style.background;
                    el.style.background = '#bbf7d0';
                    setTimeout(function() {
                        el.style.background = originalBg;
                    }, 400);
                });
            });
        });
    </script>
</body>
</html>
