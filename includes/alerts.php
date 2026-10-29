<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * Fetch all active alerts applicable to the current route.
 */
function get_active_site_alerts(string $current_slug): array {
    $db = get_db();
    $stmt = $db->query("
        SELECT * FROM site_alerts
        WHERE status = 1
          AND (start_date IS NULL OR start_date <= NOW())
          AND (end_date IS NULL OR end_date >= NOW())
        ORDER BY FIELD(severity, 'emergency', 'warning', 'info', 'success'), id DESC
    ");
    $candidates = $stmt->fetchAll();
    $applicable = [];

    foreach ($candidates as $alert) {
        $rules = trim($alert['target_pages'] ?? '');
        if ($rules === '' || $rules === '*') {
            $applicable[] = $alert;
            continue;
        }

        $lines = array_filter(array_map('trim', explode("\n", str_replace("\r", "", $rules))));
        $matched = false;

        foreach ($lines as $line) {
            if ($line === '<front>' && ($current_slug === 'home' || $current_slug === '')) {
                $matched = true;
                break;
            }
            if ($line === $current_slug) {
                $matched = true;
                break;
            }
            if (str_contains($line, '*')) {
                $pattern = '#^' . str_replace('\*', '.*', preg_quote($line, '#')) . '$#i';
                if (preg_match($pattern, $current_slug)) {
                    $matched = true;
                    break;
                }
            }
        }

        if ($matched) {
            $applicable[] = $alert;
        }
    }

    return $applicable;
}

/**
 * Output modal HTML, banners, and dismissal scripts.
 */
function render_site_alerts(string $current_slug): void {
    $alerts = get_active_site_alerts($current_slug);
    if (empty($alerts)) {
        return;
    }

    $modals = [];
    $banners = [];

    foreach ($alerts as $a) {
        if ($a['display_type'] === 'modal') {
            $modals[] = $a;
        } else {
            $banners[] = $a;
        }
    }
    ?>

    <style>
        /* Top Banner Alerts */
        .site-banner-alert {
            padding: 12px 20px;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 2px solid rgba(0, 0, 0, 0.1);
            position: relative;
            z-index: 1001;
        }
        .banner-warning { background: #fffbeb; color: #92400e; border-color: #fde68a; }
        .banner-emergency { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
        .banner-info { background: #f0fdf4; color: #166534; border-color: #bbf7d0; }
        .banner-success { background: #f0f9ff; color: #075985; border-color: #bae6fd; }
        .banner-content { flex: 1; margin-right: 15px; }

        /* Modal Dialog Overlay */
        .site-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(7, 27, 48, 0.7);
            z-index: 10000;
            align-items: center;
            justify-content: center;
            padding: 20px;
            backdrop-filter: blur(2px);
            opacity: 0;
            transition: opacity 0.25s ease-in-out;
        }
        .site-modal-overlay.active {
            display: flex;
            opacity: 1;
        }
        .site-modal-card {
            background: #ffffff;
            width: 100%;
            max-width: 580px;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.25), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            transform: scale(0.95);
            transition: transform 0.25s ease-in-out;
            border-top: 6px solid #0b2545;
        }
        .site-modal-overlay.active .site-modal-card {
            transform: scale(1);
        }
        .site-modal-card.modal-warning { border-top-color: #d97706; }
        .site-modal-card.modal-emergency { border-top-color: #dc2626; }
        .site-modal-card.modal-info { border-top-color: #0b2545; }
        .site-modal-card.modal-success { border-top-color: #16a34a; }

        .site-modal-header {
            padding: 20px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #f1f5f9;
        }
        .site-modal-header h3 {
            font-size: 1.25rem;
            color: #0b2545;
            margin: 0;
            font-weight: 700;
        }
        .site-modal-close {
            background: transparent;
            border: none;
            font-size: 1.6rem;
            line-height: 1;
            color: #94a3b8;
            cursor: pointer;
            transition: color 0.15s ease;
        }
        .site-modal-close:hover { color: #0b2545; }
        .site-modal-body {
            padding: 24px;
            color: #334155;
            line-height: 1.6;
            font-size: 1rem;
        }
        .site-modal-footer {
            padding: 16px 24px;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }
        .btn-modal-cta {
            background: #0b2545;
            color: #ffffff !important;
            padding: 9px 18px;
            border-radius: 4px;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
            display: inline-block;
        }
        .btn-modal-cta:hover { background: #c59b27; }
        .btn-modal-dismiss {
            background: #e2e8f0;
            color: #334155;
            border: none;
            padding: 9px 18px;
            border-radius: 4px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
        }
        .btn-modal-dismiss:hover { background: #cbd5e1; }
    </style>

    <!-- Top Alert Banners -->
    <?php foreach ($banners as $b): ?>
        <div id="site-alert-<?= $b['id'] ?>" class="site-banner-alert banner-<?= htmlspecialchars($b['severity']) ?>" style="display:none;">
            <div class="banner-content">
                <strong><?= htmlspecialchars($b['title']) ?>:</strong>
                <?= htmlspecialchars($b['message']) ?>
                <?php if (!empty($b['cta_url']) && !empty($b['cta_label'])): ?>
                    <a href="<?= htmlspecialchars($b['cta_url']) ?>" style="margin-left: 10px; font-weight:700; color:inherit; text-decoration:underline;">
                        <?= htmlspecialchars($b['cta_label']) ?> &raquo;
                    </a>
                <?php endif; ?>
            </div>
            <?php if ($b['dismissible']): ?>
                <button type="button" onclick="dismissSiteAlert(<?= $b['id'] ?>)" style="background:transparent; border:none; font-size:1.2rem; cursor:pointer; color:inherit; padding:0 6px;">&times;</button>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <!-- Modal Pop-Up Dialogs -->
    <?php foreach ($modals as $m): ?>
        <div id="site-modal-overlay-<?= $m['id'] ?>" class="site-modal-overlay" role="dialog" aria-modal="true">
            <div class="site-modal-card modal-<?= htmlspecialchars($m['severity']) ?>">
                <div class="site-modal-header">
                    <h3><?= htmlspecialchars($m['title']) ?></h3>
                    <?php if ($m['dismissible']): ?>
                        <button type="button" class="site-modal-close" onclick="closeSiteModal(<?= $m['id'] ?>)">&times;</button>
                    <?php endif; ?>
                </div>
                <div class="site-modal-body">
                    <?= nl2br(htmlspecialchars($m['message'])) ?>
                </div>
                <div class="site-modal-footer">
                    <?php if (!empty($m['cta_url']) && !empty($m['cta_label'])): ?>
                        <a href="<?= htmlspecialchars($m['cta_url']) ?>" class="btn-modal-cta">
                            <?= htmlspecialchars($m['cta_label']) ?>
                        </a>
                    <?php endif; ?>
                    <?php if ($m['dismissible']): ?>
                        <button type="button" class="btn-modal-dismiss" onclick="closeSiteModal(<?= $m['id'] ?>)">Dismiss</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <script>
        function dismissSiteAlert(id) {
            const banner = document.getElementById('site-alert-' + id);
            if (banner) banner.style.display = 'none';
            try {
                localStorage.setItem('frnp_alert_dismissed_' + id, '1');
            } catch(e) {}
        }

        function closeSiteModal(id) {
            const overlay = document.getElementById('site-modal-overlay-' + id);
            if (overlay) {
                overlay.classList.remove('active');
                setTimeout(() => overlay.style.display = 'none', 250);
            }
            try {
                localStorage.setItem('frnp_alert_dismissed_' + id, '1');
            } catch(e) {}
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Check dismissal status for banners
            <?php foreach ($banners as $b): ?>
                if (!localStorage.getItem('frnp_alert_dismissed_<?= $b['id'] ?>')) {
                    const el = document.getElementById('site-alert-<?= $b['id'] ?>');
                    if (el) el.style.display = 'flex';
                }
            <?php endforeach; ?>

            // Open active modal queue
            <?php foreach ($modals as $m): ?>
                if (!localStorage.getItem('frnp_alert_dismissed_<?= $m['id'] ?>')) {
                    const modal = document.getElementById('site-modal-overlay-<?= $m['id'] ?>');
                    if (modal) {
                        modal.style.display = 'flex';
                        setTimeout(() => modal.classList.add('active'), 50);
                    }
                }
            <?php endforeach; ?>
        });
    </script>
    <?php
}