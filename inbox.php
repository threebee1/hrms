<?php
// Start session
session_start();

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

// Assume user is logged in - In a real application, you would verify this
// For demo purposes, we'll simulate a logged-in HR user
if (!isset($_SESSION['user_id'])) {
    // For demo: Set a default HR user
    $_SESSION['user_id'] = 1; // Assuming ID 1 is an HR user
    $_SESSION['username'] = 'hr_manager';
    $_SESSION['role'] = 'hr';
}

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

// Get list of all available users for new messages
function getAvailableUsers($conn, $current_user_id, $current_user_role) {
    // Allow all users to message any other user, excluding themselves
    $sql = "SELECT u.id, u.username, u.role, e.first_name, e.last_name, e.department, e.position, e.profile_picture
            FROM users u
            LEFT JOIN employees e ON u.id = e.user_id
            WHERE u.id != ?
            ORDER BY COALESCE(e.last_name, u.username), COALESCE(e.first_name, u.username)";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $current_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $users = $result->fetch_all(MYSQLI_ASSOC);
    
    // Add fallback for missing employee data
    foreach ($users as &$user) {
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
    
    return $users;
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
        $sql = "INSERT INTO inbox (sender_id, receiver_id, subject, message) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iiss", $current_user_id, $receiver_id, $subject, $message);
       
        if ($stmt->execute()) {
            $success_message = "Message sent successfully";
            // If we're in a conversation, redirect back to it
            if (isset($_GET['user_id'])) {
                header("Location: inbox.php?user_id=" . $_GET['user_id']);
                exit;
            }
        } else {
            $error_message = "Error sending message: " . $conn->error;
        }
    }
}

// Mark messages as read when viewing a conversation
if (isset($_GET['user_id'])) {
    $other_user_id = $_GET['user_id'];
   
    // Mark messages as read
    $sql = "UPDATE inbox SET is_read = 1 WHERE sender_id = ? AND receiver_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $other_user_id, $current_user_id);
    $stmt->execute();
   
    // Get conversation
    $conversation = getConversation($conn, $current_user_id, $other_user_id);
    $other_user = getUserDetails($conn, $other_user_id);
}

// Get user's conversations for the sidebar
$conversations = getUserConversations($conn, $current_user_id);
$unread_count = countUnreadMessages($conn, $current_user_id);

// Get available users for new message
$available_users = getAvailableUsers($conn, $current_user_id, $current_user_role);

