<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php?error=admin_required");
    exit;
}
require_once("../config/db.php");

// Helper functions
function getCount($conn, $table) {
    $result = mysqli_query($conn, "SELECT COUNT(*) as total FROM `$table`");
    $row = mysqli_fetch_assoc($result);
    return $row ? $row['total'] : 0;
}
function getSum($conn, $table, $col) {
    $result = mysqli_query($conn, "SELECT COALESCE(SUM(`$col`),0) as total FROM `$table`");
    $row = mysqli_fetch_assoc($result);
    return $row ? $row['total'] : 0;
}
function uploadImage($fileKey, $targetDir = "../uploads/") {
    if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) return null;
    $ext = strtolower(pathinfo($_FILES[$fileKey]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','gif','webp'])) return false;
    $filename = time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);
    if (move_uploaded_file($_FILES[$fileKey]['tmp_name'], $targetDir . $filename)) return $filename;
    return false;
}
function statusBadge($status) {
    $status = strtolower($status ?? '');
    $map = [
        'active' => 'green', 'completed' => 'green', 'approved' => 'green', 'delivered' => 'green',
        'confirmed' => 'blue', 'pending' => 'gold', 'cancelled' => 'red', 'declined' => 'red',
        'offline' => 'gray', 'online' => 'green', 'rejected' => 'red', 'accepted' => 'blue',
        'processing' => 'orange', 'dispatched' => 'blue', 'returned' => 'purple',
        'unpaid' => 'red', 'paid' => 'green', 'searching' => 'gold', 'in progress' => 'blue',
        'scheduled' => 'gold', 'boarding' => 'blue', 'delayed' => 'red',
        'out for delivery' => 'orange', 'preparing' => 'blue', 'overdue' => 'red'
    ];
    $cls = $map[$status] ?? 'gray';
    return "<span class=\"badge badge-$cls\">" . ucfirst($status) . "</span>";
}

$stats = [
    'users' => getCount($conn, 'users'),
    'drivers' => getCount($conn, 'drivers'),
    'bookings' => getCount($conn, 'bookings'),
    'flights' => getCount($conn, 'flights'),
    'flight_bookings' => getCount($conn, 'flight_bookings'),
    'orders' => getCount($conn, 'orders'),
    'vehicles' => getCount($conn, 'vehicles'),
    'trips' => getCount($conn, 'trips'),
    'trip_bookings' => getCount($conn, 'trip_bookings'),
    'rentals' => getCount($conn, 'rentals'),
    'rental_vehicles' => getCount($conn, 'rental_vehicles'),
    'purchases' => getCount($conn, 'purchases'),
    'foods' => getCount($conn, 'foods'),
];
$totalRevenue = getSum($conn, 'bookings', 'fare') + getSum($conn, 'orders', 'total_price') + getSum($conn, 'rentals', 'total_price') + getSum($conn, 'flight_bookings', 'total_price') + getSum($conn, 'trip_bookings', 'total_price');
$activeDrivers = getCount($conn, 'drivers'); // simplified

$msg = '';
$msgType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'update_booking') {
        $id = intval($_POST['booking_id']); $status = $_POST['status'];
        if (in_array($status, ['pending','accepted','declined','completed'])) {
            $stmt = mysqli_prepare($conn, "UPDATE bookings SET status=? WHERE id=?");
            mysqli_stmt_bind_param($stmt, "si", $status, $id);
            mysqli_stmt_execute($stmt); mysqli_stmt_close($stmt);
            $msg = "Booking #$id updated to $status"; $msgType = 'success';
        }
    } elseif ($action === 'delete_booking') {
        $id = intval($_POST['booking_id']);
        mysqli_query($conn, "DELETE FROM bookings WHERE id=$id");
        $msg = "Booking deleted"; $msgType = 'warning';
    } elseif ($action === 'update_trip') {
        $id = intval($_POST['trip_id']); $status = $_POST['status'];
        if (in_array($status, ['confirmed','cancelled','completed'])) {
            $stmt = mysqli_prepare($conn, "UPDATE trips SET status=? WHERE id=?");
            mysqli_stmt_bind_param($stmt, "si", $status, $id);
            mysqli_stmt_execute($stmt); mysqli_stmt_close($stmt);
            $msg = "Trip #$id updated"; $msgType = 'success';
        }
    } elseif ($action === 'delete_trip') {
        $id = intval($_POST['trip_id']);
        mysqli_query($conn, "DELETE FROM trips WHERE id=$id");
        $msg = "Trip deleted"; $msgType = 'warning';
      } elseif ($action === 'update_trip_booking') {
        $id = intval($_POST['tb_id']); $status = $_POST['status'];
        if (in_array($status, ['pending','confirmed','cancelled','completed'])) {
          $stmt = mysqli_prepare($conn, "UPDATE trip_bookings SET status=? WHERE id=?");
          mysqli_stmt_bind_param($stmt, "si", $status, $id);
          mysqli_stmt_execute($stmt); mysqli_stmt_close($stmt);
          $msg = "Trip booking #$id updated"; $msgType = 'success';
        }
      } elseif ($action === 'delete_trip_booking') {
        $id = intval($_POST['tb_id']);
        mysqli_query($conn, "DELETE FROM trip_bookings WHERE id=$id");
        $msg = "Trip booking deleted"; $msgType = 'warning';
    } elseif ($action === 'update_flight') {
        $id = intval($_POST['flight_id']); $status = $_POST['status'];
        if (in_array($status, ['confirmed','cancelled','completed'])) {
            $stmt = mysqli_prepare($conn, "UPDATE flights SET status=? WHERE id=?");
            mysqli_stmt_bind_param($stmt, "si", $status, $id);
            mysqli_stmt_execute($stmt); mysqli_stmt_close($stmt);
            $msg = "Flight #$id updated"; $msgType = 'success';
        }
    } elseif ($action === 'delete_flight') {
        $id = intval($_POST['flight_id']);
        mysqli_query($conn, "DELETE FROM flights WHERE id=$id");
        $msg = "Flight deleted"; $msgType = 'warning';
    } elseif ($action === 'update_flight_booking') {
        $id = intval($_POST['fb_id']); $status = $_POST['status'];
        if (in_array($status, ['pending','confirmed','cancelled','completed'])) {
            $stmt = mysqli_prepare($conn, "UPDATE flight_bookings SET status=? WHERE id=?");
            mysqli_stmt_bind_param($stmt, "si", $status, $id);
            mysqli_stmt_execute($stmt); mysqli_stmt_close($stmt);
            $msg = "Flight booking #$id updated"; $msgType = 'success';
        }
    } elseif ($action === 'delete_flight_booking') {
        $id = intval($_POST['fb_id']);
        mysqli_query($conn, "DELETE FROM flight_bookings WHERE id=$id");
        $msg = "Flight booking deleted"; $msgType = 'warning';
    } elseif ($action === 'update_order') {
        $id = intval($_POST['order_id']); $status = $_POST['status'];
        if (in_array($status, ['pending','processing','dispatched','delivered','cancelled'])) {
            $stmt = mysqli_prepare($conn, "UPDATE orders SET status=? WHERE id=?");
            mysqli_stmt_bind_param($stmt, "si", $status, $id);
            mysqli_stmt_execute($stmt); mysqli_stmt_close($stmt);
            $msg = "Order #$id updated"; $msgType = 'success';
        }
    } elseif ($action === 'delete_order') {
        $id = intval($_POST['order_id']);
        mysqli_query($conn, "DELETE FROM orders WHERE id=$id");
        $msg = "Order deleted"; $msgType = 'warning';
    } elseif ($action === 'update_rental') {
        $id = intval($_POST['rental_id']); $status = $_POST['status'];
        if (in_array($status, ['pending','confirmed','active','returned','cancelled'])) {
            $stmt = mysqli_prepare($conn, "UPDATE rentals SET status=? WHERE id=?");
            mysqli_stmt_bind_param($stmt, "si", $status, $id);
            mysqli_stmt_execute($stmt); mysqli_stmt_close($stmt);
            $msg = "Rental #$id updated"; $msgType = 'success';
        }
    } elseif ($action === 'delete_rental') {
        $id = intval($_POST['rental_id']);
        mysqli_query($conn, "DELETE FROM rentals WHERE id=$id");
        $msg = "Rental deleted"; $msgType = 'warning';
    } elseif ($action === 'update_purchase') {
        $id = intval($_POST['purchase_id']); $status = $_POST['status'];
        if (in_array($status, ['pending','approved','completed','cancelled','rejected'])) {
            $stmt = mysqli_prepare($conn, "UPDATE purchases SET status=? WHERE id=?");
            mysqli_stmt_bind_param($stmt, "si", $status, $id);
            mysqli_stmt_execute($stmt); mysqli_stmt_close($stmt);
            $msg = "Purchase #$id updated"; $msgType = 'success';
        }
    } elseif ($action === 'delete_purchase') {
        $id = intval($_POST['purchase_id']);
        mysqli_query($conn, "DELETE FROM purchases WHERE id=$id");
        $msg = "Purchase deleted"; $msgType = 'warning';
    } elseif ($action === 'update_vehicle_status') {
        $id = intval($_POST['vehicle_id']);
        $status = $_POST['status'];
        if (in_array($status, ['available','reserved'])) {
            $stmt = mysqli_prepare($conn, "UPDATE vehicles SET status=? WHERE id=?");
            mysqli_stmt_bind_param($stmt, "si", $status, $id);
            mysqli_stmt_execute($stmt); mysqli_stmt_close($stmt);
            $msg = "Vehicle #$id status updated to $status"; $msgType = 'success';
        }
    } elseif ($action === 'update_rental_vehicle_status') {
        $id = intval($_POST['rv_id']);
        $is_avail = intval($_POST['is_available']);
        $stmt = mysqli_prepare($conn, "UPDATE rental_vehicles SET is_available=? WHERE id=?");
        mysqli_stmt_bind_param($stmt, "ii", $is_avail, $id);
        mysqli_stmt_execute($stmt); mysqli_stmt_close($stmt);
        $msg = "Rental vehicle #$id availability updated"; $msgType = 'success';
    } elseif ($action === 'delete_vehicle') {
        $id = intval($_POST['vehicle_id']);
        mysqli_query($conn, "DELETE FROM vehicles WHERE id=$id");
        $msg = "Vehicle deleted"; $msgType = 'warning';
    } elseif ($action === 'delete_rental_vehicle') {
        $id = intval($_POST['rv_id']);
        mysqli_query($conn, "DELETE FROM rental_vehicles WHERE id=$id");
        $msg = "Rental vehicle deleted"; $msgType = 'warning';
    } elseif ($action === 'delete_food') {
        $id = intval($_POST['food_id']);
        mysqli_query($conn, "DELETE FROM foods WHERE id=$id");
        $msg = "Food item deleted"; $msgType = 'warning';
    } elseif ($action === 'insert_flight') {
        $stmt = mysqli_prepare($conn, "INSERT INTO flights (flight_number, airline, origin_code, dest_code, origin_city, dest_city, departure_time, arrival_time, duration_mins, price_per_seat, available_seats, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'confirmed')");
        $duration = (strtotime($_POST['arrival_time']) - strtotime($_POST['departure_time'])) / 60;
        mysqli_stmt_bind_param($stmt, "ssssssssiid", $_POST['flight_number'], $_POST['airline'], $_POST['origin_code'], $_POST['dest_code'], $_POST['origin_city'], $_POST['dest_city'], $_POST['departure_time'], $_POST['arrival_time'], $duration, $_POST['price_per_seat'], $_POST['available_seats']);
        if (mysqli_stmt_execute($stmt)) $msg = "Flight added"; else $msg = "Error: " . mysqli_error($conn);
        mysqli_stmt_close($stmt);
    } elseif ($action === 'insert_trip') {
        $stmt = mysqli_prepare($conn, "INSERT INTO trips (transport_type, from_city, to_city, departure_date, departure_time, available_seats, price_per_seat, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'confirmed')");
        mysqli_stmt_bind_param($stmt, "sssssid", $_POST['transport_type'], $_POST['from_city'], $_POST['to_city'], $_POST['departure_date'], $_POST['departure_time'], $_POST['available_seats'], $_POST['price_per_seat']);
        if (mysqli_stmt_execute($stmt)) $msg = "Trip added"; else $msg = "Error: " . mysqli_error($conn);
        mysqli_stmt_close($stmt);
    } elseif ($action === 'insert_vehicle') {
        $img = uploadImage('vehicle_image');
        if ($img === false) { $msg = "Invalid image file (jpg,png,gif,webp)"; $msgType = 'warning'; }
        else {
            $stmt = mysqli_prepare($conn, "INSERT INTO vehicles (make, model, year, category, `condition`, price, image_url, fuel_type, transmission, mileage, color, seats, engine, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'available')");
            mysqli_stmt_bind_param($stmt, "ssissdsssissi", $_POST['make'], $_POST['model'], $_POST['year'], $_POST['category'], $_POST['condition'], $_POST['price'], $img, $_POST['fuel_type'], $_POST['transmission'], $_POST['mileage'], $_POST['color'], $_POST['seats'], $_POST['engine']);
            if (mysqli_stmt_execute($stmt)) $msg = "Vehicle added"; else $msg = "Error: " . mysqli_error($conn);
            mysqli_stmt_close($stmt);
        }
    } elseif ($action === 'insert_rental_vehicle') {
        $img = uploadImage('rv_image');
        if ($img === false) { $msg = "Invalid image file"; $msgType = 'warning'; }
        else {
            $is_avail = isset($_POST['is_available']) ? 1 : 0;
            $stmt = mysqli_prepare($conn, "INSERT INTO rental_vehicles (make, model, year, category, plate, price_per_day, image_url, description, is_available) VALUES (?,?,?,?,?,?,?,?,?)");
            mysqli_stmt_bind_param($stmt, "ssisssdsi", $_POST['make'], $_POST['model'], $_POST['year'], $_POST['category'], $_POST['plate'], $_POST['price_per_day'], $img, $_POST['description'], $is_avail);
            if (mysqli_stmt_execute($stmt)) $msg = "Rental vehicle added"; else $msg = "Error: " . mysqli_error($conn);
            mysqli_stmt_close($stmt);
        }
    } elseif ($action === 'insert_food') {
        $img = uploadImage('food_image');
        if ($img === false) $img = null;
        $stmt = mysqli_prepare($conn, "INSERT INTO foods (name, description, category, price, image_url, is_available) VALUES (?,?,?,?,?,1)");
        mysqli_stmt_bind_param($stmt, "sssds", $_POST['name'], $_POST['description'], $_POST['category'], $_POST['price'], $img);
        if (mysqli_stmt_execute($stmt)) $msg = "Food item added"; else $msg = "Error: " . mysqli_error($conn);
        mysqli_stmt_close($stmt);
    } elseif ($action === 'insert_rental') {
        $user_id = intval($_POST['user_id']); $vehicle_id = intval($_POST['vehicle_id']);
        $pickup = $_POST['pickup_date']; $return = $_POST['return_date'];
        $pickup_loc = $_POST['pickup_location']; $driver_opt = isset($_POST['driver_option']) ? 1 : 0;
        $notes = $_POST['notes'];
        $vq = mysqli_query($conn, "SELECT make, model, price_per_day FROM rental_vehicles WHERE id = $vehicle_id");
        $v = mysqli_fetch_assoc($vq);
        if ($v) {
            $car_model = $v['make'] . ' ' . $v['model']; $daily_rate = $v['price_per_day'];
            $days = max(1, (strtotime($return) - strtotime($pickup)) / 86400);
            $total_price = ($daily_rate + ($driver_opt ? 15000 : 0)) * $days;
            $stmt = mysqli_prepare($conn, "INSERT INTO rentals (user_id, vehicle_id, car_model, daily_rate, pickup_date, return_date, pickup_location, total_days, total_price, driver_option, notes, status, payment_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'unpaid')");
            mysqli_stmt_bind_param($stmt, "iisssssidds", $user_id, $vehicle_id, $car_model, $daily_rate, $pickup, $return, $pickup_loc, $days, $total_price, $driver_opt, $notes);
            if (mysqli_stmt_execute($stmt)) $msg = "Rental order created"; else $msg = "Error: " . mysqli_error($conn);
            mysqli_stmt_close($stmt);
        } else { $msg = "Invalid rental vehicle ID"; $msgType = 'warning'; }
    }
    // Delete user
    elseif ($action === 'delete_user') {
      $id = intval($_POST['user_id']);
      mysqli_query($conn, "DELETE FROM users WHERE id=$id");
      $msg = "User deleted"; $msgType = 'warning';
    }
    // Delete driver
    elseif ($action === 'delete_driver') {
      $id = intval($_POST['driver_id']);
      mysqli_query($conn, "DELETE FROM drivers WHERE driver_id=$id");
      $msg = "Driver deleted"; $msgType = 'warning';
    }
}

