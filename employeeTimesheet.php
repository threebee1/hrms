<?php
// Start the session with secure settings
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict'
]);
session_start();
session_regenerate_id(true);

// Database connection
$host = getenv('DB_HOST') ?: 'localhost';
$dbname = getenv('DB_NAME') ?: 'hrms';
$dbuser = getenv('DB_USER') ?: 'root';
$dbpass = getenv('DB_PASS') ?: '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $dbuser, $dbpass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die('Internal server error: Database connection failed.');
}

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    error_log("No user_id in session, redirecting to login.html");
    header('Location: login.html');
    exit();
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$user_id = $_SESSION['user_id'];

// Verify timesheets table exists
try {
    $pdo->query("DESCRIBE timesheets");
} catch (PDOException $e) {
    error_log("Timesheets table does not exist or is inaccessible: " . $e->getMessage());
    die('Internal server error: Timesheet table is missing or inaccessible.');
}

// Fetch user data with LEFT JOIN to handle missing employee records
$stmt = $pdo->prepare("
    SELECT u.id, u.username, u.email, u.role, 
           e.first_name, e.last_name, e.department, e.position
    FROM users u
    LEFT JOIN employees e ON u.id = e.id
    WHERE u.id = ?
");
try {
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("User query failed: " . $e->getMessage());
    die('Internal server error: Unable to fetch user data.');
}

if (!$user) {
    error_log("User not found for user_id: $user_id");
    session_destroy();
    header('Location: login.html?error=user_not_found');
    exit();
}

// Set default values for missing employee data
$user['first_name'] = $user['first_name'] ?? '';
$user['last_name'] = $user['last_name'] ?? '';
$user['department'] = $user['department'] ?? 'Not Assigned';
$user['position'] = $user['position'] ?? 'Not Assigned';

// Fetch unread messages count
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as unread FROM messages WHERE recipient_id = ? AND is_read = 0");
    $stmt->execute([$user_id]);
    $unreadMessages = $stmt->fetch(PDO::FETCH_ASSOC)['unread'];
} catch (PDOException $e) {
    error_log("Unread messages query failed: " . $e->getMessage());
    $unreadMessages = 0;
}

