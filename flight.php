<?php
session_start();
require_once '../../config/db.php';

// Enable exceptions for mysqli errors (better debugging)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ---------- AUTH ----------
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit();
}
$uid = (int) $_SESSION['user_id'];

// Fetch user – safe direct query because $uid is an integer
$userRes = mysqli_query($conn, "SELECT name FROM users WHERE id = $uid");
$user = mysqli_fetch_assoc($userRes);
if (!$user) {
    session_destroy();
    header('Location: ../../index.php');
    exit();
}
$name    = explode(' ', $user['name'])[0];
$initial = strtoupper(substr($user['name'], 0, 1));

$msg = $err = '';

/* ============================================================
   1. BOOK A FLIGHT
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book_flight'])) {
    $flight_id = (int) $_POST['flight_id'];
    $pname     = trim($_POST['passenger_name']);
    $passport  = trim($_POST['passport_number'] ?? '');
    $cabin     = $_POST['cabin_class'] ?? 'economy';

    $multipliers = ['economy' => 1, 'business' => 2.5, 'first' => 4.5];
    $mult = $multipliers[$cabin] ?? 1;

    // Fetch flight with direct query (no user input in the query)
    $flightRes = mysqli_query($conn, "SELECT * FROM flights WHERE id = $flight_id AND status = 'confirmed' AND available_seats > 0");
    $flight = mysqli_fetch_assoc($flightRes);

    if ($flight && !empty($pname)) {
        $total = $flight['price_per_seat'] * $mult;
        $ref   = 'TXF' . strtoupper(substr(md5(uniqid()), 0, 8));
        $seat  = chr(rand(65, 70)) . rand(1, 30);

        // Use only the date part for departure_date
        $depDate = date('Y-m-d', strtotime($flight['departure_time']));
        // Set passport to NULL if empty (column default is NULL)
        $passportVal = $passport === '' ? null : $passport;

        $ins = mysqli_prepare($conn, "
            INSERT INTO flight_bookings 
            (flight_id, user_id, from_city, to_city, departure_date, departure_time,
             passenger_name, passport_number, seat_number, cabin_class, total_price,
             status, source_type, booking_ref)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'confirmed', 'flight', ?)
        ");
       mysqli_stmt_bind_param($ins, "iisssssssdss",
            $flight_id, $uid,
            $flight['origin_city'], $flight['dest_city'],
            $depDate, $flight['departure_time'],
            $pname, $passportVal, $seat, $cabin, $total, $ref
        );

        if (mysqli_stmt_execute($ins)) {
            // Decrease available seats
            $upd = mysqli_prepare($conn, "UPDATE flights SET available_seats = available_seats - 1 WHERE id = ?");
            mysqli_stmt_bind_param($upd, "i", $flight_id);
            mysqli_stmt_execute($upd);
            $msg = "<i class='fas fa-plane'></i> Flight booked! Booking ref: <strong>$ref</strong> · Seat: <strong>$seat</strong>";
        } else {
            $err = "<i class='fas fa-times-circle'></i> Booking failed. Please try again.";
        }
    } else {
        $err = '<i class="fas fa-ban"></i> Flight not available or seats sold out.';
    }
}

/* ============================================================
   2. SUBMIT A CUSTOM FLIGHT REQUEST
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_flight'])) {
    $origin      = trim($_POST['origin']);
    $destination = trim($_POST['destination']);
    $date        = $_POST['departure_date'];
    $pname       = trim($_POST['passenger_name']);
    $passport    = trim($_POST['passport_number']);
    $cabin       = $_POST['cabin_class'] ?? 'economy';

    if ($origin && $destination && $date && $pname) {
        $passportVal = $passport === '' ? null : $passport;

        $stmt = mysqli_prepare($conn, "
            INSERT INTO flight_bookings 
            (flight_id, user_id, from_city, to_city, departure_date,
             passenger_name, passport_number, cabin_class,
             status, source_type)
            VALUES (NULL, ?, ?, ?, ?,
                    ?, ?, ?,
                    'pending', 'request')
        ");
        mysqli_stmt_bind_param($stmt, "issssss",
            $uid, $origin, $destination, $date,
            $pname, $passportVal, $cabin
        );
        mysqli_stmt_execute($stmt);
        $msg = "<i class='fas fa-paper-plane'></i> Flight request submitted! Waiting for approval.";
    } else {
        $err = "<i class='fas fa-exclamation-circle'></i> Please fill all required fields.";
    }
}

/* ============================================================
   3. FETCH AVAILABLE FLIGHTS
   ============================================================ */
