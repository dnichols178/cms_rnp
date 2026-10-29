<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content.php';
require_permission('administer_site');

$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_term') {
    if (function_exists('validate_csrf')) {
        validate_csrf();
    }
    $vid = (int)$_POST['vocabulary_id'];
    $name = trim($_POST['name'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $weight = (int)($_POST['weight'] ?? 0);

    if ($slug === '') {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name), '-'));
    }

    if ($vid && $name) {
        try {
            $stmt = $db->prepare('INSERT INTO taxonomy_terms (vocabulary_id, name, slug, weight) VALUES (?, ?, ?, ?)');
            $stmt->execute([$vid, $name, $slug, $weight]);
            set_flash('success', "Term '{$name}' created.");
        } catch (\PDOException $e) {
            set_flash('error', 'Error creating term: ' . $e->getMessage());
        }
    } else {
        set_flash('error', 'Vocabulary and Term Name are required.');
    }

    header('Location: ' . base_path('admin/taxonomy.php'));
    exit;
}

$vocabularies = $db->query('SELECT * FROM taxonomy_vocabularies ORDER BY name ASC')->fetchAll();
$terms = $db->query('
    SELECT t.*, v.name AS vocab_name 
    FROM taxonomy_terms t
    JOIN taxonomy_vocabularies v ON v.id = t.vocabulary_id
    ORDER BY v.name ASC, t.weight ASC, t.name ASC
')->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Taxonomy Management - FRNP CMS</title>
    <link rel="stylesheet" href="<?= base_path('assets/css/admin.css') ?>">
</head>
<body>
    <div class="admin-wrapper">
        <aside class="admin-nav">
            <?php require __DIR__ . '/nav.php'; ?>
        </aside>
        <main class="admin-main">
            <h1>Structure &raquo; Taxonomy Terms</h1>
            <?php render_flash_messages(); ?>

            <section class="card">
                <h3>Existing Terms</h3>
                <table>
                    <thead>
                        <tr><th>Term</th><th>Slug</th><th>Vocabulary</th><th>Weight</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($terms as $t): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($t['name']) ?></strong></td>
                            <td><code><?= htmlspecialchars($t['slug']) ?></code></td>
                            <td><?= htmlspecialchars($t['vocab_name']) ?></td>
                            <td><?= $t['weight'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>

            <section class="card">
                <h3>Add New Taxonomy Term</h3>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                    <input type="hidden" name="action" value="add_term">
                    <div class="form-row">
                        <label>Vocabulary *</label>
                        <select name="vocabulary_id" required>
                            <?php foreach ($vocabularies as $v): ?>
                                <option value="<?= $v['id'] ?>"><?= htmlspecialchars($v['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <label>Term Name *</label>
                        <input type="text" name="name" required placeholder="e.g. Groundwater Monitoring">
                    </div>
                    <div class="form-row">
                        <label>Slug (URL Identifier)</label>
                        <input type="text" name="slug" placeholder="leave-blank-for-automatic">
                    </div>
                    <div class="form-row">
                        <label>Weight (Display Order)</label>
                        <input type="number" name="weight" value="0">
                    </div>
                    <button type="submit" class="btn-primary">Add Term</button>
                </form>
            </section>
        </main>
    </div>
</body>
</html>