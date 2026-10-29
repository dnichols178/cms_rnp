<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/content.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/analytics.php';

record_pageview($node ?? null);

$can_edit_current_node = !empty($node['id']) && (
    user_has_permission('create_content') || 
    user_has_permission('edit_any_content') ||
    (isset($_SESSION['user']['roles']) && in_array('administrator', $_SESSION['user']['roles'], true))
);

$menu_tree   = get_menu_tree('main');
$site_name   = get_site_setting('site_name', 'Four Rivers Nuclear Partnership');
$site_slogan = get_site_setting('site_slogan', 'Paducah Deactivation Project');
$sub_tagline = get_site_setting('sub_tagline', 'A Department of Energy Cleanup Contractor');
$footer_copy = get_site_setting('footer_copyright', 'Four Rivers Nuclear Partnership, LLC (FRNP). All Rights Reserved.');
$footer_disc = get_site_setting('footer_disclaimer', 'FRNP manages the Deactivation Project at the Paducah Gaseous Diffusion Plant for the U.S. Department of Energy.');
$ga_id       = trim(get_site_setting('ga_measurement_id', ''));

$is_home     = (isset($node['slug']) && $node['slug'] === 'home') || ($path ?? '') === 'home';
$is_article_template = (isset($template_file) && basename($template_file) === 'node--article.php')
    || (!empty($node['template']) && basename($node['template']) === 'node--article.php')
    || (($node['type'] ?? '') === 'article');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if (!empty($node['keywords'])): ?>
        <meta name="keywords" content="<?= htmlspecialchars($node['keywords']) ?>">
    <?php endif; ?>
    <title><?= $page_title ?? (htmlspecialchars($node['title'] ?? 'Paducah Site') . ' | ' . htmlspecialchars($site_name)) ?></title>
    <link rel="stylesheet" href="<?= base_path('assets/css/style.css') ?>">

    <?php if ($ga_id !== ''): ?>
        <script async src="https://www.googletagmanager.com/gtag/js?id=<?= htmlspecialchars($ga_id) ?>"></script>
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('js', new Date());
            gtag('config', '<?= htmlspecialchars($ga_id) ?>');
        </script>
    <?php endif; ?>
</head>
<?php
// Resolve dynamic body classes based on the active route and node context
$body_classes = [];

// Front page class
if (($path ?? '') === 'home' || ($path ?? '') === '') {
    $body_classes[] = 'path-frontpage';
}

// Node-specific classes: node type, node ID, and status
if (!empty($node)) {
    if (!empty($node['type'])) {
        // e.g., page-node-type-page, page-node-type-article, page-node-type-view
        $clean_type = preg_replace('/[^a-z0-9_-]/', '-', strtolower(trim((string)$node['type'])));
        $body_classes[] = 'page-node-type-' . $clean_type;
        $body_classes[] = 'node-type-' . $clean_type;
    }
    if (!empty($node['id'])) {
        $body_classes[] = 'node-' . (int)$node['id'];
    }
    if (isset($node['status'])) {
        $body_classes[] = ($node['status'] == 1 || $node['status'] === 'published') ? 'node-published' : 'node-unpublished';
    }
}

