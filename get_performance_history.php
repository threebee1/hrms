<?php
// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "hrms";


$conn = new mysqli($servername, $username, $password, $dbname);


if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}


if (isset($_GET['employee_id'])) {
    $employee_id = $_GET['employee_id'];
   
    // Get employee info
    $emp_sql = "SELECT first_name, last_name FROM employees WHERE id = ?";
    $emp_stmt = $conn->prepare($emp_sql);
    $emp_stmt->bind_param("i", $employee_id);
    $emp_stmt->execute();
    $emp_result = $emp_stmt->get_result();
    $emp_row = $emp_result->fetch_assoc();
   
    // Get performance history
    $sql = "SELECT p.*, e.first_name, e.last_name
            FROM performance p
            JOIN employees e ON p.employee_id = e.id
            WHERE p.employee_id = ?
            ORDER BY p.review_date DESC";
           
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $employee_id);
    $stmt->execute();
    $result = $stmt->get_result();
   
    if ($result->num_rows > 0) {
        echo '<table class="table table-striped">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Rating</th>
                        <th>Comments</th>
                    </tr>
                </thead>
                <tbody>';
               
        while ($row = $result->fetch_assoc()) {
            echo '<tr>
                    <td>' . date("M d, Y", strtotime($row["review_date"])) . '</td>
                    <td>';
                   
            for ($i = 1; $i <= 5; $i++) {
                if ($i <= $row["rating"]) {
                    echo '<i class="fas fa-star text-warning"></i>';
                } else {
                    echo '<i class="far fa-star text-muted"></i>';
                }
            }
           
            echo '</td>
                    <td>' . nl2br(htmlspecialchars($row["comments"])) . '</td>
                  </tr>';
        }
       
        echo '</tbody></table>';
    } else {
        echo '<div class="alert alert-info">No performance history found for this employee.</div>';
    }
} else {
    echo '<div class="alert alert-danger">Invalid request.</div>';
}


$conn->close();
?>