// Handle timesheet submission
$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $action = $_POST['action'] ?? '';
    $date = trim($_POST['date'] ?? '');
    $clock_in = trim($_POST['clock_in'] ?? '');
    $clock_out = trim($_POST['clock_out'] ?? '');
    $break_duration = trim($_POST['break_duration'] ?? '');

    error_log("POST received: action=$action, date=$date, clock_in=$clock_in, clock_out=$clock_out, break_duration=$break_duration, user_id=$user_id");

    // Validate inputs
    if (empty($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $error_message = "Please select a valid date.";
        error_log("Validation failed: Invalid date format: $date");
    } elseif ($action === 'clock_in') {
        // Validate clock-in time format (HH:MM)
        if (empty($clock_in) || !preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $clock_in)) {
            $error_message = "Please enter a valid clock-in time (e.g., 09:00).";
            error_log("Validation failed: Invalid clock-in time: $clock_in");
        } else {
            try {
                $pdo->beginTransaction();

                // Check if a timesheet entry already exists for the date
                $stmt = $pdo->prepare("SELECT id FROM timesheets WHERE employee_id = ? AND date = ?");
                $stmt->execute([$user_id, $date]);
                if ($stmt->fetch()) {
                    $error_message = "A timesheet entry already exists for this date.";
                    error_log("Duplicate timesheet entry for user_id: $user_id, date: $date");
                } else {
                    // Insert new timesheet entry
                    $stmt = $pdo->prepare("
                        INSERT INTO timesheets (employee_id, date, clock_in, status)
                        VALUES (?, ?, ?, 'pending')
                    ");
                    $stmt->execute([$user_id, $date, $clock_in]);
                    error_log("Clock-in recorded successfully for user_id: $user_id, date: $date, clock_in: $clock_in");
                    $success_message = "Clock-in recorded successfully!";
                }

                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Timesheet clock-in failed: " . $e->getMessage());
                $error_message = "Error recording clock-in. Please try again.";
            }
        }
    } elseif ($action === 'clock_out') {
        // Validate clock-out inputs
        if (empty($clock_out) || !preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $clock_out)) {
            $error_message = "Please enter a valid clock-out time (e.g., 17:00).";
            error_log("Validation failed: Invalid clock-out time: $clock_out");
        } elseif (empty($break_duration) || !is_numeric($break_duration) || $break_duration < 0) {
            $error_message = "Please enter a valid break duration (minutes).";
            error_log("Validation failed: Invalid break duration: $break_duration");
        } else {
            try {
                $pdo->beginTransaction();

                // Update existing timesheet entry
                $stmt = $pdo->prepare("
                    SELECT id, clock_in FROM timesheets WHERE employee_id = ? AND date = ? AND clock_out IS NULL
                ");
                $stmt->execute([$user_id, $date]);
                $timesheet = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$timesheet) {
                    $error_message = "No open timesheet found for this date. Please clock in first.";
                    error_log("No open timesheet for user_id: $user_id, date: $date");
                } else {
                    // Calculate total hours
                    $clock_in_time = new DateTime($timesheet['clock_in']);
                    $clock_out_time = new DateTime($clock_out);
                    if ($clock_out_time <= $clock_in_time) {
                        $error_message = "Clock-out time must be after clock-in time.";
                        error_log("Invalid clock-out time: $clock_out is not after clock-in time: {$timesheet['clock_in']}");
                    } else {
                        $interval = $clock_in_time->diff($clock_out_time);
                        $total_hours = $interval->h + ($interval->i / 60) - ($break_duration / 60);

                        if ($total_hours <= 0) {
                            $error_message = "Invalid timesheet: Total hours must be positive.";
                            error_log("Invalid total hours: $total_hours for user_id: $user_id, date: $date");
                        } else {
                            $stmt = $pdo->prepare("
                                UPDATE timesheets 
                                SET clock_out = ?, break_duration = ?, total_hours = ?, status = 'pending'
                                WHERE id = ?
                            ");
                            $stmt->execute([$clock_out, $break_duration, $total_hours, $timesheet['id']]);
                            error_log("Clock-out recorded successfully for user_id: $user_id, date: $date, total_hours: $total_hours");
                            $success_message = "Clock-out recorded successfully!";
                        }
                    }
                }

                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Timesheet clock-out failed: " . $e->getMessage());
                $error_message = "Error recording clock-out. Please try again.";
            }
        }
    }
}

// Fetch employee's timesheet data
$search_date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : null;
$query = "SELECT id, date, clock_in, clock_out, break_duration, total_hours, status
          FROM timesheets
          WHERE employee_id = ?
          " . ($search_date ? "AND date = ?" : "") . "
          ORDER BY date DESC";
