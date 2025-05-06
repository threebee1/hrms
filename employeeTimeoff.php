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
        return 0;
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
    
    foreach ($raw_requests as $row) {
        if (is_array($row) && !empty($row['id'])) {
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
    <title>Employee Time Off | HRMS</title>
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
            min-width: 44px;
            min-height: 44px;
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
            gap: 15px;
            margin-bottom: 30px;
            align-items: flex-start;
        }

        .form-group {
            flex: 1;
            min-width: 200px;
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

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
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

        .btn-outline-secondary {
            background: transparent;
            border: 1px solid var(--primary);
            color: var(--primary);
        }

        .btn-outline-secondary:hover {
            background: var(--light);
            color: var(--primary-light);
            box-shadow: var(--shadow-hover);
            transform: translateY(-2px);
        }

        .table-responsive {
            border-radius: var(--border-radius);
            overflow-x: auto;
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
            position: sticky;
            top: 0;
            z-index: 5;
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

        .badge {
            padding: 6px 12px;
            border-radius: var(--border-radius);
            color: #fff;
            font-size: 13px;
            font-weight: 500;
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
            padding: 8px;
            vertical-align: top;
            min-width: 80px;
            height: 100px;
            position: relative;
            transition: var(--transition);
        }

        .calendar th {
            background: var(--primary);
            color: var(--white);
            text-align: center;
            font-weight: 600;
            height: 40px;
        }

        .calendar td {
            background: var(--white);
        }

        .calendar .day-number {
            position: absolute;
            top: 4px;
            right: 4px;
            font-size: 14px;
            font-weight: 600;
            color: var(--text);
        }

        .calendar .day-event {
            font-size: 12px;
            margin: 2px 0;
            border-radius: 4px;
            padding: 2px 4px;
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
            padding: 4px 8px;
        }

        .balance-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 30px;
        }

        .balance-card {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 20px;
            text-align: center;
            box-shadow: var(--shadow);
            transition: var(--transition);
            flex: 1;
            min-width: 200px;
        }

        .balance-card:hover {
            box-shadow: var(--shadow-hover);
            transform: translateY(-4px);
        }

        .balance-card h4 {
            color: var(--primary);
            margin-bottom: 10px;
            font-size: 18px;
        }

        .balance-card p {
            margin: 5px 0;
            font-size: 14px;
        }

        .balance-card .text-muted {
            color: var(--text-light);
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
                min-width: 100%;
            }
            .btn {
                width: 100%;
                min-width: 0;
            }
            .balance-grid {
                flex-direction: column;
            }
            .calendar th, .calendar td {
                min-width: 40px;
                height: 50px;
                padding: 4px;
            }
            .calendar th {
                font-size: 11px;
            }
            .calendar .day-number {
                font-size: 10px;
            }
            .calendar .day-event {
                font-size: 9px;
                padding: 1px 2px;
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
            .balance-card h4 {
                font-size: 16px;
            }
            .balance-card p {
                font-size: 12px;
            }
            .calendar th, .calendar td {
                min-width: 35px;
                height: 45px;
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
                min-height: 44px;
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
            .calendar th, .calendar td {
                min-width: 30px;
                height: 40px;
            }
            .calendar .day-number {
                font-size: 9px;
            }
            .calendar .day-event {
                font-size: 8px;
            }
            .form-label {
                font-size: 12px;
            }
            .form-control, .form-select {
                padding: 8px;
                font-size: 12px;
            }
            .alert-success, .alert-error {
                font-size: 13px;
                padding: 12px;
            }
            .calendar-legend {
                gap: 8px;
            }
            .calendar-legend span {
                font-size: 12px;
            }
            .calendar-legend .badge {
                padding: 3px 6px;
                font-size: 11px;
            }
            .balance-card {
                min-width: 100%;
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
    <aside class="sidebar" role="navigation" aria-label="Main navigation">
        <div class="sidebar-header">
            <div class="logo">
                <i class="fas fa-user-tie logo-icon" aria-hidden="true"></i>
                <h1 class="logo-text">HRMS</h1>
            </div>
        </div>
        <nav class="sidebar-menu">
            <a href="Employeedashboard.php" class="menu-item">
                <i class="fas fa-home" aria-hidden="true"></i>
                <span class="menu-text">Dashboard</span>
            </a>
            <a href="Employeepersonal.php" class="menu-item">
                <i class="fas fa-user" aria-hidden="true"></i>
                <span class="menu-text">Personal Info</span>
            </a>
            <a href="Employeetimesheet.php" class="menu-item">
                <i class="fas fa-clock" aria-hidden="true"></i>
                <span class="menu-text">Timesheet</span>
            </a>
            <a href="Employeetimeoff.php" class="menu-item active">
                <i class="fas fa-calendar-minus" aria-hidden="true"></i>
                <span class="menu-text">Time Off</span>
            </a>
            <a href="Employeeinbox.php" class="menu-item">
                <i class="fas fa-inbox" aria-hidden="true"></i>
                <span class="menu-text">Inbox</span>
                <?php if ($unreadMessages > 0): ?>
                    <span class="menu-badge"><?php echo htmlspecialchars($unreadMessages); ?></span>
                <?php endif; ?>
            </a>
            <a href="employeeView.php" class="menu-item">
                <i class="fas fa-chart-line" aria-hidden="true"></i>
                <span class="menu-text">Performance</span>
            </a>
            <a href="login.html" class="menu-item">
                <i class="fas fa-sign-out-alt" aria-hidden="true"></i>
                <span class="menu-text">Logout</span>
            </a>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="main-content" role="main">
        <!-- Header -->
        <header class="header" role="banner">
            <button class="hamburger-menu" id="hamburgerMenu" aria-label="Open sidebar" aria-expanded="false" aria-controls="sidebar">
                <i class="fas fa-bars"></i>
            </button>
            <div class="header-title">
                <h1>Time Off</h1>
                <p>Manage leave</p>
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
                    <h2><i class="fas fa-calendar-minus" aria-hidden="true"></i>My Time Off</h2>
                </div>
                <div class="card-body">
                    <!-- Notifications -->
                    <?php if ($success_message): ?>
                        <div class="alert-success" role="alert">
                            <i class="fas fa-check-circle" aria-hidden="true"></i>
                            <?php echo htmlspecialchars($success_message); ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($error_message): ?>
                        <div class="alert-error" role="alert">
                            <i class="fas fa-exclamation-circle" aria-hidden="true"></i>
                            <?php echo htmlspecialchars($error_message); ?>
                        </div>
                    <?php endif; ?>

                    <!-- Leave Balance -->
                    <h3 class="mb-3">Leave Balance</h3>
                    <div class="balance-grid">
                        <?php foreach ($balance as $type => $data): ?>
                            <div class="balance-card">
                                <h4><?php echo ucfirst($type); ?></h4>
                                <p><?php echo $data['remaining']; ?> days left</p>
                                <p class="text-muted">Used: <?php echo $data['used']; ?> / Total: <?php echo $data['total']; ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Request Form -->
                    <h3 class="mb-3">Request Time Off</h3>
                    <form action="Employeetimeoff.php" method="POST" class="form-grid">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <div class="form-group">
                            <label for="leave_type" class="form-label">Leave Type</label>
                            <select class="form-select" id="leave_type" name="leave_type" required aria-required="true">
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
                            <textarea class="form-control" id="notes" name="notes" rows="3"></textarea>
                        </div>
                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-paper-plane me-2" aria-hidden="true"></i>Submit
                            </button>
                        </div>
                    </form>

                    <!-- Calendar -->
                    <h3 class="mb-3">Calendar</h3>
                    <div class="mb-2">
                        <button class="btn btn-outline-secondary" id="prevMonth" aria-label="Previous month"><i class="fas fa-chevron-left"></i></button>
                        <button class="btn btn-outline-secondary" id="currentMonth"><?php echo date('F Y'); ?></button>
                        <button class="btn btn-outline-secondary" id="nextMonth" aria-label="Next month"><i class="fas fa-chevron-right"></i></button>
                    </div>
                    <div class="calendar-legend">
                        <span><span class="badge vacation-badge"></span>Vacation</span>
                        <span><span class="badge sick-badge"></span>Sick</span>
                        <span><span class="badge personal-badge"></span>Personal</span>
                        <span><span class="badge bereavement-badge"></span>Bereavement</span>
                        <span><span class="badge other-badge"></span>Other</span>
                    </div>
                    <div class="calendar-container">
                        <table class="calendar" role="grid" aria-label="Time off calendar">
                            <thead>
                                <tr>
                                    <th scope="col">Sun</th><th scope="col">Mon</th><th scope="col">Tue</th><th scope="col">Wed</th><th scope="col">Thu</th><th scope="col">Fri</th><th scope="col">Sat</th>
                                </tr>
                            </thead>
                            <tbody id="calendarBody"></tbody>
                        </table>
                    </div>

                    <!-- Request History -->
                    <h3 class="mb-3 mt-4">History</h3>
                    <div class="table-responsive">
                        <table class="table" role="grid" aria-label="Time off request history">
                            <thead>
                                <tr>
                                    <th scope="col">Type</th>
                                    <th scope="col">Dates</th>
                                    <th scope="col">Days</th>
                                    <th scope="col">Notes</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Requested</th>
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
                                            <td><?php echo (isset($row['business_days']) ? $row['business_days'] : '0'); ?> days</td>
                                            <td><?php echo htmlspecialchars($row['notes'] ?: 'N/A'); ?></td>
                                            <td><span class="badge <?php echo htmlspecialchars($row['status'] ?? 'pending'); ?>-badge"><?php echo ucfirst(htmlspecialchars($row['status'] ?? 'Unknown')); ?></span></td>
                                            <td><?php echo !empty($row['created_at']) && strtotime($row['created_at']) ? date('M d, Y', strtotime($row['created_at'])) : 'N/A'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center">No requests found</td>
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
            hamburgerMenu.setAttribute('aria-expanded', sidebar.classList.contains('active'));
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 992 && sidebar.classList.contains('active')) {
                if (!sidebar.contains(e.target) && !hamburgerMenu.contains(e.target)) {
                    sidebar.classList.remove('active');
                    hamburgerMenu.setAttribute('aria-expanded', 'false');
                }
            }
        });

        // Close sidebar on escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && window.innerWidth <= 992) {
                sidebar.classList.remove('active');
                hamburgerMenu.setAttribute('aria-expanded', 'false');
            }
        });

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
                        html += `<td class="${hasEvents ? 'has-events' : ''}" role="gridcell"><div class="day-number">${day}</div>`;
                        if (hasEvents) {
                            calendarData[dateStr].forEach(event => {
                                html += `<div class="day-event ${event.type}-badge" title="${event.type}">${event.type}</div>`;
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
