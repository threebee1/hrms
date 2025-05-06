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

// Fetch the role of the logged-in user
$query_role = "SELECT role FROM users WHERE id = ?";
$stmt_role = $pdo->prepare($query_role);
$stmt_role->execute([$user_id]);
$user_data = $stmt_role->fetch(PDO::FETCH_ASSOC);

// Check if user exists and has HR role
if (!$user_data || $user_data['role'] !== 'hr') {
    header('Location: dashboard.php');
    exit();
}

// Function to convert AM/PM time to 24-hour format
function convertTo24Hour($time) {
    return date("H:i:s", strtotime($time));
}

// Function to calculate total hours worked
function calculateTotalHours($clock_in, $clock_out, $break_duration) {
    $clock_in_time = strtotime($clock_in);
    $clock_out_time = strtotime($clock_out);
    
    if ($clock_out_time <= $clock_in_time) {
        $clock_out_time += 86400; // Add 24 hours if clock out is next day
    }
    
    $total_seconds = $clock_out_time - $clock_in_time;
    $total_hours = ($total_seconds - ($break_duration * 60)) / 3600;
    
    return max(0, $total_hours); // Ensure we don't return negative hours
}

// Function to format total_hours as HH:MM
function formatTotalHours($total_hours) {
    if (!isset($total_hours) || $total_hours === null) {
        return 'N/A';
    }
    $hours = floor($total_hours);
    $minutes = round(($total_hours - $hours) * 60);
    return sprintf("%02d:%02d", $hours, $minutes);
}

// Handle export requests
if (isset($_GET['export'])) {
    $export_type = $_GET['export'];
    $search_employee_id = isset($_GET['employee_id']) ? intval($_GET['employee_id']) : null;
    $search_date = isset($_GET['date']) ? $_GET['date'] : null;
    $sort = isset($_GET['sort']) ? $_GET['sort'] : 'date_desc';
   
    // Validate date format if provided
    if ($search_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $search_date)) {
        $search_date = null;
    }
   
    // Generate the data for export
    $export_data = getTimesheetData($pdo, $search_employee_id, $search_date, $sort);
   
    if ($export_type === 'pdf') {
        exportAsPDF($export_data);
    } elseif ($export_type === 'excel') {
        exportAsExcel($export_data);
    }
    exit();
}

