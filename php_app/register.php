<?php
require 'config.php';

// Redirect if already authenticated
if (!empty($_SESSION['uid'])) {
    header('Location: student.php');
    exit;
}

$msg = '';
$err = '';
$name_val = '';
$username_val = '';
$email_val = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $name_val     = trim($_POST['name'] ?? '');
    $username_val = trim($_POST['username'] ?? '');
    $email_val    = trim($_POST['email'] ?? '');
    $password_val = $_POST['password'] ?? '';
    $confirm_val  = $_POST['confirm_password'] ?? '';

    if (strlen($name_val) < 2) {
        $err = 'Please enter your full name.';
    } elseif (strlen($username_val) < 3 || !preg_match('/^[a-zA-Z0-9_.-]+$/', $username_val)) {
        $err = 'Username must be at least 3 characters and contain only letters, numbers, dots, or underscores.';
    } elseif (!filter_var($email_val, FILTER_VALIDATE_EMAIL)) {
        $err = 'Please provide a valid email address.';
    } elseif (strlen($password_val) < 8) {
        $err = 'Password must be at least 8 characters long.';
    } elseif ($password_val !== $confirm_val) {
        $err = 'Passwords do not match. Please re-enter.';
    } else {
        try {
            // Check for duplicate username or email
            $check = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
            $check->execute([$username_val, $email_val]);
            if ($check->fetch()) {
                $err = 'A user account with this username or email already exists.';
            } else {
                $hash = password_hash($password_val, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('INSERT INTO users (name, username, email, password_hash, role) VALUES (?, ?, ?, ?, "student")');
                $stmt->execute([$name_val, $username_val, $email_val, $hash]);

                // Auto-login newly registered student
                $new_id = $pdo->lastInsertId();
                session_regenerate_id(true);
                $_SESSION = [
                    'uid'         => $new_id,
                    'name'        => $name_val,
                    'username'    => $username_val,
                    'role'        => 'student',
                    'must_change' => 0,
                    'csrf'        => bin2hex(random_bytes(16))
                ];
                header('Location: student.php');
                exit;
            }
        } catch (Exception $e) {
            $err = 'Registration failed: ' . $e->getMessage();
        }
    }
}

header_html('Student Registration');
?>
<div class="row justify-content-center align-items-center" style="min-height: 70vh;">
  <div class="col-md-6 col-lg-5">
    <div class="card p-4 shadow-sm border-0">
      <div class="text-center mb-4">
        <div class="brand-icon text-white mx-auto mb-2" style="width: 48px; height: 48px; font-size: 1.5rem;">
          <i class="bi bi-mortarboard-fill"></i>
        </div>
        <h4 class="fw-bold mb-1">Create Student Account</h4>
        <p class="text-muted small">Analyze your resume, discover matching jobs &amp; bridge skill gaps</p>
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
          <label class="form-label small fw-semibold text-secondary">Full Name</label>
          <div class="input-group">
            <span class="input-group-text bg-light"><i class="bi bi-person-badge"></i></span>
            <input class="form-control" name="name" value="<?=h($name_val)?>" placeholder="e.g. John Doe" required autofocus>
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label small fw-semibold text-secondary">Username</label>
          <div class="input-group">
            <span class="input-group-text bg-light"><i class="bi bi-at"></i></span>
            <input class="form-control" name="username" value="<?=h($username_val)?>" placeholder="Choose a username" required>
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label small fw-semibold text-secondary">Email Address</label>
          <div class="input-group">
            <span class="input-group-text bg-light"><i class="bi bi-envelope"></i></span>
            <input class="form-control" type="email" name="email" value="<?=h($email_val)?>" placeholder="name@example.com" required>
          </div>
        </div>

        <div class="row g-2 mb-3">
          <div class="col-md-6">
            <label class="form-label small fw-semibold text-secondary">Password</label>
            <input class="form-control" type="password" name="password" placeholder="Min. 8 characters" required minlength="8">
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold text-secondary">Confirm Password</label>
            <input class="form-control" type="password" name="confirm_password" placeholder="Re-enter password" required minlength="8">
          </div>
        </div>

        <button type="submit" class="btn btn-success w-100 py-2 fw-semibold">
          <i class="bi bi-check-circle me-1"></i> Create Student Account
        </button>
      </form>

      <div class="text-center mt-3 pt-3 border-top small">
        <span class="text-muted">Already registered?</span> 
        <a href="index.php" class="text-decoration-none fw-semibold">Sign in here</a>
      </div>
    </div>
  </div>
</div>
<?php
footer_html();
