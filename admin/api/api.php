<?php
session_start();
require_once("../config/db.php");

header("Content-Type: application/json");

// ================= SECURITY =================
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

// ================= INPUT =================
$action = $_GET['action'] ?? null;
$input  = json_decode(file_get_contents("php://input"), true);

// ================= HELPER =================
function respond($data) {
    echo json_encode($data);
    exit;
}

// ================= ROUTER =================
switch ($action) {

    // ================= GET ALL =================
    case "get_all":

        $users = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM users"), MYSQLI_ASSOC);
        $drivers = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM drivers"), MYSQLI_ASSOC);
        $bookings = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM bookings"), MYSQLI_ASSOC);
        $orders = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM orders"), MYSQLI_ASSOC);
        $notifications = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM notifications ORDER BY id DESC"), MYSQLI_ASSOC);

        // Optional tables (if exist)
        $flights = @mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM flights"), MYSQLI_ASSOC) ?: [];
        $trips = @mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM trips"), MYSQLI_ASSOC) ?: [];
        $vehicles = @mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM vehicles"), MYSQLI_ASSOC) ?: [];
        $dishes = @mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM dishes"), MYSQLI_ASSOC) ?: [];

        respond([
            "users" => $users,
            "drivers" => $drivers,
            "bookings" => $bookings,
            "orders" => $orders,
            "notifications" => $notifications,
            "flights" => $flights,
            "trips" => $trips,
            "vehicles" => $vehicles,
            "dishes" => $dishes
        ]);
    break;

    // ================= USERS =================
    case "create_user":
        $name = $input['name'];
        $email = $input['email'];

        mysqli_query($conn, "INSERT INTO users (name, email) VALUES ('$name','$email')");
        respond(["success" => true]);
    break;

    case "delete_user":
        $id = (int)$input['id'];
        mysqli_query($conn, "DELETE FROM users WHERE id=$id");
        respond(["success" => true]);
    break;

    // ================= DRIVERS =================
    case "create_driver":
        $name = $input['name'];
        $car = $input['car'];

        mysqli_query($conn, "INSERT INTO drivers (name, car) VALUES ('$name','$car')");
        respond(["success" => true]);
    break;

    case "delete_driver":
        $id = (int)$input['id'];
        mysqli_query($conn, "DELETE FROM drivers WHERE id=$id");
        respond(["success" => true]);
    break;

    // ================= BOOKINGS =================
    case "update_ride":
        $id = (int)$input['id'];
        $status = $input['status'];

        mysqli_query($conn, "UPDATE bookings SET status='$status' WHERE id=$id");
        respond(["success" => true]);
    break;

    // ================= FLIGHTS =================
    case "create_flight":
        $airline = $input['airline'];
        $from = $input['from'];
        $to = $input['to'];
        $price = $input['price'];

        mysqli_query($conn, "INSERT INTO flights (airline, origin, destination, price) 
                             VALUES ('$airline','$from','$to','$price')");
        respond(["success" => true]);
    break;

    // ================= TRIPS =================
    case "create_trip":
        $title = $input['title'];
        $location = $input['location'];
        $price = $input['price'];

        mysqli_query($conn, "INSERT INTO trips (title, location, price) 
                             VALUES ('$title','$location','$price')");
        respond(["success" => true]);
    break;

    // ================= VEHICLES =================
    case "create_vehicle":
        $name = $input['name'];
        $type = $input['type'];

        mysqli_query($conn, "INSERT INTO vehicles (name, type) 
                             VALUES ('$name','$type')");
        respond(["success" => true]);
    break;

    // ================= FOOD =================
    case "create_dish":
        $name = $input['name'];
        $price = $input['price'];

        mysqli_query($conn, "INSERT INTO dishes (name, price) 
                             VALUES ('$name','$price')");
        respond(["success" => true]);
    break;

    // ================= NOTIFICATIONS =================
    case "send_notification":
        $message = $input['message'];

        mysqli_query($conn, "INSERT INTO notifications (message, status) 
                             VALUES ('$message','unread')");
        respond(["success" => true]);
    break;

    case "mark_notification":
        $id = (int)$input['id'];

        mysqli_query($conn, "UPDATE notifications SET status='read' WHERE id=$id");
        respond(["success" => true]);
    break;

    // ================= DEFAULT =================
    default:
        respond(["error" => "Invalid action"]);
}