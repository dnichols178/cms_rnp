<?php

declare(strict_types=1);

// admin/public_comments.php - Native Admin Console
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/public_comments.php';

require_permission('administer_site');

$pdo = function_exists('get_db') ? get_db() : ($db ?? null);
$page_title = 'Public Comments';

// 1. Export CSV Docket Handler
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="public_comments_' . date('Y-m-d_His') . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Date Submitted', 'Full Name', 'Email', 'City', 'Region', 'Country', 'ISP / Org', 'IP Address', 'Comment']);

    $stmt = $pdo->query("SELECT * FROM public_comments ORDER BY created_at DESC");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['id'],
            $row['created_at'],
            $row['full_name'],
            $row['email'],
            $row['geo_city'],
            $row['geo_region'],
            $row['geo_country'],
            $row['geo_org'],
            $row['ip_address'],
            $row['comment']
        ]);
    }
    fclose($output);
    exit;
}

// 2. Save Period Settings Handler
$settings_saved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $title         = trim($_POST['title'] ?? 'Public Comment Period');
    $description   = trim($_POST['description'] ?? '');
    $startDate     = trim($_POST['start_date'] ?? '');
    $endDate       = trim($_POST['end_date'] ?? '');
    $isEnabled     = isset($_POST['is_enabled']) ? 1 : 0;
    $closedMessage = trim($_POST['closed_message'] ?? '');

    $stmt = $pdo->prepare("
        UPDATE public_comments_settings 
        SET title = ?, description = ?, start_date = ?, end_date = ?, is_enabled = ?, closed_message = ?, updated_at = NOW()
        WHERE id = 1
    ");
    $stmt->execute([$title, $description, $startDate, $endDate, $isEnabled, $closedMessage]);
    $settings_saved = true;
}

$settings = get_comments_settings($pdo);

// 3. Commenter User Intelligence Profile
$profile_comment = null;
$visitor_logs = [];
$visitor_summary = [
    'total_views'   => 0,
    'total_dwell'   => 0,
    'downloads'     => [],
    'links_clicked' => [],
    'first_seen'    => null,
    'referrer'      => 'Direct'
];

if (isset($_GET['view_profile'])) {
    $cid = (int)$_GET['view_profile'];
    $cstmt = $pdo->prepare("SELECT * FROM public_comments WHERE id = ?");
    $cstmt->execute([$cid]);
    $profile_comment = $cstmt->fetch(PDO::FETCH_ASSOC);

    if ($profile_comment) {
        $vid = $profile_comment['visitor_id'];
        $vstmt = $pdo->prepare("SELECT * FROM visitor_activity_log WHERE visitor_id = ? ORDER BY created_at ASC");
        $vstmt->execute([$vid]);
        $visitor_logs = $vstmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($visitor_logs as $log) {
            if (!$visitor_summary['first_seen']) {
                $visitor_summary['first_seen'] = $log['created_at'];
                if (!empty($log['referrer'])) {
                    $visitor_summary['referrer'] = $log['referrer'];
                }
            }
            if ($log['event_type'] === 'pageview') {
                $visitor_summary['total_views']++;
            }
            if ($log['event_type'] === 'dwell_time') {
                $visitor_summary['total_dwell'] += (int)$log['dwell_seconds'];
            }
            if ($log['event_type'] === 'file_download') {
                $visitor_summary['downloads'][] = [
                    'url'  => $log['target_url'],
                    'time' => $log['created_at']
                ];
            }
            if ($log['event_type'] === 'link_click') {
                $visitor_summary['links_clicked'][] = [
                    'url'  => $log['target_url'],
                    'time' => $log['created_at']
                ];
            }
        }
    }
}