// Function to get timesheet data
function getTimesheetData($pdo, $employee_id = null, $date = null, $sort = 'date_desc') {
    $whereClauses = [];
    $params = [];
    
    if ($employee_id) {
        $whereClauses[] = "ts.employee_id = ?";
        $params[] = $employee_id;
    }
    if ($date) {
        $whereClauses[] = "ts.date = ?";
        $params[] = $date;
    }

    $whereSql = '';
    if (count($whereClauses) > 0) {
        $whereSql = 'WHERE ' . implode(' AND ', $whereClauses);
    }

    // Validate sort parameter
    $valid_sorts = ['date_desc', 'date_asc', 'name_asc', 'name_desc'];
    if (!in_array($sort, $valid_sorts)) {
        $sort = 'date_desc';
    }

    // Determine ORDER BY clause based on sort parameter
    $orderBy = '';
    switch ($sort) {
        case 'name_asc':
            $orderBy = 'ORDER BY e.first_name ASC, e.last_name ASC';
            break;
        case 'name_desc':
            $orderBy = 'ORDER BY e.first_name DESC, e.last_name DESC';
            break;
        case 'date_asc':
            $orderBy = 'ORDER BY ts.date ASC';
            break;
        case 'date_desc':
        default:
            $orderBy = 'ORDER BY ts.date DESC';
            break;
    }

    $query = "SELECT ts.id, e.first_name, e.last_name, ts.date, ts.clock_in, ts.clock_out,
                     ts.break_duration, ts.total_hours
              FROM timesheets ts
              JOIN employees e ON ts.employee_id = e.id
              $whereSql
              $orderBy";

    $stmt = $pdo->prepare($query);
    if (!empty($params)) {
        $stmt->execute($params);
    } else {
        $stmt->execute();
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to export as PDF
function exportAsPDF($data) {
    require_once('tcpdf/tcpdf.php');
   
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator('Timesheet Management System');
    $pdf->SetAuthor('Your Company');
    $pdf->SetTitle('Timesheet Report');
    $pdf->SetSubject('Timesheet Data');
    $pdf->SetKeywords('Timesheet, Report, PDF');
   
    $pdf->AddPage();
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 15, 'Timesheet Report', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
   
    // Table header
    $html = '<table border="1" cellpadding="4">
        <tr style="background-color:#f2f2f2;">
            <th><b>Employee</b></th>
            <th><b>Date</b></th>
            <th><b>Clock In</b></th>
            <th><b>Clock Out</b></th>
            <th><b>Break (mins)</b></th>
            <th><b>Total Hours</b></th>
        </tr>';
   
    // Table data
    foreach ($data as $row) {
        $html .= '<tr>
            <td>' . htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) . '</td>
            <td>' . htmlspecialchars($row['date']) . '</td>
            <td>' . htmlspecialchars($row['clock_in']) . '</td>
            <td>' . htmlspecialchars($row['clock_out']) . '</td>
            <td>' . htmlspecialchars($row['break_duration']) . '</td>
            <td>' . htmlspecialchars(formatTotalHours($row['total_hours'])) . '</td>
        </tr>';
    }
   
    $html .= '</table>';
   
    $pdf->writeHTML($html, true, false, false, false, '');
    $pdf->Output('timesheet_report_'.date('Ymd').'.pdf', 'D');
}

// Function to export as Excel
function exportAsExcel($data) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment;filename="timesheet_report_'.date('Ymd').'.xls"');
    header('Cache-Control: max-age=0');
   
    echo '<table border="1">
        <tr>
            <th>Employee</th>
            <th>Date</th>
            <th>Clock In</th>
            <th>Clock Out</th>
            <th>Break (mins)</th>
            <th>Total Hours</th>
        </tr>';
   
    foreach ($data as $row) {
        echo '<tr>
            <td>' . htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) . '</td>
            <td>' . htmlspecialchars($row['date']) . '</td>
            <td>' . htmlspecialchars($row['clock_in']) . '</td>
            <td>' . htmlspecialchars($row['clock_out']) . '</td>
            <td>' . htmlspecialchars($row['break_duration']) . '</td>
            <td>' . htmlspecialchars(formatTotalHours($row['total_hours'])) . '</td>
        </tr>';
    }
   
    echo '</table>';
    exit();
}

// Handle timesheet submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_timesheet'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('Invalid CSRF token');
    }

    $employee_id = intval($_POST['employee_id']);
    $date = $_POST['date'];
    $clock_in = $_POST['clock_in'];
    $clock_out = $_POST['clock_out'];
    $break_duration = intval($_POST['break_duration']);
    
    // Convert times to 24-hour format for comparison
    $clock_in_24 = convertTo24Hour($clock_in);
    $clock_out_24 = convertTo24Hour($clock_out);
    
    // Calculate total hours
    $total_hours = calculateTotalHours($clock_in_24, $clock_out_24, $break_duration);
    
    // Insert the timesheet record
    $insert_query = "INSERT INTO timesheets 
                    (employee_id, date, clock_in, clock_out, break_duration, total_hours, status) 
                    VALUES (?, ?, ?, ?, ?, ?, 'approved')";
    $insert_stmt = $pdo->prepare($insert_query);
    $insert_stmt->execute([
        $employee_id,
        $date,
        $clock_in,
        $clock_out,
        $break_duration,
        $total_hours
    ]);
    
    header('Location: timesheet.php?success=1');
    exit();
}

// Fetch timesheet data for display
$search_employee_id = isset($_GET['employee_id']) ? intval($_GET['employee_id']) : null;
$search_date = isset($_GET['date']) ? $_GET['date'] : null;
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'date_desc';

