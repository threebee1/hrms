<?php
session_start();


$response = '';
$search = '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;  // Number of employees per page
$offset = ($page - 1) * $limit;


// Handle search functionality
if (isset($_POST['search'])) {
    $search = trim($_POST['search']);
}


$conn = new mysqli("localhost", "root", "", "hrms");


if ($conn->connect_error) {
    $response = "❌ Database connection failed!";
} else {
    // Build query with search and pagination
    $query = "SELECT * FROM employees WHERE first_name LIKE ? OR last_name LIKE ? OR email LIKE ? LIMIT ?, ?";
    $stmt = $conn->prepare($query);
    $searchTerm = '%' . $search . '%';
    $stmt->bind_param("sssii", $searchTerm, $searchTerm, $searchTerm, $offset, $limit);
    $stmt->execute();
    $result = $stmt->get_result();


    // Fetch total number of employees for pagination
    $countQuery = "SELECT COUNT(*) as total FROM employees WHERE first_name LIKE ? OR last_name LIKE ? OR email LIKE ?";
    $countStmt = $conn->prepare($countQuery);
    $countStmt->bind_param("sss", $searchTerm, $searchTerm, $searchTerm);
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $totalEmployees = $countResult->fetch_assoc()['total'];
    $totalPages = ceil($totalEmployees / $limit);


    if ($result->num_rows > 0) {
        echo "<h2>All Employees</h2>";
        echo "<form method='POST' action=''>
                <input type='text' name='search' value='$search' placeholder='Search by Name, Email, or Department' />
                <button type='submit'>Search</button>
              </form>";
        echo "<table class='employee-table'>";
        echo "<thead>
                <tr>
                    <th>ID</th>
                    <th>First Name</th>
                    <th>Last Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Birthdate</th>
                    <th>Address</th>
                    <th>Department</th>
                    <th>Position</th>
                    <th>Hire Date</th>
                </tr>
              </thead>";
        echo "<tbody>";


        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . $row['id'] . "</td>";
            echo "<td>" . $row['first_name'] . "</td>";
            echo "<td>" . $row['last_name'] . "</td>";
            echo "<td>" . $row['email'] . "</td>";
            echo "<td>" . $row['phone'] . "</td>";
            echo "<td>" . $row['birthdate'] . "</td>";
            echo "<td>" . $row['address'] . "</td>";
            echo "<td>" . $row['department'] . "</td>";
            echo "<td>" . $row['position'] . "</td>";
            echo "<td>" . $row['hire_date'] . "</td>";
            echo "</tr>";
        }


        echo "</tbody>";
        echo "</table>";


        // Pagination Controls
        echo "<div class='pagination'>";
        if ($page > 1) {
            echo "<a href='?page=" . ($page - 1) . "'>Previous</a>";
        }
        echo " | Page $page of $totalPages | ";
        if ($page < $totalPages) {
            echo "<a href='?page=" . ($page + 1) . "'>Next</a>";
        }
        echo "</div>";


    } else {
        $response = "❌ No employees found.";
    }


    $stmt->close();
    $countStmt->close();
    $conn->close();
}
?>


<!-- Display the response message if there's any error or status -->
<?php if ($response) echo "<p>$response</p>"; ?>


<!-- Add some simple styles -->
<style>
    body {
        font-family: Arial, sans-serif;
        margin: 0;
        padding: 0;
        background-color: #f4f4f4;
    }


    h2 {
        text-align: center;
        margin-top: 20px;
    }


    .employee-table {
        width: 80%;
        margin: 20px auto;
        border-collapse: collapse;
        background-color: white;
        box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
    }


    .employee-table th, .employee-table td {
        padding: 10px;
        text-align: left;
        border: 1px solid #ddd;
    }


    .employee-table th {
        background-color: #4CAF50;
        color: white;
    }


    .employee-table tr:nth-child(even) {
        background-color: #f2f2f2;
    }


    .pagination {
        text-align: center;
        margin-top: 20px;
    }


    .pagination a {
        color: #4CAF50;
        padding: 8px 16px;
        text-decoration: none;
        margin: 0 5px;
    }


    .pagination a:hover {
        background-color: #ddd;
        border-radius: 5px;
    }


    input[type="text"] {
        padding: 8px;
        width: 250px;
        margin-right: 10px;
    }


    button {
        padding: 8px 16px;
        background-color: #4CAF50;
        color: white;
        border: none;
        cursor: pointer;
    }


    button:hover {
        background-color: #45a049;
    }


    form {
        text-align: center;
        margin-bottom: 20px;
    }
</style>