function fetchAll($conn, $sql) {
    $result = mysqli_query($conn, $sql);
    return mysqli_fetch_all($result, MYSQLI_ASSOC);
}

$bookings = fetchAll($conn, "SELECT b.*, u.name AS user_name, u.surname AS user_surname, CONCAT(d.name,' ',d.surname) AS driver_fullname FROM bookings b LEFT JOIN users u ON b.user_id = u.id LEFT JOIN drivers d ON b.driver_id = d.driver_id ORDER BY b.id DESC LIMIT 200");
$trips = fetchAll($conn, "SELECT * FROM trips ORDER BY id DESC");
$trip_bookings = fetchAll($conn, "SELECT tb.*, u.name AS user_name, u.surname AS user_surname FROM trip_bookings tb LEFT JOIN users u ON tb.user_id = u.id ORDER BY tb.id DESC");
$flights = fetchAll($conn, "SELECT * FROM flights ORDER BY id DESC");
$flight_bookings = fetchAll($conn, "SELECT fb.*, u.name AS user_name, u.surname AS user_surname, f.flight_number FROM flight_bookings fb LEFT JOIN users u ON fb.user_id = u.id LEFT JOIN flights f ON fb.flight_id = f.id ORDER BY fb.id DESC");
$orders = fetchAll($conn, "SELECT o.*, u.name AS user_name, u.surname AS user_surname, f.name AS food_name FROM orders o LEFT JOIN users u ON o.user_id = u.id LEFT JOIN foods f ON o.food_id = f.id ORDER BY o.id DESC");
$foods = fetchAll($conn, "SELECT * FROM foods ORDER BY id DESC");
$rentals = fetchAll($conn, "SELECT r.*, u.name AS user_name, u.surname AS user_surname FROM rentals r LEFT JOIN users u ON r.user_id = u.id ORDER BY r.id DESC");
$rental_vehicles = fetchAll($conn, "SELECT * FROM rental_vehicles ORDER BY id DESC");
$vehicles = fetchAll($conn, "SELECT * FROM vehicles ORDER BY id DESC");
$purchases = fetchAll($conn, "SELECT p.*, u.name AS user_name, u.surname AS user_surname, v.make, v.model, v.image_url AS vehicle_image FROM purchases p LEFT JOIN users u ON p.user_id = u.id LEFT JOIN vehicles v ON p.vehicle_id = v.id ORDER BY p.id DESC");
$users = fetchAll($conn, "SELECT * FROM users ORDER BY id DESC");
$drivers = fetchAll($conn, "SELECT * FROM drivers ORDER BY driver_id DESC");