$flights = [];
$res = mysqli_query($conn, "SELECT * FROM flights WHERE status = 'confirmed' AND available_seats > 0 ORDER BY departure_time ASC");
while ($row = mysqli_fetch_assoc($res)) {
    $flights[] = $row;
}

/* ============================================================
   4. FETCH MY FLIGHTS
   ============================================================ */
$myFlights = [];
$myRes = mysqli_query($conn, "
    SELECT fb.*,
           f.flight_number, f.airline,
           f.origin_code, f.dest_code,
           f.origin_city AS f_origin_city, f.dest_city AS f_dest_city,
           f.departure_time AS f_departure_time, f.arrival_time AS f_arrival_time
    FROM flight_bookings fb
    LEFT JOIN flights f ON f.id = fb.flight_id
    WHERE fb.user_id = $uid
    ORDER BY fb.created_at DESC
    LIMIT 15
");
while ($row = mysqli_fetch_assoc($myRes)) {
    $myFlights[] = $row;
}

/* ============================================================
   5. NOTIFICATIONS
   ============================================================ */
$notifRes = mysqli_query($conn, "
    SELECT id, status, from_city, to_city, departure_date, source_type, updated_at
    FROM flight_bookings
    WHERE user_id = $uid
    ORDER BY updated_at DESC
    LIMIT 5
");
$notifications = [];
while ($n = mysqli_fetch_assoc($notifRes)) {
    $notifications[] = $n;
}
$countPending = 0;
foreach ($notifications as $n) {
    if ($n['status'] === 'pending') $countPending++;
}

$cities = ['Abuja','Lagos','Port Harcourt','Kano','Enugu','Ibadan','Benin City','Calabar','Uyo','Owerri','Jos','Kaduna','Warri','Ilorin','Abeokuta'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>TransFly — TransNet X</title>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Outfit:wght@300;400;500;600;700&family=Playfair+Display:wght@600;700;800&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="assets/bootstrap-icons/bootstrap-icons.css"/>
<style>
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
        --accent-flight: #A78BFA;
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
            rgba(167,139,250,1),
            rgba(139,92,246,1)
        );
        -webkit-background-clip:text;
        -webkit-text-fill-color:transparent;
        background-clip:text;
        text-shadow:
            0 0 12px rgba(167,139,250,.35),
            0 0 25px rgba(167,139,250,.18);
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
            rgba(167,139,250,1),
            rgba(139,92,246,1)
        );
        -webkit-background-clip:text;
        -webkit-text-fill-color:transparent;
        background-clip:text;
        text-shadow:
            0 0 12px rgba(167,139,250,.35),
            0 0 25px rgba(167,139,250,.18);
        transition:all .3s ease;
    }

    .logo em{
        font-style:normal;
        font-size:11px;
        letter-spacing:3px;
        color:rgba(167,139,250,.7);
        -webkit-text-fill-color:rgba(167,139,250,.7);
        text-shadow:0 0 10px rgba(167,139,250,.2);
    }

    .logo:hover span{
        filter:brightness(1.15);
        letter-spacing:3px;
    }

    .logo:hover em{
        color:rgba(167,139,250,1);
        -webkit-text-fill-color:rgba(167,139,250,1);
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
        background: url('plane.png') center/cover no-repeat;
        z-index: -2;
    }
    .hero-overlay {
        position: absolute;
        top: 0; left: 0; width: 100%; height: 100%;
        background: linear-gradient(to bottom, rgba(6,6,10,0.6) 0%, rgba(6,6,10,0.2) 40%, rgba(6,6,10,0.9) 90%, var(--black) 100%);
        z-index: -1;
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

    /* Suggestion Dropdown */
    .suggestion-dropdown {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: rgba(15,15,20,0.98);
        backdrop-filter: blur(12px);
        border: 1px solid var(--border);
        border-radius: 12px;
        max-height: 200px;
        overflow-y: auto;
        z-index: 500;
        display: none;
        box-shadow: 0 15px 30px rgba(0,0,0,0.5);
    }
    .suggestion-item {
        padding: 12px 20px;
        cursor: pointer;
        color: var(--text);
        border-bottom: 1px solid rgba(212,168,67,0.08);
        font-size: 14px;
        transition: background 0.2s;
    }
    .suggestion-item:last-child {
        border-bottom: none;
    }
    .suggestion-item:hover,
    .suggestion-item.active {
        background: rgba(212,168,67,0.12);
        color: var(--gold);
    }

    /* Sections */
    .section-header{display:flex;align-items:center;justify-content:space-between;margin:50px 0 20px;}
    .section-title{font-family:'Playfair Display',serif;font-size:28px;color:var(--text);display:flex;align-items:center;gap:12px;}
    .section-title i{color:var(--gold);}
    .section-title::after{content:'';flex:1;height:1px;background:linear-gradient(90deg,var(--gold),transparent);}
    .filter-bar{display:flex;gap:12px;}
    .filter-btn{background:var(--card);border:1px solid var(--border);border-radius:30px;padding:8px 18px;color:var(--muted);font-size:13px;cursor:pointer;transition:0.3s;display:flex;align-items:center;gap:6px;}
    .filter-btn.active,.filter-btn:hover{border-color:var(--gold);color:var(--gold);background:rgba(212,168,67,0.08);}
    .flights-grid{display:flex;flex-direction:column;gap:16px;animation:fadeUp 0.8s ease;}

    .flight-card{
        position:relative;background:var(--card);backdrop-filter:blur(16px);
        border:1px solid var(--border);border-radius:24px;padding:24px 28px;
        display:grid;grid-template-columns:1fr auto 1fr auto;gap:24px;align-items:center;
        transition:0.4s;cursor:pointer;box-shadow:0 4px 20px rgba(0,0,0,0.2);
    }
    .flight-card:hover{transform:translateY(-6px);border-color:rgba(212,168,67,0.5);box-shadow:0 20px 50px rgba(0,0,0,0.5),0 0 30px rgba(212,168,67,0.15);}
    .flight-card.selected{border-color:var(--gold);}
    .fc-code{font-family:'Bebas Neue',sans-serif;font-size:44px;letter-spacing:3px;color:var(--text);}
    .fc-city{font-size:13px;color:var(--muted);margin-top:2px;}
    .fc-time{font-size:16px;font-weight:600;color:var(--text);margin-top:6px;}
    .fc-middle{display:flex;flex-direction:column;align-items:center;gap:8px;}
    .fc-route-line{position:relative;width:120px;height:2px;background:linear-gradient(90deg,var(--gold),rgba(212,168,67,0.3));}
    .fc-route-line i{position:absolute;top:-9px;left:50%;transform:translateX(-50%);color:var(--gold);font-size:14px;}
    .fc-duration{font-size:12px;color:var(--muted);}
    .fc-airline{font-size:11px;color:var(--muted);}
    .fc-right{text-align:right;}
    .fc-price{font-family:'Playfair Display',serif;font-size:28px;color:var(--gold);font-weight:700;}
    .fc-seats{font-size:12px;color:#34D399;margin-bottom:8px;}
    .fc-select-btn{background:rgba(212,168,67,0.1);border:1px solid rgba(212,168,67,0.3);border-radius:30px;padding:10px 24px;color:var(--gold);font-weight:600;font-size:13px;cursor:pointer;transition:0.3s;display:inline-flex;align-items:center;gap:6px;}
    .fc-select-btn:hover{background:var(--gold);color:var(--black);}
    .empty-state{text-align:center;padding:60px;color:var(--muted);}

    /* Request section */
    .request-section{margin-top:60px;background:var(--card);backdrop-filter:blur(16px);border:1px solid var(--border);border-radius:24px;padding:30px;box-shadow:0 4px 20px rgba(0,0,0,0.2);}
    .request-form{display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:14px;align-items:end;}
    .form-group{display:flex;flex-direction:column;}
    .form-label{font-size:11px;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);margin-bottom:6px;}
    .form-input,.form-select{width:100%;background:rgba(6,6,10,0.8);border:1px solid var(--border);border-radius:var(--radius-sm);padding:12px 16px;color:var(--text);font-size:14px;outline:none;transition:border-color 0.2s;}
    .form-input:focus,.form-select:focus{border-color:var(--gold);}
    .form-select option{background:#06060A;color:var(--text);}
    .request-btn{padding:12px 24px;border-radius:var(--radius-sm);background:linear-gradient(135deg,var(--gold3),var(--gold));border:none;color:var(--black);font-weight:700;font-size:14px;cursor:pointer;transition:0.3s;white-space:nowrap;}
    .request-btn:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(212,168,67,0.3);}

    /* My flights */
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
    .cabin-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:20px;}
    .cab{background:rgba(255,255,255,0.05);border:1px solid var(--border);border-radius:14px;padding:16px;cursor:pointer;text-align:center;transition:0.3s;}
    .cab input{display:none;}
    .cab-icon{font-size:24px;margin-bottom:8px;color:var(--gold);}
    .cab-name{font-size:14px;font-weight:600;color:var(--text);}
    .cab-mult{font-size:11px;color:var(--muted);}
    .cab.selected{border-color:var(--gold);background:rgba(212,168,67,0.1);}
    .modal-actions{display:flex;gap:12px;margin-top:24px;justify-content:flex-end;}
    .btn-ghost{background:transparent;border:1px solid var(--border);border-radius:30px;padding:10px 24px;color:var(--muted);cursor:pointer;transition:0.2s;}
    .btn-ghost:hover{border-color:var(--gold);color:var(--gold);}
    .btn-confirm{background:linear-gradient(135deg,var(--gold3),var(--gold));border:none;border-radius:30px;padding:10px 24px;color:var(--black);font-weight:700;cursor:pointer;}

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
        .flight-card{grid-template-columns:1fr;}
        .topbar-right{gap:10px;}
        .nav-link{display:none;}
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
    <a href="uber.php" class="sidebar-item"><i class="fas fa-car"></i> Rides</a>
    <a href="trip.php" class="sidebar-item"><i class="fas fa-bus"></i> Trips</a>
    <a href="flight.php" class="sidebar-item active"><i class="fas fa-plane"></i> Flights</a>
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
    <div class="hamburger" id="hamburger" onclick="toggleSidebar()">
      <span></span><span></span><span></span>
    </div>
    <a href="transnet.php" class="logo"><span>TransNet X</span><em>FLY</em></a>
  </div>
  <div class="topbar-right">
    <a href="#" class="nav-link" onclick="openAboutModal()"><i class="fas fa-info-circle"></i> About</a>
    <a href="#" class="nav-link" onclick="openContactModal()"><i class="fas fa-envelope"></i> Contact</a>
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
    <div class="hero-eyebrow"><i class="fas fa-plane-departure"></i> TransNet X Air · Fly Beyond</div>
    <h1>THE SKY IS<br><span>NOT THE LIMIT</span></h1>
    <p>Discover a new standard of air travel. Book instantly, fly in luxury.</p>
  </div>

  <div class="booking-widget">
    <div class="bw-tabs">
      <div class="bw-tab active"><i class="fas fa-plane"></i> Book a Flight</div>
      <div class="bw-tab" onclick="document.querySelector('.request-section').scrollIntoView({behavior:'smooth'})"><i class="fas fa-concierge-bell"></i> Request Custom Flight</div>
      <div class="bw-tab" onclick="document.querySelector('.my-book-grid').scrollIntoView({behavior:'smooth'})"><i class="fas fa-suitcase-rolling"></i> Manage Booking</div>
    </div>
    <div class="bw-form">
      <div class="bw-field">
        <div class="bw-label"><i class="fas fa-map-marker-alt"></i> Leaving From</div>
        <input type="text" class="bw-input" id="originInput" placeholder="City or airport" list="cityList" autocomplete="off">
        <div class="suggestion-dropdown"></div>
      </div>
      <div class="bw-swap" onclick="swapCities()"><i class="fas fa-exchange-alt"></i></div>
      <div class="bw-field">
        <div class="bw-label"><i class="fas fa-flag-checkered"></i> Going To</div>
        <input type="text" class="bw-input" id="destInput" placeholder="City or airport" list="cityList" autocomplete="off">
        <div class="suggestion-dropdown"></div>
      </div>
      <div class="bw-field">
        <div class="bw-label"><i class="far fa-calendar-alt"></i> Departure</div>
        <input type="date" class="bw-input" id="dateInput">
      </div>
      <button class="bw-submit" onclick="filterFlights()">
        Search Flights <i class="fas fa-arrow-right"></i>
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

  <!-- Available Flights -->
  <div class="section-header">
    <div class="section-title"><i class="fas fa-plane-departure"></i> Available Flights</div>
    <div class="filter-bar">
      <button class="filter-btn active" onclick="sortFlights('default')"><i class="fas fa-clock"></i> Best</button>
      <button class="filter-btn" onclick="sortFlights('price')"><i class="fas fa-tag"></i> Cheapest</button>
      <button class="filter-btn" onclick="sortFlights('duration')"><i class="fas fa-hourglass-half"></i> Quickest</button>
    </div>
  </div>

  <div class="flights-grid" id="flightsGrid">
    <?php foreach($flights as $i=>$fl): ?>
    <?php
      $dep = new DateTime($fl['departure_time']);
      $arr = new DateTime($fl['arrival_time']);
      $dur_h = floor($fl['duration_mins']/60);
      $dur_m = $fl['duration_mins']%60;
    ?>
    <div class="flight-card" 
         data-origin-city="<?= htmlspecialchars($fl['origin_city']) ?>"
         data-dest-city="<?= htmlspecialchars($fl['dest_city']) ?>"
         data-departure-date="<?= substr($fl['departure_time'],0,10) ?>"
         data-price="<?= $fl['price_per_seat'] ?>"
         data-duration="<?= $fl['duration_mins'] ?>"
         style="animation-delay:<?= $i*0.06 ?>s"
         onclick="selectFlight(<?= $fl['id'] ?>,<?= $fl['price_per_seat'] ?>,'<?= htmlspecialchars($fl['flight_number']) ?>','<?= htmlspecialchars($fl['origin_code']) ?>','<?= htmlspecialchars($fl['dest_code']) ?>','<?= htmlspecialchars($fl['origin_city']) ?>','<?= htmlspecialchars($fl['dest_city']) ?>')"
    >
      <div class="fc-origin">
        <div class="fc-code"><?= $fl['origin_code'] ?></div>
        <div class="fc-city"><?= htmlspecialchars($fl['origin_city']) ?></div>
        <div class="fc-time"><?= $dep->format('H:i') ?></div>
      </div>
      <div class="fc-middle">
        <div class="fc-route-line"><i class="fas fa-plane"></i></div>
        <div class="fc-duration"><?= $dur_h ?>h <?= $dur_m ?>m</div>
        <div class="fc-airline"><i class="fas fa-building"></i> <?= htmlspecialchars($fl['airline']) ?></div>
      </div>
      <div class="fc-dest">
        <div class="fc-code"><?= $fl['dest_code'] ?></div>
        <div class="fc-city"><?= htmlspecialchars($fl['dest_city']) ?></div>
        <div class="fc-time"><?= $arr->format('H:i') ?></div>
      </div>
      <div class="fc-right">
        <div class="fc-price">₦<?= number_format($fl['price_per_seat'],0) ?></div>
        <div class="fc-seats"><i class="fas fa-chair"></i> <?= $fl['available_seats'] ?> seats left</div>
        <button class="fc-select-btn"><i class="fas fa-ticket-alt"></i> Select Flight</button>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if(empty($flights)): ?>
      <div class="empty-state"><i class="fas fa-info-circle"></i> No available flights at the moment.</div>
    <?php endif; ?>
  </div>

  <!-- Request Flight -->
  <div class="request-section">
    <div class="section-header">
      <div class="section-title"><i class="fas fa-pen-ruler"></i> Request a Flight</div>
    </div>
    <form method="POST" class="request-form">
      <div class="form-group">
        <label class="form-label"><i class="fas fa-map-marker-alt"></i> From City</label>
        <input class="form-input" name="origin" placeholder="Abuja" required>
      </div>
      <div class="form-group">
        <label class="form-label"><i class="fas fa-flag-checkered"></i> To City</label>
        <input class="form-input" name="destination" placeholder="Lagos" required>
      </div>
      <div class="form-group">
        <label class="form-label"><i class="far fa-calendar-alt"></i> Departure Date</label>
        <input type="date" class="form-input" name="departure_date" required>
      </div>
      <div class="form-group">
        <label class="form-label"><i class="fas fa-user"></i> Passenger Name</label>
        <input class="form-input" name="passenger_name" value="<?= htmlspecialchars($user['name']) ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label"><i class="fas fa-passport"></i> Passport / ID</label>
        <input class="form-input" name="passport_number">
      </div>
      <div class="form-group">
        <label class="form-label"><i class="fas fa-chair"></i> Cabin Class</label>
        <select name="cabin_class" class="form-select">
          <option value="economy">Economy</option>
          <option value="business">Business</option>
          <option value="first">First Class</option>
        </select>
      </div>
      <button type="submit" name="request_flight" class="request-btn"><i class="fas fa-paper-plane"></i> Submit Request</button>
    </form>
  </div>

  <!-- My Flights -->
  <div class="section-header" style="margin-top:60px;">
    <div class="section-title"><i class="fas fa-history"></i> My Flights</div>
  </div>
  <div class="my-book-grid">
    <?php if(empty($myFlights)): ?>
      <div class="empty-state"><i class="fas fa-plane-slash"></i> No flights yet. Book or request one!</div>
    <?php else: ?>
      <?php foreach($myFlights as $i=>$b): ?>
      <div class="mb-item" style="animation-delay:<?= $i*0.05 ?>s">
        <div class="mb-icon"><?= $b['source_type'] === 'request' ? '<i class="fas fa-file-alt"></i>' : '<i class="fas fa-plane"></i>' ?></div>
        <div class="mb-info">
          <div class="mb-codes">
            <?php if($b['flight_id']): ?>
              <?= htmlspecialchars($b['origin_code']) ?> → <?= htmlspecialchars($b['dest_code']) ?>
            <?php else: ?>
              <?= htmlspecialchars($b['from_city']) ?> → <?= htmlspecialchars($b['to_city']) ?>
            <?php endif; ?>
          </div>
          <div class="mb-meta">
            <?php if($b['flight_id']): ?>
              <i class="fas fa-tag"></i> <?= htmlspecialchars($b['flight_number']) ?> · 
              <i class="fas fa-user"></i> <?= htmlspecialchars($b['passenger_name']) ?>
              · <i class="fas fa-chair"></i> <?= ucfirst($b['cabin_class']) ?> · 
              Seat <?= htmlspecialchars($b['seat_number']) ?>
              · Ref: <strong><?= $b['booking_ref'] ?></strong>
              · <?= date('M d, Y H:i', strtotime($b['f_departure_time'] ?? $b['departure_date'])) ?>
            <?php else: ?>
              <i class="fas fa-calendar-day"></i> Request · <?= htmlspecialchars($b['passenger_name']) ?>
              · <?= ucfirst($b['cabin_class']) ?>
              · <?= date('M d, Y', strtotime($b['departure_date'])) ?>
            <?php endif; ?>
          </div>
        </div>
        <div>
          <div class="mb-price">₦<?= number_format($b['total_price'] ?? 0,0) ?></div>
          <span class="status-pill s-<?= $b['status'] ?>"><?= ucfirst($b['status']) ?></span>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<!-- Booking Modal -->
