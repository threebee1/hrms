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

// Fetch user and employee data
$stmt = $pdo->prepare("
    SELECT u.id, u.username, u.email, u.role, u.password AS hashed_password, 
           e.first_name, e.last_name, e.phone, e.birthdate, e.address, e.department, e.position, e.hire_date
    FROM users u
    JOIN employees e ON u.id = e.id
    WHERE u.id = ?
");
try {
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("User query failed: " . $e->getMessage());
    die('Internal server error');
}

if (!$user) {
    session_destroy();
    header('Location: login.php?error=user_not_found');
    exit();
}

// Format birthdate for display (YYYY/MM/DD)
$formatted_birthdate = $user['birthdate'] ? date('Y/m/d', strtotime($user['birthdate'])) : '';

$error_message = '';
$success_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $birthdate = trim($_POST['birthdate'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');
    $old_password = trim($_POST['old_password'] ?? '');

    // Validate inputs
    if (empty($first_name) || empty($last_name) || empty($email) || empty($phone) || empty($birthdate) || empty($address)) {
        $error_message = "All fields are required.";
    } else {
        // Validate birthdate format (YYYY/MM/DD)
        $birthdate_valid = preg_match('/^\d{4}\/\d{2}\/\d{2}$/', $birthdate);
        $birthDate = $birthdate_valid ? DateTime::createFromFormat('Y/m/d', $birthdate) : false;

        if (!$birthdate_valid || !$birthDate) {
            $error_message = "Invalid birthdate format. Please use YYYY/MM/DD.";
        } else {
            // Calculate age
            $today = new DateTime();
            $age = $today->diff($birthDate)->y;

            if ($age < 20 || $age > 65) {
                $error_message = "Age must be between 20 and 65 years.";
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error_message = "Invalid email format.";
            } elseif (!preg_match("/^[0-9\-\(\)\/\+\s]*$/", $phone)) {
                $error_message = "Invalid phone number format.";
            } elseif (!empty($password)) {
                if (empty($old_password)) {
                    $error_message = "Please enter your current password to change it.";
                } else {
                    if (!password_verify($old_password, $user['hashed_password'])) {
                        $error_message = "Current password is incorrect.";
                    } elseif ($password !== $confirm_password) {
                        $error_message = "New passwords do not match.";
                    } elseif (strlen($password) < 8 || !preg_match("/[a-zA-Z]/", $password) || !preg_match("/\d/", $password)) {
                        $error_message = "Password must be at least 8 characters long and include letters and numbers.";
                    }
                }
            }

            if (empty($error_message)) {
                $mysql_birthdate = $birthDate->format('Y-m-d');
                try {
                    $pdo->beginTransaction();

                    // Update users table
                    $update_user = $pdo->prepare("UPDATE users SET email = ? WHERE id = ?");
                    $update_user->execute([$email, $user_id]);

                    // Update password if provided
                    if (!empty($password)) {
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        $update_pwd = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                        $update_pwd->execute([$hashed_password, $user_id]);
                    }

                    // Update employees table
                    $update_employee = $pdo->prepare("
                        UPDATE employees 
                        SET first_name = ?, last_name = ?, phone = ?, birthdate = ?, address = ? 
                        WHERE id = ?
                    ");
                    $update_employee->execute([$first_name, $last_name, $phone, $mysql_birthdate, $address, $user_id]);

                    $pdo->commit();
                    $success_message = "Your information has been updated successfully!";

                    // Refresh user data
                    $stmt->execute([$user_id]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    $formatted_birthdate = $user['birthdate'] ? date('Y/m/d', strtotime($user['birthdate'])) : '';

                } catch (Exception $e) {
                    $pdo->rollBack();
                    error_log("Update failed: " . $e->getMessage());
                    $error_message = "Error updating profile. Please try again.";
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Personal Information | HRPro</title>
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

        /* Content Styles */
        .content {
            max-width: 1200px;
            margin: 40px auto;
            padding: 0 30px;
        }

        .personal-section {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 30px;
            box-shadow: var(--shadow);
            transition: var(--transition);
        }

        .personal-section:hover {
            box-shadow: var(--shadow-hover);
            transform: translateY(-4px);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            border-bottom: 1px solid var(--gray);
            padding-bottom: 10px;
        }

        .section-title {
            font-family: 'Poppins', sans-serif;
            font-size: 24px;
            color: var(--primary);
            font-weight: 600;
        }

        .profile-info-container {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 24px;
            margin-bottom: 30px;
        }

        .profile-card {
            background: linear-gradient(135deg, var(--light) 0%, var(--white) 100%);
            border-radius: var(--border-radius);
            padding: 20px;
            box-shadow: var(--shadow);
            transition: var(--transition);
        }

        .profile-card:hover {
            box-shadow: var(--shadow-hover);
            transform: translateY(-2px);
        }

        .profile-card h5 {
            font-family: 'Poppins', sans-serif;
            font-size: 18px;
            color: var(--primary);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
        }

        .profile-card p {
            font-size: 14px;
            color: var(--text);
            margin-bottom: 10px;
        }

        .profile-card p strong {
            color: var(--text-light);
            font-weight: 500;
            margin-right: 8px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 24px;
            margin-bottom: 30px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group.full-width {
            grid-column: span 2;
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
            border: 1px solid var(--gray);
            border-radius: var(--border-radius);
            font-size: 14px;
            background: var(--white);
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--secondary);
            box-shadow: var(--focus-ring);
            background: rgba(191, 162, 219, 0.05);
        }

        .form-control:invalid:focus {
            border-color: var(--error);
            box-shadow: 0 0 0 3px rgba(255, 107, 107, 0.3);
        }

        textarea.form-control {
            min-height: 120px;
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
        }

        .btn-primary {
            background: var(--gradient);
            color: var(--white);
            border: none;
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

        .password-section {
            grid-column: span 2;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid var(--gray);
        }

        .password-field {
            position: relative;
        }

        .password-field .form-control {
            padding-right: 40px; /* Space for the eye icon */
            border: 2px solid var(--gray);
            background: var(--white);
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.05);
            transition: var(--transition);
        }

        .password-field .form-control:focus {
            border-color: var(--secondary);
            box-shadow: var(--focus-ring), inset 0 2px 4px rgba(0, 0, 0, 0.05);
            background: var(--white);
        }

        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-light);
            cursor: pointer;
            font-size: 16px;
            padding: 8px;
            transition: var(--transition);
        }

        .password-toggle:hover {
            color: var(--primary);
        }

        .password-toggle i {
            display: block;
        }

        .form-text {
            font-size: 12px;
            color: var(--text-light);
            margin-top: 5px;
        }

        /* Checkbox Styling */
        .password-section .form-group {
            display: flex;
            flex-direction: row;
            align-items: center;
            gap: 10px;
        }

        .password-section input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin: 0;
            cursor: pointer;
            accent-color: var(--primary);
            border-radius: 4px;
        }

        .password-section .form-label {
            margin-bottom: 0;
            cursor: pointer;
        }

        /* Flatpickr Custom Styles */
        .flatpickr-calendar {
            background: var(--white);
            border: 1px solid var(--gray);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            font-family: 'Outfit', sans-serif;
        }

        .flatpickr-month {
            color: var(--text);
            font-family: 'Poppins', sans-serif;
            font-weight: 600;
        }

        .flatpickr-weekdays {
            border-bottom: 1px solid var(--gray);
            padding-bottom: 8px;
        }

        .flatpickr-weekday {
            color: var(--text-light);
            font-size: 12px;
        }

        .flatpickr-day {
            border-radius: var(--border-radius);
            color: var(--text);
            transition: var(--transition);
        }

        .flatpickr-day:hover {
            background: var(--light);
            box-shadow: var(--shadow);
        }

        .flatpickr-day.selected,
        .flatpickr-day.startRange,
        .flatpickr-day.endRange {
            background: var(--primary);
            color: var(--white);
            border-color: var(--primary);
        }

        .flatpickr-day.today {
            background: var(--secondary);
            color: var(--white);
            font-weight: 600;
        }

        .flatpickr-day.disabled,
        .flatpickr-day.prevMonthDay,
        .flatpickr-day.nextMonthDay {
            color: var(--text-light);
            opacity: 0.5;
        }

        .flatpickr-prev-month,
        .flatpickr-next-month {
            color: var(--text-light);
            border-radius: var(--border-radius);
        }

        .flatpickr-prev-month:hover,
        .flatpickr-next-month:hover {
            color: var(--primary);
            background: var(--light);
        }

        .numInputWrapper input {
            border: 1px solid var(--gray);
            border-radius: var(--border-radius);
            padding: 5px;
        }

        .flatpickr-current-month .numInputWrapper span.arrowUp::after,
        .flatpickr-current-month .numInputWrapper span.arrowDown::after {
            border-top-color: var(--text);
        }

        .age-validation {
            font-size: 12px;
            color: var(--error);
            margin-top: 5px;
            display: none;
        }

        .age-validation.show {
            display: block;
        }

        /* Notification Styles */
        .update-notification {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: var(--white);
            padding: 20px 25px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            display: flex;
            align-items: center;
            z-index: 1000;
            animation: slideIn 0.5s ease-out;
            border-left: 4px solid var(--primary);
            min-width: 300px;
        }

        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .update-notification i {
            color: var(--primary);
            margin-right: 12px;
            font-size: 20px;
        }

        .update-notification span {
            margin-right: 15px;
            font-size: 14px;
            flex: 1;
        }

        .close-notification {
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: var(--text-light);
            transition: var(--transition);
        }

        .close-notification:hover {
            color: var(--primary);
            transform: scale(1.1);
        }

        /* Responsive Styles */
        @media (max-width: 1400px) {
            .profile-info-container {
                grid-template-columns: 1fr;
            }
            .form-grid {
                grid-template-columns: 1fr;
            }
            .form-group.full-width {
                grid-column: span 1;
            }
            .password-section {
                grid-column: span 1;
            }
        }

        @media (max-width: 992px) {
            .content {
                padding: 0 20px;
                margin: 20px auto;
            }
            .personal-section {
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
            .section-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
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
            <a href="personal.php" class="menu-item active">
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

    <!-- Main Content -->
    <main class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-title">
                <h1>Personal Information</h1>
                <p>Update your personal and account details</p>
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
            <section class="personal-section" role="region" aria-labelledby="personal-info-title">
                <div class="section-header">
                    <h2 class="section-title" id="personal-info-title">Personal Information</h2>
                </div>

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

                <!-- Profile Information -->
                <div class="profile-info-container">
                    <div class="profile-card">
                        <h5><i class="fas fa-id-card me-2"></i>Account Details</h5>
                        <p><strong>Username:</strong> <?php echo htmlspecialchars($user['username']); ?></p>
                        <p><strong>Role:</strong> <?php echo htmlspecialchars(ucfirst($user['role'])); ?></p>
                    </div>
                    <div class="profile-card">
                        <h5><i class="fas fa-briefcase me-2"></i>Employment Info</h5>
                        <p><strong>Department:</strong> <?php echo htmlspecialchars($user['department']); ?></p>
                        <p><strong>Position:</strong> <?php echo htmlspecialchars($user['position']); ?></p>
                        <p><strong>Hire Date:</strong> <?php echo htmlspecialchars($user['hire_date']); ?></p>
                    </div>
                </div>

                <!-- Edit Form -->
                <form id="personalForm" method="POST" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="first_name" class="form-label">First Name</label>
                            <input type="text" class="form-control" id="first_name" name="first_name" value="<?php echo htmlspecialchars($user['first_name']); ?>" required aria-required="true">
                        </div>
                        <div class="form-group">
                            <label for="last_name" class="form-label">Last Name</label>
                            <input type="text" class="form-control" id="last_name" name="last_name" value="<?php echo htmlspecialchars($user['last_name']); ?>" required aria-required="true">
                        </div>
                        <div class="form-group full-width">
                            <label for="email" class="form-label">Email Address</label>
                            <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required aria-required="true">
                        </div>
                        <div class="form-group">
                            <label for="phone" class="form-label">Phone Number</label>
                            <input type="text" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($user['phone']); ?>" required aria-required="true">
                        </div>
                        <div class="form-group">
                            <label for="birthdate" class="form-label">Birthdate</label>
                            <input type="text" class="form-control flatpickr-input" id="birthdate" name="birthdate" value="<?php echo htmlspecialchars($formatted_birthdate); ?>" required placeholder="YYYY/MM/DD" aria-required="true">
                            <div class="age-validation" id="ageValidation" role="alert"></div>
                        </div>
                        <div class="form-group full-width">
                            <label for="address" class="form-label">Address</label>
                            <textarea class="form-control" id="address" name="address" rows="4" required aria-required="true"><?php echo htmlspecialchars($user['address']); ?></textarea>
                        </div>
                        <div class="password-section">
                            <div class="form-group">
                                <input type="checkbox" id="changePassword" aria-controls="passwordFields">
                                <label for="changePassword" class="form-label">Change Password</label>
                            </div>
                            <div id="passwordFields" style="display: none;">
                                <div class="form-grid">
                                    <div class="form-group password-field">
                                        <label for="old_password" class="form-label">Current Password</label>
                                        <input type="password" class="form-control" id="old_password" name="old_password" aria-describedby="old_password_help">
                                        <button type="button" class="password-toggle" onclick="togglePassword('old_password', this)" aria-label="Toggle current password visibility">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <div class="form-group"></div>
                                    <div class="form-group password-field">
                                        <label for="password" class="form-label">New Password</label>
                                        <input type="password" class="form-control" id="password" name="password" aria-describedby="password_help">
                                        <button type="button" class="password-toggle" onclick="togglePassword('password', this)" aria-label="Toggle new password visibility">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <div class="form-text" id="password_help">Password must be at least 8 characters long and include letters and numbers.</div>
                                    </div>
                                    <div class="form-group password-field">
                                        <label for="confirm_password" class="form-label">Confirm New Password</label>
                                        <input type="password" class="form-control" id="confirm_password" name="confirm_password">
                                        <button type="button" class="password-toggle" onclick="togglePassword('confirm_password', this)" aria-label="Toggle confirm password visibility">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary" aria-label="Save changes">
                            <i class="fas fa-save me-2"></i>Save Changes
                        </button>
                    </div>
                </form>
            </section>
        </div>
    </main>

    <!-- Notification Container -->
    <div id="notificationContainer"></div>

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

        // Password Toggle
        function togglePassword(fieldId, button) {
            const field = document.getElementById(fieldId);
            const icon = button.querySelector('i');
            if (field.type === 'password') {
                field.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                field.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        // Change Password Checkbox
        const changePasswordCheckbox = document.getElementById('changePassword');
        const passwordFields = document.getElementById('passwordFields');
        changePasswordCheckbox.addEventListener('change', function() {
            passwordFields.style.display = this.checked ? 'block' : 'none';
            if (!this.checked) {
                document.getElementById('old_password').value = '';
                document.getElementById('password').value = '';
                document.getElementById('confirm_password').value = '';
            }
        });

        // Flatpickr for Birthdate
        flatpickr('#birthdate', {
            dateFormat: 'Y/m/d',
            maxDate: 'today',
            onChange: function(selectedDates, dateStr) {
                validateAge(dateStr);
            },
            onOpen: function() {
                document.querySelector('.flatpickr-calendar').setAttribute('role', 'dialog');
                document.querySelector('.flatpickr-calendar').setAttribute('aria-label', 'Date picker');
            }
        });

        // Client-Side Validation
        const form = document.getElementById('personalForm');
        const fields = {
            first_name: { regex: /.+/, message: 'First name is required.' },
            last_name: { regex: /.+/, message: 'Last name is required.' },
            email: { regex: /^\S+@\S+\.\S+$/, message: 'Please enter a valid email address.' },
            phone: { regex: /^[0-9\-\(\)\/\+\s]*$/, message: 'Please enter a valid phone number.' },
            birthdate: { regex: /^\d{4}\/\d{2}\/\d{2}$/, message: 'Please enter a valid date (YYYY/MM/DD).' }
        };

        function validateField(fieldId, value) {
            const field = fields[fieldId];
            const input = document.getElementById(fieldId);
            if (!field.regex.test(value)) {
                input.setCustomValidity(field.message);
                input.reportValidity();
                return false;
            } else {
                input.setCustomValidity('');
                return true;
            }
        }

        // Real-time validation
        Object.keys(fields).forEach(fieldId => {
            const input = document.getElementById(fieldId);
            input.addEventListener('input', () => validateField(fieldId, input.value));
        });

        // Age Validation
        function validateAge(dateStr) {
            const ageValidation = document.getElementById('ageValidation');
            if (!/^\d{4}\/\d{2}\/\d{2}$/.test(dateStr)) {
                ageValidation.textContent = 'Invalid date format. Please use YYYY/MM/DD.';
                ageValidation.classList.add('show');
                return false;
            }
            const birthDate = new Date(dateStr.replace(/\//g, '-'));
            if (isNaN(birthDate.getTime())) {
                ageValidation.textContent = 'Invalid date. Please use YYYY/MM/DD.';
                ageValidation.classList.add('show');
                return false;
            }
            const today = new Date();
            let age = today.getFullYear() - birthDate.getFullYear();
            const monthDiff = today.getMonth() - birthDate.getMonth();
            if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
                age--;
            }
            if (age < 20 || age > 65) {
                ageValidation.textContent = `Age must be between 20 and 65 years (current age: ${age}).`;
                ageValidation.classList.add('show');
                return false;
            } else {
                ageValidation.classList.remove('show');
                return true;
            }
        }

        // Form Submission Validation
        form.addEventListener('submit', function(e) {
            let valid = true;
            Object.keys(fields).forEach(fieldId => {
                if (!validateField(fieldId, document.getElementById(fieldId).value)) {
                    valid = false;
                }
            });

            const birthdate = document.getElementById('birthdate').value;
            if (birthdate && !validateAge(birthdate)) {
                valid = false;
            }

            if (changePasswordCheckbox.checked) {
                const password = document.getElementById('password').value;
                const confirmPassword = document.getElementById('confirm_password').value;
                const oldPassword = document.getElementById('old_password').value;

                if (!oldPassword) {
                    document.getElementById('old_password').setCustomValidity('Current password is required.');
                    document.getElementById('old_password').reportValidity();
                    valid = false;
                } else {
                    document.getElementById('old_password').setCustomValidity('');
                }

                if (password.length < 8) {
                    document.getElementById('password').setCustomValidity('Password must be at least 8 characters long.');
                    document.getElementById('password').reportValidity();
                    valid = false;
                } else if (!/\d/.test(password) || !/[a-zA-Z]/.test(password)) {
                    document.getElementById('password').setCustomValidity('Password must include both letters and numbers.');
                    document.getElementById('password').reportValidity();
                    valid = false;
                } else {
                    document.getElementById('password').setCustomValidity('');
                }

                if (password !== confirmPassword) {
                    document.getElementById('confirm_password').setCustomValidity('Passwords do not match.');
                    document.getElementById('confirm_password').reportValidity();
                    valid = false;
                } else {
                    document.getElementById('confirm_password').setCustomValidity('');
                }
            }

            if (!valid) {
                e.preventDefault();
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