<?php declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content.php';

// Detect current running script and map child routes to parent sections
$current_script = basename($_SERVER['SCRIPT_NAME'] ?? '');
if ($current_script === 'node_edit.php' || $current_script === 'revisions.php') {
    $current_script = 'nodes.php';
} elseif ($current_script === 'content_type_fields.php') {
    $current_script = 'content_types.php';
} elseif ($current_script === 'user_edit.php') {
    $current_script = 'users.php';
}

$nav_groups = [
    'Content & Assets' => [
        [
            'title'      => 'Content Repository',
            'url'        => base_path('admin/nodes.php'),
            'file'       => 'nodes.php',
            'permission' => 'create_content',
            'icon'       => '<path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/>'
        ],
        [
            'title'      => 'File Manager',
            'url'        => base_path('admin/file_manager.php'),
            'file'       => 'file_manager.php',
            'permission' => 'create_content',
            'icon'       => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z"/>'
        ],
        [
            'title'      => 'Media Assets',
            'url'        => base_path('admin/media.php'),
            'file'       => 'media.php',
            'permission' => 'create_content',
            'icon'       => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z"/>'
        ],
        [
            'title'      => 'Taxonomy Terms',
            'url'        => base_path('admin/taxonomy.php'),
            'file'       => 'taxonomy.php',
            'permission' => 'administer_site',
            'icon'       => '<path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z"/><path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6z"/>'
        ],
    ],
    'Structure & Design' => [
        [
            'title'      => 'Menu Navigation',
            'url'        => base_path('admin/menus.php'),
            'file'       => 'menus.php',
            'permission' => 'administer_site',
            'icon'       => '<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/>'
        ],
        [
            'title'      => 'Block Layout',
            'url'        => base_path('admin/blocks.php'),
            'file'       => 'blocks.php',
            'permission' => 'administer_site',
            'icon'       => '<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z"/>'
        ],
        [
            'title'      => 'Views',
            'url'        => base_path('admin/views.php'),
            'file'       => 'views.php',
            'permission' => 'administer_site',
            'icon'       => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 4.5v15m6-15v15m-10.875 0h15.75c.621 0 1.125-.504 1.125-1.125V5.625c0-.621-.504-1.125-1.125-1.125H4.125C3.504 4.5 3 5.004 3 5.625v12.75c0 .621.504 1.125 1.125 1.125z"/>'
        ],
        [
            'title'      => 'URL Patterns',
            'url'        => base_path('admin/pathauto.php'),
            'file'       => 'pathauto.php',
            'permission' => 'administer_site',
            'icon'       => '<path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244" />'
        ],
        [
            'title'      => 'Content Types',
            'url'        => base_path('admin/content_types.php'),
            'file'       => 'content_types.php',
            'permission' => 'administer_content_types',
            'icon'       => '<path stroke-linecap="round" stroke-linejoin="round" d="M6.429 9.75L2.25 12l4.179 2.25m0-4.5l5.571 3 5.571-3m-11.142 0L2.25 7.5 12 2.25l9.75 5.25-4.179 2.25m0 0L21.75 12l-4.179 2.25m0 0l4.179 2.25L12 21.75 2.25 16.5l4.179-2.25m11.142 0l-5.571 3-5.571-3"/>'
        ],
        [
            'title'      => 'Registrations',
            'url'        => base_path('admin/registrations.php'),
            'file'       => 'registrations.php',
            'permission' => 'view_registrations',
            'icon'       => '<path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/>'
        ],
        [
            'title'      => 'Custom Pages',
            'url'        => base_path('admin/custom_pages.php'),
            'file'       => 'custom_pages.php',
            'permission' => 'administer_site',
            'icon'       => '<path stroke-linecap="round" stroke-linejoin="round" d="M17.25 6.75L22.5 12l-5.25 5.25m-10.5 0L1.5 12l5.25-5.25m7.5-3l-4.5 16.5"/>'
        ],
    ],
    'Communications' => [
        [
            'title'      => 'Alerts & Modals',
            'url'        => base_path('admin/alerts.php'),
            'file'       => 'alerts.php',
            'permission' => 'administer_site',
            'icon'       => '<path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0"/>'
        ],
        [
            'title'      => 'Public Inquiries',
            'url'        => base_path('admin/submissions.php'),
            'file'       => 'submissions.php',
            'permission' => 'administer_site',
            'icon'       => '<path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/>'
        ],
        [
            'title'      => 'Email Transport',
            'url'        => base_path('admin/emails.php'),
            'file'       => 'emails.php',
            'permission' => 'administer_site',
            'icon'       => '<path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5"/>'
        ],
        [
            'title'      => 'Real-Time Stats',
            'url'        => base_path('admin/analytics.php'),
            'file'       => 'analytics.php',
            'permission' => 'administer_site',
            'icon'       => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z"/>'
        ],
        [
            'title'      => 'Rules & Automation',
            'url'        => base_path('admin/rules.php'),
            'file'       => 'rules.php',
            'permission' => 'administer_site',
            'icon'       => '<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"/>'
        ],
    ],
    'Access & Configuration' => [
        [
            'title'      => 'User Accounts',
            'url'        => base_path('admin/users.php'),
            'file'       => 'users.php',
            'permission' => 'administer_users',
            'icon'       => '<path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/>'
        ],
        [
            'title'      => 'Permissions Matrix',
            'url'        => base_path('admin/permissions.php'),
            'file'       => 'permissions.php',
            'permission' => 'administer_site',
            'icon'       => '<path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/>'
        ],
        [
            'title'      => 'URL Redirects',
            'url'        => base_path('admin/redirects.php'),
            'file'       => 'redirects.php',
            'permission' => 'administer_site',
            'icon'       => '<path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/>'
        ],
        [
            'title'      => 'Site Settings',
            'url'        => base_path('admin/settings.php'),
            'file'       => 'settings.php',
            'permission' => 'administer_site',
            'icon'       => '<path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>'
        ],
    ],
    'Maintenance' => [
        [
            'title'      => 'Site Status',
            'url'        => base_path('admin/status.php'),
            'file'       => 'status.php',
            'permission' => 'administer_site',
            'icon'       => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />'
        ],
        [
            'title'      => 'Cron & Cleanup',
            'url'        => base_path('admin/cron.php'),
            'file'       => 'cron.php',
            'permission' => 'administer_site',
            'icon'       => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/>'
        ],
        [
            'title'      => 'Database Backup',
            'url'        => base_path('admin/backup.php'),
            'file'       => 'backup.php',
            'permission' => 'administer_site',
            'icon'       => '<path stroke-linecap="round" stroke-linejoin="round" d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 5.625c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125"/>'
        ],
        [
            'title'      => 'Watchdog Logs',
            'url'        => base_path('admin/watchdog.php'),
            'file'       => 'watchdog.php',
            'permission' => 'administer_site',
            'icon'       => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>'
        ],
        [
            'title'      => 'Cache Performance',
            'url'        => base_path('admin/cache.php'),
            'file'       => 'cache.php',
            'permission' => 'administer_site',
            'icon'       => '<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"/>'
        ],
        [
            'title'      => 'Static Site Export',
            'url'        => base_path('admin/static_export.php'),
            'file'       => 'static_export.php',
            'permission' => 'administer_site',
            'icon'       => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/>'
        ],
        [
            'title'      => 'Drupal Migration',
            'url'        => base_path('admin/migrate_drupal.php'),
            'file'       => 'migrate_drupal.php',
            'permission' => 'administer_site',
            'icon'       => '<path stroke-linecap="round" stroke-linejoin="round" d="M7.5 7.5h-.75A2.25 2.25 0 004.5 9.75v7.5a2.25 2.25 0 002.25 2.25h7.5a2.25 2.25 0 002.25-2.25v-.75m-6-3l9-9m0 0h-5.25m5.25 0v5.25"/>'
        ],
    ]
];

