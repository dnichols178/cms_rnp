<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/analytics.php';

require_permission('administer_site');

$metrics = get_realtime_metrics();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Real-Time Site Analytics - FRNP CMS</title>
    <link rel="stylesheet" href="<?= base_path('assets/css/admin.css') ?>">
    <meta http-equiv="refresh" content="30">
    <style>
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        .stat-card {
            background: #fff;
            padding: 20px;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .stat-val {
            font-size: 2.2rem;
            font-weight: 800;
            color: #0b2545;
            line-height: 1.1;
        }
        .stat-label {
            font-size: 0.85rem;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 6px;
        }
        .live-pulse {
            display: inline-block;
            width: 10px;
            height: 10px;
            background: #10b981;
            border-radius: 50%;
            margin-right: 6px;
            box-shadow: 0 0 0 rgba(16, 185, 129, 0.4);
            animation: pulse 1.5s infinite;
        }
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
            70% { box-shadow: 0 0 0 8px rgba(16, 185, 129, 0); }
            100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <?php require __DIR__ . '/nav.php'; ?>
        <main class="admin-main">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
                <h1>Site Intelligence &raquo; Real-Time Tracker</h1>
                <span style="font-size:0.85rem; color:#64748b;">Auto-refreshing every 30s &bull; <?= date('H:i:s T') ?></span>
            </div>

            <div class="stat-grid">
                <div class="stat-card">
                    <div class="stat-val"><span class="live-pulse"></span><?= number_format($metrics['active_15m']) ?></div>
                    <div class="stat-label">Active Users (Last 15 Min)</div>
                </div>
                <div class="stat-card">
                    <div class="stat-val"><?= number_format($metrics['today_views']) ?></div>
                    <div class="stat-label">Total Hits Today</div>
                </div>
                <div class="stat-card">
                    <div class="stat-val"><?= number_format($metrics['devices']['mobile'] ?? 0) ?></div>
                    <div class="stat-label">Mobile Traffic Today</div>
                </div>
                <div class="stat-card">
                    <div class="stat-val"><?= number_format($metrics['devices']['desktop'] ?? 0) ?></div>
                    <div class="stat-label">Desktop Traffic Today</div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1.8fr 1fr; gap: 25px;">
                <!-- Most Active Content Today -->
                <section class="card">
                    <h3>Top Visited Pages (Last 24 Hours)</h3>
                    <table>
                        <thead>
                            <tr><th>Route / Slug</th><th style="text-align:right;">Views</th><th style="text-align:right;">Unique Sessions</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($metrics['top_pages'])): ?>
                                <tr><td colspan="3" style="text-align:center; color:#777;">No visits recorded today yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($metrics['top_pages'] as $p): ?>
                                <tr>
                                    <td>
                                       
                                        <strong><a href="<?= base_path(htmlspecialchars($p['path'])) ?>">/<?= htmlspecialchars($p['path']) ?></a></strong>
                                    </td>
                                    <td style="text-align:right; font-weight:600;"><?= number_format((int)$p['hits']) ?></td>
                                    <td style="text-align:right; color:#64748b;"><?= number_format((int)$p['unique_visitors']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </section>

                <!-- Inbound Referrals -->
                <section class="card">
                    <h3>External Referrers (Last 7 Days)</h3>
                    <table>
                        <thead>
                            <tr><th>Origin URL</th><th style="text-align:right;">Hits</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($metrics['referrers'])): ?>
                                <tr><td colspan="2" style="text-align:center; color:#777;">Direct / bookmark traffic only.</td></tr>
                            <?php else: ?>
                                <?php foreach ($metrics['referrers'] as $r): ?>
                                <tr>
                                    <td style="max-width:240px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                        <a href="<?= htmlspecialchars($r['referer']) ?>" rel="nofollow"><?= htmlspecialchars($r['referer']) ?></a>
                                    </td>
                                    <td style="text-align:right; font-weight:600;"><?= number_format((int)$r['count']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </section>
            </div>
        </main>
    </div>
</body>
</html>