// User authentication status class
$body_classes[] = (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user'])) ? 'user-logged-in' : 'user-anonymous';

$body_class_attr = !empty($body_classes) ? ' class="' . htmlspecialchars(implode(' ', array_unique($body_classes)), ENT_QUOTES, 'UTF-8') . '"' : '';
?>
<body<?= $body_class_attr ?>>
    <!-- Top Utility Bar -->

    <!-- Main Navigation Header (Pinned / Sticky) -->
    <header class="site-header" id="siteHeader">
        <div class="container main-header">
            <div class="logo">
                <a href="<?= base_path('home') ?>">
                    <img width="250" src="<?= base_path('assets/img/frnp_logo.png') ?>">
                </a>
            </div>
            <nav class="nav-menu">
                <?php foreach ($menu_tree as $item): ?>
                    <?php $has_children = !empty($item['children']); ?>
                    <div class="nav-item <?= $has_children ? 'has-dropdown' : '' ?>">
                        <a href="<?= htmlspecialchars($item['url']) ?>" class="nav-link">
                            <?= htmlspecialchars($item['title']) ?>
                            <?php if ($has_children): ?>
                                
                            <?php endif; ?>
                        </a>
                        <?php if ($has_children): ?>
                            <div class="nav-dropdown">
                                <?php foreach ($item['children'] as $sub): ?>
                                    <a href="<?= htmlspecialchars($sub['url']) ?>" class="nav-dropdown-link">
                                        <?= htmlspecialchars($sub['title']) ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if (isset($_SESSION['user'])): ?>
                    <?php 
                        $logged_in_name = $_SESSION['user']['username'] ?? $_SESSION['user']['name'] ?? 'Dashboard';
                    ?>
                    <div class="nav-item">
                        <a class="nav-link" id="loggedin" href="<?= base_path('admin/nodes.php') ?>"><?= htmlspecialchars($logged_in_name) ?></a>
                    </div>
                <?php endif; ?>
            </nav>
        </div>
    </header>

    <?php
// Fetch hierarchical menu tree
$nav_tree = function_exists('get_menu_tree') ? get_menu_tree('main') : [];
?>

<header class="site-header">
    <div class="header-container" style="display: flex; align-items: center; justify-content: space-between; position: relative;">
        
        <!-- Site Logo / Branding -->
        <a href="<?= base_path('home') ?>" class="site-logo">
            <img src="<?= base_path('assets/img/frnp_logo.png') ?>" alt="Four Rivers Nuclear Partnership" style="max-height: 50px; width: auto;">
        </a>

        <!-- Hamburger Toggle Button (Hidden on desktop via CSS) -->
        <button type="button" 
                class="mobile-menu-toggle" 
                id="mobileMenuToggle" 
                aria-label="Toggle Navigation Menu" 
                aria-expanded="false" 
                aria-controls="primaryNavigation">
            <span class="hamburger-bar"></span>
            <span class="hamburger-bar"></span>
            <span class="hamburger-bar"></span>
        </button>

        <!-- Navigation Menu -->
        <nav class="primary-nav" id="primaryNavigation" aria-label="Main Navigation">
            <ul class="nav-menu">
                <?php foreach ($nav_tree as $item): ?>
                    <?php $has_children = !empty($item['children']); ?>
                    <li class="nav-item <?= $has_children ? 'has-dropdown' : '' ?>">
                        <a href="<?= htmlspecialchars($item['url']) ?>" class="nav-link">
                            <?= htmlspecialchars($item['title']) ?>
                            <?php if ($has_children): ?>
                                <span class="dropdown-caret" aria-hidden="true">&#9662;</span>
                            <?php endif; ?>
                        </a>
                        <?php if ($has_children): ?>
                            <ul class="dropdown-menu">
                                <?php foreach ($item['children'] as $child): ?>
                                    <li class="dropdown-item">
                                        <a href="<?= htmlspecialchars($child['url']) ?>" class="dropdown-link">
                                            <?= htmlspecialchars($child['title']) ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>

    </div>
</header>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const toggleBtn = document.getElementById('mobileMenuToggle');
    const primaryNav = document.getElementById('primaryNavigation');

    if (!toggleBtn || !primaryNav) return;

    toggleBtn.addEventListener('click', function() {
        const isOpen = primaryNav.classList.toggle('is-open');
        toggleBtn.classList.toggle('is-active');
        toggleBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });

    // Close mobile menu when clicking outside
    document.addEventListener('click', function(e) {
        if (!primaryNav.contains(e.target) && !toggleBtn.contains(e.target) && primaryNav.classList.contains('is-open')) {
            primaryNav.classList.remove('is-open');
            toggleBtn.classList.remove('is-active');
            toggleBtn.setAttribute('aria-expanded', 'false');
        }
    });

    // Toggle dropdowns on mobile touch/click
    const dropdownItems = primaryNav.querySelectorAll('.has-dropdown > .nav-link');
    dropdownItems.forEach(function(item) {
        item.addEventListener('click', function(e) {
            if (window.innerWidth <= 768) {
                e.preventDefault();
                this.parentElement.classList.toggle('dropdown-open');
            }
        });
    });
});
</script>

    <!-- Hero Banner (Subpages) OR Skydio-Style Video Hero (Home Page) -->
    <?php if ($is_home): ?>
        <?php if (file_exists(__DIR__ . '/node--home.php')): ?>
            <?php include __DIR__ . '/node--home.php'; ?>
        <?php else: ?>
            <section class="skydio-hero-wrapper" id="homeHero">
                <?php
