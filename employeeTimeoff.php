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
    die('Internal server error');
}

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$user_id = $_SESSION['user_id'];

// Fetch user data
$stmt = $pdo->prepare("
    SELECT u.id, u.username, u.email, u.role, 
           e.first_name, e.last_name, e.department, e.position
    FROM users u
    JOIN employees e ON u.id = e.id
    WHERE u.id = ?
");
try {
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("User query failed for user_id $user_id: " . $e->getMessage());
    die('Internal server error');
}

if (!$user) {
    session_destroy();
    header('Location: login.php?error=user_not_found');
    exit();
}

// Fetch unread messages count
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as unread FROM messages WHERE recipient_id = ? AND is_read = 0");
    $stmt->execute([$user_id]);
    $unreadMessages = $stmt->fetch(PDO::FETCH_ASSOC)['unread'];
} catch (PDOException $e) {
    error_log("Unread messages query failed for user_id $user_id: " . $e->getMessage());
    $unreadMessages = 0;
}

// Function to calculate time off balance
function getTimeOffBalance($pdo, $employee_id, $year = null) {
    $year = $year ?: date('Y');
    $leave_types = ['vacation', 'sick', 'personal', 'bereavement', 'other'];
    $balances = array_fill_keys($leave_types, ['total' => 0, 'used' => 0, 'remaining' => 0]);
    
    // Set default allocations
    $default_allocations = [
        'vacation' => 15,
        'sick' => 10,
        'personal' => 5,
        'bereavement' => 3,
        'other' => 2
    ];
    
    // Get used days
    $used = array_fill_keys($leave_types, 0);
    $stmt = $pdo->prepare("SELECT leave_type, SUM(DATEDIFF(end_date, start_date) + 1) as days_used 
                           FROM time_off_requests 
                           WHERE employee_id = ? AND status = 'approved' AND YEAR(start_date) = ?
                           GROUP BY leave_type");
    $stmt->execute([$employee_id, $year]);
    
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (in_array($row['leave_type'], $leave_types)) {
            $used[$row['leave_type']] = (int)$row['days_used'];
        }
    }
    
    // Calculate remaining
    foreach ($leave_types as $type) {
        $balances[$type] = [
            'total' => $default_allocations[$type],
            'used' => $used[$type],
            'remaining' => max(0, $default_allocations[$type] - $used[$type])
        ];
    }
    
    return $balances;
}

// Function to calculate business days
function getBusinessDays($start_date, $end_date) {
    try {
        $start = new DateTime($start_date);
        $end = new DateTime($end_date);
        $end->modify('+1 day');
        $periods = new DatePeriod($start, new DateInterval('P1D'), $end);
        return iterator_count(array_filter(iterator_to_array($periods), fn($date) => $date->format('N') < 6));
    } catch (Exception $e) {
        return 0; // Return 0 if date parsing fails
    }
}

