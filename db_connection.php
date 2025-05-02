<?php
// Database configuration
$host = "localhost";    // Database host (usually 'localhost' if you're running MySQL locally)
$username = "root";     // Database username
$password = "";         // Database password (empty by default for 'root' in many local setups)
$database = "hrms";     // Name of the database to connect to


// Create a connection using mysqli
$conn = new mysqli($host, $username, $password, $database);


// Check if the connection was successful
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
} else {
   
}


// Optionally, you can return the connection for use in other files
// return $conn;
?>


