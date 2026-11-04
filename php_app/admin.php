<?php
require 'config.php';
require_role('admin');

$admin_id = (int)$_SESSION['uid'];
$msg = '';
$err = '';

// Handle On-Demand Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'trigger_collector') {
        // Ping Python AI service to run collector
        [$res, $curl_err] = ai_post('/collect-jobs', null, 10);

        if ($curl_err) {
            $err = 'Could not reach AI Microservice: ' . $curl_err;
        } else {
            log_admin_action($pdo, $admin_id, 'Triggered Job Collection Pipeline', 'Background fetch initiated.');
            $msg = 'Job collector triggered in the background. Job sources will update in moments.';
        }
    } elseif ($action === 'match_all') {
        // Trigger batch re-matching
        [$res, $curl_err] = ai_post('/match-all', null, 30);

        if ($curl_err) {
            $err = 'Could not reach AI Microservice: ' . $curl_err;
        } else {
            $data = json_decode($res, true);
            $count = $data['processed_resumes'] ?? 0;
            log_admin_action($pdo, $admin_id, 'Re-matched All Resumes', "Processed {$count} resumes.");
            $msg = "Batch re-matching completed for {$count} student resume(s).";
        }
    } elseif ($action === 'toggle_job_status') {
        $jid = (int)($_POST['job_id'] ?? 0);
        $new_status = (int)($_POST['new_status'] ?? 0);
        if ($jid > 0) {
            $stmt = $pdo->prepare("UPDATE job_vacancies SET is_active = ? WHERE id = ?");
            $stmt->execute([$new_status, $jid]);
            log_admin_action($pdo, $admin_id, 'Toggled Job Status', "Job ID: {$jid} set to active={$new_status}");
            $msg = "Job ID #{$jid} status updated.";
        }
    }
}

// Key Platform Metrics
$query_val = fn($q) => (int)$pdo->query($q)->fetchColumn();
$students_count   = $query_val("SELECT COUNT(*) FROM users WHERE role = 'student'");
$resumes_count    = $query_val("SELECT COUNT(*) FROM resumes");
$total_jobs       = $query_val("SELECT COUNT(*) FROM job_vacancies");
$active_jobs      = $query_val("SELECT COUNT(*) FROM job_vacancies WHERE is_active = 1");
$apps_count       = $query_val("SELECT COUNT(*) FROM applications");
$avg_ats          = (float)$pdo->query("SELECT IFNULL(AVG(ats_score), 0) FROM resume_analysis")->fetchColumn();

// Fetch Job Sources
$sources = $pdo->query("SELECT * FROM job_sources ORDER BY id ASC")->fetchAll();

// Top In-Demand Skills Calculation
$skill_counts = [];
foreach ($pdo->query("SELECT skills_json FROM job_vacancies WHERE is_active = 1") as $row) {
    $skills = json_decode($row['skills_json'] ?? '[]', true) ?: [];
    foreach ($skills as $s) {
        $skill_counts[$s] = ($skill_counts[$s] ?? 0) + 1;
    }
}
arsort($skill_counts);
$top_skills = array_slice($skill_counts, 0, 15, true);

// Student Directory with Latest ATS & Match Stats
$students_query = "
    SELECT 
        u.id, u.name, u.username, u.email, u.created_at,
        (SELECT COUNT(*) FROM resumes WHERE user_id = u.id) AS resume_count,
        (SELECT a.ats_score FROM resume_analysis a JOIN resumes r ON r.id = a.resume_id WHERE r.user_id = u.id ORDER BY a.id DESC LIMIT 1) AS latest_ats,
        (SELECT CONCAT(j.company, ' &bull; ', j.title, ' (', m.score, '%)') 
         FROM job_matches m 
         JOIN resumes r ON r.id = m.resume_id 
         JOIN job_vacancies j ON j.id = m.job_id 
         WHERE r.user_id = u.id ORDER BY m.score DESC LIMIT 1) AS best_match,
        (SELECT COUNT(*) FROM applications WHERE user_id = u.id) AS app_count
    FROM users u
    WHERE u.role = 'student'
    ORDER BY u.id DESC