// Handle time off request submission
$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $leave_type = trim($_POST['leave_type'] ?? '');
    $start_date = trim($_POST['start_date'] ?? '');
    $end_date = trim($_POST['end_date'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    // Validate inputs
    if (!in_array($leave_type, ['vacation', 'sick', 'personal', 'bereavement', 'other'])) {
        $error_message = "Please select a valid leave type.";
    } elseif (empty($start_date) || empty($end_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
        $error_message = "Please select valid start and end dates.";
    } else {
        try {
            $start = new DateTime($start_date);
            $end = new DateTime($end_date);
            
            if ($end < $start) {
                $error_message = "End date cannot be before start date.";
            } elseif ($start < new DateTime()) {
                $error_message = "Cannot request time off in the past.";
            } else {
                // Check available balance
                $balance = getTimeOffBalance($pdo, $user_id);
                $days_requested = getBusinessDays($start_date, $end_date);
                
                if ($balance[$leave_type]['remaining'] < $days_requested) {
                    $error_message = "Insufficient leave balance for $leave_type leave.";
                } else {
                    // Insert new time off request
                    $stmt = $pdo->prepare("
                        INSERT INTO time_off_requests (employee_id, leave_type, start_date, end_date, notes, status, created_at)
                        VALUES (?, ?, ?, ?, ?, 'pending', NOW())
                    ");
                    $stmt->execute([$user_id, $leave_type, $start_date, $end_date, $notes]);
                    $success_message = "Time off request submitted successfully!";
                }
            }
        } catch (Exception $e) {
            error_log("Time off request failed for user_id $user_id: " . $e->getMessage());
            $error_message = "Error submitting request. Please try again.";
        }
    }
}

// Fetch employee's time off requests
$requests = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, leave_type, start_date, end_date, notes, status, created_at
        FROM time_off_requests
        WHERE employee_id = ?
        ORDER BY created_at DESC
    ");
    $stmt->execute([$user_id]);
    $raw_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Filter out invalid rows and calculate additional fields
    foreach ($raw_requests as $row) {
        if (is_array($row) && !empty($row['id'])) {
            // Skip rows with null or invalid dates
            if (empty($row['start_date']) || empty($row['end_date']) || empty($row['created_at'])) {
                error_log("Skipping invalid time off request for user_id $user_id: " . json_encode($row));
                continue;
            }
            $row['business_days'] = getBusinessDays($row['start_date'], $row['end_date']);
            try {
                $row['total_days'] = (new DateTime($row['end_date']))->diff(new DateTime($row['start_date']))->days + 1;
            } catch (Exception $e) {
                error_log("Date parsing failed for request ID {$row['id']}: " . $e->getMessage());
                continue;
            }
            $requests[] = $row;
        } else {
            error_log("Invalid row in time off requests for user_id $user_id: " . json_encode($row));
        }
    }
    error_log("Fetched time off requests for user_id $user_id: " . json_encode($requests));
} catch (PDOException $e) {
    error_log("Time off requests query failed for user_id $user_id: " . $e->getMessage());
    $requests = [];
}

// Fetch calendar data for approved requests
$current_month = date('Y-m');
try {
    $stmt = $pdo->prepare("
        SELECT start_date, end_date, leave_type
        FROM time_off_requests
        WHERE employee_id = ? AND status = 'approved'
        AND (DATE_FORMAT(start_date, '%Y-%m') = ? OR DATE_FORMAT(end_date, '%Y-%m') = ?)
    ");
    $stmt->execute([$user_id, $current_month, $current_month]);
    $calendar_data = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (empty($row['start_date']) || empty($row['end_date'])) {
            continue;
        }
        $start = new DateTime($row['start_date']);
        $end = new DateTime($row['end_date']);
        $end->modify('+1 day');
        foreach (new DatePeriod($start, new DateInterval('P1D'), $end) as $date) {
            $date_str = $date->format('Y-m-d');
            $calendar_data[$date_str][] = ['type' => $row['leave_type']];
        }
    }
} catch (PDOException $e) {
    error_log("Calendar data query failed for user_id $user_id: " . $e->getMessage());
    $calendar_data = [];
}

// Get time off balance
$balance = getTimeOffBalance($pdo, $user_id);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Time Off | HRPro</title>
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

        /* Sidebar Styles */
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

        /* Main Content Styles */
        .main-content {
            flex: 1;
            overflow-y: auto;
            padding-bottom: 60px;
            background-color: var(--white);
        }

        /* Header Styles */
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
            gap: 2px;
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

        /* Content Styles */
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

        .form-control, .form-select {
            width: 100%;
            padding: 12px;
            border: 2px solid var(--gray);
            border-radius: var(--border-radius);
            font-size: 14px;
            background: var(--white);
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.05);
            transition: var(--transition);
        }

        .form-control:focus, .form-select:focus {
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
            vertical-align: middle;
            border-top: 1px solid var(--gray);
        }

        .badge {
            padding: 6px 12px;
            border-radius: var(--border-radius);
            color: #fff;
        }

        .pending-badge { background: #ffc107; color: #000; }
        .approved-badge { background: var(--success); }
        .rejected-badge { background: var(--error); }
        .vacation-badge { background: #64b5f6; }
        .sick-badge { background: #ef5350; }
        .personal-badge { background: #9575cd; }
        .bereavement-badge { background: #4db6ac; }
        .other-badge { background: #ffb74d; }

        .calendar-container {
            overflow-x: auto;
        }

        .calendar {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .calendar th, .calendar td {
            border: 1px solid var(--gray);
            padding: 10px;
            vertical-align: top;
            min-width: 120px;
            height: 140px;
            position: relative;
            transition: var(--transition);
        }

        .calendar th {
            background: var(--primary);
            color: var(--white);
            text-align: center;
            font-weight: 600;
            height: 50px;
        }

        .calendar td {
            background: var(--white);
        }

        .calendar .day-number {
            position: absolute;
            top: 8px;
            right: 8px;
            font-size: 16px;
            font-weight: 600;
            color: var(--text);
        }

        .calendar .day-event {
            font-size: 14px;
            margin: 4px 0;
            border-radius: 4px;
            padding: 4px 6px;
            color: #fff;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            cursor: pointer;
            transition: var(--transition);
        }

        .calendar .day-event:hover {
            opacity: 0.9;
            transform: scale(1.02);
        }

        .calendar .has-events {
            background: rgba(191, 162, 219, 0.1);
        }

        .calendar .other-month {
            background: var(--light);
            color: var(--text-light);
        }

        .calendar-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 20px;
        }

        .calendar-legend span {
            display: flex;
            align-items: center;
            font-size: 14px;
        }

        .calendar-legend .badge {
            margin-right: 8px;
        }

        .balance-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .balance-card {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 20px;
            text-align: center;
            box-shadow: var(--shadow);
        }

        .balance-card h4 {
            color: var(--primary);
            margin-bottom: 10px;
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
            .form-grid, .balance-grid {
                grid-template-columns: 1fr;
            }
            .calendar th, .calendar td {
                min-width: 80px;
                height: 100px;
                padding: 5px;
            }
            .calendar .day-number {
                font-size: 14px;
            }
            .calendar .day-event {
                font-size: 12px;
                padding: 2px 4px;
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
            <a href="Employeetimesheet.php" class="menu-item">
                <i class="fas fa-clock"></i>
                <span class="menu-text">Timesheet</span>
            </a>
            <a href="Employeetimeoff.php" class="menu-item active">
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
            <a href="logout.php" class="menu-item">
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
                <h1>Time Off</h1>
                <p>Manage your leave requests</p>
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
                    <h2><i class="fas fa-calendar-minus me-2"></i>My Time Off</h2>
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

                    <!-- Leave Balance -->
                    <h3 class="mb-4">Leave Balance</h3>
                    <div class="balance-grid">
                        <?php foreach ($balance as $type => $data): ?>
                            <div class="balance-card">
                                <h4><?php echo ucfirst($type); ?></h4>
                                <p><?php echo $data['remaining']; ?> days remaining</p>
                                <p class="text-muted">Used: <?php echo $data['used']; ?> / Total: <?php echo $data['total']; ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Request Form -->
                    <h3 class="mb-4">Request Time Off</h3>
                    <form action="Employeetimeoff.php" method="POST" class="form-grid">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <div class="form-group">
                            <label for="leave_type" class="form-label">Leave Type</label>
                            <select class="form-select" id="leave_type" name="leave_type" required>
                                <option value="">Select Type</option>
                                <option value="vacation">Vacation</option>
                                <option value="sick">Sick</option>
                                <option value="personal">Personal</option>
                                <option value="bereavement">Bereavement</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="start_date" class="form-label">Start Date</label>
                            <input type="text" class="form-control flatpickr-input" id="start_date" name="start_date" required aria-required="true">
                        </div>
                        <div class="form-group">
                            <label for="end_date" class="form-label">End Date</label>
                            <input type="text" class="form-control flatpickr-input" id="end_date" name="end_date" required aria-required="true">
                        </div>
                        <div class="form-group">
                            <label for="notes" class="form-label">Notes (Optional)</label>
                            <textarea class="form-control" id="notes" name="notes" rows="4"></textarea>
                        </div>
                        <div class="form-group" style="display: flex; align-items: flex-end;">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-paper-plane me-2"></i>Submit Request
                            </button>
                        </div>
                    </form>

                    <!-- Calendar -->
                    <h3 class="mb-4">Time Off Calendar</h3>
                    <div class="mb-3">
                        <button class="btn btn-outline-secondary" id="prevMonth"><i class="fas fa-chevron-left"></i></button>
                        <button class="btn btn-outline-secondary" id="currentMonth"><?php echo date('F Y'); ?></button>
                        <button class="btn btn-outline-secondary" id="nextMonth"><i class="fas fa-chevron-right"></i></button>
                    </div>
                    <div class="calendar-legend">
                        <span><span class="badge vacation-badge"></span>Vacation</span>
                        <span><span class="badge sick-badge"></span>Sick</span>
                        <span><span class="badge personal-badge"></span>Personal</span>
                        <span><span class="badge bereavement-badge"></span>Bereavement</span>
                        <span><span class="badge other-badge"></span>Other</span>
                    </div>
                    <div class="calendar-container">
                        <table class="calendar">
                            <thead>
                                <tr>
                                    <th>Sun</th><th>Mon</th><th>Tue</th><th>Wed</th><th>Thu</th><th>Fri</th><th>Sat</th>
                                </tr>
                            </thead>
                            <tbody id="calendarBody"></tbody>
                        </table>
                    </div>

                    <!-- Request History -->
                    <h3 class="mb-4 mt-5">Request History</h3>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>Dates</th>
                                    <th>Duration</th>
                                    <th>Notes</th>
                                    <th>Status</th>
                                    <th>Requested</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($requests) > 0): ?>
                                    <?php foreach ($requests as $row): ?>
                                        <tr>
                                            <td><span class="badge <?php echo htmlspecialchars($row['leave_type'] ?? 'other'); ?>-badge"><?php echo ucfirst(htmlspecialchars($row['leave_type'] ?? 'Unknown')); ?></span></td>
                                            <td>
                                                <?php 
                                                $start_date = !empty($row['start_date']) && strtotime($row['start_date']) ? date('M d, Y', strtotime($row['start_date'])) : 'N/A';
                                                $end_date = !empty($row['end_date']) && strtotime($row['end_date']) ? date('M d, Y', strtotime($row['end_date'])) : 'N/A';
                                                echo "$start_date - $end_date";
                                                ?>
                                            </td>
                                            <td><?php echo (isset($row['business_days']) ? $row['business_days'] : '0'); ?> business days</td>
                                            <td><?php echo htmlspecialchars($row['notes'] ?: 'N/A'); ?></td>
                                            <td><span class="badge <?php echo htmlspecialchars($row['status'] ?? 'pending'); ?>-badge"><?php echo ucfirst(htmlspecialchars($row['status'] ?? 'Unknown')); ?></span></td>
                                            <td><?php echo !empty($row['created_at']) && strtotime($row['created_at']) ? date('M d, Y', strtotime($row['created_at'])) : 'N/A'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4">No time off requests found</td>
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

        // Flatpickr for Dates
        flatpickr('#start_date, #end_date', {
            dateFormat: 'Y-m-d',
            minDate: 'today',
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

        // Calendar Rendering
        let currentDate = new Date();
        const calendarData = <?php echo json_encode($calendar_data); ?>;

        function renderCalendar() {
            const year = currentDate.getFullYear();
            const month = currentDate.getMonth();
            const firstDay = new Date(year, month, 1).getDay();
            const daysInMonth = new Date(year, month + 1, 0).getDate();
            let html = '';
            let day = 1;
            
            for (let i = 0; i < 6; i++) {
                html += '<tr>';
                for (let j = 0; j < 7; j++) {
                    if (i === 0 && j < firstDay || day > daysInMonth) {
                        html += '<td class="other-month"></td>';
                    } else {
                        const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                        const hasEvents = calendarData[dateStr]?.length > 0;
                        html += `<td class="${hasEvents ? 'has-events' : ''}"><div class="day-number">${day}</div>`;
                        if (hasEvents) {
                            calendarData[dateStr].forEach(event => {
                                html += `<div class="day-event ${event.type}-badge">${event.type}</div>`;
                            });
                        }
                        html += '</td>';
                        day++;
                    }
                }
                html += '</tr>';
                if (day > daysInMonth) break;
            }
            
            document.getElementById('calendarBody').innerHTML = html;
            document.getElementById('currentMonth').textContent = currentDate.toLocaleString('en-US', { month: 'long', year: 'numeric' });
        }

        document.getElementById('prevMonth').addEventListener('click', () => {
            currentDate.setMonth(currentDate.getMonth() - 1);
            renderCalendar();
        });

        document.getElementById('nextMonth').addEventListener('click', () => {
            currentDate.setMonth(currentDate.getMonth() + 1);
            renderCalendar();
        });

        renderCalendar();
    </script>
</body>
</html>