// Validate date format if provided
if ($search_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $search_date)) {
    $search_date = null;
}

// Log sort parameter for debugging
error_log("Sort parameter received: " . $sort);

$result = getTimesheetData($pdo, $search_employee_id, $search_date, $sort);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Timesheet Management | HRPro</title>
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
            --border-radius: 12px;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            --shadow-hover: 0 8px 24px rgba(0, 0, 0, 0.15);
            --transition: all 0.3s ease;
            --focus-ring: 0 0 0 3px rgba(191, 162, 219, 0.3);
            --gradient: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            --reset-gradient: linear-gradient(135deg, #FF6B6B, #FF8E8E);
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

        /* Main Content Styles */
        .main-content {
            flex: 1;
            overflow-y: auto;
            padding-bottom: 60px;
            background-color: var(--white);
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
            position: relative;
            text-align: center;
        }

        .card-header h2 {
            font-family: 'Poppins', sans-serif;
            font-size: 24px;
            margin: 0;
        }

        .close-btn {
            position: absolute;
            top: 20px;
            right: 20px;
            color: var(--white);
            font-size: 1.5rem;
            opacity: 0.8;
            transition: var(--transition);
            text-decoration: none;
        }

        .close-btn:hover {
            opacity: 1;
            transform: scale(1.1);
            color: var(--white);
        }

        .card-body {
            padding: 30px;
        }

        .alert-success {
            background: rgba(75, 181, 67, 0.15);
            color: var(--success);
            border: 1px solid rgba(75, 181, 67, 0.3);
            padding: 15px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            box-shadow: var(--shadow);
            animation: fadeIn 0.5s ease;
        }

        .alert-success i {
            margin-right: 10px;
            font-size: 20px;
        }

        .search-card {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: var(--shadow);
            transition: var(--transition);
        }

        .search-card:hover {
            box-shadow: var(--shadow-hover);
            transform: translateY(-2px);
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
            padding: 12px 20px;
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

        .btn-reset {
            background: var(--reset-gradient);
            color: var(--white);
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
        }

        .btn-reset:hover {
            background: #FF8E8E;
            box-shadow: var(--shadow-hover);
            transform: translateY(-2px);
        }

        .btn-reset::before {
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

        .btn-reset:hover::before {
            width: 200px;
            height: 200px;
        }

        .btn-export {
            background: linear-gradient(135deg, #28a745, #34c759);
            color: var(--white);
            box-shadow: var(--shadow);
        }

        .btn-export:hover {
            background: #34c759;
            box-shadow: var(--shadow-hover);
            transform: translateY(-2px);
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
            .form-grid {
                grid-template-columns: 1fr;
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
                <i class="fas fa-users logo-icon"></i>
                <h1 class="logo-text">HRPro</h1>
            </div>
            <button class="toggle-btn" id="sidebarToggle">
                <i class="fas fa-chevron-left"></i>
            </button>
        </div>
        <nav class="sidebar-menu">
            <a href="dashboard.php" class="menu-item">
                <i class="fas fa-home"></i>
                <span class="menu-text">Home</span>
            </a>
            <a href="personal.php" class="menu-item">
                <i class="fas fa-user"></i>
                <span class="menu-text">Personal</span>
            </a>
            <a href="timesheet.php" class="menu-item active">
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

    <!-- Main Content -->
    <main class="main-content">
        <div class="content">
            <div class="card">
                <div class="card-header position-relative">
                    <h2><i class="fas fa-clock me-2"></i>Timesheet Management</h2>
                    <a href="dashboard.php" class="close-btn"><i class="fas fa-times"></i></a>
                </div>
                
                <div class="card-body">
                    <!-- Display success message if submission was successful -->
                    <?php if (isset($_GET['success']) && $_GET['success'] === '1'): ?>
                        <div class="alert-success" role="alert">
                            <i class="fas fa-check-circle"></i>
                            Timesheet record added successfully!
                        </div>
                    <?php endif; ?>
                    
                    <!-- Search Form -->
                    <div class="search-card">
                        <form action="timesheet.php" method="GET" class="form-grid" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px;">
                            <div>
                                <label for="employee_id" class="form-label">Employee</label>
                                <select name="employee_id" id="employee_id" class="form-select">
                                    <option value="">All Employees</option>
                                    <?php
                                    $employeeQuery = "SELECT id, first_name, last_name FROM employees ORDER BY last_name, first_name";
                                    $stmt = $pdo->query($employeeQuery);
                                    while ($employee = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                        $selected = ($search_employee_id == $employee['id']) ? 'selected' : '';
                                        echo "<option value='" . htmlspecialchars($employee['id']) . "' $selected>" .
                                             htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']) . "</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            
                            <div>
                                <label for="date" class="form-label">Date</label>
                                <input type="date" id="date" name="date" class="form-control" value="<?php echo htmlspecialchars($search_date); ?>">
                            </div>
                            
                            <div>
                                <label for="sort" class="form-label">Sort By</label>
                                <select name="sort" id="sort" class="form-select">
                                    <option value="date_desc" <?php echo $sort === 'date_desc' ? 'selected' : ''; ?>>Date (Newest First)</option>
                                    <option value="date_asc" <?php echo $sort === 'date_asc' ? 'selected' : ''; ?>>Date (Oldest First)</option>
                                    <option value="name_asc" <?php echo $sort === 'name_asc' ? 'selected' : ''; ?>>Name (A-Z)</option>
                                    <option value="name_desc" <?php echo $sort === 'name_desc' ? 'selected' : ''; ?>>Name (Z-A)</option>
                                </select>
                            </div>
                            
                            <div style="display: flex; align-items: flex-end; gap: 10px;">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-search me-2"></i>Search
                                </button>
                                <a href="timesheet.php?sort=date_desc" class="btn btn-reset w-100">
                                    <i class="fas fa-undo me-2"></i>Reset Filter
                                </a>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Export Buttons -->
                    <div class="d-flex justify-content-end mb-4 gap-2">
                        <a href="?export=pdf&employee_id=<?php echo urlencode($search_employee_id); ?>&date=<?php echo urlencode($search_date); ?>&sort=<?php echo urlencode($sort); ?>"
                           class="btn btn-export">
                            <i class="fas fa-file-pdf me-2"></i>Export as PDF
                        </a>
                        <a href="?export=excel&employee_id=<?php echo urlencode($search_employee_id); ?>&date=<?php echo urlencode($search_date); ?>&sort=<?php echo urlencode($sort); ?>"
                           class="btn btn-export">
                            <i class="fas fa-file-excel me-2"></i>Export as Excel
                        </a>
                    </div>
                    
                    <!-- Timesheet Table -->
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Date</th>
                                    <th>Clock In</th>
                                    <th>Clock Out</th>
                                    <th>Break (mins)</th>
                                    <th>Total Hours</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($result) > 0): ?>
                                    <?php foreach ($result as $row): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></td>
                                            <td><?php echo htmlspecialchars($row['date']); ?></td>
                                            <td><?php echo htmlspecialchars($row['clock_in']); ?></td>
                                            <td><?php echo htmlspecialchars($row['clock_out']); ?></td>
                                            <td><?php echo htmlspecialchars($row['break_duration']); ?></td>
                                            <td><?php echo htmlspecialchars(formatTotalHours($row['total_hours'])); ?></td>
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
    <script>
        // Sidebar Toggle
        document.getElementById('sidebarToggle').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('collapsed');
        });

        // Set today's date as default if no date is selected
        document.addEventListener('DOMContentLoaded', function() {
            const dateInput = document.getElementById('date');
            if (!dateInput.value) {
                const today = new Date().toISOString().split('T')[0];
                dateInput.value = today;
            }

            // Auto-dismiss alerts after 5 seconds
            const alert = document.querySelector('.alert-success');
            if (alert) {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 300);
                }, 5000);
            }
        });
    </script>
</body>
</html>