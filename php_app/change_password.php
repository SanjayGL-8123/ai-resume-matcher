<?php
require 'config.php';
require_role(); // Must be logged in

$uid = (int)$_SESSION['uid'];
$must_change = !empty($_SESSION['must_change']);
$err = '';
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $current_pass = $_POST['current_password'] ?? '';
    $new_pass     = $_POST['new_password'] ?? '';
    $confirm_pass = $_POST['confirm_password'] ?? '';

    // If voluntary password change (must_change is 0), verify current password
    if (!$must_change) {
        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$uid]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($current_pass, $row['password_hash'])) {
            $err = 'Current password is incorrect.';
        }
    }

    if (!$err) {
        if (strlen($new_pass) < 8) {
            $err = 'New password must be at least 8 characters long.';
        } elseif ($new_pass !== $confirm_pass) {
            $err = 'New passwords do not match. Please re-enter.';
        } else {
            $new_hash = password_hash($new_pass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?");
            $stmt->execute([$new_hash, $uid]);

            $_SESSION['must_change'] = 0;
            log_admin_action($pdo, $uid, 'Password Changed', 'User updated their account password.');

            $redirect_target = ($_SESSION['role'] ?? '') === 'admin' ? 'admin.php' : 'student.php';
            header("Location: {$redirect_target}?msg=password_updated");
            exit;
        }
    }
}

header_html('Change Password');
?>
<div class="row justify-content-center align-items-center" style="min-height: 65vh;">
  <div class="col-md-5 col-lg-4">
    <div class="card p-4 shadow-sm border-0">
      <div class="text-center mb-3">
        <div class="brand-icon text-white mx-auto mb-2" style="width: 48px; height: 48px; font-size: 1.5rem; background: linear-gradient(135deg, #f59e0b, #d97706);">
          <i class="bi bi-shield-lock-fill"></i>
        </div>
        <h4 class="fw-bold mb-1"><?=$must_change ? 'Update Temporary Password' : 'Change Password'?></h4>
        <p class="text-muted small">
          <?=$must_change ? 'First-time login: Please establish a secure permanent password.' : 'Update your account login password.'?>
        </p>
      </div>

      <?php if ($must_change): ?>
        <div class="alert alert-warning py-2 small mb-3">
          <i class="bi bi-exclamation-triangle-fill me-1"></i> You must choose a new password before proceeding to the platform.
        </div>
      <?php endif; ?>

      <?php if ($err): ?>
        <div class="alert alert-danger py-2 small d-flex align-items-center mb-3">
          <i class="bi bi-exclamation-circle-fill me-2 fs-5"></i>
          <div><?=h($err)?></div>
        </div>
      <?php endif; ?>

      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?=csrf()?>">

        <?php if (!$must_change): ?>
          <div class="mb-3">
            <label class="form-label small fw-semibold text-secondary">Current Password</label>
            <input class="form-control" type="password" name="current_password" required placeholder="Enter current password">
          </div>
        <?php endif; ?>

        <div class="mb-3">
          <label class="form-label small fw-semibold text-secondary">New Password</label>
          <input class="form-control" type="password" name="new_password" required minlength="8" placeholder="Minimum 8 characters">
        </div>

        <div class="mb-3">
          <label class="form-label small fw-semibold text-secondary">Confirm New Password</label>
          <input class="form-control" type="password" name="confirm_password" required minlength="8" placeholder="Re-type new password">
        </div>

        <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold">
          <i class="bi bi-check-circle me-1"></i> Save New Password
        </button>

        <?php if (!$must_change): ?>
          <div class="text-center mt-3">
            <a href="<?=$_SESSION['role'] === 'admin' ? 'admin.php' : 'student.php'?>" class="text-decoration-none small text-muted">
              Cancel &amp; return to dashboard
            </a>
          </div>
        <?php endif; ?>
      </form>
    </div>
  </div>
</div>
<?php
footer_html();