// Revenue data for chart (simulated monthly from DB aggregates)
$monthlyRevenue = [];
for ($m = 1; $m <= 8; $m++) {
    $monthlyRevenue[] = rand(18, 52); // placeholder – replace with real query if needed
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>TransNetX Ultimate — Super Admin</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.js">
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js">
</script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
@import url('https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Barlow+Condensed:wght@300;400;500;600;700&family=Barlow:wght@300;400;500;600&display=swap');
*{margin:0;padding:0;box-sizing:border-box}
:root{
  --gold:#f5c518;--gold2:#e8a800;--gold3:#ffd85e;
  --bg:#080810;--bg2:#0d0d1a;--bg3:#111122;--bg4:#16162a;
  --glass:rgba(255,255,255,0.04);--glass2:rgba(255,255,255,0.07);
  --glass-border:rgba(245,197,24,0.15);--glass-border2:rgba(255,255,255,0.08);
  --text:#f0f0ff;--text2:#a0a0c0;--text3:#606080;
  --red:#ff4466;--green:#00e5a0;--blue:#4488ff;--purple:#9b59b6;--orange:#ff8800;
  --sidebar-w:260px;--trans:cubic-bezier(0.4,0,0.2,1);
}
html,body{height:100%;overflow:hidden;background:var(--bg);color:var(--text);font-family:'Barlow',sans-serif}
#sidebar{
  position:fixed;top:0;left:0;width:var(--sidebar-w);height:100vh;
  background:linear-gradient(180deg,#090915 0%,#0a0a18 50%,#080810 100%);
  border-right:1px solid var(--glass-border);
  display:flex;flex-direction:column;z-index:1000;
  transition:transform 0.3s var(--trans);overflow:hidden;
}
#sidebar::before{
  content:'';position:absolute;top:0;left:0;right:0;bottom:0;
  background:radial-gradient(ellipse at 50% 0%,rgba(245,197,24,0.06) 0%,transparent 60%);
  pointer-events:none;
}
.sidebar-logo{padding:24px 20px 20px;border-bottom:1px solid var(--glass-border2);display:flex;align-items:center;gap:12px}
.logo-mark{width:40px;height:40px;background:linear-gradient(135deg,var(--gold),var(--gold2));border-radius:10px;display:flex;align-items:center;justify-content:center;font-family:'Bebas Neue';font-size:22px;color:#000;letter-spacing:1px;flex-shrink:0;box-shadow:0 0 20px rgba(245,197,24,0.3)}
.logo-text{font-family:'Bebas Neue';font-size:20px;letter-spacing:2px;color:var(--text)}
.logo-text span{color:var(--gold)}
.logo-sub{font-size:9px;color:var(--text3);letter-spacing:3px;text-transform:uppercase;margin-top:-2px}
.sidebar-nav{flex:1;overflow-y:auto;overflow-x:hidden;padding:12px 0;scrollbar-width:none}
.sidebar-nav::-webkit-scrollbar{display:none}
.nav-section-label{font-size:9px;letter-spacing:3px;color:var(--text3);text-transform:uppercase;padding:16px 20px 6px}
.nav-item{display:flex;align-items:center;gap:12px;padding:10px 20px;cursor:pointer;border-left:2px solid transparent;transition:all 0.2s;position:relative;margin:1px 0;background:none;border-top:none;border-right:none;border-bottom:none;width:100%;text-align:left;font-family:'Barlow',sans-serif;font-size:13px;color:var(--text2)}
.nav-item:hover{background:var(--glass);border-left-color:rgba(245,197,24,0.4);color:var(--gold)}
.nav-item.active{background:linear-gradient(90deg,rgba(245,197,24,0.12),transparent);border-left-color:var(--gold);color:var(--gold)}
.nav-item.active::after{content:'';position:absolute;right:0;top:0;bottom:0;width:1px;background:linear-gradient(180deg,transparent,var(--gold),transparent)}
.nav-icon{font-size:15px;width:22px;text-align:center;flex-shrink:0}
.nav-label{font-size:13px;font-weight:500;letter-spacing:0.3px;white-space:nowrap}
.nav-badge{margin-left:auto;background:var(--red);color:#fff;font-size:10px;padding:2px 7px;border-radius:10px;font-weight:600}
.nav-badge.gold{background:linear-gradient(90deg,var(--gold2),var(--gold));color:#000}
.sidebar-footer{padding:16px 20px;border-top:1px solid var(--glass-border2);display:flex;align-items:center;gap:10px}
.admin-avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--gold),var(--gold2));display:flex;align-items:center;justify-content:center;font-family:'Bebas Neue';font-size:16px;color:#000;flex-shrink:0}
.admin-info{flex:1;min-width:0}
.admin-name{font-size:13px;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.admin-role{font-size:10px;color:var(--gold);letter-spacing:1px}
#main{margin-left:var(--sidebar-w);height:100vh;overflow-y:auto;background:var(--bg2);scrollbar-width:thin;scrollbar-color:var(--glass-border) transparent}
#main::-webkit-scrollbar{width:4px}
#main::-webkit-scrollbar-thumb{background:var(--glass-border);border-radius:4px}
#topbar{position:sticky;top:0;z-index:100;height:64px;background:linear-gradient(90deg,rgba(13,13,26,0.97),rgba(13,13,26,0.99));backdrop-filter:blur(20px);border-bottom:1px solid var(--glass-border2);display:flex;align-items:center;padding:0 28px;gap:16px}
.page-title{font-family:'Bebas Neue';font-size:22px;letter-spacing:2px;color:var(--text)}
.page-title span{color:var(--gold)}
.topbar-spacer{flex:1}
.status-pill{display:flex;align-items:center;gap:6px;padding:6px 14px;background:rgba(0,229,160,0.08);border:1px solid rgba(0,229,160,0.2);border-radius:20px;font-size:11px;color:var(--green);font-weight:600;letter-spacing:0.5px}
.status-dot{width:6px;height:6px;background:var(--green);border-radius:50%;animation:pulse-green 2s infinite}
@keyframes pulse-green{0%,100%{box-shadow:0 0 0 0 rgba(0,229,160,0.4)}50%{box-shadow:0 0 0 5px rgba(0,229,160,0)}}
.page{display:none;padding:28px;min-height:calc(100vh - 64px);animation:pageIn 0.3s ease}
.page.active{display:block}
@keyframes pageIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
.metrics-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:16px;margin-bottom:28px}
.metric-card{background:linear-gradient(135deg,var(--glass2),var(--glass));border:1px solid var(--glass-border2);border-radius:14px;padding:20px;position:relative;overflow:hidden;cursor:default;transition:transform 0.2s,border-color 0.2s}
.metric-card:hover{transform:translateY(-2px);border-color:var(--glass-border)}
.metric-card::before{content:'';position:absolute;top:0;right:0;width:80px;height:80px;border-radius:0 0 0 80px;opacity:0.06}
.metric-card.gold::before{background:var(--gold)}
.metric-card.green::before{background:var(--green)}
.metric-card.blue::before{background:var(--blue)}
.metric-card.red::before{background:var(--red)}
.metric-card.purple::before{background:var(--purple)}
.metric-card.orange::before{background:var(--orange)}
.metric-top{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:16px}
.metric-icon{width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
.metric-icon.gold{background:rgba(245,197,24,0.12);color:var(--gold)}
.metric-icon.green{background:rgba(0,229,160,0.12);color:var(--green)}
.metric-icon.blue{background:rgba(68,136,255,0.12);color:var(--blue)}
.metric-icon.red{background:rgba(255,68,102,0.12);color:var(--red)}
.metric-icon.purple{background:rgba(155,89,182,0.12);color:var(--purple)}
.metric-icon.orange{background:rgba(255,136,0,0.12);color:var(--orange)}
.metric-change{font-size:11px;font-weight:600;padding:3px 8px;border-radius:6px}
.metric-change.up{background:rgba(0,229,160,0.12);color:var(--green)}
.metric-change.down{background:rgba(255,68,102,0.1);color:var(--red)}
.metric-value{font-family:'Bebas Neue';font-size:34px;letter-spacing:1px;line-height:1}
.metric-label{font-size:12px;color:var(--text3);margin-top:4px;letter-spacing:0.5px}
.chart-grid{display:grid;gap:20px;margin-bottom:28px}
.chart-grid.cols-2{grid-template-columns:1fr 1fr}
.chart-grid.cols-3{grid-template-columns:2fr 1fr}
.chart-card{background:linear-gradient(135deg,var(--glass),rgba(255,255,255,0.02));border:1px solid var(--glass-border2);border-radius:16px;padding:22px;overflow:hidden;margin-bottom:16px}
.chart-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px}
.chart-title{font-family:'Barlow Condensed';font-size:14px;font-weight:600;letter-spacing:1.5px;color:var(--text);text-transform:uppercase}
.chart-subtitle{font-size:11px;color:var(--text3);margin-top:3px}
.chart-actions{display:flex;gap:6px}
.filter-btn{padding:4px 10px;border-radius:6px;font-size:11px;cursor:pointer;border:1px solid var(--glass-border2);background:transparent;color:var(--text2);transition:all 0.15s;font-family:'Barlow',sans-serif}
.filter-btn.active,.filter-btn:hover{border-color:var(--gold);color:var(--gold);background:rgba(245,197,24,0.08)}
.table-card{background:linear-gradient(135deg,var(--glass),rgba(255,255,255,0.02));border:1px solid var(--glass-border2);border-radius:16px;overflow:hidden;margin-bottom:20px}
.table-header{padding:18px 22px;border-bottom:1px solid var(--glass-border2);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px}
.table-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.btn{padding:7px 16px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;border:none;transition:all 0.2s;font-family:'Barlow',sans-serif;letter-spacing:0.5px}
.btn-gold{background:linear-gradient(90deg,var(--gold2),var(--gold));color:#000}
.btn-gold:hover{box-shadow:0 4px 15px rgba(245,197,24,0.3);transform:translateY(-1px)}
.btn-outline{background:transparent;border:1px solid var(--glass-border2);color:var(--text2)}
.btn-outline:hover{border-color:var(--gold);color:var(--gold)}
.btn-danger{background:rgba(255,68,102,0.12);border:1px solid rgba(255,68,102,0.25);color:var(--red)}
.btn-success{background:rgba(0,229,160,0.1);border:1px solid rgba(0,229,160,0.22);color:var(--green)}
.data-table{width:100%;border-collapse:collapse;font-size:13px}
.data-table th{padding:10px 22px;text-align:left;font-size:10px;letter-spacing:1.5px;color:var(--text3);font-weight:600;text-transform:uppercase;border-bottom:1px solid var(--glass-border2);background:rgba(255,255,255,0.02)}
.data-table td{padding:13px 22px;border-bottom:1px solid rgba(255,255,255,0.04);vertical-align:middle}
.data-table tr:last-child td{border-bottom:none}
.data-table tbody tr:hover td{background:rgba(255,255,255,0.025)}
.badge{padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;display:inline-flex;align-items:center;gap:4px;white-space:nowrap}
.badge-green{background:rgba(0,229,160,0.12);color:var(--green)}
.badge-red{background:rgba(255,68,102,0.12);color:var(--red)}
.badge-blue{background:rgba(68,136,255,0.12);color:var(--blue)}
.badge-gold{background:rgba(245,197,24,0.12);color:var(--gold)}
.badge-purple{background:rgba(155,89,182,0.12);color:var(--purple)}
.badge-orange{background:rgba(255,136,0,0.12);color:var(--orange)}
.badge-gray{background:rgba(255,255,255,0.06);color:var(--text2)}
.avatar{width:34px;height:34px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-family:'Bebas Neue';font-size:14px;font-weight:700;flex-shrink:0}
.user-cell{display:flex;align-items:center;gap:10px}
.user-name{font-weight:500;color:var(--text)}
.user-email{font-size:11px;color:var(--text3)}
.activity-feed{max-height:360px;overflow-y:auto;scrollbar-width:none}
.activity-feed::-webkit-scrollbar{display:none}
.feed-item{display:flex;align-items:flex-start;gap:14px;padding:12px 22px;border-bottom:1px solid rgba(255,255,255,0.04);transition:background 0.15s}
.feed-item:hover{background:rgba(255,255,255,0.02)}
.feed-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;margin-top:4px}
.feed-text{font-size:13px;color:var(--text2);line-height:1.5}
.feed-time{font-size:11px;color:var(--text3);margin-top:2px}
.stat-bar-wrap{display:flex;flex-direction:column;gap:12px;padding:0 22px 22px}
.stat-bar-item{display:flex;flex-direction:column;gap:5px}
.stat-bar-label{display:flex;justify-content:space-between;align-items:center}
.stat-bar-name{font-size:12px;color:var(--text2)}
.stat-bar-val{font-size:12px;font-weight:600;color:var(--text)}
.stat-bar-track{height:4px;background:rgba(255,255,255,0.06);border-radius:3px;overflow:hidden}
.stat-bar-fill{height:100%;border-radius:3px;transition:width 1.2s var(--trans)}
.quick-actions{display:grid;grid-template-columns:repeat(8,1fr);gap:12px;margin-bottom:28px}
.qa-btn{background:var(--glass);border:1px solid var(--glass-border2);border-radius:12px;padding:14px 8px;display:flex;flex-direction:column;align-items:center;gap:6px;cursor:pointer;transition:all 0.2s}
.qa-btn:hover{border-color:var(--glass-border);background:var(--glass2);transform:translateY(-2px)}
.qa-icon{font-size:22px}
.qa-label{font-size:10px;color:var(--text2);text-align:center;letter-spacing:0.5px}
.donut-wrap{max-width:180px;margin:0 auto 16px}
.top-section{display:grid;grid-template-columns:1fr 340px;gap:20px;margin-bottom:28px}
.section-title{font-family:'Bebas Neue';font-size:14px;letter-spacing:2px;color:var(--text2);text-transform:uppercase;margin-bottom:16px;display:flex;align-items:center;gap:10px}
.section-title::after{content:'';flex:1;height:1px;background:var(--glass-border2)}
.status-select{background:rgba(255,255,255,0.05);border:1px solid var(--glass-border2);border-radius:6px;padding:4px 8px;color:var(--text);font-family:'Barlow',sans-serif;font-size:12px}
.inp{background:var(--glass);border:1px solid var(--glass-border2);border-radius:8px;padding:8px 14px;font-size:13px;color:var(--text);outline:none;width:100%;font-family:'Barlow',sans-serif;transition:border-color 0.2s}
.inp:focus{border-color:rgba(245,197,24,0.4)}
.inp::placeholder{color:var(--text3)}
.inp-label{font-size:10px;color:var(--text3);margin-bottom:5px;letter-spacing:1px;text-transform:uppercase}
.sidebar-toggle{display:none;position:fixed;top:14px;left:16px;z-index:2000;width:36px;height:36px;border-radius:8px;background:var(--glass2);border:1px solid var(--glass-border);align-items:center;justify-content:center;cursor:pointer;color:var(--text);font-size:20px}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.85);backdrop-filter:blur(8px);z-index:500;justify-content:center;align-items:center}
.modal-overlay.open{display:flex}
.modal{background:var(--bg3);border:1px solid var(--glass-border);border-radius:20px;width:90%;max-width:560px;max-height:85vh;overflow-y:auto;padding:28px;position:relative}
.close-modal{position:absolute;top:16px;right:20px;background:transparent;border:none;color:var(--text3);font-size:20px;cursor:pointer}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.form-group{display:flex;flex-direction:column;gap:4px}
.form-group.full{grid-column:1/-1}
.form-group input,.form-group select,.form-group textarea{background:rgba(255,255,255,0.05);border:1px solid var(--glass-border2);border-radius:8px;padding:8px 12px;color:var(--text);font-family:'Barlow',sans-serif}
.toast{position:fixed;bottom:20px;right:20px;background:var(--bg2);border-left:4px solid var(--gold);padding:10px 20px;border-radius:8px;z-index:1000;transition:0.3s;opacity:0;color:var(--text);font-size:13px}
.toast.show{opacity:1}
.img-thumb{width:50px;height:40px;object-fit:cover;border-radius:6px}
@media(max-width:1100px){
  :root{--sidebar-w:0px}
  #sidebar{transform:translateX(-260px)}
  #sidebar.open{transform:translateX(0)}
  #main{margin-left:0}
  .sidebar-toggle{display:flex}
  .chart-grid.cols-2,.chart-grid.cols-3,.top-section{grid-template-columns:1fr}
  .quick-actions{grid-template-columns:repeat(4,1fr)}
  .metrics-grid{grid-template-columns:repeat(2,1fr)}
}
</style>
</head>
<body>

<button class="sidebar-toggle" onclick="toggleSidebar()"><i data-lucide="menu" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></button>

<div id="sidebar">
  <div class="sidebar-logo">
    <div class="logo-mark">TX</div>
    <div>
      <div class="logo-text">Trans<span>Net</span>X</div>
      <div class="logo-sub">Ultimate Admin</div>
    </div>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-section-label">Main</div>
    <div class="nav-item active" onclick="showPage('overview',this)"><span class="nav-icon">⬡</span><span class="nav-label">Overview</span></div>
    <div class="nav-item" onclick="showPage('analytics',this)"><span class="nav-icon"><i data-lucide="bar-chart-2" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></span><span class="nav-label">Analytics</span></div>
    <div class="nav-item" onclick="showPage('financials',this)"><span class="nav-icon"><i data-lucide="circle-dollar-sign" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></span><span class="nav-label">Financial Reports</span></div>
    <div class="nav-section-label">Services</div>
    <div class="nav-item" onclick="showPage('rides',this)"><span class="nav-icon"><i data-lucide="car" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></span><span class="nav-label">Ride Hailing</span></div>
    <div class="nav-item" onclick="showPage('flights',this)"><span class="nav-icon"><i data-lucide="plane" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i>️</span><span class="nav-label">Flights</span></div>
    <div class="nav-item" onclick="showPage('trips',this)"><span class="nav-icon"><i data-lucide="bus" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></span><span class="nav-label">Intercity Trips</span></div>
    <div class="nav-item" onclick="showPage('rentals',this)"><span class="nav-icon"><i data-lucide="key" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></span><span class="nav-label">Car Rentals</span></div>
    <div class="nav-item" onclick="showPage('orders',this)"><span class="nav-icon"><i data-lucide="utensils" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></span><span class="nav-label">Food Orders</span></div>
    <div class="nav-item" onclick="showPage('marketplace',this)"><span class="nav-icon"><i data-lucide="car-front" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></span><span class="nav-label">Marketplace</span></div>
    <div class="nav-section-label">People</div>
    <div class="nav-item" onclick="showPage('users',this)"><span class="nav-icon"><i data-lucide="users" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></span><span class="nav-label">Users</span></div>
    <div class="nav-item" onclick="showPage('drivers',this)"><span class="nav-icon"><i data-lucide="id-card" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></span><span class="nav-label">Drivers</span></div>
    <div class="nav-section-label">System</div>
    <div class="nav-item" onclick="showPage('live',this)"><span class="nav-icon"><i data-lucide="radio" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></span><span class="nav-label">Live Activity</span></div>
    <div class="nav-item" onclick="showPage('notifications',this)"><span class="nav-icon"><i data-lucide="bell" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></span><span class="nav-label">Notifications</span></div>
    <div class="nav-item" onclick="showPage('security',this)"><span class="nav-icon"><i data-lucide="shield" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i>️</span><span class="nav-label">Security Center</span></div>
    <div class="nav-item" onclick="showPage('api',this)"><span class="nav-icon"><i data-lucide="zap" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></span><span class="nav-label">API Monitor</span></div>
    <div class="nav-item" onclick="showPage('logs',this)"><span class="nav-icon"><i data-lucide="clipboard-list" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></span><span class="nav-label">Audit Logs</span></div>
    <div class="nav-item" onclick="showPage('settings',this)"><span class="nav-icon"><i data-lucide="settings" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i>️</span><span class="nav-label">Settings</span></div>
    <div class="nav-item" onclick="window.location.href='../auth/logout.php'" style="color:var(--red)"><span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span><span class="nav-label">Logout</span></div>
  </nav>
  <div class="sidebar-footer">
    <div class="admin-avatar">SA</div>
    <div class="admin-info">
      <div class="admin-name">Super Admin</div>
      <div class="admin-role">SUPER ADMINISTRATOR</div>
    </div>
  </div>
