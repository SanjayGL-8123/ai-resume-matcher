<?php
require 'config.php';
require_role(); // Any authenticated user (student or admin) can use the student portal

$uid = (int)$_SESSION['uid'];
$err = '';
$success = '';

// Handle Application Status Updates (Save, Applied, Interview, Offer, Rejected)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_application') {
    check_csrf();
    $job_id = (int)($_POST['job_id'] ?? 0);
    $status = $_POST['status'] ?? 'saved';
    $valid_statuses = ['saved', 'applied', 'interview', 'rejected', 'offer'];

    if ($job_id > 0 && in_array($status, $valid_statuses, true)) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO applications (user_id, job_id, status)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE status = VALUES(status), updated_at = NOW()
            ");
            $stmt->execute([$uid, $job_id, $status]);
            $success = "Application status updated to '" . ucfirst($status) . "'.";
        } catch (Exception $e) {
            $err = "Could not update application status: " . $e->getMessage();
        }
    }
}

// Handle Resume File Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['resume'])) {
    check_csrf();
    $f = $_FILES['resume'];
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));

    if ($f['error'] !== UPLOAD_ERR_OK) {
        $err = 'File upload error occurred. Please try again.';
    } elseif (!in_array($ext, ['pdf', 'docx'], true)) {
        $err = 'Invalid file type. Please upload a PDF (.pdf) or Word document (.docx).';
    } elseif ($f['size'] > 6 * 1024 * 1024) {
        $err = 'File size exceeds maximum allowed limit (6 MB).';
    } else {
        // Send file to Python AI microservice
        $cfile = new CURLFile($f['tmp_name'], mime_content_type($f['tmp_name']) ?: 'application/octet-stream', 'resume.' . $ext);
        [$response_raw, $curl_err] = ai_post('/analyze', ['file' => $cfile], 90);

        if ($curl_err || !$response_raw) {
            $err = 'AI Microservice is not reachable. Ensure FastAPI is running on port 8000 (<code>uvicorn app:app --port 8000</code> in python_ai directory).';
        } else {
            $res = json_decode($response_raw, true);
            if (!$res || isset($res['detail'])) {
                $err = 'AI Analysis failed: ' . ($res['detail'] ?? 'Invalid response from parser.');
            } else {
                // Save Resume
                $stmt = $pdo->prepare('INSERT INTO resumes (user_id, file_name, text_content) VALUES (?, ?, ?)');
                $stmt->execute([$uid, basename($f['name']), $res['text']]);
                $rid = (int)$pdo->lastInsertId();

                // Save Analysis
                $feedback = [
                    'missing'   => $res['missing_sections'] ?? [],
                    'tips'      => $res['suggestions'] ?? [],
                    'breakdown' => $res['breakdown'] ?? [],
                    'words'     => $res['word_count'] ?? 0,
                    'gemini'    => $res['gemini_insights'] ?? []
                ];

                $stmt = $pdo->prepare('INSERT INTO resume_analysis (resume_id, ats_score, skills_json, feedback_json) VALUES (?, ?, ?, ?)');
                $stmt->execute([$rid, $res['ats_score'], json_encode($res['skills'] ?? []), json_encode($feedback)]);

                // Trigger Semantic Job Matcher
                ai_post('/match/' . $rid, null, 90);

                header('Location: student.php?rid=' . $rid . '&msg=uploaded');
                exit;
            }
        }
    }
}

// Fetch all resumes uploaded by this user for the switcher
$stmt_resumes = $pdo->prepare("SELECT id, file_name, uploaded_at FROM resumes WHERE user_id = ? ORDER BY id DESC");
$stmt_resumes->execute([$uid]);
$user_resumes = $stmt_resumes->fetchAll();

// Determine which resume to view
$selected_rid = (int)($_GET['rid'] ?? 0);
if ($selected_rid <= 0 && !empty($user_resumes)) {
    $selected_rid = (int)$user_resumes[0]['id'];
}

