<?php
session_start();
require_once '../../config/db.php';

// ---------- AUTH ----------
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit();
}
$uid = (int) $_SESSION['user_id'];

// Fetch user
$stmt = mysqli_prepare($conn, "SELECT name FROM users WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $uid);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
if (!$user) {
    session_destroy();
    header('Location: ../../index.php');
    exit();
}
$name    = explode(' ', $user['name'])[0];
$initial = strtoupper(substr($user['name'], 0, 1));
$msg = $err = '';

/* ============================================================
   API HANDLERS
   ============================================================ */
if (isset($_GET['action']) && $_GET['action'] === 'notifications') {
    header('Content-Type: application/json');
    $stmt = mysqli_prepare($conn, "
        SELECT status, pickup_location, dropoff_location, user_seen
        FROM bookings
        WHERE user_id = ? AND status IN ('accepted', 'completed') AND user_seen = 0
        ORDER BY id DESC
    ");
    mysqli_stmt_bind_param($stmt, "i", $uid);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $data = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $message = $row['status'] === 'accepted'
            ? '🚗 Driver accepted your ride'
            : '✅ Ride completed';
        $data[] = [
            "message" => $message,
            "pickup_location" => $row['pickup_location'],
            "dropoff_location" => $row['dropoff_location'],
            "user_seen" => $row['user_seen']
        ];
    }
    echo json_encode($data);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'status') {
    header('Content-Type: application/json');
    $stmt = mysqli_prepare($conn, "
        SELECT 
            b.*, 
            CONCAT(d.name, ' ', d.surname) AS driver_full_name,
            d.vehicle_model,
            d.plate_number,
            d.image_url AS car_photo
        FROM bookings b
        LEFT JOIN drivers d ON d.driver_id = b.driver_id
        WHERE b.user_id = ?
        ORDER BY b.id DESC
        LIMIT 1
    ");
    mysqli_stmt_bind_param($stmt, "i", $uid);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    if (!$row) {
        echo json_encode([]);
        exit;
    }
    // Normalise driver car photo path: relative to TransNet/uber.php -> ../../uploads/filename
    $photo = $row['car_photo'] ?? null;
    if ($photo) {
        $photo = '../../uploads/' . basename($photo);
    }
    echo json_encode([
        'id' => $row['id'],
        'status' => $row['status'],
        'pickup_location' => $row['pickup_location'],
        'dropoff_location' => $row['dropoff_location'],
        'fare' => $row['fare'],
        'name' => $row['driver_full_name'] ?? null,
        'vehicle_model' => $row['vehicle_model'] ?? null,
        'plate_number' => $row['plate_number'] ?? null,
        'vehicle_photo' => $photo
    ]);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'complete') {
    header('Content-Type: application/json');
    $stmt = mysqli_prepare($conn, "
        UPDATE bookings
        SET status = 'completed', user_seen = 0, completed_at = NOW()
        WHERE user_id = ? AND status = 'accepted'
        ORDER BY id DESC
        LIMIT 1
    ");
    mysqli_stmt_bind_param($stmt, "i", $uid);
    mysqli_stmt_execute($stmt);
    echo json_encode(["ok" => true]);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'mark') {
    header('Content-Type: application/json');
    $stmt = mysqli_prepare($conn, "
        UPDATE bookings
        SET user_seen = 1
        WHERE user_id = ? AND status IN ('accepted', 'completed') AND user_seen = 0
    ");
    mysqli_stmt_bind_param($stmt, "i", $uid);
    mysqli_stmt_execute($stmt);
    echo json_encode(["ok" => true]);
    exit;
}

/* ============================================================
   NORMAL PAGE LOGIC
   ============================================================ */
$stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $uid);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($result);
$fname = explode(' ', $user['name'])[0] ?? 'User';
$initial = strtoupper(substr($user['name'], 0, 1)) ?? 'U';

$online = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM drivers WHERE status = 'online'"))['total'] ?? 0;

// Book ride
if (isset($_POST['book_ride'])) {
    $pickup  = trim($_POST['pickup'] ?? '');
    $dropoff = trim($_POST['dropoff'] ?? '');
    $type    = $_POST['ride_type'] ?? 'standard';
    $notes   = trim($_POST['notes'] ?? '');
    if (empty($pickup) || empty($dropoff)) {
        $err = "Pickup and dropoff required!";
    } else {
        $check = mysqli_prepare($conn, "SELECT id FROM bookings WHERE user_id = ? AND status IN ('pending','accepted') LIMIT 1");
        mysqli_stmt_bind_param($check, "i", $uid);
        mysqli_stmt_execute($check);
        $res = mysqli_stmt_get_result($check);
        if (mysqli_num_rows($res) > 0) {
            $err = '⚠️ You already have an active ride!';
        } else {
            $baseFare = 1200;
            $multiplier = ['standard'=>1, 'premium'=>1.6, 'xl'=>2.0, 'eco'=>0.85][$type] ?? 1;
            $fare = round($baseFare * $multiplier, 0);
            $stmt = mysqli_prepare($conn, "
                INSERT INTO bookings 
                (user_id, pickup_location, dropoff_location, ride_type, notes, status, fare, user_seen) 
                VALUES (?, ?, ?, ?, ?, 'pending', ?, 1)
            ");
            mysqli_stmt_bind_param($stmt, "issssd", $uid, $pickup, $dropoff, $type, $notes, $fare);
            mysqli_stmt_execute($stmt);
            header("Location: uber.php?success=1");
            exit();
        }
    }
}

// History
$my_rides = [];
$stmt = mysqli_prepare($conn, "
    SELECT 
        b.*, 
        CONCAT(d.name, ' ', d.surname) AS driver_name,
        d.plate_number,
        d.image_url
    FROM bookings b
    LEFT JOIN drivers d ON d.driver_id = b.driver_id
    WHERE b.user_id = ?
    ORDER BY b.id DESC
");
mysqli_stmt_bind_param($stmt, "i", $uid);
mysqli_stmt_execute($stmt);
$rides_result = mysqli_stmt_get_result($stmt);
while ($ride = mysqli_fetch_assoc($rides_result)) {
    $my_rides[] = $ride;
}

if (isset($_GET['success']) && $_GET['success'] == 1) {
    $msg = "Ride requested successfully! A driver will be assigned soon.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>TransRide — TransNet X</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Outfit:wght@300;400;500;600;700&family=Playfair+Display:wght@600;700;800&display=swap" rel="stylesheet"/>
<style>
    /* ========== DARK THEME — GOLD ACCENT ========== */
    :root {
        --gold: #D4A843;
        --gold2: #F0C96A;
        --gold3: #9A6F1E;
        --black: #06060A;
        --card: rgba(255,255,255,.04);
        --border: rgba(212,168,67,.15);
        --text: #EDE8DC;
        --muted: rgba(237,232,220,.45);
        --radius: 20px;
        --radius-sm: 12px;
    }

    *,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
    body{
        font-family:'Outfit',sans-serif;
        color:var(--text);
        background:var(--black);
        min-height:100vh;
        overflow-x:hidden;
        position:relative;
    }

    body::before{
        content:"";
        position:fixed;inset:0;
        background:
            radial-gradient(circle at 20% 30%, rgba(212,168,67,0.06) 0%, transparent 50%),
            radial-gradient(circle at 80% 70%, rgba(212,168,67,0.04) 0%, transparent 50%);
        pointer-events:none;z-index:0;
    }

    /* Sidebar (right side) */
    .sidebar {
        position:fixed;right:-300px;top:0;width:300px;height:100vh;
        background:rgba(6,6,10,0.98);backdrop-filter:blur(20px);
        border-left:1px solid var(--border);z-index:1000;
        transition:right 0.4s cubic-bezier(0.16,1,0.3,1);
        padding:24px 0;display:flex;flex-direction:column;
        box-shadow:-5px 0 25px rgba(0,0,0,0.4);
    }
    .sidebar.open {right:0;}
    .sidebar-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.5);backdrop-filter:blur(4px);z-index:999;opacity:0;visibility:hidden;transition:0.3s;}
    .sidebar-overlay.show{opacity:1;visibility:visible;}
    .sidebar-header{padding:0 20px 20px;border-bottom:1px solid var(--border);margin-bottom:16px;}
    .sidebar-logo{
    font-family:'Bebas Neue',sans-serif;
    font-size:24px;
    letter-spacing:3px;
    text-decoration:none;
    display:inline-block;
}

.sidebar-logo span{
    background:linear-gradient(
        135deg,
        rgba(52,211,153,1),
        rgba(16,185,129,1)
    );

    -webkit-background-clip:text;
    -webkit-text-fill-color:transparent;
    background-clip:text;

    text-shadow:
        0 0 12px rgba(52,211,153,.35),
        0 0 24px rgba(52,211,153,.18);

    transition:all .3s ease;
}

.sidebar-logo:hover span{
    filter:brightness(1.15);
    letter-spacing:4px;
}

    .sidebar-user{display:flex;align-items:center;gap:12px;padding:12px 20px;margin-bottom:16px;}
    .sidebar-avatar{width:48px;height:48px;border-radius:50%;background:linear-gradient(135deg,var(--gold3),var(--gold2));display:flex;align-items:center;justify-content:center;font-weight:700;font-size:18px;color:var(--black);}
    .sidebar-user-info h4{font-size:15px;font-weight:600;color:var(--text);}
    .sidebar-user-info p{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;}
    .sidebar-nav{flex:1;display:flex;flex-direction:column;gap:4px;padding:0 12px;}
    .sidebar-item{display:flex;align-items:center;gap:14px;padding:14px 16px;border-radius:10px;color:var(--muted);text-decoration:none;font-size:14px;transition:0.2s;border:1px solid transparent;}
    .sidebar-item i{width:22px;font-size:1.2rem;color:inherit;}
    .sidebar-item:hover{background:rgba(212,168,67,0.08);color:var(--text);border-color:var(--border);}
    .sidebar-item.active{background:rgba(212,168,67,0.12);color:var(--gold);border-color:rgba(212,168,67,0.3);font-weight:600;}
    .sidebar-item.logout{color:#F87171;margin-top:auto;}
    .sidebar-item.logout:hover{background:rgba(248,113,113,0.08);border-color:rgba(248,113,113,0.2);}
    .sidebar-footer{padding:20px;border-top:1px solid var(--border);margin-top:16px;font-size:11px;color:var(--muted);text-align:center;}

    /* Hamburger */
    .hamburger{width:40px;height:40px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;cursor:pointer;border-radius:8px;margin-right:12px;}
    .hamburger span{width:22px;height:2px;background:var(--text);border-radius:2px;transition:0.3s;}
    .hamburger.open span:nth-child(1){transform:rotate(45deg) translate(5px,6px);}
    .hamburger.open span:nth-child(2){opacity:0;}
    .hamburger.open span:nth-child(3){transform:rotate(-45deg) translate(5px,-6px);}

    /* Topbar */
    .topbar{
        position:fixed;top:0;left:0;right:0;z-index:200;
        height:72px;display:flex;align-items:center;justify-content:space-between;
        padding:0 28px;
        background:rgba(6,6,10,0.85);backdrop-filter:blur(24px);
        border-bottom:1px solid var(--border);
    }
    .topbar-left{display:flex;align-items:center;}
  .logo{
    font-family:'Bebas Neue',sans-serif;
    font-size:22px;
    letter-spacing:2px;
    text-decoration:none;
    display:flex;
    align-items:center;
    gap:4px;
}

.logo span{
    background:linear-gradient(
        135deg,
        rgba(52,211,153,1),
        rgba(16,185,129,1)
    );

    -webkit-background-clip:text;
    -webkit-text-fill-color:transparent;
    background-clip:text;

    text-shadow:
        0 0 12px rgba(52,211,153,.35),
        0 0 24px rgba(52,211,153,.18);

    transition:all .3s ease;
}

.logo em{
    font-style:normal;
    font-size:11px;
    letter-spacing:3px;

    color:rgba(52,211,153,.7);
    -webkit-text-fill-color:rgba(52,211,153,.7);

    text-shadow:
        0 0 10px rgba(52,211,153,.2);
}

.logo:hover span{
    filter:brightness(1.15);
    letter-spacing:3px;
}

.logo:hover em{
    color:rgba(52,211,153,1);
    -webkit-text-fill-color:rgba(52,211,153,1);
}
    .topbar-right{display:flex;align-items:center;gap:20px;}
    .nav-link{color:var(--muted);text-decoration:none;font-size:14px;font-weight:500;cursor:pointer;transition:0.2s;}
    .nav-link:hover{color:var(--gold);}
    .notif-wrapper{position:relative;cursor:pointer;font-size:20px;color:var(--muted);}
    .notif-wrapper i{font-size:1.2rem;}
    .notif-count{position:absolute;top:-6px;right:-8px;background:#E53E3E;color:#fff;font-size:10px;padding:2px 6px;border-radius:50%;}
    .notif-dropdown{position:absolute;top:40px;right:0;width:300px;max-height:360px;overflow-y:auto;background:rgba(15,15,20,0.98);border:1px solid var(--border);border-radius:12px;display:none;flex-direction:column;box-shadow:0 15px 40px rgba(0,0,0,0.5);z-index:300;}
    .notif-item{padding:14px;border-bottom:1px solid rgba(212,168,67,0.1);font-size:13px;color:var(--text);}
    .notif-item i{margin-right:8px;width:20px;}
    .notif-item small{display:block;color:var(--muted);font-size:10px;margin-top:4px;margin-left:28px;}
    .user-avatar{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,var(--gold3),var(--gold2));display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;color:var(--black);cursor:pointer;border:1.5px solid rgba(212,168,67,0.3);}

    /* Page Layout */
    .page{position:relative;z-index:1;padding-top:72px;display:grid;grid-template-columns:420px 1fr;min-height:100vh;}

    /* Booking Panel (left) */
    .panel{background:rgba(6,6,10,0.9);backdrop-filter:blur(20px);border-right:1px solid var(--border);padding:32px 28px;overflow-y:auto;height:calc(100vh - 72px);position:sticky;top:72px;box-shadow:5px 0 25px rgba(0,0,0,0.2);}
    .hero h1{font-family:'Playfair Display',serif;font-size:32px;font-weight:800;color:var(--text);}
    .hero h1 span{color:var(--gold);}
    .online-badge{display:inline-flex;align-items:center;gap:6px;background:rgba(212,168,67,0.1);border:1px solid rgba(212,168,67,0.3);border-radius:20px;padding:4px 12px;font-size:12px;color:var(--gold);margin-top:8px;}
    .pulse{width:7px;height:7px;border-radius:50%;background:var(--gold);animation:ping 1.5s ease-in-out infinite;}
    @keyframes ping{0%,100%{box-shadow:0 0 0 0 rgba(212,168,67,0.7)}50%{box-shadow:0 0 0 6px rgba(212,168,67,0)}}

    .msg-box{padding:14px 20px;border-radius:12px;margin-bottom:20px;font-size:14px;}
    .msg-box.success{background:rgba(52,211,153,0.1);border:1px solid rgba(52,211,153,0.3);color:#34D399;}
    .msg-box.error{background:rgba(248,113,113,0.1);border:1px solid rgba(248,113,113,0.3);color:#F87171;}

    .form-group{margin-bottom:16px;}
    .form-label{font-size:11px;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);margin-bottom:6px;}
    .form-input,.form-select{width:100%;background:rgba(255,255,255,0.05);border:1px solid var(--border);border-radius:var(--radius-sm);padding:12px 16px;color:var(--text);font-size:14px;outline:none;transition:border-color 0.2s;}
    .form-input:focus,.form-select:focus{border-color:var(--gold);}
    .form-select option{background:#1a1a1a;color:var(--text);}

    .ride-types{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:16px;}
    .rt{background:rgba(255,255,255,0.05);border:1px solid var(--border);border-radius:var(--radius-sm);padding:10px 6px;text-align:center;cursor:pointer;transition:all .2s;}
    .rt input{display:none;}
    .rt label{cursor:pointer;display:block;}
    .rt .rt-icon{font-size:20px;margin-bottom:4px; color: var(--gold);}
    .rt .rt-name{font-size:12px;font-weight:600;color:var(--text);}
    .rt .rt-price{font-size:10px;color:var(--muted);}
    .rt:has(input:checked){border-color:var(--gold);background:rgba(212,168,67,0.1);}

    .btn{width:100%;padding:14px;border-radius:var(--radius-sm);border:none;cursor:pointer;font-family:'Bebas Neue',sans-serif;font-size:18px;letter-spacing:2px;transition:all .3s;position:relative;overflow:hidden;}
    .btn-primary{background:linear-gradient(135deg,var(--gold3),var(--gold));color:var(--black);}

    /* Right side */
    .right-side{
        display:flex;flex-direction:column;
        height:calc(100vh - 72px);overflow-y:auto;
    }
    .status-bar{padding:25px 30px;border-bottom:1px solid var(--border);background:var(--card);backdrop-filter:blur(16px);}
    .status-content{display:flex;justify-content:space-between;align-items:center;}
    .status-text h2{font-family:'Playfair Display',serif;font-size:28px;color:var(--text);}
    .status-text p{font-size:13px;color:var(--muted);}
    .driver-info{display:flex;align-items:center;gap:24px;}
    .driver-info img{width:260px;height:160px;border-radius:16px;object-fit:cover;border:3px solid var(--gold);box-shadow:0 8px 25px rgba(212,168,67,0.25);}
    .driver-details { display: flex; flex-direction: column; gap: 8px; }
    .dd-item { display: flex; align-items: center; gap: 10px; }
    .dd-icon { width: 28px; height: 28px; border-radius: 8px; background: rgba(212,168,67,0.1); color: var(--gold); display: flex; align-items: center; justify-content: center; font-size: 12px; }
    .dd-text { display: flex; flex-direction: column; }
    .dd-label { font-size: 10px; text-transform: uppercase; color: var(--muted); letter-spacing: 0.5px; margin-bottom: 2px; }
    .dd-value { font-size: 14px; font-weight: 600; color: var(--text); line-height: 1.2; }
    .rides-section{padding:20px 28px;flex:1;}
    .sec-title{font-family:'Playfair Display',serif;font-size:24px;color:var(--text);display:flex;align-items:center;gap:12px;}
    .sec-title::after{content:'';flex:1;height:1px;background:linear-gradient(90deg,var(--gold),transparent);}
    .rides-list{display:flex;flex-direction:column;gap:10px;}
    .ride-item{background:var(--card);backdrop-filter:blur(16px);border:1px solid var(--border);border-radius:14px;padding:18px 22px;display:grid;grid-template-columns:1fr auto;gap:8px;transition:0.3s;box-shadow:0 2px 10px rgba(0,0,0,0.2);}
    .ride-item:hover{border-color:rgba(212,168,67,0.4);}
    .ri-route{font-size:14px;font-weight:500;color:var(--text);}
    .ri-meta{font-size:12px;color:var(--muted);}
    .ri-fare{font-family:'Playfair Display',serif;font-size:22px;color:var(--gold);font-weight:700;}
    .status-pill{display:inline-block;padding:3px 10px;border-radius:12px;font-size:11px;background:rgba(255,255,255,0.05);}
    .s-pending{color:#F0C96A;background:rgba(240,201,106,0.1);}.s-accepted{color:#34D399;background:rgba(52,211,153,0.1);}.s-completed{color:#A78BFA;background:rgba(167,139,250,0.1);}.s-declined,.s-cancelled{color:#F87171;background:rgba(248,113,113,0.1);}

    /* Map Container */
    #mapContainer {
        height: 350px;
        margin: 24px 0 0 0;
        border-radius: 16px;
        border: 1px solid var(--border);
        z-index: 1; /* Keep map below modals/overlays */
    }
    .leaflet-container {
        font-family: 'Outfit', sans-serif;
    }
    .leaflet-control-zoom {
        border: 1px solid var(--border) !important;
    }
    .leaflet-control-zoom a {
        background: rgba(15,15,20,0.8) !important;
        color: var(--gold) !important;
        backdrop-filter: blur(10px);
    }
    .leaflet-control-zoom a:hover {
        background: rgba(212,168,67,0.1) !important;
    }

    /* GPS Module – compact */
    .gps-module{background:var(--card);backdrop-filter:blur(16px);border:1px solid var(--border);border-radius:16px;padding:20px 24px;margin:16px 28px;}
    .gps-header{display:flex;align-items:center;gap:10px;margin-bottom:18px;}
    .gps-icon-wrap{
        width:34px;height:34px;
        background:rgba(212,168,67,0.1);
        border:1px solid rgba(212,168,67,0.3);
        border-radius:8px;
        display:flex;align-items:center;justify-content:center;
        flex-shrink:0;
    }
    .gps-icon-wrap svg{
        width:18px;height:18px;
        stroke:var(--gold);
        fill:none;
        stroke-width:2;
        stroke-linecap:round;
        stroke-linejoin:round;
    }
    .gps-title{font-size:13px;font-weight:600;color:var(--gold);text-transform:uppercase;}
    .gps-subtitle{font-size:11px;color:var(--muted);margin-top:3px;}
    .gps-status-badge{margin-left:auto;font-size:10px;font-weight:700;text-transform:uppercase;padding:3px 10px;border-radius:20px;border:1px solid;display:flex;align-items:center;gap:5px;white-space:nowrap;}
    .gps-status-badge.idle{color:var(--muted);border-color:var(--border);background:rgba(0,0,0,0.2);}
    .gps-status-badge.locating{color:var(--gold);border-color:rgba(212,168,67,0.3);background:rgba(212,168,67,0.08);}
    .gps-status-badge.live{color:#34D399;border-color:rgba(52,211,153,0.3);background:rgba(52,211,153,0.08);}
    .gps-status-badge.error{color:#F87171;border-color:rgba(248,113,113,0.3);background:rgba(248,113,113,0.08);}
    .gps-pulse{width:7px;height:7px;border-radius:50%;background:currentColor;display:inline-block;}
    .gps-pulse.animate{animation:gpsPulse 1.4s ease-in-out infinite;}
    @keyframes gpsPulse{0%,100%{opacity:1;transform:scale(1);}50%{opacity:0.4;transform:scale(0.7);}}
    .gps-data{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px;}
    .gps-data-item{background:rgba(255,255,255,0.03);border:1px solid var(--border);border-radius:10px;padding:12px 14px;}
    .gps-data-label{font-size:10px;color:var(--muted);text-transform:uppercase;margin-bottom:5px;}
    .gps-data-value{font-size:15px;font-weight:600;color:var(--text);}
    .gps-data-value.placeholder{color:var(--muted);font-weight:400;}
    .gps-data-full{grid-column:1 / -1;}
    .gps-address-row{
        background:rgba(212,168,67,0.03);
        border:1px solid rgba(212,168,67,0.15);
        border-radius:10px;
        padding:10px 14px;
        display:flex;align-items:flex-start;gap:10px;
        margin-bottom:14px;
    }
    .gps-address-icon{font-size:14px;color:var(--gold);margin-top:2px;}
    .gps-address-text{font-size:12px;color:var(--text);line-height:1.5;}
    .gps-address-text.placeholder{color:var(--muted);font-style:italic;}
    .gps-actions{display:flex;gap:8px;}
    .gps-btn{
        flex:1;padding:10px 14px;border-radius:10px;
        font-size:12px;font-weight:600;cursor:pointer;
        border:none;transition:0.2s;
        display:flex;align-items:center;justify-content:center;gap:6px;
    }
    .gps-btn-primary{background:linear-gradient(135deg,var(--gold3),var(--gold));color:var(--black);}
    .gps-btn-primary:hover{filter:brightness(1.1);transform:translateY(-1px);}
    .gps-btn-primary:disabled{opacity:0.45;cursor:not-allowed;transform:none;}
    .gps-btn-secondary{background:rgba(255,255,255,0.03);border:1px solid var(--border);color:var(--muted);}
    .gps-btn-secondary:hover{background:rgba(255,255,255,0.06);color:var(--text);}
    .gps-accuracy-bar-wrap{margin-top:14px;display:none;}
    .gps-accuracy-bar-wrap.visible{display:block;}
    .gps-accuracy-label-row{display:flex;justify-content:space-between;font-size:10px;color:var(--muted);text-transform:uppercase;margin-bottom:5px;}
    .gps-accuracy-track{height:3px;background:rgba(255,255,255,0.08);border-radius:10px;overflow:hidden;}
    .gps-accuracy-fill{height:100%;border-radius:10px;background:var(--gold);transition:width 0.6s ease;}
    .gps-timestamp{margin-top:10px;font-size:10px;color:var(--muted);text-align:right;}

    /* Destination Chips */
    .destination-bar{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:20px;}
    .dest-chip{background:var(--card);border:1px solid var(--border);border-radius:30px;padding:6px 14px;font-size:12px;color:var(--muted);cursor:pointer;transition:0.2s;display:flex;align-items:center;gap:5px;}
    .dest-chip i{font-size:0.8rem;color:var(--gold);}
    .dest-chip:hover{border-color:var(--gold);color:var(--gold);transform:translateY(-1px);box-shadow:0 2px 8px rgba(212,168,67,0.15);}

    /* Modals */
    .modal-overlay{position:fixed;inset:0;z-index:800;background:rgba(0,0,0,0.6);backdrop-filter:blur(6px);display:none;align-items:center;justify-content:center;}
    .modal-overlay.open{display:flex;animation:fadeIn 0.3s ease;}
    @keyframes fadeIn{from{opacity:0}}
    .modal{background:rgba(15,15,20,0.98);border:1px solid var(--border);border-radius:24px;padding:32px;width:500px;max-width:95vw;box-shadow:0 30px 60px rgba(0,0,0,0.5);animation:slideUp 0.4s ease;}
    @keyframes slideUp{from{transform:translateY(40px);opacity:0}}
    .modal h2{font-family:'Playfair Display',serif;font-size:26px;color:var(--text);margin-bottom:20px;}
    .modal p,.modal li{color:var(--text);line-height:1.6;margin-bottom:12px;}
    .modal-actions{display:flex;gap:12px;margin-top:24px;justify-content:flex-end;}
    .btn-ghost{background:transparent;border:1px solid var(--border);border-radius:30px;padding:10px 24px;color:var(--muted);cursor:pointer;transition:0.2s;}
    .btn-ghost:hover{border-color:var(--gold);color:var(--gold);}

    /* Footer */
    .footer{background:rgba(6,6,10,0.96);backdrop-filter:blur(12px);border-top:1px solid var(--border);padding:30px 28px;text-align:center;font-size:13px;color:var(--muted);}
    .footer a{color:var(--gold);text-decoration:none;margin:0 10px;}
    .footer a:hover{text-decoration:underline;}

    @media(max-width:900px){.page{grid-template-columns:1fr;}.panel{position:static;height:auto;}}
</style>
</head>
<body>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-header">
    <a href="transnet.php" class="sidebar-logo"><span>TransNet X</span></a>
  </div>
  <div class="sidebar-user">
    <div class="sidebar-avatar"><?= $initial ?></div>
    <div class="sidebar-user-info">
      <h4><?= htmlspecialchars($fname) ?></h4>
      <p>Traveler</p>
    </div>
  </div>
  <nav class="sidebar-nav">
    <a href="transnet.php" class="sidebar-item"><i class="fas fa-home"></i> Portal Home</a>
    <a href="uber.php" class="sidebar-item active"><i class="fas fa-car"></i> Rides</a>
    <a href="trip.php" class="sidebar-item"><i class="fas fa-bus"></i> Trips</a>
    <a href="flight.php" class="sidebar-item"><i class="fas fa-plane"></i> Flights</a>
    <a href="rental.php" class="sidebar-item"><i class="fas fa-key"></i> Rentals</a>
    <a href="../about.php" class="sidebar-item" onclick="openAboutModal()"><i class="fas fa-info-circle"></i> About Us</a>
    <a href="../dashboard.php" class="sidebar-item"><i class="fas fa-chart-line"></i> Dashboard</a>
    <a href="../records.php" class="sidebar-item"><i class="fas fa-clipboard-list"></i> Records</a>
    <a href="../settings.php" class="sidebar-item"><i class="fas fa-cog"></i> Settings</a>
    <a href="../../index.php" class="sidebar-item logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
  </nav>
  <div class="sidebar-footer">© 2025 TransNet X<br>Version 2.1.0</div>
</aside>

<nav class="topbar">
  <div class="topbar-left">
    <a href="transnet.php" class="logo"><span>TransNet X</span><em>  UBER </em></a>
  </div>
  <div class="topbar-right">
    <a href="#" class="nav-link" onclick="openAboutModal()"><i class="fas fa-info-circle"></i> About</a>
    <a href="#" class="nav-link" onclick="openContactModal()"><i class="fas fa-envelope"></i> Contact</a>
    <div class="notif-wrapper" onclick="toggleNotif()">
      <i class="far fa-bell"></i>
      <span id="notifCount" class="notif-count">0</span>
      <div id="notifDropdown" class="notif-dropdown">
        <div id="notifList"></div>
      </div>
    </div>
    <div class="hamburger" id="hamburger" onclick="toggleSidebar()">
      <span></span><span></span><span></span>
    </div>
    <div class="user-avatar" onclick="window.location.href='../profile.php'" title="Your Profile"><?= $initial ?></div>
  </div>
</nav>

<div class="page">
  <!-- BOOKING PANEL -->
  <aside class="panel">
    <div class="hero">
      <h1>BOOK A <span>RIDE</span></h1>
      <div class="online-badge">
        <span class="pulse"></span> <?= $online ?> driver<?= $online!=1?'s':'' ?> online
      </div>
    </div>
    <?php if($msg): ?><div class="msg-box success"><?= $msg ?></div><?php endif; ?>
    <?php if($err): ?><div class="msg-box error"><?= $err ?></div><?php endif; ?>

    <form method="POST">
      <div class="form-group">
        <label class="form-label"><i class="fas fa-map-marker-alt"></i> Pickup Location</label>
        <input class="form-input" name="pickup" placeholder="Enter pickup address" required/>
      </div>
      <div class="form-group">
        <label class="form-label"><i class="fas fa-flag-checkered"></i> Drop-off Location</label>
        <input class="form-input" name="dropoff" placeholder="Enter destination" required/>
      </div>
      <label class="form-label" style="margin-bottom:8px;">Ride Type</label>
      <div class="ride-types">
        <div class="rt"><input type="radio" name="ride_type" id="rt1" value="standard" checked/>
          <label for="rt1"><div class="rt-icon"><i class="fas fa-car"></i></div><div class="rt-name">Standard</div><div class="rt-price">Base fare</div></label></div>
        <div class="rt"><input type="radio" name="ride_type" id="rt2" value="premium"/>
          <label for="rt2"><div class="rt-icon"><i class="fas fa-car-side"></i></div><div class="rt-name">Premium</div><div class="rt-price">+40%</div></label></div>
        <div class="rt"><input type="radio" name="ride_type" id="rt3" value="xl"/>
          <label for="rt3"><div class="rt-icon"><i class="fas fa-shuttle-van"></i></div><div class="rt-name">XL</div><div class="rt-price">+70%</div></label></div>
        <div class="rt"><input type="radio" name="ride_type" id="rt4" value="eco"/>
          <label for="rt4"><div class="rt-icon"><i class="fas fa-leaf"></i></div><div class="rt-name">Eco</div><div class="rt-price">-10%</div></label></div>
      </div>
      <div class="form-group">
        <label class="form-label"><i class="fas fa-pencil-alt"></i> Notes (optional)</label>
        <input class="form-input" name="notes" placeholder="Any special instructions?"/>
      </div>
      <button type="submit" name="book_ride" class="btn btn-primary">REQUEST RIDE <i class="fas fa-arrow-right"></i></button>
    </form>

    <!-- Map Container -->
    <div id="mapContainer"></div>
  </aside>

  <!-- RIGHT SIDE -->
  <div class="right-side">
    <div class="status-bar" id="statusBar">
      <div class="status-content">
        <div class="status-text">
          <h2 id="rideStatus">No active ride</h2>
          <p id="rideDetails">Book a ride to get started</p>
        </div>
        <div class="driver-info" id="driverInfo" style="display:none;">
          <img id="driverPhoto" src="../../uploads/first.jpg" alt="driver car">
          <div class="driver-details">
            <div class="dd-item">
              <div class="dd-icon"><i class="fas fa-user"></i></div>
              <div class="dd-text">
                <span class="dd-label">Driver</span>
                <span class="dd-value" id="driverName"></span>
              </div>
            </div>
            <div class="dd-item">
              <div class="dd-icon"><i class="fas fa-car"></i></div>
              <div class="dd-text">
                <span class="dd-label">Vehicle</span>
                <span class="dd-value" id="driverCar"></span>
              </div>
            </div>
            <div class="dd-item">
              <div class="dd-icon"><i class="fas fa-id-card"></i></div>
              <div class="dd-text">
                <span class="dd-label">Plate No.</span>
                <span class="dd-value" id="driverPlate"></span>
              </div>
            </div>
          </div>
        </div>
        <button id="completeBtn" class="btn btn-primary" style="display:none; width:auto; padding:8px 20px;">COMPLETE RIDE</button>
      </div>
    </div>

    <!-- GPS Module (compact) -->
    <div class="gps-module" id="gpsModule">
      <div class="gps-header">
        <div class="gps-icon-wrap">
          <svg viewBox="0 0 24 24"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/><circle cx="12" cy="9" r="2.5"/></svg>
        </div>
        <div>
          <div class="gps-title">GPS Location</div>
          <div class="gps-subtitle">Live Device Position</div>
        </div>
        <div class="gps-status-badge idle" id="gpsStatusBadge">
          <span class="gps-pulse" id="gpsPulseDot"></span>
          <span id="gpsStatusText">Idle</span>
        </div>
      </div>
      <div class="gps-data">
        <div class="gps-data-item"><div class="gps-data-label">Latitude</div><div class="gps-data-value placeholder" id="gpsLat">—</div></div>
        <div class="gps-data-item"><div class="gps-data-label">Longitude</div><div class="gps-data-value placeholder" id="gpsLng">—</div></div>
        <div class="gps-data-item"><div class="gps-data-label">Altitude (m)</div><div class="gps-data-value placeholder" id="gpsAlt">—</div></div>
        <div class="gps-data-item"><div class="gps-data-label">Speed (km/h)</div><div class="gps-data-value placeholder" id="gpsSpeed">—</div></div>
      </div>
      <div class="gps-address-row">
        <i class="fas fa-map-marker-alt gps-address-icon"></i>
        <div class="gps-address-text placeholder" id="gpsAddress">Address will appear after location is fetched</div>
      </div>
      <div class="gps-actions">
        <button class="gps-btn gps-btn-primary" id="gpsFetchBtn" onclick="gpsGetLocation()">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/></svg>
          Get My Location
        </button>
        <button class="gps-btn gps-btn-secondary" id="gpsUseBtn" onclick="gpsUseAddress()" disabled><i class="fas fa-map-marker-alt"></i> Use as Pickup</button>
      </div>
      <div class="gps-accuracy-bar-wrap" id="gpsAccuracyWrap">
        <div class="gps-accuracy-label-row">
          <span>Signal Accuracy</span>
          <span id="gpsAccuracyText">—</span>
        </div>
        <div class="gps-accuracy-track"><div class="gps-accuracy-fill" id="gpsAccuracyFill" style="width:0%"></div></div>
      </div>
      <div class="gps-timestamp" id="gpsTimestamp"></div>
    </div>

    <div class="rides-section">
      <div class="sec-title"><i class="fas fa-history"></i> My Rides</div>
      <div class="rides-list">
        <?php if(empty($my_rides)): ?>
          <div style="text-align:center;padding:30px;color:var(--muted);font-size:13px;">No rides yet. Book your first ride! <i class="fas fa-car"></i></div>
        <?php else: ?>
          <?php foreach($my_rides as $i=>$r): ?>
            <div class="ride-item" style="animation-delay:<?= $i*.05 ?>s">
              <div>
                <div class="ri-route"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($r['pickup_location']) ?> → <i class="fas fa-flag-checkered"></i> <?= htmlspecialchars($r['dropoff_location']) ?></div>
                <div class="ri-meta">
                  <?= ucfirst($r['ride_type']) ?> · <?= date('M d, H:i', strtotime($r['created_at'])) ?>
                  <?php if(!empty($r['driver_name'])): ?>
                    · Driver: <strong><?= htmlspecialchars($r['driver_name']) ?></strong> (<?= htmlspecialchars($r['plate_number']) ?>)
                  <?php endif; ?>
                </div>
              </div>
              <div style="text-align:right">
                <div class="ri-fare">₦<?= number_format($r['fare'],0) ?></div>
                <span class="status-pill s-<?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Modals -->
<div class="modal-overlay" id="aboutModal">
  <div class="modal">
    <h2><i class="fas fa-info-circle"></i> About TransRide</h2>
    <p>TransRide is the ride-hailing arm of TransNet X, connecting you with reliable drivers across Nigeria.</p>
    <p><strong>How to book:</strong></p>
    <ul>
      <li>Enter your pickup and drop-off locations.</li>
      <li>Choose your ride type and any notes.</li>
      <li>Request a ride—we’ll match you with a driver instantly.</li>
    </ul>
    <p><strong>Our services:</strong></p>
    <ul>
      <li>Real‑time driver assignment & GPS tracking.</li>
      <li>Multiple ride options (Standard, Premium, XL, Eco).</li>
      <li>Secure payments & 24/7 support.</li>
    </ul>
    <div class="modal-actions"><button class="btn-ghost" onclick="closeAboutModal()">Close</button></div>
  </div>
</div>

<div class="modal-overlay" id="contactModal">
  <div class="modal">
    <h2><i class="fas fa-envelope"></i> Contact Us</h2>
    <p><i class="fas fa-phone-alt"></i> +234 912 417 524 9</p>
    <p><i class="fas fa-envelope"></i> @transnetx.com</p>
    <p><i class="fas fa-map-marker-alt"></i> Tambari Housing Estate MBC3 Transit X Hub, Bauchi , Bauchi State</p>
    <div class="modal-actions"><button class="btn-ghost" onclick="closeContactModal()">Close</button></div>
  </div>
</div>

<div class="footer">
  <p>© <?= date('Y') ?> TransNet X. All rights reserved.</p>
  <p>
    <a href="../about.php" onclick="openAboutModal()">About Us</a> | 
    <a href="#" onclick="openContactModal()">Contact Us</a> | 
    <a href="#">Privacy Policy</a> | 
    <a href="#">Terms of Service</a>
  </p>
</div>

<script>
    // ---------- Sidebar ----------
    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('open');
        document.getElementById('sidebarOverlay').classList.toggle('show');
        document.getElementById('hamburger').classList.toggle('open');
    }

    // ---------- Notifications ----------
    function loadNotifications() {
        fetch('uber.php?action=notifications')
            .then(res => res.json())
            .then(data => {
                const list = document.getElementById("notifList");
                const countSpan = document.getElementById("notifCount");
                list.innerHTML = "";
                let unread = data.length;
                if (data.length === 0) {
                    const emptyItem = document.createElement("div");
                    emptyItem.className = "notif-item";
                    emptyItem.innerText = "No new notifications";
                    list.appendChild(emptyItem);
                } else {
                    data.forEach(n => {
                        const item = document.createElement("div");
                        item.className = "notif-item";
                        item.innerHTML = `${n.message}<br><small>${n.pickup_location} → ${n.dropoff_location}</small>`;
                        list.appendChild(item);
                    });
                }
                countSpan.innerText = unread;
                countSpan.style.display = unread ? "inline-block" : "none";
            });
    }

    function markNotificationsRead() {
        fetch('uber.php?action=mark').then(() => loadNotifications());
    }

    function toggleNotif() {
        const box = document.getElementById("notifDropdown");
        if (box.style.display === "flex") {
            box.style.display = "none";
        } else {
            box.style.display = "flex";
            markNotificationsRead();
        }
    }

    // ---------- Ride Status ----------
    function loadRideStatus() {
        fetch('uber.php?action=status')
            .then(res => res.json())
            .then(data => {
                const statusText = document.getElementById("rideStatus");
                const details = document.getElementById("rideDetails");
                const driverBox = document.getElementById("driverInfo");
                const completeBtn = document.getElementById("completeBtn");
                const driverName = document.getElementById("driverName");
                const driverCar = document.getElementById("driverCar");
                const driverPlate = document.getElementById("driverPlate");
                const driverPhoto = document.getElementById("driverPhoto");

                driverBox.style.display = "none";
                completeBtn.style.display = "none";

                if (!data || !data.status) {
                    statusText.innerHTML = "No active ride";
                    details.innerText = "Book a ride to get started";
                    return;
                }

                if (data.status === "pending") {
                    statusText.innerHTML = '<i class="fas fa-hourglass-half"></i> Request Sent';
                    details.innerText = "Waiting for driver...";
                }
                if (data.status === "accepted") {
                    statusText.innerHTML = '<i class="fas fa-car"></i> Driver On The Way';
                    details.innerText = "Driver is coming to your location";
                    if (data.name) {
                        driverBox.style.display = "flex";
                        completeBtn.style.display = "block";
                        driverName.innerText = data.name || "N/A";
                        driverCar.innerText = data.vehicle_model || "N/A";
                        driverPlate.innerText = data.plate_number || "N/A";
                        driverPhoto.src = data.vehicle_photo || "../../uploads/default-car.png";
                    }
                }
                if (data.status === "completed") {
                    statusText.innerHTML = '<i class="fas fa-check-circle"></i> Ride Completed';
                    details.innerText = "Thanks for riding with us!";
                }
                if (data.status === "declined") {
                    statusText.innerHTML = '<i class="fas fa-times-circle"></i> Ride Declined';
                    details.innerText = "Please try booking again";
                }
            });
    }

    document.getElementById("completeBtn").onclick = () => {
        if (confirm("Mark this ride as completed?")) {
            fetch('uber.php?action=complete').then(() => location.reload());
        }
    };

    // ---------- GPS ----------
    (function () {
        var gpsData = { lat: null, lng: null, alt: null, speed: null, accuracy: null, address: null };
        var watchId = null;
        var map = null;
        var marker = null;

        // Initialize Map
        map = L.map('mapContainer').setView([9.0820, 8.6753], 6); // Default to Nigeria
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '© OpenStreetMap'
        }).addTo(map);

        // Custom icon for marker
        var carIcon = L.divIcon({
            html: '<div style="background:var(--gold);width:16px;height:16px;border-radius:50%;border:3px solid var(--black);box-shadow:0 0 10px var(--gold);"></div>',
            className: '',
            iconSize: [22, 22],
            iconAnchor: [11, 11]
        });

        map.on('click', function(e) {
            var lat = e.latlng.lat;
            var lng = e.latlng.lng;
            updateMapLocation(lat, lng, true);
        });

        function updateMapLocation(lat, lng, fromClick) {
            if (!marker) {
                marker = L.marker([lat, lng], {icon: carIcon}).addTo(map);
            } else {
                marker.setLatLng([lat, lng]);
            }
            map.setView([lat, lng], 16);
            if (fromClick) {
                gpsData.lat = lat;
                gpsData.lng = lng;
                setField('gpsLat', lat.toFixed(6), false);
                setField('gpsLng', lng.toFixed(6), false);
                reverseGeocode(lat, lng);
            }
        }

        function el(id){ return document.getElementById(id); }
        function setStatus(state, text) {
            var badge = el('gpsStatusBadge');
            var dot = el('gpsPulseDot');
            var label = el('gpsStatusText');
            badge.className = 'gps-status-badge ' + state;
            label.textContent = text;
            if (state === 'locating' || state === 'live') dot.classList.add('animate');
            else dot.classList.remove('animate');
        }
        function setField(id, value, isPlaceholder) {
            var node = el(id);
            node.textContent = value;
            node.className = 'gps-data-value' + (isPlaceholder ? ' placeholder' : '');
        }
        function setAddress(text, isPlaceholder) {
            var node = el('gpsAddress');
            node.textContent = text;
            node.className = 'gps-address-text' + (isPlaceholder ? ' placeholder' : '');
        }
        function updateAccuracyBar(meters) {
            el('gpsAccuracyWrap').classList.add('visible');
            var pct = meters <= 10 ? 100 : meters <= 50 ? 75 : meters <= 200 ? 45 : 20;
            el('gpsAccuracyFill').style.width = pct + '%';
            el('gpsAccuracyText').textContent = Math.round(meters) + ' m';
        }
        function reverseGeocode(lat, lng) {
            setAddress('Fetching address...', true);
            fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${lat}&lon=${lng}&zoom=16&addressdetails=1`, { headers: { 'Accept-Language': 'en' } })
                .then(r => r.json())
                .then(data => {
                    var addr = data.display_name || 'Unknown address';
                    if (addr.length > 120) addr = addr.substring(0, 117) + '…';
                    gpsData.address = addr;
                    setAddress(addr, false);
                    el('gpsUseBtn').disabled = false;
                })
                .catch(() => { setAddress('Could not resolve address', true); el('gpsUseBtn').disabled = true; });
        }
        function onPosition(pos) {
            var c = pos.coords;
            gpsData.lat = c.latitude;
            gpsData.lng = c.longitude;
            gpsData.alt = c.altitude;
            gpsData.speed = c.speed;
            gpsData.accuracy = c.accuracy;
            setStatus('live', 'Live');
            setField('gpsLat', c.latitude.toFixed(6), false);
            setField('gpsLng', c.longitude.toFixed(6), false);
            setField('gpsAlt', c.altitude !== null ? c.altitude.toFixed(1) : 'N/A', c.altitude === null);
            setField('gpsSpeed', c.speed !== null ? (c.speed * 3.6).toFixed(1) : 'N/A', c.speed === null);
            updateAccuracyBar(c.accuracy);
            reverseGeocode(c.latitude, c.longitude);
            updateMapLocation(c.latitude, c.longitude, false);
            el('gpsTimestamp').textContent = 'Updated: ' + new Date().toLocaleTimeString();
            el('gpsFetchBtn').disabled = false;
            el('gpsFetchBtn').innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/></svg> Refresh';
        }
        function onError(err) {
            var msg = {1:'Permission denied',2:'Position unavailable',3:'Request timed out'}[err.code] || 'Unknown error';
            setStatus('error', msg);
            setAddress('Location access failed — ' + msg, true);
            el('gpsUseBtn').disabled = true;
            el('gpsFetchBtn').disabled = false;
            el('gpsFetchBtn').innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/></svg> Retry';
        }
        window.gpsGetLocation = function () {
            if (!navigator.geolocation) {
                setStatus('error', 'Not supported');
                setAddress('Geolocation not supported by your browser.', true);
                return;
            }
            setStatus('locating', 'Locating…');
            el('gpsFetchBtn').disabled = true;
            el('gpsFetchBtn').innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/></svg> Locating…';
            if (watchId !== null) navigator.geolocation.clearWatch(watchId);
            watchId = navigator.geolocation.watchPosition(onPosition, onError, {
                enableHighAccuracy: true, timeout: 15000, maximumAge: 0
            });
        };
        window.gpsUseAddress = function () {
            if (!gpsData.address) return;
            var pickupInput = document.querySelector('input[name="pickup"]');
            if (pickupInput) {
                pickupInput.value = gpsData.address;
                pickupInput.dispatchEvent(new Event('input', { bubbles: true }));
            }
        };
    })();

    // ---------- Modals ----------
    function openAboutModal(){ document.getElementById('aboutModal').classList.add('open'); }
    function closeAboutModal(){ document.getElementById('aboutModal').classList.remove('open'); }
    function openContactModal(){ document.getElementById('contactModal').classList.add('open'); }
    function closeContactModal(){ document.getElementById('contactModal').classList.remove('open'); }
    window.addEventListener('click', function(e){
        if (e.target.classList.contains('modal-overlay')) {
            e.target.classList.remove('open');
        }
    });

    // ---------- Periodic Updates ----------
    setInterval(loadRideStatus, 4000);
    setInterval(loadNotifications, 10000);
    loadRideStatus();
    loadNotifications();
</script>
</body>
</html>