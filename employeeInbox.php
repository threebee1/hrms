<?php
// Start session with secure settings
session_set_cookie_params([
    'lifetime' => 86400, // 1 day
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'],
    'secure' => isset($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

// Check if user is logged in, if not redirect to login page
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "hrms";

$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get current user information from session
$current_user_id = $_SESSION['user_id'];
$current_user_role = $_SESSION['role'];

// Function to get user details
function getUserDetails($conn, $user_id) {
    $sql = "SELECT u.id, u.username, u.role, e.first_name, e.last_name, e.department, e.position, e.profile_picture
            FROM users u
            LEFT JOIN employees e ON u.id = e.user_id
            WHERE u.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    
    // Fallback for missing employee data
    if (!$user['first_name'] || !$user['last_name']) {
        $user['first_name'] = $user['username'];
        $user['last_name'] = '';
    }
    if (!$user['department']) {
        $user['department'] = ucfirst($user['role']);
    }
    if (!$user['position']) {
        $user['position'] = ucfirst($user['role']);
    }
    
    return $user;
}

// Function to get conversation messages
function getConversation($conn, $user1_id, $user2_id) {
    $sql = "SELECT i.*,
                  COALESCE(CONCAT(e_sender.first_name, ' ', e_sender.last_name), u_sender.username) as sender_name,
                  e_sender.profile_picture as sender_picture,
                  u_sender.role as sender_role,
                  COALESCE(CONCAT(e_receiver.first_name, ' ', e_receiver.last_name), u_receiver.username) as receiver_name,
                  e_receiver.profile_picture as receiver_picture,
                  u_receiver.role as receiver_role
           FROM inbox i
           LEFT JOIN users u_sender ON i.sender_id = u_sender.id
           LEFT JOIN employees e_sender ON u_sender.id = e_sender.user_id
           LEFT JOIN users u_receiver ON i.receiver_id = u_receiver.id
           LEFT JOIN employees e_receiver ON u_receiver.id = e_receiver.user_id
           WHERE (i.sender_id = ? AND i.receiver_id = ?)
              OR (i.sender_id = ? AND i.receiver_id = ?)
           ORDER BY i.sent_at ASC";
   
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiii", $user1_id, $user2_id, $user2_id, $user1_id);
    $stmt->execute();
    $result = $stmt->get_result();
   
    return $result->fetch_all(MYSQLI_ASSOC);
}

// Function to count unread messages
function countUnreadMessages($conn, $user_id) {
    $sql = "SELECT COUNT(*) as count FROM inbox WHERE receiver_id = ? AND is_read = 0";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row['count'];
}

// Function to get user conversations
function getUserConversations($conn, $user_id) {
    $sql = "SELECT i.*,
            COALESCE(CONCAT(e_other.first_name, ' ', e_other.last_name), u_other.username) as other_name,
            e_other.profile_picture as other_picture,
            COALESCE(e_other.department, u_other.role) as other_department,
            COALESCE(e_other.position, u_other.role) as other_position,
            u_other.role as other_role,
            u_other.id as other_id,
            (SELECT COUNT(*) FROM inbox WHERE
                ((sender_id = i.sender_id AND receiver_id = i.receiver_id) OR
                (sender_id = i.receiver_id AND receiver_id = i.sender_id)) AND
                receiver_id = ? AND is_read = 0) as unread_count
            FROM inbox i
            JOIN (
                SELECT
                    CASE
                        WHEN sender_id = ? THEN receiver_id
                        ELSE sender_id
                    END as other_user_id,
                    MAX(sent_at) as max_sent_at
                FROM inbox
                WHERE sender_id = ? OR receiver_id = ?
                GROUP BY other_user_id
            ) latest ON
                ((i.sender_id = ? AND i.receiver_id = latest.other_user_id) OR
                (i.sender_id = latest.other_user_id AND i.receiver_id = ?)) AND
                i.sent_at = latest.max_sent_at
            JOIN users u_other ON latest.other_user_id = u_other.id
            LEFT JOIN employees e_other ON u_other.id = e_other.user_id
            ORDER BY i.sent_at DESC";
   
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiiiii", $user_id, $user_id, $user_id, $user_id, $user_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
   
    return $result->fetch_all(MYSQLI_ASSOC);
}

// Get list of available HR staff for employees to message
function getAvailableHRStaff($conn) {
    $sql = "SELECT u.id, u.username, u.role, e.first_name, e.last_name, e.department, e.position, e.profile_picture
            FROM users u
            LEFT JOIN employees e ON u.id = e.user_id
            WHERE u.role IN ('hr', 'admin')
            ORDER BY COALESCE(e.last_name, u.username), COALESCE(e.first_name, u.username)";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $staff = $result->fetch_all(MYSQLI_ASSOC);
    
    // Add fallback for missing employee data
    foreach ($staff as &$user) {
        if (!$user['first_name'] || !$user['last_name']) {
            $user['first_name'] = $user['username'];
            $user['last_name'] = '';
        }
        if (!$user['department']) {
            $user['department'] = ucfirst($user['role']);
        }
        if (!$user['position']) {
            $user['position'] = ucfirst($user['role']);
        }
    }
    
    return $staff;
}

// Process message sending
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['send_message'])) {
    $receiver_id = $_POST['receiver_id'];
    $subject = $_POST['subject'] ?? '';
    $message = $_POST['message'];
   
    // Validate input
    if (empty($message)) {
        $error_message = "Message cannot be empty";
    } else {
        // Verify the receiver is HR or admin
        $sql = "SELECT role FROM users WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $receiver_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $receiver = $result->fetch_assoc();
       
        if ($receiver && ($receiver['role'] === 'hr' || $receiver['role'] === 'admin')) {
            $sql = "INSERT INTO inbox (sender_id, receiver_id, subject, message) VALUES (?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iiss", $current_user_id, $receiver_id, $subject, $message);
           
            if ($stmt->execute()) {
                $success_message = "Message sent successfully";
                if (isset($_GET['user_id'])) {
                    header("Location: employeeinbox.php?user_id=" . $_GET['user_id']);
                    exit;
                }
            } else {
                $error_message = "Error sending message: " . $conn->error;
            }
        } else {
            $error_message = "You can only send messages to HR staff";
        }
    }
}