<div class="modal-overlay" id="bookModal">
  <div class="modal">
    <h2><i class="fas fa-check-circle"></i> Confirm Your Booking</h2>
    <div id="modalInfo" style="background:rgba(255,255,255,0.05);border-radius:12px;padding:16px;margin-bottom:20px;"></div>
    <form method="POST">
      <input type="hidden" name="flight_id" id="modalFlightId"/>
      <div class="form-group" style="margin-bottom:14px;">
        <label class="form-label"><i class="fas fa-user"></i> Passenger Name</label>
        <input class="form-input" name="passenger_name" value="<?= htmlspecialchars($user['name']) ?>" required/>
      </div>
      <div class="form-group" style="margin-bottom:14px;">
        <label class="form-label"><i class="fas fa-passport"></i> Passport / ID</label>
        <input class="form-input" name="passport_number" placeholder="Optional"/>
      </div>
      <label class="form-label" style="margin-bottom:10px;"><i class="fas fa-crown"></i> Cabin Class</label>
      <div class="cabin-grid" id="cabinSelector">
        <div class="cab selected"><input type="radio" name="cabin_class" value="economy" checked><label><div class="cab-icon"><i class="fas fa-chair"></i></div><div class="cab-name">Economy</div><div class="cab-mult">Base price</div></label></div>
        <div class="cab"><input type="radio" name="cabin_class" value="business"><label><div class="cab-icon"><i class="fas fa-couch"></i></div><div class="cab-name">Business</div><div class="cab-mult">×2.5</div></label></div>
        <div class="cab"><input type="radio" name="cabin_class" value="first"><label><div class="cab-icon"><i class="fas fa-gem"></i></div><div class="cab-name">First</div><div class="cab-mult">×4.5</div></label></div>
      </div>
      <div style="background:rgba(255,255,255,0.05);border-radius:12px;padding:14px 18px;display:flex;justify-content:space-between;align-items:center;margin:16px 0;">
        <span style="color:var(--muted);"><i class="fas fa-tag"></i> Total Price</span>
        <span id="modalPrice" style="font-family:'Playfair Display',serif;font-size:28px;color:var(--gold);font-weight:700;">₦0</span>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn-ghost" onclick="closeModal()"><i class="fas fa-times"></i> Cancel</button>
        <button type="submit" name="book_flight" class="btn-confirm"><i class="fas fa-check"></i> Confirm Booking</button>
      </div>
    </form>
  </div>
