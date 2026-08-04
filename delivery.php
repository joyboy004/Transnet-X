<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

$uid = (int)$_SESSION['user_id'];

$stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $uid);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($result);

if (!$user) {
    session_destroy();
    header('Location: ../index.php');
    exit();
}

$fname   = explode(' ', $user['name'])[0];
$initial = strtoupper(substr($user['name'], 0, 1));

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$msg = $err = '';

if (isset($_GET['ok']) && isset($_GET['code']) && isset($_GET['fare'])) {
    $code = htmlspecialchars($_GET['code']);
    $total_fare = (float)$_GET['fare'];
    $msg = "<i class='fas fa-box'></i> Request placed! Tracking: <strong>$code</strong> · ₦" . number_format($total_fare);
}

// ── Handle POST requests ─────────────────
$track_result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF CHECK
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $err = "<i class='fas fa-exclamation-circle'></i> Invalid request. Please refresh the page and try again.";
    } else {
        if (isset($_POST['place_order'])) {
            // Rate limit
            if (isset($_SESSION['last_order']) && (time() - $_SESSION['last_order']) < 10) {
                $err = "<i class='fas fa-exclamation-circle'></i> Please wait a few seconds before submitting again.";
            } else {
                $sender_name   = trim($_POST['sender_name']);
                $sender_phone  = trim($_POST['sender_phone']);
                $pickup_addr   = trim($_POST['pickup_address']);
                $pickup_lm     = trim($_POST['pickup_landmark'] ?? '');

                $recip_name    = trim($_POST['recipient_name']);
                $recip_phone   = trim($_POST['recipient_phone']);
                $delivery_addr = trim($_POST['delivery_address']);
                $delivery_lm   = trim($_POST['delivery_landmark'] ?? '');

                $pkg_type  = $_POST['package_type'] ?? 'small_parcel';
                $pkg_desc  = trim($_POST['package_description'] ?? '');
                $weight    = (float)($_POST['weight_kg'] ?? 0);

                $fragile   = isset($_POST['is_fragile']) ? 1 : 0;
                $signature = isset($_POST['require_signature']) ? 1 : 0;

                $del_type   = $_POST['delivery_type'] ?? 'standard';
                $pay_method = $_POST['payment_method'] ?? 'card';

                $notes     = trim($_POST['notes'] ?? '');
                $scheduled = !empty($_POST['scheduled_date']) ? $_POST['scheduled_date'] : NULL;

                // Validate enum arrays
                $allowed_pkg_types = ['document', 'small_parcel', 'medium_parcel', 'large_parcel', 'fragile', 'food', 'electronics'];
                $allowed_del_types = ['standard', 'express', 'same_day', 'scheduled'];
                $allowed_pay_methods = ['card', 'transfer', 'cod', 'wallet'];

                $pkg_type   = in_array($pkg_type, $allowed_pkg_types, true) ? $pkg_type : 'small_parcel';
                $del_type   = in_array($del_type, $allowed_del_types, true) ? $del_type : 'standard';
                $pay_method = in_array($pay_method, $allowed_pay_methods, true) ? $pay_method : 'card';

                // Price logic
                $fare_map = [
                    'standard'  => 2500,
                    'express'   => 4500,
                    'same_day'  => 6000,
                    'scheduled' => 2000
                ];
                $weight_fee = $weight > 5 ? ($weight - 5) * 300 : 0;
                $base_fare  = ($fare_map[$del_type] ?? 2500) + $weight_fee + ($fragile ? 500 : 0);
                $total_fare = $base_fare;

                $code = 'TXD' . strtoupper(substr(md5(uniqid()), 0, 10));

                // Validation
                if (!$sender_name || !$sender_phone || !$pickup_addr || !$recip_name || !$recip_phone || !$delivery_addr) {
                    $err = "<i class='fas fa-exclamation-circle'></i> Please fill all required fields.";
                } elseif (strlen($sender_name) > 100) {
                    $err = "<i class='fas fa-exclamation-circle'></i> Sender name is too long.";
                } elseif (strlen($sender_phone) > 30) {
                    $err = "<i class='fas fa-exclamation-circle'></i> Sender phone number is too long.";
                } elseif (strlen($pickup_addr) > 1000) {
                    $err = "<i class='fas fa-exclamation-circle'></i> Pickup address is too long.";
                } elseif (strlen($pickup_lm) > 100) {
                    $err = "<i class='fas fa-exclamation-circle'></i> Pickup landmark is too long.";
                } elseif (strlen($recip_name) > 100) {
                    $err = "<i class='fas fa-exclamation-circle'></i> Recipient name is too long.";
                } elseif (strlen($recip_phone) > 30) {
                    $err = "<i class='fas fa-exclamation-circle'></i> Recipient phone number is too long.";
                } elseif (strlen($delivery_addr) > 1000) {
                    $err = "<i class='fas fa-exclamation-circle'></i> Delivery address is too long.";
                } elseif (strlen($delivery_lm) > 100) {
                    $err = "<i class='fas fa-exclamation-circle'></i> Delivery landmark is too long.";
                } elseif (strlen($pkg_desc) > 1000) {
                    $err = "<i class='fas fa-exclamation-circle'></i> Package description is too long.";
                } elseif (strlen($notes) > 1000) {
                    $err = "<i class='fas fa-exclamation-circle'></i> Notes are too long.";
                } elseif (!preg_match('/^[0-9+\-\s]{7,30}$/', $sender_phone)) {
                    $err = "<i class='fas fa-exclamation-circle'></i> Invalid sender phone number.";
                } elseif (!preg_match('/^[0-9+\-\s]{7,30}$/', $recip_phone)) {
                    $err = "<i class='fas fa-exclamation-circle'></i> Invalid recipient phone number.";
                } else {
                    $ins = mysqli_prepare($conn, "
                        INSERT INTO delivery_requests
                        (user_id, tracking_code, sender_name, sender_phone, pickup_address, pickup_landmark,
                         recipient_name, recipient_phone, delivery_address, delivery_landmark,
                         package_type, package_description, weight_kg, is_fragile, require_signature,
                         delivery_type, base_fare, total_fare, payment_method, scheduled_date, notes, status)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                    ");
                    mysqli_stmt_bind_param($ins, "isssssssssssdiisddsss",
                        $uid, $code, $sender_name, $sender_phone, $pickup_addr, $pickup_lm,
                        $recip_name, $recip_phone, $delivery_addr, $delivery_lm,
                        $pkg_type, $pkg_desc, $weight, $fragile, $signature,
                        $del_type, $base_fare, $total_fare, $pay_method, $scheduled, $notes
                    );
                    
                    if (mysqli_stmt_execute($ins)) {
                        $_SESSION['last_order'] = time();
                        header("Location: delivery.php?ok=1&code=" . urlencode($code) . "&fare=" . urlencode($total_fare));
                        exit();
                    } else {
                        $err = "<i class='fas fa-exclamation-circle'></i> Failed to place request. Please try again.";
                    }
                }
            }
        } elseif (isset($_POST['track_order'])) {
            $code = strtoupper(trim($_POST['tracking_code']));
            $tr = mysqli_prepare($conn, "SELECT * FROM delivery_requests WHERE tracking_code = ? AND user_id = ?");
            mysqli_stmt_bind_param($tr, "si", $code, $uid);
            mysqli_stmt_execute($tr);
            $track_result = mysqli_fetch_assoc(mysqli_stmt_get_result($tr));
        }
    }
}