$params = [$user_id];
if ($search_date) {
    $params[] = $search_date;
}

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $timesheets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Timesheet query failed: " . $e->getMessage());
    $timesheets = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Timesheet | HRPro</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/flatpickr/4.6.13/flatpickr.min.css">
    <style>
        :root {
            --primary: #4B3F72;
            --primary-light: #6B5CA5;
            --primary-dark: #3A3159;
            --secondary: #BFA2DB;
            --light: #F7EDF0;
            --white: #FFFFFF;
            --error: #FF6B6B;
            --success: #4BB543;
            --text: #2D2A4A;
            --text-light: #A0A0B2;
            --gray: #E5E5E5;
            --border-radius: 12px;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            --shadow-hover: 0 8px 24px rgba(0, 0, 0, 0.15);
            --transition: all 0.3s ease;
            --focus-ring: 0 0 0 3px rgba(191, 162, 219, 0.3);
            --gradient: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Outfit', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
        }

        body {
            display: flex;
            min-height: 100vh;
            background: linear-gradient(135deg, var(--light) 0%, var(--white) 100%);
            color: var(--text);
            line-height: 1.6;
        }

        .sidebar {
            width: 260px;
            background: var(--gradient);
            color: var(--white);
            box-shadow: var(--shadow);
            transition: var(--transition);
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
        }

        .sidebar.collapsed {
            width: 80px;
        }

        .sidebar.collapsed .logo-text,
        .sidebar.collapsed .menu-text,
        .sidebar.collapsed .menu-badge {
            display: none;
        }

        .sidebar.collapsed .menu-item {
            justify-content: center;
        }

        .sidebar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        }

        .logo {
            display: flex;
            align-items: center;
        }

        .logo-icon {
            font-size: 24px;
            color: var(--secondary);
            margin-right: 10px;
        }

        .logo-text {
            font-family: 'Poppins', sans-serif;
            font-size: 24px;
            font-weight: 600;
        }

        .toggle-btn {
            background: none;
            border: none;
            font-size: 16px;
            color: var(--secondary);
            cursor: pointer;
            padding: 5px;
            transition: var(--transition);
        }

        .toggle-btn:hover {
            color: var(--white);
            transform: scale(1.1);
        }

        .sidebar-menu {
            padding: 15px 0;
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
            background-color: var(--primary-light);
            transform: translateX(5px);
        }

        .menu-item.active {
            background-color: var(--primary-light);
            border-left: 4px solid var(--secondary);
        }

        .menu-item i {
            margin-right: 12px;
            font-size: 18px;
        }

        .menu-badge {
            background: var(--error);
            color: var(--white);
            border-radius: 50%;
            padding: 2px 8px;
            margin-left: auto;
            font-size: 12px;
            font-weight: 600;
        }

        .main-content {
            flex: 1;
            overflow-y: auto;
            padding-bottom: 60px;
            background-color: var(--white);
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 30px;
            background: var(--white);
            box-shadow: var(--shadow);
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .header-title h1 {
            font-family: 'Poppins', sans-serif;
            font-size: 28px;
            color: var(--primary);
            margin-bottom: 5px;
        }

        .header-title p {
            font-size: 14px;
            color: var(--text-light);
        }

        .header-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .current-time {
            font-size: 14px;
            color: var(--text-light);
        }

        .user-profile {
            display: flex;
            align-items: center;
        }

        .user-avatar {
            width: 44px;
            height: 44px;
            background: var(--gradient);
            color: var(--white);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 10px;
            font-weight: 600;
            font-size: 16px;
            box-shadow: var(--shadow);
        }

        .content {
            max-width: 1200px;
            margin: 40px auto;
            padding: 0 30px;
        }

        .card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            transition: var(--transition);
        }

        .card:hover {
            box-shadow: var(--shadow-hover);
            transform: translateY(-4px);
        }

        .card-header {
            background: var(--gradient);
            color: var(--white);
            padding: 20px;
            border-radius: var(--border-radius) var(--border-radius) 0 0;
            text-align: center;
        }

        .card-header h2 {
            font-family: 'Poppins', sans-serif;
            font-size: 24px;
            margin: 0;
        }

        .card-body {
            padding: 30px;
        }

        .alert-success,
        .alert-error {
            padding: 20px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            font-size: 16px;
            animation: fadeIn 0.5s ease;
            box-shadow: var(--shadow);
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

        .alert-success i,
        .alert-error i {
            margin-right: 12px;
            font-size: 20px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 24px;
            margin-bottom: 30px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-label {
            font-size: 14px;
            color: var(--text);
            font-weight: 600;
            margin-bottom: 8px;
        }

        .form-control {
            width: 100%;
            padding: 12px;
            border: 2px solid var(--gray);
            border-radius: var(--border-radius);
            font-size: 14px;
            background: var(--white);
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.05);
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--secondary);
            box-shadow: var(--focus-ring), inset 0 2px 4px rgba(0, 0, 0, 0.05);
            background: var(--white);
        }

        .btn {
            padding: 14px 24px;
            border-radius: var(--border-radius);
            font-size: 14px;
            cursor: pointer;
            transition: var(--transition);
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: none;
        }

        .btn-primary {
            background: var(--gradient);
            color: var(--white);
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
        }

        .btn-primary:hover {
            background: var(--primary-light);
            box-shadow: var(--shadow-hover);
            transform: translateY(-2px);
        }

        .btn-primary::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            transform: translate(-50%, -50%);
            transition: width 0.6s ease, height 0.6s ease;
        }

        .btn-primary:hover::before {
            width: 200px;
            height: 200px;
        }

        .table-responsive {
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        .table {
            margin-bottom: 0;
            width: 100%;
        }

        .table thead th {
            background: var(--primary);
            color: var(--white);
            padding: 12px;
            font-weight: 600;
            text-align: left;
        }

        .table tbody tr {
            transition: var(--transition);
        }

        .table tbody tr:hover {
            background: rgba(191, 162, 219, 0.1);
        }

        .table td {
            padding: 12px;
 confront-align: middle;
            border-top: 1px solid var(--gray);
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 992px) {
            .content {
                padding: 0 20px;
                margin: 20px auto;
            }
            .card-body {
                padding: 20px;
            }
            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                position: fixed;
                left: 0;
                top: 0;
                bottom: 0;
                transform: translateX(0);
                z-index: 1000;
            }
            .sidebar.collapsed {
                transform: translateX(-100%);
            }
            .main-content {
                margin-left: 0;
            }
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
                padding: 15px;
            }
            .header-info {
                width: 100%;
                justify-content: space-between;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            * {
                animation-duration: 0.01ms !important;
                transition-duration: 0.01ms !important;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <i class="fas fa-user-tie logo-icon"></i>
                <h1 class="logo-text">Employee</h1>
            </div>
            <button class="toggle-btn" id="sidebarToggle">
                <i class="fas fa-chevron-left"></i>
            </button>
        </div>
        <nav class="sidebar-menu">
            <a href="Employeedashboard.php" class="menu-item">
                <i class="fas fa-home"></i>
                <span class="menu-text">Dashboard</span>
            </a>
            <a href="Employeepersonal.php" class="menu-item">
                <i class="fas fa-user"></i>
                <span class="menu-text">Personal Info</span>
            </a>
            <a href="Employeetimesheet.php" class="menu-item active">
                <i class="fas fa-clock"></i>
                <span class="menu-text">Timesheet</span>
            </a>
            <a href="Employeetimeoff.php" class="menu-item">
                <i class="fas fa-calendar-minus"></i>
                <span class="menu-text">Time Off</span>
            </a>
            <a href="Employeeinbox.php" class="menu-item">
                <i class="fas fa-inbox"></i>
                <span class="menu-text">Inbox</span>
                <?php if ($unreadMessages > 0): ?>
                    <span class="menu-badge"><?php echo htmlspecialchars($unreadMessages); ?></span>
                <?php endif; ?>
            </a>
            <a href="login.html" class="menu-item">
                <i class="fas fa-sign-out-alt"></i>
                <span class="menu-text">Logout</span>
            </a>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-title">
                <h1>Timesheet</h1>
                <p>Log and view your work hours</p>
            </div>
            <div class="header-info">
                <div class="current-time" id="currentTime">
                    <?php 
                    date_default_timezone_set('Asia/Manila');
                    echo date('l, F j, Y g:i A'); 
                    ?>
                </div>
                <div class="user-profile">
                    <div class="user-avatar">
                        <?php 
                        $initials = substr($user['username'] ?? '', 0, 2) ?: 'UK';
                        echo htmlspecialchars($initials);
                        ?>
                    </div>
                    <span><?php echo htmlspecialchars($user['username'] . ' - ' . ucfirst($user['role'])); ?></span>
                </div>
            </div>
        </header>

        <!-- Content -->
        <div class="content">
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-clock me-2"></i>My Timesheet</h2>
                </div>
                <div class="card-body">
                    <!-- Notifications -->
                    <?php if ($success_message): ?>
                        <div class="alert-success" role="alert">
                            <i class="fas fa-check-circle"></i>
                            <?php echo htmlspecialchars($success_message); ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($error_message): ?>
                        <div class="alert-error" role="alert">
                            <i class="fas fa-exclamation-circle"></i>
                            <?php echo htmlspecialchars($error_message); ?>
                        </div>
                    <?php endif; ?>
                    <?php if (empty($user['department']) || empty($user['position'])): ?>
                        <div class="alert-error" role="alert">
                            <i class="fas fa-exclamation-circle"></i>
                            Your employee profile is incomplete. Please contact HR to update your details.
                        </div>
                    <?php endif; ?>

                    <!-- Timesheet Form -->
                    <form action="Employeetimesheet.php" method="POST" class="form-grid">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <div class="form-group">
                            <label for="date" class="form-label">Date</label>
                            <input type="text" class="form-control flatpickr-input" id="date" name="date" value="<?php echo htmlspecialchars($search_date ?: date('Y-m-d')); ?>" required aria-required="true">
                        </div>
                        <div class="form-group">
                            <label for="clock_in" class="form-label">Clock In</label>
                            <input type="time" class="form-control" id="clock_in" name="clock_in" aria-required="true">
                        </div>
                        <div class="form-group">
                            <label for="clock_out" class="form-label">Clock Out</label>
                            <input type="time" class="form-control" id="clock_out" name="clock_out">
                        </div>
                        <div class="form-group">
                            <label for="break_duration" class="form-label">Break Duration (minutes)</label>
                            <input type="number" class="form-control" id="break_duration" name="break_duration" min="0" step="1">
                        </div>
                        <div class="form-group" style="display: flex; align-items: flex-end; gap: 10px;">
                            <button type="submit" name="action" value="clock_in" class="btn btn-primary">
                                <i class="fas fa-sign-in-alt me-2"></i>Clock In
                            </button>
                            <button type="submit" name="action" value="clock_out" class="btn btn-primary">
                                <i class="fas fa-sign-out-alt me-2"></i>Clock Out
                            </button>
                        </div>
                    </form>

                    <!-- Timesheet Table -->
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Clock In</th>
                                    <th>Clock Out</th>
                                    <th>Break (mins)</th>
                                    <th>Total Hours</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($timesheets) > 0): ?>
                                    <?php foreach ($timesheets as $row): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($row['date']); ?></td>
                                            <td><?php echo htmlspecialchars($row['clock_in'] ?: 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($row['clock_out'] ?: 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($row['break_duration'] ?: '0'); ?></td>
                                            <td><?php echo htmlspecialchars($row['total_hours'] ? number_format($row['total_hours'], 2) : 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars(ucfirst($row['status'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4">No timesheet records found</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/flatpickr/4.6.13/flatpickr.min.js"></script>
    <script>
        // Sidebar Toggle
        document.getElementById('sidebarToggle').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('collapsed');
        });

        // Current Time Update
        function updateTime() {
            const timeElement = document.getElementById('currentTime');
            const now = new Date();
            timeElement.textContent = now.toLocaleString('en-US', {
                weekday: 'long',
                month: 'long',
                day: 'numeric',
                year: 'numeric',
                hour: 'numeric',
                minute: 'numeric',
                hour12: true
            });
        }
        setInterval(updateTime, 1000);
        updateTime();

        // Flatpickr for Date
        flatpickr('#date', {
            dateFormat: 'Y-m-d',
            maxDate: 'today',
            onOpen: function() {
                document.querySelector('.flatpickr-calendar').setAttribute('role', 'dialog');
                document.querySelector('.flatpickr-calendar').setAttribute('aria-label', 'Date picker');
            }
        });

        // Auto-dismiss Notifications
        document.querySelectorAll('.alert-success, .alert-error').forEach(alert => {
            setTimeout(() => {
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 300);
            }, 5000);
        });
    </script>
</body>
</html>