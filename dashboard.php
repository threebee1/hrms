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
    SELECT id, username, role 
    FROM users 
    WHERE id = ?
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

// Delete expired announcements (older than announcement_date)
try {
    $deleteStmt = $pdo->prepare("
        DELETE FROM announcements 
        WHERE announcement_date < CURDATE()
    ");
    $deleteStmt->execute();
} catch (PDOException $e) {
    error_log("Failed to delete expired announcements: " . $e->getMessage());
}

// Handle announcement deletion (via AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_announcement']) && ($currentUser['role'] === 'admin' || $currentUser['role'] === 'hr')) {
    header('Content-Type: application/json');
    
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
        exit();
    }

    $announcement_id = filter_var($_POST['announcement_id'] ?? '', FILTER_SANITIZE_NUMBER_INT);

    if (empty($announcement_id)) {
        echo json_encode(['success' => false, 'message' => 'Announcement ID is required.']);
        exit();
    }

    try {
        $deleteStmt = $pdo->prepare("
            DELETE FROM announcements 
            WHERE id = ?
        ");
        $success = $deleteStmt->execute([$announcement_id]);
        
        if ($success) {
            echo json_encode(['success' => true, 'message' => 'Announcement deleted successfully!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete announcement.']);
        }
    } catch (PDOException $e) {
        error_log("Announcement deletion failed: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error occurred.']);
    }
    exit();
}

// Fetch announcements
$announcementsStmt = $pdo->query("SELECT id, message, created_at, tag FROM announcements ORDER BY created_at DESC LIMIT 1");
$latestAnnouncement = $announcementsStmt->fetch(PDO::FETCH_ASSOC);

// Handle announcement submission (via AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_announcement']) && ($currentUser['role'] === 'admin' || $currentUser['role'] === 'hr')) {
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

// Fetch employee count
$employeeCountStmt = $pdo->query("SELECT COUNT(*) as total FROM employees");
$employeeCount = $employeeCountStmt->fetch(PDO::FETCH_ASSOC)['total'];

// Fetch payroll status
$payrollStmt = $pdo->query("SELECT COUNT(*) as processed FROM payroll WHERE status = 'processed'");
$payrollProcessed = $payrollStmt->fetch(PDO::FETCH_ASSOC)['processed'];

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

// Fetch today's interviews
$today = date('Y-m-d');
$interviewsStmt = $pdo->prepare("
    SELECT i.candidate_name, i.position, i.interview_time, i.status 
    FROM interviews i
    WHERE interview_date = ?
    ORDER BY interview_time
");
$interviewsStmt->execute([$today]);
$todaysInterviews = $interviewsStmt->fetchAll(PDO::FETCH_ASSOC);

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

    $leaveType = filter_var($_POST['leave_type'] ?? '', FILTER_SANITIZE_STRING);
    $startDate = filter_var($_POST['start_date'] ?? '', FILTER_SANITIZE_STRING);
    $endDate = filter_var($_POST['end_date'] ?? '', FILTER_SANITIZE_STRING);
    $notes = filter_var($_POST['notes'] ?? '', FILTER_SANITIZE_STRING);

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
    <title>HR Dashboard</title>
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

    .dashboard-grid {
        display: grid;
        grid-template-columns: repeat(12, 1fr);
        gap: 24px;
        padding: 30px;
        max-width: 1600px;
        margin: 0 auto;
    }

    .announcement-banner {
        grid-column: 1 / -1;
        background-color: var(--light);
        border-radius: var(--border-radius);
        padding: 20px;
        display: flex;
        align-items: center;
        box-shadow: var(--shadow);
        transition: var(--transition);
        cursor: pointer;
        position: relative;
    }

    .announcement-banner:hover {
        box-shadow: var(--shadow-hover);
        transform: translateY(-2px);
    }

    .announcement-icon {
        font-size: 24px;
        color: var(--primary);
        margin-right: 15px;
    }

    .announcement-text {
        flex: 1;
    }

    .announcement-text h3 {
        font-family: 'Poppins', sans-serif;
        font-size: 18px;
        color: var(--text);
        margin-bottom: 5px;
    }

    .announcement-text p {
        font-size: 14px;
        color: var(--text-light);
        max-width: 80%;
    }

    .announcement-tag {
        font-size: 12px;
        padding: 4px 8px;
        border-radius: var(--border-radius);
        margin-left: 10px;
        font-weight: 500;
    }

    .announcement-tag.important {
        background-color: rgba(255, 107, 107, 0.2);
        color: var(--error);
    }

    .announcement-tag.critical {
        background-color: rgba(191, 162, 219, 0.2);
        color: var(--secondary);
    }

    .announcement-tag.minor {
        background-color: rgba(75, 181, 67, 0.2);
        color: var(--success);
    }

    .announcement-tag.employee {
        background-color: rgba(75, 63, 114, 0.2);
        color: var(--primary);
    }

    .announcement-actions {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-left: auto;
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
        grid-column: span 3;
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

    .stats-icon.payroll {
        background-color: rgba(75, 63, 114, 0.15);
        color: var(--primary);
    }

    .stats-icon.employees {
        background-color: rgba(75, 181, 67, 0.15);
        color: var(--success);
    }

    .stats-icon.days {
        background-color: rgba(191, 162, 219, 0.15);
        color: var(--secondary);
    }

    .stats-icon.processed {
        background-color: rgba(255, 107, 107, 0.15);
        color: var(--error);
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

    .stats-change.negative {
        color: var(--error);
    }

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

    .time-details {
        display: flex;
        justify-content: space-between;
        margin-top: 20px;
        padding-top: 20px;
        border-top: 1px solid var(--gray);
    }

    .time-detail {
        text-align: center;
    }

    .time-label {
        font-size: 12px;
        color: var(--text-light);
        margin-bottom: 5px;
    }

    .time-value {
        font-size: 14px;
        font-weight: 600;
        color: var(--text);
    }

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

    .timeoff-actions {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .interviews-section {
        grid-column: span 6;
        background-color: var(--white);
        border-radius: var(--border-radius);
        padding: 24px;
        box-shadow: var(--shadow);
        transition: var(--transition);
    }

    .interviews-section:hover {
        box-shadow: var(--shadow-hover);
    }

    .tabs {
        display: flex;
        border-bottom: 1px solid var(--gray);
        margin-bottom: 20px;
    }

    .tab {
        padding: 10px 20px;
        font-size: 14px;
        color: var(--text-light);
        cursor: pointer;
        border-bottom: 2px solid transparent;
        transition: var(--transition);
    }

    .tab.active {
        color: var(--primary);
        border-bottom: 2px solid var(--primary);
        font-weight: 600;
    }

    .interview-list {
        display: flex;
        flex-direction: column;
        gap: 12px;
        max-height: 300px;
        overflow-y: auto;
    }

    .interview-item {
        display: flex;
        align-items: center;
        padding: 12px;
        border-radius: var(--border-radius);
        background-color: var(--light);
        transition: var(--transition);
        width: 100%;
    }

    .interview-item:hover {
        background-color: rgba(191, 162, 219, 0.1);
    }

    .interview-time {
        font-size: 14px;
        color: var(--text-light);
        width: 100px;
        font-weight: 500;
        flex-shrink: 0;
    }

    .interview-details {
        flex: 1;
        padding-right: 10px;
    }

    .interview-name {
        font-family: 'Poppins', sans-serif;
        font-size: 15px;
        font-weight: 600;
        color: var(--text);
        margin-bottom: 3px;
    }

    .interview-position {
        font-size: 13px;
        color: var(--text-light);
    }

    .interview-status {
        font-size: 12px;
        padding: 4px 10px;
        border-radius: var(--border-radius);
        background-color: rgba(191, 162, 219, 0.2);
        color: var(--secondary);
        font-weight: 500;
        flex-shrink: 0;
    }

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
        max-width: 400px;
        box-shadow: var(--shadow);
        transform: translateY(-20px);
        transition: var(--transition);
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
    }

    .holiday-info {
        margin-bottom: 15px;
    }

    .holiday-info-label {
        font-size: 14px;
        color: var(--text-light);
        margin-bottom: 5px;
    }

    .holiday-info-value {
        font-size: 16px;
        color: var(--text);
    }

    .holiday-badge {
        display: inline-block;
        padding: 4px 10px;
        border-radius: var(--border-radius);
        font-size: 12px;
        margin-top: 5px;
    }

    .holiday-badge.regular {
        background-color: rgba(191, 162, 219, 0.2);
        color: var(--secondary);
    }

    .holiday-modal-footer {
        padding: 15px 20px;
        border-top: 1px solid var(--gray);
        display: flex;
        justify-content: flex-end;
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
        .interviews-section {
            grid-column: span 6;
        }
    }

    @media (max-width: 992px) {
        .stats-card {
            grid-column: span 12;
        }
        .timeoff-section {
            grid-column: span 12;
        }
        .interviews-section {
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
        .announcement-banner {
            flex-direction: column;
            align-items: flex-start;
        }
        .announcement-text p {
            max-width: 100%;
        }
        .announcement-actions {
            margin-left: 0;
            margin-top: 10px;
        }
    }

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
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <i class="fas fa-users logo-icon"></i>
                <h1 class="logo-text">HRPro</h1>
            </div>
            <button class="toggle-btn" id="sidebarToggle">
                <i class="fas fa-chevron-left"></i>
            </button>
        </div>
        <nav class="sidebar-menu">
            <a href="<?php echo ($currentUser['role'] === 'admin' || $currentUser['role'] === 'hr') ? 'dashboard.php' : 'employeedashboard.php'; ?>" class="menu-item active">
                <i class="fas fa-home"></i>
                <span class="menu-text">Home</span>
            </a>
            <a href="personal.php" class="menu-item">
                <i class="fas fa-user"></i>
                <span class="menu-text">Personal</span>
            </a>
            <a href="timesheet.php" class="menu-item">
                <i class="fas fa-clock"></i>
                <span class="menu-text">Timesheet</span>
            </a>
            <a href="timeoff.php" class="menu-item">
                <i class="fas fa-calendar-minus"></i>
                <span class="menu-text">Time Off</span>
            </a>
            <a href="emergency.php" class="menu-item">
                <i class="fas fa-bell"></i>
                <span class="menu-text">Emergency</span>
            </a>
            <a href="performance.php" class="menu-item">
                <i class="fas fa-chart-line"></i>
                <span class="menu-text">Performance</span>
            </a>
            <a href="professionalpath.php" class="menu-item">
                <i class="fas fa-briefcase"></i>
                <span class="menu-text">Professional Path</span>
            </a>
            <a href="inbox.php" class="menu-item">
                <i class="fas fa-inbox"></i>
                <span class="menu-text">Inbox</span>
            </a>
            <a href="addEmployees.php" class="menu-item">
                <i class="fas fa-user-plus"></i>
                <span class="menu-text">Add Employee</span>
            </a>
            <a href="login.html" class="menu-item">
                <i class="fas fa-sign-out-alt"></i>
                <span class="menu-text">Logout</span>
            </a>
        </nav>
    </aside>

    <main class="main-content">
        <header class="header">
            <div class="header-title">
                <h1>HR Dashboard</h1>
                <p>Welcome back to your workspace</p>
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
                        $initials = substr($currentUser['username'] ?? '', 0, 2) ?: 'UK';
                        echo htmlspecialchars($initials);
                        ?>
                    </div>
                    <span><?php echo htmlspecialchars($currentUser['username'] . ' - ' . ucfirst($currentUser['role'])); ?></span>
                </div>
            </div>
        </header>

        <div class="dashboard-grid">
            <section class="announcement-banner" id="announcementBanner">
                <i class="fas fa-bullhorn announcement-icon"></i>
                <div class="announcement-text">
                    <h3>Today's Announcements</h3>
                    <p id="announcementMessage"><?php echo htmlspecialchars($latestAnnouncement['message'] ?? 'No announcements today'); ?>
                        <?php if ($latestAnnouncement['tag']): ?>
                            <span class="announcement-tag <?php echo htmlspecialchars($latestAnnouncement['tag']); ?>">
                                <?php echo htmlspecialchars(ucfirst($latestAnnouncement['tag'])); ?>
                            </span>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="announcement-actions">
                    <i class="fas fa-chevron-right announcement-action-btn"></i>
                    <?php if ($latestAnnouncement && ($currentUser['role'] === 'admin' || $currentUser['role'] === 'hr')): ?>
                        <button class="announcement-action-btn delete-announcement" data-id="<?php echo $latestAnnouncement['id']; ?>">
                            <i class="fas fa-trash"></i>
                        </button>
                    <?php endif; ?>
                </div>
            </section>

            <section class="stats-card">
                <div class="stats-header">
                    <span class="stats-title">Payroll</span>
                    <div class="stats-icon payroll">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                </div>
                <div class="stats-value" id="payroll-days"></div>
                <div class="stats-change">
                    <i class="fas fa-clock"></i> <span id="payroll-remaining"></span>
                </div>
            </section>

            <section class="stats-card">
                <div class="stats-header">
                    <span class="stats-title">Total Employees</span>
                    <div class="stats-icon employees">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
                <div class="stats-value">
                    <?php echo $employeeCount; ?> 
                    <span style="font-size: 16px; color: var(--success);">+2</span>
                </div>
                <div class="stats-change">
                    <i class="fas fa-arrow-up"></i> 2 new hires
                </div>
            </section>

            <section class="stats-card">
                <div class="stats-header">
                    <span class="stats-title">Working Days</span>
                    <div class="stats-icon days">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                </div>
                <div class="stats-value" id="working-days"></div>
                <div class="stats-change negative">
                    <i class="fas fa-arrow-down"></i> <span id="holiday-count"></span>
                </div>
            </section>

            <section class="stats-card">
                <div class="stats-header">
                    <span class="stats-title">Payroll Processed</span>
                    <div class="stats-icon processed">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
                <div class="stats-value">
                    <?php echo $payrollProcessed; ?>/<?php echo $employeeCount; ?>
                </div>
                <div class="stats-change">
                    <i class="fas fa-arrow-up"></i> 
                    <?php echo $employeeCount > 0 ? round(($payrollProcessed / $employeeCount) * 100) : 0; ?>% complete
                </div>
            </section>

            <section class="calendar-section">
                <div class="section-header">
                    <h2 class="section-title">Calendar</h2>
                </div>
                <div class="calendar-header">
                    <div class="calendar-nav">
                        <button class="calendar-nav-btn" id="prevMonth">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <button class="calendar-nav-btn" id="nextMonth">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                    <div class="calendar-month" id="currentMonthYear">
                        <?php echo date('F Y'); ?>
                    </div>
                </div>
                <div class="calendar-weekdays">
                    <div>S</div><div>M</div><div>T</div><div>W</div><div>T</div><div>F</div><div>S</div>
                </div>
                <div class="calendar-days" id="calendarDays"></div>
                
                <div class="time-details">
                    <div class="time-detail">
                        <span class="time-label">Start Time</span>
                        <span class="time-value">9:00 AM</span>
                    </div>
                    <div class="time-detail">
                        <span class="time-label">End Time</span>
                        <span class="time-value">6:00 PM</span>
                    </div>
                    <div class="time-detail">
                        <span class="time-label">Break Time</span>
                        <span class="time-value">60 min</span>
                    </div>
                </div>
            </section>

            <section class="timeoff-section">
                <div class="section-header">
                    <h2 class="section-title">Time Off</h2>
                </div>
                <div class="timeoff-progress">
                    <?php 
                    $leaveTypes = [
                        'vacation' => 'Vacation',
                        'sick' => 'Sick Leave',
                        'personal' => 'Personal Days',
                        'bereavement' => 'Bereavement'
                    ];
                    foreach ($leaveTypes as $key => $name): ?>
                        <div>
                            <div class="timeoff-type">
                                <span class="timeoff-name"><?php echo $name; ?></span>
                                <span class="timeoff-days"><?php echo $timeOffData[$key . '_days'] ?? 0; ?> days YTD</span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill" 
                                     style="width: <?php echo min(($timeOffData[$key . '_days'] ?? 0) * 10, 100); ?>%;"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="timeoff-actions">
                    <button class="btn btn-primary" id="requestTimeOffBtn">
                        <i class="fas fa-paper-plane"></i> Request Time Off
                    </button>
                    <?php if ($currentUser['role'] === 'admin' || $currentUser['role'] === 'hr'): ?>
                        <button class="btn btn-outline" id="approveTimeOffBtn">
                            <i class="fas fa-check"></i> Approve Time Off
                        </button>
                    <?php endif; ?>
                </div>
            </section>

            <section class="interviews-section">
                <div class="section-header">
                    <h2 class="section-title">Interviews</h2>
                </div>
                <div class="tabs">
                    <div class="tab active" data-tab="today">Today</div>
                    <div class="tab" data-tab="upcoming">Upcoming</div>
                    <div class="tab" data-tab="completed">Completed</div>
                </div>
                <div class="interview-list" id="interviewList">
                    <?php if (!empty($todaysInterviews)): ?>
                        <?php foreach ($todaysInterviews as $interview): ?>
                            <div class="interview-item">
                                <div class="interview-time"><?php echo date('g:i A', strtotime($interview['interview_time'])); ?></div>
                                <div class="interview-details">
                                    <div class="interview-name"><?php echo htmlspecialchars($interview['candidate_name']); ?></div>
                                    <div class="interview-position"><?php echo htmlspecialchars($interview['position']); ?></div>
                                </div>
                                <div class="interview-status"><?php echo htmlspecialchars(ucfirst($interview['status'])); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="interview-item" style="justify-content: center; color: var(--text-light);">
                            No interviews scheduled for today
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </main>

    <div class="modal" id="timeOffRequestModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Request Time Off</h3>
                <button class="modal-close" id="closeRequestModal">×</button>
            </div>
            <form id="timeOffRequestForm">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="submit_time_off" value="1">
                <div class="form-group">
                    <label for="leaveType">Leave Type</label>
                    <select class="form-control" id="leaveType" name="leave_type" required>
                        <option value="">Select leave type</option>
                        <option value="vacation">Vacation</option>
                        <option value="sick">Sick Leave</option>
                        <option value="personal">Personal Day</option>
                        <option value="bereavement">Bereavement</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="startDate">Start Date</label>
                    <input type="text" class="form-control flatpickr-input" id="startDate" name="start_date" required>
                </div>
                <div class="form-group">
                    <label for="endDate">End Date</label>
                    <input type="text" class="form-control flatpickr-input" id="endDate" name="end_date" required>
                </div>
                <div class="form-group">
                    <label for="notes">Notes</label>
                    <textarea class="form-control" id="notes" name="notes" rows="3"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" id="cancelRequest">Cancel</button>
                    <button type="submit" class="btn btn-primary">Submit Request</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($currentUser['role'] === 'admin' || $currentUser['role'] === 'hr'): ?>
    <div class="modal" id="timeOffApproveModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Approve Time Off Requests</h3>
                <button class="modal-close" id="closeApproveModal">×</button>
            </div>
            <div class="form-group">
                <label>Pending Requests</label>
                <div class="pending-requests">
                    <?php 
                    $pendingRequestsStmt = $pdo->query("
                        SELECT t.*, u.username as employee_name 
                        FROM time_off_requests t
                        JOIN users u ON t.employee_id = u.id
                        WHERE t.status = 'pending'
                        ORDER BY t.created_at DESC
                    ");
                    $pendingRequests = $pendingRequestsStmt->fetchAll(PDO::FETCH_ASSOC);
                    if (!empty($pendingRequests)): ?>
                        <?php foreach ($pendingRequests as $request): ?>
                            <div class="request-item">
                                <div class="request-header">
                                    <span class="request-name"><?php echo htmlspecialchars($request['employee_name']); ?></span>
                                    <span class="request-days">
                                        <?php echo (strtotime($request['end_date']) - strtotime($request['start_date'])) / (60 * 60 * 24) + 1; ?> days
                                    </span>
                                </div>
                                <div class="request-dates">
                                    <?php echo date('M j', strtotime($request['start_date'])) . ' - ' . date('M j, Y', strtotime($request['end_date'])); ?>
                                </div>
                                <div class="request-type"><?php echo htmlspecialchars(ucfirst($request['leave_type'])); ?></div>
                                <div class="request-actions">
                                    <button class="btn btn-outline btn-sm" 
                                            onclick="viewRequestDetails(<?php echo $request['id']; ?>)">View Details</button>
                                    <div class="approve-reject">
                                        <button class="btn btn-success btn-sm"
                                                onclick="approveRequest(<?php echo $request['id']; ?>)">Approve</button>
                                        <button class="btn btn-error btn-sm"
                                                onclick="rejectRequest(<?php echo $request['id']; ?>)">Reject</button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align: center; color: var(--text-light); padding: 20px;">
                            No pending time off requests
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" id="cancelApprove">Close</button>
            </div>
        </div>
    </div>

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

    <div class="holiday-modal" id="holidayModal">
        <div class="holiday-modal-content">
            <div class="holiday-modal-header">
                <h3 class="holiday-modal-title" id="holidayModalTitle"></h3>
                <button class="holiday-modal-close" id="closeHolidayModal">×</button>
            </div>
            <div class="holiday-modal-body">
                <div class="holiday-info">
                    <span class="holiday-info-label">Date</span>
                    <span class="holiday-info-value" id="holidayModalDate"></span>
                </div>
                <div class="holiday-info">
                    <span class="holiday-info-label">Type</span>
                    <span class="holiday-badge regular" id="holidayModalType">Regular Holiday</span>
                </div>
            </div>
            <div class="holiday-modal-footer">
                <button class="btn btn-outline" id="holidayModalCloseBtn">Close</button>
            </div>
        </div>
    </div>

    <div id="notificationContainer"></div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/flatpickr/4.6.13/flatpickr.min.js"></script>
    <script>
        document.getElementById('sidebarToggle').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('collapsed');
        });

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

        const timeOffRequestModal = document.getElementById('timeOffRequestModal');
        const holidayModal = document.getElementById('holidayModal');
        const announcementModal = document.getElementById('announcementModal');

        document.getElementById('requestTimeOffBtn').addEventListener('click', () => {
            timeOffRequestModal.classList.add('active');
        });

        document.getElementById('closeRequestModal').addEventListener('click', () => {
            timeOffRequestModal.classList.remove('active');
        });

        document.getElementById('cancelRequest').addEventListener('click', () => {
            timeOffRequestModal.classList.remove('active');
        });

        if (document.getElementById('approveTimeOffBtn')) {
            document.getElementById('approveTimeOffBtn').addEventListener('click', () => {
                window.location.href = 'timeoff.php';
            });
        }

        document.getElementById('closeHolidayModal').addEventListener('click', () => {
            holidayModal.classList.remove('active');
        });

        document.getElementById('holidayModalCloseBtn').addEventListener('click', () => {
            holidayModal.classList.remove('active');
        });

        if (announcementModal) {
            document.querySelector('.announcement-action-btn:not(.delete-announcement)').addEventListener('click', () => {
                announcementModal.classList.add('active');
            });

            document.getElementById('closeAnnouncementModal').addEventListener('click', () => {
                announcementModal.classList.remove('active');
            });

            document.getElementById('cancelAnnouncement').addEventListener('click', () => {
                announcementModal.classList.remove('active');
            });
        }

        flatpickr('.flatpickr-input', {
            dateFormat: 'Y-m-d',
            minDate: 'today'
        });

        document.getElementById('timeOffRequestForm').addEventListener('submit', function(e) {
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
                    timeOffRequestModal.classList.remove('active');
                    form.reset();
                    setTimeout(() => location.reload(), 2000);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('An error occurred while submitting the request. Please try again.', 'error');
            });
        });

        if (document.getElementById('announcementForm')) {
            document.getElementById('announcementForm').addEventListener('submit', function(e) {
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
                        announcementModal.classList.remove('active');
                        form.reset();
                        setTimeout(() => location.reload(), 2000);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('An error occurred while submitting the announcement. Please try again.', 'error');
                });
        });
        }

        document.querySelectorAll('.delete-announcement').forEach(button => {
            button.addEventListener('click', function(e) {
                e.stopPropagation();
                if (confirm('Are you sure you want to delete this announcement?')) {
                    const announcementId = this.getAttribute('data-id');
                    const formData = new FormData();
                    formData.append('delete_announcement', '1');
                    formData.append('announcement_id', announcementId);
                    formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');

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
                            setTimeout(() => location.reload(), 2000);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showNotification('An error occurred while deleting the announcement. Please try again.', 'error');
                    });
                }
            });
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
                notification.remove();
            }, 5000);
            notification.querySelector('.close-notification').addEventListener('click', () => {
                notification.remove();
            });
        }

        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', function() {
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                const tabType = this.getAttribute('data-tab');
                fetchInterviews(tabType);
            });
        });

        function fetchInterviews(type) {
            fetch(`fetch_interviews.php?type=${type}`)
                .then(response => response.json())
                .then(data => {
                    const interviewList = document.getElementById('interviewList');
                    interviewList.innerHTML = '';
                    if (data.length === 0) {
                        interviewList.innerHTML = `
                            <div class="interview-item" style="justify-content: center; color: var(--text-light);">
                                No interviews scheduled for ${type}
                            </div>
                        `;
                    } else {
                        data.forEach(interview => {
                            const item = document.createElement('div');
                            item.className = 'interview-item';
                            item.innerHTML = `
                                <div class="interview-time">${interview.interview_time}</div>
                                <div class="interview-details">
                                    <div class="interview-name">${interview.candidate_name}</div>
                                    <div class="interview-position">${interview.position}</div>
                                </div>
                                <div class="interview-status">${interview.status}</div>
                            `;
                            interviewList.appendChild(item);
                        });
                    }
                })
                .catch(error => {
                    showNotification('Failed to fetch interviews.', 'error');
                });
        }

        function approveRequest(id) {
            if (confirm('Are you sure you want to approve this request?')) {
                fetch('approve_request.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id, action: 'approve', csrf_token: '<?php echo $_SESSION['csrf_token']; ?>' })
                })
                .then(response => response.json())
                .then(data => {
                    showNotification(data.message, data.success ? 'success' : 'error');
                    if (data.success) {
                        setTimeout(() => location.reload(), 2000);
                    }
                })
                .catch(error => {
                    showNotification('An error occurred.', 'error');
                });
            }
        }

        function rejectRequest(id) {
            if (confirm('Are you sure you want to reject this request?')) {
                fetch('approve_request.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id, action: 'reject', csrf_token: '<?php echo $_SESSION['csrf_token']; ?>' })
                })
                .then(response => response.json())
                .then(data => {
                    showNotification(data.message, data.success ? 'success' : 'error');
                    if (data.success) {
                        setTimeout(() => location.reload(), 2000);
                    }
                })
                .catch(error => {
                    showNotification('An error occurred.', 'error');
                });
            }
        }

        function viewRequestDetails(id) {
            fetch(`fetch_request_details.php?id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(`Request Details:\n\nEmployee: ${data.request.employee_name}\nType: ${data.request.leave_type}\nDates: ${data.request.start_date} to ${data.request.end_date}\nNotes: ${data.request.notes || 'None'}`);
                    } else {
                        showNotification(data.message, 'error');
                    }
                })
                .catch(error => {
                    showNotification('Failed to fetch request details.', 'error');
                });
        }

        const holidays = <?php echo json_encode($philippineHolidays); ?>;
        const events = <?php echo json_encode($monthEvents); ?>;
        let currentDate = new Date();
        const today = new Date().toISOString().split('T')[0];

        function renderCalendar() {
            const year = currentDate.getFullYear();
            const month = currentDate.getMonth();
            const firstDay = new Date(year, month, 1).getDay();
            const lastDate = new Date(year, month + 1, 0).getDate();
            const prevLastDate = new Date(year, month, 0).getDate();
            
            document.getElementById('currentMonthYear').textContent = currentDate.toLocaleString('default', { month: 'long', year: 'numeric' });
            const calendarDays = document.getElementById('calendarDays');
            calendarDays.innerHTML = '';

            for (let i = firstDay; i > 0; i--) {
                const day = prevLastDate - i + 1;
                const dateStr = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                calendarDays.innerHTML += `<div class="calendar-day other-month">${day}</div>`;
            }

            for (let i = 1; i <= lastDate; i++) {
                const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(i).padStart(2, '0')}`;
                let classes = 'calendar-day';
                let tooltip = '';
                let isHoliday = false;

                if (dateStr === today) {
                    classes += ' today';
                }

                const holiday = holidays.find(h => h.date === dateStr);
                if (holiday) {
                    classes += ' holiday';
                    tooltip = holiday.name;
                    isHoliday = true;
                }

                const dayEvents = events.filter(e => e.event_date === dateStr);
                if (dayEvents.length > 0) {
                    classes += ' event';
                    if (!isHoliday) {
                        tooltip = dayEvents.map(e => e.title).join(', ');
                    }
                }

                calendarDays.innerHTML += `
                    <div class="${classes}" data-date="${dateStr}">
                        ${i}
                        ${dayEvents.length > 0 && !isHoliday ? '<span class="event-dot"></span>' : ''}
                        ${tooltip ? `<span class="holiday-tooltip">${tooltip}</span>` : ''}
                    </div>
                `;
            }

            const totalDays = firstDay + lastDate;
            const nextDays = totalDays % 7 === 0 ? 0 : 7 - (totalDays % 7);
            for (let i = 1; i <= nextDays; i++) {
                calendarDays.innerHTML += `<div class="calendar-day other-month">${i}</div>`;
            }

            document.querySelectorAll('.calendar-day.holiday, .calendar-day.event').forEach(day => {
                day.addEventListener('click', function() {
                    const date = this.getAttribute('data-date');
                    const holiday = holidays.find(h => h.date === date);
                    const event = events.find(e => e.event_date === date);
                    if (holiday) {
                        document.getElementById('holidayModalTitle').textContent = holiday.name;
                        document.getElementById('holidayModalDate').textContent = new Date(date).toLocaleDateString('en-US', {
                            month: 'long',
                            day: 'numeric',
                            year: 'numeric'
                        });
                        document.getElementById('holidayModalType').textContent = 'Regular Holiday';
                        holidayModal.classList.add('active');
                    } else if (event) {
                        document.getElementById('holidayModalTitle').textContent = event.title;
                        document.getElementById('holidayModalDate').textContent = new Date(date).toLocaleDateString('en-US', {
                            month: 'long',
                            day: 'numeric',
                            year: 'numeric'
                        });
                        document.getElementById('holidayModalType').textContent = 'Event';
                        holidayModal.classList.add('active');
                    }
                });
            });
        }

        document.getElementById('prevMonth').addEventListener('click', () => {
            currentDate.setMonth(currentDate.getMonth() - 1);
            renderCalendar();
        });

        document.getElementById('nextMonth').addEventListener('click', () => {
            currentDate.setMonth(currentDate.getMonth() + 1);
            renderCalendar();
        });

        function updatePayrollStats() {
            const now = new Date();
            const year = now.getFullYear();
            const month = now.getMonth();
            const lastDay = new Date(year, month + 1, 0).getDate();
            const today = now.getDate();
            const daysUntilPayroll = lastDay - today;
            
            document.getElementById('payroll-days').textContent = daysUntilPayroll;
            document.getElementById('payroll-remaining').textContent = 
                daysUntilPayroll <= 0 ? 'Payroll processing today' : `${daysUntilPayroll} days until payroll`;
        }

        function updateWorkingDays() {
            const now = new Date();
            const year = now.getFullYear();
            const month = now.getMonth();
            const lastDay = new Date(year, month + 1, 0).getDate();
            let workingDays = 0;
            let holidayCount = 0;

            for (let i = 1; i <= lastDay; i++) {
                const date = new Date(year, month, i);
                const dateStr = date.toISOString().split('T')[0];
                const isWeekend = date.getDay() === 0 || date.getDay() === 6;
                const isHoliday = holidays.some(h => h.date === dateStr);

                if (!isWeekend && !isHoliday) {
                    workingDays++;
                }
                if (isHoliday) {
                    holidayCount++;
                }
            }

            document.getElementById('working-days').textContent = workingDays;
            document.getElementById('holiday-count').textContent = `${holidayCount} holidays this month`;
        }

        renderCalendar();
        updatePayrollStats();
        updateWorkingDays();
    </script>
</body>
</html>