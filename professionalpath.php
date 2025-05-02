<?php
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict'
]);
session_start();
session_regenerate_id(true);

// Database connection using PDO
$host = getenv('DB_HOST') ?: 'localhost';
$dbname = getenv('DB_NAME') ?: 'hrms';
$dbuser = getenv('DB_USER') ?: 'root';
$dbpass = getenv('DB_PASS') ?: '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $dbuser, $dbpass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die('Internal server error');
}

// Authentication and authorization
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$user_id = (int)$_SESSION['user_id'];
$stmt_role = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$stmt_role->execute([$user_id]);
$user_data = $stmt_role->fetch(PDO::FETCH_ASSOC);

if (!$user_data || $user_data['role'] !== 'hr') {
    header('Location: dashboard.php');
    exit();
}

// Initialize messages
$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Handle form submission for adding/editing career milestones
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_milestone'], $_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $employee_id = (int)($_POST['employee_id'] ?? 0);
    $milestone_type = trim($_POST['milestone_type'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $milestone_date = $_POST['milestone_date'] ?? '';
    $milestone_id = (int)($_POST['milestone_id'] ?? 0);

    if ($employee_id <= 0) {
        $error_message = 'Invalid employee ID.';
    } elseif (empty($milestone_type) || !in_array($milestone_type, ['Promotion', 'Certification', 'Training'])) {
        $error_message = 'Invalid milestone type.';
    } elseif (empty($title)) {
        $error_message = 'Milestone title is required.';
    } elseif (empty($milestone_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $milestone_date)) {
        $error_message = 'Invalid milestone date.';
    } else {
        try {
            $pdo->beginTransaction();
            if ($milestone_id > 0) {
                // Update existing milestone
                $stmt = $pdo->prepare(
                    "UPDATE career_milestones SET milestone_type = ?, title = ?, description = ?, milestone_date = ? WHERE id = ? AND employee_id = ?"
                );
                $stmt->execute([$milestone_type, $title, $description, $milestone_date, $milestone_id, $employee_id]);
                $success_message = 'Career milestone updated successfully!';
                error_log("Career milestone updated: MilestoneID=$milestone_id, EmployeeID=$employee_id");
            } else {
                // Insert new milestone
                $stmt = $pdo->prepare(
                    "INSERT INTO career_milestones (employee_id, milestone_type, title, description, milestone_date) VALUES (?, ?, ?, ?, ?)"
                );
                $stmt->execute([$employee_id, $milestone_type, $title, $description, $milestone_date]);
                $success_message = 'Career milestone added successfully!';
                error_log("Career milestone added: EmployeeID=$employee_id, Type=$milestone_type");
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_message = 'Error saving career milestone. Please try again.';
            error_log("Career milestone failed: " . $e->getMessage());
        }
    }

    $_SESSION['success_message'] = $success_message;
    $_SESSION['error_message'] = $error_message;
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// Fetch employee data with latest performance rating and milestone count
$stmt = $pdo->prepare(
    "SELECT e.id, e.first_name, e.last_name, e.email, e.department, e.position, e.hire_date,
        (SELECT rating FROM performance WHERE employee_id = e.id ORDER BY review_date DESC LIMIT 1) as latest_rating,
        (SELECT review_date FROM performance WHERE employee_id = e.id ORDER BY review_date DESC LIMIT 1) as latest_review_date,
        (SELECT COUNT(*) FROM career_milestones WHERE employee_id = e.id) as milestone_count
    FROM employees e
    ORDER BY e.department, e.last_name, e.first_name"
);
$stmt->execute();
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch departments for filter
$stmt = $pdo->prepare("SELECT DISTINCT department FROM employees WHERE department IS NOT NULL AND department != '' ORDER BY department");
$stmt->execute();
$departments = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch milestone stats for reports
$stmt = $pdo->prepare("SELECT milestone_type, COUNT(*) as count FROM career_milestones GROUP BY milestone_type");
$stmt->execute();
$milestone_stats = ['Promotion' => 0, 'Certification' => 0, 'Training' => 0];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if (array_key_exists($row['milestone_type'], $milestone_stats)) {
        $milestone_stats[$row['milestone_type']] = $row['count'];
    }
}

$stmt = $pdo->prepare(
    "SELECT e.department, COUNT(cm.id) as count
    FROM employees e
    LEFT JOIN career_milestones cm ON e.id = cm.employee_id
    GROUP BY e.department"
);
$stmt->execute();
$dept_milestone_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Manage employee professional paths, milestones, and career development in HRPro">
    <title>Professional Path | HRPro</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4B3F72;
            --primary-light: #6B5CA5;
            --secondary: #BFA2DB;
            --accent: #7C3AED;
            --light: #F7EDF0;
            --white: #FFFFFF;
            --error: #EF4444;
            --success: #10B981;
            --text: #2D2A4A;
            --gray: #E5E7EB;
            --border-radius: 10px;
            --shadow: 0 6px 16px rgba(0, 0, 0, 0.08);
            --transition: all 0.2s ease-in-out;
        }

        body {
            display: flex;
            min-height: 100vh;
            background: var(--light);
            font-family: 'Inter', 'Roboto', sans-serif;
            color: var(--text);
            line-height: 1.6;
        }

        /* Sidebar Styles */
        .sidebar {
            width: 260px;
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            color: var(--white);
            position: sticky;
            top: 0;
            height: 100vh;
            transition: var(--transition);
        }

        .sidebar.collapsed {
            width: 80px;
        }

        .sidebar.collapsed .logo-text, .sidebar.collapsed .menu-text {
            display: none;
        }

        .sidebar-header {
            display: flex;
            justify-content: space-between;
            padding: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        }

        .logo-icon {
            font-size: 24px;
            color: var(--secondary);
            margin-right: 10px;
        }

        .logo-text {
            font-size: 24px;
            font-weight: 600;
        }

        .toggle-btn {
            background: none;
            border: none;
            color: var(--secondary);
            cursor: pointer;
            transition: var(--transition);
        }

        .toggle-btn:hover {
            color: var(--white);
        }

        .menu-item {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: var(--white);
            text-decoration: none;
            transition: var(--transition);
            position: relative;
        }

        .menu-item:hover {
            background: var(--primary-light);
        }

        .menu-item.active {
            background: var(--primary-light);
            border-left: 4px solid var(--secondary);
        }

        .menu-item i {
            margin-right: 12px;
            font-size: 18px;
        }

        .sidebar.collapsed .menu-item:hover::after {
            content: attr(data-tooltip);
            position: absolute;
            left: 90px;
            background: var(--primary);
            color: var(--white);
            padding: 4px 8px;
            border-radius: 4px;
            z-index: 1000;
            white-space: nowrap;
        }

        /* Main Content Styles */
        .main-content {
            flex: 1;
            padding: 32px;
            background: var(--white);
        }

        .section-header {
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            color: var(--white);
            padding: 24px;
            border-radius: var(--border-radius);
            text-align: center;
            margin-bottom: 32px;
            box-shadow: var(--shadow);
        }

        .section-body {
            max-width: 1200px;
            margin: 0 auto;
            padding: 24px;
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
        }

        .alert-success, .alert-error {
            position: relative;
            padding: 16px 48px 16px 16px;
            border-radius: var(--border-radius);
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            animation: fadeIn 0.5s ease-in;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            color: var(--error);
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .alert-dismiss {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: inherit;
            font-size: 18px;
            cursor: pointer;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-12px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .form-control, .form-select {
            padding: 12px 12px 12px 40px;
            border: 1px solid var(--gray);
            border-radius: var(--border-radius);
            background: #F9FAFB;
            transition: var(--transition);
            font-size: 15px;
            font-weight: 400;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.2);
            background: var(--white);
        }

        .form-control.is-invalid {
            border-color: var(--error);
            background: rgba(239, 68, 68, 0.05);
        }

        .form-group {
            position: relative;
            margin-bottom: 24px;
        }

        .form-group i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text);
            font-size: 16px;
        }

        .form-error {
            color: var(--error);
            font-size: 0.85rem;
            margin-top: 6px;
        }

        .form-label {
            font-weight: 500;
            margin-bottom: 8px;
            color: var(--text);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--accent), var(--primary-light));
            color: var(--white);
            border: none;
            padding: 12px 32px;
            border-radius: var(--border-radius);
            font-weight: 500;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.3);
            transition: var(--transition);
        }

        .btn-primary:hover {
            background: var(--accent);
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(124, 58, 237, 0.4);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #6c757d, #adb5bd);
            color: var(--white);
            border: none;
            padding: 12px 24px;
            border-radius: var(--border-radius);
            transition: var(--transition);
        }

        .btn-secondary:hover {
            background: #6c757d;
            transform: translateY(-2px);
        }

        .employee-card {
            transition: var(--transition);
            border-left: 5px solid transparent;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
        }

        .employee-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
        }

        .performance-high { border-left-color: var(--success); }
        .performance-medium { border-left-color: var(--secondary); }
        .performance-low { border-left-color: var(--error); }
        .performance-none { border-left-color: var(--gray); }

        .nav-tabs .nav-link {
            color: var(--text);
            padding: 12px 20px;
            border: none;
            font-weight: 500;
            transition: var(--transition);
        }

        .nav-tabs .nav-link.active {
            color: var(--primary);
            border-bottom: 3px solid var(--primary);
            background: rgba(191, 162, 219, 0.1);
        }

        .nav-tabs .nav-link:hover {
            color: var(--primary);
            background: rgba(191, 162, 219, 0.05);
        }

        .modal-content {
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
        }

        .modal-header {
            background: var(--primary);
            color: var(--white);
            border-radius: var(--border-radius) var(--border-radius) 0 0;
        }

        .modal-body {
            padding: 24px;
        }

        .modal-footer {
            border-top: none;
            padding: 16px 24px;
        }

        .table thead th {
            background: var(--primary);
            color: var(--white);
            padding: 14px;
        }

        .table tbody tr:hover {
            background: rgba(191, 162, 219, 0.1);
        }

        .chart-container {
            position: relative;
            height: 300px;
            margin-bottom: 24px;
        }

        @media (max-width: 768px) {
            .sidebar {
                position: fixed;
                z-index: 1000;
                transform: translateX(0);
            }
            .sidebar.collapsed {
                transform: translateX(-100%);
            }
            .main-content {
                padding: 16px;
            }
            .section-body {
                padding: 16px;
            }
            .form-control, .form-select {
                font-size: 14px;
            }
            .btn-primary, .btn-secondary {
                padding: 10px 24px;
            }
        }
    </style>
