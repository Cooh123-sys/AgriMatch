<?php
// config/db.php

// ----- Connection settings (WAMP defaults) -----
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');           // default WAMP root password is empty
define('DB_NAME', 'agrimatch');

// 1. Connect to MySQL server WITHOUT selecting a database yet
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS);

if ($conn->connect_error) {
    die('Database connection failed: ' . $conn->connect_error);
}

// 2. Create the database if it doesn't exist
$sql = "CREATE DATABASE IF NOT EXISTS " . DB_NAME . " CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";
if (!$conn->query($sql)) {
    die('Error creating database: ' . $conn->error);
}

// 3. Select the database
$conn->select_db(DB_NAME);

// 4. Create tables if they don't exist
$tableQueries = [];

// USERS table (shared login table for farmer, buyer, admin)
$tableQueries[] = "
CREATE TABLE IF NOT EXISTS users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    role ENUM('farmer','buyer','admin') NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    phone VARCHAR(20) NOT NULL,
    password VARCHAR(255) NOT NULL,
    status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";

// FARMER DETAILS table
$tableQueries[] = "
CREATE TABLE IF NOT EXISTS farmer_details (
    farmer_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    location VARCHAR(150) NOT NULL,
    id_document VARCHAR(255) DEFAULT NULL,   -- uploaded National ID file path
    map_document VARCHAR(255) DEFAULT NULL,  -- uploaded map-to-home file path
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";

// FARMER CROPS table (one farmer -> many crop types)
$tableQueries[] = "
CREATE TABLE IF NOT EXISTS farmer_crops (
    crop_id INT AUTO_INCREMENT PRIMARY KEY,
    farmer_id INT NOT NULL,
    crop_type VARCHAR(100) NOT NULL,
    FOREIGN KEY (farmer_id) REFERENCES farmer_details(farmer_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";

// BUYER DETAILS table
$tableQueries[] = "
CREATE TABLE IF NOT EXISTS buyer_details (
    buyer_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    physical_address VARCHAR(255) NOT NULL,
    organization_type ENUM('school','hotel','manufacturing_company','hospital','retailer','wholesaler','exporter','other') NOT NULL,
    business_certificate VARCHAR(255) DEFAULT NULL, -- uploaded certificate file path
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";

// Run each table creation query
foreach ($tableQueries as $query) {
    if (!$conn->query($query)) {
        die('Error creating table: ' . $conn->error);
    }
}

// 5. Seed a default admin account if none exists yet
$check = $conn->query("SELECT user_id FROM users WHERE role = 'admin' LIMIT 1");
if ($check->num_rows === 0) {
    $adminName  = 'System Administrator';
    $adminEmail = 'admin@agrimatch.com';
    $adminPhone = '0000000000';
    $adminPass  = password_hash('Admin@123', PASSWORD_DEFAULT); // change after first login

    $stmt = $conn->prepare("INSERT INTO users (role, full_name, email, phone, password, status) VALUES ('admin', ?, ?, ?, ?, 'verified')");
    $stmt->bind_param('ssss', $adminName, $adminEmail, $adminPhone, $adminPass);
    $stmt->execute();
    $stmt->close();
}
?>