<?php
/**
 * Add New Employee Page
 * Handles employee creation with form validation, account setup, and profile picture upload.
 * Fixed employee_id column error in users table insert.
 */

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict'
]);
session_start();
session_regenerate_id(true);

// Database Connection
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

// Authentication
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Initialize Messages and Form Data
$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);
$form_data = $_SESSION['form_data'] ?? [];
$create_account = isset($form_data['create_account']);

// Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit'], $_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $_SESSION['form_data'] = $_POST;
    $create_account = isset($_POST['create_account']);

    // Sanitize Inputs
    $first_name = filter_var(trim($_POST['first_name'] ?? ''), FILTER_SANITIZE_SPECIAL_CHARS);
    $last_name = filter_var(trim($_POST['last_name'] ?? ''), FILTER_SANITIZE_SPECIAL_CHARS);
    $email = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
    $phone = filter_var(trim($_POST['phone'] ?? ''), FILTER_SANITIZE_SPECIAL_CHARS);
    $birthdate = trim($_POST['birthdate'] ?? '');
    $address = filter_var(trim($_POST['address'] ?? ''), FILTER_SANITIZE_SPECIAL_CHARS);
    $department = filter_var(trim($_POST['department'] ?? ''), FILTER_SANITIZE_SPECIAL_CHARS);
    $position = filter_var(trim($_POST['position'] ?? ''), FILTER_SANITIZE_SPECIAL_CHARS);
    $role = filter_var(trim($_POST['role'] ?? ''), FILTER_SANITIZE_SPECIAL_CHARS);
    $hire_date = trim($_POST['hire_date'] ?? '');
    $account_username = $create_account ? filter_var(trim($_POST['account_username'] ?? ''), FILTER_SANITIZE_SPECIAL_CHARS) : '';
    $account_password = $create_account ? ($_POST['account_password'] ?? '') : '';

    // Validation
    $error_message = '';
    if (!preg_match('/^[A-Za-z\s\'-]+$/u', $first_name) || strlen($first_name) < 2 || strlen($first_name) > 50) {
        $error_message = 'First name must be 2-50 characters, containing only letters, spaces, hyphens, or apostrophes.';
    } elseif (!preg_match('/^[A-Za-z\s\'-]+$/u', $last_name) || strlen($last_name) < 2 || strlen($last_name) > 50) {
        $error_message = 'Last name must be 2-50 characters, containing only letters, spaces, hyphens, or apostrophes.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = 'Invalid email format.';
    } elseif (!preg_match('/^(09\d{9}|\+639\d{9})$/', $phone)) {
        $error_message = 'Phone number must be 09XXXXXXXXX or +639XXXXXXXXX.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthdate) || !DateTime::createFromFormat('Y-m-d', $birthdate)) {
        $error_message = 'Invalid birthdate format (YYYY-MM-DD).';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hire_date) || !DateTime::createFromFormat('Y-m-d', $hire_date)) {
        $error_message = 'Invalid hire date format (YYYY-MM-DD).';
    } elseif ($create_account && !preg_match('/^[a-zA-Z0-9._]{3,20}$/', $account_username)) {
        $error_message = 'Username must be 3-20 characters, containing only letters, numbers, dots, or underscores.';
    } elseif ($create_account && strlen($account_password) < 8) {
        $error_message = 'Password must be at least 8 characters.';
    } elseif ($create_account && empty($role)) {
        $error_message = 'Please select a role.';
    }

    // Validate Birthdate Age (20-65 years)
    if (empty($error_message)) {
        $birth_date = new DateTime($birthdate);
        $today = new DateTime();
        $age = $today->diff($birth_date)->y;
        if ($age < 20 || $age > 65) {
            $error_message = 'Employee age must be between 20 and 65 years.';
        }
    }

    // Validate Hire Date (within last 5 days, not future)
    if (empty($error_message)) {
        $hire_date_obj = new DateTime($hire_date);
        $today = new DateTime();
        $five_days_ago = (new DateTime())->modify('-5 days');
        if ($hire_date_obj > $today || $hire_date_obj < $five_days_ago) {
            $error_message = 'Hire date must be within the last 5 days and not in the future.';
        }
    }

    // Check Email Uniqueness
    if (empty($error_message)) {
        $stmt = $pdo->prepare("SELECT id FROM employees WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error_message = 'Email address is already in use.';
        }
    }

    // Check Username Uniqueness
    if (empty($error_message) && $create_account) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$account_username]);
        if ($stmt->fetch()) {
            $error_message = 'Username is already taken.';
        }
    }

    // Process Profile Picture
    $profile_picture = '';
    if (empty($error_message) && isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['profile_picture'];
        $allowed_types = ['image/jpeg', 'image/png'];
        $max_size = 2 * 1024 * 1024; // 2MB

        if (!in_array($file['type'], $allowed_types)) {
            $error_message = 'Profile picture must be JPEG or PNG.';
        } elseif ($file['size'] > $max_size) {
            $error_message = 'Profile picture must be less than 2MB.';
        } else {
            $target_dir = 'Uploads/';
            if (!is_dir($target_dir)) {
                mkdir($target_dir, 0755, true);
            }
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $target_file = $target_dir . uniqid('emp_', true) . '.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], $target_file)) {
                $error_message = 'Error uploading profile picture.';
            } else {
                $profile_picture = $target_file;
            }
        }
    }

    // Save Employee with Transaction
    if (empty($error_message)) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "INSERT INTO employees (first_name, last_name, email, phone, birthdate, address, department, position, hire_date, profile_picture, username)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$first_name, $last_name, $email, $phone, $birthdate, $address, $department, $position, $hire_date, $profile_picture, $account_username]);
            $employee_id = $pdo->lastInsertId();

            if ($create_account) {
                $hashed_password = password_hash($account_password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)");
                $stmt->execute([$account_username, $email, $hashed_password, $role]);
            }

            $pdo->commit();
            $success_message = 'Employee added successfully!' . ($create_account ? ' Account created.' : '');
            error_log("Employee added: ID=$employee_id, Email=$email, AccountCreated=" . ($create_account ? 'Yes' : 'No'));
            unset($_SESSION['form_data']);
            $_SESSION['success_message'] = $success_message;
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_message = 'Failed to save employee data: ' . $e->getMessage();
            error_log("Employee addition failed: " . $e->getMessage());
        }
    }

    $_SESSION['error_message'] = $error_message;
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// Username Suggestions AJAX
if (isset($_GET['action']) && $_GET['action'] === 'check_username' && isset($_GET['username'], $_GET['csrf_token']) && $_GET['csrf_token'] === $_SESSION['csrf_token']) {
    $username = trim($_GET['username']);
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    header('Content-Type: application/json');
    echo json_encode(['available' => !$stmt->fetch()]);
    exit();
}