</div>

<div id="main">
  <div id="topbar">
    <div class="page-title" id="pageTitle">Overview <span>Dashboard</span></div>
    <div class="topbar-spacer"></div>
    <div class="status-pill"><div class="status-dot"></div>LIVE</div>
  </div>

  <?php if($msg): ?>
  <div class="toast show" id="toastMsg" style="opacity:1"><?= htmlspecialchars($msg) ?></div>
  <script>setTimeout(()=>{let t=document.getElementById('toastMsg');if(t)t.style.opacity='0';},3000);</script>
  <?php endif; ?>

  <!-- ═══════════ OVERVIEW ═══════════ -->
  <div id="page-overview" class="page active">
    <div class="metrics-grid">
      <div class="metric-card gold"><div class="metric-top"><div class="metric-icon gold"><i data-lucide="circle-dollar-sign" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></div><span class="metric-change up">↑ Active</span></div><div class="metric-value">₦<?= number_format($totalRevenue, 0) ?></div><div class="metric-label">Total Revenue (All Services)</div></div>
      <div class="metric-card green"><div class="metric-top"><div class="metric-icon green"><i data-lucide="users" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></div><span class="metric-change up">↑ Active</span></div><div class="metric-value"><?= number_format($stats['users']) ?></div><div class="metric-label">Total Users</div></div>
      <div class="metric-card blue"><div class="metric-top"><div class="metric-icon blue"><i data-lucide="car" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></div><span class="metric-change up">↑ Active</span></div><div class="metric-value"><?= number_format($stats['bookings']) ?></div><div class="metric-label">Ride Bookings</div></div>
      <div class="metric-card red"><div class="metric-top"><div class="metric-icon red"><i data-lucide="utensils" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></div><span class="metric-change up">↑ Active</span></div><div class="metric-value"><?= number_format($stats['orders']) ?></div><div class="metric-label">Food Orders</div></div>
      <div class="metric-card purple"><div class="metric-top"><div class="metric-icon purple"><i data-lucide="plane" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i>️</div><span class="metric-change up">↑ Active</span></div><div class="metric-value"><?= number_format($stats['flight_bookings']) ?></div><div class="metric-label">Flight Bookings</div></div>
      <div class="metric-card orange"><div class="metric-top"><div class="metric-icon orange"><i data-lucide="id-card" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></div><span class="metric-change up">↑ Active</span></div><div class="metric-value"><?= number_format($stats['drivers']) ?></div><div class="metric-label">Registered Drivers</div></div>
    </div>

    <div class="section-title">Quick Actions</div>
    <div class="quick-actions">
      <div class="qa-btn" onclick="openModal('modal-flight')"><div class="qa-icon"><i data-lucide="plane" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i>️</div><div class="qa-label">Add Flight</div></div>
      <div class="qa-btn" onclick="openModal('modal-trip')"><div class="qa-icon"><i data-lucide="bus" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></div><div class="qa-label">Add Trip</div></div>
      <div class="qa-btn" onclick="openModal('modal-vehicle')"><div class="qa-icon"><i data-lucide="car" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></div><div class="qa-label">Add Vehicle</div></div>
      <div class="qa-btn" onclick="openModal('modal-rental-vehicle')"><div class="qa-icon"><i data-lucide="car-front" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></div><div class="qa-label">Add Rental Car</div></div>
      <div class="qa-btn" onclick="openModal('modal-rental')"><div class="qa-icon"><i data-lucide="key" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></div><div class="qa-label">New Rental</div></div>
      <div class="qa-btn" onclick="openModal('modal-food')"><div class="qa-icon"><i data-lucide="utensils" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></div><div class="qa-label">Add Food</div></div>
      <div class="qa-btn" onclick="showPage('rides',null)"><div class="qa-icon"><i data-lucide="bar-chart-2" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></div><div class="qa-label">View Rides</div></div>
      <div class="qa-btn" onclick="showPage('settings',null)"><div class="qa-icon"><i data-lucide="wrench" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></div><div class="qa-label">Settings</div></div>
    </div>

    <div class="chart-grid cols-2">
      <div class="chart-card">
        <div class="chart-header">
          <div><div class="chart-title">Revenue Growth</div><div class="chart-subtitle">Monthly across all services</div></div>
          <div class="chart-actions">
            <button class="filter-btn active">W</button>
            <button class="filter-btn">M</button>
            <button class="filter-btn">Y</button>
          </div>
        </div>
        <div style="position:relative;height:230px"><canvas id="revenueChart" role="img" aria-label="Revenue growth line chart"></canvas></div>
      </div>
      <div class="chart-card">
        <div class="chart-header"><div><div class="chart-title">Service Breakdown</div><div class="chart-subtitle">Count by service</div></div></div>
        <div class="donut-wrap"><div style="position:relative;height:170px"><canvas id="serviceChart" role="img" aria-label="Service breakdown donut"></canvas></div></div>
        <div style="display:flex;flex-wrap:wrap;gap:10px;justify-content:center;font-size:11px;color:var(--text2)">
          <span style="display:flex;align-items:center;gap:4px"><span style="width:9px;height:9px;border-radius:2px;background:#f5c518;display:inline-block"></span>Rides <?= $stats['bookings'] ?></span>
          <span style="display:flex;align-items:center;gap:4px"><span style="width:9px;height:9px;border-radius:2px;background:#4488ff;display:inline-block"></span>Flights <?= $stats['flight_bookings'] ?></span>
          <span style="display:flex;align-items:center;gap:4px"><span style="width:9px;height:9px;border-radius:2px;background:#00e5a0;display:inline-block"></span>Orders <?= $stats['orders'] ?></span>
          <span style="display:flex;align-items:center;gap:4px"><span style="width:9px;height:9px;border-radius:2px;background:#9b59b6;display:inline-block"></span>Rentals <?= $stats['rentals'] ?></span>
          <span style="display:flex;align-items:center;gap:4px"><span style="width:9px;height:9px;border-radius:2px;background:#ff8800;display:inline-block"></span>Trips <?= $stats['trip_bookings'] ?></span>
        </div>
      </div>
    </div>

    <div class="top-section">
      <div class="table-card">
        <div class="table-header">
          <div class="chart-title">Recent Ride Bookings</div>
          <div class="table-actions"><button class="btn btn-outline" onclick="showPage('rides',null)">View All</button></div>
        </div>
        <table class="data-table">
          <thead><tr><th>ID</th><th>User</th><th>Driver</th><th>Fare</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach(array_slice($bookings,0,5) as $b):?>
            <tr><td style="font-family:monospace;color:var(--gold)">#<?= $b['id'] ?></td><td><?= htmlspecialchars(($b['user_name']??'').' '.($b['user_surname']??'')) ?></td><td><?= htmlspecialchars($b['driver_fullname']??'—') ?></td><td style="font-weight:600;color:var(--gold)">₦<?= number_format($b['fare']??0,2) ?></td><td><?= statusBadge($b['status']) ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div>
        <div class="chart-card" style="margin-bottom:16px">
          <div class="chart-header"><div><div class="chart-title">Live Feed</div><div class="chart-subtitle" id="feed-time">Updated just now</div></div><div class="status-pill"><div class="status-dot"></div>LIVE</div></div>
          <div class="activity-feed" id="live-feed">
            <div class="feed-item"><div class="feed-dot" style="background:var(--green)"></div><div><div class="feed-text">System online — all services operational</div><div class="feed-time">Just now</div></div></div>
            <div class="feed-item"><div class="feed-dot" style="background:var(--blue)"></div><div><div class="feed-text">Admin logged in — Super Admin</div><div class="feed-time">Just now</div></div></div>
          </div>
        </div>
        <div class="chart-card">
          <div class="chart-header"><div class="chart-title">Service Health</div></div>
          <div class="stat-bar-wrap">
            <div class="stat-bar-item"><div class="stat-bar-label"><span class="stat-bar-name">Ride Hailing</span><span class="stat-bar-val">98.4%</span></div><div class="stat-bar-track"><div class="stat-bar-fill" style="width:98%;background:var(--green)"></div></div></div>
            <div class="stat-bar-item"><div class="stat-bar-label"><span class="stat-bar-name">Flight Booking</span><span class="stat-bar-val">99.1%</span></div><div class="stat-bar-track"><div class="stat-bar-fill" style="width:99%;background:var(--green)"></div></div></div>
            <div class="stat-bar-item"><div class="stat-bar-label"><span class="stat-bar-name">Food Orders</span><span class="stat-bar-val">96.7%</span></div><div class="stat-bar-track"><div class="stat-bar-fill" style="width:97%;background:var(--green)"></div></div></div>
            <div class="stat-bar-item"><div class="stat-bar-label"><span class="stat-bar-name">Database</span><span class="stat-bar-val">99.9%</span></div><div class="stat-bar-track"><div class="stat-bar-fill" style="width:99%;background:var(--green)"></div></div></div>
          </div>
        </div>
      </div>
    </div>

    <div class="chart-card">
      <div class="chart-header"><div><div class="chart-title">User & Driver Growth</div><div class="chart-subtitle">Registration trends — past 8 months</div></div><div class="chart-actions"><button class="filter-btn active">Monthly</button></div></div>
      <div style="position:relative;height:190px"><canvas id="growthChart" role="img" aria-label="User and driver growth bar chart"></canvas></div>
    </div>
  </div>

  <!-- ═══════════ ANALYTICS ═══════════ -->
  <div id="page-analytics" class="page">
    <div class="metrics-grid" style="grid-template-columns:repeat(4,1fr)">
      <div class="metric-card blue"><div class="metric-top"><div class="metric-icon blue"><i data-lucide="car" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></div></div><div class="metric-value"><?= number_format($stats['bookings']) ?></div><div class="metric-label">Total Ride Bookings</div></div>
      <div class="metric-card green"><div class="metric-top"><div class="metric-icon green"><i data-lucide="utensils" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></div></div><div class="metric-value"><?= number_format($stats['orders']) ?></div><div class="metric-label">Total Food Orders</div></div>
      <div class="metric-card purple"><div class="metric-top"><div class="metric-icon purple"><i data-lucide="plane" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i>️</div></div><div class="metric-value"><?= number_format($stats['flight_bookings']) ?></div><div class="metric-label">Flight Bookings</div></div>
      <div class="metric-card orange"><div class="metric-top"><div class="metric-icon orange"><i data-lucide="key" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></div></div><div class="metric-value"><?= number_format($stats['rentals']) ?></div><div class="metric-label">Car Rentals</div></div>
    </div>
    <div class="chart-grid cols-2">
      <div class="chart-card"><div class="chart-header"><div class="chart-title">Ride Booking Trends</div></div><div style="position:relative;height:230px"><canvas id="rideChart" role="img" aria-label="Ride booking trends"></canvas></div></div>
      <div class="chart-card"><div class="chart-header"><div class="chart-title">Order Analytics</div></div><div style="position:relative;height:230px"><canvas id="orderChart" role="img" aria-label="Order analytics"></canvas></div></div>
    </div>
  </div>

  <!-- ═══════════ FINANCIALS ═══════════ -->
  <div id="page-financials" class="page">
    <div class="metrics-grid" style="grid-template-columns:repeat(4,1fr)">
      <div class="metric-card gold"><div class="metric-top"><div class="metric-icon gold"><i data-lucide="circle-dollar-sign" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></div></div><div class="metric-value">₦<?= number_format($totalRevenue, 0) ?></div><div class="metric-label">Gross Revenue</div></div>
      <div class="metric-card green"><div class="metric-top"><div class="metric-icon green"><i data-lucide="trending-up" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></div></div><div class="metric-value">₦<?= number_format($totalRevenue * 0.15, 0) ?></div><div class="metric-label">Est. Commission (15%)</div></div>
      <div class="metric-card blue"><div class="metric-top"><div class="metric-icon blue"><i data-lucide="banknote" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></div></div><div class="metric-value">₦<?= number_format($totalRevenue * 0.85, 0) ?></div><div class="metric-label">Est. Payouts</div></div>
      <div class="metric-card red"><div class="metric-top"><div class="metric-icon red">↩️</div></div><div class="metric-value">₦0</div><div class="metric-label">Total Refunds</div></div>
    </div>
    <div class="chart-card" style="margin-bottom:20px">
      <div class="chart-header"><div class="chart-title">Revenue vs Commission vs Payouts</div></div>
      <div style="position:relative;height:260px"><canvas id="finChart" role="img" aria-label="Revenue commission and payouts line chart"></canvas></div>
    </div>
  </div>

  <!-- ═══════════ RIDES ═══════════ -->
  <div id="page-rides" class="page">
    <div class="metrics-grid">
      <div class="metric-card blue"><div class="metric-top"><div class="metric-icon blue"><i data-lucide="car" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></div></div><div class="metric-value"><?= number_format($stats['bookings']) ?></div><div class="metric-label">Total Bookings</div></div>
      <div class="metric-card gold"><div class="metric-top"><div class="metric-icon gold"><i data-lucide="circle-dollar-sign" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></div></div><div class="metric-value">₦<?= number_format(getSum($conn, 'bookings', 'fare'), 0) ?></div><div class="metric-label">Ride Revenue</div></div>
    </div>
    <div class="table-card">
      <div class="table-header"><div class="chart-title">Ride Bookings Management</div></div>
      <table class="data-table">
        <thead><tr><th>ID</th><th>User</th><th>Driver</th><th>Pickup</th><th>Dropoff</th><th>Fare</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
          <?php foreach($bookings as $b):?>
          <tr>
            <td style="font-family:monospace;color:var(--gold)">#<?= $b['id'] ?></td>
            <td><?= htmlspecialchars(($b['user_name']??'').' '.($b['user_surname']??'')) ?></td>
            <td><?= htmlspecialchars($b['driver_fullname']??'—') ?></td>
            <td><?= htmlspecialchars($b['pickup_location']??'') ?></td>
            <td><?= htmlspecialchars($b['dropoff_location']??'') ?></td>
            <td style="font-weight:600;color:var(--gold)">₦<?= number_format($b['fare']??0,2) ?></td>
            <td><?= statusBadge($b['status']) ?></td>
            <td>
              <form method="POST" style="display:inline-flex;gap:4px;">
                <input type="hidden" name="action" value="update_booking"><input type="hidden" name="booking_id" value="<?= $b['id'] ?>">
                <select name="status" class="status-select"><?php foreach(['pending','accepted','completed','declined'] as $s):?><option <?= ($b['status']==$s)?'selected':'' ?>><?= $s ?></option><?php endforeach; ?></select>
                <button type="submit" class="btn btn-gold" style="padding:4px 8px;font-size:11px"><i data-lucide="check" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></button>
              </form>
              <form method="POST" style="display:inline;"><input type="hidden" name="action" value="delete_booking"><input type="hidden" name="booking_id" value="<?= $b['id'] ?>"><button type="submit" class="btn btn-danger" style="padding:4px 8px;font-size:11px" onclick="return confirm('Delete?')"><i data-lucide="trash-2" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i>️</button></form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ═══════════ FLIGHTS ═══════════ -->
  <div id="page-flights" class="page">
    <div class="metrics-grid" style="grid-template-columns:repeat(4,1fr)">
      <div class="metric-card purple"><div class="metric-top"><div class="metric-icon purple"><i data-lucide="plane" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i>️</div></div><div class="metric-value"><?= $stats['flights'] ?></div><div class="metric-label">Scheduled Flights</div></div>
      <div class="metric-card gold"><div class="metric-top"><div class="metric-icon gold"><i data-lucide="ticket" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></div></div><div class="metric-value"><?= $stats['flight_bookings'] ?></div><div class="metric-label">Bookings</div></div>
    </div>
    <div class="table-card" style="margin-bottom:20px">
      <div class="table-header"><div class="chart-title">Flight Schedule</div><div class="table-actions"><button class="btn btn-gold" onclick="openModal('modal-flight')">+ Add Flight</button></div></div>
      <table class="data-table">
        <thead><tr><th>ID</th><th>Flight#</th><th>Airline</th><th>Route</th><th>Departure</th><th>Price</th><th>Seats</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
          <?php foreach($flights as $f):?>
          <tr>
            <td style="font-family:monospace;color:var(--gold)">#<?= $f['id'] ?></td><td><?= htmlspecialchars($f['flight_number']) ?></td><td><?= htmlspecialchars($f['airline']) ?></td>
            <td><?= htmlspecialchars($f['origin_city']) ?> → <?= htmlspecialchars($f['dest_city']) ?></td>
            <td><?= date('Y-m-d H:i', strtotime($f['departure_time'])) ?></td>
            <td style="font-weight:600;color:var(--gold)">₦<?= number_format($f['price_per_seat'],2) ?></td><td><?= $f['available_seats'] ?></td><td><?= statusBadge($f['status']) ?></td>
            <td>
              <form method="POST" style="display:inline-flex;gap:4px;"><input type="hidden" name="action" value="update_flight"><input type="hidden" name="flight_id" value="<?= $f['id'] ?>"><select name="status" class="status-select"><?php foreach(['confirmed','cancelled','completed'] as $s):?><option <?= ($f['status']==$s)?'selected':'' ?>><?= $s ?></option><?php endforeach; ?></select><button type="submit" class="btn btn-gold" style="padding:4px 8px;font-size:11px"><i data-lucide="check" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></button></form>
              <form method="POST" style="display:inline;"><input type="hidden" name="action" value="delete_flight"><input type="hidden" name="flight_id" value="<?= $f['id'] ?>"><button type="submit" class="btn btn-danger" style="padding:4px 8px;font-size:11px" onclick="return confirm('Delete?')"><i data-lucide="trash-2" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i>️</button></form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="table-card">
      <div class="table-header"><div class="chart-title">Flight Bookings</div></div>
      <table class="data-table">
        <thead><tr><th>ID</th><th>User</th><th>Flight</th><th>Route</th><th>Passenger</th><th>Total</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
          <?php foreach($flight_bookings as $fb):?>
          <tr>
            <td style="font-family:monospace;color:var(--gold)">#<?= $fb['id'] ?></td><td><?= htmlspecialchars(($fb['user_name']??'').' '.($fb['user_surname']??'')) ?></td><td><?= htmlspecialchars($fb['flight_number']??'N/A') ?></td>
            <td><?= htmlspecialchars($fb['from_city']??'') ?> → <?= htmlspecialchars($fb['to_city']??'') ?></td>
            <td><?= htmlspecialchars($fb['passenger_name']??'') ?></td><td style="font-weight:600;color:var(--gold)">₦<?= number_format($fb['total_price']??0,2) ?></td>
            <td><?= statusBadge($fb['status']) ?></td>
            <td>
              <form method="POST" style="display:inline-flex;gap:4px;"><input type="hidden" name="action" value="update_flight_booking"><input type="hidden" name="fb_id" value="<?= $fb['id'] ?>"><select name="status" class="status-select"><?php foreach(['pending','confirmed','cancelled','completed'] as $s):?><option <?= ($fb['status']==$s)?'selected':'' ?>><?= $s ?></option><?php endforeach; ?></select><button type="submit" class="btn btn-gold" style="padding:4px 8px;font-size:11px"><i data-lucide="check" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></button></form>
              <form method="POST" style="display:inline;"><input type="hidden" name="action" value="delete_flight_booking"><input type="hidden" name="fb_id" value="<?= $fb['id'] ?>"><button type="submit" class="btn btn-danger" style="padding:4px 8px;font-size:11px" onclick="return confirm('Delete?')"><i data-lucide="trash-2" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i>️</button></form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ═══════════ TRIPS ═══════════ -->
  <div id="page-trips" class="page">
    <div class="metrics-grid" style="grid-template-columns:repeat(4,1fr)">
      <div class="metric-card blue"><div class="metric-top"><div class="metric-icon blue"><i data-lucide="bus" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></div></div><div class="metric-value"><?= $stats['trips'] ?></div><div class="metric-label">Scheduled Trips</div></div>
      <div class="metric-card gold"><div class="metric-top"><div class="metric-icon gold"><i data-lucide="ticket" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></div></div><div class="metric-value"><?= $stats['trip_bookings'] ?></div><div class="metric-label">Trip Bookings</div></div>
    </div>
    <div class="table-card" style="margin-bottom:20px">
      <div class="table-header"><div class="chart-title">Intercity Trips</div><div class="table-actions"><button class="btn btn-gold" onclick="openModal('modal-trip')">+ Add Trip</button></div></div>
      <table class="data-table">
        <thead><tr><th>ID</th><th>From</th><th>To</th><th>Date</th><th>Time</th><th>Seats</th><th>Price/Seat</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
          <?php foreach($trips as $t):?>
          <tr>
            <td style="font-family:monospace;color:var(--gold)">#<?= $t['id'] ?></td><td><?= htmlspecialchars($t['from_city']) ?></td><td><?= htmlspecialchars($t['to_city']) ?></td>
            <td><?= $t['departure_date'] ?></td><td><?= $t['departure_time'] ?></td><td><?= $t['available_seats'] ?></td>
            <td style="font-weight:600;color:var(--gold)">₦<?= number_format($t['price_per_seat'],2) ?></td><td><?= statusBadge($t['status']) ?></td>
            <td>
              <form method="POST" style="display:inline-flex;gap:4px;"><input type="hidden" name="action" value="update_trip"><input type="hidden" name="trip_id" value="<?= $t['id'] ?>"><select name="status" class="status-select"><?php foreach(['confirmed','cancelled','completed'] as $s):?><option <?= ($t['status']==$s)?'selected':'' ?>><?= $s ?></option><?php endforeach; ?></select><button type="submit" class="btn btn-gold" style="padding:4px 8px;font-size:11px"><i data-lucide="check" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></button></form>
              <form method="POST" style="display:inline;"><input type="hidden" name="action" value="delete_trip"><input type="hidden" name="trip_id" value="<?= $t['id'] ?>"><button type="submit" class="btn btn-danger" style="padding:4px 8px;font-size:11px" onclick="return confirm('Delete?')"><i data-lucide="trash-2" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i>️</button></form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="table-card">
      <div class="table-header"><div class="chart-title">Trip Bookings</div></div>
      <table class="data-table">
        <thead><tr><th>ID</th><th>User</th><th>Route</th><th>Seats</th><th>Total</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach($trip_bookings as $tb):?>
          <tr>
            <td style="font-family:monospace;color:var(--gold)">#<?= $tb['id'] ?></td>
            <td><?= htmlspecialchars(($tb['user_name']??'').' '.($tb['user_surname']??'')) ?></td>
            <td><?= htmlspecialchars($tb['from_city']??'') ?> → <?= htmlspecialchars($tb['to_city']??'') ?></td>
            <td><?= $tb['seats_booked'] ?></td>
            <td style="font-weight:600;color:var(--gold)">₦<?= number_format($tb['total_price'],2) ?></td>
            <td><?= statusBadge($tb['status']) ?></td>
            <td>
              <form method="POST" style="display:inline-flex;gap:4px;">
                <input type="hidden" name="action" value="update_trip_booking">
                <input type="hidden" name="tb_id" value="<?= $tb['id'] ?>">
                <select name="status" class="status-select">
                  <?php foreach(['pending','confirmed','cancelled','completed'] as $s):?>
                    <option <?= ($tb['status']==$s)?'selected':'' ?>><?= $s ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-gold" style="padding:4px 8px;font-size:11px"><i data-lucide="check" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></button>
              </form>
              <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="delete_trip_booking">
                <input type="hidden" name="tb_id" value="<?= $tb['id'] ?>">
                <button type="submit" class="btn btn-danger" style="padding:4px 8px;font-size:11px" onclick="return confirm('Delete?')"><i data-lucide="trash-2" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i>️</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ═══════════ RENTALS ═══════════ -->
  <div id="page-rentals" class="page">
    <div class="metrics-grid" style="grid-template-columns:repeat(4,1fr)">
      <div class="metric-card orange"><div class="metric-top"><div class="metric-icon orange"><i data-lucide="key" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></div></div><div class="metric-value"><?= $stats['rentals'] ?></div><div class="metric-label">Total Rentals</div></div>
      <div class="metric-card gold"><div class="metric-top"><div class="metric-icon gold"><i data-lucide="circle-dollar-sign" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></div></div><div class="metric-value">₦<?= number_format(getSum($conn, 'rentals', 'total_price'), 0) ?></div><div class="metric-label">Rental Revenue</div></div>
      <div class="metric-card blue"><div class="metric-top"><div class="metric-icon blue"><i data-lucide="car-front" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></div></div><div class="metric-value"><?= $stats['rental_vehicles'] ?></div><div class="metric-label">Fleet Size</div></div>
    </div>
    <div class="table-card" style="margin-bottom:20px">
      <div class="table-header"><div class="chart-title">Car Rentals</div><div class="table-actions"><button class="btn btn-gold" onclick="openModal('modal-rental')">+ New Rental</button></div></div>
      <table class="data-table">
        <thead><tr><th>ID</th><th>User</th><th>Vehicle</th><th>Pickup</th><th>Return</th><th>Days</th><th>Total</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
          <?php foreach($rentals as $r):?>
          <tr>
            <td style="font-family:monospace;color:var(--gold)">#<?= $r['id'] ?></td><td><?= htmlspecialchars(($r['user_name']??'').' '.($r['user_surname']??'')) ?></td><td><?= htmlspecialchars($r['car_model']) ?></td>
            <td><?= $r['pickup_date'] ?></td><td><?= $r['return_date'] ?></td><td><?= $r['total_days'] ?></td>
            <td style="font-weight:600;color:var(--gold)">₦<?= number_format($r['total_price'],2) ?></td><td><?= statusBadge($r['status']) ?></td>
            <td>
              <form method="POST" style="display:inline-flex;gap:4px;"><input type="hidden" name="action" value="update_rental"><input type="hidden" name="rental_id" value="<?= $r['id'] ?>"><select name="status" class="status-select"><?php foreach(['pending','confirmed','active','returned','cancelled'] as $s):?><option <?= ($r['status']==$s)?'selected':'' ?>><?= $s ?></option><?php endforeach; ?></select><button type="submit" class="btn btn-gold" style="padding:4px 8px;font-size:11px"><i data-lucide="check" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></button></form>
              <form method="POST" style="display:inline;"><input type="hidden" name="action" value="delete_rental"><input type="hidden" name="rental_id" value="<?= $r['id'] ?>"><button type="submit" class="btn btn-danger" style="padding:4px 8px;font-size:11px" onclick="return confirm('Delete?')"><i data-lucide="trash-2" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i>️</button></form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="table-card">
      <div class="table-header"><div class="chart-title">Rental Fleet</div><div class="table-actions"><button class="btn btn-gold" onclick="openModal('modal-rental-vehicle')">+ Add Vehicle</button></div></div>
      <table class="data-table">
        <thead><tr><th>Image</th><th>ID</th><th>Vehicle</th><th>Plate</th><th>Daily Rate</th><th>Available</th><th>Actions</th></tr></thead>
        <tbody>
          <?php foreach($rental_vehicles as $rv):?>
          <tr>
            <td><?= $rv['image_url'] ? "<img src='../uploads/".htmlspecialchars($rv['image_url'])."' class='img-thumb'>" : '—' ?></td>
            <td style="font-family:monospace;color:var(--gold)">#<?= $rv['id'] ?></td><td><?= htmlspecialchars($rv['make'].' '.$rv['model']) ?> (<?= $rv['year'] ?>)</td>
            <td><?= htmlspecialchars($rv['plate']??'N/A') ?></td><td style="font-weight:600;color:var(--gold)">₦<?= number_format($rv['price_per_day'],2) ?></td>
            <td><?= $rv['is_available'] ? '<span class="badge badge-green"><i data-lucide="check-circle-2" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i> Yes</span>' : '<span class="badge badge-red"><i data-lucide="x-circle" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i> No</span>' ?></td>
            <td>
              <form method="POST" style="display:inline-flex;gap:4px;"><input type="hidden" name="action" value="update_rental_vehicle_status"><input type="hidden" name="rv_id" value="<?= $rv['id'] ?>"><select name="is_available" class="status-select"><option value="1" <?= ($rv['is_available']==1)?'selected':'' ?>>Yes</option><option value="0" <?= ($rv['is_available']==0)?'selected':'' ?>>No</option></select><button type="submit" class="btn btn-gold" style="padding:4px 8px;font-size:11px"><i data-lucide="check" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></button></form>
              <form method="POST" style="display:inline;"><input type="hidden" name="action" value="delete_rental_vehicle"><input type="hidden" name="rv_id" value="<?= $rv['id'] ?>"><button type="submit" class="btn btn-danger" style="padding:4px 8px;font-size:11px" onclick="return confirm('Delete?')"><i data-lucide="trash-2" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i>️</button></form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ═══════════ ORDERS ═══════════ -->
  <div id="page-orders" class="page">
    <div class="metrics-grid">
      <div class="metric-card gold"><div class="metric-top"><div class="metric-icon gold"><i data-lucide="utensils" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></div></div><div class="metric-value"><?= $stats['orders'] ?></div><div class="metric-label">Total Orders</div></div>
      <div class="metric-card green"><div class="metric-top"><div class="metric-icon green"><i data-lucide="check-circle-2" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></div></div><div class="metric-value"><?= $stats['foods'] ?></div><div class="metric-label">Menu Items</div></div>
    </div>
    <div class="table-card" style="margin-bottom:20px">
      <div class="table-header"><div class="chart-title">Food Orders</div></div>
      <table class="data-table">
        <thead><tr><th>ID</th><th>User</th><th>Food</th><th>Qty</th><th>Total</th><th>Address</th><th>Payment</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
          <?php foreach($orders as $o):?>
          <tr>
            <td style="font-family:monospace;color:var(--gold)">#<?= $o['id'] ?></td><td><?= htmlspecialchars(($o['user_name']??'').' '.($o['user_surname']??'')) ?></td><td><?= htmlspecialchars($o['food_name']??'') ?></td>
            <td><?= $o['quantity'] ?></td><td style="font-weight:600;color:var(--gold)">₦<?= number_format($o['total_price'],2) ?></td>
            <td><?= htmlspecialchars($o['dropoff_address']??'') ?></td><td><?= htmlspecialchars($o['payment_method']??'cash') ?></td>
            <td><?= statusBadge($o['status']) ?></td>
            <td>
              <form method="POST" style="display:inline-flex;gap:4px;"><input type="hidden" name="action" value="update_order"><input type="hidden" name="order_id" value="<?= $o['id'] ?>"><select name="status" class="status-select"><?php foreach(['pending','processing','dispatched','delivered','cancelled'] as $s):?><option <?= ($o['status']==$s)?'selected':'' ?>><?= $s ?></option><?php endforeach; ?></select><button type="submit" class="btn btn-gold" style="padding:4px 8px;font-size:11px"><i data-lucide="check" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></button></form>
              <form method="POST" style="display:inline;"><input type="hidden" name="action" value="delete_order"><input type="hidden" name="order_id" value="<?= $o['id'] ?>"><button type="submit" class="btn btn-danger" style="padding:4px 8px;font-size:11px" onclick="return confirm('Delete?')"><i data-lucide="trash-2" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i>️</button></form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="table-card">
      <div class="table-header"><div class="chart-title">Food Menu</div><div class="table-actions"><button class="btn btn-gold" onclick="openModal('modal-food')">+ Add Food</button></div></div>
      <table class="data-table">
        <thead><tr><th>Image</th><th>ID</th><th>Name</th><th>Category</th><th>Price</th><th>Available</th><th>Actions</th></tr></thead>
        <tbody>
          <?php foreach($foods as $f):?>
          <tr>
            <td><?= $f['image_url'] ? "<img src='../uploads/".htmlspecialchars($f['image_url'])."' class='img-thumb'>" : '—' ?></td>
            <td style="font-family:monospace;color:var(--gold)">#<?= $f['id'] ?></td><td><?= htmlspecialchars($f['name']) ?></td><td><?= htmlspecialchars($f['category']) ?></td>
            <td style="font-weight:600;color:var(--gold)">₦<?= number_format($f['price'],2) ?></td><td><?= $f['is_available'] ? '<span class="badge badge-green"><i data-lucide="check-circle-2" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></span>' : '<span class="badge badge-red"><i data-lucide="x-circle" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></span>' ?></td>
            <td><form method="POST"><input type="hidden" name="action" value="delete_food"><input type="hidden" name="food_id" value="<?= $f['id'] ?>"><button type="submit" class="btn btn-danger" style="padding:4px 8px;font-size:11px" onclick="return confirm('Delete?')"><i data-lucide="trash-2" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i>️</button></form></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ═══════════ MARKETPLACE ═══════════ -->
  <div id="page-marketplace" class="page">
    <div class="metrics-grid" style="grid-template-columns:repeat(4,1fr)">
      <div class="metric-card blue"><div class="metric-top"><div class="metric-icon blue"><i data-lucide="car-front" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></div></div><div class="metric-value"><?= $stats['vehicles'] ?></div><div class="metric-label">Vehicles Listed</div></div>
      <div class="metric-card gold"><div class="metric-top"><div class="metric-icon gold"><i data-lucide="handshake" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></div></div><div class="metric-value"><?= $stats['purchases'] ?></div><div class="metric-label">Purchase Requests</div></div>
    </div>
    <div class="table-card" style="margin-bottom:20px">
      <div class="table-header"><div class="chart-title">Vehicle Listings</div><div class="table-actions"><button class="btn btn-gold" onclick="openModal('modal-vehicle')">+ Add Vehicle</button></div></div>
      <table class="data-table">
        <thead><tr><th>Image</th><th>ID</th><th>Make/Model</th><th>Year</th><th>Price</th><th>Condition</th><th>Actions</th></tr></thead>
        <tbody>
          <?php foreach($vehicles as $v):?>
          <tr>
            <td><?= $v['image_url'] ? "<img src='../uploads/".htmlspecialchars($v['image_url'])."' class='img-thumb'>" : '—' ?></td>
            <td style="font-family:monospace;color:var(--gold)">#<?= $v['id'] ?></td><td><?= htmlspecialchars($v['make'].' '.$v['model']) ?></td><td><?= $v['year'] ?></td>
            <td style="font-weight:600;color:var(--gold)">₦<?= number_format($v['price'],2) ?></td><td><?= htmlspecialchars($v['condition']) ?></td>
            <td>
              <form method="POST" style="display:inline-flex;gap:4px;"><input type="hidden" name="action" value="update_vehicle_status"><input type="hidden" name="vehicle_id" value="<?= $v['id'] ?>"><select name="status" class="status-select"><option <?= ($v['status']=='available')?'selected':'' ?>>available</option><option <?= ($v['status']=='reserved')?'selected':'' ?>>reserved</option></select><button type="submit" class="btn btn-gold" style="padding:4px 8px;font-size:11px"><i data-lucide="check" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></button></form>
              <form method="POST" style="display:inline;"><input type="hidden" name="action" value="delete_vehicle"><input type="hidden" name="vehicle_id" value="<?= $v['id'] ?>"><button type="submit" class="btn btn-danger" style="padding:4px 8px;font-size:11px" onclick="return confirm('Delete?')"><i data-lucide="trash-2" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i>️</button></form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="table-card">
      <div class="table-header"><div class="chart-title">Purchase Requests</div></div>
      <table class="data-table">
        <thead><tr><th>ID</th><th>Buyer</th><th>Vehicle</th><th>Amount</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
          <?php foreach($purchases as $p):?>
          <tr>
            <td style="font-family:monospace;color:var(--gold)">#<?= $p['id'] ?></td><td><?= htmlspecialchars($p['full_name']??'') ?></td>
            <td><?= htmlspecialchars(($p['make']??'').' '.($p['model']??'')) ?></td>
            <td style="font-weight:600;color:var(--gold)">₦<?= number_format($p['amount']??0,2) ?></td><td><?= statusBadge($p['status']) ?></td>
            <td>
              <form method="POST" style="display:inline-flex;gap:4px;"><input type="hidden" name="action" value="update_purchase"><input type="hidden" name="purchase_id" value="<?= $p['id'] ?>"><select name="status" class="status-select"><?php foreach(['pending','approved','completed','cancelled','rejected'] as $s):?><option <?= ($p['status']==$s)?'selected':'' ?>><?= $s ?></option><?php endforeach; ?></select><button type="submit" class="btn btn-gold" style="padding:4px 8px;font-size:11px"><i data-lucide="check" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></button></form>
              <form method="POST" style="display:inline;"><input type="hidden" name="action" value="delete_purchase"><input type="hidden" name="purchase_id" value="<?= $p['id'] ?>"><button type="submit" class="btn btn-danger" style="padding:4px 8px;font-size:11px" onclick="return confirm('Delete?')"><i data-lucide="trash-2" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i>️</button></form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ═══════════ USERS ═══════════ -->
  <div id="page-users" class="page">
    <div class="metrics-grid" style="grid-template-columns:repeat(3,1fr)">
      <div class="metric-card green"><div class="metric-top"><div class="metric-icon green"><i data-lucide="users" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></div></div><div class="metric-value"><?= number_format($stats['users']) ?></div><div class="metric-label">Total Users</div></div>
    </div>
    <div class="table-card">
      <div class="table-header"><div class="chart-title">Registered Users</div></div>
      <table class="data-table">
        <thead><tr><th>ID</th><th>Full Name</th><th>Email</th><th>Phone</th><th>State</th><th>Joined</th><th>Actions</th></tr></thead>
        <tbody>
          <?php foreach($users as $u):?>
          <tr>
            <td style="font-family:monospace;color:var(--gold)">#<?= $u['id'] ?></td>
            <td><?= htmlspecialchars(($u['name']??'').' '.($u['surname']??'')) ?></td>
            <td><?= htmlspecialchars($u['email']??'') ?></td>
            <td><?= htmlspecialchars($u['phone']??'') ?></td>
            <td><?= htmlspecialchars($u['state']??'') ?></td>
            <td style="color:var(--text3);font-size:12px"><?= $u['created_at'] ?></td>
            <td>
              <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="delete_user">
                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                <button type="submit" class="btn btn-danger" style="padding:4px 8px;font-size:11px" onclick="return confirm('Delete user?')"><i data-lucide="trash-2" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ═══════════ DRIVERS ═══════════ -->
  <div id="page-drivers" class="page">
    <div class="metrics-grid" style="grid-template-columns:repeat(3,1fr)">
      <div class="metric-card green"><div class="metric-top"><div class="metric-icon green"><i data-lucide="id-card" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></div></div><div class="metric-value"><?= number_format($stats['drivers']) ?></div><div class="metric-label">Total Drivers</div></div>
    </div>
    <div class="table-card">
      <div class="table-header"><div class="chart-title">Driver Registry</div></div>
      <table class="data-table">
        <thead><tr><th>ID</th><th>Name</th><th>Phone</th><th>Email</th><th>Vehicle</th><th>Plate</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
          <?php foreach($drivers as $d):?>
          <tr>
            <td style="font-family:monospace;color:var(--gold)">#<?= $d['driver_id'] ?></td>
            <td><?= htmlspecialchars(($d['name']??'').' '.($d['surname']??'')) ?></td>
            <td><?= htmlspecialchars($d['phone']??'') ?></td>
            <td><?= htmlspecialchars($d['email']??'') ?></td>
            <td><?= htmlspecialchars(($d['vehicle_make']??'').' '.($d['vehicle_model']??'')) ?></td>
            <td><?= htmlspecialchars($d['plate_number']??'') ?></td>
            <td><?= statusBadge($d['status']??'offline') ?></td>
            <td>
              <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="delete_driver">
                <input type="hidden" name="driver_id" value="<?= $d['driver_id'] ?>">
                <button type="submit" class="btn btn-danger" style="padding:4px 8px;font-size:11px" onclick="return confirm('Delete driver?')"><i data-lucide="trash-2" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ═══════════ LIVE ACTIVITY ═══════════ -->
  <div id="page-live" class="page">
    <div class="chart-grid cols-2">
      <div class="chart-card">
        <div class="chart-header"><div class="chart-title">Live Event Stream</div><div class="status-pill"><div class="status-dot"></div>REAL-TIME</div></div>
        <div class="activity-feed" id="live-feed2" style="max-height:480px">
          <div class="feed-item"><div class="feed-dot" style="background:var(--green)"></div><div><div class="feed-text"><strong>SYSTEM</strong> — All services operational</div><div class="feed-time">Just now</div></div></div>
        </div>
      </div>
      <div>
        <div class="chart-card" style="margin-bottom:16px">
          <div class="chart-header"><div class="chart-title">Real-Time Counters</div></div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:0">
            <div style="text-align:center;padding:20px;border-right:1px solid var(--glass-border2);border-bottom:1px solid var(--glass-border2)"><div style="font-family:'Bebas Neue';font-size:40px;color:var(--blue)" id="rt-rides"><?= $stats['bookings'] ?></div><div style="font-size:11px;color:var(--text3)">Total Rides</div></div>
            <div style="text-align:center;padding:20px;border-bottom:1px solid var(--glass-border2)"><div style="font-family:'Bebas Neue';font-size:40px;color:var(--orange)" id="rt-orders"><?= $stats['orders'] ?></div><div style="font-size:11px;color:var(--text3)">Total Orders</div></div>
            <div style="text-align:center;padding:20px;border-right:1px solid var(--glass-border2)"><div style="font-family:'Bebas Neue';font-size:40px;color:var(--green)" id="rt-drivers"><?= $stats['drivers'] ?></div><div style="font-size:11px;color:var(--text3)">Drivers</div></div>
            <div style="text-align:center;padding:20px"><div style="font-family:'Bebas Neue';font-size:28px;color:var(--gold)" id="rt-revenue">₦<?= number_format($totalRevenue/1000000,1) ?>M</div><div style="font-size:11px;color:var(--text3)">Total Revenue</div></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ═══════════ NOTIFICATIONS ═══════════ -->
  <div id="page-notifications" class="page">
    <div class="table-actions" style="margin-bottom:20px;display:flex;gap:10px;flex-wrap:wrap">
      <button class="btn btn-gold">+ Send Notification</button>
      <button class="btn btn-outline">Mark All Read</button>
    </div>
    <div class="chart-card">
      <div class="chart-header"><div class="chart-title">Notification Center</div></div>
      <div style="padding:20px;color:var(--text3)">No notifications yet. System is running smoothly.</div>
    </div>
  </div>

  <!-- ═══════════ SECURITY ═══════════ -->
  <div id="page-security" class="page">
    <div class="chart-card">
      <div class="chart-header"><div class="chart-title">Security Status</div></div>
      <div style="padding:20px;color:var(--green);font-size:14px"><i data-lucide="check-circle-2" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i> All security systems operational. No threats detected.</div>
    </div>
  </div>

  <!-- ═══════════ API ═══════════ -->
  <div id="page-api" class="page">
    <div class="chart-card">
      <div class="chart-header"><div class="chart-title">API Monitor</div></div>
      <div style="position:relative;height:190px"><canvas id="apiChart" role="img" aria-label="API request volume"></canvas></div>
    </div>
  </div>

  <!-- ═══════════ LOGS ═══════════ -->
  <div id="page-logs" class="page">
    <div class="table-card">
      <div class="table-header"><div class="chart-title">Audit Log</div></div>
      <table class="data-table">
        <thead><tr><th>Action</th><th>Actor</th><th>Target</th><th>Timestamp</th></tr></thead>
        <tbody>
          <tr><td><span class="badge badge-green">system.startup</span></td><td>System</td><td>All Services</td><td style="color:var(--text3);font-size:12px"><?= date('Y-m-d H:i:s') ?></td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ═══════════ SETTINGS ═══════════ -->
  <div id="page-settings" class="page">
    <div class="chart-card" style="margin-bottom:16px">
      <div class="chart-header"><div class="chart-title">Platform Settings</div></div>
      <div style="display:flex;flex-direction:column;gap:12px;padding:16px">
        <div><div class="inp-label">Platform Name</div><input class="inp" value="TransNetX Ultimate"></div>
        <div><div class="inp-label">Primary Currency</div><input class="inp" value="NGN (₦)"></div>
        <div><div class="inp-label">Support Email</div><input class="inp" value="support@transnetx.com"></div>
      </div>
    </div>
    <div style="display:flex;gap:12px;flex-wrap:wrap">
      <button class="btn btn-gold" style="padding:10px 28px"><i data-lucide="save" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i> Save Settings</button>
      <button class="btn btn-outline" style="padding:10px 28px"><i data-lucide="refresh-cw" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i> Reset Defaults</button>
    </div>
  </div>

