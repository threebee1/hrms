<?php
// Set headers for JSON response and CORS
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Replace * with your frontend domain in production
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

try {
    // Database connection
    $host = 'localhost';
    $dbname = 'hrms';
    $dbuser = 'root';
    $dbpass = '';

    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $dbuser, $dbpass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['login_key']) || !isset($input['password']) || !isset($input['type'])) {
        throw new Exception('Invalid or missing input data');
    }

    $login_key = $input['login_key'];
    $password = $input['password'];
    $type = $input['type'];

    // Prepare query based on type
    $field = $type === 'email' ? 'email' : 'username';
    $stmt = $pdo->prepare("SELECT * FROM users WHERE $field = :login_key");
    $stmt->execute(['login_key' => $login_key]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        // Verify password
        if (password_verify($password, $user['password'])) {
            // Start session
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Strict'
            ]);
            session_start();
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];

            // Set redirect based on role
            $redirect = 'index.php'; // Default landing page
            if (isset($user['role'])) {
                $role = strtolower($user['role']);
                if ($role === 'hr') {
                    $redirect = 'dashboard.php';
                } elseif ($role === 'employee') {
                    $redirect = 'employeeDashboard.php';
                }
            }

            echo json_encode([
                'success' => true,
                'message' => 'Login successful',
                'redirect' => $redirect
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Invalid password'
            ]);
        }
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'User not found'
        ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}
?>