";
$students = $pdo->query($students_query)->fetchAll();

// Recent Admin Activity Logs
$logs = $pdo->query("
    SELECT l.*, u.name AS admin_name 
    FROM admin_activity_logs l 
    LEFT JOIN users u ON u.id = l.admin_id 
    ORDER BY l.id DESC LIMIT 10
")->fetchAll();

header_html('Admin Dashboard');
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h4 class="fw-bold mb-1 text-dark"><i class="bi bi-speedometer2 text-primary me-2"></i>Admin Dashboard</h4>
    <p class="text-muted small mb-0">Platform overview, student analytics, and automated job matching operations</p>
  </div>
  <div class="d-flex gap-2">
    <!-- Trigger Job Collector -->
    <form method="post" class="d-inline">
      <input type="hidden" name="csrf" value="<?=csrf()?>">
      <input type="hidden" name="action" value="trigger_collector">
      <button class="btn btn-sm btn-outline-primary" type="submit">
        <i class="bi bi-cloud-arrow-down me-1"></i> Sync Job Sources
      </button>
    </form>
    <!-- Batch Re-match All -->
    <form method="post" class="d-inline">
      <input type="hidden" name="csrf" value="<?=csrf()?>">
      <input type="hidden" name="action" value="match_all">
      <button class="btn btn-sm btn-primary" type="submit">
        <i class="bi bi-arrow-repeat me-1"></i> Re-match All Resumes
      </button>
    </form>
  </div>
</div>

<?php if ($msg): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="bi bi-check-circle-fill me-2"></i> <?=h($msg)?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>

<?php if ($err): ?>
  <div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-2"></i> <?=h($err)?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>

<!-- Analytics Overview Row -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-lg-2">
    <div class="card p-3 shadow-sm border-0 stat-card">
      <div class="small text-muted fw-semibold">Students</div>
      <div class="display-6 fw-bold text-dark"><?=$students_count?></div>
      <small class="text-secondary"><i class="bi bi-people me-1"></i>Registered</small>
    </div>
  </div>

  <div class="col-sm-6 col-lg-2">
    <div class="card p-3 shadow-sm border-0 stat-card">
      <div class="small text-muted fw-semibold">Resumes</div>
      <div class="display-6 fw-bold text-dark"><?=$resumes_count?></div>
      <small class="text-secondary"><i class="bi bi-file-earmark-text me-1"></i>Uploaded</small>
    </div>
  </div>

  <div class="col-sm-6 col-lg-2">
    <div class="card p-3 shadow-sm border-0 stat-card">
      <div class="small text-muted fw-semibold">Active Jobs</div>
      <div class="display-6 fw-bold text-primary"><?=$active_jobs?></div>
      <small class="text-secondary">of <?=$total_jobs?> Total</small>
    </div>
  </div>

  <div class="col-sm-6 col-lg-2">
    <div class="card p-3 shadow-sm border-0 stat-card">
      <div class="small text-muted fw-semibold">Applications</div>
      <div class="display-6 fw-bold text-dark"><?=$apps_count?></div>
      <small class="text-secondary"><i class="bi bi-send me-1"></i>Tracked</small>
    </div>
  </div>

  <div class="col-sm-6 col-lg-4">
    <div class="card p-3 shadow-sm border-0 stat-card">
      <div class="small text-muted fw-semibold">Average ATS Score</div>
      <div class="d-flex align-items-baseline gap-2">
        <div class="display-6 fw-bold text-success"><?=round($avg_ats, 1)?></div>
        <span class="text-muted">/ 100</span>
      </div>
      <small class="text-secondary">Across all student resume analyses</small>
    </div>
  </div>
</div>

<!-- Middle Section: Top In-Demand Skills & Job Source Status -->
<div class="row g-3 mb-4">
  <div class="col-lg-6">
    <div class="card p-3 shadow-sm border-0 h-100">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-bar-chart-line-fill text-primary me-2"></i>Most Demanded Skills in Active Jobs</h6>
        <span class="badge bg-light text-dark border">Market Intelligence</span>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <?php if (!empty($top_skills)): ?>
          <?php foreach ($top_skills as $sk => $cnt): ?>
            <span class="badge bg-dark px-3 py-2 fs-6">
              <?=h($sk)?> <span class="badge bg-primary text-white ms-1"><?=$cnt?></span>
            </span>
          <?php endforeach; ?>
        <?php else: ?>
          <p class="text-muted small">No active jobs found. Run the job collector to index skills.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card p-3 shadow-sm border-0 h-100">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-hdd-network-fill text-primary me-2"></i>Job Sources Status</h6>
        <span class="badge bg-light text-dark border"><?=count($sources)?> Sources</span>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
          <thead class="table-light">
            <tr>
              <th>Source</th>
              <th>Status</th>
              <th>Last Run</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($sources as $s): ?>
              <tr>
                <td class="fw-semibold text-capitalize"><?=h($s['name'])?></td>
                <td class="small text-secondary"><?=h($s['last_status'] ?: 'Pending')?></td>
                <td class="small text-muted"><?=$s['last_run'] ? date('M d, H:i', strtotime($s['last_run'])) : 'Never'?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Students Directory & Analytics Table -->
<div class="card shadow-sm border-0 mb-4">
  <div class="card-header bg-transparent py-3 border-bottom d-flex justify-content-between align-items-center">
    <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-people-fill text-primary me-2"></i>Registered Students &amp; Performance</h5>
    <span class="badge bg-light text-dark border"><?=count($students)?> Students</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Student</th>
            <th>Email</th>
            <th>Resumes</th>
            <th>Latest ATS</th>
            <th>Best Job Match</th>
            <th>Applications</th>
            <th>Joined</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($students)): ?>
            <tr>
              <td colspan="7" class="text-center py-4 text-muted">No students registered yet.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($students as $stu): 
                $ats = $stu['latest_ats'] !== null ? (int)$stu['latest_ats'] : null;
                $ats_badge = $ats !== null ? ($ats >= 80 ? 'bg-success' : ($ats >= 65 ? 'bg-primary' : ($ats >= 50 ? 'bg-warning text-dark' : 'bg-danger'))) : 'bg-secondary';
            ?>
              <tr>
                <td>
                  <strong><?=h($stu['name'])?></strong>
                  <div class="small text-muted">@<?=h($stu['username'])?></div>
                </td>
                <td class="small text-secondary"><?=h($stu['email'] ?: 'None')?></td>
                <td>
                  <span class="badge bg-light text-dark border"><?=$stu['resume_count']?></span>
                </td>
                <td>
                  <?php if ($ats !== null): ?>
                    <span class="badge <?=$ats_badge?> px-2 py-1"><?=$ats?> / 100</span>
                  <?php else: ?>
                    <span class="text-muted small">Not Analyzed</span>
                  <?php endif; ?>
                </td>
                <td class="small text-truncate" style="max-width: 250px;">
                  <?=$stu['best_match'] ? $stu['best_match'] : '<span class="text-muted">-</span>'?>
                </td>
                <td>
                  <span class="badge bg-info-subtle text-info border border-info-subtle"><?=$stu['app_count']?></span>
                </td>
                <td class="small text-muted"><?=date('M d, Y', strtotime($stu['created_at']))?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Admin Activity Logs -->
<div class="card shadow-sm border-0 mb-4">
  <div class="card-header bg-transparent py-3 border-bottom">
    <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-journal-text text-primary me-2"></i>Recent System Activity Logs</h6>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0">
        <thead class="table-light">
          <tr>
            <th>Time</th>
            <th>Admin</th>
            <th>Action</th>
            <th>Details</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($logs)): ?>
            <tr>
              <td colspan="4" class="text-center py-3 text-muted small">No activity logs recorded yet.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($logs as $l): ?>
              <tr>
                <td class="small text-muted"><?=date('M d, Y H:i:s', strtotime($l['created_at']))?></td>
                <td class="small fw-semibold"><?=h($l['admin_name'] ?: 'System')?></td>
                <td class="small"><?=h($l['action'])?></td>
                <td class="small text-secondary"><?=h($l['details'] ?: '-')?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php
footer_html();
