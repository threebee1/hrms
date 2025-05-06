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
    header('Location: login.html');
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
$success_message = '';
$error_message = '';

// Handle form submission for adding or updating performance ratings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_rating'], $_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $employee_id = (int)($_POST['employee_id'] ?? 0);
    $rating = (int)($_POST['rating'] ?? 0);
    $comments = trim($_POST['comments'] ?? '');
    $review_date = date('Y-m-d');

    if ($employee_id <= 0) {
        $error_message = 'Invalid employee ID.';
    } elseif ($rating < 1 || $rating > 5) {
        $error_message = 'Rating must be between 1 and 5.';
    } else {
        // Check if rating exists for today
        $stmt = $pdo->prepare("SELECT id FROM performance WHERE employee_id = ? AND DATE(review_date) = ?");
        $stmt->execute([$employee_id, $review_date]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // Update existing rating
            $stmt = $pdo->prepare("UPDATE performance SET rating = ?, comments = ? WHERE id = ?");
            if ($stmt->execute([$rating, $comments, $existing['id']])) {
                $success_message = 'Performance rating updated successfully!';
            } else {
                $error_message = 'Error updating performance rating.';
            }
        } else {
            // Insert new rating
            $stmt = $pdo->prepare("INSERT INTO performance (employee_id, review_date, rating, comments) VALUES (?, ?, ?, ?)");
            if ($stmt->execute([$employee_id, $review_date, $rating, $comments])) {
                $success_message = 'Performance rating added successfully!';
            } else {
                $error_message = 'Error adding performance rating.';
            }
        }
    }
}

// Fetch employee data with latest performance rating
$stmt = $pdo->prepare(
    "SELECT e.id, e.first_name, e.last_name, e.email, e.department, e.position,
        (SELECT rating FROM performance WHERE employee_id = e.id ORDER BY review_date DESC LIMIT 1) as latest_rating,
        (SELECT comments FROM performance WHERE employee_id = e.id ORDER BY review_date DESC LIMIT 1) as latest_comments,
        (SELECT review_date FROM performance WHERE employee_id = e.id ORDER BY review_date DESC LIMIT 1) as latest_review_date
    FROM employees e
    ORDER BY e.department, e.last_name, e.first_name"
);
$stmt->execute();
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch departments for filter
$stmt = $pdo->prepare("SELECT DISTINCT department FROM employees WHERE department IS NOT NULL AND department != '' ORDER BY department");
$stmt->execute();
$departments = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch stats for reports
$stmt = $pdo->prepare("SELECT rating, COUNT(*) as count FROM performance GROUP BY rating");
$stmt->execute();
$rating_stats = array_fill(1, 5, 0);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $rating_stats[$row['rating']] = $row['count'];
}

