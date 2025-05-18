<?php
require_once 'config/db.php';
require_once 'includes/functions.php';
require_once 'includes/session.php';

// Check if user is logged in and is a company
if (!isLoggedIn() || getUserRole() !== 'company') {
    header('Location: login.php');
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $application_id = $_POST['application_id'];
    $interview_date = $_POST['interview_date'];
    $interview_time = $_POST['interview_time'];
    $interview_mode = $_POST['interview_mode'];
    $interview_location = $_POST['interview_location'];
    $interview_link = $_POST['interview_link'];
    $company_notes = $_POST['notes'];

    // Validate required fields
    if (empty($interview_date) || empty($interview_time) || empty($interview_mode)) {
        $error = "Please fill in all required fields.";
    } elseif ($interview_mode === 'onsite' && empty($interview_location)) {
        $error = "Please provide interview location for onsite interviews.";
    } elseif ($interview_mode === 'online' && empty($interview_link)) {
        $error = "Please provide interview link for online interviews.";
    } else {
        try {
            // Start transaction
            $pdo->beginTransaction();

            // Get application details
            $stmt = $pdo->prepare("
                SELECT ja.*, jl.title AS job_title, u.id as user_id, u.name AS applicant_name, jl.company_id, ja.job_id
                FROM job_applications ja
                JOIN job_listings jl ON ja.job_id = jl.id
                JOIN users u ON ja.user_id = u.id
                WHERE ja.id = ?
            ");
            $stmt->execute([$application_id]);
            $application = $stmt->fetch();

            // Insert interview schedule into interview_schedules table
            $stmt = $pdo->prepare("
                INSERT INTO interview_schedules (
                    application_id, user_id, company_id, job_id, 
                    interview_date, interview_time, interview_mode, 
                    interview_location, interview_link, status, 
                    company_notes, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'proposed', ?, NOW())
            ");
            $stmt->execute([
                $application_id,
                $application['user_id'],
                $application['company_id'],
                $application['job_id'],
                $interview_date,
                $interview_time,
                $interview_mode,
                $interview_mode === 'onsite' ? $interview_location : null,
                $interview_mode === 'online' ? $interview_link : null,
                $company_notes
            ]);

            // Update application status
            $stmt = $pdo->prepare("
                UPDATE job_applications 
                SET status = 'interview' 
                WHERE id = ?
            ");
            $stmt->execute([$application_id]);

            // Fetch admin user ID dynamically
            $stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
            $stmt->execute();
            $admin = $stmt->fetch();
            $admin_id = $admin ? $admin['id'] : null;

            // Create notification for admin if admin user exists
            if ($admin_id) {
                $stmt = $pdo->prepare("
                    INSERT INTO notifications (
                        user_id, title, type, message, link, is_read, created_at
                    ) VALUES (?, ?, 'interview_schedule', ?, ?, 0, NOW())
                ");
                $stmt->execute([
                    $admin_id,
                    'New Interview Schedule',
                    "New interview schedule for {$application['job_title']} with {$application['applicant_name']}",
                    "manage_schedules.php"
                ]);
            }

            // Fetch company user ID from companies table using the company record ID from session
            $company_record_id = $_SESSION['company_id']; // This is likely the companies.id
            $stmt = $pdo->prepare("SELECT user_id FROM companies WHERE id = ?");
            $stmt->execute([$company_record_id]);
            $company = $stmt->fetch();
            $company_user_id = $company ? $company['user_id'] : null;

            if ($company_user_id) {
                $stmt = $pdo->prepare("
                    INSERT INTO notifications (
                        user_id, title, type, message, link, is_read, created_at
                    ) VALUES (?, ?, 'interview_schedule', ?, ?, 0, NOW())
                ");
                $stmt->execute([
                    $company_user_id,
                    'Interview Schedule Created',
                    "You have scheduled an interview for {$application['job_title']} with {$application['applicant_name']}",
                    "company_dashboard.php"
                ]);
            }

            $pdo->commit();
            $success = "Interview scheduled successfully! Waiting for admin approval.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error scheduling interview: " . $e->getMessage();
        }
    }
}

// Get application details
$application_id = $_GET['application_id'] ?? null;
if (!$application_id) {
    header('Location: company_dashboard.php');
    exit();
}

$stmt = $pdo->prepare("
    SELECT ja.*, jl.title AS job_title, u.name as applicant_name 
    FROM job_applications ja 
    JOIN job_listings jl ON ja.job_id = jl.id 
    JOIN users u ON ja.user_id = u.id 
    WHERE ja.id = ?
");
$stmt->execute([$application_id]);
$application = $stmt->fetch();

if (!$application) {
    header('Location: company_dashboard.php');
    exit();
}

// Add this after the initial checks
if (isset($_GET['check_slot'])) {
    $date = $_GET['date'];
    $time = $_GET['time'];
    $company_id = $_SESSION['company_id'];
    $application_id = $_GET['application_id'] ?? null;
    
    $isAvailable = isSlotAvailable($pdo, $date, $time, $company_id, $application_id);
    $message = $isAvailable ? 'Slot is available' : 'This applicant already has a pending interview schedule';
    echo json_encode(['available' => $isAvailable, 'message' => $message]);
    exit;
}
require_once 'includes/header.php';
?>

<style>
:root {
    --primary-color: #4B654F;
    --primary-dark: #3A463A;
    --primary-light: #E6EFE6;
    --accent-color: #E9F5E9;
    --text-primary: #333333;
    --text-muted: #6c757d;
    --border-radius: 15px;
    --box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
}

body {
    background: linear-gradient(135deg, #f5f7f5 0%, #e8f0e8 100%);
    color: var(--text-primary);
}

.card {
    border: none;
    border-radius: var(--border-radius);
    box-shadow: var(--box-shadow);
    margin-bottom: 1.5rem;
}

.card-header {
    background: var(--primary-light);
    border-bottom: 1px solid #BFCABF;
    color: var(--primary-dark);
    font-weight: 600;
}

.card-title, .card-header h5, .card-header h4 {
    color: var(--primary-dark);
    font-weight: 700;
}

.btn-primary {
    background-color: var(--primary-color) !important;
    border-color: var(--primary-color) !important;
    color: #fff !important;
    font-weight: 600;
    border-radius: 8px;
    transition: all 0.3s ease;
}

.btn-primary:hover, .btn-primary:focus {
    background-color: var(--primary-dark) !important;
    border-color: var(--primary-dark) !important;
    color: #fff !important;
    box-shadow: 0 4px 8px rgba(75, 101, 79, 0.15);
}

.btn-outline-secondary {
    color: var(--primary-color) !important;
    border-color: var(--primary-color) !important;
    border-radius: 8px;
    font-weight: 600;
    transition: all 0.3s ease;
}

.btn-outline-secondary:hover, .btn-outline-secondary:focus {
    background-color: var(--primary-color) !important;
    color: #fff !important;
    box-shadow: 0 4px 8px rgba(75, 101, 79, 0.15);
}

.form-label {
    color: var(--primary-dark);
    font-weight: 500;
}

.form-control:focus, .form-select:focus {
    border-color: var(--primary-color);
    box-shadow: 0 0 0 0.25rem rgba(75, 101, 79, 0.15);
}

.form-control, .form-select {
    border: 1px solid #BFCABF;
    color: var(--primary-dark);
}

.alert-success {
    background: var(--primary-light);
    color: var(--primary-dark);
    border: 1px solid #BFCABF;
}

.alert-danger {
    background: #fff0f0;
    color: var(--primary-dark);
    border: 1px solid #BFCABF;
}

.custom-header {
    background-color: var(--primary-light);
    color: var(--primary-dark);
    font-weight: 600;
    padding: 15px;
    border-radius: var(--border-radius) var(--border-radius) 0 0;
    margin: -1.25rem -1.25rem 1rem -1.25rem;
    border-bottom: 1px solid #BFCABF;
}

.form-buttons {
    margin-top: 20px;
}

.form-buttons .row {
    gap: 15px;
}

@media (max-width: 767px) {
    .form-buttons .col-md-6 {
        margin-bottom: 10px;
    }
}
</style>

<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h4 class="mb-0">Schedule Interview</h4>
                </div>
                <div class="card-body">
                    <h6 class="card-subtitle mb-3 text-muted">
                        Position: <?php echo htmlspecialchars($application['job_title']); ?><br>
                        Applicant: <?php echo htmlspecialchars($application['applicant_name']); ?>
                    </h6>

                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo $error; ?></div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success"><?php echo $success; ?></div>
                    <?php endif; ?>

                    <form method="POST" action="" id="interviewForm">
                        <input type="hidden" name="application_id" value="<?php echo $application_id; ?>">

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="interview_date" class="form-label">Interview Date</label>
                                <input type="date" class="form-control" id="interview_date" name="interview_date" required>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="interview_time" class="form-label">Interview Time</label>
                                <input type="time" class="form-control" id="interview_time" name="interview_time" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="interview_mode" class="form-label">Interview Mode</label>
                            <select class="form-select" id="interview_mode" name="interview_mode" required>
                                <option value="onsite">On-site</option>
                                <option value="online">Online</option>
                            </select>
                        </div>

                        <div class="mb-3" id="locationField">
                            <label for="interview_location" class="form-label">Interview Location</label>
                            <input type="text" class="form-control" id="interview_location" name="interview_location">
                            <div class="form-text">Required for on-site interviews</div>
                        </div>

                        <div class="mb-3" id="linkField" style="display: none;">
                            <label for="interview_link" class="form-label">Interview Link</label>
                            <input type="url" class="form-control" id="interview_link" name="interview_link">
                            <div class="form-text">Required for online interviews</div>
                        </div>

                        <div class="mb-3">
                            <label for="notes" class="form-label">Additional Notes</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3"></textarea>
                        </div>

                        <div class="form-buttons">
                            <div class="row">
                                <div class="col-md-6">
                                    <button type="submit" class="btn btn-primary">Schedule Interview</button>
                                </div>
                                <div class="col-md-6">
                                    <a href="view_applications.php" class="btn btn-outline-secondary">Cancel</a>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('interview_mode').addEventListener('change', function() {
    const locationField = document.getElementById('locationField');
    const linkField = document.getElementById('linkField');
    
    if (this.value === 'onsite') {
        locationField.style.display = 'block';
        linkField.style.display = 'none';
        document.getElementById('interview_location').required = true;
        document.getElementById('interview_link').required = false;
    } else {
        locationField.style.display = 'none';
        linkField.style.display = 'block';
        document.getElementById('interview_location').required = false;
        document.getElementById('interview_link').required = true;
    }
});

document.addEventListener('DOMContentLoaded', function() {
    const dateInput = document.getElementById('interview_date');
    const timeInput = document.getElementById('interview_time');
    const submitButton = document.querySelector('button[type="submit"]');
    const timeFeedback = document.createElement('div');
    timeFeedback.className = 'invalid-feedback';
    timeInput.parentNode.appendChild(timeFeedback);
    
    function checkSlotAvailability() {
        const date = dateInput.value;
        const time = timeInput.value;
        const applicationId = document.querySelector('input[name="application_id"]').value;
        
        if (date && time) {
            fetch(`schedule_interview.php?check_slot=1&date=${date}&time=${time}&application_id=${applicationId}`)
                .then(response => response.json())
                .then(data => {
                    if (!data.available) {
                        timeInput.classList.add('is-invalid');
                        timeFeedback.textContent = data.message;
                        submitButton.disabled = true;
                    } else {
                        timeInput.classList.remove('is-invalid');
                        timeFeedback.textContent = '';
                        submitButton.disabled = false;
                    }
                });
        }
    }
    
    dateInput.addEventListener('change', checkSlotAvailability);
    timeInput.addEventListener('change', checkSlotAvailability);
});
</script>

<?php require_once 'includes/footer.php'; ?>