</div>

<!-- About Modal -->
<div class="modal-overlay" id="aboutModal">
  <div class="modal">
    <h2><i class="fas fa-info-circle"></i> About TransFly</h2>
    <p>TransFly is the premier flight booking service of TransNet X, offering seamless air travel across Nigeria and beyond.</p>
    <p><strong>How to book:</strong></p>
    <ul>
      <li>Search for flights using the search bar above.</li>
      <li>Select your desired flight and choose a cabin class.</li>
      <li>Enter passenger details and confirm your booking.</li>
      <li>Receive instant confirmation and your booking reference.</li>
    </ul>
    <p><strong>Our services:</strong></p>
    <ul>
      <li>Real‑time flight availability & instant booking.</li>
      <li>Custom flight requests for unlisted routes.</li>
      <li>Secure passenger management & e‑tickets.</li>
      <li>24/7 customer support.</li>
    </ul>
    <div class="modal-actions">
      <button class="btn-ghost" onclick="closeAboutModal()">Close</button>
    </div>
  </div>
</div>

<!-- Contact Modal -->
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

<!-- Footer -->
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
// ---------- CITY SUGGESTIONS ----------
const cities = <?= json_encode($cities) ?>;

function setupCityAutocomplete(input, dropdown) {
  input.addEventListener('input', function() {
    const val = this.value.trim().toLowerCase();
    dropdown.innerHTML = '';
    if (!val) {
      dropdown.style.display = 'none';
      return;
    }
    const matches = cities.filter(city => city.toLowerCase().includes(val));
    if (matches.length === 0) {
      dropdown.style.display = 'none';
      return;
    }
    matches.forEach(city => {
      const div = document.createElement('div');
      div.className = 'suggestion-item';
      div.textContent = city;
      div.addEventListener('mousedown', function(e) {
        e.preventDefault();
        input.value = city;
        dropdown.style.display = 'none';
        // Trigger filtering if needed
        filterFlights();
      });
      dropdown.appendChild(div);
    });
    dropdown.style.display = 'block';
  });

  // Hide dropdown when focus leaves the input (with small delay to allow click)
  input.addEventListener('blur', function() {
    setTimeout(() => {
      dropdown.style.display = 'none';
    }, 150);
  });

  input.addEventListener('focus', function() {
    if (this.value.trim().length > 0) {
      this.dispatchEvent(new Event('input'));
    }
  });

  // Hide dropdown on Escape
  input.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      dropdown.style.display = 'none';
    }
  });
}

