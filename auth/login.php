<?php
session_start();
require_once("../config/db.php");

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role     = $_POST['role'] ?? '';

    // Validate input
    if (empty($email) || empty($password) || empty($role)) {
        header("Location: ../index.php?error=All+fields+are+required");
        exit();
    }

    // ─── ADMIN HARDCODED (Optional) ─────────────────────────────
    if ($role === "admin" && $email === "admin@gmail.com" && $password === "admin123") {
        $_SESSION['user_id']   = 1;        // admin pseudo-ID
        $_SESSION['role']      = "admin";
        $_SESSION['admin_logged_in'] = true;
        header("Location: ../admin/dashboard.php");
        exit();
    }

    // ─── USER or DRIVER ───────────────────────────────────────
    $table = ($role === "user") ? "users" : "drivers";

    // Use prepared statement to prevent SQL injection
    $stmt = mysqli_prepare($conn, "SELECT * FROM $table WHERE email = ?");
    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if ($result && $data = mysqli_fetch_assoc($result)) {
        if (password_verify($password, $data['password'])) {
            // Set session based on role
            if ($role === "user") {
                $_SESSION['user_id'] = $data['id'];           // users.id
                $_SESSION['role']    = "user";
                $redirect = "../user/dashboard.php";
            } else { // driver
                $_SESSION['driver_id'] = $data['driver_id'];  // drivers.driver_id (correct PK)
                $_SESSION['role']      = "driver";
                $redirect = "../driver/dashboard.php";
            }
            header("Location: $redirect");
            exit();
        } else {
            header("Location: ../index.php?error=Invalid+password");
            exit();
        }
    } else {
        header("Location: ../index.php?error=No+account+found+with+that+email");
        exit();
    }
} else {
    header("Location: ../index.php?error=Invalid+request");
    exit();
}
?>