</div><!-- /main -->

<!-- MODALS -->
<div class="modal-overlay" id="modal-flight"><div class="modal"><button class="close-modal" onclick="closeModal('modal-flight')"><i data-lucide="x" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></button><h3 style="font-family:'Bebas Neue';letter-spacing:1px;margin-bottom:16px;color:var(--gold)">Add Flight</h3><form method="POST"><input type="hidden" name="action" value="insert_flight"><div class="form-grid"><div class="form-group full"><label class="inp-label">Flight Number</label><input type="text" name="flight_number" required class="inp"></div><div class="form-group"><label class="inp-label">Airline</label><input type="text" name="airline" class="inp"></div><div class="form-group"><label class="inp-label">Origin Code</label><input type="text" name="origin_code" required class="inp"></div><div class="form-group"><label class="inp-label">Dest Code</label><input type="text" name="dest_code" required class="inp"></div><div class="form-group"><label class="inp-label">Origin City</label><input type="text" name="origin_city" required class="inp"></div><div class="form-group"><label class="inp-label">Dest City</label><input type="text" name="dest_city" required class="inp"></div><div class="form-group"><label class="inp-label">Departure Time</label><input type="datetime-local" name="departure_time" required class="inp"></div><div class="form-group"><label class="inp-label">Arrival Time</label><input type="datetime-local" name="arrival_time" required class="inp"></div><div class="form-group"><label class="inp-label">Price per Seat</label><input type="number" step="0.01" name="price_per_seat" required class="inp"></div><div class="form-group"><label class="inp-label">Available Seats</label><input type="number" name="available_seats" value="100" class="inp"></div></div><button type="submit" class="btn btn-gold" style="margin-top:16px">Add Flight</button></form></div></div>

