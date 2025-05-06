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
    $pdo->exec("SET time_zone = '+08:00'");
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

// Set timezone explicitly
date_default_timezone_set('Asia/Manila');

// Function to format total_hours as HH:MM
function formatTotalHours($total_hours) {
    if (!isset($total_hours) || $total_hours === null) {
        return 'N/A';
    }
    $hours = floor($total_hours);
    $minutes = round(($total_hours - $hours) * 60);
    return sprintf("%02d:%02d", $hours, $minutes);
}

// Handle timesheet submission
$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $action = $_POST['action'] ?? '';
    $date = date('Y-m-d'); // Always use current date
    $current_time = date('H:i'); // Current time in HH:MM format

    error_log("POST received: action=$action, date=$date, current_time=$current_time, user_id=$user_id, server_time=" . date('Y-m-d H:i:s'));

    if ($action === 'clock_in') {
        try {
            $pdo->beginTransaction();

            // Check if a timesheet entry already exists for the date
            $stmt = $pdo->prepare("SELECT id FROM timesheets WHERE employee_id = ? AND date = ?");
            $stmt->execute([$user_id, $date]);
            if ($stmt->fetch()) {
                $error_message = "A timesheet entry already exists for today.";
                error_log("Duplicate timesheet entry for user_id: $user_id, date: $date");
            } else {
                // Insert new timesheet entry
                $stmt = $pdo->prepare("
                    INSERT INTO timesheets (employee_id, date, clock_in)
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$user_id, $date, $current_time]);
                error_log("Clock-in recorded successfully for user_id: $user_id, date: $date, clock_in: $current_time");
                $success_message = "Clock-in recorded successfully at $current_time!";
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Timesheet clock-in failed: " . $e->getMessage());
            $error_message = "Error recording clock-in. Please try again.";
        }
    } elseif ($action === 'clock_out') {
        try {
            $pdo->beginTransaction();

            // Update existing timesheet entry
            $stmt = $pdo->prepare("
                SELECT id, clock_in FROM timesheets WHERE employee_id = ? AND date = ? AND clock_out IS NULL
            ");
            $stmt->execute([$user_id, $date]);
            $timesheet = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$timesheet) {
                $error_message = "No open timesheet found for today. Please clock in first.";
                error_log("No open timesheet for user_id: $user_id, date: $date");
            } else {
                // Calculate total hours
                $clock_in_time = new DateTime($timesheet['clock_in']);
                $clock_out_time = new DateTime($current_time);
                if ($clock_out_time <= $clock_in_time) {
                    $error_message = "Clock-out time must be after clock-in time.";
                    error_log("Invalid clock-out time: $current_time is not after clock-in time: {$timesheet['clock_in']}");
                } else {
                    $interval = $clock_in_time->diff($clock_out_time);
                    $total_minutes = ($interval->h * 60) + $interval->i;
                    $total_hours = $total_minutes / 60;

                    $stmt = $pdo->prepare("
                        UPDATE timesheets 
                        SET clock_out = ?, total_hours = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$current_time, $total_hours, $timesheet['id']]);
                    error_log("Clock-out recorded successfully for user_id: $user_id, date: $date, total_hours: $total_hours");
                    $success_message = "Clock-out recorded successfully at $current_time!";
                }
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Timesheet clock-out failed: " . $e->getMessage());
            $error_message = "Error recording clock-out. Please try again.";
        }
    } elseif ($action === 'download_excel') {
        // Fetch timesheet data for Excel
        $stmt = $pdo->prepare("
            SELECT date, clock_in, clock_out, total_hours
            FROM timesheets
            WHERE employee_id = ?
            ORDER BY date DESC
        ");
        $stmt->execute([$user_id]);
        $timesheets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Set headers for Excel download
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment;filename="Timesheet_' . date('Y-m-d') . '.xls"');
        header('Cache-Control: max-age=0');

        // Output Excel content
        echo "Date\tClock In\tClock Out\tTotal Hours\n";
        foreach ($timesheets as $row) {
            echo htmlspecialchars($row['date']) . "\t" .
                 htmlspecialchars($row['clock_in'] ?: 'N/A') . "\t" .
                 htmlspecialchars($row['clock_out'] ?: 'N/A') . "\t" .
                 htmlspecialchars(formatTotalHours($row['total_hours'])) . "\n";
        }
        exit();
    }
}