// Generate Username Suggestions
function generateUsernameSuggestions($first_name, $last_name) {
    $first_name = strtolower(trim($first_name));
    $last_name = strtolower(trim($last_name));
    if (empty($first_name) || empty($last_name)) return [];

    $suggestions = [
        $first_name . $last_name,
        $first_name . '.' . $last_name,
        substr($first_name, 0, 1) . $last_name,
        $first_name . substr($last_name, 0, 1),
        substr($first_name, 0, 3) . substr($last_name, 0, 3),
        $first_name . $last_name . rand(10, 99),
        $first_name . rand(10, 99),
        $last_name . rand(10, 99)
    ];
    return array_unique($suggestions);
}

$username_suggestions = [];
if (!empty($form_data['first_name']) && !empty($form_data['last_name'])) {
    $username_suggestions = generateUsernameSuggestions($form_data['first_name'], $form_data['last_name']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Employee | HRPro</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4B3F72;
            --primary-light: #6B5CA5;
            --secondary: #BFA2DB;
            --accent: #7C3AED;
            --light: #F7EDF0;
            --white: #FFFFFF;
            --error: #EF4444;
            --success: #10B981;
            --text: #2D2A4A;
            --gray: #E5E7EB;
            --border-radius: 10px;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            --transition: all 0.3s ease;
        }

        body {
            display: flex;
            min-height: 100vh;
            background: var(--light);
            font-family: 'Inter', sans-serif;
            color: var(--text);
            line-height: 1.6;
        }

        /* Sidebar Styles */
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
            align-items: center;
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
            font-size: 18px;
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
            width: 24px;
            text-align: center;
        }

        /* Enhanced Main Content Styles */
        .main-content {
            flex: 1;
            padding: 32px;
            background: var(--white);
            box-shadow: inset 0 0 20px rgba(0, 0, 0, 0.03);
            overflow-y: auto;
        }

        /* Enhanced Section Header Styles */
        .section-header {
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            color: var(--white);
            padding: 24px;
            border-radius: var(--border-radius);
            text-align: center;
            margin-bottom: 32px;
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
        }

        .section-header::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle at top right, rgba(191, 162, 219, 0.3), transparent);
            opacity: 0.6;
        }

        .section-header h2 {
            margin: 0;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            z-index: 1;
            letter-spacing: 0.5px;
        }

        .section-header h2 i {
            margin-right: 12px;
            font-size: 1.2em;
        }

        /* Enhanced Section Body Styles */
        .section-body {
            width: 100%;
            margin: 0;
            padding: 32px;
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            position: relative;
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        /* Enhanced Alert Styles */
        .alert-success, .alert-error {
            padding: 16px 20px;
            border-radius: var(--border-radius);
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            animation: fadeIn 0.5s ease-in;
            font-weight: 500;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border-left: 4px solid var(--success);
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            color: var(--error);
            border-left: 4px solid var(--error);
        }

        .alert-success i, .alert-error i {
            font-size: 1.2em;
            margin-right: 12px;
        }

        .alert-dismiss {
            margin-left: auto;
            background: none;
            border: none;
            color: inherit;
            font-size: 16px;
            cursor: pointer;
            transition: var(--transition);
            padding: 4px;
        }

        .alert-dismiss:hover {
            opacity: 0.7;
            transform: scale(1.1);
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-12px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Enhanced Form Element Styles */
        .form-control, .form-select {
            padding: 12px 12px 12px 40px;
            border: 1px solid var(--gray);
            border-radius: var(--border-radius);
            background: #F9FAFB;
            font-size: 15px;
            font-weight: 400;
            transition: var(--transition);
            height: 52px;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        }

        textarea.form-control {
            height: auto;
            padding-top: 15px;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.2);
            background: var(--white);
        }

        .form-control.is-invalid {
            border-color: var(--error);
            background: rgba(239, 68opposite, 68, 0.05);
        }

        .form-group {
            position: relative;
            margin-bottom: 24px;
        }

        .form-group i {
            position: absolute;
            left: 12px;
            top: 43px;
            color: var(--text);
            font-size: 16px;
            width: 24px;
            text-align: center;
            opacity: 0.7;
        }

        .form-error {
            color: var(--error);
            font-size: 0.85rem;
            margin-top: 6px;
            font-weight: 500;
        }

        .form-label {
            font-weight: 500;
            margin-bottom: 8px;
            color: var(--text);
            display: block;
            font-size: 0.95rem;
        }

        .form-label .text-danger {
            font-weight: 700;
        }

        /* Enhanced Button Styles */
        .btn-primary {
            background: linear-gradient(135deg, var(--accent), var(--primary-light));
            color: var(--white);
            border: none;
            padding: 14px 32px;
            border-radius: var(--border-radius);
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.3);
            transition: var(--transition);
            letter-spacing: 0.5px;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--primary-light), var(--accent));
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(124, 58, 237, 0.4);
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        .btn-primary i {
            margin-right: 10px;
        }

        /* Enhanced Accordion Styles */
        .accordion-item {
            border: none;
            margin-bottom: 20px;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            transition: var(--transition);
        }

        .accordion-item:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .accordion-button {
            background: linear-gradient(135deg, var(--primary-light), var(--primary));
            color: var(--white);
            font-weight: 600;
            padding: 18px 20px;
            border-radius: var(--border-radius) var(--border-radius) 0 0;
            transition: var(--transition);
            border: none;
            position: relative;
            overflow: hidden;
        }

        .accordion-button::after {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='%23ffffff'%3e%3cpath fill-rule='evenodd' d='M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z'/%3e%3c/svg%3e");
        }

        .accordion-button:not(.collapsed) {
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            color: var(--white);
            box-shadow: none;
        }

        .accordion-button:not(.collapsed)::after {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='%23ffffff'%3e%3cpath fill-rule='evenodd' d='M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z'/%3e%3c/svg%3e");
            transform: rotate(-180deg);
        }

        .accordion-button:focus {
            box-shadow: none;
            border-color: transparent;
        }

        .accordion-button i {
            margin-right: 12px;
            font-size: 1.1em;
        }

        .accordion-body {
            background: var(--white);
            padding: 24px 28px;
            border: 1px solid rgba(0, 0, 0, 0.05);
            border-top: none;
            border-radius: 0 0 var(--border-radius) var(--border-radius);
        }

        .row {
            margin-left: -12px;
            margin-right: -12px;
        }

        .col-md-6 {
            padding-left: 12px;
            padding-right: 12px;
        }

        /* Enhanced Photo Upload Styles */
        .photo-upload {
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .photo-preview {
            width: 140px;
            height: 140px;
            border-radius: 50%;
            background: #F9FAFB;
            overflow: hidden;
            border: 4px solid var(--secondary);
            margin-bottom: 20px;
            position: relative;
            transition: var(--transition);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .photo-preview::before {
            content: '\f030';
            font-family: 'Font Awesome 5 Free';
            font-weight: 900;
            font-size: 2em;
            color: rgba(0, 0, 0, 0.2);
        }

        .photo-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .photo-preview:hover {
            border-color: var(--accent);
            transform: scale(1.02);
        }

        .photo-preview:hover::after {
            content: '\f030';
            font-family: 'Font Awesome 5 Free';
            font-weight: 900;
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            color: var(--white);
            font-size: 24px;
            background: rgba(107, 92, 165, 0.7);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Enhanced Toggle Switch Styles */
        .toggle-switch-container {
            display: flex;
            align-items: center;
            margin-bottom: 24px;
        }

        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 30px;
            margin-left: 16px;
            vertical-align: middle;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: var(--gray);
            transition: var(--transition);
            border-radius: 30px;
            box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 22px;
            width: 22px;
            left: 4px;
            bottom: 4px;
            background: var(--white);
            transition: var(--transition);
            border-radius: 50%;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15);
        }

        input:checked + .toggle-slider {
            background: var(--accent);
        }

        input:checked + .toggle-slider:before {
            transform: translateX(30px);
        }

        .toggle-label {
            font-weight: 600;
            color: var(--text);
            margin-right: 8px;
        }

        /* Enhanced Username Suggestions Styles */
        .username-suggestions {
            margin-top: 15px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .username-suggestions .suggestion {
            display: inline-flex;
            align-items: center;
            padding: 8px 16px;
            background: var(--secondary);
            color: var(--white);
            border-radius: 20px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 500;
            transition: var(--transition);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .username-suggestions .suggestion:hover {
            background: var(--accent);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        .username-suggestions .suggestion.available {
            background: var(--success);
        }

        .username-suggestions .suggestion.available::before {
            content: '\f00c';
            font-family: 'Font Awesome 5 Free';
            font-weight: 900;
            margin-right: 6px;
            font-size: 0.8em;
        }

        /* Enhanced Footer Info Styles */
        .info-footer {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 24px 28px;
            margin-top: 32px;
            cursor: pointer;
            transition: var(--transition);
            box-shadow: var(--shadow);
            width: 100%;
            margin-left: 0;
            margin-right: 0;
            border: 1px solid rgba(0, 0, 0, 0.05);
            position: relative;
        }

        .info-footer h5 {
            margin: 0;
            font-weight: 600;
            display: flex;
            align-items: center;
            color: var(--primary);
        }

        .info-footer h5 i {
            color: var(--accent);
            margin-right: 12px;
            font-size: 1.2em;
        }

        .info-footer:after {
            content: '\f078';
            font-family: 'Font Awesome 5 Free';
            font-weight: 900;
            position: absolute;
            right: 24px;
            top: 24px;
            color: var(--primary);
            transition: var(--transition);
        }

        .info-footer.active:after {
            transform: rotate(180deg);
        }

        .info-footer .info-content {
            display: none;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--gray);
            color: var(--text);
        }

        .info-footer .info-content p {
            margin-bottom: 16px;
            line-height: 1.6;
        }

        .info-footer .info-content ul {
            padding-left: 24px;
        }

        .info-footer .info-content li {
            margin-bottom: 8px;
            position: relative;
        }

        .info-footer.active .info-content {
            display: block;
            animation: slideDown 0.3s ease-in-out;
        }

        .info-footer:hover {
            background: var(--light);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.1);
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Enhanced Responsive Styles */
        @media (max-width: 992px) {
            .main-content {
                padding: 24px;
            }
            
            .section-body {
                padding: 24px;
            }
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
            .section-body {
                padding: 20px;
            }
            .form-control, .form-select {
                font-size: 14px;
                height: 48px;
            }
            .photo-preview {
                width: 120px;
                height: 120px;
            }
            .username-suggestions {
                flex-direction: column;
            }
            .username-suggestions .suggestion {
                margin-bottom: 8px;
            }
        }

        @media (max-width: 576px) {
            .main-content {
                padding: 16px;
            }
            .section-body {
                padding: 16px;
            }
            .accordion-button {
                padding: 16px;
            }
            .accordion-body {
                padding: 16px;
            }
            .btn-primary {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <aside class="sidebar" role="navigation">
        <div class="sidebar-header">
            <div class="d-flex align-items-center">
                <i class="fas fa-users logo-icon"></i>
                <h1 class="logo-text">HRPro</h1>
            </div>
            <button class="toggle-btn" id="sidebarToggle" aria-label="Toggle Sidebar"><i class="fas fa-chevron-left"></i></button>
        </div>
        <nav class="sidebar-menu">
            <a href="dashboard.php" class="menu-item"><i class="fas fa-home"></i><span class="menu-text">Home</span></a>
            <a href="personal.php" class="menu-item"><i class="fas fa-user"></i><span class="menu-text">Personal</span></a>
            <a href="timesheet.php" class="menu-item"><i class="fas fa-clock"></i><span class="menu-text">Timesheet</span></a>
            <a href="timeoff.php" class="menu-item"><i class="fas fa-calendar-minus"></i><span class="menu-text">Time Off</span></a>
            <a href="emergency.php" class="menu-item"><i class="fas fa-bell"></i><span class="menu-text">Emergency</span></a>
            <a href="performance.php" class="menu-item"><i class="fas fa-chart-line"></i><span class="menu-text">Performance</span></a>
            <a href="professionalpath.php" class="menu-item"><i class="fas fa-briefcase"></i><span class="menu-text">Professional Path</span></a>
            <a href="inbox.php" class="menu-item"><i class="fas fa-inbox"></i><span class="menu-text">Inbox</span></a>
            <a href="addEmployees.php" class="menu-item active"><i class="fas fa-user-plus"></i><span class="menu-text">Add Employee</span></a>
            <a href="login.html" class="menu-item"><i class="fas fa-sign-out-alt"></i><span class="menu-text">Logout</span></a>
        </nav>
    </aside>

    <main class="main-content">
        <div class="section-header">
            <h2><i class="fas fa-user-plus me-2"></i>Add New Employee</h2>
        </div>

        <div class="section-body">
            <?php if ($success_message): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?><button class="alert-dismiss" aria-label="Dismiss"><i class="fas fa-times"></i></button></div>
            <?php endif; ?>
            <?php if ($error_message): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?><button class="alert-dismiss" aria-label="Dismiss"><i class="fas fa-times"></i></button></div>
            <?php endif; ?>

            <form action="" method="POST" enctype="multipart/form-data" id="employeeForm" role="form">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <div class="accordion" id="employeeAccordion">
                    <!-- Personal Information -->
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="personalHeading">
                            <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#personalCollapse" aria-expanded="true" aria-controls="personalCollapse">
                                <i class="fas fa-user"></i>Personal Information
                            </button>
                        </h2>
                        <div id="personalCollapse" class="accordion-collapse collapse show" aria-labelledby="personalHeading">
                            <div class="accordion-body">
                                <div class="row g-4">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="first_name">First Name <span class="text-danger">*</span></label>
                                            <i class="fas fa-user"></i>
                                            <input type="text" id="first_name" name="first_name" class="form-control" required pattern="[A-Za-z\s'\-]{2,50}" aria-describedby="firstNameError" value="<?php echo htmlspecialchars($form_data['first_name'] ?? ''); ?>" title="2-50 characters, letters, spaces, hyphens, or apostrophes">
                                            <div id="firstNameError" class="form-error"></div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="last_name">Last Name <span class="text-danger">*</span></label>
                                            <i class="fas fa-user"></i>
                                            <input type="text" id="last_name" name="last_name" class="form-control" required pattern="[A-Za-z\s'\-]{2,50}" aria-describedby="lastNameError" value="<?php echo htmlspecialchars($form_data['last_name'] ?? ''); ?>" title="2-50 characters, letters, spaces, hyphens, or apostrophes">
                                            <div id="lastNameError" class="form-error"></div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="email">Email <span class="text-danger">*</span></label>
                                            <i class="fas fa-envelope"></i>
                                            <input type="email" id="email" name="email" class="form-control" required aria-describedby="emailError" value="<?php echo htmlspecialchars($form_data['email'] ?? ''); ?>">
                                            <div id="emailError" class="form-error"></div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="phone">Phone Number <span class="text-danger">*</span></label>
                                            <i class="fas fa-phone"></i>
                                            <input type="tel" id="phone" name="phone" class="form-control" required pattern="^(09\d{9}|\+639\d{9})$" aria-describedby="phoneError" value="<?php echo htmlspecialchars($form_data['phone'] ?? ''); ?>" title="09XXXXXXXXX or +639XXXXXXXXX">
                                            <div id="phoneError" class="form-error"></div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="birthdate">Date of Birth <span class="text-danger">*</span></label>
                                            <i class="fas fa-calendar-alt"></i>
                                            <input type="text" id="birthdate" name="birthdate" class="form-control datepicker" required aria-describedby="birthdateError" value="<?php echo htmlspecialchars($form_data['birthdate'] ?? ''); ?>">
                                            <div id="birthdateError" class="form-error"></div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="address">Address</label>
                                            <i class="fas fa-map-marker-alt"></i>
                                            <textarea id="address" name="address" class="form-control" style="height: 100px;"><?php echo htmlspecialchars($form_data['address'] ?? ''); ?></textarea>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="profile_picture">Profile Photo</label>
                                            <div class="photo-upload">
                                                <div class="photo-preview">
                                                    <img id="previewImage" src="" alt="Profile Preview" style="display: none;">
                                                </div>
                                                <input type="file" id="profile_picture" name="profile_picture" class="form-control" accept="image/jpeg,image/png" aria-describedby="photoError">
                                            </div>
                                            <div id="photoError" class="form-error"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Job Details -->
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="jobHeading">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#jobCollapse" aria-expanded="false" aria-controls="jobCollapse">
                                <i class="fas fa-briefcase"></i>Job Details
                            </button>
                        </h2>
                        <div id="jobCollapse" class="accordion-collapse collapse" aria-labelledby="jobHeading">
                            <div class="accordion-body">
                                <div class="row g-4">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="department">Department</label>
                                            <i class="fas fa-building"></i>
                                            <input type="text" id="department" name="department" class="form-control" value="<?php echo htmlspecialchars($form_data['department'] ?? ''); ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="position">Position</label>
                                            <i class="fas fa-id-badge"></i>
                                            <input type="text" id="position" name="position" class="form-control" value="<?php echo htmlspecialchars($form_data['position'] ?? ''); ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="hire_date">Hire Date <span class="text-danger">*</span></label>
                                            <i class="fas fa-calendar-alt"></i>
                                            <input type="text" id="hire_date" name="hire_date" class="form-control datepicker" required aria-describedby="hireDateError" value="<?php echo htmlspecialchars($form_data['hire_date'] ?? ''); ?>">
                                            <div id="hireDateError" class="form-error"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Account Setup -->
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="accountHeading">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#accountCollapse" aria-expanded="false" aria-controls="accountCollapse">
                                <i class="fas fa-user-lock"></i>Account Setup
                            </button>
                        </h2>
                        <div id="accountCollapse" class="accordion-collapse collapse" aria-labelledby="accountHeading">
                            <div class="accordion-body">
                                <div class="form-group mb-4">
                                    <label style="display: inline-block; font-weight: 500;">
                                        Create System Account
                                        <label class="toggle-switch">
                                            <input type="checkbox" id="create_account_toggle" name="create_account" <?php echo $create_account ? 'checked' : ''; ?>>
                                            <span class="toggle-slider"></span>
                                        </label>
                                    </label>
                                </div>
                                <div id="account_fields" style="display: <?php echo $create_account ? 'block' : 'none'; ?>;">
                                    <div class="row g-4">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="account_username">Username <span class="text-danger">*</span></label>
                                                <i class="fas fa-user-tag"></i>
                                                <input type="text" id="account_username" name="account_username" class="form-control" <?php echo $create_account ? 'required' : ''; ?> pattern="[a-zA-Z0-9._]{3,20}" aria-describedby="usernameError" value="<?php echo htmlspecialchars($form_data['account_username'] ?? ''); ?>" title="3-20 characters, letters, numbers, dots, or underscores">
                                                <div id="usernameError" class="form-error"></div>
                                                <div class="username-suggestions" id="usernameSuggestions">
                                                    <?php foreach (array_slice($username_suggestions, 0, 5) as $suggestion): ?>
                                                        <span class="suggestion" data-username="<?php echo htmlspecialchars($suggestion); ?>"><?php echo htmlspecialchars($suggestion); ?></span>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="account_password">Password <span class="text-danger">*</span></label>
                                                <i class="fas fa-lock"></i>
                                                <input type="password" id="account_password" name="account_password" class="form-control" autocomplete="new-password" <?php echo $create_account ? 'required' : ''; ?> aria-describedby="passwordError">
                                                <div id="passwordError" class="form-error"></div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="role">Role <span class="text-danger">*</span></label>
                                                <i class="fas fa-user-shield"></i>
                                                <select id="role" name="role" class="form-control" <?php echo $create_account ? 'required' : ''; ?> aria-describedby="roleError">
                                                    <option value="">Select Role</option>
                                                    <option value="HR" <?php echo ($form_data['role'] ?? '') === 'HR' ? 'selected' : ''; ?>>HR</option>
                                                    <option value="Employee" <?php echo ($form_data['role'] ?? '') === 'Employee' ? 'selected' : ''; ?>>Employee</option>
                                                </select>
                                                <div id="roleError" class="form-error"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="text-end mt-4">
                    <button type="submit" name="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Employee</button>
                </div>
            </form>
        </div>

        <div class="info-footer">
            <h5><i class="fas fa-info-circle me-2"></i>Important Information</h5>
            <div class="info-content">
                <p>Ensure all required fields are accurately filled to avoid delays in employee onboarding.</p>
                <ul>
                    <li>Profile photos must be professional and in JPEG or PNG format (max 2MB).</li>
                    <li>Phone numbers should follow the format 09XXXXXXXXX or +639XXXXXXXXX.</li>
                    <li>Account usernames must be unique and between 3-20 characters.</li>
                    <li>Passwords must be at least 8 characters long for security.</li>
                </ul>
            </div>
        </div>
    </main>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Debounce Utility
        function debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }

        // Initialize Sidebar
        function initSidebar() {
            const sidebarToggle = document.getElementById('sidebarToggle');
            sidebarToggle.addEventListener('click', () => {
                document.querySelector('.sidebar').classList.toggle('collapsed');
            });
        }

        // Initialize Form
        function initForm() {
            const form = document.getElementById('employeeForm');
            const createAccountToggle = document.getElementById('create_account_toggle');
            const accountFields = document.getElementById('account_fields');
            const accountInputs = accountFields.querySelectorAll('input, select');

            // Toggle Account Fields
            function toggleAccountFields() {
                const isChecked = createAccountToggle.checked;
                accountFields.style.display = isChecked ? 'block' : 'none';
                accountInputs.forEach(input => {
                    input.required = isChecked;
                    if (!isChecked) {
                        input.value = '';
                        input.classList.remove('is-invalid');
                        const errorDiv = document.getElementById(input.id + 'Error');
                        if (errorDiv) errorDiv.textContent = '';
                    }
                });
            }

            createAccountToggle.addEventListener('change', toggleAccountFields);
            toggleAccountFields();

            // Form Submission Validation
            form.addEventListener('submit', (e) => {
                validateFields();
                const errors = form.querySelectorAll('.form-error:not(:empty)');
                if (errors.length > 0) {
                    e.preventDefault();
                    alert('Please correct the invalid fields.');
                }
            });
        }

        // Initialize Datepickers
        function initDatepickers() {
            flatpickr('.datepicker', {
                dateFormat: 'Y-m-d',
                altInput: true,
                altFormat: 'M d, Y',
                maxDate: 'today',
                onChange: (selectedDates, dateStr, instance) => {
                    const input = instance.element;
                    const error = document.getElementById(input.id + 'Error');
                    const date = new Date(dateStr);
                    const today = new Date();

                    if (input.id === 'birthdate') {
                        let age = today.getFullYear() - date.getFullYear();
                        const monthDiff = today.getMonth() - date.getMonth();
                        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < date.getDate())) age--;
                        if (age < 20 || age > 65) {
                            error.textContent = 'Age must be between 20 and 65 years.';
                            input.classList.add('is-invalid');
                        } else {
                            error.textContent = '';
                            input.classList.remove('is-invalid');
                        }
                    } else if (input.id === 'hire_date') {
                        const fiveDaysAgo = new Date(today.getTime() - 5 * 24 * 60 * 60 * 1000);
                        if (date < fiveDaysAgo || date > today) {
                            error.textContent = 'Hire date must be within the last 5 days and not in the future.';
                            input.classList.add('is-invalid');
                        } else {
                            error.textContent = '';
                            input.classList.remove('is-invalid');
                        }
                    }
                }
            });
        }

        // Validate Fields
        function validateFields() {
            // First Name
            const firstName = document.getElementById('first_name');
            const firstNameError = document.getElementById('firstNameError');
            if (!/^[A-Za-z\s'-]{2,50}$/.test(firstName.value)) {
                firstNameError.textContent = 'First name must be 2-50 characters, letters, spaces, hyphens, or apostrophes.';
                firstName.classList.add('is-invalid');
            } else {
                firstNameError.textContent = '';
                firstName.classList.remove('is-invalid');
            }

            // Last Name
            const lastName = document.getElementById('last_name');
            const lastNameError = document.getElementById('lastNameError');
            if (!/^[A-Za-z\s'-]{2,50}$/.test(lastName.value)) {
                lastNameError.textContent = 'Last name must be 2-50 characters, letters, spaces, hyphens, or apostrophes.';
                lastName.classList.add('is-invalid');
            } else {
                lastNameError.textContent = '';
                lastName.classList.remove('is-invalid');
            }

            // Email
            const email = document.getElementById('email');
            const emailError = document.getElementById('emailError');
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value)) {
                emailError.textContent = 'Invalid email format.';
                email.classList.add('is-invalid');
            } else {
                emailError.textContent = '';
                email.classList.remove('is-invalid');
            }

            // Phone
            const phone = document.getElementById('phone');
            const phoneError = document.getElementById('phoneError');
            if (!/^(09\d{9}|\+639\d{9})$/.test(phone.value)) {
                phoneError.textContent = 'Phone number must be 09XXXXXXXXX or +639XXXXXXXXX.';
                phone.classList.add('is-invalid');
            } else {
                phoneError.textContent = '';
                phone.classList.remove('is-invalid');
            }

            // Birthdate
            const birthdate = document.getElementById('birthdate');
            const birthdateError = document.getElementById('birthdateError');
            if (!/^\d{4}-\d{2}-\d{2}$/.test(birthdate.value)) {
                birthdateError.textContent = 'Invalid birthdate format (YYYY-MM-DD).';
                birthdate.classList.add('is-invalid');
            } else {
                birthdateError.textContent = '';
                birthdate.classList.remove('is-invalid');
            }

            // Hire Date
            const hireDate = document.getElementById('hire_date');
            const hireDateError = document.getElementById('hireDateError');
            if (!/^\d{4}-\d{2}-\d{2}$/.test(hireDate.value)) {
                hireDateError.textContent = 'Invalid hire date format (YYYY-MM-DD).';
                hireDate.classList.add('is-invalid');
            } else {
                hireDateError.textContent = '';
                hireDateError.classList.remove('is-invalid');
            }

            // Username
            const username = document.getElementById('account_username');
            const usernameError = document.getElementById('usernameError');
            if (document.getElementById('create_account_toggle').checked && !/^[a-zA-Z0-9._]{3,20}$/.test(username.value)) {
                usernameError.textContent = 'Username must be 3-20 characters, letters, numbers, dots, or underscores.';
                username.classList.add('is-invalid');
            } else {
                usernameError.textContent = '';
                username.classList.remove('is-invalid');
            }

            // Password
            const password = document.getElementById('account_password');
            const passwordError = document.getElementById('passwordError');
            if (document.getElementById('create_account_toggle').checked && password.value.length < 8) {
                passwordError.textContent = 'Password must be at least 8 characters.';
                password.classList.add('is-invalid');
            } else {
                passwordError.textContent = '';
                password.classList.remove('is-invalid');
            }

            // Role
            const role = document.getElementById('role');
            const roleError = document.getElementById('roleError');
            if (document.getElementById('create_account_toggle').checked && !role.value) {
                roleError.textContent = 'Please select a role.';
                role.classList.add('is-invalid');
            } else {
                roleError.textContent = '';
                role.classList.remove('is-invalid');
            }
        }

        // Handle Username Suggestions
        function initUsernameSuggestions() {
            const firstName = document.getElementById('first_name');
            const lastName = document.getElementById('last_name');
            const usernameInput = document.getElementById('account_username');
            const suggestionsContainer = document.getElementById('usernameSuggestions');
            let abortController = null;

            function generateSuggestions() {
                const first = firstName.value.trim().toLowerCase();
                const last = lastName.value.trim().toLowerCase();
                if (!first || !last) return [];

                const suggestions = [
                    first + last,
                    first + '.' + last,
                    first.charAt(0) + last,
                    first + last.charAt(0),
                    first.substr(0, 3) + last.substr(0, 3),
                    first + last + Math.floor(Math.random() * 90 + 10),
                    first + Math.floor(Math.random() * 90 + 10),
                    last + Math.floor(Math.random() * 90 + 10)
                ];

                return [...new Set(suggestions)];
            }

            async function checkUsernameAvailability(username) {
                if (abortController) {
                    abortController.abort();
                }
                abortController = new AbortController();
                try {
                    const response = await fetch(`?action=check_username&username=${encodeURIComponent(username)}&csrf_token=<?php echo $_SESSION['csrf_token']; ?>`, {
                        signal: abortController.signal
                    });
                    const data = await response.json();
                    return data.available;
                } catch (error) {
                    if (error.name === 'AbortError') {
                        return false;
                    }
                    console.error('Error checking username:', error);
                    return false;
                }
            }

            const updateSuggestions = debounce(async () => {
                suggestionsContainer.innerHTML = '';
                const suggestions = generateSuggestions();
                for (const suggestion of suggestions.slice(0, 5)) {
                    const isAvailable = await checkUsernameAvailability(suggestion);
                    const span = document.createElement('span');
                    span.className = `suggestion ${isAvailable ? 'available' : ''}`;
                    span.textContent = suggestion;
                    span.dataset.username = suggestion;
                    span.addEventListener('click', () => {
                        usernameInput.value = suggestion;
                        usernameInput.classList.remove('is-invalid');
                        document.getElementById('usernameError').textContent = isAvailable ? '' : 'Username is already taken.';
                    });
                    suggestionsContainer.appendChild(span);
                }
            }, 500);

            firstName.addEventListener('input', updateSuggestions);
            lastName.addEventListener('input', updateSuggestions);
            usernameInput.addEventListener('input', debounce(async () => {
                const error = document.getElementById('usernameError');
                if (usernameInput.value) {
                    const isAvailable = await checkUsernameAvailability(usernameInput.value);
                    error.textContent = isAvailable ? '' : 'Username is already taken.';
                    usernameInput.classList.toggle('is-invalid', !isAvailable);
                } else {
                    error.textContent = '';
                    usernameInput.classList.remove('is-invalid');
                }
            }, 500));
        }

        // Handle Profile Picture Preview
        function initProfilePicture() {
            const input = document.getElementById('profile_picture');
            const preview = document.getElementById('previewImage');
            const error = document.getElementById('photoError');

            input.addEventListener('change', () => {
                const file = input.files[0];
                if (file) {
                    if (!['image/jpeg', 'image/png'].includes(file.type)) {
                        error.textContent = 'Only JPEG or PNG files are allowed.';
                        input.classList.add('is-invalid');
                        preview.style.display = 'none';
                    } else if (file.size > 2 * 1024 * 1024) {
                        error.textContent = 'File size must be less than 2MB.';
                        input.classList.add('is-invalid');
                        preview.style.display = 'none';
                    } else {
                        const reader = new FileReader();
                        reader.onload = (e) => {
                            preview.src = e.target.result;
                            preview.style.display = 'block';
                        };
                        reader.readAsDataURL(file);
                        error.textContent = '';
                        input.classList.remove('is-invalid');
                    }
                }
            });
        }

        // Initialize Info Footer
        function initInfoFooter() {
            document.querySelector('.info-footer').addEventListener('click', () => {
                const footer = document.querySelector('.info-footer');
                footer.classList.toggle('active');
            });
        }

        // Initialize Alerts
        function initAlerts() {
            document.querySelectorAll('.alert-dismiss').forEach(button => {
                button.addEventListener('click', () => {
                    button.parentElement.style.display = 'none';
                });
            });
        }

        // Initialize All
        document.addEventListener('DOMContentLoaded', () => {
            initSidebar();
            initForm();
            initDatepickers();
            initUsernameSuggestions();
            initProfilePicture();
            initInfoFooter();
            initAlerts();
        });
    </script>
</body>
</html>