// Attach to both inputs
document.addEventListener('DOMContentLoaded', function() {
  const originInput = document.getElementById('originInput');
  const destInput = document.getElementById('destInput');
  const originDropdown = originInput.parentElement.querySelector('.suggestion-dropdown');
  const destDropdown = destInput.parentElement.querySelector('.suggestion-dropdown');

  setupCityAutocomplete(originInput, originDropdown);
  setupCityAutocomplete(destInput, destDropdown);
});

// ---------- EXISTING FUNCTIONS (unchanged) ----------
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('sidebarOverlay').classList.toggle('show');
  document.getElementById('hamburger').classList.toggle('open');
}

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
        if(n.status === 'pending'){ msg='Flight request awaiting approval'; icon='<i class="fas fa-hourglass-half"></i>'; }
        else if(n.status === 'confirmed'){ msg=`Flight ${n.from_city}→${n.to_city} confirmed`; icon='<i class="fas fa-check-circle"></i>'; }
        else if(n.status === 'cancelled'){ msg=`Flight ${n.from_city}→${n.to_city} cancelled`; icon='<i class="fas fa-ban"></i>'; }
        else if(n.status === 'completed'){ msg=`Flight ${n.from_city}→${n.to_city} completed`; icon='<i class="fas fa-flag-checkered"></i>'; }
        else { msg=`Status: ${n.status}`; icon='<i class="fas fa-bell"></i>'; }
        html += `<div class="notif-item">${icon} ${msg}<small>${n.from_city}→${n.to_city} · ${n.departure_date || ''}</small></div>`;
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

