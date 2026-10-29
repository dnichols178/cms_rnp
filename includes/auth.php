<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

// Safe standalone definition of base_path for auth/session initialization
if (!function_exists('base_path')) {
    function base_path(string $path = ''): string {
        static $base = null;
        if ($base === null) {
            $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
            if (preg_match('#^(.*?)/(admin|api|includes|custom_pages)/#', $script, $matches)) {
                $base = rtrim($matches[1], '/');
            } else {
                $dir = dirname($script);
                $base = ($dir === '/' || $dir === '\\' || $dir === '.') ? '' : rtrim($dir, '/');
            }
        }

        $clean = ltrim($path, '/');
        if ($clean === '' || $clean === 'home') {
            return $base !== '' ? $base : '/';
        }
        return ($base !== '' ? $base : '') . '/' . $clean;
    }
}

if (!function_exists('url')) {
    function url(string $path = ''): string {
        return base_path($path);
    }
}

// Session Initialization with Dynamic Path Scope
if (session_status() === PHP_SESSION_NONE) {
    $cookie_path = base_path();
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => $cookie_path !== '' ? $cookie_path : '/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function user_has_permission(string $permission): bool {
    $user = current_user();
    if (!$user) {
        return false;
    }

    // Administrator wildcard bypass
    if (in_array('administrator', $user['roles'], true)) {
        return true;
    }

    return in_array($permission, $user['permissions'], true);
}

function require_permission(string $permission): void {
    enforce_session_timeout();
    $user = current_user();
    if (!$user) {
        header('Location: ' . base_path('admin/index.php'));
        exit;
    }

    if (!user_has_permission($permission)) {
        http_response_code(403);
        die('403 Forbidden: Your account does not have permission to access this resource.');
    }
}

// Rolling 30-Minute Inactivity Session Timeout
function enforce_session_timeout(int $timeout_seconds = 1800): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (isset($_SESSION['user'])) {
        $now = time();
        if (isset($_SESSION['last_activity']) && ($now - $_SESSION['last_activity'] > $timeout_seconds)) {
            $username = $_SESSION['user']['username'] ?? 'User';
            $_SESSION = [];
            session_destroy();
            require_once __DIR__ . '/content.php';
            watchdog('auth', "Session expired for '{$username}' due to inactivity.", 'notice');
            header('Location: ' . base_path('admin/index.php?msg=timeout'));
            exit;
        }
        $_SESSION['last_activity'] = $now;
    }
}

function authenticate_user(string $username, string $password): bool {
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM users WHERE username = :u AND status = 1 LIMIT 1');
    $stmt->execute(['u' => $username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        // Fetch roles
        $role_stmt = $db->prepare('
            SELECT r.machine_name 
            FROM roles r 
            JOIN user_roles ur ON ur.role_id = r.id 
            WHERE ur.user_id = :uid
        ');
        $role_stmt->execute(['uid' => $user['id']]);
        $roles = $role_stmt->fetchAll(PDO::FETCH_COLUMN);

        // Fetch permissions
        $perm_stmt = $db->prepare('
            SELECT DISTINCT p.machine_name 
            FROM permissions p
            JOIN role_permissions rp ON rp.permission_id = p.id
            JOIN user_roles ur ON ur.role_id = rp.role_id
            WHERE ur.user_id = :uid
        ');
        $perm_stmt->execute(['uid' => $user['id']]);
        $permissions = $perm_stmt->fetchAll(PDO::FETCH_COLUMN);

        $_SESSION['user'] = [
            'id'          => (int)$user['id'],
            'username'    => $user['username'],
            'email'       => $user['email'],
            'roles'       => $roles,
            'permissions' => $permissions
        ];

        if (file_exists(__DIR__ . '/rules.php')) {
    require_once __DIR__ . '/rules.php';
    rules_invoke_event('user_login', [
        'user' => $_SESSION['user']
    ]);
}

        require_once __DIR__ . '/content.php';
        watchdog('auth', "User '{$user['username']}' authenticated successfully.", 'info');
        return true;
    }
    return false;
}

// Generate or retrieve session-bound CSRF token
function get_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Validate inbound CSRF token on POST operations
function validate_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        if (empty($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            http_response_code(403);
            require_once __DIR__ . '/content.php';
            watchdog('security', 'CSRF token mismatch detected from IP: ' . ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 'error');
            die('403 Forbidden: Invalid or expired security token. Please refresh the form and try again.');
        }
    }
}