// Fetch employee's timesheet data
$search_date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : null;
$query = "SELECT id, date, clock_in, clock_out, total_hours
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
    <title>Employee Timesheet | HRMS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
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
            --border-radius: 16px;
            --shadow: 0 6px 20px rgba(0, 0, 0, 0.08);
            --shadow-hover: 0 8px 24px rgba(0, 0, 0, 0.12);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --focus-ring: 0 0 0 4px rgba(191, 162, 219, 0.2);
            --gradient: linear-gradient(145deg, var(--primary) 0%, var(--primary-dark) 100%);
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
            background: linear-gradient(145deg, var(--white) 0%, var(--light) 100%);
            color: var(--text);
            line-height: 1.6;
        }

        /* Sidebar Styles */
        .sidebar {
            width: 280px;
            background: var(--gradient);
            color: var(--white);
            box-shadow: var(--shadow);
            transition: var(--transition);
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
            transform: translateX(0);
        }

        .sidebar.collapsed {
            transform: translateX(-100%);
        }

        .sidebar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 24px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.15);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo-icon {
            font-size: 28px;
            color: var(--secondary);
        }

        .logo-text {
            font-family: 'Poppins', sans-serif;
            font-size: 26px;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        .toggle-btn {
            background: none;
            border: none;
            font-size: 18px;
            color: var(--secondary);
            cursor: pointer;
            padding: 8px;
            border-radius: 8px;
            transition: var(--transition);
        }

        .toggle-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            color: var(--white);
        }

        .sidebar-menu {
            padding: 20px 0;
        }

        .menu-item {
            display: flex;
            align-items: center;
            padding: 14px 24px;
            color: var(--white);
            text-decoration: none;
            transition: var(--transition);
            border-left: 4px solid transparent;
            position: relative;
            overflow: hidden;
        }

        .menu-item:hover {
            background: rgba(255, 255, 255, 0.1);
            border-left-color: var(--secondary);
            transform: translateX(5px);
        }

        .menu-item.active {
            background: rgba(255, 255, 255, 0.15);
            border-left-color: var(--secondary);
        }

        .menu-item i {
            margin-right: 14px;
            font-size: 20px;
        }

        .menu-text {
            font-size: 16px;
            font-weight: 500;
        }

        .menu-badge {
            margin-left: auto;
            background: var(--error);
            color: var(--white);
            border-radius: 12px;
            padding: 4px 10px;
            font-size: 12px;
            font-weight: 600;
        }

        /* Main Content Styles */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 30px;
            background: var(--white);
            transition: var(--transition);
        }

        /* Header Styles */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 20px;
            background: linear-gradient(145deg, var(--white) 0%, var(--light) 100%);
            box-shadow: var(--shadow);
            border-radius: var(--border-radius);
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .hamburger-menu {
            display: none;
            background: none;
            border: none;
            font-size: 20px;
            color: var(--primary);
            cursor: pointer;
            padding: 6px;
        }

        .header-title h1 {
            font-family: 'Poppins', sans-serif;
            font-size: 24px;
            color: var(--primary);
            font-weight: 700;
            margin-bottom: 4px;
        }

        .header-title p {
            font-size: 14px;
            color: var(--text-light);
            font-weight: 400;
        }

        .header-info {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .current-time {
            font-size: 14px;
            color: var(--text-light);
            font-weight: 500;
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            background: var(--primary);
            color: var(--white);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 16px;
            box-shadow: var(--shadow);
        }

        .user-profile span {
            font-size: 14px;
            font-weight: 500;
        }

        /* Content Styles */
        .content {
            max-width: 1200px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            transition: var(--transition);
            overflow: hidden;
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
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .card-header h2 {
            font-family: 'Poppins', sans-serif;
            font-size: 24px;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-body {
            padding: 30px;
            background: linear-gradient(135deg, var(--white) 0%, var(--light) 100%);
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
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 30px;
            align-items: center;
            justify-content: space-between;
        }

        .form-group {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
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
            min-width: 140px;
            position: relative;
            overflow: hidden;
        }

        .btn-primary {
            background: var(--primary);
            color: var(--white);
            box-shadow: var(--shadow);
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

        .btn-secondary {
            background: var(--secondary);
            color: var(--primary);
            box-shadow: var(--shadow);
        }

        .btn-secondary:hover {
            background: var(--primary-light);
            color: var(--white);
            box-shadow: var(--shadow-hover);
            transform: translateY(-2px);
        }

        .table-responsive {
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow);
            background: var(--white);
        }

        .table {
            margin-bottom: 0;
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        .table thead th {
            background: var(--primary);
            color: var(--white);
            padding: 14px;
            font-weight: 600;
            text-align: left;
            font-size: 15px;
            border-bottom: 2px solid var(--primary-dark);
        }

        .table tbody tr {
            transition: var(--transition);
        }

        .table tbody tr:hover {
            background: rgba(191, 162, 219, 0.1);
            transform: translateX(5px);
        }

        .table td {
            padding: 14px;
            vertical-align: middle;
            border-top: 1px solid var(--gray);
            font-size: 14px;
            color: var(--text);
        }

        .table td.text-center {
            text-align: center;
            color: var(--text-light);
            font-style: italic;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Responsive Styles */
        @media (max-width: 992px) {
            .main-content {
                margin-left: 0;
            }
            .sidebar {
                transform: translateX(-100%);
            }
            .sidebar.active {
                transform: translateX(0);
            }
            .hamburger-menu {
                display: block;
            }
            .content {
                padding: 0 15px;
                margin: 20px auto;
            }
            .card-body {
                padding: 20px;
            }
            .form-grid {
                flex-direction: column;
                align-items: flex-start;
            }
            .form-group {
                flex-direction: column;
                width: 100%;
            }
            .btn {
                width: 100%;
            }
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: row;
                align-items: center;
                padding: 10px 15px;
                gap: 10px;
            }
            .header-title h1 {
                font-size: 20px;
                margin-bottom: 2px;
            }
            .header-title p {
                font-size: 12px;
            }
            .header-info {
                gap: 12px;
            }
            .current-time {
                font-size: 12px;
            }
            .user-avatar {
                width: 32px;
                height: 32px;
                font-size: 14px;
            }
            .user-profile span {
                font-size: 12px;
            }
            .hamburger-menu {
                font-size: 18px;
                padding: 4px;
            }
            .table thead th {
                font-size: 14px;
                padding: 10px;
            }
            .table td {
                font-size: 13px;
                padding: 10px;
            }
        }

        @media (max-width: 576px) {
            .header {
                padding: 8px 12px;
            }
            .header-title h1 {
                font-size: 18px;
            }
            .header-title p {
                font-size: 11px;
            }
            .card-header h2 {
                font-size: 20px;
            }
            .btn {
                padding: 10px 16px;
                font-size: 13px;
            }
            .user-profile span {
                display: none;
            }
            .header-info {
                gap: 8px;
            }
            .current-time {
                font-size: 11px;
            }
            .table thead th {
                font-size: 13px;
            }
            .table td {
                font-size: 12px;
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
                <h1 class="logo-text">HRMS</h1>
            </div>
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
            <a href="employeeView.php" class="menu-item">
                <i class="fas fa-chart-line"></i>
                <span class="menu-text">Performance</span>
            </a>
            <a href="emergency.php" class="menu-item">
                <i class="fas fa-bell"></i>
                <span class="menu-text">Emergency</span>
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
            <button class="hamburger-menu" id="hamburgerMenu">
                <i class="fas fa-bars"></i>
            </button>
            <div class="header-title">
                <h1>Timesheet</h1>
                <p>Log your work hours</p>
            </div>
            <div class="header-info">
                <div class="current-time" id="currentTime"></div>
                <div class="user-profile">
                    <div class="user-avatar"><?php echo strtoupper(substr($user['first_name'] ?? $user['username'], 0, 1)); ?></div>
                    <span><?php echo htmlspecialchars($user['username'] . ' - ' . ucfirst($user['role'])); ?></span>
                </div>
            </div>
        </header>

        <!-- Content -->
        <div class="content">
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-clock"></i>My Timesheet</h2>
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
                        <input type="hidden" name="date" value="<?php echo date('Y-m-d'); ?>">
                        <div class="form-group">
                            <button type="submit" name="action" value="clock_in" class="btn btn-primary">
                                <i class="fas fa-sign-in-alt me-2"></i>Clock In
                            </button>
                            <button type="submit" name="action" value="clock_out" class="btn btn-primary">
                                <i class="fas fa-sign-out-alt me-2"></i>Clock Out
                            </button>
                            <button type="submit" name="action" value="download_excel" class="btn btn-secondary">
                                <i class="fas fa-file-excel me-2"></i>Download as Excel
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
                                    <th>Total Hours</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($timesheets) > 0): ?>
                                    <?php foreach ($timesheets as $row): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($row['date']); ?></td>
                                            <td><?php echo htmlspecialchars($row['clock_in'] ?: 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($row['clock_out'] ?: 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars(formatTotalHours($row['total_hours'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" class="text-center">No timesheet records found</td>
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
    <script>
        // Update current time
        function updateTime() {
            const timeElement = document.getElementById('currentTime');
            if (timeElement) {
                const now = new Date();
                timeElement.textContent = now.toLocaleString('en-US', {
                    hour: 'numeric',
                    minute: 'numeric',
                    hour12: true
                });
            }
        }
        setInterval(updateTime, 1000);
        updateTime();

        // Sidebar toggle
        const sidebar = document.querySelector('.sidebar');
        const hamburgerMenu = document.getElementById('hamburgerMenu');

        hamburgerMenu?.addEventListener('click', () => {
            sidebar.classList.toggle('active');
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 992 && sidebar.classList.contains('active')) {
                if (!sidebar.contains(e.target) && !hamburgerMenu.contains(e.target)) {
                    sidebar.classList.remove('active');
                }
            }
        });

        // Close sidebar on escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && window.innerWidth <= 992) {
                sidebar.classList.remove('active');
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