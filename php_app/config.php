<?php
// Global Configuration, Database Connection & UI Framework
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Robust environment variable loader supporting comments, quotes, and whitespace.
 */
function env($key, $default = '') {
    static $env_cache = null;
    if ($env_cache === null) {
        $env_cache = [];
        $paths = [
            __DIR__ . '/../.env',
            __DIR__ . '/.env'
        ];
        
        $env_file = null;
        foreach ($paths as $p) {
            if (file_exists($p)) {
                $env_file = $p;
                break;
            }
        }

        if ($env_file) {
            $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                if (strpos($line, '=') !== false) {
                    [$k, $v] = explode('=', $line, 2);
                    $k = trim($k);
                    $v = trim($v);
                    // Strip surrounding quotes if present
                    if ((str_starts_with($v, '"') && str_ends_with($v, '"')) ||
                        (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
                        $v = substr($v, 1, -1);
                    }
                    $env_cache[$k] = $v;
                }
            }
        }
    }
    return $env_cache[$key] ?? $default;
}

// Database Connection with friendly error diagnostics
$db_host = env('DB_HOST', '127.0.0.1');
$db_port = env('DB_PORT', '3306');
$db_name = env('DB_NAME', 'resume_ai');
$db_user = env('DB_USER', 'root');
$db_pass = env('DB_PASS', '');

