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
    SELECT id, title, message, created_at 
    FROM announcements 
    ORDER BY created_at DESC 
    LIMIT 5
");
$announcements = $announcementsStmt->fetchAll(PDO::FETCH_ASSOC);

// Handle announcement creation (admin only)
$announcementSuccess = null;
$announcementError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_announcement']) && $currentUser['role'] === 'admin') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $announcementError = "Invalid CSRF token.";
    } else {
        $title = filter_var($_POST['announcement_title'], FILTER_SANITIZE_STRING);
        $message = filter_var($_POST['announcement_message'], FILTER_SANITIZE_STRING);

        if (empty($title) || empty($message)) {
            $announcementError = "Title and message are required.";
        } else {
            $insertStmt = $pdo->prepare("
                INSERT INTO announcements (title, message, created_at)
                VALUES (?, ?, NOW())
            ");
            try {
                if ($insertStmt->execute([$title, $message])) {
                    $announcementSuccess = "Announcement posted successfully!";
                    header('Location: Employeedashboard.php');
                    exit();
                } else {
                    $announcementError = "Failed to post announcement.";
                }
            } catch (PDOException $e) {
                error_log("Announcement insert failed: " . $e->getMessage());
                $announcementError = "Failed to post announcement.";
            }
        }
    }
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

// Fetch recent timesheets
$timesheetsStmt = $pdo->prepare("
    SELECT date, clock_in, clock_out, break_duration, total_hours, status 
    FROM timesheets 
    WHERE employee_id = ? 
    ORDER BY date DESC 
    LIMIT 5
");
$timesheetsStmt->execute([$currentUserId]);
$recentTimesheets = $timesheetsStmt->fetchAll(PDO::FETCH_ASSOC);

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
$requestSuccess = null;
$requestError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_time_off'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $requestError = "Invalid CSRF token.";
    } else {
        $leaveType = filter_var($_POST['leave_type'], FILTER_SANITIZE_STRING);
        $startDate = filter_var($_POST['start_date'], FILTER_SANITIZE_STRING);
        $endDate = filter_var($_POST['end_date'], FILTER_SANITIZE_STRING);
        $notes = filter_var($_POST['notes'], FILTER_SANITIZE_STRING);

        if (!in_array($leaveType, ['vacation', 'sick', 'personal', 'bereavement', 'other'])) {
            $requestError = "Invalid leave type.";
        } elseif (!DateTime::createFromFormat('Y-m-d', $startDate) || !DateTime::createFromFormat('Y-m-d', $endDate)) {
            $requestError = "Invalid date format.";
        } else {
            $insertStmt = $pdo->prepare("
                INSERT INTO time_off_requests 
                (employee_id, leave_type, start_date, end_date, notes, status) 
                VALUES (?, ?, ?, ?, ?, 'pending')
            ");
            if ($insertStmt->execute([$currentUserId, $leaveType, $startDate, $endDate, $notes])) {
                $requestSuccess = "Time off request submitted successfully!";
            } else {
                $requestError = "Failed to submit time off request.";
            }
        }
    }
    header('Content-Type: application/json');
    echo json_encode([
        'success' => isset($requestSuccess),
        'message' => $requestSuccess ?? $requestError
    ]);
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
    <title>Employee Dashboard</title>
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
        --shadow-hover: 0 6px 20px rgba(0, 0, 0, 0.15);
        --transition: all 0.3s ease;
        --focus-ring: 0 0 0 3px rgba(191, 162, 219, 0.3);
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
        background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
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
    .sidebar.collapsed .menu-text {
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
        margin-left: auto;
        background-color: var(--error);
        color: var(--white);
        border-radius: 10px;
        padding: 2px 8px;
        font-size: 12px;
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
        background-color: var(--white);
        box-shadow: var(--shadow);
        position: sticky;
        top: 0;
        z-index: 10;
    }

    .header-title h1 {
        font-family: 'Poppins', sans-serif;
        font-size: 26px;
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
        width: 42px;
        height: 42px;
        background-color: var(--primary);
        color: var(--white);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 10px;
        font-weight: 600;
        font-size: 16px;
    }

    /* Dashboard Grid */
    .dashboard-grid {
        display: grid;
        grid-template-columns: repeat(12, 1fr);
        gap: 24px;
        padding: 30px;
        max-width: 1600px;
        margin: 0 auto;
    }

    /* Announcement Section */
    .announcement-section {
        grid-column: 1 / -1;
        background-color: var(--white);
        border-radius: var(--border-radius);
        padding: 24px;
        box-shadow: var(--shadow);
        transition: var(--transition);
    }

    .announcement-section:hover {
        box-shadow: var(--shadow-hover);
    }

    .announcement-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }

    .announcement-title {
        font-family: 'Poppins', sans-serif;
        font-size: 20px;
        color: var(--primary);
        font-weight: 600;
    }

    .announcement-list {
        display: flex;
        flex-direction: column;
        gap: 16px;
    }

    .announcement-item {
        background-color: var(--light);
        border-radius: var(--border-radius);
        padding: 16px;
        transition: var(--transition);
        display: flex;
        align-items: center;
        gap: 16px;
    }

    .announcement-item:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-hover);
    }

    .announcement-icon {
        font-size: 24px;
        color: var(--primary);
        flex-shrink: 0;
    }

    .announcement-content {
        flex: 1;
    }

    .announcement-content h3 {
        font-family: 'Poppins', sans-serif;
        font-size: 18px;
        color: var(--text);
        margin-bottom: 8px;
    }

    .announcement-content p {
        font-size: 14px;
        color: var(--text-light);
        margin-bottom: 8px;
    }

    .announcement-content small {
        font-size: 12px;
        color: var(--text-light);
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

    /* Stats Cards */
    .stats-card {
        grid-column: span 4;
        background-color: var(--white);
        border-radius: var(--border-radius);
        padding: 24px;
        box-shadow: var(--shadow);
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
        background-color: rgba(191, 162, 219, 0.15);
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

    /* Calendar Section */
    .calendar-section {
        grid-column: span 8;
        background-color: var(--white);
        border-radius: var(--border-radius);
        padding: 24px;
        box-shadow: var(--shadow);
        transition: var(--transition);
    }

    .calendar-section:hover {
        box-shadow: var(--shadow-hover);
    }

    .section-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }

    .section-title {
        font-family: 'Poppins', sans-serif;
        font-size: 20px;
        color: var(--primary);
        font-weight: 600;
    }

    .btn {
        padding: 10px 18px;
        border-radius: var(--border-radius);
        font-size: 14px;
        cursor: pointer;
        transition: var(--transition);
        font-weight: 500;
    }

    .btn-primary {
        background-color: var(--primary);
        color: var(--white);
        border: none;
    }

    .btn-primary:hover {
        background-color: var(--primary-light);
        box-shadow: var(--shadow-hover);
    }

    .btn-outline {
        background-color: transparent;
        border: 1px solid var(--primary);
        color: var(--primary);
    }

    .btn-outline:hover {
        background-color: var(--light);
        color: var(--primary-light);
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
        background-color: var(--light);
        box-shadow: var(--shadow);
    }

    .calendar-day.today {
        background-color: var(--primary);
        color: var(--white);
        font-weight: 600;
    }

    .calendar-day.other-month {
        color: var(--text-light);
    }

    .calendar-day.holiday {
        background-color: rgba(255, 107, 107, 0.1);
        color: var(--error);
        border: 1px solid var(--error);
    }

    .calendar-day.holiday:hover {
        background-color: var(--error);
        color: var(--white);
    }

    .event-dot {
        position: absolute;
        bottom: 4px;
        width: 5px;
        height: 5px;
        background-color: var(--success);
        border-radius: 50%;
    }

    .holiday-tooltip {
        position: absolute;
        top: -40px;
        left: 50%;
        transform: translateX(-50%);
        background-color: var(--primary-dark);
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

    /* Time Off Section */
    .timeoff-section {
        grid-column: span 4;
        background-color: var(--white);
        border-radius: var(--border-radius);
        padding: 24px;
        box-shadow: var(--shadow);
        transition: var(--transition);
    }

    .timeoff-section:hover {
        box-shadow: var(--shadow-hover);
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
        background-color: var(--gray);
        border-radius: 3px;
        margin-bottom: 15px;
        overflow: hidden;
    }

    .progress-fill {
        height: 100%;
        background-color: var(--primary);
        border-radius: 3px;
        transition: width 0.5s ease;
    }

    /* Timesheets Section */
    .timesheets-section {
        grid-column: span 8;
        background-color: var(--white);
        border-radius: var(--border-radius);
        padding: 24px;
        box-shadow: var(--shadow);
        transition: var(--transition);
    }

    .timesheets-section:hover {
        box-shadow: var(--shadow-hover);
    }

    .timesheet-list {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .timesheet-item {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr 1fr 1fr;
        align-items: center;
        padding: 12px;
        border-radius: var(--border-radius);
        background-color: var(--light);
        transition: var(--transition);
    }

    .timesheet-item:hover {
        background-color: rgba(191, 162, 219, 0.1);
    }

    .timesheet-date {
        font-weight: 500;
    }

    .timesheet-time {
        font-size: 14px;
        color: var(--text);
    }

    .timesheet-status {
        font-size: 12px;
        padding: 4px 10px;
        border-radius: var(--border-radius);
        font-weight: 500;
        text-align: center;
    }

    .timesheet-status.pending {
        background-color: rgba(255, 193, 7, 0.2);
        color: #FFA000;
    }

    .timesheet-status.approved {
        background-color: rgba(75, 181, 67, 0.2);
        color: var(--success);
    }

    .timesheet-status.rejected {
        background-color: rgba(255, 107, 107, 0.2);
        color: var(--error);
    }

    /* Modal Styles */
    .modal {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.5);
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
        background-color: var(--white);
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

    /* Holiday Modal */
    .holiday-modal {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.5);
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
        background-color: var(--white);
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
        background-color: var(--white);
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
        background-color: var(--light);
        transition: var(--transition);
    }

    .holiday-info:hover {
        background-color: rgba(191, 162, 219, 0.1);
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
        background-color: rgba(191, 162, 219, 0.2);
        color: var(--secondary);
    }

    .holiday-modal-footer {
        padding: 15px 20px;
        border-top: 1px solid var(--gray);
        display: flex;
        justify-content: flex-end;
        position: sticky;
        bottom: 0;
        background-color: var(--white);
    }

    /* Notification Styles */
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

    @keyframes slideIn {
        from { transform: translateX(100%); }
        to { transform: translateX(0); }
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
        .timesheets-section {
            grid-column: span 12;
        }
    }

    @media (max-width: 992px) {
        .stats-card {
            grid-column: span 12;
        }
        .timeoff-section {
            grid-column: span 12;
        }
        .timesheets-section {
            grid-column: span 12;
        }
        .dashboard-grid {
            padding: 20px;
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
        .timesheet-item {
            grid-template-columns: 1fr 1fr;
            gap: 8px;
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

    /* Alert Styles */
    .alert-success {
        background-color: rgba(75, 181, 67, 0.1);
        color: var(--success);
        border: 1px solid rgba(75, 181, 67, 0.3);
    }

    .alert-error {
        background-color: rgba(255, 107, 107, 0.1);
        color: var(--error);
        border: 1px solid rgba(255, 107, 107, 0.3);
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
                <h1>Employee Dashboard</h1>
                <p>Welcome back, <?php echo htmlspecialchars($currentUser['first_name'] ?? $currentUser['username']); ?></p>
            </div>
            <div class="header-info">
                <div class="current-time" id="currentTime"></div>
                <div class="user-profile">
                    <div class="user-avatar"><?php echo strtoupper(substr($currentUser['first_name'] ?? $currentUser['username'], 0, 1)); ?></div>
                    <span><?php echo htmlspecialchars($currentUser['username']); ?></span>
                </div>
            </div>
        </header>

        <!-- Dashboard Grid -->
        <div class="dashboard-grid">
            <!-- Announcement Section -->
            <div class="announcement-section">
                <div class="announcement-header">
                    <h2 class="announcement-title">Announcements</h2>
                    <?php if ($currentUser['role'] === 'admin'): ?>
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
                                    <h3><?php echo htmlspecialchars($announcement['title']); ?></h3>
                                    <p><?php echo htmlspecialchars($announcement['message']); ?></p>
                                    <small><?php echo date('F j, Y, g:i a', strtotime($announcement['created_at'])); ?></small>
                                </div>
                                <div class="announcement-actions">
                                    <button class="announcement-action-btn"><i class="fas fa-eye"></i></button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Stats Cards -->
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

            <!-- Calendar Section -->
            <div class="calendar-section">
                <div class="section-header">
                    <h2 class="section-title">Calendar</h2>
                    <button class="btn btn-outline" id="viewHolidays">View Holidays</button>
                </div>
                <div class="calendar-header">
                    <div class="calendar-nav">
                        <button class="calendar-nav-btn" id="prevMonth"><i class="fas fa-chevron-left"></i></button>
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

            <!-- Time Off Section -->
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

    <!-- Announcement Modal -->
    <?php if ($currentUser['role'] === 'admin'): ?>
        <div class="modal" id="announcementModal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title">Post New Announcement</h2>
                    <button class="modal-close" id="closeAnnouncementModal">&times;</button>
                </div>
                <form id="announcementForm" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <div class="form-group">
                        <label for="announcement_title">Title</label>
                        <input type="text" id="announcement_title" name="announcement_title" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="announcement_message">Message</label>
                        <textarea id="announcement_message" name="announcement_message" class="form-control" required></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline" id="cancelAnnouncement">Cancel</button>
                        <button type="submit" name="submit_announcement" class="btn btn-primary">Post</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- Time Off Request Modal -->
    <div class="modal" id="timeOffModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Request Time Off</h2>
                <button class="modal-close" id="closeTimeOffModal">&times;</button>
            </div>
            <form id="timeOffForm" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
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
                    <input type="text" id="start_date" name="start_date" class="form-control flatpickr" required>
                </div>
                <div class="form-group">
                    <label for="end_date">End Date</label>
                    <input type="text" id="end_date" name="end_date" class="form-control flatpickr" required>
                </div>
                <div class="form-group">
                    <label for="notes">Notes (Optional)</label>
                    <textarea id="notes" name="notes" class="form-control"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" id="cancelTimeOff">Cancel</button>
                    <button type="submit" name="submit_time_off" class="btn btn-primary">Submit</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Holiday Modal -->
    <div class="holiday-modal" id="holidayModal">
        <div class="holiday-modal-content">
            <div class="holiday-modal-header">
                <h2 class="holiday-modal-title">Philippine Holidays 2025</h2>
                <button class="holiday-modal-close" id="closeHolidayModal">&times;</button>
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

    <!-- Notification -->
    <?php if ($announcementSuccess || $announcementError): ?>
        <div class="update-notification <?php echo $announcementSuccess ? 'alert-success' : 'alert-error'; ?>">
            <i class="fas fa-<?php echo $announcementSuccess ? 'check-circle' : 'exclamation-circle'; ?>"></i>
            <span><?php echo htmlspecialchars($announcementSuccess ?? $announcementError); ?></span>
            <button class="close-notification" id="closeNotification">&times;</button>
        </div>
    <?php endif; ?>

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
        const sidebarToggle = document.getElementById('sidebarToggle');
        sidebarToggle.addEventListener('click', () => {
            sidebar.classList.toggle('collapsed');
            sidebarToggle.querySelector('i').classList.toggle('fa-chevron-left');
            sidebarToggle.querySelector('i').classList.toggle('fa-chevron-right');
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

        // Announcement modal
        <?php if ($currentUser['role'] === 'admin'): ?>
            document.getElementById('openAnnouncementModal')?.addEventListener('click', () => openModal('announcementModal'));
            document.getElementById('closeAnnouncementModal')?.addEventListener('click', () => closeModal('announcementModal'));
            document.getElementById('cancelAnnouncement')?.addEventListener('click', () => closeModal('announcementModal'));
        <?php endif; ?>

        // Time off modal
        document.getElementById('requestTimeOff')?.addEventListener('click', () => openModal('timeOffModal'));
        document.getElementById('closeTimeOffModal')?.addEventListener('click', () => closeModal('timeOffModal'));
        document.getElementById('cancelTimeOff')?.addEventListener('click', () => closeModal('timeOffModal'));

        // Holiday modal
        document.getElementById('viewHolidays')?.addEventListener('click', () => openModal('holidayModal'));
        document.getElementById('closeHolidayModal')?.addEventListener('click', () => closeModal('holidayModal'));
        document.getElementById('closeHolidayModalFooter')?.addEventListener('click', () => closeModal('holidayModal'));

        // Close modals on escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeModal('announcementModal');
                closeModal('timeOffModal');
                closeModal('holidayModal');
            }
        });

        // Notification handling
        document.getElementById('closeNotification')?.addEventListener('click', (e) => {
            e.target.closest('.update-notification').remove();
        });

        // Flatpickr initialization
        flatpickr('.flatpickr', {
            dateFormat: 'Y-m-d',
            minDate: 'today'
        });

        // Time off form submission
        const timeOffForm = document.getElementById('timeOffForm');
        timeOffForm?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(timeOffForm);
            try {
                const response = await fetch('Employeedashboard.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                const notification = document.createElement('div');
                notification.className = `update-notification ${result.success ? 'alert-success' : 'alert-error'}`;
                notification.innerHTML = `
                    <i class="fas fa-${result.success ? 'check-circle' : 'exclamation-circle'}"></i>
                    <span>${result.message}</span>
                    <button class="close-notification">&times;</button>
                `;
                document.body.appendChild(notification);
                notification.querySelector('.close-notification').addEventListener('click', () => notification.remove());
                if (result.success) {
                    timeOffForm.reset();
                    closeModal('timeOffModal');
                    setTimeout(() => notification.remove(), 3000);
                }
            } catch (error) {
                console.error('Error submitting time off request:', error);
            }
        });

        // Calendar functionality
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

            // Previous month days
            for (let i = firstDayIndex; i > 0; i--) {
                const day = document.createElement('div');
                day.className = 'calendar-day other-month';
                day.textContent = prevLastDay - i + 1;
                calendarDays.appendChild(day);
            }

            // Current month days
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

            // Next month days
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
    </script>
</body>
</html>