$analysis = null;
if ($selected_rid > 0) {
    $stmt_analysis = $pdo->prepare("
        SELECT a.*, r.id as rid, r.file_name, r.uploaded_at
        FROM resume_analysis a
        JOIN resumes r ON r.id = a.resume_id
        WHERE r.id = ? AND r.user_id = ?
        LIMIT 1
    ");
    $stmt_analysis->execute([$selected_rid, $uid]);
    $analysis = $stmt_analysis->fetch();
}

// Fetch current user applications map (job_id => status)
$apps_stmt = $pdo->prepare("SELECT job_id, status FROM applications WHERE user_id = ?");
$apps_stmt->execute([$uid]);
$user_applications = [];
foreach ($apps_stmt->fetchAll() as $app_row) {
    $user_applications[(int)$app_row['job_id']] = $app_row['status'];
}

// Active Tab ('matches' or 'applications')
$active_tab = $_GET['tab'] ?? 'matches';

// Filter parameters for job matching
$search_query = trim($_GET['q'] ?? '');
$score_filter = (int)($_GET['min_score'] ?? 0);
$remote_filter = isset($_GET['remote']) ? 1 : 0;

header_html('Student Dashboard');
?>

<?php if (isset($_GET['msg']) && $_GET['msg'] === 'uploaded'): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="bi bi-check-circle-fill me-2"></i> Resume uploaded and analyzed successfully! View your ATS breakdown and job matches below.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>

<?php if ($success): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="bi bi-check-circle-fill me-2"></i> <?=h($success)?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>

<?php if ($err): ?>
  <div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-2"></i> <?=$err?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>

<!-- Top Action Header: Upload Resume & Switcher -->
<div class="row g-3 mb-4">
  <div class="col-lg-8">
    <div class="card p-3 shadow-sm border-0 h-100">
      <h6 class="fw-bold text-secondary mb-2"><i class="bi bi-cloud-arrow-up-fill text-primary me-2"></i>Upload New Resume</h6>
      <form method="post" enctype="multipart/form-data" class="row g-2 align-items-center">
        <input type="hidden" name="csrf" value="<?=csrf()?>">
        <div class="col-md-8">
          <input class="form-control" type="file" name="resume" accept=".pdf,.docx" required>
          <div class="form-text small">Accepted formats: <strong>.PDF, .DOCX</strong> (Max file size: 6 MB)</div>
        </div>
        <div class="col-md-4">
          <button class="btn btn-primary w-100 py-2 fw-semibold">
            <i class="bi bi-magic me-1"></i> Analyze &amp; Match
          </button>
        </div>
      </form>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card p-3 shadow-sm border-0 h-100">
      <h6 class="fw-bold text-secondary mb-2"><i class="bi bi-clock-history text-secondary me-2"></i>Resume Versions (<?=count($user_resumes)?>)</h6>
      <?php if (!empty($user_resumes)): ?>
        <form method="get" class="d-flex flex-column gap-2">
          <select name="rid" class="form-select form-select-sm" onchange="this.form.submit()">
            <?php foreach ($user_resumes as $ur): ?>
              <option value="<?=$ur['id']?>" <?=$ur['id'] == $selected_rid ? 'selected' : ''?>>
                <?=h($ur['file_name'])?> (<?=date('M d, Y', strtotime($ur['uploaded_at']))?>)
              </option>
            <?php endforeach; ?>
          </select>
          <small class="text-muted">Select an uploaded resume to inspect its detailed evaluation.</small>
        </form>
      <?php else: ?>
        <p class="text-muted small mb-0">No resumes uploaded yet. Upload your first resume to get started!</p>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($analysis): 
    $fb = json_decode($analysis['feedback_json'] ?? '{}', true) ?: [];
    $skills = json_decode($analysis['skills_json'] ?? '[]', true) ?: [];
    $breakdown = $fb['breakdown'] ?? ['contact' => 0, 'sections' => 0, 'skills' => 0, 'impact' => 0, 'format' => 0];
    $score = (int)$analysis['ats_score'];
    $score_bg = $score >= 80 ? '#10b981' : ($score >= 65 ? '#2563eb' : ($score >= 50 ? '#f59e0b' : '#ef4444'));
?>

<!-- Resume Analysis Report -->
<div class="card shadow-sm border-0 mb-4 overflow-hidden">
  <div class="card-header bg-white py-3 border-bottom">
    <div class="d-flex flex-wrap justify-content-between align-items-center">
      <div>
        <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-file-earmark-check-fill text-primary me-2"></i>ATS Analysis: <?=h($analysis['file_name'])?></h5>
        <small class="text-muted">Analyzed on <?=date('F d, Y &bull; h:i A', strtotime($analysis['uploaded_at']))?></small>
      </div>
      <div class="mt-2 mt-md-0">
        <span class="badge bg-light text-dark border px-3 py-2 fs-6">
          <i class="bi bi-check2-circle text-success me-1"></i> <?=count($skills)?> Skills Detected
        </span>
      </div>
    </div>
  </div>

  <div class="card-body p-4">
    <div class="row g-4">
      <!-- Circular ATS Score Gauge -->
      <div class="col-lg-3 text-center d-flex flex-column align-items-center justify-content-center border-end">
        <div class="score-circle mb-3" style="background: <?=$score_bg?>;">
          <span style="font-size: 2.2rem; line-height: 1;"><?=$score?></span>
          <span style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 1px;">out of 100</span>
        </div>
        <h6 class="fw-bold mb-1">
          <?=$score >= 80 ? 'Excellent Match' : ($score >= 65 ? 'Competitive Profile' : ($score >= 50 ? 'Needs Improvement' : 'Action Required'))?>
        </h6>
        <p class="text-muted small px-2">Estimated ATS-readiness for automated enterprise job screeners.</p>
      </div>

      <!-- Dimension Breakdown Meters -->
      <div class="col-lg-5">
        <h6 class="fw-bold text-secondary mb-3"><i class="bi bi-bar-chart-fill text-primary me-2"></i>Score Breakdown</h6>
        
        <div class="mb-2">
          <div class="d-flex justify-content-between small fw-semibold">
            <span>Contact Information</span>
            <span><?=$breakdown['contact']?> / 15</span>
          </div>
          <div class="progress" style="height: 6px;">
            <div class="progress-bar bg-info" style="width: <?=(($breakdown['contact'] / 15) * 100)?>%"></div>
          </div>
        </div>

        <div class="mb-2">
          <div class="d-flex justify-content-between small fw-semibold">
            <span>Essential Resume Sections</span>
            <span><?=$breakdown['sections']?> / 35</span>
          </div>
          <div class="progress" style="height: 6px;">
            <div class="progress-bar bg-primary" style="width: <?=(($breakdown['sections'] / 35) * 100)?>%"></div>
          </div>
        </div>

        <div class="mb-2">
          <div class="d-flex justify-content-between small fw-semibold">
            <span>Technical Skill Density</span>
            <span><?=$breakdown['skills']?> / 25</span>
          </div>
          <div class="progress" style="height: 6px;">
            <div class="progress-bar bg-success" style="width: <?=(($breakdown['skills'] / 25) * 100)?>%"></div>
          </div>
        </div>

        <div class="mb-2">
          <div class="d-flex justify-content-between small fw-semibold">
            <span>Action Verbs &amp; Quantified Impact</span>
            <span><?=$breakdown['impact']?> / 15</span>
          </div>
          <div class="progress" style="height: 6px;">
            <div class="progress-bar bg-warning" style="width: <?=(($breakdown['impact'] / 15) * 100)?>%"></div>
          </div>
        </div>

        <div class="mb-2">
          <div class="d-flex justify-content-between small fw-semibold">
            <span>Length &amp; Formatting</span>
            <span><?=$breakdown['format']?> / 10</span>
          </div>
          <div class="progress" style="height: 6px;">
            <div class="progress-bar bg-secondary" style="width: <?=(($breakdown['format'] / 10) * 100)?>%"></div>
          </div>
        </div>
      </div>

      <!-- Detected Skills Badge Cloud -->
      <div class="col-lg-4">
        <h6 class="fw-bold text-secondary mb-2"><i class="bi bi-tags-fill text-primary me-2"></i>Detected Skills (<?=count($skills)?>)</h6>
        <div class="p-3 bg-light rounded" style="max-height: 180px; overflow-y: auto;">
          <?php if (!empty($skills)): ?>
            <?php foreach ($skills as $s): ?>
              <span class="badge bg-white text-dark border me-1 mb-1 shadow-sm"><?=h($s)?></span>
            <?php endforeach; ?>
          <?php else: ?>
            <p class="text-muted small mb-0">No technical skills detected yet. Include standard industry skill keywords.</p>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php if (!empty($fb['gemini']['summary'])): ?>
      <!-- Google Gemini AI Executive Summary -->
      <div class="card border-0 shadow-sm p-3 mt-3" style="background: linear-gradient(135deg, #f0fdf4 0%, #eff6ff 100%); border-left: 4px solid #3b82f6 !important;">
        <div class="d-flex align-items-center mb-2">
          <i class="bi bi-stars text-primary fs-5 me-2"></i>
          <h6 class="fw-bold mb-0 text-dark">Google Gemini AI &bull; Executive Profile &amp; Strengths</h6>
        </div>
        <p class="small text-secondary mb-2"><?=h($fb['gemini']['summary'])?></p>
        <?php if (!empty($fb['gemini']['strengths'])): ?>
          <div class="d-flex flex-wrap gap-1">
            <?php foreach ($fb['gemini']['strengths'] as $st): ?>
              <span class="badge bg-white text-dark border shadow-sm"><i class="bi bi-check-lg text-success me-1"></i><?=h($st)?></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <!-- Recommendations & Missing Sections Checklist -->
    <div class="row g-3 mt-2">
      <?php if (!empty($fb['missing'])): ?>
        <div class="col-md-4">
          <div class="alert alert-warning border-0 shadow-sm p-3 h-100 mb-0">
            <h6 class="fw-bold text-warning-emphasis mb-2"><i class="bi bi-exclamation-triangle-fill me-1"></i> Missing Key Sections:</h6>
            <ul class="mb-0 ps-3 small">
              <?php foreach ($fb['missing'] as $miss_sec): ?>
                <li class="text-capitalize"><?=h($miss_sec)?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
      <?php endif; ?>

      <div class="<?=!empty($fb['missing']) ? 'col-md-8' : 'col-12'?>">
        <div class="card bg-light border-0 p-3 h-100">
          <h6 class="fw-bold text-dark mb-2"><i class="bi bi-lightbulb-fill text-warning me-1"></i> Actionable ATS Improvement Suggestions</h6>
          <ul class="mb-0 ps-3 small text-secondary">
            <?php foreach (($fb['tips'] ?? []) as $tip): ?>
              <li class="mb-1"><?=h($tip)?></li>
            <?php endforeach; ?>
            <?php if (empty($fb['tips'])): ?>
              <li class="text-success">Great job! Your resume meets all primary automated screening benchmarks.</li>
            <?php endif; ?>
          </ul>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Tabs Navigation: Matching Jobs vs My Applications -->
<ul class="nav nav-pills mb-3 border-bottom pb-2">
  <li class="nav-item">
    <a class="nav-link <?=$active_tab === 'matches' ? 'active' : ''?>" href="?rid=<?=$selected_rid?>&tab=matches">
      <i class="bi bi-briefcase-fill me-1"></i> Matching Jobs
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?=$active_tab === 'applications' ? 'active' : ''?>" href="?rid=<?=$selected_rid?>&tab=applications">
      <i class="bi bi-bookmark-check-fill me-1"></i> My Application Tracker (<?=count($user_applications)?>)
    </a>
  </li>
</ul>

<?php if ($active_tab === 'applications'): ?>
  <!-- Application Tracker Tab -->
  <div class="card shadow-sm border-0 p-4">
    <h5 class="fw-bold text-dark mb-3"><i class="bi bi-kanban me-2 text-primary"></i>Tracked Applications</h5>
    <?php
    $app_list_stmt = $pdo->prepare("
        SELECT a.status, a.updated_at, j.*
        FROM applications a
        JOIN job_vacancies j ON j.id = a.job_id
        WHERE a.user_id = ?
        ORDER BY a.updated_at DESC
    ");
    $app_list_stmt->execute([$uid]);
    $tracked_jobs = $app_list_stmt->fetchAll();
    ?>

    <?php if (empty($tracked_jobs)): ?>
      <div class="text-center py-5 text-muted">
        <i class="bi bi-clipboard-x display-4 text-secondary mb-2"></i>
        <h6>No tracked applications yet.</h6>
        <p class="small">Browse the <strong>Matching Jobs</strong> tab and click "Save Job" or "Mark Applied" to organize your job search.</p>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead class="table-light">
            <tr>
              <th>Job Title</th>
              <th>Company &amp; Location</th>
              <th>Status</th>
              <th>Last Updated</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($tracked_jobs as $tj): 
                $status_class = [
                    'saved'     => 'bg-secondary',
                    'applied'   => 'bg-primary',
                    'interview' => 'bg-warning text-dark',
                    'offer'     => 'bg-success',
                    'rejected'  => 'bg-danger'
                ][$tj['status']] ?? 'bg-secondary';
            ?>
              <tr>
                <td>
                  <strong><?=h($tj['title'])?></strong>
                  <?php if ($tj['is_remote']): ?>
                    <span class="badge bg-info-subtle text-info ms-1">Remote</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?=h($tj['company'])?>
                  <div class="small text-muted"><?=h($tj['location'])?></div>
                </td>
                <td>
                  <span class="badge <?=$status_class?> px-2 py-1 text-capitalize"><?=h($tj['status'])?></span>
                </td>
                <td class="small text-muted"><?=date('M d, Y', strtotime($tj['updated_at']))?></td>
                <td>
                  <div class="d-flex gap-2">
                    <a href="<?=h($tj['apply_url'])?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary">
                      <i class="bi bi-box-arrow-up-right me-1"></i> View / Apply
                    </a>
                    <!-- Quick update status dropdown -->
                    <form method="post" class="d-inline">
                      <input type="hidden" name="csrf" value="<?=csrf()?>">
                      <input type="hidden" name="action" value="update_application">
                      <input type="hidden" name="job_id" value="<?=$tj['id']?>">
                      <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="saved" <?=$tj['status'] === 'saved' ? 'selected' : ''?>>Saved</option>
                        <option value="applied" <?=$tj['status'] === 'applied' ? 'selected' : ''?>>Applied</option>
                        <option value="interview" <?=$tj['status'] === 'interview' ? 'selected' : ''?>>Interview</option>
                        <option value="offer" <?=$tj['status'] === 'offer' ? 'selected' : ''?>>Offer</option>
                        <option value="rejected" <?=$tj['status'] === 'rejected' ? 'selected' : ''?>>Rejected</option>
                      </select>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

<?php else: ?>
  <!-- Job Matches Tab -->
  <!-- Search & Filter Controls -->
  <div class="card p-3 shadow-sm border-0 mb-3">
    <form method="get" class="row g-2 align-items-center">
      <input type="hidden" name="rid" value="<?=$selected_rid?>">
      <input type="hidden" name="tab" value="matches">

      <div class="col-md-5">
        <div class="input-group input-group-sm">
          <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
          <input type="text" name="q" class="form-control" placeholder="Search title, company, or keyword..." value="<?=h($search_query)?>">
        </div>
      </div>

      <div class="col-md-3">
        <select name="min_score" class="form-select form-select-sm">
          <option value="0" <?=$score_filter === 0 ? 'selected' : ''?>>All Matches (Any Score)</option>
          <option value="75" <?=$score_filter === 75 ? 'selected' : ''?>>Strong Matches (75%+)</option>
          <option value="60" <?=$score_filter === 60 ? 'selected' : ''?>>Good Matches (60%+)</option>
          <option value="45" <?=$score_filter === 45 ? 'selected' : ''?>>Partial Matches (45%+)</option>
        </select>
      </div>

      <div class="col-md-2">
        <div class="form-check form-switch pt-1">
          <input class="form-check-input" type="checkbox" name="remote" value="1" id="remoteCheck" <?=$remote_filter ? 'checked' : ''?>>
          <label class="form-check-label small" for="remoteCheck">Remote Only</label>
        </div>
      </div>

      <div class="col-md-2 d-flex gap-1">
        <button type="submit" class="btn btn-sm btn-primary w-100">Filter</button>
        <?php if ($search_query || $score_filter || $remote_filter): ?>
          <a href="?rid=<?=$selected_rid?>&tab=matches" class="btn btn-sm btn-outline-secondary" title="Reset Filters"><i class="bi bi-arrow-counterclockwise"></i></a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <?php
  // Build Query for Matches
  $sql = "
      SELECT m.*, j.title, j.company, j.location, j.apply_url, j.source, j.is_remote, j.posted_at
      FROM job_matches m
      JOIN job_vacancies j ON j.id = m.job_id
      WHERE m.resume_id = ? AND j.is_active = 1
  ";
  $params = [$selected_rid];

  if ($score_filter > 0) {
      $sql .= " AND m.score >= ?";
      $params[] = $score_filter;
  }
  if ($remote_filter) {
      $sql .= " AND j.is_remote = 1";
  }
  if ($search_query !== '') {
      $sql .= " AND (j.title LIKE ? OR j.company LIKE ? OR j.description LIKE ?)";
      $term = "%{$search_query}%";
      $params[] = $term;
      $params[] = $term;
      $params[] = $term;
  }

  $sql .= " ORDER BY m.score DESC LIMIT 40";
  $stmt_jobs = $pdo->prepare($sql);
  $stmt_jobs->execute($params);
  $jobs = $stmt_jobs->fetchAll();
  ?>

  <?php if (empty($jobs)): ?>
    <div class="card p-5 text-center shadow-sm border-0 text-muted">
      <i class="bi bi-search display-4 text-secondary mb-2"></i>
      <h5>No matching jobs found with your current filters.</h5>
      <p class="small mb-3">Ensure active job vacancies exist or reset your search filters.</p>
      <div>
        <a href="?rid=<?=$selected_rid?>&tab=matches" class="btn btn-sm btn-outline-primary">Reset Filters</a>
      </div>
    </div>
  <?php else: ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
      <span class="small text-muted fw-semibold">Displaying <strong><?=count($jobs)?></strong> jobs matched to your resume</span>
      <small class="text-secondary">Scoring: 60% Skill Overlap + 40% TF-IDF Cosine Similarity</small>
    </div>

    <div class="row g-3">
      <?php foreach ($jobs as $j): 
          $hits = json_decode($j['matching_json'] ?? '[]', true) ?: [];
          $miss = json_decode($j['missing_json'] ?? '[]', true) ?: [];
          $courses = json_decode($j['courses_json'] ?? '{}', true) ?: [];
          $app_status = $user_applications[(int)$j['job_id']] ?? null;

          $score_val = (int)$j['score'];
          $badge_color = $score_val >= 80 ? 'bg-success' : ($score_val >= 65 ? 'bg-primary' : ($score_val >= 45 ? 'bg-warning text-dark' : 'bg-secondary'));
      ?>
        <div class="col-12">
          <div class="card p-3 shadow-sm border-0">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
              <div>
                <h5 class="fw-bold mb-1 text-dark">
                  <?=h($j['title'])?>
                  <?php if ($j['is_remote']): ?>
                    <span class="badge bg-info-subtle text-info border border-info-subtle ms-1 fs-6">Remote</span>
                  <?php endif; ?>
                </h5>
                <div class="text-muted small">
                  <span class="fw-semibold text-secondary"><?=h($j['company'])?></span> &bull; 
                  <i class="bi bi-geo-alt"></i> <?=h($j['location'])?> &bull; 
                  <span class="badge bg-light text-dark border">Source: <?=h($j['source'])?></span>
                </div>
              </div>

              <div class="d-flex align-items-center gap-2">
                <span class="badge <?=$badge_color?> fs-6 px-3 py-2">
                  <i class="bi bi-lightning-charge-fill me-1"></i> <?=$score_val?>% <?=h($j['label'])?>
                </span>
              </div>
            </div>

            <!-- Skills Comparison Row -->
            <div class="mt-3 p-2 bg-light rounded small">
              <div class="mb-1">
                <strong class="text-success"><i class="bi bi-check-circle-fill me-1"></i>Skills You Match (<?=count($hits)?>):</strong>
                <?php if (!empty($hits)): ?>
                  <?php foreach ($hits as $sk_hit): ?>
                    <span class="badge bg-success-subtle text-success border border-success-subtle me-1"><?=h($sk_hit)?></span>
                  <?php endforeach; ?>
                <?php else: ?>
                  <span class="text-muted">None specified in requirement</span>
                <?php endif; ?>
              </div>

              <?php if (!empty($miss)): ?>
                <div>
                  <strong class="text-danger"><i class="bi bi-x-circle-fill me-1"></i>Missing Skills (<?=count($miss)?>):</strong>
                  <?php foreach ($miss as $sk_miss): ?>
                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle me-1"><?=h($sk_miss)?></span>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>

            <!-- Recommended Free Upskilling Courses for Missing Skills -->
            <?php if (!empty($courses)): ?>
              <div class="mt-2 border-top pt-2">
                <small class="fw-bold text-secondary"><i class="bi bi-book-half text-primary me-1"></i>Upskilling Recommendations to boost your match:</small>
                <div class="d-flex flex-wrap gap-2 mt-1">
                  <?php foreach ($courses as $skill_name => $course_list): ?>
                    <?php if (!empty($course_list[0])): $c = $course_list[0]; ?>
                      <a href="<?=h($c['url'])?>" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm py-0 px-2 text-decoration-none" style="font-size: 0.78rem;">
                        <i class="bi bi-mortarboard me-1 text-primary"></i> Learn <strong><?=h($skill_name)?></strong>: <?=h($c['name'])?>
                      </a>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endif; ?>

            <!-- Action Buttons Row & Application Status Tracking -->
            <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 pt-2 border-top">
              <div class="d-flex align-items-center gap-2">
                <form method="post" class="d-flex align-items-center gap-1">
                  <input type="hidden" name="csrf" value="<?=csrf()?>">
                  <input type="hidden" name="action" value="update_application">
                  <input type="hidden" name="job_id" value="<?=$j['job_id']?>">
                  <label class="small text-muted fw-semibold me-1">My Status:</label>
                  <select name="status" class="form-select form-select-sm" style="width: auto;" onchange="this.form.submit()">
                    <option value="" disabled <?=$app_status === null ? 'selected' : ''?>>Select Status...</option>
                    <option value="saved" <?=$app_status === 'saved' ? 'selected' : ''?>>📌 Save Job</option>
                    <option value="applied" <?=$app_status === 'applied' ? 'selected' : ''?>>🚀 Applied</option>
                    <option value="interview" <?=$app_status === 'interview' ? 'selected' : ''?>>🎉 Interview</option>
                    <option value="offer" <?=$app_status === 'offer' ? 'selected' : ''?>>🏆 Offer</option>
                    <option value="rejected" <?=$app_status === 'rejected' ? 'selected' : ''?>>Rejected</option>
                  </select>
                </form>
              </div>

              <div>
                <a class="btn btn-sm btn-success px-3" href="<?=h($j['apply_url'])?>" target="_blank" rel="noopener">
                  <i class="bi bi-send-fill me-1"></i> Apply on Job Site
                </a>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

<?php endif; ?>

<?php else: ?>
  <!-- Fresh user with 0 resumes -->
  <div class="card p-5 text-center shadow-sm border-0 my-4">
    <div class="mb-3">
      <i class="bi bi-file-earmark-arrow-up display-2 text-primary"></i>
    </div>
    <h4 class="fw-bold">No Resume Uploaded Yet</h4>
    <p class="text-muted" style="max-width: 500px; margin: 0 auto 1.5rem auto;">
      Upload your resume in PDF or Word (.docx) format above to instantly calculate your ATS compatibility score, detect your skills, and view personalized job openings!
    </p>
  </div>
<?php endif; ?>

<?php
footer_html();