// ── Fetch user's orders ───────────────────
$my_orders = [];
$orders_stmt = mysqli_prepare($conn, "
    SELECT * FROM delivery_requests
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 20
");
if ($orders_stmt) {
    mysqli_stmt_bind_param($orders_stmt, 'i', $uid);
    mysqli_stmt_execute($orders_stmt);
    $orders_result = mysqli_stmt_get_result($orders_stmt);
    while ($row = mysqli_fetch_assoc($orders_result)) {
        $my_orders[] = $row;
    }
}

// ── Notifications (approved requests) ─────
$notifications = [];
$notif_stmt = mysqli_prepare($conn, "
    SELECT id, tracking_code, status, created_at
    FROM delivery_requests
    WHERE user_id = ? AND status = 'approved'
    ORDER BY created_at DESC
");
if ($notif_stmt) {
    mysqli_stmt_bind_param($notif_stmt, 'i', $uid);
    mysqli_stmt_execute($notif_stmt);
    $notif_result = mysqli_stmt_get_result($notif_stmt);
    while ($row = mysqli_fetch_assoc($notif_result)) {
        $notifications[] = $row;
    }
}
$notif_count = count($notifications);

// ── Stats ─────────────────────────────────
$total_orders   = count($my_orders);
$delivered      = count(array_filter($my_orders, fn($o) => $o['status'] === 'delivered'));
$in_transit     = count(array_filter($my_orders, fn($o) => in_array($o['status'], ['approved','in_transit'])));
$total_spent    = array_sum(array_column($my_orders, 'total_fare'));

// Map for package type icons (Lucide icon names)
$pkg_icons = [
    'document'      => 'file-text',
    'small_parcel'  => 'package',
    'medium_parcel' => 'boxes',
    'large_parcel'  => 'box',
    'fragile'       => 'alert-circle',
    'food'          => 'utensils',
    'electronics'   => 'smartphone'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>TransDeliver — TransNet X</title>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&family=Bebas+Neue&display=swap" rel="stylesheet"/>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js" defer onerror="this.remove()"></script>
<script src="../assets/offline-icons.js"></script>
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
:root{
  --ac:#10B981;--ac2:#34D399;--ac3:#6EE7B7;--ac-dk:#064E3B;
  --warn:#F59E0B;--red:#EF4444;
  --black:#030A07;--surface:#080F0A;--card:rgba(16,185,129,.04);
  --border:rgba(16,185,129,.14);--border2:rgba(16,185,129,.25);
  --text:#D1FAE5;--muted:rgba(209,250,229,.42);
  --gold:#D4A843;--r:14px;--r2:8px;
}
html{scroll-behavior:smooth}
body{font-family:'Sora',sans-serif;background:var(--black);color:var(--text);min-height:100vh;overflow-x:hidden;display:flex;flex-direction:column;}

/* ── Background ── */
.bg-grid{
  position:fixed;inset:0;z-index:0;pointer-events:none;
  background-image:linear-gradient(rgba(16,185,129,.04) 1px,transparent 1px),
                   linear-gradient(90deg,rgba(16,185,129,.04) 1px,transparent 1px);
  background-size:48px 48px;
  mask-image:radial-gradient(ellipse 80% 80% at 50% 50%,black 30%,transparent 100%);
}
.bg-glow{
  position:fixed;inset:0;z-index:0;pointer-events:none;
  background:radial-gradient(ellipse 60% 50% at 15% 25%,rgba(16,185,129,.08) 0%,transparent 60%),
             radial-gradient(ellipse 50% 40% at 85% 75%,rgba(16,185,129,.05) 0%,transparent 60%);
}

/* ── SIDEBAR (left side) ── */
.sidebar {
  position: fixed;
  left: -280px;
  top: 0;
  width: 280px;
  height: 100vh;
  background: rgba(3, 10, 7, 0.98);
  backdrop-filter: blur(20px);
  border-right: 1px solid var(--border);
  z-index: 1000;
  transition: left 0.3s cubic-bezier(0.16,1,0.3,1);
  padding: 24px 0;
  display: flex;
  flex-direction: column;
}
.sidebar.open { left: 0; }
.sidebar-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.5);
  backdrop-filter: blur(3px);
  z-index: 999;
  opacity: 0;
  visibility: hidden;
  transition: all 0.3s;
}
.sidebar-overlay.show { opacity: 1; visibility: visible; }

.sidebar-header {
  padding: 0 20px 20px;
  border-bottom: 1px solid var(--border);
  margin-bottom: 16px;
}
.sidebar-logo {
  font-family: 'Bebas Neue', sans-serif;
  font-size: 24px;
  letter-spacing: 3px;
  color: var(--text);
  text-decoration: none;
}
.sidebar-logo span {
  background: linear-gradient(135deg, var(--ac3), var(--ac));
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
}
.sidebar-user {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 20px;
  margin-bottom: 16px;
}
.sidebar-avatar {
  width: 48px; height: 48px; border-radius: 50%;
  background: linear-gradient(135deg, var(--ac-dk), var(--ac));
  display: flex; align-items: center; justify-content: center;
  font-weight: 700; font-size: 18px; color: #fff;
}
.sidebar-user-info h4 { font-size: 15px; font-weight: 600; }
.sidebar-user-info p { font-size: 11px; color: var(--muted); text-transform: uppercase; letter-spacing: 1px; }

.sidebar-nav {
  flex: 1;
  display: flex; flex-direction: column; gap: 4px;
  padding: 0 12px;
}
.sidebar-item {
  display: flex; align-items: center; gap: 14px;
  padding: 14px 16px; border-radius: 10px;
  color: var(--muted); text-decoration: none;
  font-size: 14px; font-weight: 500;
  transition: all 0.2s; border: 1px solid transparent;
}
.sidebar-item i {
  width: 22px;
  font-size: 1.2rem;
  color: currentColor;
}
.sidebar-item:hover {
  background: rgba(16,185,129,0.08); color: var(--text);
  border-color: var(--border);
}
.sidebar-item.active {
  background: rgba(16,185,129,0.12); color: var(--ac);
  border-color: var(--border2);
}
.sidebar-item.logout { color: #F87171; margin-top: auto; }
.sidebar-item.logout:hover { background: rgba(248,113,113,0.08); border-color: rgba(248,113,113,0.2); }

.sidebar-footer {
  padding: 20px; border-top: 1px solid var(--border);
  margin-top: 16px; font-size: 11px; color: var(--muted); text-align: center;
}

/* ── Hamburger ── */
.hamburger {
  width: 40px; height: 40px;
  display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  gap: 6px; cursor: pointer;
  border-radius: 8px; transition: all 0.2s;
  margin-right: 12px;
}
.hamburger:hover { background: rgba(255,255,255,0.05); }
.hamburger span {
  width: 22px; height: 2px;
  background: var(--text); border-radius: 2px;
  transition: all 0.3s;
}
.hamburger.open span:nth-child(1) { transform: rotate(45deg) translate(5px,6px); }
.hamburger.open span:nth-child(2) { opacity: 0; }
.hamburger.open span:nth-child(3) { transform: rotate(-45deg) translate(5px,-6px); }

/* ── Topbar ── */
.topbar{
  position:fixed; top:0; left:0; right:0; z-index:200;
  display:flex; align-items:center; justify-content:space-between;
  padding:0 24px; height:66px;
  background:rgba(3,10,7,.92); backdrop-filter:blur(22px);
  border-bottom:1px solid var(--border);
}
.topbar-left { display: flex; align-items: center; }
.logo{font-family:'Bebas Neue',sans-serif;font-size:22px;letter-spacing:2px;text-decoration:none;}
.logo span{background:linear-gradient(135deg,var(--ac3),var(--ac));-webkit-background-clip:text;-webkit-text-fill-color:transparent;}
.logo em{font-style:normal;color:rgba(16,185,129,.3);font-size:11px;letter-spacing:3px;margin-left:3px;vertical-align:middle;-webkit-text-fill-color:rgba(16,185,129,.3);}

.topbar-right {
  display: flex; align-items: center; gap: 16px;
}
.notif-wrapper{position:relative;cursor:pointer;font-size:20px; color: var(--muted);}
.notif-wrapper i { font-size: 1.2rem; }
.notif-count{
  position:absolute; top:-6px; right:-8px;
  background:#F87171; color:#fff;
  font-size:10px; padding:2px 6px; border-radius:50%;
}
.notif-dropdown{
  position:absolute; top:40px; right:0; width:280px; max-height:350px; overflow-y:auto;
  background:rgba(3,10,7,.95); border:1px solid var(--border);
  border-radius:10px; display:none; flex-direction:column; backdrop-filter:blur(20px); z-index:300;
}
.notif-item{padding:12px;border-bottom:1px solid rgba(255,255,255,.05);font-size:13px;color:var(--text);}
.notif-item i { margin-right: 8px; width: 20px; }
.notif-item small{display:block;color:var(--muted);font-size:10px;margin-top:4px; margin-left: 28px;}

.user-avatar {
  width: 38px; height: 38px; border-radius: 50%;
  background: linear-gradient(135deg, var(--ac-dk), var(--ac));
  display: flex; align-items: center; justify-content: center;
  font-weight: 700; font-size: 14px; color: #fff;
  cursor: pointer; border: 1.5px solid var(--border);
}

/* ── Layout ── */
.layout{
  position:relative; z-index:1;
  padding:80px 36px 60px;
  max-width:1280px; margin:0 auto;
  display:grid; grid-template-columns:1fr 1fr;
  gap:24px; min-height:100vh;
  flex: 1;
}
.left-panel, .right-panel{display:flex;flex-direction:column;gap:16px;}

/* ── Stats row ── */
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:8px;}
.stat-card{background:rgba(255,255,255,.025);border:1px solid var(--border);border-radius:var(--r);padding:16px;transition:transform .25s;}
.stat-card:hover{transform:translateY(-3px);}
.sc-icon{font-size:22px;margin-bottom:10px;}
.sc-val{font-family:'JetBrains Mono',monospace;font-size:22px;font-weight:500;color:var(--ac);}
.sc-label{font-size:10.5px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-top:4px;}

/* ── Panel cards ── */
.panel-sec{background:rgba(255,255,255,.025);border:1px solid var(--border);border-radius:var(--r);overflow:hidden;}
.psec-head{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;}
.psec-head h2{font-size:13px;font-weight:700;letter-spacing:-.2px;display:flex;align-items:center;gap:8px;}
.dot{width:6px;height:6px;border-radius:50%;background:var(--ac);box-shadow:0 0 8px var(--ac);}
.psec-body{padding:18px;}

/* tabs */
.tabs{display:flex;gap:0;border-bottom:1px solid var(--border);margin-bottom:18px;}
.tab{padding:9px 16px;font-size:12.5px;font-weight:500;cursor:pointer;color:var(--muted);border-bottom:2px solid transparent;margin-bottom:-1px;transition:all .2s;}
.tab.active{color:var(--ac);border-bottom-color:var(--ac);}

/* two‑column form */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:16px;}
.form-col{display:flex;flex-direction:column;gap:14px;}
.form-group{margin-bottom:0;}
.form-label{font-size:10.5px;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);margin-bottom:5px;display:block;}
.form-label i { margin-right: 6px; font-size: 12px; }
.form-input,.form-select,.form-textarea{width:100%;background:rgba(255,255,255,.035);border:1px solid rgba(255,255,255,.08);border-radius:var(--r2);padding:10px 13px;color:var(--text);font-family:inherit;font-size:13px;outline:none;transition:border-color .2s;}
.form-input:focus,.form-select:focus,.form-textarea:focus{border-color:rgba(16,185,129,.5);background:rgba(16,185,129,.04);}
.form-input::placeholder,.form-textarea::placeholder{color:var(--muted);}
.form-select option{background:#030A07;}
.form-textarea{resize:vertical;min-height:70px;}

/* package grid */
.pkg-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin-bottom:14px;}
.pkg-opt{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.06);border-radius:var(--r2);padding:8px 4px;text-align:center;cursor:pointer;transition:all .2s;}
.pkg-opt input{display:none;}
.pkg-opt label{cursor:pointer;display:block;}
.pkg-icon{font-size:20px;margin-bottom:3px; color: var(--ac); }
.pkg-name{font-size:9.5px;color:var(--muted);letter-spacing:.3px;}
.pkg-opt:has(input:checked){border-color:var(--ac);background:rgba(16,185,129,.08);}