<div class="modal-overlay" id="modal-trip"><div class="modal"><button class="close-modal" onclick="closeModal('modal-trip')"><i data-lucide="x" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></button><h3 style="font-family:'Bebas Neue';letter-spacing:1px;margin-bottom:16px;color:var(--gold)">Add Trip</h3><form method="POST"><input type="hidden" name="action" value="insert_trip"><div class="form-grid"><div class="form-group"><label class="inp-label">Transport Type</label><select name="transport_type" class="inp"><option>bus</option><option>train</option><option>sea</option></select></div><div class="form-group"><label class="inp-label">From City</label><input type="text" name="from_city" required class="inp"></div><div class="form-group"><label class="inp-label">To City</label><input type="text" name="to_city" required class="inp"></div><div class="form-group"><label class="inp-label">Departure Date</label><input type="date" name="departure_date" required class="inp"></div><div class="form-group"><label class="inp-label">Departure Time</label><input type="time" name="departure_time" required class="inp"></div><div class="form-group"><label class="inp-label">Available Seats</label><input type="number" name="available_seats" value="40" class="inp"></div><div class="form-group"><label class="inp-label">Price per Seat</label><input type="number" step="0.01" name="price_per_seat" required class="inp"></div></div><button type="submit" class="btn btn-gold" style="margin-top:16px">Add Trip</button></form></div></div>

