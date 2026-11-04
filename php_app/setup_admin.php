<?php
/**
 * Administrator Provisioning Script
 * Usage via CLI:
 *   php php_app/setup_admin.php
 * Or via Web Browser during initial setup.
 */

require 'config.php';

$is_cli = (PHP_SAPI === 'cli');

// Check if any admin already exists
$admin_exists = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() > 0;

$u = env('ADMIN_USERNAME', 'admin');
$p = env('ADMIN_PASSWORD', '');

$success_msg = '';
$error_msg = '';

if ($is_cli) {
    if (!$p) {
        fwrite(STDERR, "Error: ADMIN_PASSWORD is empty in .env. Please set a password for the initial admin.\n");
        exit(1);
    }

    $hash = password_hash($p, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("
        INSERT INTO users (name, username, email, password_hash, role, must_change_password)
        VALUES (?, ?, ?, ?, 'admin', 1)
        ON DUPLICATE KEY UPDATE 
            password_hash = VALUES(password_hash), 
            must_change_password = 1
    ");
    $stmt->execute([$u, $u, $u . '@local.system', $hash]);
    
    echo "====================================================\n";
    echo "  SUCCESS: Admin user '{$u}' created or updated.\n";
    echo "  Role: Administrator\n";
    echo "  Must change password on first login: YES\n";
    echo "====================================================\n";
    exit(0);
}

// Web Browser Access
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted_key = $_POST['admin_key'] ?? '';
    $new_user      = trim($_POST['username'] ?? 'admin');
    $new_pass      = $_POST['password'] ?? '';

    // Verify against .env ADMIN_PASSWORD or allow if zero admins exist
    if ($admin_exists && ($p === '' || $submitted_key !== $p)) {
        $error_msg = 'An admin account already exists. To reset, supply the current ADMIN_PASSWORD from your .env file.';
    } elseif (strlen($new_pass) < 8) {
        $error_msg = 'Admin password must be at least 8 characters long.';
    } else {
        $hash = password_hash($new_pass, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("
            INSERT INTO users (name, username, email, password_hash, role, must_change_password)
            VALUES (?, ?, ?, ?, 'admin', 1)
            ON DUPLICATE KEY UPDATE 
                password_hash = VALUES(password_hash), 
                must_change_password = 1
        ");
        $stmt->execute([$new_user, $new_user, $new_user . '@local.system', $hash]);
        $success_msg = "Admin account '{$new_user}' created successfully! You can now log in at <a href='index.php'>index.php</a>.";
    }
}

header_html('Initialize Administrator');
?>
<div class="row justify-content-center align-items-center" style="min-height: 65vh;">
  <div class="col-md-6 col-lg-5">
    <div class="card p-4 shadow-sm border-0">
      <div class="text-center mb-4">
        <div class="brand-icon text-white mx-auto mb-2" style="width: 48px; height: 48px; font-size: 1.5rem; background: linear-gradient(135deg, #ef4444, #b91c1c);">
          <i class="bi bi-shield-lock"></i>
        </div>
        <h4 class="fw-bold mb-1">Admin Account Setup</h4>
        <p class="text-muted small">Initialize or reset the platform system administrator account</p>
      </div>

      <?php if ($success_msg): ?>
        <div class="alert alert-success small">
          <i class="bi bi-check-circle-fill me-1"></i> <?=$success_msg?>
        </div>
      <?php endif; ?>

      <?php if ($error_msg): ?>
        <div class="alert alert-danger small">
          <i class="bi bi-exclamation-triangle-fill me-1"></i> <?=h($error_msg)?>
        </div>
      <?php endif; ?>

      <?php if (!$success_msg): ?>
        <form method="post" autocomplete="off">
          <?php if ($admin_exists): ?>
            <div class="mb-3">
              <label class="form-label small fw-semibold text-secondary">Current .env ADMIN_PASSWORD</label>
              <input class="form-control" type="password" name="admin_key" placeholder="Enter ADMIN_PASSWORD from .env" required>
              <div class="form-text small">Required because an administrator account already exists.</div>
            </div>
          <?php endif; ?>

          <div class="mb-3">
            <label class="form-label small fw-semibold text-secondary">Admin Username</label>
            <input class="form-control" name="username" value="<?=h($u)?>" required>
          </div>

          <div class="mb-3">
            <label class="form-label small fw-semibold text-secondary">Initial Admin Password</label>
            <input class="form-control" type="password" name="password" placeholder="Min. 8 characters" required minlength="8">
            <div class="form-text small">Note: First login will require changing this password.</div>
          </div>

          <button type="submit" class="btn btn-danger w-100 py-2 fw-semibold">
            <i class="bi bi-person-check-fill me-1"></i> Create Admin Account
          </button>
        </form>
      <?php else: ?>
        <a href="index.php" class="btn btn-primary w-100 py-2 fw-semibold">Proceed to Login</a>
      <?php endif; ?>

      <div class="card mt-3 bg-light border-0 p-3 small text-muted">
        <strong>CLI Alternative:</strong>
        <code class="d-block mt-1 text-dark bg-white p-1 rounded border">php php_app/setup_admin.php</code>
      </div>
    </div>
  </div>
</div>
<?php
footer_html();
