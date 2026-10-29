<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content.php';
require_permission('administer_site');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $keys = [
        'site_name', 
        'site_slogan', 
        'sub_tagline', 
        'ga_measurement_id',
        'footer_copyright', 
        'footer_disclaimer'
    ];
    foreach ($keys as $k) {
        set_site_setting($k, trim($_POST[$k] ?? ''));
    }
    set_flash('success', 'Site configuration updated successfully.');
    header('Location: ' . base_path('admin/settings.php'));
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Site Settings - FRNP CMS</title>
    <link rel="stylesheet" href="<?= base_path('assets/css/admin.css') ?>">
</head>
<body>
    <div class="admin-wrapper">
        <?php require __DIR__ . '/nav.php'; ?>
        <main class="admin-main" style="max-width: 850px;">
            <h1>Configuration &raquo; Site Settings</h1>
            <?php render_flash_messages(); ?>

            <form method="POST" class="card">
                <h3>Branding & Identity</h3>
                <div class="form-row">
                    <label>Site Name</label>
                    <input type="text" name="site_name" value="<?= htmlspecialchars(get_site_setting('site_name')) ?>" required>
                </div>
                <div class="form-row">
                    <label>Header Tagline / Project Name</label>
                    <input type="text" name="site_slogan" value="<?= htmlspecialchars(get_site_setting('site_slogan')) ?>">
                </div>
                <div class="form-row">
                    <label>DOE Secondary Tagline</label>
                    <input type="text" name="sub_tagline" value="<?= htmlspecialchars(get_site_setting('sub_tagline')) ?>">
                </div>

                <h3 style="margin-top: 30px;">Footer & Compliance</h3>
                <div class="form-row">
                    <label>Copyright Attribution</label>
                    <input type="text" name="footer_copyright" value="<?= htmlspecialchars(get_site_setting('footer_copyright')) ?>">
                </div>
                <div class="form-row">
                    <label>Legal / Contract Disclaimer</label>
                    <textarea name="footer_disclaimer" rows="3"><?= htmlspecialchars(get_site_setting('footer_disclaimer')) ?></textarea>
                </div>
                <div class="form-row">
                    <label>Google Analytics Measurement ID (GA4)</label>
                    <input type="text" name="ga_measurement_id" value="<?= htmlspecialchars(get_site_setting('ga_measurement_id')) ?>" placeholder="G-XXXXXXXXXX">
                    <small style="color: #64748b;">Injected automatically across all public portal pages. Excluded from administrative paths.</small>
                </div>
                
                <div class="form-actions" style="margin-top: 25px;">
                    <button type="submit" class="btn-primary">Save Configuration</button>
                </div>
            </form>
        </main>
    </div>
</body>
</html>