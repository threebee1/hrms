<?php
session_set_cookie_params(['secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
session_start();
session_regenerate_id(true);

// Validate CSRF token for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        http_response_code(403);
        die('Invalid CSRF token');
    }
}

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

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$user_id = $_SESSION['user_id'];
$stmt_role = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$stmt_role->execute([$user_id]);
$user_data = $stmt_role->fetch(PDO::FETCH_ASSOC);

if (!$user_data || $user_data['role'] !== 'hr') {
    header('Location: dashboard.php');
    exit();
}

function getTimeOffBalance($pdo, $employee_id, $year = null) {
    // Validate employee_id
    if (!is_numeric($employee_id)) {
        throw new InvalidArgumentException("Invalid employee ID");
    }
    
    $year = $year ?: date('Y');
    
    try {
        // Get employee's leave allowances from database
        $stmt = $pdo->prepare("SELECT leave_type, days_allowed FROM leave_allowances 
                              WHERE employee_id = ? AND year = ?");
        $stmt->execute([$employee_id, $year]);
        $allowances = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Default allowances if not specified in DB
        $default_allowances = [
            'vacation' => 15,
            'sick' => 10,
            'personal' => 5,
            'bereavement' => 3,
            'other' => 2
        ];
        
        // Merge defaults with database values
        $balances = array_merge($default_allowances, $allowances);
        
        // Initialize used days array
        $used = array_fill_keys(array_keys($balances), 0);
        
        // Get approved leave days
        $stmt = $pdo->prepare("SELECT leave_type, SUM(DATEDIFF(end_date, start_date) + 1) as days_used 
                               FROM time_off_requests 
                               WHERE employee_id = ? 
                               AND status = 'approved' 
                               AND (YEAR(start_date) = ? OR YEAR(end_date) = ?)
                               GROUP BY leave_type");
        $stmt->execute([$employee_id, $year, $year]);
        
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (array_key_exists($row['leave_type'], $used)) {
                $used[$row['leave_type']] = (int)$row['days_used'];
            }
        }
        
        // Calculate remaining days
        $remaining = [];
        foreach ($balances as $type => $allowed) {
            $remaining[$type] = max(0, $allowed - ($used[$type] ?? 0));
        }
        
        return [
            'total' => $balances,
            'used' => $used,
            'remaining' => $remaining
        ];
        
    } catch (PDOException $e) {
        error_log("Error calculating leave balance: " . $e->getMessage());
        return false;
    }
}

function getBusinessDays($start_date, $end_date, $pdo = null) {
    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    $end->modify('+1 day');
    
    $holidays = [];
    if ($pdo) {
        try {
            $year = $start->format('Y');
            $stmt = $pdo->prepare("SELECT holiday_date FROM company_holidays 
                                  WHERE YEAR(holiday_date) = ?");
            $stmt->execute([$year]);
            $holidays = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            error_log("Error fetching holidays: " . $e->getMessage());
        }
    }
    
    $period = new DatePeriod($start, new DateInterval('P1D'), $end);
    
    return iterator_count(array_filter(
        iterator_to_array($period),
        function($date) use ($holidays) {
            // Weekday (1-7, 1=Monday, 7=Sunday)
            $weekday = $date->format('N');
            $date_str = $date->format('Y-m-d');
            return $weekday < 6 && !in_array($date_str, $holidays);
        }
    ));
}

function getPendingRequests($pdo) {
    $stmt = $pdo->prepare("SELECT r.id, e.first_name, e.last_name, r.leave_type, r.start_date, r.end_date, r.notes, 
                           r.created_at, e.id as employee_id, e.department
                           FROM time_off_requests r JOIN employees e ON r.employee_id = e.id 
                           WHERE r.status = 'pending' ORDER BY r.created_at DESC");
    $stmt->execute();
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($requests as &$row) {
        $row['business_days'] = getBusinessDays($row['start_date'], $row['end_date'], $pdo);
        $row['total_days'] = (new DateTime($row['end_date']))->diff(new DateTime($row['start_date']))->days + 1;
    }
    return $requests;
}

function getAllRequests($pdo, $filters, $limit, $offset) {
    $where = [];
    $params = [];
    
    if ($filters['status']) {
        $where[] = "r.status = ?";
        $params[] = $filters['status'];
    }
    if ($filters['department']) {
        $where[] = "e.department = ?";
        $params[] = $filters['department'];
    }
    if ($filters['leave_type']) {
        $where[] = "r.leave_type = ?";
        $params[] = $filters['leave_type'];
    }
    if ($filters['employee_id']) {
        $where[] = "e.id = ?";
        $params[] = (int)$filters['employee_id'];
    }
    if ($filters['start_date']) {
        $where[] = "r.start_date >= ?";
        $params[] = $filters['start_date'];
    }
    if ($filters['end_date']) {
        $where[] = "r.end_date <= ?";
        $params[] = $filters['end_date'];
    }
    if ($filters['search']) {
        $search = "%{$filters['search']}%";
        $where[] = "(e.first_name LIKE ? OR e.last_name LIKE ?)";
        $params[] = $search;
        $params[] = $search;
    }
    
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // Secure order_by handling
    $allowed_order = [
        'r.created_at DESC' => 'r.created_at DESC',
        'r.created_at ASC' => 'r.created_at ASC',
        'e.first_name ASC' => 'e.first_name ASC',
        'e.first_name DESC' => 'e.first_name DESC'
    ];
    $order_by = $allowed_order[$filters['order_by']] ?? 'r.created_at DESC';
    
    $query = "SELECT r.id, e.first_name, e.last_name, r.leave_type, r.start_date, r.end_date, r.notes, r.status, 
              r.created_at, e.id as employee_id, e.department
              FROM time_off_requests r JOIN employees e ON r.employee_id = e.id 
              $whereSql ORDER BY $order_by LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    
    $stmt = $pdo->prepare($query);
    foreach ($params as $i => $param) {
        $stmt->bindValue($i + 1, $param, is_int($param) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($requests as &$row) {
        $row['business_days'] = getBusinessDays($row['start_date'], $row['end_date'], $pdo);
        $row['total_days'] = (new DateTime($row['end_date']))->diff(new DateTime($row['start_date']))->days + 1;
    }
    return $requests;
}

function getTotalRecords($pdo, $filters) {
    $where = [];
    $params = [];
    
    if ($filters['status']) {
        $where[] = "r.status = ?";
        $params[] = $filters['status'];
    }
    if ($filters['department']) {
        $where[] = "e.department = ?";
        $params[] = $filters['department'];
    }
    if ($filters['leave_type']) {
        $where[] = "r.leave_type = ?";
        $params[] = $filters['leave_type'];
    }
    if ($filters['employee_id']) {
        $where[] = "e.id = ?";
        $params[] = (int)$filters['employee_id'];
    }
    if ($filters['start_date']) {
        $where[] = "r.start_date >= ?";
        $params[] = $filters['start_date'];
    }
    if ($filters['end_date']) {
        $where[] = "r.end_date <= ?";
        $params[] = $filters['end_date'];
    }
    if ($filters['search']) {
        $search = "%{$filters['search']}%";
        $where[] = "(e.first_name LIKE ? OR e.last_name LIKE ?)";
        $params[] = $search;
        $params[] = $search;
    }
    
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM time_off_requests r JOIN employees e ON r.employee_id = e.id $whereSql");
    foreach ($params as $i => $param) {
        $stmt->bindValue($i + 1, $param, is_int($param) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    return $stmt->fetchColumn();
}

function getAllDepartments($pdo) {
    $stmt = $pdo->prepare("SELECT DISTINCT department FROM employees WHERE department IS NOT NULL AND department != '' ORDER BY department");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

$error = $success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    if (in_array($_POST['action'], ['approve', 'reject'])) {
        $request_id = (int)($_POST['request_id'] ?? 0);
        $status = $_POST['action'] === 'approve' ? 'approved' : 'rejected';
        
        if ($request_id > 0) {
            $stmt = $pdo->prepare("UPDATE time_off_requests SET status = ? WHERE id = ?");
            $stmt->execute([$status, $request_id]);
            $success = "Request $status successfully.";
        } else {
            $error = "Invalid request ID.";
        }
    } elseif ($_POST['action'] === 'bulk_approve' && !empty($_POST['request_ids'])) {
        $ids = array_map('intval', $_POST['request_ids']);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("UPDATE time_off_requests SET status = 'approved' WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $success = $stmt->rowCount() . " request(s) approved.";
    }
}

$filters = [
    'status' => $_GET['status'] ?? '',
    'department' => $_GET['department'] ?? '',
    'leave_type' => $_GET['leave_type'] ?? '',
    'employee_id' => (int)($_GET['employee_id'] ?? ''),
    'search' => $_GET['search'] ?? '',
    'start_date' => $_GET['start_date'] ?? '',
    'end_date' => $_GET['end_date'] ?? '',
    'order_by' => $_GET['order_by'] ?? 'r.created_at DESC'
];

$page = max(1, (int)($_GET['page'] ?? 1));
$records_per_page = 10;
$offset = ($page - 1) * $records_per_page;

$pending_requests = getPendingRequests($pdo);
$all_requests = getAllRequests($pdo, $filters, $records_per_page, $offset);
$total_records = getTotalRecords($pdo, $filters);
$total_pages = ceil($total_records / $records_per_page);
$departments = getAllDepartments($pdo);

$stats = array_fill_keys(['pending', 'approved', 'rejected', 'vacation', 'sick', 'personal', 'bereavement', 'other'], 0);
$stmt = $pdo->prepare("SELECT status, COUNT(*) as count FROM time_off_requests GROUP BY status");
$stmt->execute();
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $stats[$row['status']] = $row['count'];
}
$stmt = $pdo->prepare("SELECT leave_type, COUNT(*) as count FROM time_off_requests GROUP BY leave_type");
$stmt->execute();
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $stats[$row['leave_type']] = $row['count'];
}

$current_month = date('Y-m');
$stmt = $pdo->prepare("SELECT COUNT(*) FROM time_off_requests 
                       WHERE DATE_FORMAT(start_date, '%Y-%m') = ? OR DATE_FORMAT(end_date, '%Y-%m') = ?");
$stmt->execute([$current_month, $current_month]);
$stats['current_month'] = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT e.id, e.first_name, e.last_name, r.start_date, r.end_date, r.leave_type, r.status 
                       FROM time_off_requests r JOIN employees e ON r.employee_id = e.id
                       WHERE (DATE_FORMAT(start_date, '%Y-%m') = ? OR DATE_FORMAT(end_date, '%Y-%m') = ?) AND r.status = 'approved'");
$stmt->execute([$current_month, $current_month]);
$calendar_data = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $start = new DateTime($row['start_date']);
    $end = new DateTime($row['end_date']);
    $end->modify('+1 day');
    foreach (new DatePeriod($start, new DateInterval('P1D'), $end) as $date) {
        $date_str = $date->format('Y-m-d');
        $calendar_data[$date_str][] = [
            'name' => $row['first_name'] . ' ' . $row['last_name'],
            'type' => $row['leave_type']
        ];
    }
}

$stmt = $pdo->prepare("SELECT e.department, COUNT(*) as count 
                       FROM time_off_requests r JOIN employees e ON r.employee_id = e.id GROUP BY e.department");
$stmt->execute();
$dept_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT DATE_FORMAT(start_date, '%Y-%m') as month, COUNT(*) as count 
                       FROM time_off_requests WHERE start_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                       GROUP BY DATE_FORMAT(start_date, '%Y-%m') ORDER BY month");
$stmt->execute();
$monthly_trends = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Time Off | HRPro</title>
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
            transition: var(--transition);
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
            border: none;
        }

        .table thead th {
            background: var(--primary);
            color: var(--white);
            padding: 14px;
        }

        .table tbody tr:hover {
            background: rgba(191, 162, 219, 0.1);
        }

        .pagination a {
            padding: 10px 15px;
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--text);
            text-decoration: none;
            box-shadow: var(--shadow);
            margin: 0 5px;
        }

        .pagination a.active {
            background: var(--primary);
            color: var(--white);
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
            <a href="timeoff.php" class="menu-item active"><i class="fas fa-calendar-minus"></i><span class="menu-text">Time Off</span></a>
            <a href="emergency.php" class="menu-item"><i class="fas fa-bell"></i><span class="menu-text">Emergency</span></a>
            <a href="performance.php" class="menu-item"><i class="fas fa-chart-line"></i><span class="menu-text">Performance</span></a>
            <a href="professionalpath.php" class="menu-item"><i class="fas fa-briefcase"></i><span class="menu-text">Professional Path</span></a>
            <a href="inbox.php" class="menu-item"><i class="fas fa-inbox"></i><span class="menu-text">Inbox</span></a>
            <a href="addEmployees.php" class="menu-item"><i class="fas fa-user-plus"></i><span class="menu-text">Add Employee</span></a>
            <a href="login.html" class="menu-item"><i class="fas fa-sign-out-alt"></i><span class="menu-text">Logout</span></a>
        </nav>
    </aside>

    <main class="main-content">
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-calendar-minus me-2"></i>Time Off Management</h2>
            </div>
            <div class="card-body">
                <?php if ($success): ?>
                    <div class="alert-success"><i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert-error"><i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <ul class="nav nav-tabs mb-4" id="timeoffTabs">
                    <li class="nav-item">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#pending">Pending</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#all">All Requests</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#calendar">Calendar</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#reports">Reports</button>
                    </li>
                </ul>

                <div class="tab-content">
                    <div class="tab-pane fade show active" id="pending">
                        <?php if (empty($pending_requests)): ?>
                            <div class="text-center p-4">No pending requests.</div>
                        <?php else: ?>
                            <form method="POST" id="bulkActionForm" class="mb-4">
                                <input type="hidden" name="action" value="bulk_approve">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <button type="submit" class="btn btn-primary" id="bulkApproveBtn" disabled><i class="fas fa-check me-2"></i>Approve Selected</button>
                            </form>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th><input type="checkbox" id="selectAll"></th>
                                        <th>Employee</th>
                                        <th>Department</th>
                                        <th>Type</th>
                                        <th>Duration</th>
                                        <th>Dates</th>
                                        <th>Requested</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pending_requests as $request): ?>
                                        <tr>
                                            <td><input type="checkbox" name="request_ids[]" value="<?php echo $request['id']; ?>" class="request-checkbox" form="bulkActionForm"></td>
                                            <td><?php echo htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?></td>
                                            <td><?php echo htmlspecialchars($request['department']); ?></td>
                                            <td><span class="badge <?php echo $request['leave_type']; ?>-badge"><?php echo ucfirst($request['leave_type']); ?></span></td>
                                            <td><?php echo $request['business_days']; ?> business days</td>
                                            <td><?php echo date('M d, Y', strtotime($request['start_date'])); ?> - <?php echo date('M d, Y', strtotime($request['end_date'])); ?></td>
                                            <td><?php echo date('M d, Y', strtotime($request['created_at'])); ?></td>
                                            <td>
                                                <button class="btn btn-sm btn-success approve-btn" 
                                                        data-request-id="<?php echo $request['id']; ?>"
                                                        data-employee-id="<?php echo $request['employee_id']; ?>"
                                                        data-employee-name="<?php echo htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?>"
                                                        data-leave-type="<?php echo $request['leave_type']; ?>"
                                                        data-start-date="<?php echo $request['start_date']; ?>"
                                                        data-end-date="<?php echo $request['end_date']; ?>"
                                                        data-notes="<?php echo htmlspecialchars($request['notes']); ?>">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                                <button class="btn btn-sm btn-danger reject-btn"
                                                        data-request-id="<?php echo $request['id']; ?>"
                                                        data-employee-name="<?php echo htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?>"
                                                        data-leave-type="<?php echo $request['leave_type']; ?>"
                                                        data-start-date="<?php echo $request['start_date']; ?>"
                                                        data-end-date="<?php echo $request['end_date']; ?>">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>

                    <div class="tab-pane fade" id="all">
                        <form method="GET" class="row g-3 mb-4">
                            <div class="col-md-4">
                                <select name="status" class="form-select">
                                    <option value="">All Statuses</option>
                                    <option value="pending" <?php echo $filters['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="approved" <?php echo $filters['status'] === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                    <option value="rejected" <?php echo $filters['status'] === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <select name="department" class="form-select">
                                    <option value="">All Departments</option>
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $filters['department'] === $dept ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($dept); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <select name="leave_type" class="form-select">
                                    <option value="">All Types</option>
                                    <option value="vacation" <?php echo $filters['leave_type'] === 'vacation' ? 'selected' : ''; ?>>Vacation</option>
                                    <option value="sick" <?php echo $filters['leave_type'] === 'sick' ? 'selected' : ''; ?>>Sick</option>
                                    <option value="personal" <?php echo $filters['leave_type'] === 'personal' ? 'selected' : ''; ?>>Personal</option>
                                    <option value="bereavement" <?php echo $filters['leave_type'] === 'bereavement' ? 'selected' : ''; ?>>Bereavement</option>
                                    <option value="other" <?php echo $filters['leave_type'] === 'other' ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <input type="text" class="form-control datepicker" name="start_date" placeholder="From Date" value="<?php echo htmlspecialchars($filters['start_date']); ?>">
                            </div>
                            <div class="col-md-4">
                                <input type="text" class="form-control datepicker" name="end_date" placeholder="To Date" value="<?php echo htmlspecialchars($filters['end_date']); ?>">
                            </div>
                            <div class="col-md-4">
                                <input type="text" class="form-control" name="search" placeholder="Search Employee" value="<?php echo htmlspecialchars($filters['search']); ?>">
                            </div>
                            <div class="col-md-4">
                                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search me-2"></i>Search</button>
                            </div>
                            <input type="hidden" name="order_by" value="<?php echo htmlspecialchars($filters['order_by']); ?>">
                        </form>

                        <table class="table">
                            <thead>
                                <tr>
                                    <th><a href="?order_by=<?php echo $filters['order_by'] === 'e.first_name ASC' ? 'e.first_name DESC' : 'e.first_name ASC'; ?>">Employee</a></th>
                                    <th>Department</th>
                                    <th>Type</th>
                                    <th>Duration</th>
                                    <th>Dates</th>
                                    <th>Status</th>
                                    <th><a href="?order_by=<?php echo $filters['order_by'] === 'r.created_at DESC' ? 'r.created_at ASC' : 'r.created_at DESC'; ?>">Requested</a></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($all_requests as $request): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?></td>
                                        <td><?php echo htmlspecialchars($request['department']); ?></td>
                                        <td><span class="badge <?php echo $request['leave_type']; ?>-badge"><?php echo ucfirst($request['leave_type']); ?></span></td>
                                        <td><?php echo $request['business_days']; ?> business days</td>
                                        <td><?php echo date('M d, Y', strtotime($request['start_date'])); ?> - <?php echo date('M d, Y', strtotime($request['end_date'])); ?></td>
                                        <td><span class="badge <?php echo $request['status']; ?>-badge"><?php echo ucfirst($request['status']); ?></span></td>
                                        <td><?php echo date('M d, Y', strtotime($request['created_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <?php if ($total_pages > 1): ?>
                            <div class="pagination d-flex justify-content-center mt-4">
                                <?php if ($page > 1): ?>
                                    <a href="?page=<?php echo $page - 1; ?>">Previous</a>
                                <?php endif; ?>
                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <a href="?page=<?php echo $i; ?>" class="<?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                                <?php endfor; ?>
                                <?php if ($page < $total_pages): ?>
                                    <a href="?page=<?php echo $page + 1; ?>">Next</a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="tab-pane fade" id="calendar">
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
                    </div>

                    <div class="tab-pane fade" id="reports">
                        <div class="row">
                            <div class="col-md-6 mb-4">
                                <div class="card">
                                    <div class="card-header"><h5>Time Off by Type</h5></div>
                                    <div class="card-body"><canvas id="timeOffByTypeChart"></canvas></div>
                                </div>
                            </div>
                            <div class="col-md-6 mb-4">
                                <div class="card">
                                    <div class="card-header"><h5>Time Off by Department</h5></div>
                                    <div class="card-body"><canvas id="timeOffByDepartmentChart"></canvas></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <div class="modal fade" id="approveModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">Approve Request</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="approve">
                        <input type="hidden" name="request_id" id="approve_request_id">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <h6 id="approve_employee_name"></h6>
                        <p id="approve_request_details" class="text-muted"></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Approve</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="rejectModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Reject Request</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="request_id" id="reject_request_id">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <h6 id="reject_employee_name"></h6>
                        <p id="reject_request_details" class="text-muted"></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Reject</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        document.getElementById('sidebarToggle').addEventListener('click', () => {
            document.querySelector('.sidebar').classList.toggle('collapsed');
        });

        flatpickr('.datepicker', { dateFormat: 'Y-m-d' });

        const selectAll = document.getElementById('selectAll');
        const checkboxes = document.querySelectorAll('.request-checkbox');
        const bulkApproveBtn = document.getElementById('bulkApproveBtn');

        selectAll?.addEventListener('change', () => {
            checkboxes.forEach(cb => cb.checked = selectAll.checked);
            bulkApproveBtn.disabled = !Array.from(checkboxes).some(cb => cb.checked);
        });

        checkboxes.forEach(cb => cb.addEventListener('change', () => {
            bulkApproveBtn.disabled = !Array.from(checkboxes).some(cb => cb.checked);
        }));

        document.querySelectorAll('.approve-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const modal = new bootstrap.Modal(document.getElementById('approveModal'));
                document.getElementById('approve_request_id').value = btn.dataset.requestId;
                document.getElementById('approve_employee_name').textContent = btn.dataset.employeeName;
                document.getElementById('approve_request_details').innerHTML = 
                    `${btn.dataset.leaveType} leave from ${formatDate(btn.dataset.startDate)} to ${formatDate(btn.dataset.endDate)}` +
                    (btn.dataset.notes ? `<br><small>Notes: ${btn.dataset.notes}</small>` : '');
                modal.show();
            });
        });

        document.querySelectorAll('.reject-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const modal = new bootstrap.Modal(document.getElementById('rejectModal'));
                document.getElementById('reject_request_id').value = btn.dataset.requestId;
                document.getElementById('reject_employee_name').textContent = btn.dataset.employeeName;
                document.getElementById('reject_request_details').innerHTML = 
                    `${btn.dataset.leaveType} leave from ${formatDate(btn.dataset.startDate)} to ${formatDate(btn.dataset.endDate)}`;
                modal.show();
            });
        });

        function formatDate(dateStr) {
            return new Date(dateStr).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        }

        let currentDate = new Date();
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
                                html += `<div class="day-event ${event.type}-badge" title="${event.name}">${event.name}</div>`;
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

        const calendarData = <?php echo isset($calendar_data) ? json_encode($calendar_data) : '{}'; ?>;
        renderCalendar();

        new Chart(document.getElementById('timeOffByTypeChart'), {
            type: 'pie',
            data: {
                labels: ['Vacation', 'Sick', 'Personal', 'Bereavement', 'Other'],
                datasets: [{
                    data: [
                        <?php echo $stats['vacation']; ?>,
                        <?php echo $stats['sick']; ?>,
                        <?php echo $stats['personal']; ?>,
                        <?php echo $stats['bereavement']; ?>,
                        <?php echo $stats['other']; ?>
                    ],
                    backgroundColor: ['#64b5f6', '#ef5350', '#9575cd', '#4db6ac', '#ffb74d']
                }]
            }
        });

        new Chart(document.getElementById('timeOffByDepartmentChart'), {
            type: 'bar',
            data: {
                labels: [<?php echo implode(',', array_map(fn($d) => "'".addslashes($d['department'])."'", $dept_stats)); ?>],
                datasets: [{
                    label: 'Requests',
                    data: [<?php echo implode(',', array_column($dept_stats, 'count')); ?>],
                    backgroundColor: '#4B3F72'
                }]
            },
            options: {
                scales: { y: { beginAtZero: true } }
            }
        });
    </script>
</body>
</html>