.dtype-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin-bottom:14px;}
.dtype{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.06);border-radius:var(--r2);padding:10px 12px;cursor:pointer;transition:all .2s;}
.dtype input{display:none;}
.dtype label{cursor:pointer;display:flex;align-items:center;gap:8px;}
.dtype-icon { font-size: 22px; width: 32px; text-align: center; }
.dtype-info .dtype-name { font-weight: 500; font-size: 13px; }
.dtype-info .dtype-price { font-size: 10px; color: var(--ac); }
.dtype:has(input:checked){border-color:var(--ac);background:rgba(16,185,129,.07);}
.check-row{display:flex;gap:14px;margin-bottom:14px;}
.check-item{display:flex;align-items:center;gap:7px;cursor:pointer;font-size:12.5px;color:var(--muted);}
.check-item input{accent-color:var(--ac);width:14px;height:14px;}
.check-item i { margin-right: 4px; }

.fare-display{background:rgba(16,185,129,.06);border:1px solid var(--border2);border-radius:var(--r2);padding:12px 16px;display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;}
.fd-label{font-size:11px;color:var(--muted);}.fd-amount{font-family:'JetBrains Mono',monospace;font-size:22px;color:var(--ac);font-weight:500;}

.btn-place{width:100%;padding:13px;border-radius:var(--r2);border:none;background:linear-gradient(135deg,var(--ac-dk),var(--ac));color:#fff;font-family:'Sora',sans-serif;font-size:14px;font-weight:700;cursor:pointer;transition:all .3s;}
.btn-place i { margin-right: 8px; }
.btn-place:hover{box-shadow:0 8px 28px rgba(16,185,129,.3);transform:translateY(-2px);}

/* orders list */
.orders-list{display:flex;flex-direction:column;gap:10px;}
.order-card{background:rgba(255,255,255,.025);border:1px solid var(--border);border-radius:var(--r);padding:16px 20px;display:grid;grid-template-columns:auto 1fr auto;gap:14px;align-items:center;transition:all .28s;animation:slideIn .4s ease both;}
.order-card:hover{border-color:var(--border2);background:rgba(16,185,129,.03);transform:translateX(4px);}
@keyframes slideIn{from{opacity:0;transform:translateX(-10px)}}
.oc-icon{width:44px;height:44px;border-radius:12px;background:rgba(16,185,129,.08);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:20px; color: var(--ac);}
.oc-info .oc-code{font-family:'JetBrains Mono',monospace;font-size:12.5px;color:var(--ac2);}
.oc-route{font-size:13px;font-weight:600;margin:3px 0;}
.oc-meta{font-size:11px;color:var(--muted);}
.oc-right{text-align:right;}
.oc-fare{font-family:'JetBrains Mono',monospace;font-size:16px;font-weight:500;color:var(--ac);}
.status-chip{display:inline-block;padding:3px 10px;border-radius:10px;font-size:10.5px;margin-top:5px;font-weight:500;}
.s-pending  {background:rgba(245,158,11,.1);color:#FCD34D;border:1px solid rgba(245,158,11,.25);}
.s-approved {background:rgba(16,185,129,.1);color:var(--ac2);border:1px solid var(--border2);}
.s-in_transit{background:rgba(167,139,250,.1);color:#C4B5FD;border:1px solid rgba(167,139,250,.25);}
.s-delivered{background:rgba(16,185,129,.15);color:var(--ac3);border:1px solid var(--border2);}
.s-cancelled{background:rgba(239,68,68,.08);color:#FCA5A5;border:1px solid rgba(239,68,68,.2);}

.empty-state{text-align:center;padding:50px 20px;color:var(--muted);}.empty-state .ei{font-size:40px;margin-bottom:12px;}
.msg-box{padding:12px 16px;border-radius:var(--r2);margin-bottom:16px;font-size:13px;}
.msg-box i { margin-right: 8px; }
.msg-box.success{background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.3);color:var(--ac3);}
.msg-box.error{background:rgba(239,68,68,.07);border:1px solid rgba(239,68,68,.25);color:#FCA5A5;}

/* track result */
.track-input-row{display:flex;gap:8px;margin-bottom:16px;}
.track-input-row .form-input{flex:1;font-family:'JetBrains Mono',monospace;}
.btn-track{padding:10px 16px;border-radius:var(--r2);background:rgba(16,185,129,.12);border:1px solid var(--border2);color:var(--ac);font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;}
.btn-track i { margin-right: 5px; }
.btn-track:hover{background:rgba(16,185,129,.2);}
.track-card{background:rgba(255,255,255,.02);border:1px solid var(--border);border-radius:var(--r2);overflow:hidden;}
.track-hd{padding:12px 16px;background:rgba(16,185,129,.06);border-bottom:1px solid var(--border);display:flex;justify-content:space-between;}
.track-code{font-family:'JetBrains Mono',monospace;color:var(--ac2);}
.track-route{padding:12px 16px;border-bottom:1px solid var(--border);}
.track-route-txt{font-size:13px;font-weight:500;} .track-route-sub{font-size:11.5px;color:var(--muted);margin-top:3px;}

.progress-bar{height:6px;background:rgba(255,255,255,.08);border-radius:3px;overflow:hidden;margin-top:6px;}
.progress-fill{height:100%;border-radius:3px;background:linear-gradient(90deg,var(--ac-dk),var(--ac));transition:width .4s;}
.progress-steps{display:flex;justify-content:space-between;font-size:10px;color:var(--muted);margin-bottom:4px;}

/* ── FOOTER STYLES (dark, matches page) ── */
.app-footer {
  background: rgba(3, 10, 7, 0.96);
  backdrop-filter: blur(12px);
  border-top: 1px solid var(--border);
  margin-top: 2rem;
  padding: 2rem 0 1.5rem;
  width: 100%;
  font-size: 0.875rem;
}
.footer-container {
  max-width: 1280px;
  margin: 0 auto;
  padding: 0 24px;
}
.footer-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 2rem;
  margin-bottom: 2rem;
}
.footer-brand p {
  color: var(--muted);
  font-size: 0.8rem;
  line-height: 1.4;
  margin-top: 0.75rem;
}
.footer-title {
  font-weight: 700;
  font-size: 0.85rem;
  text-transform: uppercase;
  letter-spacing: 1px;
  color: var(--ac2);
  margin-bottom: 1rem;
}
.footer-links {
  list-style: none;
  padding: 0;
}
.footer-links li {
  margin-bottom: 0.5rem;
}
.footer-links a {
  color: var(--muted);
  text-decoration: none;
  transition: color 0.2s ease;
  font-size: 0.8rem;
}
.footer-links a:hover {
  color: var(--ac);
}
.social-icons {
  display: flex;
  gap: 1rem;
  margin-bottom: 1rem;
}
.social-icons a {
  color: var(--muted);
  font-size: 1.2rem;
  transition: all 0.2s;
}
.social-icons a:hover {
  color: var(--ac);
  transform: translateY(-2px);
}
.copyright {
  text-align: center;
  padding-top: 1.5rem;
  border-top: 1px solid rgba(16,185,129,0.1);
  font-size: 0.7rem;
  color: var(--muted);
}

@media(max-width:900px){.layout{grid-template-columns:1fr;}.stats-row{grid-template-columns:repeat(2,1fr);}.form-grid{grid-template-columns:1fr;}}
@media(max-width:600px){.layout{padding:76px 16px 40px;}.pkg-grid{grid-template-columns:repeat(3,1fr);}}
</style>
</head>
<body>
<div class="bg-grid"></div>
<div class="bg-glow"></div>

<!-- SIDEBAR OVERLAY -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<!-- LEFT SIDEBAR -->
<aside class="sidebar" id="sidebar">
  <div class="sidebar-header">
    <span>TransNet X</span>
  </div>
  
  <div class="sidebar-user">
    <div class="sidebar-avatar"><?= $initial ?></div>
    <div class="sidebar-user-info">
      <h4><?= htmlspecialchars($fname) ?></h4>
      <p></p>
    </div>
  </div>
  
  <nav class="sidebar-nav">
    <a href="TransNet/transnet.php" class="sidebar-item"><i data-lucide="compass" style="width:20px;height:20px"></i> TransNet X</a>
    <a href="delivery.php" class="sidebar-item active"><i data-lucide="package" style="width:20px;height:20px"></i> Delivery</a>
    <a href="dashboard.php" class="sidebar-item"><i data-lucide="layout-dashboard" style="width:20px;height:20px"></i> Dashboard</a>
    <a href="records.php" class="sidebar-item"><i data-lucide="clipboard-list" style="width:20px;height:20px"></i> Records</a>
    <a href="order_food.php" class="sidebar-item"><i data-lucide="utensils" style="width:20px;height:20px"></i> Order a Meal</a>
    <a href="emergency.php" class="sidebar-item"><i data-lucide="alert-triangle" style="width:20px;height:20px"></i> Emergency</a>
    <a href="settings.php" class="sidebar-item"><i data-lucide="settings" style="width:20px;height:20px"></i> Settings</a>
    <a href="../auth/logout.php" class="sidebar-item logout"><i data-lucide="log-out" style="width:20px;height:20px"></i> Logout</a>
  </nav>
  
  <div class="sidebar-footer">
    © 2026 TransNet X<br>Version 2.1.0
  </div>
</aside>

<!-- TOPBAR -->
<nav class="topbar">
  <div class="topbar-left">
    <div class="hamburger" id="hamburger" onclick="toggleSidebar()">
      <span></span><span></span><span></span>
    </div>
    <a href="#" class="logo"><span>TransNet X</span><em>DELIVERY SERVICES</em></a>
  </div>
  
  <div class="topbar-right">
    <div class="notif-wrapper" onclick="toggleNotif()">
      <i data-lucide="bell" style="width:20px;height:20px;display:inline-block"></i>
      <span id="notifCount" class="notif-count"><?= $notif_count ?></span>
      <div id="notifDropdown" class="notif-dropdown"></div>
    </div>
    <div class="user-avatar" onclick="window.location.href='profile.php'" title="Your Profile"><?= $initial ?></div>
  </div>
</nav>

<div class="layout">
  <!-- Left Panel: Form -->
  <div class="left-panel">
    <?php if ($msg): ?><div class="msg-box success"><?= $msg ?></div><?php endif; ?>
    <?php if ($err): ?><div class="msg-box error"><?= $err ?></div><?php endif; ?>

    <div class="panel-sec">
      <div class="psec-head">
        <h2><span class="dot"></span> New Delivery Request</h2>
      </div>
      <div class="psec-body">
        <form method="POST">
          <input type="hidden" name="place_order" value="1">
          <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

          <div class="form-grid">
            <div class="form-col">
              <div style="font-size:11px;text-transform:uppercase;letter-spacing:1.5px;color:var(--ac);font-weight:600;margin-bottom:6px;"><i data-lucide="box" style="width:13px;height:13px;vertical-align:middle"></i> Item Information</div>
              <div class="form-group">
                <label class="form-label">Package Type</label>
                <div class="pkg-grid">
                  <?php 
                  $pkg_list = [
                      'document'      => 'file-text',
                      'small_parcel'  => 'package',
                      'medium_parcel' => 'boxes',
                      'large_parcel'  => 'box',
                      'fragile'       => 'alert-circle',
                      'food'          => 'utensils',
                      'electronics'   => 'smartphone'
                  ];
                  foreach($pkg_list as $val=>$icon): ?>
                  <div class="pkg-opt">
                    <input type="radio" name="package_type" id="pkg_<?=$val?>" value="<?=$val?>" <?=$val==='small_parcel'?'checked':''?> onchange="calcFare()"/>
                    <label for="pkg_<?=$val?>"><div class="pkg-icon"><i data-lucide="<?=$icon?>" style="width:20px;height:20px;display:inline-block"></i></div><div class="pkg-name"><?=ucfirst(str_replace('_',' ',$val))?></div></label>
                  </div>
                  <?php endforeach; ?>
                </div>
              </div>
              <div class="form-group">
                <label class="form-label"><i data-lucide="sliders" style="width:12px;height:12px;vertical-align:middle"></i> Weight (kg)</label>
                <input class="form-input" type="number" name="weight_kg" value="0" min="0" step="0.1" onchange="calcFare()"/>
              </div>
              <div class="check-row">
                <label class="check-item"><input type="checkbox" name="is_fragile" onchange="calcFare()"/> <i data-lucide="alert-circle" style="width:13px;height:13px;vertical-align:middle"></i> Fragile (+₦500)</label>
                <label class="check-item"><input type="checkbox" name="require_signature"/> <i data-lucide="check" style="width:13px;height:13px;vertical-align:middle"></i> Signature Required</label>
              </div>
              <div class="form-group">
                <label class="form-label"><i data-lucide="file-text" style="width:12px;height:12px;vertical-align:middle"></i> Package Description</label>
                <textarea class="form-textarea" name="package_description" placeholder="What are you sending?"></textarea>
              </div>
            </div>

            <div class="form-col">
              <div style="font-size:11px;text-transform:uppercase;letter-spacing:1.5px;color:var(--ac);font-weight:600;margin-bottom:6px;"><i data-lucide="map-pin" style="width:13px;height:13px;vertical-align:middle"></i> Pickup (Sender)</div>
              <div class="form-group">
                <label class="form-label"><i data-lucide="user" style="width:12px;height:12px;vertical-align:middle"></i> Sender Name</label>
                <input class="form-input" name="sender_name" value="<?= htmlspecialchars($user['name']) ?>" required/>
              </div>
              <div class="form-group">
                <label class="form-label"><i data-lucide="phone" style="width:12px;height:12px;vertical-align:middle"></i> Sender Phone</label>
                <input class="form-input" name="sender_phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" required/>
              </div>
              <div class="form-group">
                <label class="form-label"><i data-lucide="map-pin" style="width:12px;height:12px;vertical-align:middle"></i> Pickup Address</label>
                <input class="form-input" name="pickup_address" placeholder="Full pickup address" required/>
              </div>
              <div class="form-group">
                <label class="form-label"><i data-lucide="flag" style="width:12px;height:12px;vertical-align:middle"></i> Pickup Landmark</label>
                <input class="form-input" name="pickup_landmark" placeholder="E.g. beside filling station"/>
              </div>

              <div style="font-size:11px;text-transform:uppercase;letter-spacing:1.5px;color:var(--ac);font-weight:600;margin:6px 0;"><i data-lucide="flag" style="width:13px;height:13px;vertical-align:middle"></i> Drop‑off (Recipient)</div>
              <div class="form-group">
                <label class="form-label"><i data-lucide="users" style="width:12px;height:12px;vertical-align:middle"></i> Recipient Name</label>
                <input class="form-input" name="recipient_name" required/>
              </div>
              <div class="form-group">
                <label class="form-label"><i data-lucide="phone" style="width:12px;height:12px;vertical-align:middle"></i> Recipient Phone</label>
                <input class="form-input" name="recipient_phone" required/>
              </div>
              <div class="form-group">
                <label class="form-label"><i data-lucide="map-pin" style="width:12px;height:12px;vertical-align:middle"></i> Delivery Address</label>
                <input class="form-input" name="delivery_address" required/>
              </div>
              <div class="form-group">
                <label class="form-label"><i data-lucide="flag" style="width:12px;height:12px;vertical-align:middle"></i> Delivery Landmark</label>
                <input class="form-input" name="delivery_landmark" placeholder="E.g. opposite church"/>
              </div>
            </div>
          </div>

          <div style="font-size:11px;text-transform:uppercase;letter-spacing:1.5px;color:var(--ac);font-weight:600;margin-bottom:8px;"><i data-lucide="arrow-right" style="width:13px;height:13px;vertical-align:middle"></i> Delivery Speed</div>
          <div class="dtype-grid">
            <?php 
            $delivery_options = [
                'standard'  => ['truck',     'Standard',  '₦2,500'],
                'express'   => ['zap',        'Express',   '₦4,500'],
                'same_day'  => ['clock',      'Same Day',  '₦6,000'],
                'scheduled' => ['calendar',   'Scheduled', '₦2,000']
            ];
            foreach($delivery_options as $val=>[$icon,$name,$price]): ?>
            <div class="dtype">
              <input type="radio" name="delivery_type" id="dt_<?=$val?>" value="<?=$val?>" <?=$val==='standard'?'checked':''?> onchange="calcFare()"/>
              <label for="dt_<?=$val?>">
                <div class="dtype-icon"><i data-lucide="<?=$icon?>" style="width:22px;height:22px;display:inline-block"></i></div>
                <div class="dtype-info"><div class="dtype-name"><?=$name?></div><div class="dtype-price"><?=$price?></div></div>
              </label>
            </div>
            <?php endforeach; ?>
          </div>

          <div class="form-group" id="schedGroup" style="display:none;margin-bottom:14px;">
            <label class="form-label"><i data-lucide="calendar" style="width:12px;height:12px;vertical-align:middle"></i> Scheduled Date &amp; Time</label>
            <input class="form-input" type="datetime-local" name="scheduled_date" min="<?= date('Y-m-d\TH:i') ?>"/>
          </div>

          <div class="form-row2" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="form-group">
              <label class="form-label"><i data-lucide="credit-card" style="width:12px;height:12px;vertical-align:middle"></i> Payment Method</label>
              <select class="form-select" name="payment_method">
                <option value="card">Card</option>
                <option value="transfer">Bank Transfer</option>
                <option value="cod">Cash on Delivery</option>
                <option value="wallet">Wallet</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label"><i data-lucide="file-text" style="width:12px;height:12px;vertical-align:middle"></i> Notes</label>
              <input class="form-input" name="notes" placeholder="Additional instructions"/>
            </div>
          </div>

          <div class="fare-display">
            <div><div class="fd-label">Estimated Delivery Fee</div><div class="fd-amount" id="fareDisplay">₦2,500</div></div>
            <div style="font-size:11px;color:var(--muted);text-align:right">Final fare subject<br>to admin approval</div>
          </div>

          <button type="submit" class="btn-place"><i data-lucide="send" style="width:16px;height:16px;vertical-align:middle;margin-right:8px"></i> SUBMIT DELIVERY REQUEST</button>
        </form>
      </div>
    </div>
  </div>

  <!-- Right Panel: Track + My Orders -->
  <div class="right-panel">
    <!-- Stats -->
    <div class="stats-row">
      <div class="stat-card"><div class="sc-icon"><i data-lucide="package" style="width:22px;height:22px;display:inline-block"></i></div><div class="sc-val"><?= $total_orders ?></div><div class="sc-label">Total</div></div>
      <div class="stat-card"><div class="sc-icon"><i data-lucide="check-circle" style="width:22px;height:22px;display:inline-block"></i></div><div class="sc-val"><?= $delivered ?></div><div class="sc-label">Delivered</div></div>
      <div class="stat-card"><div class="sc-icon"><i data-lucide="truck" style="width:22px;height:22px;display:inline-block"></i></div><div class="sc-val"><?= $in_transit ?></div><div class="sc-label">Active</div></div>
      <div class="stat-card"><div class="sc-icon"><i data-lucide="banknote" style="width:22px;height:22px;display:inline-block"></i></div><div class="sc-val">₦<?= number_format($total_spent, 0) ?></div><div class="sc-label">Spent</div></div>
    </div>

    <!-- Track -->
    <div class="panel-sec">
      <div class="psec-head"><h2><span class="dot"></span> Track Request</h2></div>
      <div class="psec-body">
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
          <div class="track-input-row">
            <input class="form-input" name="tracking_code" placeholder="e.g. TXDABC123..." required/>
            <button type="submit" name="track_order" class="btn-track"><i data-lucide="search" style="width:14px;height:14px;vertical-align:middle;margin-right:4px"></i> Track</button>
          </div>
        </form>

        <?php if ($track_result): 
          $steps = ['pending','approved','in_transit','delivered'];
          $current_idx = array_search($track_result['status'], $steps);
          $progress = ($current_idx + 1) * 25;
        ?>
        <div class="track-card">
          <div class="track-hd">
            <span class="track-code"><?= htmlspecialchars($track_result['tracking_code']) ?></span>
            <span class="status-chip s-<?= $track_result['status'] ?>"><?= ucfirst(str_replace('_',' ',$track_result['status'])) ?></span>
          </div>
          <div class="track-route">
            <div class="track-route-txt"><i data-lucide="map-pin" style="width:14px;height:14px;vertical-align:middle"></i> <?= htmlspecialchars($track_result['pickup_address']) ?></div>
            <div style="color:var(--ac);margin:4px 0;"><i data-lucide="arrow-down" style="width:14px;height:14px;vertical-align:middle"></i></div>
            <div class="track-route-txt"><i data-lucide="flag" style="width:14px;height:14px;vertical-align:middle"></i> <?= htmlspecialchars($track_result['delivery_address']) ?></div>
            <div class="track-route-sub">
              <i data-lucide="user" style="width:12px;height:12px;vertical-align:middle"></i> To: <?= htmlspecialchars($track_result['recipient_name']) ?> · <i data-lucide="phone" style="width:12px;height:12px;vertical-align:middle"></i> <?= htmlspecialchars($track_result['recipient_phone']) ?>
            </div>
          </div>
          <div style="padding:0 16px 12px;">
            <div class="progress-steps">
              <span>Pending</span><span>Approved</span><span>In Transit</span><span>Delivered</span>
            </div>
            <div class="progress-bar">
              <div class="progress-fill" style="width:<?= $progress ?>%"></div>
            </div>
          </div>
        </div>
        <?php elseif (isset($_POST['track_order'])): ?>
        <div class="msg-box error"><i data-lucide="alert-triangle" style="width:14px;height:14px;vertical-align:middle;margin-right:6px"></i> Request not found. Check your tracking code.</div>
        <?php endif; ?>
      </div>
    </div>

    <!-- My Requests -->
    <div class="panel-sec">
      <div class="psec-head">
        <h2><span class="dot"></span> My Requests</h2>
        <span style="font-size:11px;color:var(--muted);"><?= $total_orders ?> total</span>
      </div>
      <div class="psec-body">
        <?php if (empty($my_orders)): ?>
        <div class="empty-state"><div class="ei"><i data-lucide="inbox" style="width:40px;height:40px;display:inline-block"></i></div><p>No delivery requests yet.</p></div>
        <?php else: ?>
        <div class="orders-list">
          <?php foreach ($my_orders as $o): ?>
          <div class="order-card">
            <div class="oc-icon"><i data-lucide="<?= $pkg_icons[$o['package_type']] ?? 'package' ?>" style="width:20px;height:20px;display:inline-block"></i></div>
            <div class="oc-info">
              <div class="oc-code"><?= htmlspecialchars($o['tracking_code']) ?></div>
              <div class="oc-route"><i data-lucide="map-pin" style="width:13px;height:13px;vertical-align:middle"></i> <?= htmlspecialchars($o['pickup_address']) ?> → <i data-lucide="flag" style="width:13px;height:13px;vertical-align:middle"></i> <?= htmlspecialchars($o['delivery_address']) ?></div>
              <div class="oc-meta"><i data-lucide="calendar" style="width:12px;height:12px;vertical-align:middle"></i> <?= date('M d', strtotime($o['created_at'])) ?> · <?= ucfirst($o['delivery_type']) ?></div>
            </div>
            <div class="oc-right">
              <div class="oc-fare">₦<?= number_format($o['total_fare']) ?></div>
              <span class="status-chip s-<?= $o['status'] ?>"><?= ucfirst(str_replace('_',' ',$o['status'])) ?></span>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ========== INTEGRATED FOOTER (fully adapted to dark theme) ========== -->
<footer class="app-footer">
  <div class="footer-container">
    <div class="footer-grid">
      <!-- Brand column -->
      <div class="footer-brand">
        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
          <div style="width: 32px; height: 32px; border-radius: 8px; background: linear-gradient(135deg, var(--ac-dk), var(--ac)); display: flex; align-items: center; justify-content: center;">
            <i data-lucide="compass" style="color:white;width:16px;height:16px;display:inline-block"></i>
          </div>
          <span style="font-weight: 800; font-size: 1.2rem; background: linear-gradient(135deg, var(--ac3), var(--ac)); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">TransNet X</span>
        </div>
        <p style="color: var(--muted); font-size: 0.8rem; line-height: 1.5;">Your one-stop travel & lifestyle platform. Book rides, flights, trips, rentals, and more with ease.</p>
      </div>

      <!-- Quick Links -->
      <div>
        <h4 class="footer-title">Quick Links</h4>
        <ul class="footer-links">
          <li><a href="about.php">About Us</a></li>
          <li><a href="contact.php">Contact</a></li>
          <li><a href="privacy.php">Privacy Policy</a></li>
          <li><a href="terms.php">Terms of Service</a></li>
        </ul>
      </div>

      <!-- Support -->
      <div>
        <h4 class="footer-title">Support</h4>
        <ul class="footer-links">
          <li><a href="faq.php">FAQ</a></li>
          <li><a href="help.php">Help Center</a></li>
          <li><a href="refund.php">Refund Policy</a></li>
        </ul>
      </div>

      <!-- Connect -->
      <div>
        <h4 class="footer-title">Connect With Us</h4>
        <div class="social-icons">
          <a href="#" title="Facebook">f</a>
          <a href="#" title="Twitter">𝕏</a>
          <a href="#" title="Instagram">📷</a>
          <a href="#" title="LinkedIn">in</a>
        </div>
        <p class="copyright">© 2025 TransNet X. All rights reserved.</p>
      </div>
    </div>
  </div>
</footer>

<script>
// ---------- SIDEBAR TOGGLE ----------
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('show');
    document.getElementById('hamburger').classList.toggle('open');
}

// ---------- NOTIFICATIONS ----------
const notificationData = <?= json_encode($notifications) ?>;
function toggleNotif() {
    const box = document.getElementById("notifDropdown");
    if (box.style.display === "flex") {
        box.style.display = "none";
    } else {
        box.style.display = "flex";
        let html = '';
        if (notificationData.length === 0) {
            html = '<div class="notif-item">ℹ️ No new notifications</div>';
        } else {
            notificationData.forEach(n => {
                html += `<div class="notif-item">
                    ✅ Request <strong>${n.tracking_code}</strong> was approved
                    <small>🕐 ${n.created_at}</small>
                </div>`;
            });
        }
        box.innerHTML = html;
    }
}

// Close dropdown on outside click
document.addEventListener('click', function(e) {
    if (!e.target.closest('.notif-wrapper')) {
        document.getElementById('notifDropdown').style.display = 'none';
    }
});

// ---------- FARE CALCULATION ----------
const fareMap = { standard:2500, express:4500, same_day:6000, scheduled:2000 };
function calcFare() {
    const dtype   = document.querySelector('input[name="delivery_type"]:checked')?.value || 'standard';
    const weight  = parseFloat(document.querySelector('input[name="weight_kg"]')?.value) || 0;
    const fragile = document.querySelector('input[name="is_fragile"]')?.checked ? 500 : 0;
    const wFee    = weight > 5 ? (weight - 5) * 300 : 0;
    const total   = (fareMap[dtype] || 2500) + wFee + fragile;
    document.getElementById('fareDisplay').textContent = '₦' + total.toLocaleString();
    document.getElementById('schedGroup').style.display = dtype === 'scheduled' ? 'block' : 'none';
}
document.addEventListener('input', function(e) {
    if (e.target.name === 'delivery_type' || e.target.name === 'weight_kg' || e.target.name === 'is_fragile') calcFare();
});
window.onload = function() {
    calcFare();
    if (typeof lucide !== 'undefined' && lucide.createIcons) {
        lucide.createIcons();
    } else if (typeof renderOfflineIcons !== 'undefined') {
        renderOfflineIcons(document);
    }
};
</script>
</body>
</html>