<div class="modal-overlay" id="modal-vehicle"><div class="modal"><button class="close-modal" onclick="closeModal('modal-vehicle')"><i data-lucide="x" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></button><h3 style="font-family:'Bebas Neue';letter-spacing:1px;margin-bottom:16px;color:var(--gold)">Add Vehicle for Sale</h3><form method="POST" enctype="multipart/form-data"><input type="hidden" name="action" value="insert_vehicle"><div class="form-grid"><div class="form-group"><label class="inp-label">Make</label><input type="text" name="make" required class="inp"></div><div class="form-group"><label class="inp-label">Model</label><input type="text" name="model" required class="inp"></div><div class="form-group"><label class="inp-label">Year</label><input type="number" name="year" required class="inp"></div><div class="form-group"><label class="inp-label">Category</label><input type="text" name="category" class="inp"></div><div class="form-group"><label class="inp-label">Condition</label><select name="condition" class="inp"><option>New</option><option>Used</option></select></div><div class="form-group"><label class="inp-label">Price (₦)</label><input type="number" step="0.01" name="price" required class="inp"></div><div class="form-group"><label class="inp-label">Fuel Type</label><input type="text" name="fuel_type" value="Petrol" class="inp"></div><div class="form-group"><label class="inp-label">Transmission</label><input type="text" name="transmission" value="Automatic" class="inp"></div><div class="form-group"><label class="inp-label">Mileage</label><input type="number" name="mileage" value="0" class="inp"></div><div class="form-group"><label class="inp-label">Color</label><input type="text" name="color" value="White" class="inp"></div><div class="form-group"><label class="inp-label">Seats</label><input type="number" name="seats" value="5" class="inp"></div><div class="form-group"><label class="inp-label">Engine</label><input type="text" name="engine" class="inp"></div><div class="form-group full"><label class="inp-label">Image</label><input type="file" name="vehicle_image" accept="image/*" class="inp" style="padding:6px"></div></div><button type="submit" class="btn btn-gold" style="margin-top:16px">Add Vehicle</button></form></div></div>

<div class="modal-overlay" id="modal-rental-vehicle"><div class="modal"><button class="close-modal" onclick="closeModal('modal-rental-vehicle')"><i data-lucide="x" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></button><h3 style="font-family:'Bebas Neue';letter-spacing:1px;margin-bottom:16px;color:var(--gold)">Add Rental Vehicle</h3><form method="POST" enctype="multipart/form-data"><input type="hidden" name="action" value="insert_rental_vehicle"><div class="form-grid"><div class="form-group"><label class="inp-label">Make</label><input type="text" name="make" required class="inp"></div><div class="form-group"><label class="inp-label">Model</label><input type="text" name="model" required class="inp"></div><div class="form-group"><label class="inp-label">Year</label><input type="number" name="year" required class="inp"></div><div class="form-group"><label class="inp-label">Category</label><input type="text" name="category" class="inp"></div><div class="form-group"><label class="inp-label">Plate</label><input type="text" name="plate" class="inp"></div><div class="form-group"><label class="inp-label">Daily Rate (₦)</label><input type="number" step="0.01" name="price_per_day" required class="inp"></div><div class="form-group full"><label class="inp-label">Description</label><textarea name="description" class="inp"></textarea></div><div class="form-group"><label class="inp-label">Available</label><input type="checkbox" name="is_available" checked></div><div class="form-group full"><label class="inp-label">Image</label><input type="file" name="rv_image" accept="image/*" class="inp" style="padding:6px"></div></div><button type="submit" class="btn btn-gold" style="margin-top:16px">Add Vehicle</button></form></div></div>

