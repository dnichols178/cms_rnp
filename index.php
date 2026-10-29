<?php declare(strict_types=1);
require_once __DIR__ . '/includes/content.php';
require_once __DIR__ . '/includes/template.php';
require_once __DIR__ . '/includes/auth.php';

// Resolve Route Path (Dynamically strips base_path from REQUEST_URI)
$path = trim($_GET['q'] ?? '', '/');
if ($path === '' || $path === 'index.php') {
    $req_path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $base = base_path();
    if ($base !== '' && $base !== '/' && str_starts_with((string)$req_path, $base)) {
        $req_path = substr((string)$req_path, strlen($base));
    }
    $path = trim((string)$req_path, '/');
    if ($path === '' || $path === 'index.php') {
        $path = 'home';
    }
}

// Canonical 301 redirect: strip /home from URL if explicitly requested
if ($path === 'home') {
    $req_uri_path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $base = rtrim(base_path(), '/');
    $expected_home = ($base !== '' ? $base : '') . '/home';
    if ($req_uri_path === $expected_home || (isset($_GET['q']) && trim($_GET['q'], '/') === 'home')) {
        $qs = $_GET;
        unset($qs['q']);
        $query = !empty($qs) ? '?' . http_build_query($qs) : '';
        header('Location: ' . base_path('home') . $query, true, 301);
        exit;
    }
}

$db = get_db();

// 1. URL Redirects
$r_stmt = $db->prepare('SELECT target_path, status_code FROM url_redirects WHERE source_path = :src LIMIT 1');
$r_stmt->execute(['src' => $path]);
$redir = $r_stmt->fetch();
if ($redir) {
    http_response_code((int)$redir['status_code']);
    $target = $redir['target_path'];
    if (!str_starts_with($target, 'http://') && !str_starts_with($target, 'https://')) {
        $target = base_path(preg_replace('#^/?cms/#', '', ltrim($target, '/')));
    }
    header('Location: ' . $target);
    exit;
}

// 2. XML Sitemap (/sitemap.xml)
if ($path === 'sitemap.xml') {
    header('Content-Type: application/xml; charset=utf-8');
    $nodes = $db->query("SELECT slug, updated_at FROM nodes WHERE status = 'published' OR status = '1' ORDER BY updated_at DESC")->fetchAll();
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    echo '<url><loc>' . $protocol . $host . base_path('home') . '</loc><changefreq>weekly</changefreq><priority>1.0</priority></url>';
    echo '<url><loc>' . $protocol . $host . base_path('news') . '</loc><changefreq>daily</changefreq><priority>0.8</priority></url>';
    echo '<url><loc>' . $protocol . $host . base_path('contact') . '</loc><changefreq>monthly</changefreq><priority>0.5</priority></url>';
    foreach ($nodes as $n) {
        if ($n['slug'] === 'home') continue;
        $loc = $protocol . $host . base_path(htmlspecialchars($n['slug']));
        $lastmod = date('c', strtotime($n['updated_at']));
        echo "<url><loc>{$loc}</loc><lastmod>{$lastmod}</lastmod><priority>0.7</priority></url>";
    }
    echo '</urlset>';
    exit;
}

