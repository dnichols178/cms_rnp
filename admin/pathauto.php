<?php 
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content.php';
require_once __DIR__ . '/../includes/tokens.php';

require_permission('administer_content_types');

$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_patterns') {
    validate_csrf();
    $patterns = $_POST['patterns'] ?? [];

    $stmt = $db->prepare('UPDATE content_types SET url_pattern = :pattern WHERE type = :type');
    foreach ($patterns as $type_key => $pattern) {
        $clean_pattern = trim((string)$pattern);
        if ($clean_pattern === '') {
            $clean_pattern = '[node:title]';
        }
        $stmt->execute([
            'pattern' => $clean_pattern,
            'type'    => $type_key
        ]);
    }

    if (function_exists('cache_clear')) {
        cache_clear();
    }
    watchdog('content', 'Updated global Pathauto URL pattern templates.', 'notice');
    set_flash('success', 'URL alias patterns updated successfully.');
    header('Location: ' . base_path('admin/pathauto.php'));
    exit;
}

$types = get_content_types();

$available_tokens = [
    '[node:title]'      => 'Cleaned, URL-safe node title (e.g., "groundwater-report")',
    '[node:id]'         => 'Unique numeric node ID',
    '[node:type]'       => 'Machine name of the content type (e.g., "article", "page")',
    '[node:author]'     => 'Username of the node creator',
    '[node:created:Y]'  => '4-digit creation year (e.g., "2026")',
    '[node:created:m]'  => '2-digit creation month (e.g., "04")',
    '[node:created:d]'  => '2-digit creation day (e.g., "15")',
    '[term:slug]'       => 'First assigned taxonomy category slug (or "general")',
    '[term:name]'       => 'First assigned taxonomy category name',
    '[site:name]'       => 'Global website name',
    '[current-date:Y]'  => 'Current calendar year',
    '[current-date:m]'  => 'Current calendar month'
];
?>
<!DOCTYPE html>
<html>
<head>
    <title>URL Alias Patterns (Pathauto) - FRNP CMS</title>
    <link rel="stylesheet" href="<?= base_path('assets/css/admin.css') ?>">
    <style>
        .layout-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.8fr) 360px;
            gap: 25px;
            align-items: start;
        }
        @media (max-width: 1080px) {
            .layout-grid { grid-template-columns: 1fr; }
        }
        .pattern-input-group {
            position: relative;
            display: flex;
            align-items: center;
        }
        .pattern-input-group .url-prefix {
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            border-right: none;
            padding: 8px 12px;
            color: #64748b;
            font-size: 0.88rem;
            font-family: var(--font-mono);
            border-radius: var(--radius-md) 0 0 var(--radius-md);
        }
        .pattern-input-group input[type="text"] {
            border-top-left-radius: 0;
            border-bottom-left-radius: 0;
            font-family: var(--font-mono);
            font-size: 0.88rem;
        }
        .token-chip {
            display: inline-block;
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            padding: 3px 7px;
            font-family: var(--font-mono);
            font-size: 0.78rem;
            color: #0b2545;
            cursor: pointer;
            user-select: none;
            transition: all 0.15s ease;
            margin: 0 4px 6px 0;
        }
        .token-chip:hover {
            background: #0b2545;
            color: #ffffff;
            border-color: #0b2545;
        }
        .type-header {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            margin-bottom: 6px;
        }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <?php require __DIR__ . '/nav.php'; ?>
        <main class="admin-main">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h1>Structure &raquo; URL Patterns (Pathauto)</h1>
                <a href="<?= base_path('admin/content_types.php') ?>" class="btn-secondary" style="font-size: 0.84rem;">&laquo; Content Types</a>
            </div>

            <?php render_flash_messages(); ?>

            <div class="layout-grid">
                <!-- Content Type Pattern Matrix -->
                <section class="card">
                    <div style="margin-bottom: 18px;">
                        <h3>Content Type URL Templates</h3>
                        <p style="color: #64748b; font-size: 0.88rem;">
                            Define the default automated path structure applied when new content of each type is published. Forward slashes create hierarchical directories.
                        </p>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                        <input type="hidden" name="action" value="save_patterns">

                        <?php foreach ($types as $t): ?>
                            <?php $current_pattern = $t['url_pattern'] ?? '[node:title]'; ?>
                            <div class="form-row" style="background: #fafbfc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 18px; margin-bottom: 20px;">
                                <div class="type-header">
                                    <label style="font-size: 0.95rem; font-weight: 700; color: #0b2545;">
                                        <?= htmlspecialchars($t['name']) ?> (<code><?= htmlspecialchars($t['type']) ?></code>)
                                    </label>
                                    <span style="font-size: 0.8rem; color: #64748b;">
                                        <?= htmlspecialchars($t['description'] ?: 'No description') ?>
                                    </span>
                                </div>
                                <div class="pattern-input-group">
                                    <span class="url-prefix">/</span>
                                    <input type="text" name="patterns[<?= htmlspecialchars($t['type']) ?>]" 
                                           id="pattern_<?= htmlspecialchars($t['type']) ?>" 
                                           value="<?= htmlspecialchars($current_pattern) ?>" 
                                           required>
                                </div>
                                <small style="margin-top: 6px; color: #64748b;">
                                    Example Output: <code>/<?= htmlspecialchars(pathauto_clean_token_url(token_replace($current_pattern, ['node' => ['title' => 'Example Title', 'type' => $t['type'], 'created_at' => date('Y-m-d H:i:s')]]))) ?></code>
                                </small>
                            </div>
                        <?php endforeach; ?>

                        <div style="margin-top: 15px;">
                            <button type="submit" class="btn-primary" style="padding: 10px 22px;">Save Pattern Templates</button>
                        </div>
                    </form>
                </section>

                <!-- Available Token Drawer -->
                <section class="card">
    <h3 style="margin-bottom: 12px; color: #0b2545;">Available Tokens</h3>
    <p style="font-size: 0.85rem; color: #64748b; margin-bottom: 15px;">
        Click any token below to copy it or insert it into your pattern:
    </p>

    <?php 
    require_once __DIR__ . '/../includes/tokens.php';
    $grouped_tokens = get_available_tokens(); 
    ?>

    <?php foreach ($grouped_tokens as $group_label => $token_list): ?>
        <h4 style="margin: 16px 0 8px; font-size: 0.9rem; color: #0b2545; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px;">
            <?= htmlspecialchars($group_label) ?>
        </h4>
        <table class="data-table" style="width: 100%; font-size: 0.85rem; margin-bottom: 15px;">
            <tbody>
                <?php foreach ($token_list as $token_tag => $token_desc): ?>
                    <tr style="border-bottom: 1px solid #f1f5f9;">
                        <td style="padding: 6px 8px; width: 45%;">
                            <code style="font-weight: 700; color: #0b2545; background: #f1f5f9; padding: 2px 6px; border-radius: 3px; cursor: pointer;" 
                                  title="Click to copy" 
                                  onclick="navigator.clipboard.writeText('<?= htmlspecialchars($token_tag) ?>'); alert('Copied <?= htmlspecialchars($token_tag) ?> to clipboard!');">
                                <?= htmlspecialchars($token_tag) ?>
                            </code>
                        </td>
                        <td style="padding: 6px 8px; color: #475569;">
                            <?= htmlspecialchars($token_desc) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endforeach; ?>
</section>
            </div>
        </main>
    </div>

    <script>
        function copyToken(token, el) {
            navigator.clipboard.writeText(token).then(() => {
                const orig = el.innerText;
                el.innerText = 'Copied!';
                el.style.background = '#2e7d32';
                el.style.color = '#fff';
                setTimeout(() => {
                    el.innerText = orig;
                    el.style.background = '';
                    el.style.color = '';
                }, 1200);
            });
        }
    </script>
</body>
</html>