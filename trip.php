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
   1. BOOK A SEAT ON AN EXISTING TRIP
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book_seat'])) {
    $trip_id = (int) $_POST['trip_id'];
    $seat    = trim($_POST['seat_number']);

    if (empty($seat)) {
        $err = '<i class="fas fa-exclamation-triangle"></i> Please enter a seat number.';
    } else {
        $stmt = mysqli_prepare($conn, "SELECT * FROM trips WHERE id = ? AND status = 'confirmed' AND available_seats > 0");
        mysqli_stmt_bind_param($stmt, "i", $trip_id);
        mysqli_stmt_execute($stmt);
        $trip = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        if (!$trip) {
            $err = '<i class="fas fa-ban"></i> Trip not available or sold out.';
        } else {
            $insert = mysqli_prepare($conn, "
                INSERT INTO trip_bookings 
                (trip_id, user_id, from_city, to_city, departure_date, departure_time,
                 seat_number, seats_booked, total_price, status,
                 transport_type, source_type)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, 'confirmed', ?, 'trip')
            ");
            $price     = $trip['price_per_seat'];
            $transport = $trip['transport_type'];
            mysqli_stmt_bind_param($insert, "iisssssds",
                $trip_id, $uid,
                $trip['from_city'], $trip['to_city'],
                $trip['departure_date'], $trip['departure_time'],
                $seat, $price, $transport
            );

            if (mysqli_stmt_execute($insert)) {
                $upd = mysqli_prepare($conn, "UPDATE trips SET available_seats = available_seats - 1 WHERE id = ?");
                mysqli_stmt_bind_param($upd, "i", $trip_id);
                mysqli_stmt_execute($upd);
                $msg = '<i class="fas fa-check-circle"></i> Seat ' . htmlspecialchars($seat) . ' booked successfully!';
            } else {
                $err = '<i class="fas fa-times-circle"></i> Booking failed. Please try again.';
            }
        }
    }
}

/* ============================================================
   2. SUBMIT CUSTOM TRIP REQUEST
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_trip'])) {
    $type  = $_POST['transport_type'];
    $from  = trim($_POST['from_city']);
    $to    = trim($_POST['to_city']);
    $date  = $_POST['departure_date'];
    $time  = $_POST['departure_time'];
    $seat  = trim($_POST['seat_number'] ?? '');

    if ($type && $from && $to && $date && $time) {
        $stmt = mysqli_prepare($conn, "
            INSERT INTO trip_bookings 
            (trip_id, user_id, from_city, to_city, departure_date, departure_time,
             seat_number, seats_booked, total_price, status,
             transport_type, source_type)
            VALUES (NULL, ?, ?, ?, ?, ?, ?, 1, 0, 'pending', ?, 'request')
        ");
        mysqli_stmt_bind_param($stmt, "issssss", $uid, $from, $to, $date, $time, $seat, $type);
        mysqli_stmt_execute($stmt);
        $msg = '<i class="fas fa-paper-plane"></i> Trip request submitted! Waiting for admin approval.';
    } else {
        $err = '<i class="fas fa-exclamation-circle"></i> Please fill all required fields.';
    }
}

/* ============================================================
   3. FETCH AVAILABLE TRIPS
   ============================================================ */