// Physical filesystem paths
$webp_fs_path = __DIR__ . '/../assets/img/hero-poster.webp';
$jpg_fs_path  = __DIR__ . '/../assets/img/hero-poster.jpg';

// Check browser support via Accept header
$accepts_webp = isset($_SERVER['HTTP_ACCEPT']) && str_contains(strtolower($_SERVER['HTTP_ACCEPT']), 'image/webp');

// Serve WebP only if browser accepts it AND the physical file exists
if ($accepts_webp && file_exists($webp_fs_path)) {
    $hero_poster_url = base_path('assets/img/hero-poster.webp');
} elseif (file_exists($jpg_fs_path)) {
    $hero_poster_url = base_path('assets/img/hero-poster.jpg');
} else {
    // Database or global fallback
    $hero_poster_url = get_site_setting('home_hero_poster', base_path('assets/img/hero-poster.jpg'));
}
?>

<div class="video-crop-container">
    <video class="hero-video-element" autoplay muted loop playsinline preload="metadata" poster="<?= htmlspecialchars($hero_poster_url, ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true">
        <source src="<?= base_path('assets/video/deactivation-loop.mp4') ?>" type="video/mp4">
    </video>
    <div class="hero-scrim-overlay"></div>
</div>
                <div class="hero-foreground-content">
                    <div class="hero-content-inner">
                        <h1 class="hero-main-title"><?= htmlspecialchars(get_site_setting('home_hero_headline', 'Paducah Deactivation Project')) ?></h1>
                        <p class="hero-tagline"><?= htmlspecialchars(get_site_setting('home_hero_subhead', 'Safe. Compliant. Mission-Driven Environmental Remediation.')) ?></p>
                        <div class="hero-button-group">
                            <a href="<?= base_path('program-areas') ?>" class="btn-hero-solid">Explore Operations</a>
                            <a href="#overviewSection" class="btn-hero-translucent" onclick="smoothScrollToOverview(event)">Project Overview &darr;</a>
                        </div>
                    </div>
                    <div class="hero-scroll-cue" onclick="smoothScrollToOverview(event)">
                        <span class="scroll-label">Scroll to explore</span>
                        <div class="scroll-arrow-indicator"></div>
                    </div>
                </div>
            </section>
        <?php endif; ?>
    <?php elseif (!$is_article_template): ?>
        <section class="hero-banner">
            <div class="container">
                <div class="breadcrumb">
                    <a href="<?= base_path('home') ?>">Home</a> &raquo;
                    <?php if (!empty($node['slug']) && $node['slug'] !== 'home'): ?>
                        <span class="current-page"><?= htmlspecialchars($node['title'] ?? '') ?></span>
                    <?php else: ?>
                        <span class="current-page">Overview</span>
                    <?php endif; ?>
                </div>
                <h1><?= htmlspecialchars($node['title'] ?? 'Paducah Deactivation Project') ?></h1>
            </div>
        </section>
    <?php endif; ?>

    <!-- Main Content Grid -->
    <main class="container main-container" id="overviewSection">
        <div class="content-wrapper">
            <?php if ($can_edit_current_node): ?>
                <?php if (!empty($node['id']) && user_has_permission('edit_any_content')): ?>
                    <div class="node-contextual-actions">
                        <!-- Edit Button with Gear Icon -->
                        <a href="<?= base_path('admin/node_edit.php?id=' . (int)$node['id']) ?>" 
                           class="btn-contextual-pill btn-contextual-edit" 
                           title="Edit this content">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;">
                                <path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                            <span>Edit</span>
                        </a>

                        <!-- Revisions Button with Document Icon -->
                        <a href="<?= base_path('admin/revisions.php?id=' . (int)$node['id']) ?>" 
                           class="btn-contextual-pill btn-contextual-rev" 
                           title="View revision log">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                <polyline points="14 2 14 8 20 8"/>
                                <line x1="16" y1="13" x2="8" y2="13"/>
                                <line x1="16" y1="17" x2="8" y2="17"/>
                                <polyline points="10 9 9 9 8 9"/>
                            </svg>
                            <span>Revisions</span>
                        </a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($is_article_template && !empty($node['title'])): ?>
                <h1 class="article-title" style="color: var(--frnp-navy, #0b2545); margin-top: 0; margin-bottom: 20px; font-size: 2.2rem; line-height: 1.25;">
                    <?= htmlspecialchars($node['title']) ?>
                </h1>
            <?php endif; ?>

            <?php render_region('content_top', $node['slug'] ?? '', $node ?? null); ?>
            <?= $content ?? '' ?>
            <?php render_region('content_bottom', $node['slug'] ?? '', $node ?? null); ?>
        </div>

        <aside class="sidebar">
            <div class="sidebar-box">
                <h3>Search Site</h3>
                <form method="GET" action="<?= base_path('search') ?>" class="search-form-sidebar">
                    <input type="text" name="keyword" placeholder="Search keywords..." required>
                    <button type="submit">Go</button>
                </form>
            </div>

            <?php render_region('sidebar', $node['slug'] ?? '', $node ?? null); ?>
        </aside>
    </main>

    <!-- Home 3-Column Blocks Section -->
    <?php if ($is_home): ?>
        <div class="container">
            <section class="home-columns-section">
                <div class="container home-three-col-grid">
                    <?php render_region('home_columns', $node['slug'] ?? 'home', $node ?? null); ?>
                </div>
            </section>
        </div>
    <?php endif; ?>

    <!-- Footer -->
    <footer class="site-footer">
        <div class="container footer-top">
            <div class="footer-grid">
                <div class="footer-col">
                    <h4>About FRNP</h4>
                    <p><?= htmlspecialchars($footer_disc) ?></p>
                </div>
                <div class="footer-col">
                    <h4>Navigation</h4>
                    <ul>
                        <li><a href="<?= base_path('home') ?>">Home</a></li>
                        <li><a href="<?= base_path('about-us') ?>">About Us</a></li>
                        <li><a href="<?= base_path('program-areas') ?>">Program Areas</a></li>
                        <li><a href="<?= base_path('news') ?>">News & Releases</a></li>
                        <li><a href="<?= base_path('contact') ?>">Contact Us</a></li>
                    </ul>
                </div>
                <div class="footer-col">
                    <h4>Site Office</h4>
                    <p>
                        <strong>Four Rivers Nuclear Partnership, LLC</strong><br>
                        5511 Hobbs Road<br>
                        Kevil, KY 42053
                    </p>
                </div>
            </div>
        </div>
        <div class="footer-bottom">
            <div class="container footer-bottom-content">
                <div>&copy; <?= date('Y') ?> <?= htmlspecialchars($footer_copy) ?></div>
                <div>
                    <a href="<?= base_path('sitemap.xml') ?>">XML Sitemap</a>
                    <a href="<?= base_path('contact') ?>">Site Help</a>
                    <?php if (!isset($_SESSION['user'])): ?>
                        <a href="<?= base_path('admin/index.php') ?>">Staff Login</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </footer>

    <?php 
        if (file_exists(__DIR__ . '/../includes/alerts.php')) {
            require_once __DIR__ . '/../includes/alerts.php';
            render_site_alerts($node['slug'] ?? ($path ?? 'home'));
        }
    ?>

    <!-- Responsive Viewport & Smooth Scroll Telemetry -->
    <script>
        (function() {
            function updateHeroViewport() {
                const header = document.getElementById('siteHeader');
                if (header) {
                    const rect = header.getBoundingClientRect();
                    const availableHeight = Math.max(window.innerHeight - rect.bottom, 480);
                    document.documentElement.style.setProperty('--hero-viewport-height', availableHeight + 'px');
                    document.documentElement.style.setProperty('--header-height', header.offsetHeight + 'px');
                }
            }
            window.addEventListener('resize', updateHeroViewport, { passive: true });
            window.addEventListener('orientationchange', updateHeroViewport);
            window.addEventListener('DOMContentLoaded', updateHeroViewport);
            updateHeroViewport();
        })();

        function smoothScrollToOverview(e) {
            e.preventDefault();
            const target = document.getElementById('overviewSection');
            const header = document.getElementById('siteHeader');
            if (!target) return;
            const headerOffset = header ? header.offsetHeight : 0;
            const elementPosition = target.getBoundingClientRect().top + window.pageYOffset;
            const offsetPosition = elementPosition - headerOffset;
            window.scrollTo({
                top: offsetPosition,
                behavior: 'smooth'
            });
        }
    </script>
</body>
</html>