</head>
<body>
    <aside class="sidebar" role="navigation">
        <div class="sidebar-header">
            <div class="d-flex align-items-center">
                <i class="fas fa-users logo-icon"></i>
                <h1 class="logo-text">HRPro</h1>
            </div>
            <button class="toggle-btn" id="sidebarToggle" aria-label="Toggle Sidebar"><i class="fas fa-chevron-left"></i></button>
        </div>
        <nav class="sidebar-menu">
            <a href="dashboard.php" class="menu-item" data-tooltip="Home"><i class="fas fa-home"></i><span class="menu-text">Home</span></a>
            <a href="personal.php" class="menu-item" data-tooltip="Personal"><i class="fas fa-user"></i><span class="menu-text">Personal</span></a>
            <a href="timesheet.php" class="menu-item" data-tooltip="Timesheet"><i class="fas fa-clock"></i><span class="menu-text">Timesheet</span></a>
            <a href="timeoff.php" class="menu-item" data-tooltip="Time Off"><i class="fas fa-calendar-minus"></i><span class="menu-text">Time Off</span></a>
            <a href="emergency.php" class="menu-item" data-tooltip="Emergency"><i class="fas fa-bell"></i><span class="menu-text">Emergency</span></a>
            <a href="performance.php" class="menu-item" data-tooltip="Performance"><i class="fas fa-chart-line"></i><span class="menu-text">Performance</span></a>
            <a href="professionalpath.php" class="menu-item active" data-tooltip="Professional Path"><i class="fas fa-briefcase"></i><span class="menu-text">Professional Path</span></a>
            <a href="inbox.php" class="menu-item" data-tooltip="Inbox"><i class="fas fa-inbox"></i><span class="menu-text">Inbox</span></a>
            <a href="addEmployees.php" class="menu-item" data-tooltip="Add Employee"><i class="fas fa-user-plus"></i><span class="menu-text">Add Employee</span></a>
            <a href="login.html" class="menu-item" data-tooltip="Logout"><i class="fas fa-sign-out-alt"></i><span class="menu-text">Logout</span></a>
        </nav>
    </aside>

    <main class="main-content">
        <div class="section-header">
            <h2><i class="fas fa-briefcase me-2"></i>Professional Path</h2>
        </div>

        <div class="section-body">
            <?php if ($success_message): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?><button class="alert-dismiss" aria-label="Dismiss"><i class="fas fa-times"></i></button></div>
            <?php endif; ?>
            <?php if ($error_message): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?><button class="alert-dismiss" aria-label="Dismiss"><i class="fas fa-times"></i></button></div>
            <?php endif; ?>

            <ul class="nav nav-tabs mb-4" id="pathTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="overview-tab" data-bs-toggle="tab" data-bs-target="#overview" type="button" role="tab" aria-controls="overview" aria-selected="true">Career Overview</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="reports-tab" data-bs-toggle="tab" data-bs-target="#reports" type="button" role="tab" aria-controls="reports" aria-selected="false">Reports</button>
                </li>
            </ul>

            <div class="tab-content" id="pathTabContent">
                <div class="tab-pane fade show active" id="overview" role="tabpanel" aria-labelledby="overview-tab">
                    <form method="GET" class="row g-3 mb-4" id="filterForm">
                        <div class="col-md-3 form-group">
                            <i class="fas fa-search"></i>
                            <input type="text" id="searchInput" class="form-control" placeholder="Search by name, position..." name="search" value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>" aria-label="Search employees">
                        </div>
                        <div class="col-md-3 form-group">
                            <i class="fas fa-building"></i>
                            <select id="departmentFilter" class="form-select" name="department" aria-label="Filter by department">
                                <option value="">All Departments</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo ($_GET['department'] ?? '') === $dept ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($dept); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 form-group">
                            <i class="fas fa-star"></i>
                            <select id="ratingFilter" class="form-select" name="rating" aria-label="Filter by rating">
                                <option value="">All Ratings</option>
                                <option value="5" <?php echo ($_GET['rating'] ?? '') === '5' ? 'selected' : ''; ?>>★★★★★ (5)</option>
                                <option value="4" <?php echo ($_GET['rating'] ?? '') === '4' ? 'selected' : ''; ?>>★★★★☆ (4)</option>
                                <option value="3" <?php echo ($_GET['rating'] ?? '') === '3' ? 'selected' : ''; ?>>★★★☆☆ (3)</option>
                                <option value="2" <?php echo ($_GET['rating'] ?? '') === '2' ? 'selected' : ''; ?>>★★☆☆☆ (2)</option>
                                <option value="1" <?php echo ($_GET['rating'] ?? '') === '1' ? 'selected' : ''; ?>>★☆☆☆☆ (1)</option>
                                <option value="0" <?php echo ($_GET['rating'] ?? '') === '0' ? 'selected' : ''; ?>>Not Rated</option>
                            </select>
                        </div>
                        <div class="col-md-2 form-group">
                            <i class="fas fa-trophy"></i>
                            <select id="milestoneFilter" class="form-select" name="milestone" aria-label="Filter by milestone count">
                                <option value="">All Milestones</option>
                                <option value="0" <?php echo ($_GET['milestone'] ?? '') === '0' ? 'selected' : ''; ?>>No Milestones</option>
                                <option value="1" <?php echo ($_GET['milestone'] ?? '') === '1' ? 'selected' : ''; ?>>1+ Milestones</option>
                                <option value="3" <?php echo ($_GET['milestone'] ?? '') === '3' ? 'selected' : ''; ?>>3+ Milestones</option>
                                <option value="5" <?php echo ($_GET['milestone'] ?? '') === '5' ? 'selected' : ''; ?>>5+ Milestones</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter me-2"></i>Apply Filters</button>
                        </div>
                    </form>

                    <?php
                    $current_department = '';
                    $filtered_employees = $employees;

                    // Apply filters
                    $search = strtolower(trim($_GET['search'] ?? ''));
                    $department = trim($_GET['department'] ?? '');
                    $rating = $_GET['rating'] ?? '';
                    $milestone = $_GET['milestone'] ?? '';

                    if ($search || $department || $rating !== '' || $milestone !== '') {
                        $filtered_employees = array_filter($employees, function ($emp) use ($search, $department, $rating, $milestone) {
                            $matches = true;
                            if ($search) {
                                $matches &= strpos(strtolower($emp['first_name'] . ' ' . $emp['last_name']), $search) !== false ||
                                            strpos(strtolower($emp['position']), $search) !== false;
                            }
                            if ($department) {
                                $matches &= strtolower($emp['department']) === strtolower($department);
                            }
                            if ($rating !== '') {
                                $emp_rating = $emp['latest_rating'] ?: 0;
                                $matches &= $emp_rating == $rating;
                            }
                            if ($milestone !== '') {
                                $emp_milestones = $emp['milestone_count'];
                                $matches &= $milestone == 0 ? $emp_milestones == 0 : $emp_milestones >= $milestone;
                            }
                            return $matches;
                        });
                    }

                    if (empty($filtered_employees)) {
                        echo '<div class="text-center p-4">No employees match the selected filters.</div>';
                    } else {
                        foreach ($filtered_employees as $emp) {
                            $performance_class = 'performance-none';
                            if ($emp['latest_rating'] >= 4) {
                                $performance_class = 'performance-high';
                            } elseif ($emp['latest_rating'] >= 3) {
                                $performance_class = 'performance-medium';
                            } elseif ($emp['latest_rating'] > 0) {
                                $performance_class = 'performance-low';
                            }

                            if ($emp['department'] != $current_department) {
                                $current_department = $emp['department'];
                                echo '<h4 class="text-primary mt-4 mb-3" data-department="' . htmlspecialchars($current_department) . '">' . htmlspecialchars($current_department) . ' Department</h4>';
                            }
                    ?>
                            <div class="employee-item mb-4"
                                 data-name="<?php echo strtolower($emp['first_name'] . ' ' . $emp['last_name']); ?>"
                                 data-department="<?php echo strtolower($emp['department']); ?>"
                                 data-position="<?php echo strtolower($emp['position']); ?>"
                                 data-rating="<?php echo $emp['latest_rating'] ?: 0; ?>"
                                 data-milestone="<?php echo $emp['milestone_count']; ?>">
                                <div class="card employee-card h-100 <?php echo $performance_class; ?>">
                                    <div class="card-body">
                                        <h5 class="card-title"><?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?></h5>
                                        <p class="card-text">
                                            <small class="text-muted"><?php echo htmlspecialchars($emp['position']); ?> | Hired: <?php echo date('M d, Y', strtotime($emp['hire_date'])); ?></small><br>
                                            <small class="text-muted"><?php echo htmlspecialchars($emp['email']); ?></small>
                                        </p>
                                        <div class="mb-3">
                                            <strong>Latest Performance:</strong>
                                            <div class="d-inline-block">
                                                <?php
                                                $rating = $emp['latest_rating'] ?: 0;
                                                for ($i = 1; $i <= 5; $i++) {
                                                    echo '<i class="' . ($i <= $rating ? 'fas' : 'far') . ' fa-star ' . ($i <= $rating ? 'text-secondary' : 'text-muted') . '" aria-hidden="true"></i>';
                                                }
                                                if ($emp['latest_review_date']) {
                                                    echo '<small class="text-muted ms-2">(' . date('M d, Y', strtotime($emp['latest_review_date'])) . ')</small>';
                                                }
                                                ?>
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <strong>Career Milestones:</strong>
                                            <span><?php echo $emp['milestone_count']; ?> recorded</span>
                                        </div>
                                        <button type="button" class="btn btn-primary btn-sm milestone-btn"
                                                data-bs-toggle="modal"
                                                data-bs-target="#milestoneModal"
                                                data-employee-id="<?php echo $emp['id']; ?>"
                                                data-employee-name="<?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?>"
                                                aria-label="Add career milestone for <?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?>">
                                            <i class="fas fa-trophy me-2"></i>Add Milestone
                                        </button>
                                        <button type="button" class="btn btn-secondary btn-sm view-history"
                                                data-employee-id="<?php echo $emp['id']; ?>"
                                                data-employee-name="<?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?>"
                                                aria-label="View career history for <?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?>">
                                            <i class="fas fa-history me-2"></i>View History
                                        </button>
                                    </div>
                                </div>
                            </div>
                    <?php
                        }
                    }
                    ?>
                </div>

                <div class="tab-pane fade" id="reports" role="tabpanel" aria-labelledby="reports-tab">
                    <div class="row">
                        <div class="col-md-6 mb-4">
                            <div class="card">
                                <div class="card-header"><h5>Milestone Distribution</h5></div>
                                <div class="card-body">
                                    <div class="chart-container">
                                        <canvas id="milestoneDistributionChart" aria-label="Pie chart showing distribution of career milestones by type"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-4">
                            <div class="card">
                                <div class="card-header"><h5>Milestones by Department</h5></div>
                                <div class="card-body">
                                    <div class="chart-container">
                                        <canvas id="deptMilestoneChart" aria-label="Bar chart showing number of career milestones by department"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Career Milestone Modal -->
    <div class="modal fade" id="milestoneModal" tabindex="-1" aria-labelledby="milestoneModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="milestoneModalLabel">Add Career Milestone</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" id="milestoneForm">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="employee_id" id="employee_id">
                        <input type="hidden" name="milestone_id" id="milestone_id">
                        <div class="form-group">
                            <label class="form-label">Employee:</label>
                            <h5 id="employee_name_display"></h5>
                        </div>
                        <div class="form-group">
                            <label for="milestone_type" class="form-label">Milestone Type: <span class="text-danger">*</span></label>
                            <select id="milestone_type" name="milestone_type" class="form-select" required aria-label="Milestone type">
                                <option value="">Select Type</option>
                                <option value="Promotion">Promotion</option>
                                <option value="Certification">Certification</option>
                                <option value="Training">Training</option>
                            </select>
                            <div id="typeError" class="form-error"></div>
                        </div>
                        <div class="form-group">
                            <label for="title" class="form-label">Title: <span class="text-danger">*</span></label>
                            <input type="text" id="title" name="title" class="form-control" required aria-label="Milestone title">
                            <div id="titleError" class="form-error"></div>
                        </div>
                        <div class="form-group">
                            <label for="milestone_date" class="form-label">Date: <span class="text-danger">*</span></label>
                            <input type="text" id="milestone_date" name="milestone_date" class="form-control datepicker" required aria-label="Milestone date">
                            <div id="dateError" class="form-error"></div>
                        </div>
                        <div class="form-group">
                            <label for="description" class="form-label">Description:</label>
                            <textarea class="form-control" id="description" name="description" rows="4" aria-label="Milestone description"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" aria-label="Cancel milestone">Cancel</button>
                        <button type="submit" name="submit_milestone" class="btn btn-primary" aria-label="Submit career milestone">Submit Milestone</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Career History Modal -->
    <div class="modal fade" id="historyModal" tabindex="-1" aria-labelledby="historyModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="historyModalLabel">Career History</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="historyContent" aria-live="polite"></div>
                    <nav aria-label="Career history pagination">
                        <ul class="pagination justify-content-center mt-3">
                            <li class="page-item"><a class="page-link" href="#" id="prevPage">Previous</a></li>
                            <li class="page-item"><a class="page-link" href="#" id="nextPage">Next</a></li>
                        </ul>
                    </nav>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" aria-label="Close history modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Debounce utility
        function debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }

        // Initialize sidebar
        function initSidebar() {
            const sidebar = document.querySelector('.sidebar');
            const sidebarToggle = document.getElementById('sidebarToggle');
            const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
            if (isCollapsed) {
                sidebar.classList.add('collapsed');
            }
            sidebarToggle.addEventListener('click', () => {
                sidebar.classList.toggle('collapsed');
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            });
        }

        // Initialize datepicker
        function initDatepicker() {
            flatpickr('.datepicker', {
                dateFormat: 'Y-m-d',
                altInput: true,
                altFormat: 'M d, Y',
                maxDate: 'today',
                onChange: () => validateForm()
            });
        }

        // Validate milestone form
        function validateForm() {
            const type = document.getElementById('milestone_type');
            const title = document.getElementById('title');
            const date = document.getElementById('milestone_date');
            const typeError = document.getElementById('typeError');
            const titleError = document.getElementById('titleError');
            const dateError = document.getElementById('dateError');
            let isValid = true;

            if (!type.value) {
                typeError.textContent = 'Please select a milestone type.';
                type.classList.add('is-invalid');
                isValid = false;
            } else {
                typeError.textContent = '';
                type.classList.remove('is-invalid');
            }

            if (!title.value.trim()) {
                titleError.textContent = 'Please enter a milestone title.';
                title.classList.add('is-invalid');
                isValid = false;
            } else {
                titleError.textContent = '';
                title.classList.remove('is-invalid');
            }

            if (!date.value || !/^\d{4}-\d{2}-\d{2}$/.test(date.value)) {
                dateError.textContent = 'Please select a valid date.';
                date.classList.add('is-invalid');
                isValid = false;
            } else {
                dateError.textContent = '';
                date.classList.remove('is-invalid');
            }

            document.getElementById('milestoneForm').querySelector('button[type="submit"]').disabled = !isValid;
        }

        // Client-side filtering
        const applyFilters = debounce(() => {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const departmentTerm = document.getElementById('departmentFilter').value.toLowerCase();
            const ratingTerm = document.getElementById('ratingFilter').value;
            const milestoneTerm = document.getElementById('milestoneFilter').value;

            document.querySelectorAll('.employee-item').forEach(item => {
                const name = item.dataset.name;
                const department = item.dataset.department;
                const position = item.dataset.position;
                const rating = item.dataset.rating;
                const milestone = parseInt(item.dataset.milestone);

                const matchesSearch = !searchTerm || name.includes(searchTerm) || position.includes(searchTerm);
                const matchesDepartment = !departmentTerm || department === departmentTerm;
                const matchesRating = ratingTerm === '' || rating == ratingTerm;
                const matchesMilestone = milestoneTerm === '' || (milestoneTerm == 0 ? milestone == 0 : milestone >= milestoneTerm);

                item.style.display = matchesSearch && matchesDepartment && matchesRating && matchesMilestone ? '' : 'none';
            });

            document.querySelectorAll('h4[data-department]').forEach(section => {
                const departmentName = section.dataset.department.toLowerCase();
                const hasVisibleEmployees = Array.from(
                    document.querySelectorAll(`.employee-item[data-department="${departmentName.toLowerCase()}"]`)
                ).some(emp => emp.style.display !== 'none');
                section.style.display = hasVisibleEmployees ? '' : 'none';
            });
        }, 300);

        // Milestone modal handling
        function initMilestoneModal() {
            document.querySelectorAll('.milestone-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    const modal = new bootstrap.Modal(document.getElementById('milestoneModal'));
                    const employeeId = btn.dataset.employeeId;
                    const employeeName = btn.dataset.employeeName;

                    document.getElementById('employee_id').value = employeeId;
                    document.getElementById('employee_name_display').textContent = employeeName;
                    document.getElementById('milestone_id').value = '';
                    document.getElementById('milestone_type').value = '';
                    document.getElementById('title').value = '';
                    document.getElementById('description').value = '';
                    document.getElementById('milestone_date').value = '';
                    document.getElementById('milestoneModalLabel').textContent = 'Add Career Milestone';

                    validateForm();
                    modal.show();
                });
            });

            document.querySelectorAll('.edit-milestone').forEach(btn => {
                btn.addEventListener('click', () => {
                    const modal = new bootstrap.Modal(document.getElementById('milestoneModal'));
                    const milestoneId = btn.dataset.milestoneId;
                    const employeeId = btn.dataset.employeeId;
                    const employeeName = btn.dataset.employeeName;
                    const type = btn.dataset.type;
                    const title = btn.dataset.title;
                    const description = btn.dataset.description;
                    const date = btn.dataset.date;

                    document.getElementById('employee_id').value = employeeId;
                    document.getElementById('employee_name_display').textContent = employeeName;
                    document.getElementById('milestone_id').value = milestoneId;
                    document.getElementById('milestone_type').value = type;
                    document.getElementById('title').value = title;
                    document.getElementById('description').value = description;
                    document.getElementById('milestone_date').value = date;
                    document.getElementById('milestoneModalLabel').textContent = 'Edit Career Milestone';

                    validateForm();
                    modal.show();
                });
            });

            document.getElementById('milestoneForm').addEventListener('input', validateForm);
        }

        // Career history modal with pagination
        function initHistoryModal() {
            let currentPage = 1;
            const itemsPerPage = 10;

            document.querySelectorAll('.view-history').forEach(btn => {
                btn.addEventListener('click', () => {
                    currentPage = 1;
                    const modal = new bootstrap.Modal(document.getElementById('historyModal'));
                    const employeeId = btn.dataset.employeeId;
                    const employeeName = btn.dataset.employeeName;

                    document.getElementById('historyModalLabel').textContent = `Career History - ${employeeName}`;
                    fetchHistory(employeeId, currentPage, itemsPerPage).then(data => {
                        document.getElementById('historyContent').innerHTML = data.content;
                        updatePagination(data.totalPages, currentPage);
                        initMilestoneModal(); // Reinitialize edit buttons in history modal
                        modal.show();
                    }).catch(error => {
                        document.getElementById('historyContent').innerHTML = '<div class="alert alert-error">Error loading career history. Please try again.</div>';
                        modal.show();
                    });
                });
            });

            document.getElementById('prevPage').addEventListener('click', (e) => {
                e.preventDefault();
                if (currentPage > 1) {
                    currentPage--;
                    const employeeId = document.querySelector('.view-history[data-employee-id]').dataset.employeeId;
                    fetchHistory(employeeId, currentPage, itemsPerPage).then(data => {
                        document.getElementById('historyContent').innerHTML = data.content;
                        updatePagination(data.totalPages, currentPage);
                        initMilestoneModal(); // Reinitialize edit buttons
                    });
                }
            });

            document.getElementById('nextPage').addEventListener('click', (e) => {
                e.preventDefault();
                const employeeId = document.querySelector('.view-history[data-employee-id]').dataset.employeeId;
                fetchHistory(employeeId, currentPage + 1, itemsPerPage).then(data => {
                    if (data.content) {
                        currentPage++;
                        document.getElementById('historyContent').innerHTML = data.content;
                        updatePagination(data.totalPages, currentPage);
                        initMilestoneModal(); // Reinitialize edit buttons
                    }
                });
            });
        }

        async function fetchHistory(employeeId, page, itemsPerPage) {
            const response = await fetch(
                `?action=get_history&employee_id=${employeeId}&page=${page}&items=${itemsPerPage}&csrf_token=<?php echo $_SESSION['csrf_token']; ?>`
            );
            if (!response.ok) throw new Error('Network error');
            const data = await response.json();
            return data;
        }

        function updatePagination(totalPages, currentPage) {
            document.getElementById('prevPage').parentElement.classList.toggle('disabled', currentPage === 1);
            document.getElementById('nextPage').parentElement.classList.toggle('disabled', currentPage >= totalPages);
        }

        // Initialize charts
        function initCharts() {
            const milestoneChart = new Chart(document.getElementById('milestoneDistributionChart'), {
                type: 'pie',
                data: {
                    labels: ['Promotions', 'Certifications', 'Training'],
                    datasets: [{
                        data: [
                            <?php echo $milestone_stats['Promotion']; ?>,
                            <?php echo $milestone_stats['Certification']; ?>,
                            <?php echo $milestone_stats['Training']; ?>
                        ],
                        backgroundColor: ['#4B3F72', '#6B5CA5', '#BFA2DB']
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { position: 'bottom' },
                        tooltip: {
                            callbacks: {
                                label: ctx => `${ctx.label}: ${ctx.raw} milestones`
                            }
                        }
                    }
                }
            });

            const deptMilestoneChart = new Chart(document.getElementById('deptMilestoneChart'), {
                type: 'bar',
                data: {
                    labels: [<?php echo implode(',', array_map(fn($d) => "'".addslashes($d['department'])."'", $dept_milestone_stats)); ?>],
                    datasets: [{
                        label: 'Career Milestones',
                        data: [<?php echo implode(',', array_column($dept_milestone_stats, 'count')); ?>],
                        backgroundColor: '#4B3F72'
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: { beginAtZero: true, title: { display: true, text: 'Number of Milestones' } },
                        x: { title: { display: true, text: 'Department' } }
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: ctx => `${ctx.dataset.label}: ${ctx.raw}`
                            }
                        }
                    }
                }
            });
        }

        // Initialize alerts
        function initAlerts() {
            document.querySelectorAll('.alert-dismiss').forEach(button => {
                button.addEventListener('click', () => {
                    button.parentElement.style.display = 'none';
                });
            });
        }

        // Initialize all
        document.addEventListener('DOMContentLoaded', () => {
            initSidebar();
            initDatepicker();
            initMilestoneModal();
            initHistoryModal();
            initCharts();
            initAlerts();
            document.getElementById('searchInput').addEventListener('input', applyFilters);
            document.getElementById('departmentFilter').addEventListener('change', applyFilters);
            document.getElementById('ratingFilter').addEventListener('change', applyFilters);
            document.getElementById('milestoneFilter').addEventListener('change', applyFilters);
        });
    </script>