function filterFlights() {
  const originVal = document.getElementById('originInput').value.trim().toLowerCase();
  const destVal = document.getElementById('destInput').value.trim().toLowerCase();
  const dateVal = document.getElementById('dateInput').value;
  const grid = document.getElementById('flightsGrid');
  const cards = document.querySelectorAll('#flightsGrid .flight-card');
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
    div.innerHTML = '<i class="fas fa-search"></i> No flights match your criteria.<br><small>Try a custom request below.</small>';
    grid.appendChild(div);
  }

  document.getElementById('flightsGrid').scrollIntoView({behavior:'smooth', block:'start'});
}

function setOrigin(city) {
  document.getElementById('originInput').value = city;
  filterFlights();
}

function swapCities() {
  const origin = document.getElementById('originInput');
  const dest = document.getElementById('destInput');
  [origin.value, dest.value] = [dest.value, origin.value];
  filterFlights();
}

function sortFlights(type) {
  const grid = document.getElementById('flightsGrid');
  const cards = Array.from(grid.querySelectorAll('.flight-card')).filter(c => c.style.display !== 'none');
  document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
  event.target.closest('.filter-btn').classList.add('active');

  cards.sort((a,b) => {
    if (type === 'price') return parseInt(a.dataset.price) - parseInt(b.dataset.price);
    if (type === 'duration') return parseInt(a.dataset.duration) - parseInt(b.dataset.duration);
    return 0;
  });
  cards.forEach(card => grid.appendChild(card));
}