// 4. Fetch Comments Submissions
$submissions = $pdo->query("SELECT * FROM public_comments ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - Admin</title>
    <link rel="stylesheet" href="<?= base_path() ?>/assets/css/admin.css">
    <style>
        .comment-grid { display: grid; grid-template-columns: 340px 1fr; gap: 24px; }
        .timeline { border-left: 2px solid #cbd5e0; margin-left: 8px; padding-left: 16px; list-style: none; }
        .timeline-item { position: relative; margin-bottom: 14px; }
        .timeline-item::before { content: ""; position: absolute; left: -21px; top: 5px; width: 8px; height: 8px; border-radius: 50%; background: #3182ce; border: 2px solid #fff; }
        .intel-metric-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px; }
        @media (max-width: 992px) { .comment-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body class="admin-body">
<div class="admin-wrapper">
    <?php require __DIR__ . '/nav.php'; ?>

    <div class="admin-main">
        <div class="admin-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; border-bottom:1px solid #e2e8f0; padding-bottom:14px;">
            <div>
                <h1 class="page-title" style="margin:0; font-size:1.5rem;"><?= htmlspecialchars($page_title) ?></h1>
                <small class="text-muted" style="color:#718096;">Manage formal comment window dates and analyze commenter site activity.</small>
            </div>
            <div class="admin-actions">
                <a href="?action=export_csv" class="btn btn-success">⬇ Export Docket (CSV)</a>
                <a href="<?= base_path() ?>/comments.php" target="_blank" class="btn btn-secondary">Open Public Form ↗</a>
            </div>
        </div>

        <?php if ($settings_saved): ?>
            <div class="alert alert-success" style="padding:12px; margin-bottom:16px; background:#def7ec; color:#03543f; border-radius:4px;">
                Comment period configuration successfully updated.
            </div>
        <?php endif; ?>

        <div class="comment-grid">
            <!-- Settings Panel -->
            <div>
                <div class="card" style="background:#fff; border:1px solid #e2e8f0; border-radius:6px; padding:20px;">
                    <h3 class="card-title" style="margin-top:0; font-size:1.1rem; border-bottom:1px solid #edf2f7; padding-bottom:8px;">
                        Comment Period Setup
                    </h3>

                    <form method="POST" action="">
                        <input type="hidden" name="save_settings" value="1">

                        <div class="form-group mb-3">
                            <label class="form-label" style="display:block; font-weight:600; margin-bottom:4px;">Form Status</label>
                            <label style="font-weight:normal; cursor:pointer;">
                                <input type="checkbox" name="is_enabled" value="1" <?= $settings['is_enabled'] ? 'checked' : '' ?>>
                                Accept Submissions
                            </label>
                        </div>

                        <div class="form-group mb-3">
                            <label class="form-label" style="display:block; font-weight:600; margin-bottom:4px;">Title</label>
                            <input type="text" name="title" class="form-control" style="width:100%; padding:8px;" value="<?= htmlspecialchars($settings['title']) ?>" required>
                        </div>

                        <div class="form-group mb-3">
                            <label class="form-label" style="display:block; font-weight:600; margin-bottom:4px;">Description / Instructions</label>
                            <textarea name="description" class="form-control" style="width:100%; padding:8px;" rows="3"><?= htmlspecialchars($settings['description']) ?></textarea>
                        </div>

                        <div class="form-group mb-3">
                            <label class="form-label" style="display:block; font-weight:600; margin-bottom:4px;">Start Date & Time</label>
                            <input type="datetime-local" name="start_date" class="form-control" style="width:100%; padding:8px;"
                                   value="<?= date('Y-m-d\TH:i', strtotime($settings['start_date'])) ?>" required>
                        </div>

                        <div class="form-group mb-3">
                            <label class="form-label" style="display:block; font-weight:600; margin-bottom:4px;">End Date & Time</label>
                            <input type="datetime-local" name="end_date" class="form-control" style="width:100%; padding:8px;"
                                   value="<?= date('Y-m-d\TH:i', strtotime($settings['end_date'])) ?>" required>
                        </div>

                        <div class="form-group mb-3">
                            <label class="form-label" style="display:block; font-weight:600; margin-bottom:4px;">Closed Period Notice</label>
                            <textarea name="closed_message" class="form-control" style="width:100%; padding:8px;" rows="3"><?= htmlspecialchars($settings['closed_message']) ?></textarea>
                        </div>

                        <button type="submit" class="btn btn-primary" style="width:100%;">Save Configuration</button>
                    </form>
                </div>
            </div>

            <!-- Submissions & Profile Section -->
            <div>
                <?php if ($profile_comment): ?>
                    <!-- Commenter Activity & Traffic Profile -->
                    <div class="card mb-4" style="background:#fff; border:2px solid #3182ce; border-radius:6px; padding:20px; margin-bottom:24px;">
                        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:16px;">
                            <div>
                                <h2 style="margin:0; font-size:1.25rem;">Commenter Traffic Profile</h2>
                                <span style="color:#4a5568;">
                                    <strong><?= htmlspecialchars($profile_comment['full_name']) ?></strong> (<?= htmlspecialchars($profile_comment['email']) ?>)
                                </span>
                            </div>
                            <a href="<?= base_path() ?>/admin/public_comments.php" class="btn btn-secondary btn-sm">Close Profile ✖</a>
                        </div>

                        <div style="display:grid; grid-template-columns:repeat(4, 1fr); gap:12px; margin-bottom:16px;">
                            <div class="intel-metric-box">
                                <small style="color:#718096; text-transform:uppercase; font-size:0.75rem;">Location & Network</small><br>
                                <strong><?= htmlspecialchars($profile_comment['geo_city'] . ', ' . $profile_comment['geo_region']) ?></strong><br>
                                <span style="font-size:0.8rem; color:#718096;"><?= htmlspecialchars($profile_comment['geo_org'] ?? 'Direct') ?></span>
                            </div>
                            <div class="intel-metric-box">
                                <small style="color:#718096; text-transform:uppercase; font-size:0.75rem;">Initial Referrer</small><br>
                                <span style="font-size:0.85rem; word-break:break-all;"><?= htmlspecialchars($visitor_summary['referrer'] ?: 'Direct Visit') ?></span>
                            </div>
                            <div class="intel-metric-box">
                                <small style="color:#718096; text-transform:uppercase; font-size:0.75rem;">Total Dwell Time</small><br>
                                <strong><?= round($visitor_summary['total_dwell'] / 60, 1) ?> mins</strong> (<?= $visitor_summary['total_dwell'] ?>s)
                            </div>
                            <div class="intel-metric-box">
                                <small style="color:#718096; text-transform:uppercase; font-size:0.75rem;">Documents Downloaded</small><br>
                                <strong><?= count($visitor_summary['downloads']) ?> files</strong>
                            </div>
                        </div>

                        <div class="mb-3" style="background:#fffaf0; border:1px solid #feebc8; padding:12px; border-radius:4px;">
                            <strong>Submitted Comment:</strong>
                            <p style="margin:6px 0 0; white-space:pre-wrap; font-family:monospace; color:#2d3748;"><?= htmlspecialchars($profile_comment['comment']) ?></p>
                        </div>

                        <h4 style="margin:16px 0 8px; font-size:1rem;">Visitor Journey & Site Engagement Timeline</h4>
                        <?php if (empty($visitor_logs)): ?>
                            <p class="text-muted" style="color:#a0aec0;">No prior interactions logged for this session token.</p>
                        <?php else: ?>
                            <ul class="timeline">
                                <?php foreach ($visitor_logs as $log): ?>
                                    <li class="timeline-item">
                                        <small style="color:#718096;"><?= date('H:i:s', strtotime($log['created_at'])) ?></small> —
                                        <?php if ($log['event_type'] === 'pageview'): ?>
                                            <span class="badge" style="background:#edf2f7; color:#4a5568; padding:2px 6px;">Page Visit</span>
                                            <strong><?= htmlspecialchars($log['page_title'] ?: $log['page_url']) ?></strong>
                                            <div style="font-size:0.75rem; color:#718096;"><?= htmlspecialchars($log['page_url']) ?></div>
                                        <?php elseif ($log['event_type'] === 'file_download'): ?>
                                            <span class="badge" style="background:#feebc8; color:#7b341e; padding:2px 6px;">File Download</span>
                                            <strong><?= htmlspecialchars(basename($log['target_url'])) ?></strong>
                                            <div style="font-size:0.75rem; color:#718096;"><?= htmlspecialchars($log['target_url']) ?></div>
                                        <?php elseif ($log['event_type'] === 'link_click'): ?>
                                            <span class="badge" style="background:#ebf8ff; color:#2b6cb0; padding:2px 6px;">Link Click</span>
                                            <a href="<?= htmlspecialchars($log['target_url']) ?>" target="_blank" style="font-size:0.85rem;"><?= htmlspecialchars($log['target_url']) ?></a>
                                        <?php elseif ($log['event_type'] === 'dwell_time'): ?>
                                            <span style="font-size:0.85rem; color:#4a5568;">Dwell Time: <?= (int)$log['dwell_seconds'] ?>s on page</span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Submissions Table -->
                <div class="card" style="background:#fff; border:1px solid #e2e8f0; border-radius:6px; padding:20px;">
                    <h3 class="card-title" style="margin-top:0; font-size:1.1rem; border-bottom:1px solid #edf2f7; padding-bottom:8px;">
                        Received Submissions (<?= count($submissions) ?>)
                    </h3>

                    <div class="table-responsive">
                        <table class="table" style="width:100%; border-collapse:collapse;">
                            <thead>
                                <tr style="border-bottom:2px solid #e2e8f0; text-align:left;">
                                    <th style="padding:10px;">Date</th>
                                    <th style="padding:10px;">Commenter</th>
                                    <th style="padding:10px;">Location / IP</th>
                                    <th style="padding:10px;">Excerpt</th>
                                    <th style="padding:10px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($submissions)): ?>
                                    <tr>
                                        <td colspan="5" style="text-align:center; padding:24px; color:#a0aec0;">No comments received yet.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($submissions as $sub): ?>
                                        <tr style="border-bottom:1px solid #edf2f7;">
                                            <td style="padding:10px; white-space:nowrap;"><?= date('M j, Y g:i A', strtotime($sub['created_at'])) ?></td>
                                            <td style="padding:10px;">
                                                <strong><?= htmlspecialchars($sub['full_name']) ?></strong><br>
                                                <a href="mailto:<?= htmlspecialchars($sub['email']) ?>" style="color:#3182ce; font-size:0.85rem;"><?= htmlspecialchars($sub['email']) ?></a>
                                            </td>
                                            <td style="padding:10px;">
                                                <span class="badge" style="background:#ebf8ff; color:#2b6cb0; padding:2px 6px; border-radius:3px; font-size:0.8rem;">
                                                    <?= htmlspecialchars($sub['geo_city'] ?: 'Unknown') ?>, <?= htmlspecialchars($sub['geo_region'] ?: '') ?>
                                                </span><br>
                                                <small style="color:#718096;"><?= htmlspecialchars($sub['ip_address']) ?></small>
                                            </td>
                                            <td style="padding:10px; font-size:0.9rem;">
                                                <?= htmlspecialchars(mb_strimwidth($sub['comment'], 0, 80, '...')) ?>
                                            </td>
                                            <td style="padding:10px;">
                                                <a href="?view_profile=<?= $sub['id'] ?>" class="btn btn-primary btn-sm" style="padding:4px 8px; font-size:0.8rem;">View Profile</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