$stmt = $pdo->prepare(
    "SELECT e.department, COUNT(p.rating) as count
    FROM employees e
    LEFT JOIN performance p ON e.id = p.employee_id AND p.rating >= 4
    GROUP BY e.department"
);
$stmt->execute();
$dept_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Performance Management | HRPro</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #4B3F72;
            --primary-light: #6B5CA5;
            --secondary: #BFA2DB;
            --light: #F7EDF0;
            --white: #FFFFFF;
            --error: #FF6B6B;
            --success: #4BB543;
            --text: #2D2A4A;
            --gray: #E5E5E5;
            --border-radius: 12px;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }

        body {
            display: flex;
            min-height: 100vh;
            background: var(--light);
            font-family: 'Outfit', sans-serif;
            color: var(--text);
            line-height: 1.5;
        }

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

        .main-content {
            flex: 1;
            padding: 40px;
            background: var(--white);
        }

        .card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
        }

        .card-header {
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            color: var(--white);
            padding: 20px;
            border-radius: var(--border-radius) var(--border-radius) 0 0;
            text-align: center;
        }

        .card-body {
            padding: 40px;
        }

        .alert-success, .alert-error {
            padding: 15px;
            border-radius: var(--border-radius);
            margin-bottom: 24px;
            display: flex;
            align-items: center;
        }

        .alert-success {
            background: rgba(75, 181, 67, 0.15);
            color: var(--success);
            border: 1px solid rgba(75, 181, 67, 0.3);
        }

        .alert-error {
            background: rgba(255, 107, 107, 0.15);
            color: var(--error);
            border: 1px solid rgba(255, 107, 107, 0.3);
        }

        .form-control, .form-select {
            padding: 12px;
            border: 2px solid var(--gray);
            border-radius: var(--border-radius);
            transition: var(--transition);
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--secondary);
            box-shadow: 0 0 0 3px rgba(191, 162, 219, 0.3);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            color: var(--white);
            border: none;
            padding: 12px 24px;
            border-radius: var(--border-radius);
            transition: var(--transition);
        }

        .btn-primary:hover {
            background: var(--primary-light);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #6c757d, #adb5bd);
            color: var(--white);
        }

        .table thead th {
            background: var(--primary);
            color: var(--white);
            padding: 14px;
        }

        .table tbody tr:hover {
            background: rgba(191, 162, 219, 0.1);
        }

        .rating-stars input {
            display: none;
        }

        .rating-stars label {
            cursor: pointer;
            font-size: 25px;
            color: var(--gray);
        }

        .rating-stars label:hover,
        .rating-stars label:hover ~ label,
        .rating-stars input:checked ~ label {
            color: var(--secondary);
        }

        .employee-card {
            transition: var(--transition);
            border-left: 5px solid transparent;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
        }

        .employee-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.15);
        }

        .performance-high {
            border-left-color: var(--success);
        }

        .performance-medium {
            border-left-color: var(--secondary);
        }

        .performance-low {
            border-left-color: var(--error);
        }

        .performance-none {
            border-left-color: var(--gray);
        }

        .nav-tabs .nav-link {
            color: var(--text);
            padding: 12px 20px;
            border: none;
        }

        .nav-tabs .nav-link.active {
            color: var(--primary);
            border-bottom: 3px solid var(--primary);
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
                padding: 20px;
            }
            .card-body {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="d-flex align-items-center">
                <i class="fas fa-users logo-icon"></i>
                <h1 class="logo-text">HRPro</h1>
            </div>
            <button class="toggle-btn" id="sidebarToggle"><i class="fas fa-chevron-left"></i></button>
        </div>
        <nav class="sidebar-menu">
            <a href="dashboard.php" class="menu-item"><i class="fas fa-home"></i><span class="menu-text">Home</span></a>
            <a href="personal.php" class="menu-item"><i class="fas fa-user"></i><span class="menu-text">Personal</span></a>
            <a href="timesheet.php" class="menu-item"><i class="fas fa-clock"></i><span class="menu-text">Timesheet</span></a>
            <a href="timeoff.php" class="menu-item"><i class="fas fa-calendar-minus"></i><span class="menu-text">Time Off</span></a>
            <a href="emergency.php" class="menu-item"><i class="fas fa-bell"></i><span class="menu-text">Emergency</span></a>
            <a href="performance.php" class="menu-item active"><i class="fas fa-chart-line"></i><span class="menu-text">Performance</span></a>
            <a href="professionalpath.php" class="menu-item"><i class="fas fa-briefcase"></i><span class="menu-text">Professional Path</span></a>
            <a href="inbox.php" class="menu-item"><i class="fas fa-inbox"></i><span class="menu-text">Inbox</span></a>
            <a href="addEmployees.php" class="menu-item"><i class="fas fa-user-plus"></i><span class="menu-text">Add Employee</span></a>
            <a href="login.html" class="menu-item"><i class="fas fa-sign-out-alt"></i><span class="menu-text">Logout</span></a>
        </nav>
    </aside>

    <main class="main-content">
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-chart-line me-2"></i>Performance Management</h2>
            </div>
            <div class="card-body">
                <?php if ($success_message): ?>
                    <div class="alert-success"><i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?></div>
                <?php endif; ?>
                <?php if ($error_message): ?>
                    <div class="alert-error"><i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?></div>
                <?php endif; ?>

                <ul class="nav nav-tabs mb-4" id="performanceTabs">
                    <li class="nav-item">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#overview" role="tab" aria-selected="true">Performance Overview</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#reports" role="tab" aria-selected="false">Reports</button>
                    </li>
                </ul>

                <div class="tab-content">
                    <div class="tab-pane fade show active" id="overview">
                        <form method="GET" class="row g-3 mb-4">
                            <div class="col-md-3">
                                <input type="text" id="searchInput" class="form-control" placeholder="Search by name, position..." value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>" name="search">
                            </div>
                            <div class="col-md-3">
                                <select id="departmentFilter" class="form-select" name="department">
                                    <option value="">All Departments</option>
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo ($_GET['department'] ?? '') === $dept ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($dept); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select id="ratingFilter" class="form-select" name="rating">
                                    <option value="">All Ratings</option>
                                    <option value="5" <?php echo ($_GET['rating'] ?? '') === '5' ? 'selected' : ''; ?>>★★★★★ (5)</option>
                                    <option value="4" <?php echo ($_GET['rating'] ?? '') === '4' ? 'selected' : ''; ?>>★★★★☆ (4)</option>
                                    <option value="3" <?php echo ($_GET['rating'] ?? '') === '3' ? 'selected' : ''; ?>>★★★☆☆ (3)</option>
                                    <option value="2" <?php echo ($_GET['rating'] ?? '') === '2' ? 'selected' : ''; ?>>★★☆☆☆ (2)</option>
                                    <option value="1" <?php echo ($_GET['rating'] ?? '') === '1' ? 'selected' : ''; ?>>★☆☆☆☆ (1)</option>
                                    <option value="0" <?php echo ($_GET['rating'] ?? '') === '0' ? 'selected' : ''; ?>>Not Rated</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <input type="text" class="form-control datepicker" id="dateFilter" name="date_range" placeholder="Date Range" value="<?php echo htmlspecialchars($_GET['date_range'] ?? ''); ?>">
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search me-2"></i>Filter</button>
                            </div>
                        </form>

                        <?php
                        $current_department = '';
                        $filtered_employees = $employees;

                        // Apply filters
                        $search = strtolower(trim($_GET['search'] ?? ''));
                        $department = trim($_GET['department'] ?? '');
                        $rating = $_GET['rating'] ?? '';
                        $date_range = trim($_GET['date_range'] ?? '');

                        if ($search || $department || $rating !== '' || $date_range) {
                            $filtered_employees = array_filter($employees, function ($emp) use ($search, $department, $rating, $date_range, $pdo) {
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
                                if ($date_range) {
                                    [$start_date, $end_date] = array_map('trim', explode(' to ', $date_range));
                                    $stmt = $pdo->prepare(
                                        "SELECT COUNT(*) FROM performance WHERE employee_id = ? AND review_date BETWEEN ? AND ?"
                                    );
                                    $stmt->execute([$emp['id'], $start_date, $end_date]);
                                    $matches &= $stmt->fetchColumn() > 0;
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
                                     data-rating="<?php echo $emp['latest_rating'] ?: 0; ?>">
                                    <div class="card employee-card h-100 <?php echo $performance_class; ?>">
                                        <div class="card-body">
                                            <h5 class="card-title"><?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?></h5>
                                            <p class="card-text">
                                                <small class="text-muted"><?php echo htmlspecialchars($emp['position']); ?></small><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($emp['email']); ?></small>
                                            </p>
                                            <div class="mb-3">
                                                <strong>Current Rating:</strong>
                                                <div class="d-inline-block">
                                                    <?php
                                                    $rating = $emp['latest_rating'] ?: 0;
                                                    for ($i = 1; $i <= 5; $i++) {
                                                        echo '<i class="' . ($i <= $rating ? 'fas' : 'far') . ' fa-star ' . ($i <= $rating ? 'text-secondary' : 'text-muted') . '"></i>';
                                                    }
                                                    if ($emp['latest_review_date']) {
                                                        echo '<small class="text-muted ms-2">(' . date('M d, Y', strtotime($emp['latest_review_date'])) . ')</small>';
                                                    }
                                                    ?>
                                                </div>
                                            </div>
                                            <button type="button" class="btn btn-primary btn-sm rate-btn"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#rateEmployeeModal"
                                                    data-employee-id="<?php echo $emp['id']; ?>"
                                                    data-employee-name="<?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?>"
                                                    data-current-rating="<?php echo $rating; ?>"
                                                    data-current-comments="<?php echo htmlspecialchars($emp['latest_comments'] ?: ''); ?>">
                                                <i class="fas fa-star me-2"></i>Rate Performance
                                            </button>
                                            <button type="button" class="btn btn-secondary btn-sm view-history"
                                                    data-employee-id="<?php echo $emp['id']; ?>"
                                                    data-employee-name="<?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?>">
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

                    <div class="tab-pane fade" id="reports">
                        <div class="row">
                            <div class="col-md-6 mb-4">
                                <div class="card">
                                    <div class="card-header"><h5>Rating Distribution</h5></div>
                                    <div class="card-body"><canvas id="ratingDistributionChart"></canvas></div>
                                </div>
                            </div>
                            <div class="col-md-6 mb-4">
                                <div class="card">
                                    <div class="card-header"><h5>High Performers by Department</h5></div>
                                    <div class="card-body"><canvas id="highPerformersChart"></canvas></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Rate Employee Modal -->
    <div class="modal fade" id="rateEmployeeModal" tabindex="-1" aria-labelledby="rateEmployeeModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary">
                    <h5 class="modal-title" id="rateEmployeeModalLabel">Rate Employee</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" id="rateForm">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="employee_id" id="employee_id">
                        <div class="mb-3">
                            <label class="form-label">Employee:</label>
                            <h5 id="employee_name_display"></h5>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Rating:</label>
                            <div class="rating-stars">
                                <input type="radio" id="star5" name="rating" value="5" required />
                                <label for="star5" class="fas fa-star"></label>
                                <input type="radio" id="star4" name="rating" value="4" />
                                <label for="star4" class="fas fa-star"></label>
                                <input type="radio" id="star3" name="rating" value="3" />
                                <label for="star3" class="fas fa-star"></label>
                                <input type="radio" id="star2" name="rating" value="2" />
                                <label for="star2" class="fas fa-star"></label>
                                <input type="radio" id="star1" name="rating" value="1" />
                                <label for="star1" class="fas fa-star"></label>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="comments" class="form-label">Comments:</label>
                            <textarea class="form-control" id="comments" name="comments" rows="4"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="submit_rating" class="btn btn-primary">Submit Rating</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Performance History Modal -->
    <div class="modal fade" id="historyModal" tabindex="-1" aria-labelledby="historyModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary">
                    <h5 class="modal-title" id="historyModalLabel">Performance History</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="historyContent"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Sidebar toggle
        document.getElementById('sidebarToggle').addEventListener('click', () => {
            document.querySelector('.sidebar').classList.toggle('collapsed');
        });

        // Initialize Flatpickr for date range
        flatpickr('#dateFilter', {
            mode: 'range',
            dateFormat: 'Y-m-d',
            altInput: true,
            altFormat: 'M d, Y'
        });

        // Rate modal handling
        document.querySelectorAll('.rate-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const modal = new bootstrap.Modal(document.getElementById('rateEmployeeModal'));
                const employeeId = btn.dataset.employeeId;
                const employeeName = btn.dataset.employeeName;
                const currentRating = btn.dataset.currentRating;
                const currentComments = btn.dataset.currentComments;

                document.getElementById('employee_id').value = employeeId;
                document.getElementById('employee_name_display').textContent = employeeName;
                document.getElementById('comments').value = currentComments;

                if (currentRating > 0) {
                    document.getElementById('star' + currentRating).checked = true;
                } else {
                    document.querySelectorAll('.rating-stars input').forEach(input => input.checked = false);
                }
                modal.show();
            });
        });

        // Form validation
        document.getElementById('rateForm').addEventListener('submit', (e) => {
            const rating = document.querySelector('.rating-stars input:checked');
            if (!rating) {
                e.preventDefault();
                alert('Please select a rating.');
            }
        });

        // Performance history modal
        document.querySelectorAll('.view-history').forEach(btn => {
            btn.addEventListener('click', () => {
                const modal = new bootstrap.Modal(document.getElementById('historyModal'));
                const employeeId = btn.dataset.employeeId;
                const employeeName = btn.dataset.employeeName;

                document.getElementById('historyModalLabel').textContent = 'Performance History - ' + employeeName;

                fetchHistory(employeeId).then(data => {
                    document.getElementById('historyContent').innerHTML = data;
                    modal.show();
                }).catch(error => {
                    document.getElementById('historyContent').innerHTML = '<div class="alert alert-error">Error loading performance history. Please try again.</div>';
                    modal.show();
                });
            });
        });

        // Fetch performance history
        async function fetchHistory(employeeId) {
            const response = await fetch(`?action=get_history&employee_id=${employeeId}&csrf_token=<?php echo $_SESSION['csrf_token']; ?>`);
            if (!response.ok) throw new Error('Network error');
            return await response.text();
        }

        // Client-side filtering (backup for JavaScript-only filtering)
        function applyFilters() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const departmentTerm = document.getElementById('departmentFilter').value.toLowerCase();
            const ratingTerm = document.getElementById('ratingFilter').value;

            document.querySelectorAll('.employee-item').forEach(item => {
                const name = item.dataset.name;
                const department = item.dataset.department;
                const position = item.dataset.position;
                const rating = item.dataset.rating;

                const matchesSearch = !searchTerm || name.includes(searchTerm) || position.includes(searchTerm);
                const matchesDepartment = !departmentTerm || department === departmentTerm;
                const matchesRating = ratingTerm === '' || rating == ratingTerm;

                item.style.display = matchesSearch && matchesDepartment && matchesRating ? '' : 'none';
            });

            document.querySelectorAll('h4[data-department]').forEach(section => {
                const departmentName = section.dataset.department.toLowerCase();
                const hasVisibleEmployees = Array.from(
                    document.querySelectorAll(`.employee-item[data-department="${departmentName.toLowerCase()}"]`)
                ).some(emp => emp.style.display !== 'none');
                section.style.display = hasVisibleEmployees ? '' : 'none';
            });
        }

        document.getElementById('searchInput').addEventListener('input', applyFilters);
        document.getElementById('departmentFilter').addEventListener('change', applyFilters);
        document.getElementById('ratingFilter').addEventListener('change', applyFilters);

        // Charts
        new Chart(document.getElementById('ratingDistributionChart'), {
            type: 'pie',
            data: {
                labels: ['★☆☆☆☆ (1)', '★★☆☆☆ (2)', '★★★☆☆ (3)', '★★★★☆ (4)', '★★★★★ (5)'],
                datasets: [{
                    data: [
                        <?php echo $rating_stats[1]; ?>,
                        <?php echo $rating_stats[2]; ?>,
                        <?php echo $rating_stats[3]; ?>,
                        <?php echo $rating_stats[4]; ?>,
                        <?php echo $rating_stats[5]; ?>
                    ],
                    backgroundColor: ['#FF6B6B', '#FFB74D', '#BFA2DB', '#6B5CA5', '#4B3F72']
                }]
            }
        });

        new Chart(document.getElementById('highPerformersChart'), {
            type: 'bar',
            data: {
                labels: [<?php echo implode(',', array_map(fn($d) => "'".addslashes($d['department'])."'", $dept_stats)); ?>],
                datasets: [{
                    label: 'High Performers (Rating ≥ 4)',
                    data: [<?php echo implode(',', array_column($dept_stats, 'count')); ?>],
                    backgroundColor: '#4B3F72'
                }]
            },
            options: {
                scales: { y: { beginAtZero: true } }
            }
        });
    </script>

<?php
// Handle performance history AJAX request
if (isset($_GET['action']) && $_GET['action'] === 'get_history' && isset($_GET['employee_id'], $_GET['csrf_token']) && $_GET['csrf_token'] === $_SESSION['csrf_token']) {
    $employee_id = (int)$_GET['employee_id'];
    $stmt = $pdo->prepare(
        "SELECT p.*, e.first_name, e.last_name
        FROM performance p
        JOIN employees e ON p.employee_id = e.id
        WHERE p.employee_id = ?
        ORDER BY p.review_date DESC"
    );
    $stmt->execute([$employee_id]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($history) {
        echo '<table class="table table-striped">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Rating</th>
                        <th>Comments</th>
                    </tr>
                </thead>
                <tbody>';
        foreach ($history as $row) {
            echo '<tr>
                    <td>' . date('M d, Y', strtotime($row['review_date'])) . '</td>
                    <td>';
            for ($i = 1; $i <= 5; $i++) {
                echo '<i class="' . ($i <= $row['rating'] ? 'fas' : 'far') . ' fa-star ' . ($i <= $row['rating'] ? 'text-secondary' : 'text-muted') . '"></i>';
            }
            echo '</td>
                    <td>' . nl2br(htmlspecialchars($row['comments'])) . '</td>
                  </tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<div class="alert alert-info">No performance history found for this employee.</div>';
    }
    exit();
}
?>
</body>
</html>