// Mark messages as read when viewing a conversation
if (isset($_GET['user_id'])) {
    $other_user_id = $_GET['user_id'];
   
    // Verify the other user is HR or admin
    $sql = "SELECT role FROM users WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $other_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $other_user_role = $result->fetch_assoc()['role'];
   
    if ($other_user_role === 'hr' || $other_user_role === 'admin') {
        $sql = "UPDATE inbox SET is_read = 1 WHERE sender_id = ? AND receiver_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $other_user_id, $current_user_id);
        $stmt->execute();
       
        $conversation = getConversation($conn, $current_user_id, $other_user_id);
        $other_user = getUserDetails($conn, $other_user_id);
    } else {
        header("Location: employeeinbox.php");
        exit;
    }
}

// Get user's conversations for the sidebar
$conversations = getUserConversations($conn, $current_user_id);
$unread_count = countUnreadMessages($conn, $current_user_id);

// Get available HR staff for new message
$available_hr_staff = getAvailableHRStaff($conn);

// Get current user details
$current_user = getUserDetails($conn, $current_user_id);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Inbox</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap">
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
            --shadow: 0 3px 10px rgba(0, 0, 0, 0.15), 0 2px 4px rgba(0, 0, 0, 0.1);
            --shadow-inset: inset 0 2px 4px rgba(0, 0, 0, 0.1), inset 0 -2px 4px rgba(255, 255, 255, 0.2);
            --shadow-hover: 0 6px 15px rgba(0, 0, 0, 0.2), 0 4px 6px rgba(0, 0, 0, 0.15);
            --transition: all 0.25s cubic-bezier(0.25, 0.1, 0.25, 1);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background: var(--white);
            color: var(--text);
            font-family: 'Inter', sans-serif;
            font-size: 0.85rem;
            line-height: 1.6;
            overflow-x: hidden;
        }

        /* Navbar */
        .navbar {
            background: linear-gradient(180deg, var(--primary-dark), var(--primary));
            padding: 0.7rem 1rem;
            box-shadow: var(--shadow);
            backdrop-filter: blur(8px);
        }

        .navbar-brand {
            font-weight: 600;
            font-size: 1.15rem;
            color: var(--white);
            transition: var(--transition);
        }

        .navbar-brand:hover {
            color: var(--secondary);
            transform: translateY(-1px);
        }

        .nav-link {
            color: rgba(255, 255, 255, 0.9);
            font-weight: 400;
            padding: 0.4rem 0.7rem;
            font-size: 0.8rem;
            transition: var(--transition);
            position: relative;
        }

        .nav-link:hover, .nav-link.active {
            color: var(--secondary) !important;
        }

        .nav-link::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            width: 0;
            height: 2px;
            background: var(--secondary);
            transition: var(--transition);
            transform: translateX(-50%);
        }

        .nav-link:hover::after, .nav-link.active::after {
            width: 60%;
        }

        .btn-outline-light {
            border-color: var(--gray);
            color: var(--white);
            font-size: 0.75rem;
            padding: 0.25rem 0.7rem;
            border-radius: var(--border-radius);
            transition: var(--transition);
        }

        .btn-outline-light:hover {
            background: var(--primary-light);
            border-color: var(--primary-light);
            transform: translateY(-1px);
        }

        /* Chat Container */
        .chat-container {
            height: calc(100vh - 56px);
            margin-top: 56px;
            padding: 0;
            background: var(--white);
        }

        /* Contacts List */
        .contacts-list {
            height: 100%;
            overflow-y: auto;
            background: var(--white);
            border-right: 1px solid rgba(0, 0, 0, 0.05);
            transition: var(--transition);
            box-shadow: var(--shadow);
        }

        .contacts-header {
            padding: 0.8rem 1rem;
            background: var(--white);
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            position: sticky;
            top: 0;
            z-index: 10;
            box-shadow: var(--shadow-inset);
        }

        .contact-item {
            cursor: pointer;
            padding: 0.7rem 1rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            transition: var(--transition);
            background: var(--white);
            transform: perspective(500px) translateZ(0);
        }

        .contact-item:hover, .contact-item.active {
            background: var(--light);
            transform: perspective(500px) translateZ(5px);
            box-shadow: var(--shadow-hover);
        }

        .contact-item.active {
            border-left: 3px solid var(--primary);
        }

        .profile-pic {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            object-fit: cover;
            background: linear-gradient(135deg, var(--secondary), var(--primary-light));
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 500;
            font-size: 0.75rem;
            box-shadow: var(--shadow);
            transition: var(--transition);
        }

        .profile-pic:hover {
            transform: scale(1.1) translateZ(10px);
        }

        /* Message Area */
        .message-area {
            height: 100%;
            background: var(--white);
            display: flex;
            flex-direction: column;
            box-shadow: var(--shadow);
        }

        .message-header {
            padding: 0.7rem 1rem;
            background: var(--white);
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            position: sticky;
            top: 0;
            z-index: 10;
            box-shadow: var(--shadow-inset);
        }

        .messages-container {
            flex-grow: 1;
            overflow-y: auto;
            padding: 1rem;
            background: var(--white);
        }

        .message-input {
            padding: 0.8rem 1rem;
            background: var(--white);
            border-top: 1px solid rgba(0, 0, 0, 0.05);
            box-shadow: var(--shadow-inset);
        }

        /* Message Bubbles */
        .message-bubble {
            max-width: 70%;
            padding: 0.5rem 0.9rem;
            border-radius: var(--border-radius);
            margin-bottom: 0.5rem;
            position: relative;
            word-wrap: break-word;
            box-shadow: var(--shadow);
            transition: var(--transition);
            transform: perspective(500px) translateZ(0);
        }

        .message-bubble:hover {
            transform: perspective(500px) translateZ(5px);
            box-shadow: var(--shadow-hover);
        }

        .message-sent {
            background: linear-gradient(135deg, var(--secondary), var(--primary-light));
            color: var(--white);
            margin-left: auto;
            border-bottom-right-radius: 4px;
        }

        .message-received {
            background: var(--light);
            margin-right: auto;
            border-bottom-left-radius: 4px;
        }

        .message-time {
            font-size: 0.65rem;
            color: var(--text-light);
            margin-top: 0.2rem;
            text-align: right;
            opacity: 0.8;
        }

        /* Badges & Indicators */
        .badge-notification {
            font-size: 0.55rem;
            padding: 0.15rem 0.35rem;
            transform: translate(10%, -10%);
            box-shadow: var(--shadow);
        }

        .unread-badge {
            font-size: 0.65rem;
            background: var(--primary) !important;
            padding: 0.15rem 0.4rem;
            border-radius: 8px;
            box-shadow: var(--shadow);
        }

        .role-badge {
            font-size: 0.55rem;
            padding: 0.15rem 0.35rem;
            border-radius: 6px;
            box-shadow: var(--shadow);
        }

        /* Inputs & Buttons */
        .form-control, .form-select {
            border-radius: var(--border-radius);
            border: 1px solid var(--gray);
            font-size: 0.8rem;
            padding: 0.4rem 0.8rem;
            transition: var(--transition);
            background: var(--white);
            box-shadow: var(--shadow-inset);
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 0.15rem rgba(75, 63, 114, 0.25);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            border: none;
            border-radius: var(--border-radius);
            padding: 0.4rem 0.9rem;
            font-size: 0.8rem;
            font-weight: 500;
            transition: var(--transition);
            box-shadow: var(--shadow);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--primary-light), var(--primary));
            transform: perspective(500px) translateZ(5px);
            box-shadow: var(--shadow-hover);
        }

        .btn-outline-secondary {
            border-color: var(--gray);
            color: var(--text);
            font-size: 0.8rem;
            padding: 0.3rem 0.7rem;
            border-radius: var(--border-radius);
            transition: var(--transition);
            box-shadow: var(--shadow);
        }

        .btn-outline-secondary:hover {
            background: var(--light);
            border-color: var(--primary);
            transform: perspective(500px) translateZ(5px);
        }

        /* Date Divider */
        .message-date-divider {
            text-align: center;
            margin: 0.8rem 0;
            position: relative;
        }

        .message-date-divider span {
            background: var(--white);
            padding: 0.2rem 0.7rem;
            font-size: 0.75rem;
            color: var(--text-light);
            border-radius: 8px;
            box-shadow: var(--shadow);
        }

        .message-date-divider:before {
            content: "";
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 1px;
            background: var(--gray);
            z-index: 0;
        }

        /* Empty State */
        .empty-state {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            height: 100%;
            background: var(--white);
            padding: 1rem;
            box-shadow: var(--shadow);
        }

        .help-box {
            background: var(--light);
            padding: 0.8rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            max-width: 350px;
            transition: var(--transition);
            transform: perspective(500px) translateZ(0);
        }

        .help-box:hover {
            transform: perspective(500px) translateZ(5px);
            box-shadow: var(--shadow-hover);
        }

        /* Modal Styling */
        .modal-content {
            border-radius: var(--border-radius);
            border: none;
            background: var(--white);
            box-shadow: var(--shadow);
            transform: perspective(500px) translateZ(0);
        }

        .modal-header {
            background: var(--white);
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            padding: 0.7rem 1rem;
            box-shadow: var(--shadow-inset);
        }

        .modal-footer {
            background: var(--white);
            border-top: 1px solid rgba(0, 0, 0, 0.05);
            padding: 0.7rem 1rem;
            box-shadow: var(--shadow-inset);
        }

        /* Animations */
        @keyframes popIn {
            0% { opacity: 0; transform: perspective(500px) translateZ(-10px) scale(0.95); }
            100% { opacity: 1; transform: perspective(500px) translateZ(0) scale(1); }
        }

        .message-bubble, .contact-item, .empty-state, .help-box, .modal-content {
            animation: popIn 0.3s ease-out forwards;
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 5px;
        }

        ::-webkit-scrollbar-track {
            background: var(--light);
            border-radius: 3px;
        }

        ::-webkit-scrollbar-thumb {
            background: var(--primary-light);
            border-radius: 3px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--primary);
        }

        /* Responsive Design */
        .mobile-toggle {
            display: none;
        }

        @media (max-width: 992px) {
            .contacts-list {
                position: fixed;
                top: 56px;
                left: 0;
                width: 260px;
                z-index: 1000;
                transform: translateX(-100%);
                transition: transform 0.3s ease;
                height: calc(100vh - 56px);
                box-shadow: var(--shadow);
            }

            .contacts-list.show {
                transform: translateX(0);
            }

            .mobile-toggle {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 32px;
                height: 32px;
                border-radius: 8px;
                background: var(--white);
                border: 1px solid var(--gray);
                box-shadow: var(--shadow);
            }
        }

        @media (max-width: 576px) {
            .navbar {
                padding: 0.5rem;
            }

            .navbar-brand {
                font-size: 1rem;
            }

            .nav-link {
                font-size: 0.75rem;
                padding: 0.3rem 0.5rem;
            }

            .contacts-header {
                padding: 0.6rem;
            }

            .contact-item {
                padding: 0.5rem 0.6rem;
            }

            .profile-pic {
                width: 26px;
                height: 26px;
                font-size: 0.65rem;
            }

            .message-header {
                padding: 0.5rem 0.6rem;
            }

            .messages-container {
                padding: 0.6rem;
            }

            .message-input {
                padding: 0.6rem;
            }

            .message-bubble {
                max-width: 80%;
                padding: 0.4rem 0.7rem;
            }

            .empty-state {
                padding: 0.6rem;
            }

            .help-box {
                padding: 0.6rem;
                max-width: 100%;
            }

            .form-control, .form-select {
                font-size: 0.75rem;
                padding: 0.3rem 0.6rem;
            }

            .btn-primary, .btn-outline-secondary {
                font-size: 0.75rem;
                padding: 0.3rem 0.7rem;
            }

            .message-time {
                font-size: 0.6rem;
            }

            .message-date-divider span {
                font-size: 0.7rem;
                padding: 0.15rem 0.6rem;
            }
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark fixed-top">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">Employee Portal</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="employeedashboard.php">Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="Employeepersonal.php">Personal Info</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="Employeetimesheet.php">Timesheet</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="Employeetimeoff.php">Time Off</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="employeeinbox.php">
                            Messages
                            <?php if ($unread_count > 0): ?>
                            <span class="position-relative">
                                <i class="fas fa-envelope"></i>
                                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger badge-notification">
                                    <?php echo $unread_count; ?>
                                </span>
                            </span>
                            <?php else: ?>
                            <i class="fas fa-envelope"></i>
                            <?php endif; ?>
                        </a>
                    </li>
                </ul>
                <div class="d-flex align-items-center">
                    <div class="text-white me-3">
                        <?php echo htmlspecialchars($current_user['first_name'] . ' ' . $current_user['last_name']); ?>
                        <small class="text-white-50 d-block"><?php echo htmlspecialchars($current_user['department']); ?></small>
                    </div>
                    <a href="logout.php" class="btn btn-outline-light btn-sm">
                        <i class="fas fa-sign-out-alt me-1"></i> Logout
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="container-fluid chat-container">
        <div class="row h-100">
            <!-- Contacts List -->
            <div class="col-lg-3 p-0 contacts-list" id="contactsList">
                <div class="contacts-header">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="mb-0 fw-bold" style="font-size: 0.9rem;">HR Messages</h6>
                        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#newMessageModal">
                            <i class="fas fa-plus me-1"></i> New
                        </button>
                    </div>
                    <div class="input-group input-group-sm">
                        <input type="text" class="form-control" id="searchContacts" placeholder="Search..." style="font-size: 0.8rem;">
                        <button class="btn btn-outline-secondary" type="button">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </div>
               
                <!-- Contact List -->
                <div class="contact-list">
                    <?php if (count($conversations) > 0): ?>
                        <?php foreach ($conversations as $conv): ?>
                            <?php if ($conv['other_role'] === 'hr' || $conv['other_role'] === 'admin'): ?>
                                <div class="contact-item <?php echo (isset($_GET['user_id']) && $_GET['user_id'] == $conv['other_id']) ? 'active' : ''; ?>"
                                     data-user-id="<?php echo $conv['other_id']; ?>"
                                     onclick="window.location.href='employeeinbox.php?user_id=<?php echo $conv['other_id']; ?>'">
                                    <div class="d-flex align-items-center">
                                        <div class="position-relative me-2">
                                            <?php if (!empty($conv['other_picture'])): ?>
                                                <img src="<?php echo $conv['other_picture']; ?>" alt="Profile" class="profile-pic">
                                            <?php else: ?>
                                                <div class="profile-pic d-flex justify-content-center align-items-center text-white">
                                                    <?php echo strtoupper(substr($conv['other_name'], 0, 1)); ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($conv['other_role'] == 'hr'): ?>
                                                <span class="position-absolute bottom-0 end-0 badge rounded-pill bg-success role-badge">HR</span>
                                            <?php elseif ($conv['other_role'] == 'admin'): ?>
                                                <span class="position-absolute bottom-0 end-0 badge rounded-pill bg-danger role-badge">Admin</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <h6 class="mb-0 fw-medium" style="font-size: 0.85rem;"><?php echo htmlspecialchars($conv['other_name']); ?></h6>
                                                <small class="text-muted" style="font-size: 0.65rem;"><?php echo date('M d', strtotime($conv['sent_at'])); ?></small>
                                            </div>
                                            <small class="text-muted" style="font-size: 0.7rem;"><?php echo htmlspecialchars($conv['other_department']); ?></small>
                                            <div class="d-flex justify-content-between align-items-center mt-1">
                                                <div class="preview-text text-truncate" style="max-width: 140px; font-size: 0.7rem;">
                                                    <?php if ($conv['sender_id'] == $current_user_id): ?>
                                                        <small class="text-muted fw-bold">You: </small>
                                                    <?php endif; ?>
                                                    <?php echo htmlspecialchars(substr($conv['message'], 0, 18)); ?>
                                                    <?php echo (strlen($conv['message']) > 18) ? '...' : ''; ?>
                                                </div>
                                                <?php if ($conv['unread_count'] > 0): ?>
                                                    <span class="badge rounded-pill unread-badge"><?php echo $conv['unread_count']; ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="p-2 text-center text-muted">
                            <i class="fas fa-inbox mb-2" style="font-size: 1.2rem;"></i>
                            <p style="font-size: 0.8rem;">No conversations yet</p>
                            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#newMessageModal">
                                Contact HR
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Message Area -->
            <div class="col-lg-9 p-0 message-area">
                <?php if (isset($_GET['user_id']) && isset($other_user) && ($other_user['role'] === 'hr' || $other_user['role'] === 'admin')): ?>
                    <!-- Message Header -->
                    <div class="message-header d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center">
                            <button class="btn btn-outline-secondary mobile-toggle me-2" id="toggleContacts">
                                <i class="fas fa-bars"></i>
                            </button>
                            <div class="position-relative me-2">
                                <?php if (!empty($other_user['profile_picture'])): ?>
                                    <img src="<?php echo $other_user['profile_picture']; ?>" alt="Profile" class="profile-pic">
                                <?php else: ?>
                                    <div class="profile-pic d-flex justify-content-center align-items-center text-white">
                                        <?php echo strtoupper(substr($other_user['first_name'], 0, 1)); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($other_user['role'] == 'hr'): ?>
                                    <span class="position-absolute bottom-0 end-0 badge rounded-pill bg-success role-badge">HR</span>
                                <?php elseif ($other_user['role'] == 'admin'): ?>
                                    <span class="position-absolute bottom-0 end-0 badge rounded-pill bg-danger role-badge">Admin</span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <h6 class="mb-0 fw-medium" style="font-size: 0.85rem;"><?php echo htmlspecialchars($other_user['first_name'] . ' ' . $other_user['last_name']); ?></h6>
                                <small class="text-muted" style="font-size: 0.7rem;">
                                    <?php echo htmlspecialchars($other_user['department']); ?>
                                </small>
                            </div>
                        </div>
                    </div>

                    <!-- Messages Container -->
                    <div class="messages-container" id="messagesContainer">
                        <?php
                        $date_shown = null;
                        foreach ($conversation as $message):
                            $message_date = date('Y-m-d', strtotime($message['sent_at']));
                            if ($message_date != $date_shown):
                                $date_shown = $message_date;
                                $date_display = date('F j, Y', strtotime($message['sent_at']));
                                if ($date_display == date('F j, Y')) {
                                    $date_display = 'Today';
                                } elseif ($date_display == date('F j, Y', strtotime('-1 day'))) {
                                    $date_display = 'Yesterday';
                                }
                        ?>
                            <div class="message-date-divider">
                                <span><?php echo $date_display; ?></span>
                            </div>
                        <?php endif; ?>

                            <div class="d-flex flex-column <?php echo ($message['sender_id'] == $current_user_id) ? 'align-items-end' : 'align-items-start'; ?>">
                                <div class="message-bubble <?php echo ($message['sender_id'] == $current_user_id) ? 'message-sent' : 'message-received'; ?>">
                                    <?php if (!empty($message['subject'])): ?>
                                        <div class="subject-line fw-medium" style="font-size: 0.8rem;"><?php echo htmlspecialchars($message['subject']); ?></div>
                                    <?php endif; ?>
                                    <div style="font-size: 0.8rem;"><?php echo nl2br(htmlspecialchars($message['message'])); ?></div>
                                    <div class="message-time">
                                        <?php echo date('h:i A', strtotime($message['sent_at'])); ?>
                                        <?php if ($message['sender_id'] == $current_user_id): ?>
                                            <i class="fas fa-check-double ms-1" style="font-size: 0.65rem; <?php echo $message['is_read'] ? 'color: #4fc3f7;' : ''; ?>"></i>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Message Input -->
                    <div class="message-input">
                        <form method="post" action="employeeinbox.php?user_id=<?php echo $other_user_id; ?>">
                            <input type="hidden" name="receiver_id" value="<?php echo $other_user_id; ?>">
                            <div class="mb-2">
                                <textarea class="form-control" name="message" rows="2" placeholder="Type your message..." required style="font-size: 0.8rem;"></textarea>
                            </div>
                            <div class="d-flex justify-content-end">
                                <button type="submit" name="send_message" class="btn btn-primary btn-sm">
                                    <i class="fas fa-paper-plane me-1"></i> Send
                                </button>
                            </div>
                        </form>
                    </div>
                <?php else: ?>
                    <!-- Empty State -->
                    <div class="empty-state">
                        <div class="text-center">
                            <i class="fas fa-comments fa-3x mb-3 text-primary"></i>
                            <h6 class="fw-bold" style="font-size: 0.95rem;">HR Communications</h6>
                            <p class="mb-3" style="font-size: 0.8rem;">Select a conversation or start a new one</p>
                           
                            <div class="help-box mt-2 mb-3 mx-auto">
                                <h6 class="fw-medium" style="font-size: 0.85rem;"><i class="fas fa-info-circle me-1"></i> Need Help?</h6>
                                <p style="font-size: 0.75rem;">Contact HR for:</p>
                                <ul class="text-start ps-3" style="font-size: 0.75rem;">
                                    <li>Benefits inquiries</li>
                                    <li>Workplace concerns</li>
                                    <li>Leave requests</li>
                                    <li>HR support</li>
                                </ul>
                                <p class="mb-0" style="font-size: 0.75rem;">Messages are confidential.</p>
                            </div>
                           
                            <button class="btn btn-primary btn-sm mb-2" data-bs-toggle="modal" data-bs-target="#newMessageModal">
                                <i class="fas fa-plus me-1"></i> Contact HR
                            </button>
                           
                            <button class="btn btn-outline-secondary btn-sm d-lg-none" id="showContactsBtn">
                                <i class="fas fa-users me-1"></i> Conversations
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- New Message Modal -->
    <div class="modal fade" id="newMessageModal" tabindex="-1" aria-labelledby="newMessageModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title fw-bold" id="newMessageModalLabel" style="font-size: 0.95rem;">New Message</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="employeeinbox.php">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="receiver_id" class="form-label fw-medium" style="font-size: 0.8rem;">HR Representative</label>
                            <select class="form-select form-select-sm" id="receiver_id" name="receiver_id" required>
                                <option value="">Select...</option>
                                <?php foreach ($available_hr_staff as $staff): ?>
                                    <option value="<?php echo $staff['id']; ?>">
                                        <?php echo htmlspecialchars($staff['first_name'] . ' ' . $staff['last_name']); ?>
                                        (<?php echo ucfirst($staff['role']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="subject" class="form-label fw-medium" style="font-size: 0.8rem;">Subject</label>
                            <input type="text" class="form-control form-control-sm" id="subject" name="subject" placeholder="Enter subject" style="font-size: 0.8rem;">
                        </div>
                        <div class="mb-3">
                            <label for="message" class="form-label fw-medium" style="font-size: 0.8rem;">Message</label>
                            <textarea class="form-control form-control-sm" id="message" name="message" rows="4" required placeholder="Type your message..." style="font-size: 0.8rem;"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="send_message" class="btn btn-primary btn-sm">
                            <i class="fas fa-paper-plane me-1"></i> Send
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-scroll to bottom of messages container
            const messagesContainer = document.getElementById('messagesContainer');
            if (messagesContainer) {
                messagesContainer.scrollTop = messagesContainer.scrollHeight;
            }
           
            // Mobile menu toggle
            const toggleContactsBtn = document.getElementById('toggleContacts');
            const showContactsBtn = document.getElementById('showContactsBtn');
            const contactsList = document.getElementById('contactsList');
           
            if (toggleContactsBtn) {
                toggleContactsBtn.addEventListener('click', function() {
                    contactsList.classList.toggle('show');
                });
            }
           
            if (showContactsBtn) {
                showContactsBtn.addEventListener('click', function() {
                    contactsList.classList.add('show');
                });
            }
           
            // Close contacts list when clicking outside on mobile
            document.addEventListener('click', function(event) {
                if (window.innerWidth <= 992 &&
                    !contactsList.contains(event.target) &&
                    event.target !== toggleContactsBtn &&
                    event.target !== showContactsBtn &&
                    !event.target.closest('.contact-item')) {
                    contactsList.classList.remove('show');
                }
            });
           
            // Search functionality
            const searchContacts = document.getElementById('searchContacts');
            if (searchContacts) {
                searchContacts.addEventListener('input', function() {
                    const searchTerm = this.value.toLowerCase();
                    const contactItems = document.querySelectorAll('.contact-item');
                   
                    contactItems.forEach(function(item) {
                        const contactName = item.querySelector('h6').textContent.toLowerCase();
                        item.style.display = contactName.includes(searchTerm) ? 'block' : 'none';
                    });
                });
            }
        });
    </script>
</body>
</html>
<?php
// Close database connection
$conn->close();
?>