<div class="modal-overlay" id="modal-rental"><div class="modal"><button class="close-modal" onclick="closeModal('modal-rental')"><i data-lucide="x" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></button><h3 style="font-family:'Bebas Neue';letter-spacing:1px;margin-bottom:16px;color:var(--gold)">Create Rental Order</h3><form method="POST"><input type="hidden" name="action" value="insert_rental"><div class="form-grid"><div class="form-group"><label class="inp-label">User ID</label><input type="number" name="user_id" required class="inp"></div><div class="form-group"><label class="inp-label">Vehicle ID (Rental Fleet)</label><input type="number" name="vehicle_id" required class="inp"></div><div class="form-group"><label class="inp-label">Pickup Date</label><input type="date" name="pickup_date" required class="inp"></div><div class="form-group"><label class="inp-label">Return Date</label><input type="date" name="return_date" required class="inp"></div><div class="form-group"><label class="inp-label">Pickup Location</label><input type="text" name="pickup_location" class="inp"></div><div class="form-group"><label class="inp-label">Driver Option (+₦15k/day)</label><input type="checkbox" name="driver_option" value="1"></div><div class="form-group full"><label class="inp-label">Notes</label><input type="text" name="notes" class="inp"></div></div><button type="submit" class="btn btn-gold" style="margin-top:16px">Create Rental</button></form></div></div>

<div class="modal-overlay" id="modal-food"><div class="modal"><button class="close-modal" onclick="closeModal('modal-food')"><i data-lucide="x" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i></button><h3 style="font-family:'Bebas Neue';letter-spacing:1px;margin-bottom:16px;color:var(--gold)">Add Food Item</h3><form method="POST" enctype="multipart/form-data"><input type="hidden" name="action" value="insert_food"><div class="form-grid"><div class="form-group full"><label class="inp-label">Name</label><input type="text" name="name" required class="inp"></div><div class="form-group full"><label class="inp-label">Description</label><textarea name="description" class="inp"></textarea></div><div class="form-group"><label class="inp-label">Category</label><input type="text" name="category" class="inp"></div><div class="form-group"><label class="inp-label">Price (₦)</label><input type="number" step="0.01" name="price" required class="inp"></div><div class="form-group full"><label class="inp-label">Image</label><input type="file" name="food_image" accept="image/*" class="inp" style="padding:6px"></div></div><button type="submit" class="btn btn-gold" style="margin-top:16px">Add Food</button></form></div></div>

<script>
// ─── NAVIGATION ───────────────────────────────────────────────
const pageTitles={
  overview:'Overview Dashboard',analytics:'Analytics Insights',financials:'Financial Reports',
  rides:'Ride Hailing',flights:'Flights Management',trips:'Intercity Trips',
  rentals:'Car Rentals',orders:'Food Orders',marketplace:'Vehicle Marketplace',
  users:'User Management',drivers:'Driver Management',live:'Live Activity Feed',
  notifications:'Notifications',security:'Security Center',api:'API Monitor',
  logs:'Audit Logs',settings:'Platform Settings'
};
function showPage(id,el){
  document.querySelectorAll('.page').forEach(p=>p.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n=>n.classList.remove('active'));
  const p=document.getElementById('page-'+id);
  if(p)p.classList.add('active');
  if(el)el.classList.add('active');
  const t=pageTitles[id]||id;
  const words=t.split(' ');const last=words.pop();
  document.getElementById('pageTitle').innerHTML=words.join(' ')+' <span>'+last+'</span>';
  if(window.innerWidth<=1100)document.getElementById('sidebar').classList.remove('open');
}
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open')}
function openModal(id){document.getElementById(id).classList.add('open')}
function closeModal(id){document.getElementById(id).classList.remove('open')}
document.querySelectorAll('.modal-overlay').forEach(m=>m.addEventListener('click',function(e){if(e.target===this)this.classList.remove('open')}));

// ─── ANIMATED COUNTERS ────────────────────────────────────────
function animCount(el,target,pfx='',sfx='',dur=2000){
  if(!el)return;
  const s=Date.now();
  const tick=()=>{
    const p=Math.min((Date.now()-s)/dur,1);
    const e=1-Math.pow(1-p,4);
    const v=Math.floor(e*target);
    el.textContent=pfx+(v>=1000?v.toLocaleString():v)+sfx;
    if(p<1)requestAnimationFrame(tick);
  };
  requestAnimationFrame(tick);
}

// ─── LIVE FEED TICKER ─────────────────────────────────────────
const feedEvents=[
  {c:'var(--green)',t:'RIDE BOOKED — New booking received'},
  {c:'var(--blue)',t:'FLIGHT CONFIRMED — Booking processed'},
  {c:'var(--gold)',t:'DRIVER ONLINE — Driver came online'},
  {c:'var(--orange)',t:'FOOD ORDER — New order placed'},
  {c:'var(--purple)',t:'VEHICLE LISTED — New marketplace listing'},
  {c:'var(--green)',t:'KYC APPROVED — Driver fully verified'},
  {c:'var(--red)',t:'DISPUTE — Customer complaint filed'},
  {c:'var(--blue)',t:'WALLET TOPUP — Payment received'},
];
let feedIdx=0;
setInterval(()=>{
  const feed=document.getElementById('live-feed');
  if(!feed)return;
  const ev=feedEvents[feedIdx++%feedEvents.length];
  const item=document.createElement('div');
  item.className='feed-item';
  item.style.cssText='opacity:0;transform:translateX(-12px);transition:all 0.3s';
  item.innerHTML=`<div class="feed-dot" style="background:${ev.c}"></div><div><div class="feed-text">${ev.t}</div><div class="feed-time">Just now</div></div>`;
  feed.insertBefore(item,feed.firstChild);
  setTimeout(()=>{item.style.opacity='1';item.style.transform='translateX(0)'},10);
  while(feed.children.length>8)feed.removeChild(feed.lastChild);
  const ft=document.getElementById('feed-time');
  if(ft)ft.textContent='Updated '+new Date().toLocaleTimeString();
},4200);

// ─── CHARTS ───────────────────────────────────────────────────
const M=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug'];
const co={
  grid:{color:'rgba(255,255,255,0.04)'},
  tick:{color:'#606080',font:{size:11}}
};
const revData = [<?= implode(',', $monthlyRevenue) ?>];

// Revenue Chart
const revenueCtx = document.getElementById('revenueChart');
if(revenueCtx) new Chart(revenueCtx,{
  type:'line',
  data:{labels:M,datasets:[
    {label:'Revenue (₦M)',data:revData,borderColor:'#f5c518',backgroundColor:'rgba(245,197,24,0.07)',tension:0.4,pointRadius:4,pointBackgroundColor:'#f5c518',borderWidth:2.5,fill:true},
    {label:'Commission',data:revData.map(v=>Math.round(v*0.15)),borderColor:'#4488ff',backgroundColor:'rgba(68,136,255,0.04)',tension:0.4,pointRadius:3,pointBackgroundColor:'#4488ff',borderWidth:1.5,fill:true,borderDash:[5,4]}
  ]},
  options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{grid:co.grid,ticks:co.tick},y:{grid:co.grid,ticks:{...co.tick,callback:v=>v+'M'}}}}
});

// Service Donut
const serviceCtx = document.getElementById('serviceChart');
if(serviceCtx) new Chart(serviceCtx,{
  type:'doughnut',
  data:{labels:['Rides','Flights','Orders','Rentals','Trips'],datasets:[{data:[<?= $stats['bookings'] ?>,<?= $stats['flight_bookings'] ?>,<?= $stats['orders'] ?>,<?= $stats['rentals'] ?>,<?= $stats['trip_bookings'] ?>],backgroundColor:['#f5c518','#4488ff','#00e5a0','#9b59b6','#ff8800'],borderWidth:0,hoverOffset:8}]},
  options:{responsive:true,maintainAspectRatio:false,cutout:'74%',plugins:{legend:{display:false}},animation:{duration:1400}}
});

// Growth Chart
const growthCtx = document.getElementById('growthChart');
if(growthCtx) new Chart(growthCtx,{
  type:'bar',
  data:{labels:M,datasets:[
    {label:'Users',data:[120,180,210,280,350,420,510,<?= $stats['users'] ?>],backgroundColor:'rgba(68,136,255,0.65)',borderRadius:4},
    {label:'Drivers',data:[20,35,50,70,90,120,160,<?= $stats['drivers'] ?>],backgroundColor:'rgba(245,197,24,0.65)',borderRadius:4}
  ]},
  options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{grid:co.grid,ticks:co.tick},y:{grid:co.grid,ticks:co.tick}}}
});

// Ride Chart
const rideCtx = document.getElementById('rideChart');
if(rideCtx) new Chart(rideCtx,{
  type:'line',
  data:{labels:M,datasets:[{label:'Rides',data:[120,180,210,195,230,280,310,360],borderColor:'#4488ff',backgroundColor:'rgba(68,136,255,0.07)',tension:0.4,fill:true,borderWidth:2,pointRadius:3,pointBackgroundColor:'#4488ff'}]},
  options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{grid:co.grid,ticks:co.tick},y:{grid:co.grid,ticks:co.tick}}}
});

// Order Chart
const orderCtx = document.getElementById('orderChart');
if(orderCtx) new Chart(orderCtx,{
  type:'bar',
  data:{labels:M,datasets:[{label:'Orders',data:[62,78,89,82,95,110,105,125],backgroundColor:'rgba(245,197,24,0.65)',borderRadius:5,borderSkipped:false}]},
  options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{grid:co.grid,ticks:co.tick},y:{grid:co.grid,ticks:co.tick}}}
});

// Financial Chart
const finCtx = document.getElementById('finChart');
if(finCtx) new Chart(finCtx,{
  type:'line',
  data:{labels:M,datasets:[
    {label:'Gross Revenue',data:revData,borderColor:'#f5c518',backgroundColor:'rgba(245,197,24,0.07)',tension:0.4,fill:true,borderWidth:2.5},
    {label:'Payouts',data:revData.map(v=>Math.round(v*0.85)),borderColor:'#4488ff',borderDash:[5,4],tension:0.4,borderWidth:2,fill:false},
    {label:'Commission',data:revData.map(v=>Math.round(v*0.15)),borderColor:'#00e5a0',tension:0.4,borderWidth:2,fill:false}
  ]},
  options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{grid:co.grid,ticks:co.tick},y:{grid:co.grid,ticks:{...co.tick,callback:v=>'₦'+v+'M'}}}}
});

// API Chart
const apiCtx = document.getElementById('apiChart');
if(apiCtx) new Chart(apiCtx,{
  type:'line',
  data:{labels:['00:00','02:00','04:00','06:00','08:00','10:00','12:00','14:00','16:00','18:00','20:00','22:00'],datasets:[{label:'Requests',data:[1200,800,500,700,4500,8900,12000,11000,9800,11500,8800,6000],borderColor:'#4488ff',backgroundColor:'rgba(68,136,255,0.07)',tension:0.4,fill:true,borderWidth:2,pointRadius:0}]},
  options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{grid:co.grid,ticks:co.tick},y:{grid:co.grid,ticks:{...co.tick,callback:v=>v>=1000?Math.round(v/1000)+'K':v}}}}
});

// GSAP entrance
if(typeof gsap!=='undefined'){
  gsap.fromTo('.metric-card',{y:18,opacity:0},{y:0,opacity:1,duration:0.45,stagger:0.06,ease:'power2.out',delay:0.15});
  gsap.fromTo('.chart-card',{y:14,opacity:0},{y:0,opacity:1,duration:0.45,stagger:0.07,ease:'power2.out',delay:0.35});
  gsap.fromTo('.quick-actions .qa-btn',{y:10,opacity:0},{y:0,opacity:1,duration:0.3,stagger:0.04,ease:'power2.out',delay:0.5});
}

// Filter button toggle
document.querySelectorAll('.chart-actions').forEach(group=>{
  group.querySelectorAll('.filter-btn').forEach(btn=>{
    btn.addEventListener('click',()=>{
      group.querySelectorAll('.filter-btn').forEach(b=>b.classList.remove('active'));
      btn.classList.add('active');
    });
  });
});
</script>

<script src="../assets/offline-icons.js"></script>
</body>
</html>