// 3. Contact Us Route (/contact)
if ($path === 'contact') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $c_name      = trim($_POST['name'] ?? '');
        $c_email     = trim($_POST['email'] ?? '');
        $c_subject   = trim($_POST['subject'] ?? '');
        $c_message   = trim($_POST['message'] ?? '');
        $c_ip        = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $honeypot    = trim($_POST['frnp_hp_company'] ?? '');
        $submit_time = (int)($_POST['form_token_time'] ?? 0);

        // 1. Silent rejection for bots tripping the hidden honeypot field
        if ($honeypot !== '') {
            watchdog('spam', "Spam bot caught via honeypot trap from IP: {$c_ip}", 'warning');
            $_SESSION['contact_success'] = true;
            set_flash('success', 'Your message has been sent successfully.');
            header('Location: ' . base_path('contact'));
            exit;
        }

        // 2. Minimum form submission threshold (under 2-3 seconds = bot)
        if ($submit_time === 0 || (time() - $submit_time) < 3) {
            $_SESSION['contact_error'] = 'Submission completed too quickly. Please take a moment and re-submit.';
            set_flash('error', $_SESSION['contact_error']);
            header('Location: ' . base_path('contact'));
            exit;
        }

        // 3. Required field validation
        if ($c_name === '' || $c_email === '' || $c_subject === '' || $c_message === '') {
            $_SESSION['contact_error'] = 'All fields are mandatory.';
            set_flash('error', $_SESSION['contact_error']);
            header('Location: ' . base_path('contact'));
            exit;
        } elseif (!filter_var($c_email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['contact_error'] = 'Please enter a valid email address.';
            set_flash('error', $_SESSION['contact_error']);
            header('Location: ' . base_path('contact'));
            exit;
        } else {
            $sub_stmt = $db->prepare('INSERT INTO contact_submissions (name, email, subject, message, ip_address) VALUES (?, ?, ?, ?, ?)');
            $sub_stmt->execute([$c_name, $c_email, $c_subject, $c_message, $c_ip]);
            
            // Trigger Contact Submission Event in Rules Engine
            if (file_exists(__DIR__ . '/includes/rules.php')) {
                require_once __DIR__ . '/includes/rules.php';
                rules_invoke_event('contact_submit', [
                    'contact' => [
                        'name'    => $c_name,
                        'email'   => $c_email,
                        'subject' => $c_subject,
                        'message' => $c_message,
                        'ip'      => $c_ip
                    ]
                ]);
            }

            $_SESSION['contact_success'] = true;
            set_flash('success', 'Thank you! Your message has been sent successfully.');
            header('Location: ' . base_path('contact'));
            exit;
        }
    }

    $contact_success = (bool)($_SESSION['contact_success'] ?? false);
    $contact_error   = (string)($_SESSION['contact_error'] ?? '');
    unset($_SESSION['contact_success'], $_SESSION['contact_error']);

    ob_start();
    include __DIR__ . '/templates/view--contact.php';
    $content = ob_get_clean();
    $page_title = 'Contact | Four Rivers Nuclear Partnership';
    $node = ['title' => 'Contact Us', 'slug' => 'contact'];
    include __DIR__ . '/templates/layout.php';
    exit;
}