// Get current user details
$current_user = getUserDetails($conn, $current_user_id);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HRMS Inbox</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary: #4B3F72;
            --primary-light: #6B5CA5;
            --primary-dark: #3A3159;
            --secondary: #BFA2DB;
            --light: #F7EDF0;
            --white: #FFFFFF;
            --error: #28px;
            --success: #4BB543;
            --text: #2D2A4A;
            --text-light: #A0A0B2;
            --gray: #E5E5E5;
            --border-radius: 10px;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            --shadow-hover: 0 6px 16px rgba(0, 0, 0, 0.12);
            --transition: all 0.25s ease;
            --focus-ring: 0 0 0 3px rgba(191, 162, 219, 0.5);
        }

        body {
            background-color: var(--light);
            color: var(--text);
            font-family: 'Outfit', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            line-height: 1.6;
        }

        /* Navbar */
        .navbar {
            background-color: var(--primary-dark) !important;
            box-shadow: var(--shadow);
            padding: 0.75rem 1rem;
        }

        .navbar-brand {
            font-family: 'Poppins', sans-serif;
            font-weight: 600;
            background: linear-gradient(90deg, var(--white), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-size: 1.25rem;
        }

        .nav-link {
            transition: var(--transition);
        }

        .nav-link.active {
            color: var(--secondary) !important;
        }

        .btn-outline-light:hover {
            background-color: var(--primary-light);
        }

        /* Chat Container */
        .chat-container {
            height: calc(100vh - 64px);
            margin-top: 64px;
            background-color: var(--light);
        }

        /* Contacts List */
        .contacts-list {
            height: 100%;
            overflow-y: auto;
            background-color: var(--white);
            border-right: 1px solid var(--gray);
            transition: var(--transition);
        }

        .contacts-header {
            padding: 1rem;
            background-color: var(--white);
            border-bottom: 1px solid var(--gray);
        }

        .contact-item {
            cursor: pointer;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--gray);
            transition: var(--transition);
        }

        .contact-item:hover, .contact-item.active {
            background-color: var(--light);
        }

        .contact-item.active {
            border-left: 3px solid var(--primary);
        }

        .profile-pic {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit:424px;
            background-color: var(--secondary);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 500;
        }

        /* Message Area */
        .message-area {
            display: flex;
            flex-direction: column;
            height: 100%;
            background-color: var(--white);
        }

        .message-header {
            padding: 1rem;
            border-bottom: 1px solid var(--gray);
            background-color: var(--white);
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .messages-container {
            flex-grow: 1;
            overflow-y: auto;
            padding: 1rem;
            background-color: var(--white);
        }

        .message-input {
            padding: 1rem;
            border-top: 1px solid var(--gray);
            background-color: var(--white);
        }

        /* Message Bubbles */
        .message-bubble {
            max-width: 75%;
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 0.75rem;
            position: relative;
            word-wrap: break-word;
            box-shadow: var(--shadow);
        }

        .message-sent {
            background-color: var(--secondary);
            color: var(--white);
            margin-left: auto;
            border-bottom-right-radius: 5px;
        }

        .message-received {
            background-color: var(--light);
            margin-right: auto;
            border-bottom-left-radius: 5px;
        }

        .message-time {
            font-size: 0.7em;
            color: var(--text-light);
            margin-top: 0.5rem;
            text-align: right;
        }

        /* Badges & Indicators */
        .badge-notification {
            font-size: 0.6em;
            transform: translate(30%, -30%);
        }

        .unread-badge {
            font-size: 0.7em;
            background-color: var(--primary) !important;
        }

        .role-badge {
            font-size: 0.6em;
            padding: 0.2em 0.4em;
        }

        /* Inputs & Buttons */
        .form-control, .form-select {
            border-radius: var(--border-radius);
            border: 1px solid var(--gray);
            transition: var(--transition);
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary-light);
            box-shadow: var(--focus-ring);
        }

        .btn-primary {
            background-color: var(--primary);
            border-color: var(--primary);
            transition: var(--transition);
        }

        .btn-primary:hover {
            background-color: var(--primary-light);
            border-color: var(--primary-light);
        }

        .btn-outline-secondary {
            border-color: var(--gray);
        }

        /* Date Divider */
        .message-date-divider {
            text-align: center;
            margin: 1rem 0;
            position: relative;
        }

        .message-date-divider span {
            background-color: var(--white);
            padding: 0 0.75rem;
            position: relative;
            z-index: 1;
            font-size: 0.8em;
            color: var(--text-light);
        }

        .message-date-divider:before {
            content: "";
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 1px;
            background-color: var(--gray);
            z-index: 0;
        }

        /* Empty State */
        .empty-state {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            height: 100%;
            color: var(--text-light);
        }

        /* Mobile Responsiveness */
        .mobile-toggle {
            display: none;
        }

        @media (max-width: 768px) {
            .contacts-list {
                position: fixed;
                top: 64px;
                left: 0;
                width: 100%;
                z-index: 1000;
                transform: translateX(-100%);
                transition: transform 0.3s ease;
                height: calc(100vh - 64px);
            }

            .contacts-list.show {
                transform: translateX(0);
            }

            .mobile-toggle {
                display: block;
                background-color: var(--white);
                border: 1px solid var(--gray);
            }
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 6px;
        }

        ::-webkit-scrollbar-track {
            background: var(--light);
        }

        ::-webkit-scrollbar-thumb {
            background: var(--primary-light);
            border-radius: 3px;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">HRMS</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">Dashboard</a>
                    </li>
                    <?php if ($current_user_role == 'hr' || $current_user_role == 'admin'): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="employees.php">Employees</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="performance.php">Performance</a>
                    </li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <a class="nav-link active" href="inbox.php">
                            Inbox
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
                        <?php echo $current_user['first_name'] . ' ' . $current_user['last_name']; ?>
                        <small class="text-muted">(<?php echo ucfirst($current_user_role); ?>)</small>
                    </div>
                    <a href="logout.php" class="btn btn-outline-light btn-sm">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="container-fluid chat-container">
        <div class="row h-100">
            <!-- Contacts List -->
            <div class="col-md-3 col-lg-3 p-0 contacts-list" id="contactsList">
                <div class="p-3 bg-light border-bottom">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h5 class="mb-0">Messages</h5>
                        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#newMessageModal">
                            <i class="fas fa-plus"></i> New Message
                        </button>
                    </div>
                    <div class="input-group">
                        <input type="text" class="form-control form-control-sm" id="searchContacts" placeholder="Search...">
                        <button class="btn btn-outline-secondary btn-sm" type="button">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </div>
               
                <!-- Contact List -->
                <div class="contact-list">
                    <?php if (count($conversations) > 0): ?>
                        <?php foreach ($conversations as $conv): ?>
                            <div class="contact-item <?php echo (isset($_GET['user_id']) && $_GET['user_id'] == $conv['other_id']) ? 'active' : ''; ?>"
                                 data-user-id="<?php echo $conv['other_id']; ?>"
                                 onclick="window.location.href='inbox.php?user_id=<?php echo $conv['other_id']; ?>'">
                                <div class="d-flex align-items-center">
                                    <div class="position-relative me-2">
                                        <?php if (!empty($conv['other_picture'])): ?>
                                            <img src="<?php echo $conv['other_picture']; ?>" alt="Profile" class="profile-pic">
                                        <?php else: ?>
                                            <div class="profile-pic bg-secondary d-flex justify-content-center align-items-center text-white">
                                                <?php echo strtoupper(substr($conv['other_name'], 0, 1)); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($conv['other_role'] == 'hr'): ?>
                                            <span class="position-absolute bottom-0 end-0 badge rounded-pill bg-primary" style="font-size: 0.6em;">HR</span>
                                        <?php elseif ($conv['other_role'] == 'admin'): ?>
                                            <span class="position-absolute bottom-0 end-0 badge rounded-pill bg-danger" style="font-size: 0.6em;">Admin</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex-grow-1 ms-2">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <h6 class="mb-0"><?php echo htmlspecialchars($conv['other_name']); ?></h6>
                                            <small class="text-muted"><?php echo date('M d', strtotime($conv['sent_at'])); ?></small>
                                        </div>
                                        <?php if (!empty($conv['other_department'])): ?>
                                            <small class="text-muted"><?php echo htmlspecialchars($conv['other_department']); ?> - <?php echo htmlspecialchars($conv['other_position']); ?></small>
                                        <?php endif; ?>
                                        <div class="d-flex justify-content-between align-items-center mt-1">
                                            <div class="preview-text">
                                                <?php if ($conv['sender_id'] == $current_user_id): ?>
                                                    <small class="text-muted">You: </small>
                                                <?php endif; ?>
                                                <?php echo htmlspecialchars(substr($conv['message'], 0, 30)); ?>
                                                <?php echo (strlen($conv['message']) > 30) ? '...' : ''; ?>
                                            </div>
                                            <?php if ($conv['unread_count'] > 0): ?>
                                                <span class="badge bg-primary rounded-pill unread-badge"><?php echo $conv['unread_count']; ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="p-3 text-center text-muted">
                            <p>No conversations yet</p>
                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#newMessageModal">
                                Start a new conversation
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Message Area -->
            <div class="col-md-9 col-lg-9 p-0 message-area">
                <?php if (isset($_GET['user_id'])): ?>
                    <!-- Message Header -->
                    <div class="message-header d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center">
                            <button class="btn btn-sm btn-outline-secondary me-2 mobile-toggle" id="toggleContacts">
                                <i class="fas fa-bars"></i>
                            </button>
                            <div class="position-relative me-2">
                                <?php if (!empty($other_user['profile_picture'])): ?>
                                    <img src="<?php echo $other_user['profile_picture']; ?>" alt="Profile" class="profile-pic">
                                <?php else: ?>
                                    <div class="profile-pic bg-secondary d-flex justify-content-center align-items-center text-white">
                                        <?php echo strtoupper(substr($other_user['first_name'], 0, 1)); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($other_user['role'] == 'hr'): ?>
                                    <span class="position-absolute bottom-0 end-0 badge rounded-pill bg-primary" style="font-size: 0.6em;">HR</span>
                                <?php elseif ($other_user['role'] == 'admin'): ?>
                                    <span class="position-absolute bottom-0 end-0 badge rounded-pill bg-danger" style="font-size: 0.6em;">Admin</span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <h5 class="mb-0"><?php echo $other_user['first_name'] . ' ' . $other_user['last_name']; ?></h5>
                                <small class="text-muted">
                                    <?php if (!empty($other_user['department'])): ?>
                                        <?php echo $other_user['department']; ?> - <?php echo $other_user['position']; ?>
                                    <?php else: ?>
                                        <?php echo ucfirst($other_user['role']); ?>
                                    <?php endif; ?>
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
                                        <div class="subject-line"><strong><?php echo htmlspecialchars($message['subject']); ?></strong></div>
                                    <?php endif; ?>
                                    <?php echo nl2br(htmlspecialchars($message['message'])); ?>
                                    <div class="message-time">
                                        <?php echo date('h:i A', strtotime($message['sent_at'])); ?>
                                        <?php if ($message['sender_id'] == $current_user_id): ?>
                                            <i class="fas fa-check-double ms-1" style="font-size: 0.8em; <?php echo $message['is_read'] ? 'color: #4fc3f7;' : ''; ?>"></i>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Message Input -->
                    <div class="message-input">
                        <form method="post" action="inbox.php?user_id=<?php echo $other_user_id; ?>">
                            <input type="hidden" name="receiver_id" value="<?php echo $other_user_id; ?>">
                            <div class="mb-3">
                                <textarea class="form-control" name="message" rows="3" placeholder="Type your message..." required></textarea>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <!-- Could add file attachments or other controls here -->
                                </div>
                                <button type="submit" name="send_message" class="btn btn-primary">
                                    <i class="fas fa-paper-plane me-1"></i> Send
                                </button>
                            </div>
                        </form>
                    </div>
                <?php else: ?>
                    <!-- Empty State -->
                    <div class="empty-state">
                        <div class="text-center">
                            <i class="fas fa-comments fa-4x mb-3"></i>
                            <h4>Your Messages</h4>
                            <p>Select a conversation or start a new one</p>
                            <button class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#newMessageModal">
                                <i class="fas fa-plus me-1"></i> New Message
                            </button>
                           
                            <button class="btn btn-outline-secondary mt-2 d-md-none" id="showContactsBtn">
                                <i class="fas fa-users me-1"></i> Show Conversations
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
                    <h5 class="modal-title" id="newMessageModalLabel">New Message</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="inbox.php">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="receiver_id" class="form-label">Send to</label>
                            <select class="form-select" id="receiver_id" name="receiver_id" required>
                                <option value="">Select recipient...</option>
                                <?php foreach ($available_users as $user): ?>
                                    <option value="<?php echo $user['id']; ?>">
                                        <?php echo $user['first_name'] . ' ' . $user['last_name']; ?>
                                        <?php if ($user['role'] == 'hr'): ?>(HR)<?php endif; ?>
                                        <?php if ($user['role'] == 'admin'): ?>(Admin)<?php endif; ?>
                                        <?php if (!empty($user['department'])): ?> - <?php echo $user['department']; ?><?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="subject" class="form-label">Subject (optional)</label>
                            <input type="text" class="form-control" id="subject" name="subject" placeholder="Add a subject...">
                        </div>
                        <div class="mb-3">
                            <label for="message" class="form-label">Message</label>
                            <textarea class="form-control" id="message" name="message" rows="5" required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="send_message" class="btn btn-primary">Send Message</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Scroll to bottom of messages container
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
                if (window.innerWidth <= 768 &&
                    !contactsList.contains(event.target) &&
                    event.target !== toggleContactsBtn &&
                    event.target !== showContactsBtn) {
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
                        if (contactName.includes(searchTerm)) {
                            item.style.display = 'block';
                        } else {
                            item.style.display = 'none';
                        }
                    });
                });
            }
           
            // Auto-refresh messages every 30 seconds
            if (window.location.search.includes('user_id=')) {
                setInterval(function() {
                    fetch(window.location.href)
                        .then(response => response.text())
                        .then(html => {
                            const parser = new DOMParser();
                            const doc = parser.parseFromString(html, 'text/html');
                            const newMessages = doc.getElementById('messagesContainer');
                            if (newMessages) {
                                document.getElementById('messagesContainer').innerHTML = newMessages.innerHTML;
                                // Scroll to bottom after refresh
                                messagesContainer.scrollTop = messagesContainer.scrollHeight;
                            }
                        });
                }, 30000); // 30 seconds
            }
           
            // Mark messages as read when they come into view
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const messageId = entry.target.dataset.messageId;
                        if (messageId && !entry.target.classList.contains('read')) {
                            // In a real app, you would send an AJAX request to mark as read
                            entry.target.classList.add('read');
                        }
                    }
                });
            }, { threshold: 0.5 });
           
            document.querySelectorAll('.message-bubble').forEach(bubble => {
                observer.observe(bubble);
            });
        });
    </script>
</body>
</html>
<?php
// Close database connection
$conn->close();
?>