let selectedBase = 0;
function selectFlight(id, base, flightNumber, orgCode, destCode, orgCity, destCity) {
  selectedBase = base;
  document.getElementById('modalFlightId').value = id;
  document.getElementById('modalInfo').innerHTML = `
    <div style="font-size:18px;font-weight:600;color:var(--text);">${flightNumber}</div>
    <div style="font-family:'Bebas Neue',sans-serif;font-size:24px;color:var(--gold);margin:6px 0;">${orgCode} → ${destCode}</div>
    <div style="color:var(--muted);"><i class="fas fa-city"></i> ${orgCity} → ${destCity}</div>`;
  document.getElementById('modalPrice').textContent = '₦' + base.toLocaleString();
  document.getElementById('bookModal').classList.add('open');
}
function closeModal() {
  document.getElementById('bookModal').classList.remove('open');
}

document.querySelectorAll('#cabinSelector .cab').forEach(cab => {
  cab.addEventListener('click', function() {
    document.querySelectorAll('#cabinSelector .cab').forEach(c => c.classList.remove('selected'));
    this.classList.add('selected');
    updateModalPrice();
  });
});

function updateModalPrice() {
  const checked = document.querySelector('input[name="cabin_class"]:checked');
  const cls = checked ? checked.value : 'economy';
  const mult = {economy:1, business:2.5, first:4.5}[cls] || 1;
  document.getElementById('modalPrice').textContent = '₦' + (selectedBase * mult).toLocaleString();
}

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
<script>
// Runtime mapping: convert common Font Awesome classes to Bootstrap Icons (offline)
document.addEventListener('DOMContentLoaded', function(){
  const map = {
    'fa-plane':'bi-airplane',
    'fa-plane-departure':'bi-airplane',
    'fa-plane-slash':'bi-airplane',
    'fa-paper-plane':'bi-send',
    'fa-times-circle':'bi-x-circle',
    'fa-times':'bi-x',
    'fa-ban':'bi-slash-circle',
    'fa-exclamation-circle':'bi-exclamation-circle',
    'fa-home':'bi-house',
    'fa-car':'bi-car-front',
    'fa-bus':'bi-bus-front',
    'fa-key':'bi-key',
    'fa-info-circle':'bi-info-circle',
    'fa-chart-line':'bi-graph-up',
    'fa-clipboard-list':'bi-card-list',
    'fa-cog':'bi-gear',
    'fa-sign-out-alt':'bi-box-arrow-right',
    'fa-concierge-bell':'bi-bell',
    'fa-suitcase-rolling':'bi-suitcase',
    'fa-map-marker-alt':'bi-geo-alt',
    'fa-exchange-alt':'bi-arrow-left-right',
    'fa-flag-checkered':'bi-flag',
    'fa-calendar-alt':'bi-calendar',
    'fa-arrow-right':'bi-arrow-right',
    'fa-clock':'bi-clock',
    'fa-tag':'bi-tag',
    'fa-hourglass-half':'bi-hourglass-split',
    'fa-building':'bi-building',
    'fa-chair':'bi-chair',
    'fa-ticket-alt':'bi-ticket-perforated',
    'fa-pen-ruler':'bi-pencil',
    'fa-user':'bi-person',
    'fa-passport':'bi-person-badge',
    'fa-history':'bi-clock-history',
    'fa-file-alt':'bi-file-text',
    'fa-calendar-day':'bi-calendar',
    'fa-check-circle':'bi-check-circle',
    'fa-check':'bi-check',
    'fa-phone-alt':'bi-telephone',
    'fa-envelope':'bi-envelope',
    'fa-city':'bi-building',
    'fa-crown':'bi-crown',
    'fa-couch':'bi-couch',
    'fa-gem':'bi-gem',
    'fa-search':'bi-search'
  };

  document.querySelectorAll('i').forEach(el => {
    const classes = Array.from(el.classList);
    const fa = classes.find(c => c.startsWith('fa-'));
    if (!fa) return;
    // remove Font Awesome utility classes
    ['fa','fas','far','fab','fal'].forEach(c => el.classList.remove(c));
    // remove all fa-* classes
    classes.filter(c => c.startsWith('fa-')).forEach(c => el.classList.remove(c));
    // add mapped bootstrap icon classes
    const mapped = map[fa] || ('bi-' + fa.slice(3));
    mapped.split(' ').forEach(c => el.classList.add(c));
    // ensure base bi class exists
    if (!el.classList.contains('bi')) el.classList.add('bi');
  });
});
</script>
</body>
</html>