$user = current_user() ?? ['username' => 'Staff Member', 'roles' => ['Authenticated']];
?>
<aside class="admin-sidebar" id="adminSidebar">
    <!-- Brand / Workspace Identifier -->
    <div class="sidebar-brand">
        <a href="<?= base_path('admin/nodes.php') ?>" class="brand-link">
            <span class="brand-badge">FRNP</span>
            <div class="brand-titles">
                <span class="brand-name">Workstation</span>
                <span class="brand-sub">Site Operations</span>
            </div>
        </a>
    </div>

    <!-- Quick Switch to Live Site -->
    <div class="sidebar-quick-actions">
        <a href="<?= base_path('home') ?>" class="btn-live-preview">
            <svg class="nav-svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/>
            </svg>
            <span>Live Front-End</span>
        </a>
    </div>

    <!-- Categorized Menu Navigation -->
    <nav class="sidebar-nav">
        <?php foreach ($nav_groups as $group_label => $items): ?>
            <?php
                $visible_items = array_filter($items, fn($it) => $it['permission'] === null || user_has_permission($it['permission']));
                if (empty($visible_items)) continue;
            ?>
            <div class="nav-group">
                <div class="nav-group-heading"><?= htmlspecialchars($group_label) ?></div>
                <div class="nav-group-items">
                    <?php foreach ($visible_items as $item): ?>
                        <?php $is_active = ($current_script === $item['file']); ?>
                        <a href="<?= htmlspecialchars($item['url']) ?>" class="nav-item <?= $is_active ? 'active' : '' ?>">
                            <svg class="nav-svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                <?= $item['icon'] ?>
                            </svg>
                            <span class="nav-label"><?= htmlspecialchars($item['title']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </nav>

    <!-- Session Account Drawer -->
    <div class="sidebar-user">
        <div class="user-avatar"><?= strtoupper(substr($user['username'] ?? 'U', 0, 1)) ?></div>
        <div class="user-info">
            <span class="user-name"><?= htmlspecialchars($user['username']) ?></span>
            <span class="user-role"><?= htmlspecialchars(ucfirst($user['roles'][0] ?? 'User')) ?></span>
        </div>
        <a href="<?= base_path('admin/logout.php') ?>" class="user-logout" title="Log Out">
            <svg class="nav-svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75"/>
            </svg>
        </a>
    </div>
</aside>

<!-- Universal Theme-Matching Confirmation Modal -->
<div id="frnpConfirmModalOverlay">
    <div class="frnp-confirm-card" role="dialog" aria-modal="true" aria-labelledby="frnpConfirmModalTitle">
        <div class="frnp-confirm-header">
            <h3 id="frnpConfirmModalTitle">Confirm Action</h3>
            <button type="button" class="frnp-confirm-close" onclick="frnpCloseConfirmModal()">&times;</button>
        </div>
        <div class="frnp-confirm-body">
            <p id="frnpConfirmModalMessage" style="margin: 0;"></p>
        </div>
        <div class="frnp-confirm-footer">
            <button type="button" class="btn-secondary" onclick="frnpCloseConfirmModal()" style="padding: 7px 16px; font-size: 0.88rem; cursor: pointer;">Cancel</button>
            <button type="button" id="frnpConfirmModalOkBtn" class="btn-primary" style="padding: 7px 18px; font-size: 0.88rem; cursor: pointer; background: #dc2626; border-color: #b91c1c;">Confirm</button>
        </div>
    </div>
</div>

<!-- Zero-Flicker Scroll Position & Universal Modal Engine -->
<script>
    (function() {
        var sidebar = document.getElementById('adminSidebar');
        if (sidebar) {
            // 1. Immediately restore scroll position before browser paints
            var savedScroll = sessionStorage.getItem('frnp_admin_nav_scroll');
            if (savedScroll !== null) {
                sidebar.scrollTop = parseInt(savedScroll, 10);
            } else {
                var activeItem = sidebar.querySelector('.nav-item.active');
                if (activeItem) {
                    activeItem.scrollIntoView({ block: 'nearest' });
                }
            }

            // 2. Persist scroll position across clicks and unload
            var persistScroll = function() {
                sessionStorage.setItem('frnp_admin_nav_scroll', String(sidebar.scrollTop));
            };

            sidebar.addEventListener('scroll', persistScroll, { passive: true });
            window.addEventListener('beforeunload', persistScroll);

            var links = sidebar.querySelectorAll('a');
            links.forEach(function(link) {
                link.addEventListener('click', persistScroll);
            });
        }
    })();

    // Universal Confirmation Modal Engine
    var frnpPendingConfirmCallback = null;

    function confirmModal(message, onConfirm, options) {
        options = options || {};
        var overlay = document.getElementById('frnpConfirmModalOverlay');
        var msgEl   = document.getElementById('frnpConfirmModalMessage');
        var titleEl = document.getElementById('frnpConfirmModalTitle');
        var okBtn   = document.getElementById('frnpConfirmModalOkBtn');

        if (!overlay || !msgEl || !okBtn) {
            if (window.confirm(message)) {
                if (typeof onConfirm === 'function') onConfirm();
            }
            return;
        }

        msgEl.textContent = message;
        titleEl.textContent = options.title || 'Confirm Action';
        okBtn.textContent = options.confirmText || 'Confirm';

        if (options.danger === false) {
            okBtn.style.background = '#0b2545';
            okBtn.style.borderColor = '#0b2545';
        } else {
            okBtn.style.background = '#dc2626';
            okBtn.style.borderColor = '#b91c1c';
        }

        frnpPendingConfirmCallback = onConfirm;
        overlay.classList.add('active');
        okBtn.focus();
    }

    function frnpCloseConfirmModal() {
        var overlay = document.getElementById('frnpConfirmModalOverlay');
        if (overlay) overlay.classList.remove('active');
        frnpPendingConfirmCallback = null;
    }

    document.addEventListener('DOMContentLoaded', function() {
        var okBtn = document.getElementById('frnpConfirmModalOkBtn');
        if (okBtn) {
            okBtn.addEventListener('click', function() {
                var cb = frnpPendingConfirmCallback;
                frnpCloseConfirmModal();
                if (typeof cb === 'function') {
                    cb();
                }
            });
        }

        // Global Event Delegation for any element with data-confirm
        document.addEventListener('click', function(e) {
            var trigger = e.target.closest('[data-confirm]');
            if (!trigger) return;

            e.preventDefault();
            e.stopImmediatePropagation();

            var message = trigger.getAttribute('data-confirm');
            var title = trigger.getAttribute('data-confirm-title') || 'Confirm Action';
            var btnText = trigger.getAttribute('data-confirm-btn') || 'Confirm';
            var isDanger = trigger.getAttribute('data-confirm-danger') !== 'false';

            confirmModal(message, function() {
                if (trigger.tagName === 'A' && trigger.href) {
                    window.location.href = trigger.href;
                } else if (trigger.tagName === 'BUTTON' && trigger.type === 'submit' && trigger.form) {
                    var form = trigger.form;
                    if (trigger.name) {
                        var hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.name = trigger.name;
                        hidden.value = trigger.value;
                        form.appendChild(hidden);
                    }
                    form.submit();
                } else {
                    trigger.removeAttribute('data-confirm');
                    trigger.click();
                }
            }, {
                title: title,
                confirmText: btnText,
                danger: isDanger
            });
        }, true);
    });
</script>