$stmt = mysqli_prepare($conn, "
    SELECT * FROM trips 
    WHERE status = 'confirmed' AND available_seats > 0
    ORDER BY departure_date ASC
");
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$availableTrips = [];
while ($row = mysqli_fetch_assoc($res)) {
    $availableTrips[] = $row;
}

/* ============================================================
   4. FETCH MY TRIPS
   ============================================================ */
$stmt = mysqli_prepare($conn, "
    SELECT * FROM trip_bookings 
    WHERE user_id = ?
    ORDER BY created_at DESC
");
mysqli_stmt_bind_param($stmt, "i", $uid);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$myTrips = [];
while ($row = mysqli_fetch_assoc($res)) {
    $myTrips[] = $row;
}

/* ============================================================
   5. NOTIFICATIONS
   ============================================================ */
$notifStmt = mysqli_prepare($conn, "
    SELECT id, status, from_city, to_city, departure_date, source_type, transport_type, updated_at
    FROM trip_bookings
    WHERE user_id = ?
    ORDER BY updated_at DESC
    LIMIT 5
");
mysqli_stmt_bind_param($notifStmt, "i", $uid);
mysqli_stmt_execute($notifStmt);
$notifRes = mysqli_stmt_get_result($notifStmt);
$notifications = [];
while ($n = mysqli_fetch_assoc($notifRes)) {
    $notifications[] = $n;
}
$countPending = 0;
foreach ($notifications as $n) {
    if ($n['status'] === 'pending') $countPending++;
}

function getTransportIcon($type) {
    switch ($type) {
        case 'bus': return '<i class="fas fa-bus"></i>';
        case 'flight': return '<i class="fas fa-plane"></i>';
        case 'train': return '<i class="fas fa-train"></i>';
        case 'sea': return '<i class="fas fa-ship"></i>';
        default: return '<i class="fas fa-bus"></i>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width,initial-scale=1"/>
    <title>TransTrip — TransNet X</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com"/>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Outfit:wght@300;400;500;600;700&family=Playfair+Display:wght@600;700;800&display=swap" rel="stylesheet"/>
    <style>
        /* ========== DARK THEME — GOLD ACCENT (matches the original gold theme) ========== */
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
            --uber:#34D399;
            --trip:#60A5FA;
            --flight:#A78BFA;
            --rental:#FB923C;
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
                radial-gradient(circle at 20% 30%, rgba(212,168,67,0.05) 0%, transparent 50%),
                radial-gradient(circle at 80% 70%, rgba(212,168,67,0.04) 0%, transparent 50%);
            pointer-events:none;z-index:0;
        }

        .plane-visual{
            position:relative;
            display:flex;
            justify-content:center;
            align-items:center;
            padding:40px 20px;
            perspective:1200px;
        }

        .plane-visual::before{
            content:'';
            position:absolute;
            width:380px;
            height:380px;
            border-radius:50%;
            background:radial-gradient(circle,
                rgba(212,168,67,0.22),
                transparent 70%);
            filter:blur(20px);
            z-index:0;
        }

        .plane-visual img{
            position:relative;
            z-index:2;
            width:100%;
            max-width:360px;
            border-radius:32px;
            object-fit:cover;
            transform:rotateY(-8deg) rotateX(3deg);
            transition:all .5s ease;
            border:1px solid rgba(255,255,255,0.08);
            box-shadow:
                0 25px 60px rgba(0,0,0,0.45),
                0 0 40px rgba(212,168,67,0.18);
            background:rgba(255,255,255,0.04);
            backdrop-filter:blur(14px);
            padding:14px;
        }

        .plane-visual img:hover{
            transform:rotateY(0deg) rotateX(0deg)
                      translateY(-10px) scale(1.04);
            box-shadow:
                0 35px 80px rgba(0,0,0,0.55),
                0 0 60px rgba(212,168,67,0.3);
        }

        /* Sidebar */
        .sidebar {
            position:fixed;left:-280px;top:0;width:280px;height:100vh;
            background:rgba(6,6,10,0.98);backdrop-filter:blur(20px);
            border-right:1px solid var(--border);z-index:1000;
            transition:left 0.4s cubic-bezier(0.16,1,0.3,1);
            padding:24px 0;display:flex;flex-direction:column;
            box-shadow:5px 0 25px rgba(0,0,0,0.4);
        }
        .sidebar.open {left:0;}
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
                rgba(96,165,250,1),
                rgba(59,130,246,1)
            );
            -webkit-background-clip:text;
            -webkit-text-fill-color:transparent;
            background-clip:text;
            text-shadow:
                0 0 10px rgba(96,165,250,.35),
                0 0 20px rgba(96,165,250,.15);
        }
        .sidebar-logo:hover span{
            filter:brightness(1.15);
            letter-spacing:4px;
            transition:.3s ease;
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
                rgba(96,165,250,1),
                rgba(59,130,246,1)
            );
            -webkit-background-clip:text;
            -webkit-text-fill-color:transparent;
            background-clip:text;
            text-shadow:
                0 0 12px rgba(96,165,250,.35),
                0 0 25px rgba(96,165,250,.18);
            transition:all .3s ease;
        }

        .logo em{
            font-style:normal;
            font-size:11px;
            letter-spacing:3px;
            color:rgba(96,165,250,.65);
            -webkit-text-fill-color:rgba(96,165,250,.65);
            text-shadow:0 0 10px rgba(96,165,250,.2);
        }

        .logo:hover span{
            filter:brightness(1.15);
            letter-spacing:3px;
        }

        .logo:hover em{
            color:rgba(96,165,250,.9);
            -webkit-text-fill-color:rgba(96,165,250,.9);
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

        /* Page */
        .page{position:relative;z-index:1;padding-top:72px;max-width:1200px;margin:0 auto;padding-left:28px;padding-right:28px;padding-bottom:60px;}

        /* Hero Section */
        .hero-section {
            position: relative;
            width: 100%;
            min-height: 85vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 140px 20px 80px;
            z-index: 0;
        }
        .hero-bg {
            position: absolute;
            top: 0; left: 0; width: 100%; height: 100%;
            background: radial-gradient(circle at top, rgba(6,6,10,0.8), var(--black));
            z-index: -2;
        }
        .hero-overlay {
            position: absolute;
            top: 0; left: 0; width: 100%; height: 100%;
            background: url('scenic_trip_bg.png') center/cover no-repeat;
            opacity: 0.1;
            z-index: -1;
        }
        .transport-visuals {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-bottom: 40px;
            z-index: 2;
            flex-wrap: wrap;
        }
        .transport-visuals img {
            width: 200px;
            height: 140px;
            object-fit: cover;
            border-radius: 20px;
            border: 1px solid rgba(255,255,255,0.08);
            box-shadow: 0 15px 35px rgba(0,0,0,0.4);
            transition: 0.4s;
            background: rgba(255,255,255,0.04);
            backdrop-filter: blur(14px);
            padding: 10px;
        }
        .transport-visuals img:hover {
            transform: translateY(-8px) scale(1.03);
            box-shadow: 0 25px 50px rgba(0,0,0,0.5), 0 0 30px rgba(212,168,67,0.15);
            border-color: rgba(212,168,67,0.3);
        }
        .hero-content {
            text-align: center;
            margin-bottom: 50px;
            z-index: 1;
            animation: fadeUp 1s ease both;
        }
        .hero-eyebrow{font-size:14px;letter-spacing:5px;text-transform:uppercase;color:var(--gold);margin-bottom:16px;font-weight:600;}
        .hero-content h1{font-family:'Playfair Display',serif;font-size:clamp(40px,6vw,72px);font-weight:800;line-height:1.1;margin-bottom:20px;color:var(--text);}
        .hero-content h1 span{color:var(--gold);}
        .hero-content p{color:rgba(255,255,255,0.9);font-size:18px;font-weight:300;max-width:650px;margin:0 auto;}

        /* Booking Widget */
        .booking-widget {
            background: rgba(15, 15, 20, 0.65);
            backdrop-filter: blur(24px);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 24px;
            padding: 32px;
            width: 100%;
            max-width: 1050px;
            z-index: 2;
            box-shadow: 0 30px 60px rgba(0,0,0,0.6);
            animation: fadeUp 1.2s ease both;
        }
        .bw-tabs {
            display: flex;
            gap: 30px;
            margin-bottom: 24px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            padding-bottom: 16px;
        }
        .bw-tab {
            color: var(--muted);
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            position: relative;
            padding-bottom: 16px;
            transition: 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .bw-tab i { font-size: 1.1rem; }
        .bw-tab.active {
            color: var(--gold);
        }
        .bw-tab.active::after {
            content: '';
            position: absolute;
            bottom: -17px;
            left: 0;
            width: 100%;
            height: 2px;
            background: var(--gold);
            box-shadow: 0 0 10px var(--gold);
        }
        .bw-tab:hover {
            color: var(--gold);
        }

        .bw-form {
            display: grid;
            grid-template-columns: 1fr auto 1fr 1fr auto;
            gap: 12px;
            align-items: center;
        }
        .bw-field {
            background: rgba(6,6,10,0.6);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 16px;
            padding: 14px 20px;
            position: relative;
            transition: 0.3s;
            display: flex;
            flex-direction: column;
            justify-content: center;
            height: 72px;
        }
        .bw-field:hover, .bw-field:focus-within {
            border-color: var(--gold);
            background: rgba(212,168,67,0.05);
            box-shadow: inset 0 0 0 1px var(--gold);
        }
        .bw-label {
            font-size: 11px;
            text-transform: uppercase;
            color: var(--gold);
            margin-bottom: 6px;
            letter-spacing: 1px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .bw-input {
            background: transparent;
            border: none;
            outline: none;
            color: var(--text);
            font-size: 16px;
            font-family: inherit;
            font-weight: 500;
            width: 100%;
        }
        .bw-input[type="date"]::-webkit-calendar-picker-indicator {
            filter: invert(1);
            opacity: 0.6;
            cursor: pointer;
        }
        .bw-input::placeholder {
            color: rgba(255,255,255,0.3);
        }
        .bw-swap {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: rgba(212,168,67,0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--gold);
            cursor: pointer;
            border: 1px solid rgba(212,168,67,0.2);
            transition: 0.3s;
            margin: 0 -18px;
            z-index: 2;
        }
        .bw-swap:hover {
            background: var(--gold);
            color: var(--black);
            transform: rotate(180deg);
        }
        .bw-submit {
            background: linear-gradient(135deg, var(--gold3), var(--gold));
            border: none;
            border-radius: 16px;
            padding: 0 32px;
            height: 72px;
            color: var(--black);
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: 0.3s;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 10px 20px rgba(212,168,67,0.2);
        }
        .bw-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 30px rgba(212,168,67,0.3);
            filter: brightness(1.1);
        }

        /* Destination chips */
        .destination-bar{display:flex;align-items:center;flex-wrap:wrap;gap:10px;}
        .dest-chip{background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:30px;padding:6px 14px;font-size:13px;color:var(--text);cursor:pointer;transition:0.2s;display:flex;align-items:center;gap:6px;}
        .dest-chip:hover{border-color:var(--gold);color:var(--gold);background:rgba(212,168,67,0.1);}

        /* Sections */
        .section-header{display:flex;align-items:center;justify-content:space-between;margin:50px 0 20px;}
        .section-title{font-family:'Playfair Display',serif;font-size:28px;color:var(--text);display:flex;align-items:center;gap:12px;}
        .section-title i{color:var(--gold);}
        .section-title::after{content:'';flex:1;height:1px;background:linear-gradient(90deg,var(--gold),transparent);}

        .flights-grid{display:flex;flex-direction:column;gap:16px;animation:fadeUp 0.8s ease;}

        .trip-card{
            position:relative;background:var(--card);backdrop-filter:blur(16px);
            border:1px solid var(--border);border-radius:24px;padding:24px 28px;
            display:grid;grid-template-columns:60px 1fr auto;gap:24px;align-items:center;
            transition:0.4s;cursor:pointer;box-shadow:0 4px 20px rgba(0,0,0,0.2);
        }
        .trip-card:hover{transform:translateY(-6px);border-color:rgba(212,168,67,0.5);box-shadow:0 20px 50px rgba(0,0,0,0.5),0 0 30px rgba(212,168,67,0.15);}
        .tc-icon{font-size:32px;color:var(--gold);text-align:center;}
        .tc-route{display:flex;align-items:center;gap:16px;}
        .tc-city{font-family:'Bebas Neue',sans-serif;font-size:22px;letter-spacing:1px;color:var(--text);}
        .tc-arrow{color:var(--gold);font-size:20px;}
        .tc-meta{font-size:13px;color:var(--muted);margin-top:4px;}
        .tc-right{text-align:right;}
        .tc-price{font-family:'Playfair Display',serif;font-size:24px;color:var(--gold);font-weight:700;}
        .tc-seats{font-size:12px;color:#34D399;margin-bottom:8px;}
        .book-form{display:flex;gap:8px;align-items:center;margin-top:8px;}
        .book-input{width:80px;background:rgba(255,255,255,0.05);border:1px solid var(--border);border-radius:30px;padding:8px 12px;font-size:12px;color:var(--text);text-align:center;}
        .book-btn{background:rgba(212,168,67,0.1);border:1px solid rgba(212,168,67,0.3);border-radius:30px;padding:8px 16px;color:var(--gold);font-weight:600;font-size:12px;cursor:pointer;transition:0.3s;}
        .book-btn:hover{background:var(--gold);color:var(--black);}

        /* Request section */
        .request-section{margin-top:60px;background:var(--card);backdrop-filter:blur(16px);border:1px solid var(--border);border-radius:24px;padding:30px;box-shadow:0 4px 20px rgba(0,0,0,0.2);}
        .request-form{display:grid;grid-template-columns:1fr 1fr auto 1fr;gap:14px;align-items:end;}
        .form-group{display:flex;flex-direction:column;}
        .form-label{font-size:11px;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);margin-bottom:6px;}
        .form-input,.form-select{width:100%;background:rgba(6,6,10,0.8);border:1px solid var(--border);border-radius:var(--radius-sm);padding:12px 16px;color:var(--text);font-size:14px;outline:none;transition:border-color 0.2s;}
        .form-input:focus,.form-select:focus{border-color:var(--gold);}
        .form-select option{background:#06060A;color:var(--text);}
        .request-btn{padding:12px 24px;border-radius:var(--radius-sm);background:linear-gradient(135deg,var(--gold3),var(--gold));border:none;color:var(--black);font-weight:700;font-size:14px;cursor:pointer;transition:0.3s;white-space:nowrap;}
        .request-btn:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(212,168,67,0.3);}

        /* My Trips */
        .my-book-grid{display:flex;flex-direction:column;gap:10px;}
        .mb-item{background:var(--card);backdrop-filter:blur(16px);border:1px solid var(--border);border-radius:14px;padding:18px 22px;display:flex;align-items:center;gap:16px;transition:0.3s;box-shadow:0 2px 10px rgba(0,0,0,0.2);}
        .mb-item:hover{border-color:rgba(212,168,67,0.4);}
        .mb-icon{font-size:22px;color:var(--gold);}
        .mb-codes{font-family:'Bebas Neue',sans-serif;font-size:20px;letter-spacing:2px;color:var(--text);}
        .mb-info{flex:1;}
        .mb-meta{font-size:12px;color:var(--muted);margin-top:3px;}
        .mb-price{font-family:'Playfair Display',serif;font-size:20px;color:var(--gold);font-weight:700;}
        .status-pill{display:inline-block;padding:3px 10px;border-radius:12px;font-size:11px;background:rgba(255,255,255,0.05);}
        .s-pending{color:#F0C96A;background:rgba(240,201,106,0.1);}.s-confirmed{color:#34D399;background:rgba(52,211,153,0.1);}.s-cancelled{color:#F87171;background:rgba(248,113,113,0.1);}.s-completed{color:#A78BFA;background:rgba(167,139,250,0.1);}

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

        .msg-box{padding:14px 20px;border-radius:12px;margin-bottom:20px;font-size:14px;}
        .msg-box.success{background:rgba(52,211,153,0.1);border:1px solid rgba(52,211,153,0.3);color:#34D399;}
        .msg-box.error{background:rgba(248,113,113,0.1);border:1px solid rgba(248,113,113,0.3);color:#F87171;}

        @keyframes fadeUp{from{opacity:0;transform:translateY(20px)}}
        @media(max-width:900px){
            .bw-form{grid-template-columns:1fr;}
            .bw-swap{transform:rotate(90deg); margin: 0 auto;}
            .bw-swap:hover{transform:rotate(270deg);}
            .bw-submit{height: 60px;}
            .request-form{grid-template-columns:1fr;}
            .trip-card{grid-template-columns:1fr;}
        }
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
      <h4><?= htmlspecialchars($name) ?></h4>
      <p>Traveler</p>
    </div>
  </div>
  <nav class="sidebar-nav">
    <a href="transnet.php" class="sidebar-item"><i class="fas fa-home"></i> Portal Home</a>
    <a href="uber.php" class="sidebar-item"><i class="fas fa-car"></i> Uber</a>
    <a href="trip.php" class="sidebar-item active"><i class="fas fa-bus"></i> Trips</a>
    <a href="flight.php" class="sidebar-item"><i class="fas fa-plane"></i> Flights</a>
    <a href="rental.php" class="sidebar-item"><i class="fas fa-key"></i> Rentals</a>
    <a href="../about.php" class="sidebar-item"><i class="fas fa-info-circle"></i> About Us</a>
    <a href="../dashboard.php" class="sidebar-item"><i class="fas fa-chart-line"></i> Dashboard</a>
    <a href="../records.php" class="sidebar-item"><i class="fas fa-clipboard-list"></i> Records</a>
    <a href="../settings.php" class="sidebar-item"><i class="fas fa-cog"></i> Settings</a>
    <a href="../../index.php" class="sidebar-item logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
  </nav>
  <div class="sidebar-footer">© 2025 TransNet X<br>Version 2.1.0</div>
</aside>

<nav class="topbar">
  <div class="topbar-left">
    <div class="hamburger" id="hamburger" onclick="toggleSidebar()">
      <span></span><span></span><span></span>
    </div>
    <a href="transnet.php" class="logo"><span>TransNet X</span><em>  TRIPS </em></a>
  </div>
  <div class="topbar-right">
    <a href="#" class="nav-link" onclick="openAboutModal()"><i class="fas fa-info-circle"></i> About</a>
    <a href="#" class="nav-link" onclick="openContactModal()"><i class="fas fa-envelope"></i> Contact Us</a>
    <div class="notif-wrapper" onclick="toggleNotif()">
      <i class="far fa-bell"></i>
      <span id="notifCount" class="notif-count"><?= $countPending ?></span>
      <div id="notifDropdown" class="notif-dropdown"></div>
    </div>
    <div class="user-avatar" onclick="window.location.href='../profile.php'" title="Your Profile"><?= $initial ?></div>
  </div>
</nav>

<div class="hero-section">
  <div class="hero-bg"></div>
  <div class="hero-overlay"></div>
  <div class="hero-content">
    <div class="hero-eyebrow"><i class="fas fa-road"></i> TransNet Intercity · Travel in Comfort</div>
    <h1>GO THE<br><span>EXTRA MILE</span></h1>
    <p>Book reliable bus, train, and sea trips across Nigeria with instant confirmation.</p>
  </div>

  <div class="transport-visuals">
    <img src="bus.png" alt="Bus Travel">
    <img src="train.png" alt="Train Travel">
    <img src="ship.png" alt="Sea Travel">
  </div>

  <div class="booking-widget">
    <div class="bw-tabs">
      <div class="bw-tab active"><i class="fas fa-bus"></i> Find a Trip</div>
      <div class="bw-tab" onclick="document.querySelector('.request-section').scrollIntoView({behavior:'smooth'})"><i class="fas fa-pen-ruler"></i> Custom Request</div>
      <div class="bw-tab" onclick="document.querySelector('.my-book-grid').scrollIntoView({behavior:'smooth'})"><i class="fas fa-suitcase-rolling"></i> My Trips</div>
    </div>
    <div class="bw-form">
      <div class="bw-field">
        <div class="bw-label"><i class="fas fa-map-marker-alt"></i> Leaving From</div>
        <input type="text" class="bw-input" id="originInput" placeholder="City or terminal" list="cityList" autocomplete="off">
      </div>
      <div class="bw-swap" onclick="swapCities()"><i class="fas fa-exchange-alt"></i></div>
      <div class="bw-field">
        <div class="bw-label"><i class="fas fa-flag-checkered"></i> Going To</div>
        <input type="text" class="bw-input" id="destInput" placeholder="City or terminal" list="cityList" autocomplete="off">
      </div>
      <div class="bw-field">
        <div class="bw-label"><i class="far fa-calendar-alt"></i> Travel Date</div>
        <input type="date" class="bw-input" id="dateInput">
      </div>
      <button class="bw-submit" onclick="filterTrips()">
        Search Trips <i class="fas fa-arrow-right"></i>
      </button>
    </div>
    <div class="destination-bar" id="destinationBar" style="margin-top:24px; margin-bottom:0; justify-content:flex-start;">
      <span style="font-size:13px; color:var(--muted); margin-right:8px; display:flex; align-items:center;">Popular:</span>
      <?php
      $popular = ['Lagos','Abuja','Port Harcourt','Kano','Enugu','Calabar'];
      foreach($popular as $city):
      ?>
        <span class="dest-chip" onclick="setOrigin('<?= htmlspecialchars($city) ?>')">
          <?= htmlspecialchars($city) ?>
        </span>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="page" style="padding-top:40px;">
  <datalist id="cityList">
    <?php foreach($cities as $city): ?>
      <option value="<?= htmlspecialchars($city) ?>">
    <?php endforeach; ?>
  </datalist>

  <?php if($msg): ?><div class="msg-box success"><?= $msg ?></div><?php endif; ?>
  <?php if($err): ?><div class="msg-box error"><?= $err ?></div><?php endif; ?>

  <div class="section-header">
    <div class="section-title"><i class="fas fa-calendar-check"></i> Available Trips</div>
  </div>

  <div class="flights-grid" id="tripsGrid">
    <?php if(empty($availableTrips)): ?>
      <div class="empty-state" style="text-align:center;padding:60px;color:var(--muted);"><i class="fas fa-road"></i> No available trips right now.</div>
    <?php else: ?>
      <?php foreach($availableTrips as $t): ?>
        <div class="trip-card"
             data-origin-city="<?= htmlspecialchars($t['from_city']) ?>"
             data-dest-city="<?= htmlspecialchars($t['to_city']) ?>"
             data-departure-date="<?= $t['departure_date'] ?>">
          <div class="tc-icon"><?= getTransportIcon($t['transport_type']) ?></div>
          <div>
            <div class="tc-route">
              <span class="tc-city"><?= htmlspecialchars($t['from_city']) ?></span>
              <span class="tc-arrow"><i class="fas fa-arrow-right"></i></span>
              <span class="tc-city"><?= htmlspecialchars($t['to_city']) ?></span>
            </div>
            <div class="tc-meta">
              <?= date('M d, Y', strtotime($t['departure_date'])) ?> at <?= substr($t['departure_time'],0,5) ?>
              · <?= ucfirst($t['transport_type']) ?>
            </div>
          </div>
          <div class="tc-right">
            <div class="tc-price">₦<?= number_format($t['price_per_seat']) ?></div>
            <div class="tc-seats"><i class="fas fa-chair"></i> <?= $t['available_seats'] ?> seats left</div>
            <form method="POST" class="book-form">
              <input type="hidden" name="trip_id" value="<?= $t['id'] ?>">
              <input type="text" name="seat_number" placeholder="Seat No." required class="book-input">
              <button type="submit" name="book_seat" class="book-btn"><i class="fas fa-ticket-alt"></i> Book</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div class="request-section">
    <div class="section-header">
      <div class="section-title"><i class="fas fa-pen-ruler"></i> Request a Custom Trip</div>
    </div>
    <form method="POST" class="request-form">
      <div class="form-group">
        <label class="form-label"><i class="fas fa-bus"></i> Transport Type</label>
        <select class="form-select" name="transport_type" required>
          <option value="bus">Bus</option>
          <option value="train">Train</option>
          <option value="sea">Sea</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label"><i class="fas fa-map-marker-alt"></i> From City</label>
        <input type="text" class="form-input" name="from_city" id="fromCity" required placeholder="Enter origin city">
      </div>
      <div class="form-group" style="display:flex;align-items:center;justify-content:center;">
        <button type="button" class="swap-icon" onclick="swapCities()"><i class="fas fa-exchange-alt"></i></button>
      </div>
      <div class="form-group">
        <label class="form-label"><i class="fas fa-flag-checkered"></i> To City</label>
        <input type="text" class="form-input" name="to_city" id="toCity" required placeholder="Enter destination city">
      </div>
      <div class="form-group">
        <label class="form-label"><i class="far fa-calendar-alt"></i> Departure Date</label>
        <input type="date" class="form-input" name="departure_date" required min="<?= date('Y-m-d') ?>">
      </div>
      <div class="form-group">
        <label class="form-label"><i class="fas fa-clock"></i> Departure Time</label>
        <select class="form-select" name="departure_time">
          <?php foreach(['06:00','07:30','09:00','10:30','12:00','14:00','16:00','18:00','20:00','22:00'] as $tval): ?>
            <option value="<?= $tval ?>"><?= $tval ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label"><i class="fas fa-chair"></i> Seat Number (optional)</label>
        <input type="text" class="form-input" name="seat_number" placeholder="e.g. 12A">
      </div>
      <button type="submit" name="request_trip" class="request-btn"><i class="fas fa-paper-plane"></i> Submit Request</button>
    </form>
  </div>

  <div class="section-header" style="margin-top:60px;">
    <div class="section-title"><i class="fas fa-history"></i> My Trips</div>
  </div>
  <div class="my-book-grid">
    <?php if(empty($myTrips)): ?>
      <div class="empty-state" style="text-align:center;padding:60px;color:var(--muted);"><i class="fas fa-suitcase-rolling"></i> No trips yet. Book or request one!</div>
    <?php else: ?>
      <?php foreach($myTrips as $i=>$t): ?>
        <div class="mb-item" style="animation-delay:<?= $i*0.05 ?>s">
          <div class="mb-icon"><?= getTransportIcon($t['transport_type'] ?? 'bus') ?></div>
          <div class="mb-info">
            <div class="mb-codes"><?= htmlspecialchars($t['from_city']) ?> → <?= htmlspecialchars($t['to_city']) ?></div>
            <div class="mb-meta">
              <?= date('M d, Y', strtotime($t['departure_date'])) ?> at <?= substr($t['departure_time'],0,5) ?>
              · Seat: <?= htmlspecialchars($t['seat_number'] ?? '—') ?>
            </div>
          </div>
          <div>
            <div class="mb-price">₦<?= number_format($t['total_price'],0) ?></div>
            <span class="status-pill s-<?= $t['status'] ?>"><?= ucfirst($t['status']) ?></span>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<!-- Modals -->
<div class="modal-overlay" id="aboutModal">
  <div class="modal">
    <h2><i class="fas fa-info-circle"></i> About TransTrip</h2>
    <p>TransTrip is the intercity travel arm of TransNet X, offering seamless bus, train, and sea trips across Nigeria.</p>
    <p><strong>How to book:</strong></p>
    <ul>
      <li>Search for available trips using the search bar.</li>
      <li>Choose your trip and enter a seat number.</li>
      <li>Instantly book or submit a custom trip request.</li>
    </ul>
    <p><strong>Our services:</strong></p>
    <ul>
      <li>Real‑time trip availability & booking.</li>
      <li>Custom trip requests for unlisted routes.</li>
      <li>Multiple transport types (bus, train, sea).</li>
      <li>24/7 support.</li>
    </ul>
    <div class="modal-actions">
      <button class="btn-ghost" onclick="closeAboutModal()">Close</button>
    </div>
  </div>
</div>

<div class="modal-overlay" id="contactModal">
  <div class="modal">
    <h2><i class="fas fa-envelope"></i> Contact Us</h2>
    <p><i class="fas fa-phone-alt"></i> +234 912 417 524 9</p>
    <p><i class="fas fa-envelope"></i> @transnetx.com</p>
    <p><i class="fas fa-map-marker-alt"></i> Tambari Housing Estate MBC3 Transit X Hub, Bauchi , Bauchi State</p>
    <div class="modal-actions">
      <button class="btn-ghost" onclick="closeContactModal()">Close</button>
    </div>
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
    // Sidebar
    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('open');
        document.getElementById('sidebarOverlay').classList.toggle('show');
        document.getElementById('hamburger').classList.toggle('open');
    }

    // Notifications
    let notifOpen = false;
    function toggleNotif() {
        const box = document.getElementById("notifDropdown");
        notifOpen = !notifOpen;
        if(notifOpen) {
            const notifs = <?= json_encode($notifications) ?>;
            let html = '';
            if(notifs.length === 0) {
                html = '<div class="notif-item"><i class="fas fa-info-circle"></i> No recent activity</div>';
            } else {
                notifs.forEach(n => {
                    let msg = '', icon = '';
                    if(n.status === 'pending'){ msg='Trip request awaiting approval'; icon='<i class="fas fa-hourglass-half"></i>'; }
                    else if(n.status === 'confirmed'){ msg=`Trip ${n.from_city}→${n.to_city} confirmed`; icon='<i class="fas fa-check-circle"></i>'; }
                    else if(n.status === 'cancelled'){ msg=`Trip ${n.from_city}→${n.to_city} cancelled`; icon='<i class="fas fa-ban"></i>'; }
                    else if(n.status === 'completed'){ msg=`Trip ${n.from_city}→${n.to_city} completed`; icon='<i class="fas fa-flag-checkered"></i>'; }
                    else { msg=`Status: ${n.status}`; icon='<i class="fas fa-bell"></i>'; }
                    let transportIcon = '';
                    if(n.transport_type === 'bus') transportIcon = '<i class="fas fa-bus"></i>';
                    else if(n.transport_type === 'train') transportIcon = '<i class="fas fa-train"></i>';
                    else if(n.transport_type === 'sea') transportIcon = '<i class="fas fa-ship"></i>';
                    else transportIcon = '<i class="fas fa-road"></i>';
                    html += `<div class="notif-item">${icon} ${msg}<small>${transportIcon} ${n.departure_date} · ${n.from_city} → ${n.to_city}</small></div>`;
                });
            }
            box.innerHTML = html;
            box.style.display = 'flex';
        } else {
            box.style.display = 'none';
        }
    }
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.notif-wrapper')) {
            document.getElementById('notifDropdown').style.display = 'none';
            notifOpen = false;
        }
    });

    // Filter trips
    function filterTrips() {
        const originVal = document.getElementById('originInput').value.trim().toLowerCase();
        const destVal = document.getElementById('destInput').value.trim().toLowerCase();
        const dateVal = document.getElementById('dateInput').value;
        const grid = document.getElementById('tripsGrid');
        const cards = document.querySelectorAll('#tripsGrid .trip-card');
        let anyVisible = false;
        cards.forEach(card => {
            const originCity = card.dataset.originCity.toLowerCase();
            const destCity = card.dataset.destCity.toLowerCase();
            const depDate = card.dataset.departureDate;
            let show = true;
            if (originVal && !originCity.includes(originVal)) show = false;
            if (destVal && !destCity.includes(destVal)) show = false;
            if (dateVal && depDate !== dateVal) show = false;
            card.style.display = show ? '' : 'none';
            if (show) anyVisible = true;
        });
        const empty = grid.querySelector('.empty-state');
        if (empty) empty.remove();
        if (!anyVisible) {
            const div = document.createElement('div');
            div.className = 'empty-state';
            div.style = 'text-align:center;padding:60px;color:var(--muted);';
            div.innerHTML = '<i class="fas fa-search"></i> No trips match your criteria.<br><small>Try a custom request below.</small>';
            grid.appendChild(div);
        }
        document.getElementById('tripsGrid').scrollIntoView({behavior:'smooth', block:'start'});
    }

    function setOrigin(city) {
        document.getElementById('originInput').value = city;
        filterTrips();
    }

    function swapCities() {
        const origin = document.getElementById('originInput');
        const dest = document.getElementById('destInput');
        [origin.value, dest.value] = [dest.value, origin.value];
        filterTrips();
    }

    function swapCities() {
        const f = document.getElementById('fromCity');
        const t = document.getElementById('toCity');
        const tmp = f.value; f.value = t.value; t.value = tmp;
    }

    // Modals
    function openAboutModal() { document.getElementById('aboutModal').classList.add('open'); }
    function closeAboutModal() { document.getElementById('aboutModal').classList.remove('open'); }
    function openContactModal() { document.getElementById('contactModal').classList.add('open'); }
    function closeContactModal() { document.getElementById('contactModal').classList.remove('open'); }
    window.addEventListener('click', function(e) {
        if (e.target.classList.contains('modal-overlay')) {
            e.target.classList.remove('open');
        }
    });
</script>
</body>
</html>