// 4. Topic Archives (/term/[slug])
if (str_starts_with($path, 'term/')) {
    $term_slug = substr($path, 5);
    $t_stmt = $db->prepare('SELECT id, name, description FROM taxonomy_terms WHERE slug = :slug LIMIT 1');
    $t_stmt->execute(['slug' => $term_slug]);
    $term = $t_stmt->fetch();
    if ($term) {
        $stmt = $db->prepare("
            SELECT n.id, n.title, n.slug, n.created_at, n.type, ct.name AS type_name,
                   SUBSTRING(REGEXP_REPLACE(COALESCE(n.body, ''), '<[^>]+>', ''), 1, 240) AS teaser
            FROM nodes n
            JOIN node_terms nt ON nt.node_id = n.id
            JOIN content_types ct ON ct.type = n.type
            WHERE nt.term_id = :tid AND (n.status = 'published' OR n.status = '1')
            ORDER BY n.created_at DESC
        ");
        $stmt->execute(['tid' => $term['id']]);
        $tagged_nodes = $stmt->fetchAll();
        ob_start();
        include __DIR__ . '/templates/view--term.php';
        $content = ob_get_clean();
        $page_title = htmlspecialchars($term['name']) . ' | Four Rivers Nuclear Partnership';
        $node = ['title' => 'Topic: ' . $term['name'], 'slug' => 'term/' . $term_slug];
        include __DIR__ . '/templates/layout.php';
        exit;
    }
}

// 5. News Archive (/news)
// 5. News Archive (/news)
if ($path === 'news') {
    $per_page = 5;
    $current_page = max(1, (int)($_GET['page'] ?? 1));

    // Base WHERE conditions for published news/articles
    $where_sql = "
        WHERE (n.type IN ('article', 'news', 'news_updates') OR ct.name LIKE '%News%' OR ct.name LIKE '%Article%')
          AND (n.status = 'published' OR n.status = '1' OR n.status = 1)
    ";

    // 1. Total count query
    $count_sql = "
        SELECT COUNT(*)
        FROM nodes n
        JOIN content_types ct ON ct.type = n.type
        {$where_sql}
    ";
    $total_articles = (int)$db->query($count_sql)->fetchColumn();
    $total_pages = max(1, (int)ceil($total_articles / $per_page));

    // Constrain page to valid range
    if ($current_page > $total_pages && $total_articles > 0) {
        $current_page = $total_pages;
    }
    $offset = ($current_page - 1) * $per_page;

    // 2. Fetch 5 articles for current page
    $articles_sql = "
        SELECT n.id, n.title, n.slug, n.created_at, n.fields_data,
               SUBSTRING(REGEXP_REPLACE(COALESCE(n.body, ''), '<[^>]+>', ''), 1, 260) AS teaser
        FROM nodes n
        JOIN content_types ct ON ct.type = n.type
        {$where_sql}
        ORDER BY n.created_at DESC
        LIMIT :lim OFFSET :off
    ";
    $stmt = $db->prepare($articles_sql);
    $stmt->bindValue(':lim', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($articles as &$item) {
        $item['fields_data'] = json_decode($item['fields_data'] ?? '{}', true) ?: [];
    }
    unset($item);

    // Pagination payload passed to view
    $pager = [
        'current_page' => $current_page,
        'total_pages'  => $total_pages,
        'total_items'  => $total_articles,
        'per_page'     => $per_page
    ];

    ob_start();
    include __DIR__ . '/templates/view--articles.php';
    $content = ob_get_clean();
    $page_title = 'News & Releases | Four Rivers Nuclear Partnership';
    $node = ['title' => 'News & Press Releases', 'slug' => 'news', 'type' => 'view'];
    include __DIR__ . '/templates/layout.php';
    exit;
}

// 6. Dynamic Site Search (/search)
if ($path === 'search') {
    $keyword = trim($_GET['keyword'] ?? $_GET['q'] ?? '');
    $results = [];
    if ($keyword !== '') {
        $search_term = '%' . $keyword . '%';
        $sql = "
            SELECT n.id, n.type, n.title, n.slug, n.created_at, n.fields_data,
                   SUBSTRING(REGEXP_REPLACE(COALESCE(n.body, ''), '<[^>]+>', ''), 1, 260) AS teaser,
                   CASE 
                       WHEN n.keywords LIKE ? THEN 3
                       WHEN n.title LIKE ? THEN 2
                       ELSE 1
                   END AS relevance
            FROM nodes n
            WHERE (n.title LIKE ? OR n.body LIKE ? OR n.keywords LIKE ?)
              AND (n.status = 'published' OR n.status = '1')
            ORDER BY relevance DESC, n.created_at DESC
            LIMIT 50
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([$search_term, $search_term, $search_term, $search_term, $search_term]);
        $results = $stmt->fetchAll();
        foreach ($results as &$r) {
            $r['fields_data'] = json_decode($r['fields_data'] ?? '{}', true);
            $r['terms'] = get_node_terms((int)$r['id']);
        }
        unset($r);
    }
    $page_title = 'Search Results for "' . htmlspecialchars($keyword) . '" | Four Rivers Nuclear Partnership';
    $node = ['title' => 'Search Results', 'slug' => 'search', 'type' => 'search'];
    ob_start();
    ?>
    <div class="search-results-page">
        <form method="GET" action="<?= base_path('search') ?>" style="margin-bottom: 30px; display: flex; gap: 10px;">
            <input type="text" name="keyword" value="<?= htmlspecialchars($keyword) ?>" placeholder="Search site content..." 
                   style="flex: 1; padding: 10px 14px; font-size: 1rem; border: 1px solid #cbd5e1; border-radius: 4px;" required autofocus>
            <button type="submit" class="btn-primary" style="padding: 10px 24px;">Search</button>
        </form>
        <?php if ($keyword === ''): ?>
            <p style="color: #64748b;">Please enter one or more keywords to search the site.</p>
        <?php elseif (empty($results)): ?>
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; padding: 25px; border-radius: 6px; color: #475569;">
                No matches found for <strong>&ldquo;<?= htmlspecialchars($keyword) ?>&rdquo;</strong>.
            </div>
        <?php else: ?>
            <p style="color: #64748b; font-size: 0.9rem; margin-bottom: 20px;">
                Found <?= count($results) ?> result<?= count($results) === 1 ? '' : 's' ?> for <strong>&ldquo;<?= htmlspecialchars($keyword) ?>&rdquo;</strong>:
            </p>
            <div class="search-results-list">
                <?php foreach ($results as $res): ?>
                    <article style="margin-bottom: 24px; padding-bottom: 18px; border-bottom: 1px solid #e2e8f0;">
                        <h3 style="margin: 0 0 6px 0; font-size: 1.2rem;">
                            <a href="<?= base_path($res['slug']) ?>" style="color: #0b2545; text-decoration: none;">
                                <?= htmlspecialchars($res['title']) ?>
                            </a>
                        </h3>
                        <div style="font-size: 0.8rem; color: #64748b; margin-bottom: 8px;">
                            <span style="text-transform: uppercase; font-weight: 600;"><?= htmlspecialchars($res['type']) ?></span> &bull; 
                            <?= date('F j, Y', strtotime($res['created_at'])) ?>
                        </div>
                        <?php if (!empty($res['teaser'])): ?>
                            <p style="margin: 0; color: #334155; font-size: 0.93rem; line-height: 1.5;">
                                <?= htmlspecialchars($res['teaser']) ?>...
                            </p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
    $content = ob_get_clean();
    include __DIR__ . '/templates/layout.php';
    exit;
}

// 7. Custom Pages (/custom_pages/*.php)
$custom_page_dir = realpath(__DIR__ . '/custom_pages');
$candidate_file  = __DIR__ . '/custom_pages/' . $path . '.php';
$resolved_file   = realpath($candidate_file);
if ($resolved_file && str_starts_with($resolved_file, $custom_page_dir) && is_file($resolved_file)) {
    $page_title = ucwords(str_replace(['-', '_'], ' ', basename($path))) . ' | Four Rivers Nuclear Partnership';
    $node = [
        'title'  => ucwords(str_replace(['-', '_'], ' ', basename($path))),
        'slug'   => $path,
        'type'   => 'custom_page',
        'status' => 'published'
    ];
    ob_start();
    require $resolved_file;
    $content = ob_get_clean();
    include __DIR__ . '/templates/layout.php';
    exit;
}

// Registration Form Page Interception (/slug)
if (file_exists(__DIR__ . '/includes/registrations.php')) {
    require_once __DIR__ . '/includes/registrations.php';
    $reg_form = get_registration_form_by_slug($path);
    if ($reg_form) {
        $page_title = htmlspecialchars($reg_form['title']) . ' | Four Rivers Nuclear Partnership';
        $node = [
            'title'  => $reg_form['title'],
            'slug'   => $reg_form['slug'],
            'type'   => 'registration_form',
            'status' => $reg_form['status'] ? 'published' : 'draft'
        ];
        ob_start();
        render_registration_form_view($reg_form, true);
        $content = ob_get_clean();
        include __DIR__ . '/templates/layout.php';
        exit;
    }
}

// 9. Views router

// Check for Views with matching Page path
// Views Router (/slug)
require_once __DIR__ . '/includes/views.php';
$view_page = get_view($path);

if ($view_page && ($view_page['display_type'] ?? '') === 'page') {
    // 1. Resolve title: check configured display title, fall back to view name
    $cfg = $view_page['configuration'] ?? [];
    $disp = $cfg['displays']['page'] ?? $cfg['displays']['default'] ?? [];
    $title = !empty($disp['display_title']) ? $disp['display_title'] : $view_page['name'];

    // 2. Set browser title tag
    $site_name = get_site_setting('site_name', 'Four Rivers Nuclear Partnership');
    $page_title = htmlspecialchars($title) . ' | ' . htmlspecialchars($site_name);

    // 3. Define the $node payload required by layout.php for the hero banner
    $node = [
        'title'  => $title,
        'slug'   => $path,
        'type'   => 'view',
        'status' => 'published'
    ];

    // 4. Render and output
    $content = render_view($view_page['machine_name'], 'default');
    require __DIR__ . '/templates/layout.php';
    exit;
}

// 8. Individual Node Route
$can_view_drafts = user_has_permission('create_content');
$node = get_node_by_slug($path, $can_view_drafts);
if (!$node) {
    http_response_code(404);
    watchdog('page_not_found', "404 Page Not Found encountered for path: '/{$path}'", 'warning');
    $node = [
        'title'       => 'Page Not Found',
        'body'        => '<p>The requested page could not be located on the portal.</p><p><a href="' . base_path('home') . '">Return to Home</a></p>',
        'type'        => 'page',
        'slug'        => '404',
        'created_at'  => date('Y-m-d H:i:s'),
        'fields_data' => []
    ];
}
render_node($node);
