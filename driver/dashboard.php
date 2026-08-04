<?php
session_start();
require_once '../config/db.php';

// Auth check - expect driver_id from login
if (!isset($_SESSION['driver_id'])) {
    header('Location: ../index.php');
    exit();
}

$driver_id = (int)$_SESSION['driver_id'];

// Fetch driver info using the correct primary key: driver_id
$stmt = mysqli_prepare($conn, "SELECT * FROM drivers WHERE driver_id = ?");
mysqli_stmt_bind_param($stmt, "i", $driver_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$driver = mysqli_fetch_assoc($result);

if (!$driver) {
    session_destroy();
    header('Location: ../index.php');
    exit();
}

// Handle online toggle (AJAX) – using `status` column (ENUM)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_online'])) {
    $new_status = $_POST['toggle_online'] == 1 ? 'online' : 'offline';
    $stmt = mysqli_prepare($conn, "UPDATE drivers SET status = ? WHERE driver_id = ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "si", $new_status, $driver_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
    echo json_encode(['success' => true, 'status' => $new_status]);
    exit();
}

// Handle booking response (accept/decline)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['respond_booking'])) {
    $booking_id = (int)$_POST['booking_id'];
    $action     = $_POST['action'];
    $new_status = ($action === 'accept') ? 'accepted' : 'declined';

    if ($action === 'accept') {
        $stmt = mysqli_prepare($conn, "
            UPDATE bookings 
            SET status = ?, driver_id = ?, accepted_at = NOW(), user_seen = 0
            WHERE id = ? AND status = 'pending' AND (driver_id IS NULL OR driver_id = ?)
        ");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "siii", $new_status, $driver_id, $booking_id, $driver_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    } else {
        $stmt = mysqli_prepare($conn, "
            UPDATE bookings 
            SET status = ?, declined_at = NOW(), user_seen = 0
            WHERE id = ? AND status = 'pending' AND (driver_id IS NULL OR driver_id = ?)
        ");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "sii", $new_status, $booking_id, $driver_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
    echo json_encode(['success' => true, 'status' => $new_status]);
    exit();
}

// Complete ride
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_booking'])) {
    $booking_id = (int)$_POST['booking_id'];
    $stmt = mysqli_prepare($conn, "
        UPDATE bookings 
        SET status = 'completed', completed_at = NOW(), user_seen = 0
        WHERE id = ? AND driver_id = ? AND status = 'accepted'
    ");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "ii", $booking_id, $driver_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
    echo json_encode(['success' => true]);
    exit();
}

// Fetch pending bookings (driver_id is NULL or matches current driver)
$stmt = mysqli_prepare($conn, "
    SELECT b.*, u.name AS user_name, u.phone AS user_phone
    FROM bookings b
    LEFT JOIN users u ON b.user_id = u.id
    WHERE b.status = 'pending' AND (b.driver_id IS NULL OR b.driver_id = ?)
    ORDER BY b.created_at DESC
    LIMIT 20
");
mysqli_stmt_bind_param($stmt, "i", $driver_id);
mysqli_stmt_execute($stmt);
$pending_result = mysqli_stmt_get_result($stmt);
$pending_bookings = [];
while ($row = mysqli_fetch_assoc($pending_result)) {
    $pending_bookings[] = $row;
}

// Fetch driver's own bookings (where driver_id = current driver)
$stmt = mysqli_prepare($conn, "
    SELECT b.*, u.name AS user_name, u.phone AS user_phone
    FROM bookings b
    LEFT JOIN users u ON b.user_id = u.id
    WHERE b.driver_id = ?
    ORDER BY b.created_at DESC
");
mysqli_stmt_bind_param($stmt, "i", $driver_id);
mysqli_stmt_execute($stmt);
$my_result = mysqli_stmt_get_result($stmt);
$my_bookings = [];
while ($row = mysqli_fetch_assoc($my_result)) {
    $my_bookings[] = $row;
}

// Stats
$total_trips = 0;
$total_pending = 0;
foreach ($my_bookings as $b) {
    if ($b['status'] === 'completed') $total_trips++;
    if ($b['status'] === 'accepted') $total_pending++;
}

$balance   = number_format($driver['balance'] ?? 0, 2);
$rating    = $driver['rating'] ?? '5.0';
$driver_status = $driver['status'] ?? 'offline';   // 'online' or 'offline'
$is_online = ($driver_status === 'online');        // boolean for UI
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>TransNet X — Driver Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet"/>
<style>
/* ───────────────────────────── RESET & TOKENS ── */
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
:root {
  --gold:      #D4A843;
  --gold-lt:   #F0C96A;
  --gold-dk:   #9A7020;
  --black:     #080808;
  --deep:      #0D0D0D;
  --surface:   #141414;
  --glass:     rgba(255,255,255,0.04);
  --glass-b:   rgba(255,255,255,0.08);
  --border:    rgba(212,168,67,0.18);
  --text:      #E8E2D4;
  --muted:     rgba(232,226,212,0.45);
  --green:     #34D399;
  --red:       #F87171;
  --amber:     #FBBF24;
  --blue:      #60A5FA;
  --r:         14px;
  --r-sm:      8px;
  --sidebar-w: 260px;
}
html { scroll-behavior: smooth; }
body {
  font-family: 'DM Sans', sans-serif;
  background: var(--black);
  color: var(--text);
  min-height: 100vh;
  overflow-x: hidden;
}

/* ───────────────────────────── ANIMATED BG ───── */
.bg-anim {
  position: fixed; inset: 0; z-index: 0; pointer-events: none;
  overflow: hidden;
}
.bg-anim::before {
  content:'';
  position:absolute; inset:0;
  background:
    radial-gradient(ellipse 80% 60% at 10% 20%, rgba(212,168,67,0.07) 0%, transparent 60%),
    radial-gradient(ellipse 60% 50% at 90% 80%, rgba(212,168,67,0.05) 0%, transparent 60%),
    radial-gradient(ellipse 40% 40% at 50% 50%, rgba(13,13,13,1) 0%, transparent 100%);
  animation: bgPulse 8s ease-in-out infinite alternate;
}
@keyframes bgPulse {
  0%   { opacity:.7; transform:scale(1); }
  100% { opacity:1;  transform:scale(1.04); }
}

/* moving road lines */
.road-lines {
  position:absolute; bottom:0; left:0; right:0; height:3px;
  overflow:hidden;
}
.road-lines span {
  position:absolute; bottom:0; height:2px;
  background: linear-gradient(90deg, transparent, var(--gold), transparent);
  animation: road 3s linear infinite;
  opacity: .18;
}
.road-lines span:nth-child(1)  { width:120px; animation-delay:0s;    top:0;   }
.road-lines span:nth-child(2)  { width:80px;  animation-delay:1s;    top:8px; }
.road-lines span:nth-child(3)  { width:160px; animation-delay:2s;    top:4px; }
@keyframes road {
  0%   { transform: translateX(-200px); }
  100% { transform: translateX(110vw);  }
}

/* floating particles */
.particles { position:absolute; inset:0; overflow:hidden; }
.p { position:absolute; border-radius:50%;
     background: var(--gold); opacity:0;
     animation: float linear infinite; }
@keyframes float {
  0%   { opacity:0;    transform:translateY(100vh) scale(0); }
  10%  { opacity:.06; }
  90%  { opacity:.06; }
  100% { opacity:0;    transform:translateY(-10vh) scale(1); }
}

/* ───────────────────────────── LAYOUT ─────────── */
.layout { display:flex; min-height:100vh; position:relative; z-index:1; }

/* ───────────────────────────── SIDEBAR ────────── */
.sidebar {
  width: var(--sidebar-w);
  background: rgba(10,10,10,0.92);
  border-right: 1px solid var(--border);
  display: flex; flex-direction: column;
  position: fixed; top:0; left:0; bottom:0;
  z-index: 100;
  backdrop-filter: blur(20px);
  transition: transform .35s cubic-bezier(.4,0,.2,1);
}
.sidebar-logo {
  padding: 28px 24px 20px;
  border-bottom: 1px solid var(--border);
}
.sidebar-logo .logo-text {
  font-family: 'Syne', sans-serif;
  font-weight: 800;
  font-size: 20px;
  background: linear-gradient(135deg, var(--gold-lt), var(--gold));
  -webkit-background-clip: text; -webkit-text-fill-color: transparent;
  letter-spacing: -.3px;
}
.sidebar-logo .logo-sub {
  font-size: 10px; color: var(--muted); letter-spacing: 2px;
  text-transform: uppercase; margin-top: 2px;
}

/* driver card */
.driver-card {
  margin: 16px 12px;
  background: var(--glass);
  border: 1px solid var(--border);
  border-radius: var(--r);
  padding: 16px;
  display: flex; align-items: center; gap:12px;
}
.driver-avatar {
  width:46px; height:46px; border-radius:50%;
  background: linear-gradient(135deg, var(--gold-dk), var(--gold-lt));
  display:flex; align-items:center; justify-content:center;
  font-family:'Syne',sans-serif; font-weight:700; font-size:18px;
  color: var(--black); flex-shrink:0;
  box-shadow: 0 0 20px rgba(212,168,67,.35);
}
.driver-info .name {
  font-family:'Syne',sans-serif; font-weight:700; font-size:13px;
  white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.driver-info .role {
  font-size:10px; color:var(--muted); text-transform:uppercase;
  letter-spacing:1.5px; margin-top:2px;
}

/* online toggle */
.online-toggle-wrap {
  padding: 14px 16px;
  border-bottom: 1px solid var(--border);
}
.online-label { font-size:11px; color:var(--muted); text-transform:uppercase;
  letter-spacing:1.5px; margin-bottom:8px; }
.toggle-row { display:flex; align-items:center; justify-content:space-between; }
.toggle-status { font-size:13px; font-weight:500;
  transition: color .3s; }
.toggle-status.online  { color: var(--green); }
.toggle-status.offline { color: var(--muted); }
.switch { position:relative; width:50px; height:26px; }
.switch input { opacity:0; width:0; height:0; }
.slider {
  position:absolute; inset:0; border-radius:13px;
  background: rgba(255,255,255,0.08);
  border:1px solid rgba(255,255,255,0.12);
  cursor:pointer; transition:.35s cubic-bezier(.4,0,.2,1);
}
.slider::before {
  content:''; position:absolute;
  width:20px; height:20px; border-radius:50%;
  top:2px; left:2px;
  background: var(--muted);
  transition:.35s cubic-bezier(.4,0,.2,1);
  box-shadow: 0 2px 8px rgba(0,0,0,.4);
}
.switch input:checked + .slider {
  background: rgba(52,211,153,0.2);
  border-color: var(--green);
  box-shadow: 0 0 16px rgba(52,211,153,.25);
}
.switch input:checked + .slider::before {
  transform: translateX(24px);
  background: var(--green);
  box-shadow: 0 0 12px rgba(52,211,153,.5);
}

/* nav */
.sidebar-nav { flex:1; padding:8px 10px; overflow-y:auto; }
.nav-section { font-size:9px; text-transform:uppercase; letter-spacing:2px;
  color:var(--muted); padding:12px 10px 6px; }
.nav-item {
  display:flex; align-items:center; gap:12px;
  padding:11px 12px; border-radius:var(--r-sm);
  cursor:pointer; font-size:13.5px; font-weight:400;
  color:var(--muted); transition:all .2s;
  border:1px solid transparent; margin-bottom:2px;
  text-decoration:none;
}
.nav-item:hover {
  background: var(--glass); color:var(--text);
  border-color: var(--border);
}
.nav-item.active {
  background: linear-gradient(135deg, rgba(212,168,67,.12), rgba(212,168,67,.06));
  color:var(--gold-lt); border-color: var(--border);
}
.nav-item svg { width:16px; height:16px; flex-shrink:0; }
.nav-badge {
  margin-left:auto; background:var(--gold);
  color:var(--black); border-radius:10px;
  font-size:10px; font-weight:700; padding:1px 7px;
  animation: badgePop .4s ease;
}
@keyframes badgePop {
  0%   { transform:scale(0); }
  80%  { transform:scale(1.2); }
  100% { transform:scale(1); }
}

/* sidebar footer */
.sidebar-footer { padding:12px; border-top:1px solid var(--border); }
.logout-btn {
  display:flex; align-items:center; gap:10px;
  padding:10px 12px; border-radius:var(--r-sm);
  cursor:pointer; font-size:13px; color:var(--red);
  transition:all .2s; border:1px solid transparent;
  text-decoration:none; background:none; width:100%;
}
.logout-btn:hover { background:rgba(248,113,113,.08); border-color:rgba(248,113,113,.2); }

/* ───────────────────────────── MAIN ───────────── */
.main {
  margin-left: var(--sidebar-w);
  flex:1; display:flex; flex-direction:column;
  min-height:100vh;
}

/* topbar */
.topbar {
  position:sticky; top:0; z-index:50;
  background: rgba(8,8,8,0.85);
  backdrop-filter: blur(20px);
  border-bottom:1px solid var(--border);
  padding: 14px 28px;
  display:flex; align-items:center; justify-content:space-between;
}
.topbar-left h1 {
  font-family:'Syne',sans-serif; font-weight:700; font-size:18px;
}
.topbar-left p { font-size:12px; color:var(--muted); margin-top:2px; }
.topbar-right { display:flex; align-items:center; gap:14px; }

.notif-btn {
  position:relative; width:38px; height:38px;
  background:var(--glass); border:1px solid var(--border);
  border-radius:var(--r-sm); display:flex;
  align-items:center; justify-content:center;
  cursor:pointer; transition:all .2s; color:var(--muted);
}
.notif-btn:hover { border-color:var(--gold); color:var(--gold); }
.notif-dot {
  position:absolute; top:7px; right:7px;
  width:7px; height:7px; border-radius:50%;
  background:var(--gold); border:2px solid var(--black);
  animation: ping 2s ease-in-out infinite;
}
@keyframes ping {
  0%,100% { box-shadow: 0 0 0 0 rgba(212,168,67,.7); }
  50%      { box-shadow: 0 0 0 6px rgba(212,168,67,0); }
}

.time-chip {
  font-size:12px; color:var(--muted);
  background:var(--glass); border:1px solid var(--border);
  border-radius:20px; padding:5px 12px;
}

/* ───────────────────────────── CONTENT ────────── */
.content { padding:24px 28px; flex:1; }

/* sections */
.section { display:none; }
.section.active { display:block; animation: fadeUp .4s ease; }
@keyframes fadeUp {
  0%   { opacity:0; transform:translateY(16px); }
  100% { opacity:1; transform:translateY(0); }
}

/* ───────────────────────────── STAT CARDS ─────── */
.stats-grid {
  display:grid;
  grid-template-columns: repeat(4,1fr);
  gap:14px; margin-bottom:24px;
}
.stat-card {
  background: var(--glass);
  border:1px solid var(--border);
  border-radius:var(--r);
  padding:20px;
  position:relative; overflow:hidden;
  transition: transform .25s, box-shadow .25s;
}
.stat-card:hover {
  transform:translateY(-3px);
  box-shadow:0 12px 40px rgba(212,168,67,.08);
}
.stat-card::before {
  content:''; position:absolute; inset:0;
  background:linear-gradient(135deg,rgba(255,255,255,.02),transparent);
  pointer-events:none;
}
.stat-icon {
  width:38px; height:38px; border-radius:10px;
  display:flex; align-items:center; justify-content:center;
  margin-bottom:12px; font-size:18px;
}
.stat-icon.gold   { background:rgba(212,168,67,.12); color:var(--gold); }
.stat-icon.green  { background:rgba(52,211,153,.12); color:var(--green); }
.stat-icon.blue   { background:rgba(96,165,250,.12); color:var(--blue); }
.stat-icon.amber  { background:rgba(251,191,36,.12); color:var(--amber); }
.stat-value {
  font-family:'Syne',sans-serif; font-weight:800; font-size:26px;
  line-height:1; margin-bottom:4px;
  background:linear-gradient(135deg,var(--text),var(--muted));
  -webkit-background-clip:text; -webkit-text-fill-color:transparent;
}
.stat-label { font-size:11px; color:var(--muted); text-transform:uppercase;
  letter-spacing:1.2px; }
.stat-delta { font-size:11px; margin-top:6px; }
.stat-delta.up   { color:var(--green); }
.stat-delta.down { color:var(--red); }

/* glow line on stat cards */
.stat-card::after {
  content:''; position:absolute; bottom:0; left:0; right:0; height:2px;
  background:linear-gradient(90deg,transparent,var(--gold),transparent);
  opacity:.2;
}
.stat-card:hover::after { opacity:.6; }

/* ───────────────────────────── GRID 2-COL ─────── */
.grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:24px; }
.grid-3 { display:grid; grid-template-columns:2fr 1fr; gap:16px; margin-bottom:24px; }

/* ───────────────────────────── CARD ───────────── */
.card {
  background:var(--glass);
  border:1px solid var(--border);
  border-radius:var(--r);
  overflow:hidden;
}
.card-head {
  padding:16px 20px 14px;
  border-bottom:1px solid var(--border);
  display:flex; align-items:center; justify-content:space-between;
}
.card-head h2 {
  font-family:'Syne',sans-serif; font-size:14px; font-weight:700;
  display:flex; align-items:center; gap:8px;
}
.card-head h2 span.dot {
  width:7px; height:7px; border-radius:50%; background:var(--gold);
  display:inline-block; box-shadow:0 0 8px var(--gold);
}
.card-body { padding:16px 20px; }

/* ───────────────────────────── BOOKING REQUEST ── */
.request-list { display:flex; flex-direction:column; gap:10px; }
.request-item {
  background:rgba(255,255,255,0.025);
  border:1px solid var(--border);
  border-radius:var(--r-sm);
  padding:14px 16px;
  display:flex; align-items:center; gap:14px;
  transition:all .25s;
  animation: slideIn .4s ease;
}
@keyframes slideIn {
  0%   { opacity:0; transform:translateX(-10px); }
  100% { opacity:1; transform:translateX(0); }
}
.request-item:hover { border-color:rgba(212,168,67,.35); background:rgba(212,168,67,.03); }
.req-icon {
  width:42px; height:42px; border-radius:10px;
  background:rgba(212,168,67,.1); color:var(--gold);
  display:flex; align-items:center; justify-content:center;
  font-size:18px; flex-shrink:0;
}
.req-info { flex:1; min-width:0; }
.req-info .req-name { font-weight:600; font-size:13.5px; }
.req-info .req-route {
  font-size:11.5px; color:var(--muted); margin-top:2px;
  white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.req-info .req-meta { font-size:11px; color:var(--muted); margin-top:4px; }
.req-actions { display:flex; gap:8px; flex-shrink:0; }
.btn-accept, .btn-decline {
  padding:7px 14px; border-radius:20px;
  font-size:12px; font-weight:600; cursor:pointer;
  border:none; transition:all .2s; font-family:inherit;
}
.btn-accept {
  background:rgba(52,211,153,.15); color:var(--green);
  border:1px solid rgba(52,211,153,.3);
}
.btn-accept:hover { background:rgba(52,211,153,.25); transform:scale(1.04); }
.btn-decline {
  background:rgba(248,113,113,.1); color:var(--red);
  border:1px solid rgba(248,113,113,.25);
}
.btn-decline:hover { background:rgba(248,113,113,.2); transform:scale(1.04); }
.req-status {
  display:inline-flex; align-items:center; padding:4px 10px;
  border-radius:20px; font-size:11px; font-weight:600;
}
.status-pending  { background:rgba(251,191,36,.12); color:var(--amber); border:1px solid rgba(251,191,36,.25); }
.status-accepted { background:rgba(52,211,153,.12); color:var(--green); border:1px solid rgba(52,211,153,.25); }
.status-declined { background:rgba(248,113,113,.1);  color:var(--red);   border:1px solid rgba(248,113,113,.2); }
.status-completed{ background:rgba(96,165,250,.1);   color:var(--blue);  border:1px solid rgba(96,165,250,.2); }

/* empty state */
.empty-state {
  text-align:center; padding:36px 20px; color:var(--muted);
}
.empty-state .emoji { font-size:36px; margin-bottom:10px; }
.empty-state p { font-size:13px; }

/* ───────────────────────────── MAP PLACEHOLDER ── */
.map-mock {
  height:180px;
  background:
    repeating-linear-gradient(
      0deg, rgba(255,255,255,.02) 0, rgba(255,255,255,.02) 1px, transparent 1px, transparent 40px
    ),
    repeating-linear-gradient(
      90deg, rgba(255,255,255,.02) 0, rgba(255,255,255,.02) 1px, transparent 1px, transparent 40px
    ),
    linear-gradient(135deg, #0a0a0a, #111);
  border-radius:var(--r-sm);
  position:relative; overflow:hidden;
  display:flex; align-items:center; justify-content:center;
}
.map-mock::before {
  content:''; position:absolute;
  width:80px; height:80px; border-radius:50%;
  background:radial-gradient(circle, rgba(212,168,67,.2), transparent);
  animation:mapPing 2s ease-in-out infinite;
}
@keyframes mapPing {
  0%   { transform:scale(.8); opacity:1; }
  100% { transform:scale(2.5); opacity:0; }
}
.map-pin { font-size:28px; z-index:1; filter:drop-shadow(0 0 10px var(--gold)); }

/* ───────────────────────────── BALANCE CARD ───── */
.balance-card {
  background: linear-gradient(135deg, rgba(212,168,67,.12), rgba(212,168,67,.04));
  border:1px solid rgba(212,168,67,.3);
  border-radius:var(--r);
  padding:24px;
  text-align:center;
  position:relative; overflow:hidden;
}
.balance-card::before {
  content:''; position:absolute;
  width:200px; height:200px; border-radius:50%;
  background:radial-gradient(circle, rgba(212,168,67,.06), transparent);
  top:-60px; right:-60px;
}
.balance-label { font-size:11px; text-transform:uppercase; letter-spacing:2px; color:var(--muted); margin-bottom:8px; }
.balance-amount {
  font-family:'Syne',sans-serif; font-weight:800; font-size:38px;
  background:linear-gradient(135deg,var(--gold-lt),var(--gold));
  -webkit-background-clip:text; -webkit-text-fill-color:transparent;
  margin-bottom:4px;
}
.balance-currency { font-size:11px; color:var(--muted); }
.balance-badge {
  display:inline-flex; align-items:center; gap:5px;
  background:rgba(52,211,153,.1); color:var(--green);
  border:1px solid rgba(52,211,153,.25);
  border-radius:20px; font-size:11px; padding:4px 10px; margin-top:10px;
}

/* rating */
.rating-row {
  display:flex; align-items:center; justify-content:center; gap:4px;
  margin-top:16px; padding-top:16px; border-top:1px solid var(--border);
}
.star { color:var(--gold); font-size:14px; }
.star.empty { color:rgba(255,255,255,.12); }
.rating-num { font-family:'Syne',sans-serif; font-size:18px; font-weight:700;
  color:var(--gold); margin-left:6px; }

/* ───────────────────────────── ACTIVITY FEED ──── */
.activity-list { display:flex; flex-direction:column; gap:0; }
.activity-item {
  display:flex; align-items:flex-start; gap:12px;
  padding:12px 0; border-bottom:1px solid rgba(255,255,255,.04);
  transition: background .2s;
}
.activity-item:last-child { border-bottom:none; }
.act-icon {
  width:32px; height:32px; border-radius:8px;
  display:flex; align-items:center; justify-content:center;
  font-size:14px; flex-shrink:0; margin-top:1px;
}
.act-text { flex:1; }
.act-text .act-title { font-size:12.5px; font-weight:500; }
.act-text .act-time  { font-size:11px; color:var(--muted); margin-top:2px; }
.act-amount { font-size:13px; font-weight:700; }

/* ───────────────────────────── AI PANEL ───────── */
.ai-panel {
  background: linear-gradient(135deg, rgba(96,165,250,.06), rgba(212,168,67,.04));
  border:1px solid rgba(96,165,250,.2);
  border-radius:var(--r);
  overflow:hidden; display:flex; flex-direction:column;
  height:520px;
}
.ai-header {
  padding:16px 20px;
  border-bottom:1px solid rgba(96,165,250,.15);
  display:flex; align-items:center; gap:10px;
}
.ai-logo {
  width:36px; height:36px; border-radius:10px;
  background:linear-gradient(135deg,#3B82F6,#8B5CF6);
  display:flex; align-items:center; justify-content:center;
  font-size:16px;
  box-shadow:0 0 20px rgba(59,130,246,.3);
  animation: aiGlow 3s ease-in-out infinite;
}
@keyframes aiGlow {
  0%,100% { box-shadow:0 0 20px rgba(59,130,246,.3); }
  50%      { box-shadow:0 0 30px rgba(59,130,246,.6); }
}
.ai-header-info h3 {
  font-family:'Syne',sans-serif; font-weight:700; font-size:14px;
}
.ai-header-info p { font-size:11px; color:var(--muted); }
.ai-typing {
  display:inline-flex; gap:4px; align-items:center;
}
.ai-typing span {
  width:5px; height:5px; border-radius:50%;
  background:var(--blue); animation:typing 1.4s ease-in-out infinite;
}
.ai-typing span:nth-child(2) { animation-delay:.2s; }
.ai-typing span:nth-child(3) { animation-delay:.4s; }
@keyframes typing {
  0%,80%,100% { transform:scale(.6); opacity:.4; }
  40%         { transform:scale(1);   opacity:1; }
}

.ai-messages {
  flex:1; overflow-y:auto; padding:16px 20px;
  display:flex; flex-direction:column; gap:12px;
  scrollbar-width:thin; scrollbar-color:var(--border) transparent;
}
.msg { display:flex; gap:8px; align-items:flex-start; }
.msg.user { flex-direction:row-reverse; }
.msg-avatar {
  width:28px; height:28px; border-radius:50%; flex-shrink:0;
  display:flex; align-items:center; justify-content:center;
  font-size:12px; font-weight:700;
}
.msg.ai   .msg-avatar { background:linear-gradient(135deg,#3B82F6,#8B5CF6); color:#fff; }
.msg.user .msg-avatar { background:linear-gradient(135deg,var(--gold-dk),var(--gold)); color:var(--black); }
.msg-bubble {
  max-width:78%; padding:10px 14px;
  border-radius:14px; font-size:13px; line-height:1.55;
}
.msg.ai   .msg-bubble {
  background:rgba(96,165,250,.08); border:1px solid rgba(96,165,250,.15);
  border-top-left-radius:4px;
}
.msg.user .msg-bubble {
  background:rgba(212,168,67,.1); border:1px solid rgba(212,168,67,.2);
  border-top-right-radius:4px;
}

.ai-chips {
  padding:10px 20px; display:flex; flex-wrap:wrap; gap:6px;
  border-top:1px solid rgba(255,255,255,.04);
}
.chip {
  padding:5px 12px; border-radius:20px; font-size:11.5px;
  background:rgba(255,255,255,.04); border:1px solid var(--border);
  cursor:pointer; color:var(--muted); transition:all .2s; font-family:inherit;
}
.chip:hover { background:rgba(212,168,67,.08); border-color:var(--gold); color:var(--gold); }

.ai-input-row {
  padding:12px 16px;
  border-top:1px solid var(--border);
  display:flex; gap:8px;
}
.ai-input {
  flex:1; background:rgba(255,255,255,.04);
  border:1px solid var(--border); border-radius:30px;
  padding:10px 16px; color:var(--text);
  font-family:inherit; font-size:13px; outline:none;
  transition:border-color .2s;
}
.ai-input::placeholder { color:var(--muted); }
.ai-input:focus { border-color:rgba(96,165,250,.4); }
.ai-send {
  width:40px; height:40px; border-radius:50%;
  background:linear-gradient(135deg,#3B82F6,#8B5CF6);
  border:none; cursor:pointer; color:#fff;
  display:flex; align-items:center; justify-content:center;
  transition:all .2s; flex-shrink:0;
}
.ai-send:hover { transform:scale(1.1); box-shadow:0 0 16px rgba(59,130,246,.4); }

/* ───────────────────────────── BOOKING TABLE ──── */
.table-wrap { overflow-x:auto; }
table { width:100%; border-collapse:collapse; font-size:13px; }
th {
  text-align:left; padding:10px 14px;
  font-size:10px; text-transform:uppercase; letter-spacing:1.5px;
  color:var(--muted); border-bottom:1px solid var(--border);
  font-weight:500; white-space:nowrap;
}
td {
  padding:12px 14px; border-bottom:1px solid rgba(255,255,255,.03);
  vertical-align:middle;
}
tr:hover td { background:rgba(255,255,255,.015); }
tr:last-child td { border-bottom:none; }
.td-name { font-weight:500; }
.td-route { color:var(--muted); font-size:12px; }

/* ───────────────────────────── SETTINGS ───────── */
.settings-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
.form-group { margin-bottom:16px; }
.form-label { font-size:11px; text-transform:uppercase; letter-spacing:1.2px;
  color:var(--muted); margin-bottom:6px; display:block; }
.form-input {
  width:100%; background:rgba(255,255,255,.04);
  border:1px solid var(--border); border-radius:var(--r-sm);
  padding:11px 14px; color:var(--text);
  font-family:inherit; font-size:13.5px; outline:none;
  transition:border-color .2s;
}
.form-input:focus { border-color:rgba(212,168,67,.5); }
.btn-primary {
  padding:11px 24px; border-radius:var(--r-sm);
  background:linear-gradient(135deg,var(--gold-dk),var(--gold));
  border:none; color:var(--black);
  font-family:'Syne',sans-serif; font-size:13px; font-weight:700;
  cursor:pointer; transition:all .25s; letter-spacing:.3px;
}
.btn-primary:hover { transform:translateY(-2px); box-shadow:0 8px 24px rgba(212,168,67,.3); }

/* ───────────────────────────── TOAST ──────────── */
.toast-container { position:fixed; bottom:24px; right:24px; z-index:9999;
  display:flex; flex-direction:column; gap:8px; }
.toast {
  background:rgba(20,20,20,.95); border:1px solid var(--border);
  border-radius:var(--r-sm); padding:12px 18px;
  font-size:13px; display:flex; align-items:center; gap:10px;
  backdrop-filter:blur(16px); min-width:240px;
  animation: toastIn .35s ease, toastOut .35s ease 3.65s forwards;
  box-shadow:0 8px 32px rgba(0,0,0,.5);
}
@keyframes toastIn  { 0%{opacity:0;transform:translateX(30px)} 100%{opacity:1;transform:none} }
@keyframes toastOut { 0%{opacity:1} 100%{opacity:0;transform:translateX(30px)} }
.toast.success { border-color:rgba(52,211,153,.4); }
.toast.error   { border-color:rgba(248,113,113,.4); }

/* ───────────────────────────── QUICK ACTIONS ──── */
.quick-actions { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:20px; }
.qa-btn {
  background:var(--glass); border:1px solid var(--border);
  border-radius:var(--r-sm); padding:14px 10px;
  text-align:center; cursor:pointer; transition:all .2s;
  color:var(--muted); text-decoration:none;
}
.qa-btn:hover { border-color:var(--gold); color:var(--gold); transform:translateY(-2px); }
.qa-btn .qa-icon { font-size:22px; margin-bottom:6px; }
.qa-btn .qa-label { font-size:11px; text-transform:uppercase; letter-spacing:1px; }

/* ───────────────────────────── SECTION TITLE ──── */
.section-title {
  font-family:'Syne',sans-serif; font-size:15px; font-weight:700;
  margin-bottom:14px; display:flex; align-items:center; gap:8px;
  color:var(--text);
}
.section-title::after {
  content:''; flex:1; height:1px;
  background:linear-gradient(90deg,var(--border),transparent);
}

/* ───────────────────────────── SCROLLBAR ───────── */
::-webkit-scrollbar { width:5px; height:5px; }
::-webkit-scrollbar-track { background:transparent; }
::-webkit-scrollbar-thumb { background:var(--border); border-radius:4px; }

/* ───────────────────────────── RESPONSIVE ─────── */
@media(max-width:1100px){
  .stats-grid { grid-template-columns:repeat(2,1fr); }
  .grid-2, .grid-3 { grid-template-columns:1fr; }
  .quick-actions { grid-template-columns:repeat(2,1fr); }
  .settings-grid { grid-template-columns:1fr; }
}
@media(max-width:760px){
  :root{ --sidebar-w:0px; }
  .sidebar { transform:translateX(-260px); width:260px; }
  .sidebar.open { transform:translateX(0); }
  .main { margin-left:0; }
  .stats-grid { grid-template-columns:1fr 1fr; }
  .topbar { padding:12px 16px; }
  .content { padding:16px; }
  .hamburger { display:flex !important; }
}

/* hamburger */
.hamburger {
  display:none; flex-direction:column; gap:5px;
  cursor:pointer; padding:8px; background:var(--glass);
  border:1px solid var(--border); border-radius:var(--r-sm);
}
.hamburger span {
  width:20px; height:2px; background:var(--text); border-radius:2px;
  transition:all .3s;
}

/* online pulse indicator */
.online-indicator {
  display:inline-flex; align-items:center; gap:5px;
  font-size:11px; padding:4px 10px;
  border-radius:20px; transition:all .3s;
}
.online-indicator.on  { background:rgba(52,211,153,.1); color:var(--green);
  border:1px solid rgba(52,211,153,.25); }
.online-indicator.off { background:rgba(255,255,255,.04); color:var(--muted);
  border:1px solid var(--border); }
.online-indicator .pulse {
  width:6px; height:6px; border-radius:50%;
}
.online-indicator.on .pulse {
  background:var(--green);
  animation: ping 1.5s ease-in-out infinite;
}
.online-indicator.off .pulse { background:var(--muted); }

/* shimmer loader */
.shimmer {
  background: linear-gradient(90deg,
    rgba(255,255,255,.04) 0%,
    rgba(255,255,255,.08) 50%,
    rgba(255,255,255,.04) 100%);
  background-size:200% 100%;
  animation: shimmer 1.5s infinite;
  border-radius:6px;
}
@keyframes shimmer { 0%{background-position:200% 0} 100%{background-position:-200% 0} }

/* counter animation helper */
.count-up { transition: all .3s; }
</style>
</head>
<body>

<!-- animated background -->
<div class="bg-anim">
  <div class="particles" id="particles"></div>
  <div class="road-lines">
    <span></span><span></span><span></span>
  </div>
</div>

<div class="layout">

  <!-- ═══════════ SIDEBAR ═══════════ -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-logo">
      <div class="logo-text">TransNet X</div>
      <div class="logo-sub">Driver Portal</div>
    </div>

    <!-- driver card -->
    <div class="driver-card">
      <div class="driver-avatar"><?= strtoupper(substr($driver['name'],0,1)) ?></div>
      <div class="driver-info">
        <div class="name"><?= htmlspecialchars($driver['name']) ?></div>
        <div class="role">Professional Driver</div>
      </div>
    </div>

    <!-- online toggle -->
    <div class="online-toggle-wrap">
      <div class="online-label">Driver Status</div>
      <div class="toggle-row">
        <span class="toggle-status <?= $is_online?'online':'offline' ?>" id="toggleLabel">
          <?= $is_online ? '● Online' : '○ Offline' ?>
        </span>
        <label class="switch">
          <input type="checkbox" id="onlineSwitch" <?= $is_online?'checked':'' ?>>
          <span class="slider"></span>
        </label>
      </div>
    </div>

    <!-- nav -->
    <nav class="sidebar-nav">
      <div class="nav-section">Main</div>
      <a class="nav-item active" data-section="dashboard" href="#">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
        Dashboard
      </a>
        <div class="nav-section">Info & Support</div>
        <a class="nav-item" href="../user/activity_history.php"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Activity History</a>
        <a class="nav-item" href="../user/privacy.php"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg> Privacy</a>
        <a class="nav-item" href="../user/terms.php"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg> Terms</a>
        <a class="nav-item" href="../user/contact.php"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg> Contact</a>
        <a class="nav-item" href="../user/emergency.php"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> Emergency</a>
      <a class="nav-item" data-section="requests" href="#">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>
        New Requests
        <?php if(count($pending_bookings)>0): ?>
        <span class="nav-badge"><?= count($pending_bookings) ?></span>
        <?php endif; ?>
      </a>
      <a class="nav-item" data-section="bookings" href="#">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
        History
      </a>

      <div class="nav-section">Finance</div>
      <a class="nav-item" data-section="earnings" href="#">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        Earnings & Balance
      </a>

      <div class="nav-section">Tools</div>
      <a class="nav-item" data-section="ai" href="#">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M12 1v4M12 19v4M4.22 4.22l2.83 2.83M16.95 16.95l2.83 2.83M1 12h4M19 12h4M4.22 19.78l2.83-2.83M16.95 7.05l2.83-2.83"/></svg>
        AI Assistant
      </a>
      <a class="nav-item" data-section="settings" href="#">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
        Profile & Settings
      </a>
    </nav>

    <div class="sidebar-footer">
      <a href="../auth/logout.php" class="logout-btn">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        Logout
      </a>
    </div>
  </aside>

  <!-- ═══════════ MAIN ═══════════ -->
  <main class="main">

    <!-- topbar -->
    <div class="topbar">
      <div style="display:flex;align-items:center;gap:14px;">
        <button class="hamburger" id="hamburger">
          <span></span><span></span><span></span>
        </button>
        <div class="topbar-left">
          <h1>Driver Dashboard</h1>
          <p>Welcome back, <?= htmlspecialchars(explode(' ',$driver['name'])[0]) ?> <svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"/></svg></p>
        </div>
      </div>
      <div class="topbar-right">
        <div class="online-indicator <?= $is_online?'on':'off' ?>" id="topIndicator">
          <span class="pulse"></span>
          <span id="topIndLabel"><?= $is_online?'Online':'Offline' ?></span>
        </div>
        <div class="time-chip" id="clock">--:--</div>
        <div class="notif-btn" onclick="navigate('requests')">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
          <?php if(count($pending_bookings)>0): ?>
          <span class="notif-dot"></span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- content -->
    <div class="content">

      <!-- ━━━━━━━━━━━━ DASHBOARD ━━━━━━━━━━━━ -->
      <div class="section active" id="sec-dashboard">
        <div class="stats-grid">
          <div class="stat-card">
            <div class="stat-icon gold"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="6" x2="12" y2="12"/><line x1="12" y1="18" x2="12.01" y2="18"/><path d="M9 12h6"/></svg></div>
            <div class="stat-value">₦<?= $balance ?></div>
            <div class="stat-label">Current Balance</div>
            <div class="stat-delta up">↑ Updated from DB</div>
          </div>
          <div class="stat-card">
            <div class="stat-icon green"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>
            <div class="stat-value count-up" data-target="<?= $total_trips ?>">0</div>
            <div class="stat-label">Completed Trips</div>
            <div class="stat-delta up">↑ All time</div>
          </div>
          <div class="stat-card">
            <div class="stat-icon blue"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="M12 15l-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/></svg></div>
            <div class="stat-value count-up" data-target="<?= $total_pending ?>">0</div>
            <div class="stat-label">Active Rides</div>
            <div class="stat-delta">In progress</div>
          </div>
          <div class="stat-card">
            <div class="stat-icon amber">⭐</div>
            <div class="stat-value"><?= $rating ?></div>
            <div class="stat-label">Driver Rating</div>
            <div class="stat-delta up">↑ Excellent</div>
          </div>
        </div>

        <!-- quick actions -->
        <div class="quick-actions">
          <a class="qa-btn" href="#" onclick="navigate('requests')">
            <div class="qa-icon"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg></div>
            <div class="qa-label">New Requests</div>
          </a>
          <a class="qa-btn" href="#" onclick="navigate('bookings')">
            <div class="qa-icon"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="14" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
            <div class="qa-label">My Bookings</div>
          </a>
          <a class="qa-btn" href="#" onclick="navigate('ai')">
            <div class="qa-icon"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg></div>
            <div class="qa-label">AI Assistant</div>
          </a>
          <a class="qa-btn" href="#" onclick="navigate('earnings')">
            <div class="qa-icon"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg></div>
            <div class="qa-label">Earnings</div>
          </a>
        </div>

        <div class="grid-3">
          <!-- pending requests preview -->
          <div class="card">
            <div class="card-head">
              <h2><span class="dot"></span>Incoming Requests</h2>
              <a href="#" onclick="navigate('requests')" style="font-size:11px;color:var(--gold);text-decoration:none;">View all →</a>
            </div>
            <div class="card-body">
              <?php if(empty($pending_bookings)): ?>
              <div class="empty-state">
                <div class="emoji"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg></div>
                <p>No pending requests right now.</p>
              </div>
              <?php else: ?>
              <div class="request-list">
                <?php foreach(array_slice($pending_bookings,0,3) as $bk): ?>
                <div class="request-item" id="req-<?= $bk['id'] ?>">
                  <div class="req-icon"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 7 20 7 23 10 23 16 16 16 16 7"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg></div>
                  <div class="req-info">
                    <div class="req-name"><?= htmlspecialchars($bk['user_name'] ?? 'Passenger') ?></div>
                    <div class="req-route">
                      <svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="10" r="3"/><path d="M12 21.7C17.3 17 20 13 20 10a8 8 0 1 0-16 0c0 3 2.7 7 8 11.7z"/></svg> <?= htmlspecialchars($bk['pickup_location'] ?? 'Unknown') ?>
                      → <svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg> <?= htmlspecialchars($bk['dropoff_location'] ?? 'Unknown') ?>
                    </div>
                    <div class="req-meta">
                      <svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg> ₦<?= number_format($bk['fare'] ?? 0,2) ?>
                      &nbsp;|&nbsp; <svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg> <?= htmlspecialchars($bk['user_phone'] ?? 'N/A') ?>
                    </div>
                  </div>
                  <div class="req-actions">
                    <button class="btn-accept" onclick="respondBooking(<?= $bk['id'] ?>,'accept')"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Accept</button>
                    <button class="btn-decline" onclick="respondBooking(<?= $bk['id'] ?>,'decline')"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
                  </div>
                </div>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- balance + rating -->
          <div>
            <div class="balance-card" style="margin-bottom:14px;">
              <div class="balance-label">Available Balance</div>
              <div class="balance-amount">₦<?= $balance ?></div>
              <div class="balance-currency">NGN · Updated from database</div>
              <div class="balance-badge"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg> Payout Available</div>
              <div class="rating-row">
                <?php
                  $r = (float)$rating;
                  for($i=1;$i<=5;$i++){
                    echo '<span class="star '.($i<=$r?'':'empty').'"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="currentColor" stroke="none"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg></span>';
                  }
                ?>
                <span class="rating-num"><?= $rating ?></span>
              </div>
            </div>

            <!-- map placeholder -->
            <div class="card">
              <div class="card-head">
                <h2><span class="dot"></span>Live Map</h2>
                <span style="font-size:10px;color:var(--muted);">GPS Active</span>
              </div>
              <div class="card-body" style="padding:12px;">
                <div class="map-mock">
                  <div class="map-pin"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="10" r="3"/><path d="M12 21.7C17.3 17 20 13 20 10a8 8 0 1 0-16 0c0 3 2.7 7 8 11.7z"/></svg></div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- activity feed -->
        <div class="card">
          <div class="card-head">
            <h2><span class="dot"></span>Recent Activity</h2>
          </div>
          <div class="card-body">
            <?php if(empty($my_bookings)): ?>
            <div class="empty-state"><div class="emoji"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg></div><p>No activity yet.</p></div>
            <?php else: ?>
            <div class="activity-list">
              <?php foreach(array_slice($my_bookings,0,6) as $bk): ?>
              <?php
                $statusMap = [
  'completed' => ['icon'=>'<svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>', 'text'=>'Trip Completed'],
  'accepted'  => ['icon'=>'<svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 7 20 7 23 10 23 16 16 16 16 7"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>', 'text'=>'Ride Accepted'],
  'declined'  => ['icon'=>'<svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>', 'text'=>'Ride Declined'],
  'pending'   => ['icon'=>'<svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>','text'=>'Pending Request'],
];

$meta = $statusMap[$bk['status']] ?? ['icon'=>'<svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg>','text'=>'Activity'];
              ?>
              <div class="activity-item">
                <div class="act-icon" style="background:var(--glass);"><?= $meta['icon'] ?></div>
                <div class="act-text">
                  <div class="act-title"><?= $meta['text'] ?> · <?= htmlspecialchars($bk['pickup_location'] ?? 'Unknown') ?> → <?= htmlspecialchars($bk['dropoff_location'] ?? 'Unknown') ?></div>
                  <div class="act-time"><?= date('M d, H:i', strtotime($bk['created_at'])) ?></div>
                </div>
                <span class="req-status status-<?= $bk['status'] ?>"><?= ucfirst($bk['status']) ?></span>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- ━━━━━━━━━━━━ REQUESTS ━━━━━━━━━━━━ -->
      <div class="section" id="sec-requests">
        <div class="section-title"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg> New Booking Requests</div>
        <?php if(empty($pending_bookings)): ?>
        <div class="empty-state" style="padding:60px;">
          <div class="emoji"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg></div>
          <p>No pending ride requests at the moment.<br>Toggle online to receive bookings.</p>
        </div>
        <?php else: ?>
        <div class="request-list" id="requestList">
          <?php foreach($pending_bookings as $bk): ?>
          <div class="request-item" id="req-<?= $bk['id'] ?>">
            <div class="req-icon"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 7 20 7 23 10 23 16 16 16 16 7"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg></div>
            <div class="req-info">
              <div class="req-name"><?= htmlspecialchars($bk['user_name'] ?? 'Passenger') ?></div>
              <div class="req-route">
                <svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="10" r="3"/><path d="M12 21.7C17.3 17 20 13 20 10a8 8 0 1 0-16 0c0 3 2.7 7 8 11.7z"/></svg> <?= htmlspecialchars($bk['pickup_location'] ?? 'N/A') ?>
                &nbsp;→&nbsp;
                <svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg> <?= htmlspecialchars($bk['dropoff_location'] ?? 'N/A') ?>
              </div>
              <div class="req-meta">
                <svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg> Fare: <strong>₦<?= number_format($bk['fare'] ?? 0,2) ?></strong>
                &nbsp;·&nbsp; <svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg> <?= htmlspecialchars($bk['user_phone'] ?? 'N/A') ?>
                &nbsp;·&nbsp; <svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> <?= date('H:i · M d', strtotime($bk['created_at'])) ?>
              </div>
            </div>
            <div class="req-actions">
              <button class="btn-accept" onclick="respondBooking(<?= $bk['id'] ?>,'accept')"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Accept</button>
              <button class="btn-decline" onclick="respondBooking(<?= $bk['id'] ?>,'decline')"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Decline</button>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- ━━━━━━━━━━━━ MY BOOKINGS ━━━━━━━━━━━━ -->
      <div class="section" id="sec-bookings">
        <div class="section-title"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="14" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg> My Bookings</div>
        <div class="card">
          <div class="card-body" style="padding:0;">
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Passenger</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Fare</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if(empty($my_bookings)): ?>
                  <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:40px;">No bookings found.</td></tr>
                  <?php else: ?>
                  <?php foreach($my_bookings as $bk): ?>
                  <tr>
                    <td style="color:var(--muted);font-size:12px;">#<?= $bk['id'] ?></td>
                    <td class="td-name"><?= htmlspecialchars($bk['user_name'] ?? '—') ?></td>
                    <td class="td-route"><?= htmlspecialchars($bk['pickup_location'] ?? '—') ?></td>
                    <td class="td-route"><?= htmlspecialchars($bk['dropoff_location'] ?? '—') ?></td>
                    <td style="font-weight:600;color:var(--gold);">₦<?= number_format($bk['fare']??0,2) ?></td>
                    <td><span class="req-status status-<?= $bk['status'] ?>"><?= ucfirst($bk['status']) ?></span></td>
                    <td style="color:var(--muted);font-size:12px;"><?= date('M d, Y', strtotime($bk['created_at'])) ?></td>
                    <td>
  <?php if($bk['status'] === 'accepted'): ?>
    <button class="btn-accept" onclick="completeRide(<?= $bk['id'] ?>)">
      <svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Complete
    </button>
  <?php else: ?>
    <span style="color:var(--muted);font-size:12px;">—</span>
  <?php endif; ?>
</td>
                  </tr>
                  <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <!-- ━━━━━━━━━━━━ EARNINGS ━━━━━━━━━━━━ -->
      <div class="section" id="sec-earnings">
        <div class="section-title"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg> Earnings & Balance</div>
        <div class="grid-2" style="margin-bottom:16px;">
          <div class="balance-card">
            <div class="balance-label">Total Balance</div>
            <div class="balance-amount">₦<?= $balance ?></div>
            <div class="balance-currency">NGN · Managed from admin panel</div>
            <div class="balance-badge"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg> Bank payout eligible</div>
          </div>
          <div class="card">
            <div class="card-head"><h2><span class="dot"></span>Trip Summary</h2></div>
            <div class="card-body">
              <?php
                $comp  = count(array_filter($my_bookings,fn($b)=>$b['status']==='completed'));
                $acc   = count(array_filter($my_bookings,fn($b)=>$b['status']==='accepted'));
                $dec   = count(array_filter($my_bookings,fn($b)=>$b['status']==='declined'));
                $total = count($my_bookings);
              ?>
              <div style="display:flex;flex-direction:column;gap:12px;">
                <?php foreach([
                  ['Completed','<svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',$comp,'green'],
                  ['Active','<svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg>',$acc,'blue'],
                  ['Declined','<svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',$dec,'red'],
                  ['Total Bookings','<svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="14" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',$total,'gold'],
                ] as [$label,$icon,$val,$clr]): ?>
                <div style="display:flex;align-items:center;justify-content:space-between;">
                  <span style="font-size:13px;color:var(--muted);"><?=$icon?> <?=$label?></span>
                  <span style="font-weight:700;color:var(--<?=$clr?>);"><?=$val?></span>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>
        <div class="card">
          <div class="card-head"><h2><span class="dot"></span>Earnings History</h2></div>
          <div class="card-body">
            <div class="table-wrap">
              <table>
                <thead>
                  <tr><th>Date</th><th>Passenger</th><th>Route</th><th>Fare</th><th>Status</th></tr>
                </thead>
                <tbody>
                  <?php foreach($my_bookings as $bk): ?>
                  <tr>
                    <td style="color:var(--muted);font-size:12px;"><?= date('M d', strtotime($bk['created_at'])) ?></td>
                    <td><?= htmlspecialchars($bk['user_name']??'—') ?></td>
                    <td class="td-route"><?= htmlspecialchars($bk['pickup_location']??'Unknown') ?> → <?= htmlspecialchars($bk['dropoff_location']??'Unknown') ?></td>
                    <td style="color:var(--gold);font-weight:700;">₦<?= number_format($bk['fare']??0,2) ?></td>
                    <td><span class="req-status status-<?= $bk['status'] ?>"><?= ucfirst($bk['status']) ?></span></td>
                  </tr>
                  <?php endforeach; ?>
                  <?php if(empty($my_bookings)): ?>
                  <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:30px;">No earnings yet.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <!-- ━━━━━━━━━━━━ AI ASSISTANT ━━━━━━━━━━━━ -->
      <div class="section" id="sec-ai">
        <div class="section-title"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg> AI Assistant — TransNet X</div>
        <div class="ai-panel">
          <div class="ai-header">
            <div class="ai-logo"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg></div>
            <div class="ai-header-info">
              <h3>TransNet AI</h3>
              <p>Questions · Suggestions · Tourism · Navigation</p>
            </div>
            <div style="margin-left:auto;">
              <div class="ai-typing" id="aiTyping" style="display:none;">
                <span></span><span></span><span></span>
              </div>
            </div>
          </div>

          <div class="ai-messages" id="aiMessages">
            <div class="msg ai">
              <div class="msg-avatar">AI</div>
              <div class="msg-bubble">
                <svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"/></svg> Hello <?= htmlspecialchars(explode(' ',$driver['name'])[0]) ?>! I'm your TransNet AI assistant.<br><br>
                I can help with <strong>route suggestions</strong>, <strong>tourism spots</strong> at destinations, 
                <strong>driving tips</strong>, customer service, and anything else you need on the road! <svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 7 20 7 23 10 23 16 16 16 16 7"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
              </div>
            </div>
          </div>

          <div class="ai-chips">
            <button class="chip" onclick="quickMsg('Best routes to avoid traffic?')"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="3 6 9 3 15 6 21 3 21 18 15 21 9 18 3 21"/><line x1="9" y1="3" x2="9" y2="18"/><line x1="15" y1="6" x2="15" y2="21"/></svg> Best Routes</button>
            <button class="chip" onclick="quickMsg('Tourist spots near me to suggest to customers')"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>️ Tourism Tips</button>
            <button class="chip" onclick="quickMsg('How can I improve my driver rating?')">⭐ Improve Rating</button>
            <button class="chip" onclick="quickMsg('Safety tips for night driving')"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg> Night Safety</button>
            <button class="chip" onclick="quickMsg('How to handle difficult passengers?')"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg> Passenger Tips</button>
          </div>

          <div class="ai-input-row">
            <input class="ai-input" id="aiInput" type="text"
              placeholder="Ask anything — routes, tourism, tips..." 
              onkeydown="if(event.key==='Enter')sendAI()"/>
            <button class="ai-send" onclick="sendAI()">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>
              </svg>
            </button>
          </div>
        </div>
      </div>

      <!-- ━━━━━━━━━━━━ SETTINGS ━━━━━━━━━━━━ -->
      <div class="section" id="sec-settings">
        <div class="section-title"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>️ Profile & Settings</div>
        <div class="card">
          <div class="card-head"><h2><span class="dot"></span>Personal Information</h2></div>
          <div class="card-body">
            <form method="POST" action="update_driver.php">
              <div class="settings-grid">
                <div class="form-group">
                  <label class="form-label">Full Name</label>
                  <input class="form-input" type="text" name="name" value="<?= htmlspecialchars($driver['name']) ?>"/>
                </div>
                <div class="form-group">
                  <label class="form-label">Email Address</label>
                  <input class="form-input" type="email" name="email" value="<?= htmlspecialchars($driver['email'] ?? '') ?>"/>
                </div>
                <div class="form-group">
                  <label class="form-label">Phone Number</label>
                  <input class="form-input" type="tel" name="phone" value="<?= htmlspecialchars($driver['phone'] ?? '') ?>"/>
                </div>
                <div class="form-group">
                  <label class="form-label">License Number</label>
                  <input class="form-input" type="text" name="license" value="<?= htmlspecialchars($driver['license_number'] ?? '') ?>"/>
                </div>
                <div class="form-group">
                  <label class="form-label">Vehicle Model</label>
                  <input class="form-input" type="text" name="vehicle" value="<?= htmlspecialchars($driver['vehicle_model'] ?? '') ?>"/>
                </div>
                <div class="form-group">
                  <label class="form-label">Plate Number</label>
                  <input class="form-input" type="text" name="plate" value="<?= htmlspecialchars($driver['plate_number'] ?? '') ?>"/>
                </div>
              </div>
              <button type="submit" class="btn-primary"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Save Changes</button>
            </form>
          </div>
        </div>

        <div class="card" style="margin-top:16px;">
          <div class="card-head"><h2><span class="dot"></span>Change Password</h2></div>
          <div class="card-body">
            <form method="POST" action="change_password.php">
              <div class="settings-grid">
                <div class="form-group">
                  <label class="form-label">Current Password</label>
                  <input class="form-input" type="password" name="current_password" placeholder="••••••••"/>
                </div>
                <div class="form-group">
                  <label class="form-label">New Password</label>
                  <input class="form-input" type="password" name="new_password" placeholder="••••••••"/>
                </div>
              </div>
              <button type="submit" class="btn-primary"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> Update Password</button>
            </form>
          </div>
        </div>
      </div>

    </div><!-- /content -->
  </main>
</div>

<!-- toast container -->
<div class="toast-container" id="toastContainer"></div>

<script>
// ─── PARTICLES ──────────────────────────────────────
(function(){
  const c=document.getElementById('particles');
  for(let i=0;i<18;i++){
    const p=document.createElement('div');
    p.className='p';
    const s=Math.random()*5+3;
    p.style.cssText=`
      width:${s}px;height:${s}px;
      left:${Math.random()*100}%;
      animation-duration:${Math.random()*20+15}s;
      animation-delay:${Math.random()*-20}s;
    `;
    c.appendChild(p);
  }
})();

// ─── CLOCK ──────────────────────────────────────────
function updateClock(){
  const now=new Date();
  document.getElementById('clock').textContent=
    now.toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit'});
}
setInterval(updateClock,1000); updateClock();

// ─── NAVIGATION ─────────────────────────────────────
function navigate(section){
  document.querySelectorAll('.section').forEach(s=>s.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n=>n.classList.remove('active'));
  document.getElementById('sec-'+section).classList.add('active');
  document.querySelector('[data-section="'+section+'"]').classList.add('active');
  // close mobile sidebar
  document.getElementById('sidebar').classList.remove('open');
  return false;
}
document.querySelectorAll('.nav-item').forEach(item=>{
  item.addEventListener('click',function(e){
    e.preventDefault();
    navigate(this.dataset.section);
  });
});

// ─── HAMBURGER ──────────────────────────────────────
document.getElementById('hamburger').onclick=function(){
  document.getElementById('sidebar').classList.toggle('open');
};

// ─── COUNT UP ───────────────────────────────────────
document.querySelectorAll('.count-up').forEach(el=>{
  const target=parseInt(el.dataset.target)||0;
  let cur=0;
  const step=Math.max(1,Math.floor(target/30));
  const t=setInterval(()=>{
    cur=Math.min(cur+step,target);
    el.textContent=cur;
    if(cur>=target)clearInterval(t);
  },40);
});

// ─── ONLINE TOGGLE (updated for ENUM) ───────────────
document.getElementById('onlineSwitch').addEventListener('change',function(){
  const online = this.checked ? 1 : 0;
  fetch('', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'toggle_online='+online
  })
  .then(r=>r.json())
  .then(d=>{
    if(d.success){
      const label = document.getElementById('toggleLabel');
      const topInd = document.getElementById('topIndicator');
      const topLbl = document.getElementById('topIndLabel');
      if(d.status === 'online'){
        label.textContent='● Online'; label.className='toggle-status online';
        topInd.className='online-indicator on'; topLbl.textContent='Online';
        showToast('You are now Online — customers can find you!','success');
      } else {
        label.textContent='○ Offline'; label.className='toggle-status offline';
        topInd.className='online-indicator off'; topLbl.textContent='Offline';
        showToast('You are now Offline.','');
      }
    }
  });
});

// ─── BOOKING RESPOND ────────────────────────────────
function respondBooking(id, action){
  fetch('',{
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'respond_booking=1&booking_id='+id+'&action='+action
  })
  .then(r=>r.json())
  .then(d=>{
    if(d.success){
      const el = document.getElementById('req-'+id);
      if(action === 'accept'){
        showToast('Booking #'+id+' accepted!','success');
      } else {
        showToast('Booking #'+id+' declined.','error');
      }
      if(el){
        el.style.transition = 'all .3s ease';
        el.style.opacity = '0';
        el.style.transform = 'translateX(20px)';
        setTimeout(()=> el.remove(), 300);
      }
      setTimeout(()=> location.reload(), 800);
    }
  });
}
function completeRide(id){
  fetch('', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'complete_booking=1&booking_id='+id
  })
  .then(r=>r.json())
  .then(d=>{
    if(d.success){
      showToast('Ride completed!','success');
      setTimeout(()=>location.reload(),800);
    }
  });
}
// ─── AI ASSISTANT ───────────────────────────────────
const aiMsgs=[];
function quickMsg(text){
  document.getElementById('aiInput').value=text;
  sendAI();
}

async function sendAI(){
  const inp = document.getElementById('aiInput');
  const text = inp.value.trim();
  if(!text) return;

  inp.value = '';

  // add user message
  addMessage('user', text);
  aiMsgs.push({ role:'user', content:text });

  // show typing
  const typing = document.getElementById('aiTyping');
  typing.style.display = 'inline-flex';

  try {
    const res = await fetch('ai.php', {
      method: 'POST',
      headers: { 'Content-Type':'application/json' },
      body: JSON.stringify({
        messages: aiMsgs
      })
    });

    const data = await res.json();
    console.log(data);
    typing.style.display = 'none';

    const reply = data.reply || 'No response';

    aiMsgs.push({ role:'assistant', content:reply });
    addMessage('ai', reply);

  } catch(e){
    typing.style.display = 'none';
    addMessage('ai','⚠️ Connection error. Please check your internet and try again.');
  }
}

function addMessage(role,text){
  const box=document.getElementById('aiMessages');
  const div=document.createElement('div');
  div.className='msg '+role;
  const initials=role==='ai'?'AI':<?= json_encode(strtoupper(substr($driver['name'],0,1))) ?>;
  const bubble = document.createElement('div');
  bubble.className = 'msg-bubble';
  bubble.textContent = text;
  bubble.innerHTML = bubble.innerHTML.replace(/\n/g, '<br>');
  div.innerHTML = `<div class="msg-avatar">${initials}</div>`;
  div.appendChild(bubble);
  div.style.animation='fadeUp .35s ease';
  box.appendChild(div);
  box.scrollTop=box.scrollHeight;
}

// ─── TOAST ──────────────────────────────────────────
function showToast(msg,type){
  const c=document.getElementById('toastContainer');
  const t=document.createElement('div');
  t.className='toast '+(type||'');
  t.innerHTML=(type==='success'?'<svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> ':'ℹ️ ')+msg;
  c.appendChild(t);
  setTimeout(()=>t.remove(),4100);
}

// ─── AUTO POLL for new requests ─────────────────────
setInterval(()=>{
  fetch('check_requests.php')
    .then(r=>r.json())
    .then(d=>{
      if(d.count>0){
        let badge = document.querySelector('[data-section="requests"] .nav-badge');
        if(!badge){
          document.querySelector('[data-section="requests"]').insertAdjacentHTML(
            'beforeend',`<span class="nav-badge">${d.count}</span>`);
        } else {
          badge.textContent = d.count;
        }
      }
    }).catch(()=>{});
},30000);
</script>
</body>
</html>