<?php
// Handle career history AJAX request
if (isset($_GET['action']) && $_GET['action'] === 'get_history' && isset($_GET['employee_id'], $_GET['csrf_token']) && $_GET['csrf_token'] === $_SESSION['csrf_token']) {
    $employee_id = (int)$_GET['employee_id'];
    $page = max(1, (int)($_GET['page'] ?? 1));
    $items_per_page = (int)($_GET['items'] ?? 10);
    $offset = ($page - 1) * $items_per_page;

    // Fetch career milestones
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM career_milestones WHERE employee_id = ?");
    $stmt->execute([$employee_id]);
    $total_items = $stmt->fetchColumn();
    $total_pages = ceil($total_items / $items_per_page);

    $stmt = $pdo->prepare(
        "SELECT cm.*, e.first_name, e.last_name
        FROM career_milestones cm
        JOIN employees e ON cm.employee_id = e.id
        WHERE cm.employee_id = ?
        ORDER BY cm.milestone_date DESC
        LIMIT ? OFFSET ?"
    );
    $stmt->execute([$employee_id, $items_per_page, $offset]);
    $milestones = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch interviews (assuming employee was a candidate)
    $stmt = $pdo->prepare(
        "SELECT i.position, i.interview_date, i.status
        FROM interviews i
        JOIN employees e ON i.candidate_name = CONCAT(e.first_name, ' ', e.last_name)
        WHERE e.id = ?
        ORDER BY i.interview_date DESC
        LIMIT 5"
    );
    $stmt->execute([$employee_id]);
    $interviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $output = '';
    if ($milestones || $interviews) {
        if ($milestones) {
            $output .= '<h5 class="mb-3">Career Milestones</h5>
                        <table class="table table-striped" aria-describedby="historyModalLabel">
                            <thead>
                                <tr>
                                    <th scope="col">Date</th>
                                    <th scope="col">Type</th>
                                    <th scope="col">Title</th>
                                    <th scope="col">Description</th>
                                    <th scope="col">Actions</th>
                                </tr>
                            </thead>
                            <tbody>';
            foreach ($milestones as $row) {
                $output .= '<tr>
                        <td>' . date('M d, Y', strtotime($row['milestone_date'])) . '</td>
                        <td>' . htmlspecialchars($row['milestone_type']) . '</td>
                        <td>' . htmlspecialchars($row['title']) . '</td>
                        <td>' . nl2br(htmlspecialchars($row['description'] ?: '-')) . '</td>
                        <td>
                            <button class="btn btn-sm btn-primary edit-milestone"
                                    data-milestone-id="' . $row['id'] . '"
                                    data-employee-id="' . $row['employee_id'] . '"
                                    data-employee-name="' . htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) . '"
                                    data-type="' . htmlspecialchars($row['milestone_type']) . '"
                                    data-title="' . htmlspecialchars($row['title']) . '"
                                    data-description="' . htmlspecialchars($row['description'] ?: '') . '"
                                    data-date="' . $row['milestone_date'] . '"
                                    aria-label="Edit milestone ' . htmlspecialchars($row['title']) . '">
                                <i class="fas fa-edit"></i>
                            </button>
                        </td>
                      </tr>';
            }
            $output .= '</tbody></table>';
        }

        if ($interviews) {
            $output .= '<h5 class="mt-4 mb-3">Interview History</h5>
                        <table class="table table-striped" aria-describedby="historyModalLabel">
                            <thead>
                                <tr>
                                    <th scope="col">Date</th>
                                    <th scope="col">Position</th>
                                    <th scope="col">Status</th>
                                </tr>
                            </thead>
                            <tbody>';
            foreach ($interviews as $row) {
                $output .= '<tr>
                        <td>' . date('M d, Y', strtotime($row['interview_date'])) . '</td>
                        <td>' . htmlspecialchars($row['position']) . '</td>
                        <td>' . htmlspecialchars($row['status']) . '</td>
                      </tr>';
            }
            $output .= '</tbody></table>';
        }
    } else {
        $output .= '<div class="alert alert-info">No career history found for this employee.</div>';
    }

    header('Content-Type: application/json');
    echo json_encode(['content' => $output, 'totalPages' => $total_pages]);
    exit();
}
?>
</body>
</html>