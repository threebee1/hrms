<?php
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict'
]);
session_start();
session_regenerate_id(true);

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

$user_id = (int)$_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT id FROM employees WHERE user_id = ?");
$stmt->execute([$user_id]);
$employee_data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employee_data) {
    $stmt = $pdo->prepare("INSERT INTO employees (user_id) VALUES (?)");
    if ($stmt->execute([$user_id])) {
        $employee_id = $pdo->lastInsertId();
    } else {
        $error_message = "System error: Failed to create employee record.";
    }
} else {
    $employee_id = $employee_data['id'];
}

$error_message = $success_message = '';
$max_contacts = 5;

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    if (isset($_POST['add_contact'])) {
        $name = trim($_POST['name'] ?? '');
        $relationship = trim($_POST['relationship'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        
        if (empty($name) || empty($relationship) || empty($phone)) {
            $error_message = "All fields are required.";
        } elseif (!preg_match('/^[A-Za-z\s\'-]{2,50}$/', $name)) {
            $error_message = "Name must be 2-50 characters, containing only letters, spaces, hyphens, or apostrophes.";
        } elseif (!preg_match('/^[A-Za-z\s\'-]{2,20}$/', $relationship)) {
            $error_message = "Relationship must be 2-20 characters, containing only letters, spaces, hyphens, or apostrophes.";
        } elseif (!preg_match('/^\+?\d{10,15}$/', $phone)) {
            $error_message = "Phone number must be 10-15 digits, optionally starting with '+'.";
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM emergency_contacts WHERE employee_id = ?");
            $stmt->execute([$employee_id]);
            if ($stmt->fetchColumn() >= $max_contacts) {
                $error_message = "Maximum of $max_contacts emergency contacts reached.";
            } else {
                $stmt = $pdo->prepare("SELECT id FROM emergency_contacts WHERE employee_id = ? AND phone = ?");
                $stmt->execute([$employee_id, $phone]);
                if ($stmt->fetch()) {
                    $error_message = "An emergency contact with the phone number '<strong>" . htmlspecialchars($phone) . "</strong>' already exists.";
                } else {
                    $stmt = $pdo->prepare("INSERT INTO emergency_contacts (employee_id, name, relationship, phone) VALUES (?, ?, ?, ?)");
                    if ($stmt->execute([$employee_id, $name, $relationship, $phone])) {
                        $success_message = "Emergency contact added successfully!";
                    } else {
                        $error_message = "Error adding contact.";
                    }
                }
            }
        }
    } elseif (isset($_POST['delete_contact'])) {
        $contact_id = (int)($_POST['contact_id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM emergency_contacts WHERE id = ? AND employee_id = ?");
        if ($stmt->execute([$contact_id, $employee_id])) {
            $success_message = "Emergency contact deleted successfully!";
        } else {
            $error_message = "Error deleting contact.";
        }
    } elseif (isset($_POST['update_contact'])) {
        $contact_id = (int)($_POST['contact_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $relationship = trim($_POST['relationship'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        
        if (empty($name) || empty($relationship) || empty($phone)) {
            $error_message = "All fields are required.";
        } elseif (!preg_match('/^[A-Za-z\s\'-]{2,50}$/', $name)) {
            $error_message = "Name must be 2-50 characters, containing only letters, spaces, hyphens, or apostrophes.";
        } elseif (!preg_match('/^[A-Za-z\s\'-]{2,20}$/', $relationship)) {
            $error_message = "Relationship must be 2-20 characters, containing only letters, spaces, hyphens, or apostrophes.";
        } elseif (!preg_match('/^\+?\d{10,15}$/', $phone)) {
            $error_message = "Phone number must be 10-15 digits, optionally starting with '+'.";
        } else {
            $stmt = $pdo->prepare("SELECT id FROM emergency_contacts WHERE employee_id = ? AND phone = ? AND id != ?");
            $stmt->execute([$employee_id, $phone, $contact_id]);
            if ($stmt->fetch()) {
                $error_message = "Another emergency contact with the phone number '<strong>" . htmlspecialchars($phone) . "</strong>' already exists.";
            } else {
                $stmt = $pdo->prepare("UPDATE emergency_contacts SET name = ?, relationship = ?, phone = ? WHERE id = ? AND employee_id = ?");
                if ($stmt->execute([$name, $relationship, $phone, $contact_id, $employee_id])) {
                    $success_message = "Emergency contact updated successfully!";
                } else {
                    $error_message = "Error updating contact.";
                }
            }
        }
    }
}

$stmt = $pdo->prepare("SELECT * FROM emergency_contacts WHERE employee_id = ?");
$stmt->execute([$employee_id]);
$contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Emergency Contacts | HRPro</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
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
            color: var(--text);
            line-height: 1.5;
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

        .section-header {
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            color: var(--white);
            padding: 20px;
            border-radius: var(--border-radius);
            text-align: center;
            margin-bottom: 24px;
        }

        .section-body {
            padding: 20px;
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
        }

        .alert-success, .alert-error {
            padding: 15px;
            border-radius: var(--border-radius);
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            animation: fadeIn 0.5s ease-in;
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

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .form-control {
            padding: 12px;
            border: 2px solid var(--gray);
            border-radius: var(--border-radius);
            transition: var(--transition);
        }

        .form-control:focus {
            border-color: var(--secondary);
            box-shadow: 0 0 0 3px rgba(191, 162, 219, 0.3);
        }

        .form-control.is-invalid {
            border-color: var(--error);
            background-image: none;
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

        .btn-warning {
            background: linear-gradient(135deg, #ffc107, #ffca2c);
            color: #000;
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--error), #ff8787);
            color: var(--white);
        }

        .btn-toggle {
            background: none;
            border: 2px solid var(--primary);
            color: var(--primary);
            padding: 8px 16px;
            border-radius: var(--border-radius);
            transition: var(--transition);
        }

        .btn-toggle.active, .btn-toggle:hover {
            background: var(--primary);
            color: var(--white);
        }

        .table {
            background: var(--white);
            border-radius: var(--border-radius);
            overflow: hidden;
        }

        .table thead th {
            background: var(--primary);
            color: var(--white);
            padding: 14px;
        }

        .table tbody tr:nth-child(odd) {
            background: var(--light);
        }

        .table tbody tr:hover {
            background: rgba(191, 162, 219, 0.1);
        }

        .badge {
            padding: 6px 12px;
            border-radius: var(--border-radius);
            color: #fff;
        }

        .relationship-badge {
            background: var(--secondary);
        }

        .modal-header {
            border-radius: var(--border-radius) var(--border-radius) 0 0;
        }

        .modal-header.bg-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            color: var(--white);
        }

        .modal-header.bg-danger {
            background: linear-gradient(135deg, var(--error), #ff8787);
        }

        .info-footer {
            background: var(--light);
            border-radius: var(--border-radius);
            padding: 20px;
            margin-top: 24px;
            cursor: pointer;
            transition: var(--transition);
        }

        .info-footer .info-content {
            display: none;
        }

        .info-footer.active .info-content {
            display: block;
        }

        .info-footer:hover {
            background: var(--secondary);
            color: var(--white);
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
                padding: 15px;
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
            <a href="timeoff.php" class="menu-item"><i class="fas fa-calendar-minus"></i><span class="menu-text">Time Off</span></a>
            <a href="emergency.php" class="menu-item active"><i class="fas fa-bell"></i><span class="menu-text">Emergency</span></a>
            <a href="performance.php" class="menu-item"><i class="fas fa-chart-line"></i><span class="menu-text">Performance</span></a>
            <a href="professionalpath.php" class="menu-item"><i class="fas fa-briefcase"></i><span class="menu-text">Professional Path</span></a>
            <a href="inbox.php" class="menu-item"><i class="fas fa-inbox"></i><span class="menu-text">Inbox</span></a>
            <a href="addEmployees.php" class="menu-item"><i class="fas fa-user-plus"></i><span class="menu-text">Add Employee</span></a>
            <a href="login.html" class="menu-item"><i class="fas fa-sign-out-alt"></i><span class="menu-text">Logout</span></a>
        </nav>
    </aside>

    <main class="main-content">
        <div class="section-header">
            <h2><i class="fas fa-phone-alt me-2"></i>Emergency Contacts</h2>
        </div>

        <?php if ($error_message): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?></div>
        <?php endif; ?>
        <?php if ($success_message): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?></div>
        <?php endif; ?>

        <div class="section-body">
            <div class="d-flex justify-content-start mb-4">
                <button class="btn btn-toggle me-2 active" id="addContactTab" role="tab" aria-selected="true">Add Contact</button>
                <button class="btn btn-toggle" id="viewContactsTab" role="tab" aria-selected="false">View Contacts</button>
            </div>

            <div id="addContactSection">
                <h5 class="mb-3"><i class="fas fa-plus-circle me-2"></i>Add New Emergency Contact</h5>
                <form method="POST" id="addContactForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="name" class="form-label">Full Name</label>
                            <input type="text" class="form-control" id="name" name="name" required aria-required="true" pattern="[A-Za-z\s'-]{2,50}" title="Name must be 2-50 characters, containing only letters, spaces, hyphens, or apostrophes.">
                        </div>
                        <div class="col-md-4">
                            <label for="relationship" class="form-label">Relationship</label>
                            <input type="text" class="form-control" id="relationship" name="relationship" required aria-required="true" pattern="[A-Za-z\s'-]{2,20}" title="Relationship must be 2-20 characters, containing only letters, spaces, hyphens, or apostrophes.">
                        </div>
                        <div class="col-md-4">
                            <label for="phone" class="form-label">Phone Number</label>
                            <input type="text" class="form-control" id="phone" name="phone" required aria-required="true" pattern="\+?\d{10,15}" title="Phone number must be 10-15 digits, optionally starting with '+'.">
                        </div>
                    </div>
                    <div class="text-end mt-3">
                        <button type="submit" name="add_contact" class="btn btn-primary"><i class="fas fa-save me-2"></i>Add Contact</button>
                    </div>
                </form>
            </div>

            <div id="viewContactsSection" style="display: none;">
                <h5 class="mb-3"><i class="fas fa-list me-2"></i>My Emergency Contacts</h5>
                <?php if (count($contacts) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Relationship</th>
                                    <th>Phone Number</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($contacts as $contact): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($contact['name']); ?></td>
                                        <td><span class="badge relationship-badge"><?php echo htmlspecialchars($contact['relationship']); ?></span></td>
                                        <td><?php echo htmlspecialchars($contact['phone']); ?></td>
                                        <td class="text-end">
                                            <button class="btn btn-sm btn-primary me-1" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $contact['id']; ?>" data-bs-toggle="tooltip" title="Edit Contact">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#deleteModal<?php echo $contact['id']; ?>" data-bs-toggle="tooltip" title="Delete Contact">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>

                                    <!-- Edit Modal -->
                                    <div class="modal fade" id="editModal<?php echo $contact['id']; ?>" tabindex="-1" aria-labelledby="editModalLabel<?php echo $contact['id']; ?>" aria-hidden="true">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header bg-primary">
                                                    <h5 class="modal-title" id="editModalLabel<?php echo $contact['id']; ?>">Edit Emergency Contact</h5>
                                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                                </div>
                                                <form method="POST" id="editContactForm<?php echo $contact['id']; ?>">
                                                    <div class="modal-body">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                        <input type="hidden" name="contact_id" value="<?php echo $contact['id']; ?>">
                                                        <div class="mb-3">
                                                            <label for="edit_name_<?php echo $contact['id']; ?>" class="form-label">Full Name</label>
                                                            <input type="text" class="form-control" id="edit_name_<?php echo $contact['id']; ?>" name="name" value="<?php echo htmlspecialchars($contact['name']); ?>" required aria-required="true" pattern="[A-Za-z\s'-]{2,50}" title="Name must be 2-50 characters, containing only letters, spaces, hyphens, or apostrophes.">
                                                        </div>
                                                        <div class="mb-3">
                                                            <label for="edit_relationship_<?php echo $contact['id']; ?>" class="form-label">Relationship</label>
                                                            <input type="text" class="form-control" id="edit_relationship_<?php echo $contact['id']; ?>" name="relationship" value="<?php echo htmlspecialchars($contact['relationship']); ?>" required aria-required="true" pattern="[A-Za-z\s'-]{2,20}" title="Relationship must be 2-20 characters, containing only letters, spaces, hyphens, or apostrophes.">
                                                        </div>
                                                        <div class="mb-3">
                                                            <label for="edit_phone_<?php echo $contact['id']; ?>" class="form-label">Phone Number</label>
                                                            <input type="text" class="form-control" id="edit_phone_<?php echo $contact['id']; ?>" name="phone" value="<?php echo htmlspecialchars($contact['phone']); ?>" required aria-required="true" pattern="\+?\d{10,15}" title="Phone number must be 10-15 digits, optionally starting with '+'.">
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" name="update_contact" class="btn btn-primary">Update Contact</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Delete Modal -->
                                    <div class="modal fade" id="deleteModal<?php echo $contact['id']; ?>" tabindex="-1" aria-labelledby="deleteModalLabel<?php echo $contact['id']; ?>" aria-hidden="true">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header bg-danger text-white">
                                                    <h5 class="modal-title" id="deleteModalLabel<?php echo $contact['id']; ?>">Delete Emergency Contact</h5>
                                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <p>Are you sure you want to delete <strong><?php echo htmlspecialchars($contact['name']); ?></strong> from your emergency contacts?</p>
                                                    <p class="text-danger"><small>This action cannot be undone.</small></p>
                                                </div>
                                                <div class="modal-footer">
                                                    <form method="POST">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                        <input type="hidden" name="contact_id" value="<?php echo $contact['id']; ?>">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" name="delete_contact" class="btn btn-danger">Delete Contact</button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i> You haven't added any emergency contacts yet.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Information Footer -->
        <div class="info-footer">
            <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Important Information</h5>
            <div class="info-content">
                <p>Emergency contacts are individuals who should be contacted in case of an emergency involving you. It's recommended to add at least two emergency contacts (maximum <?php echo $max_contacts; ?>).</p>
                <ul>
                    <li>Ensure phone numbers are accurate and up-to-date.</li>
                    <li>Consider adding contacts available during your working hours.</li>
                    <li>Inform your emergency contacts that you've listed them.</li>
                </ul>
            </div>
        </div>
    </main>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('sidebarToggle').addEventListener('click', () => {
            document.querySelector('.sidebar').classList.toggle('collapsed');
        });

        document.querySelector('.info-footer').addEventListener('click', () => {
            const footer = document.querySelector('.info-footer');
            footer.classList.toggle('active');
        });

        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
            new bootstrap.Tooltip(el);
        });

        const addContactTab = document.getElementById('addContactTab');
        const viewContactsTab = document.getElementById('viewContactsTab');
        const addContactSection = document.getElementById('addContactSection');
        const viewContactsSection = document.getElementById('viewContactsSection');

        addContactTab.addEventListener('click', () => {
            addContactTab.classList.add('active');
            viewContactsTab.classList.remove('active');
            addContactSection.style.display = 'block';
            viewContactsSection.style.display = 'none';
            addContactTab.setAttribute('aria-selected', 'true');
            viewContactsTab.setAttribute('aria-selected', 'false');
        });

        viewContactsTab.addEventListener('click', () => {
            viewContactsTab.classList.add('active');
            addContactTab.classList.remove('active');
            viewContactsSection.style.display = 'block';
            addContactSection.style.display = 'none';
            viewContactsTab.setAttribute('aria-selected', 'true');
            addContactTab.setAttribute('aria-selected', 'false');
        });

        function validateForm(formId) {
            const form = document.getElementById(formId);
            const inputs = form.querySelectorAll('input[required]');
            let isValid = true;

            inputs.forEach(input => {
                const pattern = input.getAttribute('pattern');
                if (pattern && !new RegExp('^' + pattern + '$').test(input.value)) {
                    input.classList.add('is-invalid');
                    isValid = false;
                } else {
                    input.classList.remove('is-invalid');
                }
            });

            return isValid;
        }

        document.getElementById('addContactForm').addEventListener('submit', (e) => {
            if (!validateForm('addContactForm')) {
                e.preventDefault();
                alert('Please correct the invalid fields.');
            }
        });

        document.querySelectorAll('[id^="editContactForm"]').forEach(form => {
            form.addEventListener('submit', (e) => {
                if (!validateForm(form.id)) {
                    e.preventDefault();
                    alert('Please correct the invalid fields.');
                }
            });
        });
    </script>
</body>
</html>