try {
    $dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4";
    $pdo = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    // If running in CLI mode, show concise error
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Database Connection Error: " . $e->getMessage() . "\n");
        exit(1);
    }

    // Friendly HTML diagnostic page for developers & students
    http_response_code(500);
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Database Setup Required</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>body{background:#f8fafc;font-family:system-ui,-apple-system,sans-serif;padding:40px 20px;}</style></head>
    <body><div class="container" style="max-width:700px;">
    <div class="card shadow-sm border-danger p-4">
    <h3 class="text-danger mb-3"><i class="bi bi-exclamation-triangle"></i> Database Connection Error</h3>
    <p class="text-muted">Could not connect to the MySQL database <code>' . htmlspecialchars($db_name) . '</code>.</p>
    <div class="alert alert-secondary font-monospace small">' . htmlspecialchars($e->getMessage()) . '</div>
    <h6>Quick Troubleshooting Checklist:</h6>
    <ol class="small text-secondary">
      <li>Make sure <strong>MySQL is running</strong> in XAMPP / WampServer.</li>
      <li>Open <strong>phpMyAdmin</strong> (<a href="http://localhost/phpmyadmin" target="_blank">http://localhost/phpmyadmin</a>).</li>
      <li>Create or import the schema file: <code>database/schema.sql</code>.</li>
      <li>Confirm credentials in <code>.env</code> (Default: Host: <code>localhost</code>, User: <code>root</code>, Password: empty).</li>
    </ol>
    <a href="' . htmlspecialchars($_SERVER['REQUEST_URI']) . '" class="btn btn-primary btn-sm mt-2">Retry Connection</a>
    </div></div></body></html>';
    exit;
}

// AI Microservice endpoint
const AI_URL = 'http://127.0.0.1:8000';

/**
 * Execute a POST request against the AI microservice without deprecated curl_close().
 * Returns [raw_response, error_string].
 */
function ai_post($endpoint, $postfields = null, $timeout = 60) {
    $ch = curl_init(AI_URL . $endpoint);
    $options = [
        CURLOPT_POST           => 1,
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_TIMEOUT        => $timeout,
    ];
    if ($postfields !== null) {
        $options[CURLOPT_POSTFIELDS] = $postfields;
    }
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    unset($ch); // CurlHandle automatically cleaned up; avoids PHP 8.5+ curl_close deprecation
    return [$response, $error];
}

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function require_role($role = null) {
    if (empty($_SESSION['uid'])) {
        header('Location: index.php');
        exit;
    }
    // Check if initial password reset is enforced
    if (!empty($_SESSION['must_change']) && basename($_SERVER['PHP_SELF']) !== 'change_password.php') {
        header('Location: change_password.php');
        exit;
    }
    // If a specific role is demanded (e.g. 'admin'), verify access
    if ($role === 'admin' && ($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Access Denied: Administrator role required.');
    }
}

function csrf() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function check_csrf() {
    $token = $_POST['csrf'] ?? '';
    if (!$token || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(400);
        exit('Invalid CSRF authentication token. Please refresh the page and try again.');
    }
}

function log_admin_action($pdo, $admin_id, $action, $details = '') {
    try {
        $stmt = $pdo->prepare("INSERT INTO admin_activity_logs (admin_id, action, details) VALUES (?, ?, ?)");
        $stmt->execute([$admin_id, $action, $details]);
    } catch (Exception $e) {
        // Silently continue if log table is unavailable
    }
}

function header_html($title = 'AI Resume Platform') {
    $role = $_SESSION['role'] ?? '';
    $name = $_SESSION['name'] ?? '';
    $currentPage = basename($_SERVER['PHP_SELF']);
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=h($title)?> &mdash; AI Resume &amp; Job Matcher</title>
  <!-- Bootstrap 5.3 & Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --primary-color: #2563eb;
      --primary-dark: #1d4ed8;
      --surface-color: #ffffff;
      --bg-color: #f1f5f9;
      --text-main: #0f172a;
      --text-muted: #64748b;
      --card-radius: 12px;
    }
    body {
      background-color: var(--bg-color);
      color: var(--text-main);
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }
    .navbar {
      background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
      box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    }
    .navbar-brand {
      font-weight: 700;
      letter-spacing: -0.02em;
    }
    .brand-icon {
      background: linear-gradient(135deg, #3b82f6, #8b5cf6);
      width: 34px;
      height: 34px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 8px;
      margin-right: 10px;
    }
    .card {
      border: 1px solid #e2e8f0;
      border-radius: var(--card-radius);
      box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05);
      transition: transform 0.15s ease, box-shadow 0.15s ease;
    }
    .stat-card {
      background: var(--surface-color);
      border-left: 4px solid var(--primary-color);
    }
    .badge-pill-soft {
      font-weight: 500;
      padding: 0.35rem 0.65rem;
      border-radius: 9999px;
    }
    .score-circle {
      width: 110px;
      height: 110px;
      border-radius: 50%;
      display: inline-flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      color: #fff;
      box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }
    .footer {
      margin-top: auto;
      border-top: 1px solid #e2e8f0;
      background: #ffffff;
      color: #94a3b8;
    }
    .nav-link.active {
      font-weight: 600;
      color: #60a5fa !important;
    }
  </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark py-2 px-3">
  <div class="container-fluid">
    <a class="navbar-brand d-flex align-items-center" href="index.php">
      <div class="brand-icon text-white"><i class="bi bi-cpu-fill"></i></div>
      <span>ResumeAI <small class="fw-normal text-secondary fs-6">| Smart Job Matcher</small></span>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navMain">
      <?php if (!empty($_SESSION['uid'])): ?>
      <ul class="navbar-nav me-auto mb-2 mb-lg-0 ms-lg-3">
        <li class="nav-item">
          <a class="nav-link <?=$currentPage === 'student.php' ? 'active' : ''?>" href="student.php">
            <i class="bi bi-file-earmark-person me-1"></i> Student Portal
          </a>
        </li>
        <?php if ($role === 'admin'): ?>
        <li class="nav-item">
          <a class="nav-link <?=$currentPage === 'admin.php' ? 'active' : ''?>" href="admin.php">
            <i class="bi bi-speedometer2 me-1"></i> Admin Dashboard
          </a>
        </li>
        <?php endif; ?>
      </ul>
      <div class="d-flex align-items-center gap-2">
        <span class="text-light small">
          <i class="bi bi-person-circle me-1"></i> <?=h($name)?>
          <span class="badge <?=$role === 'admin' ? 'bg-danger' : 'bg-primary'?> ms-1"><?=strtoupper(h($role))?></span>
        </span>
        <a class="btn btn-sm btn-outline-light ms-2" href="change_password.php" title="Change Password">
          <i class="bi bi-key"></i>
        </a>
        <a class="btn btn-sm btn-outline-danger ms-1" href="logout.php">
          <i class="bi bi-box-arrow-right me-1"></i> Logout
        </a>
      </div>
      <?php else: ?>
      <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
        <li class="nav-item">
          <a class="nav-link <?=$currentPage === 'index.php' ? 'active' : ''?>" href="index.php">Login</a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?=$currentPage === 'register.php' ? 'active' : ''?>" href="register.php">Register</a>
        </li>
      </ul>
      <?php endif; ?>
    </div>
  </div>
</nav>
<div class="container py-4 flex-grow-1">
<?php
}

function footer_html() {
    ?>
</div>
<footer class="footer py-3 text-center small">
  <div class="container">
    <span>AI Resume Analyzer &amp; Semantic Job Matcher &copy; <?=date('Y')?> &bull; Built with PHP, Python FastAPI &amp; MySQL</span>
  </div>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
}
