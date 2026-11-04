<?php
require 'config.php';

// Redirect if already authenticated
if (!empty($_SESSION['uid'])) {
    if (!empty($_SESSION['must_change'])) {
        header('Location: change_password.php');
        exit;
    }
    header('Location: ' . (($_SESSION['role'] ?? '') === 'admin' ? 'admin.php' : 'student.php'));
    exit;
}

$err = '';
$username_val = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $username_val = trim($_POST['username'] ?? '');
    $password_val = $_POST['password'] ?? '';

    if ($username_val === '' || $password_val === '') {
        $err = 'Please enter both username and password.';
    } else {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1');
        $stmt->execute([$username_val, $username_val]);
        $user = $stmt->fetch();

        if ($user && password_verify($password_val, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION = [
                'uid'         => $user['id'],
                'name'        => $user['name'],
                'username'    => $user['username'],
                'role'        => $user['role'],
                'must_change' => (int)$user['must_change_password'],
                'csrf'        => bin2hex(random_bytes(16))
            ];

            if ($user['must_change_password']) {
                header('Location: change_password.php');
            } else {
                header('Location: ' . ($user['role'] === 'admin' ? 'admin.php' : 'student.php'));
            }
            exit;
        } else {
            $err = 'Invalid username or password. Please verify your credentials.';
        }
    }
}

header_html('Login');
?>
<div class="row justify-content-center align-items-center" style="min-height: 65vh;">
  <div class="col-md-5 col-lg-4">
    <div class="card p-4 shadow-sm border-0">
      <div class="text-center mb-4">
        <div class="brand-icon text-white mx-auto mb-2" style="width: 48px; height: 48px; font-size: 1.5rem;">
          <i class="bi bi-person-lock"></i>
        </div>
        <h4 class="fw-bold mb-1">Welcome Back</h4>
        <p class="text-muted small">Sign in to your AI Resume &amp; Job Matcher account</p>
      </div>

      <?php if ($err): ?>
        <div class="alert alert-danger py-2 small d-flex align-items-center">
          <i class="bi bi-exclamation-circle-fill me-2 fs-5"></i>
          <div><?=h($err)?></div>
        </div>
      <?php endif; ?>

      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?=csrf()?>">

        <div class="mb-3">
          <label class="form-label small fw-semibold text-secondary">Username or Email</label>
          <div class="input-group">
            <span class="input-group-text bg-light"><i class="bi bi-person"></i></span>
            <input class="form-control" name="username" value="<?=h($username_val)?>" placeholder="Username or email" required autofocus>
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label small fw-semibold text-secondary">Password</label>
          <div class="input-group">
            <span class="input-group-text bg-light"><i class="bi bi-shield-lock"></i></span>
            <input class="form-control" type="password" id="passwordInput" name="password" placeholder="Enter password" required>
            <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility()">
              <i class="bi bi-eye" id="toggleIcon"></i>
            </button>
          </div>
        </div>

        <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold">
          <i class="bi bi-box-arrow-in-right me-1"></i> Sign In
        </button>
      </form>

      <div class="text-center mt-3 pt-3 border-top small">
        <span class="text-muted">Don't have an account?</span> 
        <a href="register.php" class="text-decoration-none fw-semibold">Register as Student</a>
      </div>
    </div>

    <!-- Helpful initial setup box -->
    <div class="card mt-3 bg-light border-0 p-3 small text-muted">
      <div class="d-flex align-items-start">
        <i class="bi bi-info-circle-fill text-primary me-2 mt-1"></i>
        <div>
          <strong>Initial Setup Note:</strong> To initialize your administrator account, run:
          <code class="d-block mt-1 text-dark bg-white p-1 rounded border">php php_app/setup_admin.php</code>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
function togglePasswordVisibility() {
  const input = document.getElementById('passwordInput');
  const icon = document.getElementById('toggleIcon');
  if (input.type === 'password') {
    input.type = 'text';
    icon.classList.replace('bi-eye', 'bi-eye-slash');
  } else {
    input.type = 'password';
    icon.classList.replace('bi-eye-slash', 'bi-eye');
  }
}
</script>
<?php
footer_html();
