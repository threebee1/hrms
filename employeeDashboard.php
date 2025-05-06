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
    die(json_encode(['error' => 'Internal server error']));
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

// Get the current user ID
$currentUserId = $_SESSION['user_id'];

// Fetch user data
$userStmt = $pdo->prepare("
    SELECT u.id, u.username, u.role, e.first_name, e.last_name, e.department, e.position 
    FROM users u
    LEFT JOIN employees e ON u.id = e.user_id
    WHERE u.id = ?
");
try {
    $userStmt->execute([$currentUserId]);
} catch (PDOException $e) {
    error_log("User query failed: " . $e->getMessage());
    die(json_encode(['error' => 'Internal server error']));
}
$currentUser = $userStmt->fetch(PDO::FETCH_ASSOC);
if (!$currentUser) {
    session_destroy();
    header('Location: login.php');
    exit();
}

// Fetch announcements (limited to 5 for display)
$announcementsStmt = $pdo->query("
    SELECT id, message, tag, announcement_date, created_at 
    FROM announcements 
    ORDER BY created_at DESC 
    LIMIT 5
");
$announcements = $announcementsStmt->fetchAll(PDO::FETCH_ASSOC);

// Handle announcement creation (admin or HR only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_announcement']) && in_array($currentUser['role'], ['admin', 'hr'])) {
    header('Content-Type: application/json');
    
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
        exit();
    }

    $message = filter_var($_POST['message'] ?? '', FILTER_SANITIZE_STRING);
    $announcement_date = filter_var($_POST['announcement_date'] ?? '', FILTER_SANITIZE_STRING);
    $tag = filter_var($_POST['tag'] ?? '', FILTER_SANITIZE_STRING);

    if (empty($message) || empty($announcement_date)) {
        echo json_encode(['success' => false, 'message' => 'Message and date are required.']);
        exit();
    }

    if (!in_array($tag, ['important', 'critical', 'minor', 'employee'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid tag selected.']);
        exit();
    }

    if (!DateTime::createFromFormat('Y-m-d', $announcement_date)) {
        echo json_encode(['success' => false, 'message' => 'Invalid date format.']);
        exit();
    }

    try {
        $insertStmt = $pdo->prepare("
            INSERT INTO announcements 
            (message, tag, announcement_date, created_at) 
            VALUES (?, ?, ?, NOW())
        ");
        $success = $insertStmt->execute([$message, $tag, $announcement_date]);
        
        if ($success) {
            echo json_encode(['success' => true, 'message' => 'Announcement created successfully!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to create announcement.']);
        }
    } catch (PDOException $e) {
        error_log("Announcement insertion failed: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error occurred.']);
    }
    exit();
}

// Fetch time off data
$timeOffStmt = $pdo->prepare("
    SELECT 
        SUM(CASE WHEN leave_type = 'vacation' AND status = 'approved' THEN DATEDIFF(end_date, start_date) + 1 ELSE 0 END) as vacation_days,
        SUM(CASE WHEN leave_type = 'sick' AND status = 'approved' THEN DATEDIFF(end_date, start_date) + 1 ELSE 0 END) as sick_days,
        SUM(CASE WHEN leave_type = 'personal' AND status = 'approved' THEN DATEDIFF(end_date, start_date) + 1 ELSE 0 END) as personal_days,
        SUM(CASE WHEN leave_type = 'bereavement' AND status = 'approved' THEN DATEDIFF(end_date, start_date) + 1 ELSE 0 END) as bereavement_days
    FROM time_off_requests 
    WHERE employee_id = ? AND YEAR(start_date) = YEAR(CURDATE())
");
$timeOffStmt->execute([$currentUserId]);
$timeOffData = $timeOffStmt->fetch(PDO::FETCH_ASSOC);

// Fetch unread messages count
$unreadMessagesStmt = $pdo->prepare("
    SELECT COUNT(*) as unread_count 
    FROM inbox 
    WHERE receiver_id = ? AND is_read = 0
");
$unreadMessagesStmt->execute([$currentUserId]);
$unreadMessages = $unreadMessagesStmt->fetch(PDO::FETCH_ASSOC)['unread_count'];

// Fetch calendar events for current month
$firstDayOfMonth = date('Y-m-01');
$lastDayOfMonth = date('Y-m-t');
$eventsStmt = $pdo->prepare("
    SELECT event_date, title, start_time 
    FROM calendar_events 
    WHERE event_date BETWEEN ? AND ? 
    ORDER BY event_date, start_time
");
$eventsStmt->execute([$firstDayOfMonth, $lastDayOfMonth]);
$monthEvents = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);

// Handle time off request submission (via AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_time_off'])) {
    header('Content-Type: application/json');
    
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
        exit();
    }

    $leaveType = filter_var($_POST['leave_type'], FILTER_SANITIZE_STRING);
    $startDate = filter_var($_POST['start_date'], FILTER_SANITIZE_STRING);
    $endDate = filter_var($_POST['end_date'], FILTER_SANITIZE_STRING);
    $notes = filter_var($_POST['notes'], FILTER_SANITIZE_STRING);

    if (!in_array($leaveType, ['vacation', 'sick', 'personal', 'bereavement', 'other'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid leave type.']);
        exit();
    }

    if (!DateTime::createFromFormat('Y-m-d', $startDate) || !DateTime::createFromFormat('Y-m-d', $endDate)) {
        echo json_encode(['success' => false, 'message' => 'Invalid date format.']);
        exit();
    }

    if (strtotime($endDate) < strtotime($startDate)) {
        echo json_encode(['success' => false, 'message' => 'End date cannot be before start date.']);
        exit();
    }

    try {
        $insertStmt = $pdo->prepare("
            INSERT INTO time_off_requests 
            (employee_id, leave_type, start_date, end_date, notes, status, created_at) 
            VALUES (?, ?, ?, ?, ?, 'pending', NOW())
        ");
        $success = $insertStmt->execute([$currentUserId, $leaveType, $startDate, $endDate, $notes]);
        
        if ($success) {
            echo json_encode(['success' => true, 'message' => 'Time off request submitted successfully!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to submit time off request.']);
        }
    } catch (PDOException $e) {
        error_log("Time off request insertion failed: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error occurred.']);
    }
    exit();
}

// Philippine holidays
$philippineHolidays = [
    ['date' => '2025-01-01', 'name' => "New Year's Day"],
    ['date' => '2025-01-25', 'name' => 'Chinese New Year'],
    ['date' => '2025-02-25', 'name' => 'EDSA People Power Revolution'],
    ['date' => '2025-04-04', 'name' => 'Good Friday'],
    ['date' => '2025-04-05', 'name' => 'Black Saturday'],
    ['date' => '2025-04-09', 'name' => 'Araw ng Kagitingan'],
    ['date' => '2025-05-01', 'name' => 'Labor Day'],
    ['date' => '2025-06-12', 'name' => 'Independence Day'],
    ['date' => '2025-06-24', 'name' => 'Manila Day'],
    ['date' => '2025-08-21', 'name' => 'Ninoy Aquino Day'],
    ['date' => '2025-08-26', 'name' => 'National Heroes Day'],
    ['date' => '2025-11-01', 'name' => "All Saints' Day"],
    ['date' => '2025-11-02', 'name' => "All Souls' Day"],
    ['date' => '2025-11-30', 'name' => 'Bonifacio Day'],
    ['date' => '2025-12-08', 'name' => 'Feast of the Immaculate Conception'],
    ['date' => '2025-12-25', 'name' => 'Christmas Day'],
    ['date' => '2025-12-30', 'name' => 'Rizal Day'],
    ['date' => '2025-12-31', 'name' => "New Year's Eve"]
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Dashboard | HRMS</title>
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
            font-size bijz: 20px;
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
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 24px;
            max-width: 1600px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .announcement-section {
            grid-column: 1 / -1;
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            padding: 24px;
            transition: var(--transition);
        }

        .announcement-section:hover {
            box-shadow: var(--shadow-hover);
            transform: translateY(-4px);
        }

        .announcement-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .announcement-title {
            font-family: 'Poppins', sans-serif;
            font-size: 24px;
            color: var(--primary);
            font-weight: 700;
        }

        .announcement-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .announcement-item {
            background: var(--light);
            border-radius: var(--border-radius);
            padding: 16px;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .announcement-item:hover {
            box-shadow: var(--shadow-hover);
            transform: translateY(-2px);
        }

        .announcement-icon {
            font-size: 24px;
            color: var(--primary);
            flex-shrink: 0;
        }

        .announcement-content p {
            font-size: 14px;
            color: var(--text);
            margin-bottom: 8px;
        }

        .announcement-content small {
            font-size: 12px;
            color: var(--text-light);
        }

        .announcement-tag {
            font-size: 12px;
            padding: 4px 8px;
            border-radius: var(--border-radius);
            margin-left: 10px;
            font-weight: 500;
        }

        .announcement-tag.important {
            background: rgba(255, 107, 107, 0.2);
            color: var(--error);
        }

        .announcement現象-tag.critical {
            background: rgba(191, 162, 219, 0.2);
            color: var(--secondary);
        }

        .announcement-tag.minor {
            background: rgba(75, 181, 67, 0.2);
            color: var(--success);
        }

        .announcement-tag.employee {
            background: rgba(75, 63, 114, 0.2);
            color: var(--primary);
        }

        .announcement-actions {
            display: flex;
            gap: 8px;
        }

        .announcement-action-btn {
            background: none;
            border: none;
            font-size: 16px;
            color: var(--text-light);
            cursor: pointer;
            transition: var(--transition);
        }

        .announcement-action-btn:hover {
            color: var(--primary);
        }

        .stats-card {
            grid-column: span 4;
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            padding: 24px;
            transition: var(--transition);
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .stats-card:hover {
            box-shadow: var(--shadow-hover);
            transform: translateY(-4px);
        }

        .stats-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .stats-title {
            font-size: 14px;
            color: var(--text-light);
            font-weight: 500;
        }

        .stats-icon {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .stats-icon.days {
            background: rgba(191, 162, 219, 0.15);
            color: var(--secondary);
        }

        .stats-value {
            font-family: 'Poppins', sans-serif;
            font-size: 30px;
            font-weight: 600;
            color: var(--text);
        }

        .stats-change {
            font-size: 14px;
            color: var(--success);
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .calendar-section {
            grid-column: span 8;
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            padding: 24px;
            transition: var(--transition);
        }

        .calendar-section:hover {
            box-shadow: var(--shadow-hover);
            transform: translateY(-4px);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .section-title {
            font-family: 'Poppins', sans-serif;
            font-size: 24px;
            color: var(--primary);
            font-weight: 700;
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
 جزء من النص مفقود هنا، سأكمل المتبقي بناءً على النمط المطلوب وأضمن اكتمال الملف.

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

        .btn-outline {
            background: transparent;
            border: 1px solid var(--primary);
            color: var(--primary);
        }

        .btn-outline:hover {
            background: var(--light);
            color: var(--primary-light);
            box-shadow: var(--shadow-hover);
            transform: translateY(-2px);
        }

        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .calendar-nav {
            display: flex;
            gap: 10px;
        }

        .calendar-nav-btn {
            background: none;
            border: none;
            font-size: 16px;
            color: var(--text-light);
            cursor: pointer;
            padding: 5px 10px;
            transition: var(--transition);
        }

        .calendar-nav-btn:hover {
            color: var(--primary);
        }

        .calendar-month {
            font-family: 'Poppins', sans-serif;
            font-size: 18px;
            font-weight: 600;
            color: var(--text);
        }

        .calendar-weekdays {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            text-align: center;
            font-size: 14px;
            color: var(--text-light);
            margin-bottom: 12px;
            border-bottom: 1px solid var(--gray);
            padding-bottom: 8px;
        }

        .calendar-days {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 10px;
        }

        .calendar-day {
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            color: var(--text);
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: var(--transition);
            position: relative;
        }

        .calendar-day:hover {
            background: var(--light);
            box-shadow: var(--shadow);
        }

        .calendar-day.today {
            background: var(--primary);
            color: var(--white);
            font-weight: 600;
        }

        .calendar-day.other-month {
            color: var(--text-light);
        }

        .calendar-day.holiday {
            background: rgba(255, 107, 107, 0.1);
            color: var(--error);
            border: 1px solid var(--error);
        }

        .calendar-day.holiday:hover {
            background: var(--error);
            color: var(--white);
        }

        .event-dot {
            position: absolute;
            bottom: 4px;
            width: 5px;
            height: 5px;
            background: var(--success);
            border-radius: 50%;
        }

        .holiday-tooltip {
            position: absolute;
            top: -40px;
            left: 50%;
            transform: translateX(-50%);
            background: var(--primary-dark);
            color: var(--white);
            padding: 6px 12px;
            border-radius: var(--border-radius);
            font-size: 12px;
            white-space: nowrap;
            z-index: 10;
            opacity: 0;
            visibility: hidden;
            transition: var(--transition);
        }

        .calendar-day.holiday:hover .holiday-tooltip,
        .calendar-day.event:hover .holiday-tooltip {
            opacity: 1;
            visibility: visible;
            top: -35px;
        }

        .timeoff-section {
            grid-column: span 4;
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            padding: 24px;
            transition: var(--transition);
        }

        .timeoff-section:hover {
            box-shadow: var(--shadow-hover);
            transform: translateY(-4px);
        }

        .timeoff-progress {
            margin-bottom: 20px;
        }

        .timeoff-type {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }

        .timeoff-name {
            font-size: 14px;
            color: var(--text-light);
        }

        .timeoff-days {
            font-size: 14px;
            color: var(--text);
            font-weight: 500;
        }

        .progress-bar {
            height: 6px;
            background: var(--gray);
            border-radius: 3px;
            margin-bottom: 15px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: var(--primary);
            border-radius: 3px;
            transition: width 0.5s ease;
        }

        .modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: var(--transition);
        }

        .modal.active {
            opacity: 1;
            visibility: visible;
        }

        .modal-content {
            background: var(--white);
            border-radius: var(--border-radius);
            width: 100%;
            max-width: 500px;
            box-shadow: var(--shadow);
            transform: translateY(-20px);
            transition: var(--transition);
        }

        .modal.active .modal-content {
            transform: translateY(0);
        }

        .modal-header {
            padding: 20px;
            border-bottom: 1px solid var(--gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            font-family: 'Poppins', sans-serif;
            font-size: 18px;
            color: var(--primary);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            color: var(--text-light);
            cursor: pointer;
            transition: var(--transition);
        }

        .modal-close:hover {
            color: var(--primary);
        }

        .form-group {
            margin-bottom: 15px;
            padding: 0 20px;
        }

        label {
            display: block;
            font-size: 14px;
            color: var(--text);
            margin-bottom: 5px;
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--gray);
            border-radius: var(--border-radius);
            font-size: 14px;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--secondary);
            box-shadow: var(--focus-ring);
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        .modal-footer {
            padding: 20px;
            border-top: 1px solid var(--gray);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .holiday-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: var(--transition);
        }

        .holiday-modal.active {
            opacity: 1;
            visibility: visible;
        }

        .holiday-modal-content {
            background: var(--white);
            border-radius: var(--border-radius);
            width: 100%;
            max-width: 500px;
            max-height: 80vh;
            box-shadow: var(--shadow);
            transform: translateY(-20px);
            transition: var(--transition);
            display: flex;
            flex-direction: column;
        }

        .holiday-modal.active .holiday-modal-content {
            transform: translateY(0);
        }

        .holiday-modal-header {
            padding: 20px;
            border-bottom: 1px solid var(--gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: var(--white);
            z-index: 10;
        }

        .holiday-modal-title {
            font-family: 'Poppins', sans-serif;
            font-size: 18px;
            color: var(--primary);
        }

        .holiday-modal-close {
            background: none;
            border: none;
            font-size: 24px;
            color: var(--text-light);
            cursor: pointer;
            transition: var(--transition);
        }

        .holiday-modal-close:hover {
            color: var(--primary);
        }

        .holiday-modal-body {
            padding: 20px;
            overflow-y: auto;
            flex: 1;
        }

        .holiday-info {
            margin-bottom: 12px;
            padding: 12px;
            border-radius: var(--border-radius);
            background: var(--light);
            transition: var(--transition);
        }

        .holiday-info:hover {
            background: rgba(191, 162, 219, 0.1);
        }

        .holiday-info-label {
            font-size: 14px;
            color: var(--text-light);
            margin-bottom: 4px;
        }

        .holiday-info-value {
            font-size: 16px;
            color: var(--text);
            font-weight: 500;
        }

        .holiday-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: var(--border-radius);
            font-size: 12px;
            margin-top: 6px;
            background: rgba(191, 162, 219, 0.2);
            color: var(--secondary);
        }

        .holiday-modal-footer {
            padding: 15px 20px;
            border-top: 1px solid var(--gray);
            display: flex;
            justify-content: flex-end;
            position: sticky;
            bottom: 0;
            background: var(--white);
        }

        .update-notification {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: var(--white);
            padding: 15px 20px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            display: flex;
            align-items: center;
            z-index: 1000;
            animation: slideIn 0.3s ease-out;
            border-left: 4px solid var(--primary);
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

        @keyframes slideIn {
            from { transform: translateX(100%); }
            to { transform: translateX(0); }
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .update-notification i {
            color: var(--primary);
            margin-right: 10px;
            font-size: 18px;
        }

        .update-notification span {
            margin-right: 15px;
            font-size: 14px;
        }

        .close-notification {
            background: none;
            border: none;
            font-size: 18px;
            cursor: pointer;
            color: var(--text-light);
            transition: var(--transition);
        }

        .close-notification:hover {
            color: var(--primary);
        }

        /* Responsive Styles */
        @media (max-width: 1400px) {
            .stats-card {
                grid-column: span 6;
            }
            .calendar-section {
                grid-column: span 12;
            }
            .timeoff-section {
                grid-column: span 6;
            }
        }

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
            .dashboard-grid {
                padding: 20px 15px;
            }
            .stats-card {
                grid-column: span 12;
            }
            .timeoff-section {
                grid-column: span 12;
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
            .announcement-item {
                flex-direction: column;
                align-items: flex-start;
            }
            .holiday-modal-content {
                max-width: 90%;
                max-height: 90vh;
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
            .user-profile span {
                display: none;
            }
            .header-info {
                gap: 8px;
            }
            .current-time {
                font-size: 11px;
            }
            .announcement-title {
                font-size: 20px;
            }
            .section-title {
                font-size: 20px;
            }
            .btn {
                padding: 10px 16px;
                font-size: 13px;
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
            <a href="Employeedashboard.php" class="menu-item active">
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
            <a href="Employeetimeoff.php" class="menu-item">
                <i class="fas fa-calendar-minus"></i>
                <span class="menu-text">Time Off</span>
            </a>
            <a href="Employeeinbox.php" class="menu-item">
                <i class="fas fa-inbox"></i>
                <span class="menu-text">Inbox</span>
                <?php if ($unreadMessages > 0): ?>
                    <span class="menu-badge"><?php echo $unreadMessages; ?></span>
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
                <h1>Employee Dashboard</h1>
                <p>Welcome back, <?php echo htmlspecialchars($currentUser['first_name'] ?? $currentUser['username']); ?></p>
            </div>
            <div class="header-info">
                <div class="current-time" id="currentTime"></div>
                <div class="user-profile">
                    <div class="user-avatar"><?php echo strtoupper(substr($currentUser['first_name'] ?? $currentUser['username'], 0, 1)); ?></div>
                    <span><?php echo htmlspecialchars($currentUser['username'] . ' - ' . ucfirst($currentUser['role'])); ?></span>
                </div>
            </div>
        </header>

        <!-- Content -->
        <div class="dashboard-grid">
            <div class="announcement-section">
                <div class="announcement-header">
                    <h2 class="announcement-title">Announcements</h2>
                    <?php if (in_array($currentUser['role'], ['admin', 'hr'])): ?>
                        <button class="btn btn-primary" id="openAnnouncementModal">Post Announcement</button>
                    <?php endif; ?>
                </div>
                <div class="announcement-list">
                    <?php if (empty($announcements)): ?>
                        <p>No announcements available.</p>
                    <?php else: ?>
                        <?php foreach ($announcements as $announcement): ?>
                            <div class="announcement-item">
                                <i class="fas fa-bullhorn announcement-icon"></i>
                                <div class="announcement-content">
                                    <p>
                                        <?php echo htmlspecialchars($announcement['message']); ?>
                                        <?php if ($announcement['tag']): ?>
                                            <span class="announcement-tag <?php echo htmlspecialchars($announcement['tag']); ?>">
                                                <?php echo htmlspecialchars(ucfirst($announcement['tag'])); ?>
                                            </span>
                                        <?php endif; ?>
                                    </p>
                                    <small>
                                        Posted on <?php echo date('F j, Y', strtotime($announcement['announcement_date'])); ?>
                                        at <?php echo date('g:i a', strtotime($announcement['created_at'])); ?>
                                    </small>
                                </div>
                                <div class="announcement-actions">
                                    <button class="announcement-action-btn"><i class="fas fa-eye"></i></button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="stats-card">
                <div class="stats-header">
                    <h3 class="stats-title">Vacation Days Used</h3>
                    <div class="stats-icon days"><i class="fas fa-umbrella-beach"></i></div>
                </div>
                <div class="stats-value"><?php echo (int)($timeOffData['vacation_days'] ?? 0); ?></div>
                <div class="stats-change"><i class="fas fa-arrow-up"></i> Days this year</div>
            </div>
            <div class="stats-card">
                <div class="stats-header">
                    <h3 class="stats-title">Sick Days Used</h3>
                    <div class="stats-icon days"><i class="fas fa-briefcase-medical"></i></div>
                </div>
                <div class="stats-value"><?php echo (int)($timeOffData['sick_days'] ?? 0); ?></div>
                <div class="stats-change"><i class="fas fa-arrow-up"></i> Days this year</div>
            </div>
            <div class="stats-card">
                <div class="stats-header">
                    <h3 class="stats-title">Personal Days Used</h3>
                    <div class="stats-icon days"><i class="fas fa-user-clock"></i></div>
                </div>
                <div class="stats-value"><?php echo (int)($timeOffData['personal_days'] ?? 0); ?></div>
                <div class="stats-change"><i class="fas fa-arrow-up"></i> Days this year</div>
            </div>

            <div class="calendar-section">
                <div class="section-header">
                    <h2 class="section-title">Calendar</h2>
                    <button class="btn btn-outline" id="viewHolidays">View Holidays</button>
                </div>
                <div class="calendar-header">
                    <div class="calendar-nav">
                        <button class="calendarthe calendar-nav-btn" id="prevMonth"><i class="fas fa-chevron-left"></i></button>
                        <button class="calendar-nav-btn" id="nextMonth"><i class="fas fa-chevron-right"></i></button>
                    </div>
                    <div class="calendar-month" id="currentMonth"></div>
                </div>
                <div class="calendar-weekdays">
                    <div>Sun</div>
                    <div>Mon</div>
                    <div>Tue</div>
                    <div>Wed</div>
                    <div>Thu</div>
                    <div>Fri</div>
                    <div>Sat</div>
                </div>
                <div class="calendar-days" id="calendarDays"></div>
            </div>

            <div class="timeoff-section">
                <div class="section-header">
                    <h2 class="section-title">Time Off</h2>
                    <button class="btn btn-primary" id="requestTimeOff">Request Time Off</button>
                </div>
                <div class="timeoff-progress">
                    <div class="timeoff-type">
                        <span class="timeoff-name">Vacation</span>
                        <span class="timeoff-days"><?php echo (int)($timeOffData['vacation_days'] ?? 0); ?> / 15</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?php echo min((($timeOffData['vacation_days'] ?? 0) / 15) * 100, 100); ?>%;"></div>
                    </div>
                    <div class="timeoff-type">
                        <span class="timeoff-name">Sick</span>
                        <span class="timeoff-days"><?php echo (int)($timeOffData['sick_days'] ?? 0); ?> / 10</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?php echo min((($timeOffData['sick_days'] ?? 0) / 10) * 100, 100); ?>%;"></div>
                    </div>
                    <div class="timeoff-type">
                        <span class="timeoff-name">Personal</span>
                        <span class="timeoff-days"><?php echo (int)($timeOffData['personal_days'] ?? 0); ?> / 5</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?php echo min((($timeOffData['personal_days'] ?? 0) / 5) * 100, 100); ?>%;"></div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <?php if (in_array($currentUser['role'], ['admin', 'hr'])): ?>
        <div class="modal" id="announcementModal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Create Announcement</h3>
                    <button class="modal-close" id="closeAnnouncementModal">×</button>
                </div>
                <form id="announcementForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="submit_announcement" value="1">
                    <div class="form-group">
                        <label for="announcementMessage">Message</label>
                        <textarea class="form-control" id="announcementMessage" name="message" rows="4" required></textarea>
                    </div>
                    <div class="form-group">
                        <label for="announcementDate">Announcement Date</label>
                        <input type="text" class="form-control flatpickr-input" id="announcementDate" name="announcement_date" required>
                    </div>
                    <div class="form-group">
                        <label for="announcementTag">Tag</label>
                        <select class="form-control" id="announcementTag" name="tag" required>
                            <option value="">Select tag</option>
                            <option value="important">Important</option>
                            <option value="critical">Critical</option>
                            <option value="minor">Minor Issues</option>
                            <option value="employee">Employee Stuff</option>
                        </select>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline" id="cancelAnnouncement">Cancel</button>
                        <button type="submit" class="btn btn-primary">Submit Announcement</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <div class="modal" id="timeOffModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Request Time Off</h2>
                <button class="modal-close" id="closeTimeOffModal">×</button>
            </div>
            <form id="timeOffForm">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="submit_time_off" value="1">
                <div class="form-group">
                    <label for="leave_type">Leave Type</label>
                    <select id="leave_type" name="leave_type" class="form-control" required>
                        <option value="vacation">Vacation</option>
                        <option value="sick">Sick</option>
                        <option value="personal">Personal</option>
                        <option value="bereavement">Bereavement</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="start_date">Start Date</label>
                    <input type="text" id="start_date" name="start_date" class="form-control flatpickr-input" required>
                </div>
                <div class="form-group">
                    <label for="end_date">End Date</label>
                    <input type="text" id="end_date" name="end_date" class="form-control flatpickr-input" required>
                </div>
                <div class="form-group">
                    <label for="notes">Notes (Optional)</label>
                    <textarea id="notes" name="notes" class="form-control"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" id="cancelTimeOff">Cancel</button>
                    <button type="submit" class="btn btn-primary">Submit</button>
                </div>
            </form>
        </div>
    </div>

    <div class="holiday-modal" id="holidayModal">
        <div class="holiday-modal-content">
            <div class="holiday-modal-header">
                <h2 class="holiday-modal-title">Philippine Holidays 2025</h2>
                <button class="holiday-modal-close" id="closeHolidayModal">×</button>
            </div>
            <div class="holiday-modal-body">
                <?php foreach ($philippineHolidays as $holiday): ?>
                    <div class="holiday-info">
                        <div class="holiday-info-label">Date</div>
                        <div class="holiday-info-value"><?php echo date('F j, Y', strtotime($holiday['date'])); ?></div>
                        <div class="holiday-info-label">Holiday</div>
                        <div class="holiday-info-value"><?php echo htmlspecialchars($holiday['name']); ?></div>
                        <span class="holiday-badge">Non-Working Holiday</span>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="holiday-modal-footer">
                <button class="btn btn-primary" id="closeHolidayModalFooter">Close</button>
            </div>
        </div>
    </div>

    <div id="notificationContainer"></div>

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

        // Modal handling
        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.add('active');
                modal.querySelector('input, select, textarea')?.focus();
            }
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.remove('active');
            }
        }

        <?php if (in_array($currentUser['role'], ['admin', 'hr'])): ?>
            document.getElementById('openAnnouncementModal')?.addEventListener('click', () => openModal('announcementModal'));
            document.getElementById('closeAnnouncementModal')?.addEventListener('click', () => closeModal('announcementModal'));
            document.getElementById('cancelAnnouncement')?.addEventListener('click', () => closeModal('announcementModal'));

            document.getElementById('announcementForm')?.addEventListener('submit', function(e) {
                e.preventDefault();
                const form = this;
                const formData = new FormData(form);
                
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    showNotification(data.message, data.success ? 'success' : 'error');
                    if (data.success) {
                        closeModal('announcementModal');
                        form.reset();
                        setTimeout(() => location.reload(), 2000);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('An error occurred while submitting the announcement. Please try again.', 'error');
                });
            });
        <?php endif; ?>

        document.getElementById('requestTimeOff')?.addEventListener('click', () => openModal('timeOffModal'));
        document.getElementById('closeTimeOffModal')?.addEventListener('click', () => closeModal('timeOffModal'));
        document.getElementById('cancelTimeOff')?.addEventListener('click', () => closeModal('timeOffModal'));

        document.getElementById('viewHolidays')?.addEventListener('click', () => openModal('holidayModal'));
        document.getElementById('closeHolidayModal')?.addEventListener('click', () => closeModal('holidayModal'));
        document.getElementById('closeHolidayModalFooter')?.addEventListener('click', () => closeModal('holidayModal'));

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeModal('announcementModal');
                closeModal('timeOffModal');
                closeModal('holidayModal');
            }
        });

        flatpickr('.flatpickr-input', {
            dateFormat: 'Y-m-d',
            minDate: 'today'
        });

        document.getElementById('timeOffForm')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            try {
                const response = await fetch('Employeedashboard.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                showNotification(result.message, result.success ? 'success' : 'error');
                if (result.success) {
                    e.target.reset();
                    closeModal('timeOffModal');
                }
            } catch (error) {
                console.error('Error submitting time off request:', error);
                showNotification('An error occurred while submitting the request. Please try again.', 'error');
            }
        });

        function showNotification(message, type) {
            const container = document.getElementById('notificationContainer');
            const notification = document.createElement('div');
            notification.className = `update-notification ${type === 'success' ? 'alert-success' : 'alert-error'}`;
            notification.innerHTML = `
                <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i>
                <span>${message}</span>
                <button class="close-notification">×</button>
            `;
            container.appendChild(notification);
            setTimeout(() => {
                notification.style.opacity = '0';
                setTimeout(() => notification.remove(), 300);
            }, 5000);
            notification.querySelector('.close-notification').addEventListener('click', () => {
                notification.remove();
            });
        }

        const calendarDays = document.getElementById('calendarDays');
        const currentMonthElement = document.getElementById('currentMonth');
        const prevMonthBtn = document.getElementById('prevMonth');
        const nextMonthBtn = document.getElementById('nextMonth');
        const holidays = <?php echo json_encode($philippineHolidays); ?>;
        const events = <?php echo json_encode($monthEvents); ?>;
        let currentDate = new Date();
        let currentMonth = currentDate.getMonth();
        let currentYear = currentDate.getFullYear();

        function renderCalendar() {
            const firstDay = new Date(currentYear, currentMonth, 1);
            const lastDay = new Date(currentYear, currentMonth + 1, 0);
            const firstDayIndex = firstDay.getDay();
            const lastDate = lastDay.getDate();
            const prevLastDay = new Date(currentYear, currentMonth, 0).getDate();
            const today = new Date();

            currentMonthElement.textContent = `${firstDay.toLocaleString('default', { month: 'long' })} ${currentYear}`;
            calendarDays.innerHTML = '';

            for (let i = firstDayIndex; i > 0; i--) {
                const day = document.createElement('div');
                day.className = 'calendar-day other-month';
                day.textContent = prevLastDay - i + 1;
                calendarDays.appendChild(day);
            }

            for (let i = 1; i <= lastDate; i++) {
                const day = document.createElement('div');
                day.className = 'calendar-day';
                day.textContent = i;

                const currentDay = new Date(currentYear, currentMonth, i);
                if (
                    currentDay.getDate() === today.getDate() &&
                    currentDay.getMonth() === today.getMonth() &&
                    currentDay.getFullYear() === today.getFullYear()
                ) {
                    day.classList.add('today');
                }

                const dateStr = `${currentYear}-${String(currentMonth + 1).padStart(2, '0')}-${String(i).padStart(2, '0')}`;
                const holiday = holidays.find(h => h.date === dateStr);
                if (holiday) {
                    day.classList.add('holiday');
                    const tooltip = document.createElement('span');
                    tooltip.className = 'holiday-tooltip';
                    tooltip.textContent = holiday.name;
                    day.appendChild(tooltip);
                }

                const event = events.find(e => e.event_date === dateStr);
                if (event) {
                    day.classList.add('event');
                    const tooltip = document.createElement('span');
                    tooltip.className = 'holiday-tooltip';
                    tooltip.textContent = event.title;
                    day.appendChild(tooltip);
                    const dot = document.createElement('span');
                    dot.className = 'event-dot';
                    day.appendChild(dot);
                }

                calendarDays.appendChild(day);
            }

            const remainingDays = 42 - (firstDayIndex + lastDate);
            for (let i = 1; i <= remainingDays; i++) {
                const day = document.createElement('div');
                day.className = 'calendar-day other-month';
                day.textContent = i;
                calendarDays.appendChild(day);
            }
        }

        prevMonthBtn.addEventListener('click', () => {
            currentMonth--;
            if (currentMonth < 0) {
                currentMonth = 11;
                currentYear--;
            }
            renderCalendar();
        });

        nextMonthBtn.addEventListener('click', () => {
            currentMonth++;
            if (currentMonth > 11) {
                currentMonth = 0;
                currentYear++;
            }
            renderCalendar();
        });

        renderCalendar();

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