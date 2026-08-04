<?php
session_start();
require_once '../../config/db.php';

// ── Auth guard ──
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit();
}

// CSRF token (same as vehicle sale)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$user_id = (int)$_SESSION['user_id'];

// ── Fetch user ──
$user_query = mysqli_query($conn, "SELECT * FROM users WHERE id = $user_id LIMIT 1");
if (!$user_query) die("Query error: " . mysqli_error($conn));
$user = mysqli_fetch_assoc($user_query);
if (!$user) {
    session_destroy();
    header("Location: ../../index.php?err=account_deleted");
    exit();
}
$uname  = htmlspecialchars($user['name'] ?? $user['username'] ?? 'User', ENT_QUOTES, 'UTF-8');
$uemail = htmlspecialchars($user['email'] ?? '', ENT_QUOTES, 'UTF-8');
$uphone = htmlspecialchars($user['phone'] ?? '', ENT_QUOTES, 'UTF-8');

// ── All available rental vehicles ──
$vehicles_query = mysqli_query($conn, "SELECT * FROM rental_vehicles WHERE is_available = 1 ORDER BY category, make, model");
if (!$vehicles_query) die("Query error: " . mysqli_error($conn));
$vehicles = [];
while ($row = mysqli_fetch_assoc($vehicles_query)) $vehicles[] = $row;

// Extract categories / makes for filter
$categories = array_unique(array_column($vehicles, 'category'));
sort($categories);
$makes = array_unique(array_column($vehicles, 'make'));
sort($makes);

// ── User rentals (all) ──
$rentals_query = mysqli_query($conn,
    "SELECT r.*, v.make, v.model, v.year, v.image_url, v.category
     FROM rentals r
     JOIN rental_vehicles v ON v.id = r.vehicle_id
     WHERE r.user_id = $user_id
     ORDER BY r.created_at DESC");
if (!$rentals_query) die("Query error: " . mysqli_error($conn));
$all_rentals = [];
while ($row = mysqli_fetch_assoc($rentals_query)) $all_rentals[] = $row;

$total_rentals    = count($all_rentals);
$pending_rentals  = count(array_filter($all_rentals, fn($r) => in_array($r['status'], ['pending', 'confirmed', 'active'])));
$spent_rental     = array_sum(array_column(array_filter($all_rentals, fn($r) => $r['payment_status'] === 'paid'), 'total_price'));

// ── Fetch active rental for tracker ──
$active_rental_query = mysqli_query($conn,
    "SELECT r.*, v.make, v.model, v.year, v.image_url, v.category
     FROM rentals r
     JOIN rental_vehicles v ON v.id = r.vehicle_id
     WHERE r.user_id = $user_id AND r.status IN ('pending', 'confirmed', 'active')
     ORDER BY r.created_at DESC
     LIMIT 1");
if (!$active_rental_query) die("Query error: " . mysqli_error($conn));
$active_rental = mysqli_fetch_assoc($active_rental_query);

$has_active_rental = ($active_rental !== null);

// Calculate countdown & progress if active rental exists
$progress = 0;
$countdown_text = '';
$pickup_date_formatted = '';
$return_date_formatted = '';

if ($has_active_rental) {
    $today_ts = strtotime(date('Y-m-d')); // use start of today for clean day count
    $pickup_ts = strtotime($active_rental['pickup_date']);
    $return_ts = strtotime($active_rental['return_date']);
    
    $pickup_date_formatted = date('M d, Y', $pickup_ts);
    $return_date_formatted = date('M d, Y', $return_ts);
    
    $total_duration_days = max(1, round(($return_ts - $pickup_ts) / 86400));
    $elapsed_days = round(($today_ts - $pickup_ts) / 86400);
    
    if ($today_ts < $pickup_ts) {
        $progress = 0;
        $days_until = round(($pickup_ts - $today_ts) / 86400);
        $countdown_text = "Rental starts in <strong style='color:var(--amber)'>$days_until day(s)</strong>";
    } elseif ($today_ts > $return_ts) {
        $progress = 100;
        $days_overdue = round(($today_ts - $return_ts) / 86400);
        $countdown_text = "<strong style='color:var(--red)'>OVERDUE BY $days_overdue DAY(S)</strong> (Due return: $return_date_formatted)";
    } else {
        $progress = round(($elapsed_days / $total_duration_days) * 100);
        $days_left = round(($return_ts - $today_ts) / 86400);
        $countdown_text = "<strong style='color:var(--green)'>$days_left day(s) remaining</strong> before return (Due: $return_date_formatted)";
    }
}

// ── Handle rental booking (like purchase request) ──
$msg_s = '';
$msg_e = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book_rental'])) {
    // CSRF CHECK
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $msg_e = "Invalid request. Please refresh the page and try again.";
    } else {
        // Rate limit
        if (isset($_SESSION['last_rental']) && (time() - $_SESSION['last_rental']) < 10) {
            $msg_e = "Please wait a few seconds before submitting again.";
        } else {
            // Sanitize
            $vid         = intval($_POST['vehicle_id']);
            $pickup_date = trim($_POST['pickup_date']);
            $return_date = trim($_POST['return_date']);
            $pickup_loc  = trim($_POST['pickup_location']);
            $with_driver = isset($_POST['with_driver']) ? 1 : 0;
            $notes       = trim($_POST['notes']);

            // Validation
            if (!$vid || !$pickup_date || !$return_date || !$pickup_loc) {
                $msg_e = "Please fill all required fields.";
            } elseif (strlen($pickup_loc) > 255) {
                $msg_e = "Location is too long.";
            } elseif (strlen($notes) > 1000) {
                $msg_e = "Notes are too long.";
            } else {
                // Check if user already has an active rental
                $active_check = mysqli_query($conn, "SELECT id FROM rentals WHERE user_id = $user_id AND status IN ('pending', 'confirmed', 'active') LIMIT 1");
                if (mysqli_num_rows($active_check) > 0) {
                    $msg_e = "You cannot rent another vehicle while you have an active rental that has not been returned.";
                } else {
                    // Fetch vehicle (with lock)
                    mysqli_begin_transaction($conn);
                try {
                    $veh_stmt = mysqli_prepare($conn, "SELECT * FROM rental_vehicles WHERE id = ? AND is_available = 1 FOR UPDATE");
                    mysqli_stmt_bind_param($veh_stmt, "i", $vid);
                    mysqli_stmt_execute($veh_stmt);
                    $veh_result = mysqli_stmt_get_result($veh_stmt);
                    $vehicle = mysqli_fetch_assoc($veh_result);
                    if (!$vehicle) throw new Exception("Vehicle is no longer available.");

                    $daily = (float)$vehicle['price_per_day'];
                    $days  = max(1, (int)((strtotime($return_date) - strtotime($pickup_date)) / 86400));
                    $total = $daily * $days + ($with_driver ? 15000 * $days : 0);
                    $car_model = $vehicle['make'] . ' ' . $vehicle['model'];

                    // Insert rental
                    $ins_stmt = mysqli_prepare($conn,
                        "INSERT INTO rentals (user_id, vehicle_id, car_model, daily_rate, pickup_date, return_date, pickup_location, total_days, total_price, driver_option, notes, status, payment_status, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'unpaid', NOW())");
                    mysqli_stmt_bind_param($ins_stmt, "iisdssiidis", $user_id, $vid, $car_model, $daily, $pickup_date, $return_date, $pickup_loc, $days, $total, $with_driver, $notes);
                    if (!mysqli_stmt_execute($ins_stmt)) throw new Exception("Failed to create rental.");

                    mysqli_commit($conn);
                    $_SESSION['last_rental'] = time();
                    header("Location: rental.php?ok=1");
                    exit();
                } catch (Exception $e) {
                    mysqli_rollback($conn);
                    $msg_e = $e->getMessage();
                }
                }
            }
        }
    }
}

if (isset($_GET['ok'])) $msg_s = "Rental request submitted! Your vehicle is reserved. We will confirm shortly.";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>TransNetX — Car Rentals</title>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<style>
/* ═══════════════════════════════════════════
   ROOT — Unified TransNetX Gold Theme
   (Identical to vehicle_sale.php)
═══════════════════════════════════════════ */
:root{
  --bg:#09090B;--bg1:#0F0F12;--bg2:#141418;--bg3:#1C1C22;--bg4:#242430;
  --amber:#E8A020;--amber-l:#F5C050;--amber-d:#8A5C08;--amber-g:rgba(232,160,32,.13);
  --text:#F0EEE8;--muted:#68687A;--muted2:#3A3A48;
  --green:#22C55E;--red:#EF4444;--blue:#3B82F6;
  --border:rgba(255,255,255,.07);--border2:rgba(232,160,32,.2);
  --sw:264px;--nh:66px;--r:12px;--rl:20px;--tr:.28s cubic-bezier(.4,0,.2,1);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;overflow-x:hidden;display:flex;flex-direction:column;}
body::before{content:'';position:fixed;inset:0;pointer-events:none;z-index:0;
  background-image:linear-gradient(rgba(232,160,32,.028) 1px,transparent 1px),linear-gradient(90deg,rgba(232,160,32,.028) 1px,transparent 1px);
  background-size:60px 60px;}
body::after{content:'';position:fixed;inset:0;pointer-events:none;z-index:0;
  background:radial-gradient(ellipse 60% 40% at 85% 5%,rgba(232,160,32,.06),transparent),
             radial-gradient(ellipse 40% 50% at 5% 95%,rgba(232,160,32,.04),transparent);}
::-webkit-scrollbar{width:5px}::-webkit-scrollbar-track{background:var(--bg1)}::-webkit-scrollbar-thumb{background:var(--amber-d);border-radius:3px}

/* ═══════════ SIDEBAR (exact copy) ═══════════ */
.sidebar{position:fixed;top:0;left:0;bottom:0;width:var(--sw);background:var(--bg1);border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:500;transition:transform .38s cubic-bezier(.4,0,.2,1)}
.sidebar.hidden{transform:translateX(calc(-1*var(--sw)))}
.sb-brand{display:flex;align-items:center;gap:12px;padding:20px 16px 14px;border-bottom:1px solid var(--border);flex-shrink:0}
.sb-logo{width:40px;height:40px;border-radius:10px;flex-shrink:0;background:linear-gradient(135deg,var(--amber-l),var(--amber-d));display:grid;place-items:center;font-family:'Bebas Neue',sans-serif;font-size:22px;color:#000}
.sb-title{font-family:'Bebas Neue',sans-serif;font-size:20px;letter-spacing:2px;color:var(--amber)}
.sb-sub{font-size:10px;color:var(--muted);letter-spacing:1px;text-transform:uppercase;margin-top:1px}
.sb-x{margin-left:auto;width:28px;height:28px;border-radius:7px;background:var(--bg3);border:none;color:var(--muted);cursor:pointer;display:grid;place-items:center;font-size:12px;transition:var(--tr)}
.sb-x:hover{color:var(--red)}
.sb-user{margin:12px 12px 4px;border-radius:var(--r);background:linear-gradient(135deg,var(--bg3),var(--bg4));border:1px solid var(--border2);padding:14px;flex-shrink:0}
.sb-urow{display:flex;align-items:center;gap:10px;margin-bottom:10px}
.sb-av{width:38px;height:38px;border-radius:10px;flex-shrink:0;background:linear-gradient(135deg,var(--amber-l),var(--amber-d));display:grid;place-items:center;font-size:16px;font-weight:800;color:#000;font-family:'Bebas Neue',sans-serif}
.sb-uname{font-weight:700;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sb-email{font-size:10px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sb-sr{display:flex;gap:6px}
.sb-sn{flex:1;background:var(--bg);border-radius:8px;padding:8px 6px;text-align:center}
.sb-snn{font-family:'JetBrains Mono',monospace;font-size:16px;font-weight:700;color:var(--amber)}
.sb-snl{font-size:9px;color:var(--muted);margin-top:1px}
.sb-nav{flex:1;overflow-y:auto;padding:8px 10px}
.sb-nav::-webkit-scrollbar{width:2px}
.sb-lbl{font-size:10px;font-weight:700;color:var(--muted2);letter-spacing:2px;text-transform:uppercase;padding:12px 8px 4px}
.sb-item{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;text-decoration:none;color:var(--muted);font-size:13px;font-weight:600;transition:var(--tr);margin-bottom:2px;white-space:nowrap;border:none;background:none;width:100%;cursor:pointer}
.sb-item:hover{background:var(--bg3);color:var(--text)}
.sb-item.active{background:var(--amber-g);border:1px solid var(--border2);color:var(--amber)}
.sb-ico{width:30px;height:30px;border-radius:8px;background:var(--bg3);display:grid;place-items:center;font-size:13px;flex-shrink:0;transition:var(--tr)}
.sb-item.active .sb-ico,.sb-item:hover .sb-ico{background:var(--bg4)}
.sb-badge{margin-left:auto;background:var(--red);color:#fff;border-radius:12px;font-size:10px;font-weight:800;padding:2px 6px}
.sb-foot{padding:12px;border-top:1px solid var(--border);flex-shrink:0}
.sb-logout{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;background:var(--bg3);border:none;cursor:pointer;color:var(--muted);font-size:13px;font-weight:600;text-decoration:none;transition:var(--tr);width:100%}
.sb-logout:hover{background:rgba(239,68,68,.1);color:var(--red)}

/* ═══════════ TOPBAR ═══════════ */
.topbar{position:fixed;top:0;left:var(--sw);right:0;height:var(--nh);background:rgba(9,9,11,.9);backdrop-filter:blur(24px);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 14px;z-index:400;transition:left .38s cubic-bezier(.4,0,.2,1)}
.sidebar.hidden~.layout .topbar{left:0}
.tb-l{display:flex;align-items:center;gap:8px}
.mbtn{width:38px;height:38px;background:var(--bg2);border:1px solid var(--border);border-radius:10px;display:grid;place-items:center;cursor:pointer;color:var(--muted);font-size:14px;transition: var(--tr); margin-left: -4px;}
.mbtn:hover{border-color:var(--amber);color:var(--amber)}
.tb-title{font-family:'Bebas Neue',sans-serif;font-size:22px;letter-spacing:2px;color:var(--amber)}
.tb-sub{font-size:11px;color:var(--muted);margin-top:1px}
.tb-r{display:flex;align-items:center;gap:10px}
.tb-btn{width:38px;height:38px;background:var(--bg2);border:1px solid var(--border);border-radius:10px;display:grid;place-items:center;cursor:pointer;color:var(--muted);font-size:14px;transition:var(--tr);text-decoration:none}
.tb-btn:hover{border-color:var(--amber);color:var(--amber)}
.tb-search{display:flex;align-items:center;gap:8px;background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:8px 14px;transition:var(--tr)}
.tb-search:focus-within{border-color:var(--amber-d)}
.tb-search input{background:none;border:none;outline:none;color:var(--text);font-family:inherit;font-size:13px;width:160px}
.tb-search input::placeholder{color:var(--muted)}
.tb-search i{color:var(--muted);font-size:13px}
.search-btn{
  width:38px;height:38px;border:none;border-radius:8px;
  background:linear-gradient(135deg,var(--amber-l),var(--amber));
  color:#000;cursor:pointer;display:grid;place-items:center;
  font-size:13px;transition:var(--tr);flex-shrink:0;
}
.search-btn:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(232,160,32,.35);}
.filter-search{display:flex;align-items:center;gap:8px;flex:1;min-width:220px;}
.filter-search input{flex:1;}

/* ═══════════ LAYOUT ═══════════ */
.layout{margin-left:var(--sw);padding-top:var(--nh);transition:margin-left .38s cubic-bezier(.4,0,.2,1);flex:1;}
.sidebar.hidden~.layout{margin-left:0}
.main{padding:28px;position:relative;z-index:1}

/* ═══════════ TOAST ═══════════ */
.toast{position:fixed;top:78px;right:22px;z-index:9000;display:flex;align-items:flex-start;gap:12px;background:var(--bg2);border:1px solid var(--border2);border-radius:var(--r);padding:16px 20px;min-width:300px;box-shadow:0 20px 60px rgba(0,0,0,.5);transform:translateX(120%);transition:transform .4s cubic-bezier(.4,0,.2,1)}
.toast.show{transform:translateX(0)}
.t-ico{font-size:20px;flex-shrink:0}
.toast.success .t-ico{color:var(--green)}.toast.error .t-ico{color:var(--red)}
.t-title{font-size:14px;font-weight:800;margin-bottom:2px}.t-msg{font-size:12px;color:var(--muted)}
.t-bar{position:absolute;bottom:0;left:0;height:2px;border-radius:0 0 var(--r) var(--r);animation:tb 3.8s linear forwards}
.toast.success .t-bar{background:var(--green)}.toast.error .t-bar{background:var(--red)}
@keyframes tb{from{width:100%}to{width:0%}}

/* ═══════════ PAGE HERO ═══════════ */
.ph{background:linear-gradient(135deg,var(--bg2) 0%,var(--bg3) 100%);border:1px solid var(--border);border-radius:var(--rl);padding:34px 38px;margin-bottom:26px;position:relative;overflow:hidden;animation:fu .5s ease}
.ph::before{content:'';position:absolute;right:-50px;top:-50px;width:280px;height:280px;border-radius:50%;background:radial-gradient(circle,rgba(232,160,32,.1),transparent);pointer-events:none}
.ph::after{content:'CAR RENTALS';position:absolute;right:30px;bottom:10px;font-family:'Bebas Neue',sans-serif;font-size:68px;color:rgba(255,255,255,.03);pointer-events:none;letter-spacing:4px;line-height:1}
.ph-label{font-size:10px;font-weight:700;color:var(--amber);letter-spacing:3px;text-transform:uppercase;margin-bottom:8px}
.ph-title{font-family:'Bebas Neue',sans-serif;font-size:clamp(34px,4.5vw,58px);letter-spacing:2px;line-height:1;color:var(--text)}
.ph-title span{color:var(--amber)}
.ph-sub{font-size:13px;color:var(--muted);margin-top:8px;max-width:480px;line-height:1.65}
.ph-chips{display:flex;gap:12px;margin-top:18px;flex-wrap:wrap}
.ph-chip{display:flex;align-items:center;gap:7px;background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:7px 12px;font-size:12px;color:var(--muted)}
.ph-chip i{color:var(--amber);font-size:11px}
.ph-chip strong{color:var(--text)}

/* ═══════════ STATS ═══════════ */
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:26px}
@media(max-width:900px){.stats{grid-template-columns:repeat(2,1fr)}}
.st{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r);padding:18px;animation:fu .5s ease both;transition:var(--tr)}
.st:hover{border-color:var(--border2);transform:translateY(-2px)}
.st-i{width:36px;height:36px;border-radius:9px;display:grid;place-items:center;font-size:15px;margin-bottom:10px}
.st-n{font-family:'JetBrains Mono',monospace;font-size:24px;font-weight:700;line-height:1}
.st-l{font-size:10px;color:var(--muted);font-weight:600;margin-top:3px;letter-spacing:.5px;text-transform:uppercase}
@keyframes fu{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}

/* ═══════════ FILTER BAR ═══════════ */
.filter-bar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:var(--bg2);border:1px solid var(--border);border-radius:var(--r);padding:14px 18px;margin-bottom:22px;animation:fu .55s ease}
.filter-bar select,.filter-bar input[type=text]{height:38px;background:var(--bg3);border:1px solid var(--border);border-radius:9px;padding:0 12px;color:var(--text);font-family:inherit;font-size:13px;font-weight:500;outline:none;transition:var(--tr)}
.filter-bar select{cursor:pointer;min-width:130px}
.filter-bar select option{background:var(--bg2)}
.filter-bar input[type=text]{min-width:180px;flex:1}
.filter-bar select:focus,.filter-bar input:focus{border-color:var(--amber-d)}
.fcount{font-size:12px;color:var(--muted);font-weight:600;margin-left:auto;white-space:nowrap}

/* ═══════════ VEHICLES GRID ═══════════ */
.vg{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:20px;margin-bottom:30px}
.vc{background:var(--bg2);border:1px solid var(--border);border-radius:var(--rl);overflow:hidden;cursor:pointer;transition:transform .32s cubic-bezier(.4,0,.2,1),box-shadow .32s,border-color .32s;animation:fu .5s ease both;position:relative}
.vc:hover{transform:translateY(-7px) scale(1.013);border-color:var(--amber);box-shadow:0 22px 60px rgba(0,0,0,.5),0 0 0 1px rgba(232,160,32,.15)}
.vi{position:relative;height:195px;background:var(--bg3);overflow:hidden}
.vi img{width:100%;height:100%;object-fit:cover;transition:transform .5s ease}
.vc:hover .vi img{transform:scale(1.06)}
.vni{width:100%;height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px}
.vni i{font-size:44px;color:var(--muted2)}
.vni span{font-size:11px;color:var(--muted)}
.v-cat{position:absolute;top:12px;left:12px;background:linear-gradient(135deg,var(--amber-l),var(--amber));color:#000;font-size:10px;font-weight:800;padding:3px 9px;border-radius:5px;letter-spacing:1px;text-transform:uppercase}
.v-cond{position:absolute;top:12px;right:12px;background:rgba(9,9,11,.75);backdrop-filter:blur(6px);border:1px solid var(--border);border-radius:5px;padding:3px 9px;font-size:11px;font-weight:700}
.v-cond.available{color:var(--green)}
.vbody{padding:16px}
.vmake{font-size:10px;font-weight:700;color:var(--amber);letter-spacing:2px;text-transform:uppercase;margin-bottom:3px}
.vmodel{font-family:'Bebas Neue',sans-serif;font-size:26px;letter-spacing:1px;line-height:1}
.vyear{color:var(--muted);font-size:15px}
.vspecs{display:flex;flex-wrap:wrap;gap:5px;margin:10px 0}
.vsp{display:flex;align-items:center;gap:4px;background:var(--bg3);border:1px solid var(--border);border-radius:5px;padding:3px 8px;font-size:10px;color:var(--muted)}
.vsp i{color:var(--amber);font-size:9px}
.vfooter{display:flex;align-items:center;justify-content:space-between;margin-top:12px}
.vprice{font-family:'JetBrains Mono',monospace;font-size:20px;font-weight:700;color:var(--amber)}
.vprice small{font-size:10px;color:var(--muted);font-family:'Plus Jakarta Sans',sans-serif}
.vbtn{height:36px;padding:0 16px;border-radius:8px;border:none;background:linear-gradient(135deg,var(--amber-l),var(--amber));color:#000;font-family:inherit;font-size:12px;font-weight:800;cursor:pointer;transition:var(--tr)}
.vbtn:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(232,160,32,.35)}

/* ═══════════ RENTAL HISTORY (copy of purchases) ═══════════ */
.sec-hd{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
.sec-title{font-family:'Bebas Neue',sans-serif;font-size:22px;letter-spacing:2px;color:var(--text);display:flex;align-items:center;gap:10px}
.sec-title i{color:var(--amber);font-size:15px}
.pur-card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--rl);overflow:hidden;animation:fu .7s ease}
.pur-hd{padding:18px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.pur-hd-t{font-size:14px;font-weight:800;display:flex;align-items:center;gap:8px}
.pur-hd-t i{color:var(--amber)}
.pr{display:flex;align-items:center;gap:14px;padding:14px 24px;border-bottom:1px solid var(--border);transition:var(--tr)}
.pr:last-child{border-bottom:none}
.pr:hover{background:var(--bg3)}
.pr-thumb{width:64px;height:48px;border-radius:9px;overflow:hidden;background:var(--bg3);flex-shrink:0;display:flex;align-items:center;justify-content:center}
.pr-thumb img{width:100%;height:100%;object-fit:cover}
.pr-thumb i{font-size:20px;color:var(--muted2)}
.pr-info{flex:1;min-width:0}
.pr-vn{font-size:14px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pr-meta{font-size:11px;color:var(--muted);margin-top:3px;display:flex;align-items:center;gap:7px;flex-wrap:wrap}
.pr-meta i{font-size:10px;color:var(--amber-d)}
.pr-right{text-align:right;flex-shrink:0}
.pr-amt{font-family:'JetBrains Mono',monospace;font-size:15px;font-weight:700;color:var(--amber)}
.pr-date{font-size:11px;color:var(--muted);margin-top:2px}
.pill{display:inline-flex;align-items:center;gap:3px;font-size:10px;font-weight:800;padding:3px 8px;border-radius:20px;letter-spacing:.5px;text-transform:uppercase;margin-top:4px}
.pl-pending  {background:rgba(232,160,32,.15);color:var(--amber)}
.pl-confirmed{background:rgba(59,130,246,.15);color:var(--blue)}
.pl-active   {background:rgba(34,197,94,.15);color:var(--green)}
.pl-returned {background:rgba(34,197,94,.15);color:var(--green)}
.pl-cancelled{background:rgba(255,255,255,.07);color:var(--muted)}
.empty{padding:50px;text-align:center;color:var(--muted)}
.empty i{font-size:44px;color:var(--muted2);display:block;margin-bottom:12px}
.empty p{font-size:14px;font-weight:600}

/* ═══════════ RENTAL MODAL ═══════════ */
.ov{position:fixed;inset:0;background:rgba(0,0,0,.85);backdrop-filter:blur(12px);z-index:2000;opacity:0;pointer-events:none;transition:opacity .3s;display:flex;align-items:flex-start;justify-content:center;padding:20px;overflow-y:auto}
.ov.open{opacity:1;pointer-events:all}
.modal{background:var(--bg1);border:1px solid var(--border2);border-radius:24px;width:100%;max-width:840px;margin:auto;transform:scale(.94) translateY(20px);transition:transform .35s cubic-bezier(.4,0,.2,1);box-shadow:0 50px 120px rgba(0,0,0,.7);position:relative}
.ov.open .modal{transform:scale(1) translateY(0)}
.modal::-webkit-scrollbar{width:3px}
.mhero{display:grid;grid-template-columns:1fr 1fr;border-radius:24px 24px 0 0;overflow:hidden}
@media(max-width:620px){.mhero{grid-template-columns:1fr}}
.mh-img{position:relative;min-height:240px;background:var(--bg3)}
.mh-img img{width:100%;height:100%;object-fit:cover}
.mh-noimg{width:100%;height:100%;min-height:240px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;color:var(--muted2)}
.mh-noimg i{font-size:56px}
.mh-info{padding:28px;display:flex;flex-direction:column;gap:10px;justify-content:center;background:var(--bg2)}
.mh-cat{font-size:10px;font-weight:700;color:var(--amber);letter-spacing:2px;text-transform:uppercase}
.mh-name{font-family:'Bebas Neue',sans-serif;font-size:32px;letter-spacing:1px;line-height:1}
.mh-desc{font-size:12px;color:var(--muted);line-height:1.7}
.mh-specs{display:flex;flex-wrap:wrap;gap:6px}
.mh-spec{display:flex;align-items:center;gap:4px;background:var(--bg3);border:1px solid var(--border);border-radius:5px;padding:3px 8px;font-size:10px;color:var(--muted)}
.mh-spec i{color:var(--amber);font-size:9px}
.mh-price{font-family:'JetBrains Mono',monospace;font-size:30px;font-weight:700;color:var(--amber)}
.mh-price small{font-size:12px;color:var(--muted);font-family:'Plus Jakarta Sans',sans-serif}
.mclose{position:absolute;top:12px;right:12px;width:34px;height:34px;background:rgba(9,9,11,.7);backdrop-filter:blur(8px);border:1px solid var(--border);border-radius:9px;display:grid;place-items:center;cursor:pointer;color:var(--muted);font-size:13px;transition:var(--tr);z-index:5}
.mclose:hover{border-color:var(--red);color:var(--red)}
.mform{padding:26px 30px 30px}
.mftitle{font-family:'Bebas Neue',sans-serif;font-size:20px;letter-spacing:2px;color:var(--amber);margin-bottom:20px;display:flex;align-items:center;gap:8px}
.fg{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media(max-width:520px){.fg{grid-template-columns:1fr}}
.full{grid-column:1/-1}
.flbl{display:block;font-size:11px;font-weight:700;color:var(--muted);letter-spacing:.5px;text-transform:uppercase;margin-bottom:5px}
.flbl .req{color:var(--red)}
.fc{width:100%;height:46px;background:var(--bg3);border:1px solid var(--border);border-radius:10px;padding:0 13px;color:var(--text);font-family:inherit;font-size:13px;font-weight:500;outline:none;transition:var(--tr)}
.fc::placeholder{color:var(--muted)}
.fc:focus{border-color:var(--amber-d);box-shadow:0 0 0 3px var(--amber-g)}
select.fc{cursor:pointer}select.fc option{background:var(--bg2)}
textarea.fc{height:auto;min-height:75px;padding:11px 13px;resize:vertical}
.pbox{background:var(--bg3);border:1px solid var(--border2);border-radius:var(--r);padding:14px 18px;display:flex;align-items:center;justify-content:space-between}
.pb-lbl{font-size:12px;color:var(--muted)}
.pb-amt{font-family:'JetBrains Mono',monospace;font-size:26px;font-weight:700;color:var(--amber)}
.btn-row{display:flex;gap:10px}
.btn-c{flex:1;height:50px;border-radius:12px;background:var(--bg3);border:1px solid var(--border);color:var(--muted);font-family:inherit;font-weight:700;font-size:13px;cursor:pointer;transition:var(--tr)}
.btn-c:hover{border-color:var(--red);color:var(--red)}
.btn-s{flex:2;height:50px;border-radius:12px;border:none;background:linear-gradient(135deg,var(--amber-l),var(--amber));color:#000;font-family:'Bebas Neue',sans-serif;font-size:19px;letter-spacing:2px;cursor:pointer;transition:var(--tr);position:relative;overflow:hidden}
.btn-s::after{content:'';position:absolute;inset:0;background:linear-gradient(135deg,rgba(255,255,255,.2),transparent);opacity:0;transition:.3s}
.btn-s:hover::after{opacity:1}
.btn-s:hover{transform:translateY(-1px);box-shadow:0 10px 30px rgba(232,160,32,.4)}

/* ── Notification Panel (like rental original, but unified) ── */
.notif-panel{
  position:fixed;top:76px;right:18px;width:340px;max-height:480px;
  background:var(--bg2);border:1px solid var(--border2);border-radius:var(--rl);
  overflow:hidden;opacity:0;transform:translateY(-10px) scale(.97);
  pointer-events:none;transition:var(--tr);z-index:900;
  box-shadow:0 20px 60px rgba(0,0,0,.5);
}
.notif-panel.open{opacity:1;transform:translateY(0) scale(1);pointer-events:all}
.notif-header{padding:16px 20px;background:var(--bg3);border-bottom:1px solid var(--border);font-weight:800;font-size:14px;display:flex;align-items:center;gap:8px}
.notif-header i{color:var(--amber)}
.notif-scroll{max-height:380px;overflow-y:auto}
.notif-scroll::-webkit-scrollbar{width:3px}
.notif-scroll::-webkit-scrollbar-thumb{background:var(--amber-d);border-radius:2px}
.notif-item{display:flex;align-items:flex-start;gap:12px;padding:14px 20px;border-bottom:1px solid var(--border);transition:var(--tr)}
.notif-item:hover{background:var(--bg3)}
.notif-thumb{width:40px;height:40px;border-radius:8px;object-fit:cover;background:var(--bg3);flex-shrink:0}
.notif-info{flex:1;min-width:0}
.notif-model{font-weight:600;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.notif-meta{font-size:11px;color:var(--muted);margin-top:2px}
.notif-status{display:inline-block;font-size:10px;font-weight:800;padding:2px 8px;border-radius:20px;margin-top:4px;letter-spacing:.5px;text-transform:uppercase}
.notif-status.pending{background:rgba(232,160,32,.15);color:var(--amber)}
.notif-status.confirmed,.notif-status.active{background:rgba(59,130,246,.15);color:var(--blue)}
.notif-status.returned{background:rgba(34,197,94,.15);color:var(--green)}
.notif-status.cancelled{background:rgba(255,255,255,.07);color:var(--muted)}
.notif-empty{padding:32px;text-align:center;color:var(--muted);font-size:13px}

.sb-overlay{position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:490;opacity:0;pointer-events:none;transition:opacity .35s}
.sb-overlay.show{opacity:1;pointer-events:all}

/* ═══════════ FOOTER (gold/black theme) ═══════════ */
.app-footer {
  position: relative;
  z-index: 2;
  background: rgba(9,9,11,0.96);
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
  padding: 0 28px;
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
  line-height: 1.5;
  margin-top: 0.75rem;
}
.footer-title {
  font-weight: 800;
  font-size: 0.85rem;
  text-transform: uppercase;
  letter-spacing: 1px;
  color: var(--amber);
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
  color: var(--amber-l);
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
  color: var(--amber-l);
  transform: translateY(-2px);
}
.copyright {
  text-align: center;
  padding-top: 1.5rem;
  border-top: 1px solid rgba(232,160,32,0.1);
  font-size: 0.7rem;
  color: var(--muted);
}

@media(max-width:1024px){.layout{margin-left:0}.topbar{left:0}.sidebar{transform:translateX(calc(-1*var(--sw)))}.sidebar.mob{transform:translateX(0)}}
@media(max-width:600px){.main{padding:16px}.mform{padding:18px}.mh-info{padding:18px}}
</style>
</head>
<body>
<div class="sb-overlay" id="sbo" onclick="cSB()"></div>

<!-- ═══════════ SIDEBAR ═══════════ -->
<aside class="sidebar" id="sidebar">
  <div class="sb-brand">
    <div class="sb-logo">T</div>
    <div><div class="sb-title">TransNet X</div><div class="sb-sub"> Rentals</div></div>
    <button class="sb-x" onclick="tSB()"><i class="fas fa-times"></i></button>
  </div>
  <div class="sb-user">
    <div class="sb-urow">
      <div class="sb-av"><?= strtoupper(substr($uname,0,1)) ?></div>
      <div style="flex:1;min-width:0"><div class="sb-uname"><?= $uname ?></div><div class="sb-email"><?= $uemail ?></div></div>
    </div>
    <div class="sb-sr">
      <div class="sb-sn"><div class="sb-snn"><?= $total_rentals ?></div><div class="sb-snl"> Rentals</div></div>
      <div class="sb-sn"><div class="sb-snn"><?= $pending_rentals ?></div><div class="sb-snl"> Active</div></div>
      <div class="sb-sn"><div class="sb-snn" style="font-size:13px">₦<?= number_format($spent_rental/1000,0)?>k</div><div class="sb-snl"> Spent</div></div>
    </div>
  </div>
  <nav class="sb-nav">
    <div class="sb-lbl">Car Rentals</div>
    <button class="sb-item active" onclick="gTo('vehicles')"><i class="fas fa-car-side sb-ico"></i><span>Browse Fleet</span></button>
    <button class="sb-item" onclick="gTo('purchases')"><i class="fas fa-receipt sb-ico"></i><span>My Rentals</span><?php if($pending_rentals>0):?><span class="sb-badge"><?=$pending_rentals?></span><?php endif;?></button>
    <a href="../vehicle_sale.php" class="sb-item"><i class="fas fa-tag sb-ico"></i><span>Vehicle Sales</span></a>
    <div class="sb-lbl">Other Services</div>
    <a href="../dashboard.php" class="sb-item"><i class="fas fa-table-cells-large sb-ico"></i><span> Dashboard</span></a>
    <a href="uber.php" class="sb-item"><i class="fas fa-car sb-ico"></i><span> Uber</span></a>
    <a href="trip.php" class="sb-item"><i class="fas fa-bus sb-ico"></i><span> Trips</span></a>
    <a href="flight.php" class="sb-item"><i class="fas fa-plane sb-ico"></i><span> Flights</span></a>
    <a href="../order_food.php" class="sb-item"><i class="fas fa-utensils sb-ico"></i><span> Food Order</span></a>
    <a href="../delivery.php" class="sb-item"><i class="fas fa-box sb-ico"></i><span> Delivery</span></a>
    <a href="../emergency.php" class="sb-item"><i class="fas fa-triangle-exclamation sb-ico" style="color:var(--red)"></i><span> Emergency</span></a>
    <div class="sb-lbl">Account</div>
    <a href="../profile.php" class="sb-item"><i class="fas fa-user sb-ico"></i><span>Profile</span></a>
    <a href="../settings.php" class="sb-item"><i class="fas fa-gear sb-ico"></i><span>Settings</span></a>
  </nav>
  <div class="sb-foot"><a href="../auth/logout.php" class="sb-logout"><i class="fas fa-right-from-bracket"></i>Sign Out</a></div>
</aside>

<!-- ═══════════ TOPBAR ═══════════ -->
<header class="topbar">
  <div class="tb-l">
    <button class="mbtn" onclick="tSB()"><i class="fas fa-bars"></i></button>
    <div><div class="tb-title"> Rentals</div></div>
  </div>
  <div class="tb-r">
    <div class="tb-search">
  <input type="text" id="topSearch" placeholder="Search vehicles…" oninput="doFilter()">
  <button type="button" class="search-btn" onclick="doFilter()"><i class="fas fa-magnifying-glass"></i></button>
</div>
    <button class="tb-btn" title="Rental Updates" onclick="toggleNotif()"><i class="fas fa-bell"></i><?php if($pending_rentals>0):?><span style="position:absolute;top:-4px;right:-4px;width:18px;height:18px;background:var(--red);border-radius:50%;font-size:10px;font-weight:800;display:grid;place-items:center;color:#fff;pointer-events:none"><?=$pending_rentals?></span><?php endif;?></button>
    <a href="../vehicle_sale.php" class="tb-btn" title="Vehicle Sales"><i class="fas fa-tag"></i></a>
    <a href="../dashboard.php" class="tb-btn" title="Dashboard"><i class="fas fa-table-cells-large"></i></a>
    <a href="../emergency.php" class="tb-btn" style="color:var(--red)" title="SOS"><i class="fas fa-triangle-exclamation"></i></a>
  </div>
</header>

<!-- ═══════════ NOTIFICATION PANEL ═══════════ -->
<div class="notif-panel" id="notifPanel">
  <div class="notif-header"><i class="fas fa-bell"></i> Recent Rentals</div>
  <div class="notif-scroll">
    <?php if(count($all_rentals)>0): foreach($all_rentals as $r): 
        $status_class = strtolower($r['status']);
        $status_icon = ['pending'=>'fa-clock','confirmed'=>'fa-check','active'=>'fa-car-side','returned'=>'fa-check-double','cancelled'=>'fa-ban'][$status_class] ?? 'fa-circle';
    ?>
    <div class="notif-item">
      <?php if(!empty($r['image_url'])):?>
      <img class="notif-thumb" src="../../uploads/<?= htmlspecialchars(basename($r['image_url'])) ?>" alt="">
      <?php else:?>
      <div class="notif-thumb" style="background:var(--bg3);display:grid;place-items:center;"><i class="fas fa-car" style="color:var(--amber-d)"></i></div>
      <?php endif;?>
      <div class="notif-info">
        <div class="notif-model"><?= htmlspecialchars($r['make'].' '.$r['model'].' '.$r['year']) ?></div>
        <div class="notif-meta"><?= date('M d', strtotime($r['pickup_date'])) ?> → <?= date('M d', strtotime($r['return_date'])) ?></div>
        <span class="notif-status <?= $status_class ?>"><i class="fas <?=$status_icon?>"></i> <?= ucfirst($r['status']) ?></span>
      </div>
    </div>
    <?php endforeach; else:?>
    <div class="notif-empty"><i class="fas fa-key" style="font-size:32px;display:block;margin-bottom:8px;"></i>No rentals yet</div>
    <?php endif;?>
  </div>
</div>

<!-- ═══════════ TOAST ═══════════ -->
<div class="toast" id="toast"><div class="t-ico" id="tIco"></div><div><div class="t-title" id="tTitle"></div><div class="t-msg" id="tMsg"></div></div><div class="t-bar"></div></div>

<!-- ═══════════ RENTAL MODAL ═══════════ -->
<div class="ov" id="ov" onclick="if(event.target===this)cModal()">
  <div class="modal" id="mInner">
    <button class="mclose" onclick="cModal()"><i class="fas fa-times"></i></button>
    <div class="mhero" id="mHero"></div>
    <div class="mform">
      <div class="mftitle"><i class="fas fa-key"></i>Reserve This Vehicle</div>
      <form method="POST" action="">
        <input type="hidden" name="book_rental" value="1">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <input type="hidden" name="vehicle_id" id="fVid">
        <div class="fg">
          <div><label class="flbl">Pickup Date <span class="req">*</span></label><input class="fc" type="date" name="pickup_date" id="pickDate" required min="<?= date('Y-m-d') ?>" onchange="updateTotal()"></div>
          <div><label class="flbl">Return Date <span class="req">*</span></label><input class="fc" type="date" name="return_date" id="retDate" required min="<?= date('Y-m-d') ?>" onchange="updateTotal()"></div>
          <div class="full"><label class="flbl">Pickup Location <span class="req">*</span></label><input class="fc" type="text" name="pickup_location" required placeholder="e.g. Lagos Island"></div>
          <div class="full"><label class="flbl">Driver Option</label>
            <label style="display:flex;align-items:center;gap:10px;padding:12px;background:var(--bg3);border:1px solid var(--border);border-radius:10px;cursor:pointer;margin-top:6px">
              <input type="checkbox" name="with_driver" id="withDriver" onchange="updateTotal()"> Add professional driver (+₦15,000/day)
            </label></div>
          <div class="full"><label class="flbl">Special Notes</label><textarea class="fc" name="notes" placeholder="Any special requests…"></textarea></div>
          <div class="full"><div class="pbox"><div><div class="pb-lbl">Estimated Total</div><div style="font-size:10px;color:var(--muted);margin-top:2px"><span id="daysCount">0</span> day(s)</div></div><div class="pb-amt" id="mPrice">₦0</div></div></div>
          <div class="full"><div class="btn-row"><button type="button" class="btn-c" onclick="cModal()">Cancel</button><button type="submit" class="btn-s">Confirm Rental</button></div></div>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ═══════════ LAYOUT ═══════════ -->
<div class="layout" id="layout">
<main class="main">

  <!-- HERO -->
  <div class="ph">
    <div class="ph-label">TransNet × Rentals</div>
    <div class="ph-title">Rent a Car<br><span>Drive Your Dream</span></div>
    <div class="ph-sub">Premium vehicles at daily rates. Self‑drive or with a professional chauffeur. Flexible pickup & return.</div>
    <div class="ph-chips">
      <div class="ph-chip"><i class="fas fa-car-side"></i><strong><?= count($vehicles) ?></strong> Available</div>
      <div class="ph-chip"><i class="fas fa-shield-check"></i>Insured <strong>Fleet</strong></div>
      <div class="ph-chip"><i class="fas fa-clock"></i>24 / 7 <strong>Support</strong></div>
      <div class="ph-chip"><i class="fas fa-calendar-check"></i>Flexible <strong>Booking</strong></div>
    </div>
  </div>

  <!-- ACTIVE RENTAL TRACKER BAR -->
  <?php if ($has_active_rental): ?>
  <div class="tracker-card" style="background: linear-gradient(135deg, var(--bg2) 0%, var(--bg3) 100%); border: 1px solid var(--border2); border-radius: var(--rl); padding: 22px 28px; margin-bottom: 26px; animation: fu .5s ease both;">
    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px; margin-bottom: 14px;">
      <div>
        <div style="font-size: 10px; font-weight: 700; color: var(--amber); letter-spacing: 2px; text-transform: uppercase; margin-bottom: 4px;">
          <i class="fas fa-route"></i> Active Rental Tracker
        </div>
        <h3 style="font-family: 'Bebas Neue', sans-serif; font-size: 28px; letter-spacing: 1px; line-height: 1.1; color: var(--text);">
          <?= htmlspecialchars($active_rental['car_model']) ?> <span style="font-size: 18px; color: var(--muted); font-family: 'Plus Jakarta Sans', sans-serif; font-weight: 500;">(<?= htmlspecialchars($active_rental['year']) ?>)</span>
        </h3>
        <p style="font-size: 12px; color: var(--muted); margin-top: 6px;">
          <i class="fas fa-location-dot" style="color: var(--amber); margin-right: 4px;"></i> Pickup: <strong><?= htmlspecialchars($active_rental['pickup_location']) ?></strong> · <?= $active_rental['driver_option'] ? 'Chauffeur Driven' : 'Self-drive' ?>
        </p>
      </div>
      <div style="text-align: right; min-width: 120px;">
        <span class="pill pl-<?= strtolower($active_rental['status']) ?>" style="font-size: 11px; padding: 4px 12px;"><i class="fas <?= ['pending'=>'fa-clock','confirmed'=>'fa-check','active'=>'fa-car-side'][$active_rental['status']] ?? 'fa-circle' ?>"></i> <?= ucfirst($active_rental['status']) ?></span>
        <div style="font-size: 12px; font-weight: 600; color: var(--text); margin-top: 8px; font-family: 'JetBrains Mono', monospace;">₦<?= number_format($active_rental['total_price']) ?></div>
      </div>
    </div>
    
    <div style="background: var(--bg4); border: 1px solid var(--border); border-radius: 6px; padding: 14px 18px;">
      <div style="display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 8px;">
        <span><?= $countdown_text ?></span>
        <span style="font-family: 'JetBrains Mono', monospace; font-weight: 700; color: var(--amber);"><?= $progress ?>% Complete</span>
      </div>
      <div class="tracker-bar" style="height: 8px; background: var(--bg); border-radius: 4px; overflow: hidden; border: 1px solid rgba(255,255,255,0.04);">
        <div class="tracker-fill" style="width: <?= $progress ?>%; height: 100%; background: linear-gradient(90deg, var(--amber-d), var(--amber)); border-radius: 4px; transition: width 0.5s ease;"></div>
      </div>
      <div style="display: flex; justify-content: space-between; font-size: 11px; color: var(--muted); margin-top: 8px;">
        <span><i class="far fa-calendar-check" style="margin-right: 4px;"></i>Collected: <strong><?= $pickup_date_formatted ?></strong></span>
        <span><i class="far fa-calendar-xmark" style="margin-right: 4px;"></i>Return: <strong><?= $return_date_formatted ?></strong></span>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- STATS -->
  <div class="stats">
    <div class="st" style="animation-delay:.1s">
      <div class="st-i" style="background:rgba(232,160,32,.12);color:var(--amber)"><i class="fas fa-car"></i></div>
      <div class="st-n" style="color:var(--amber)"><?= count($vehicles) ?></div>
      <div class="st-l">Fleet Size</div>
    </div>
    <div class="st" style="animation-delay:.2s">
      <div class="st-i" style="background:rgba(34,197,94,.1);color:var(--green)"><i class="fas fa-check-circle"></i></div>
      <div class="st-n" style="color:var(--green)"><?= $total_rentals ?></div>
      <div class="st-l">My Rentals</div>
    </div>
    <div class="st" style="animation-delay:.3s">
      <div class="st-i" style="background:rgba(232,160,32,.1);color:var(--amber)"><i class="fas fa-clock"></i></div>
      <div class="st-n" style="color:var(--amber)"><?= $pending_rentals ?></div>
      <div class="st-l">Active</div>
    </div>
    <div class="st" style="animation-delay:.4s">
      <div class="st-i" style="background:rgba(239,68,68,.1);color:var(--red)"><i class="fas fa-wallet"></i></div>
      <div class="st-n" style="color:var(--red);font-size:18px">₦<?= number_format($spent_rental/1000,1)?>k</div>
      <div class="st-l">Spent</div>
    </div>
  </div>

  <!-- FILTER BAR -->
  <div class="filter-bar" id="vehicles">
    <i class="fas fa-sliders" style="color:var(--amber);font-size:15px;flex-shrink:0"></i>
    <div class="filter-search">
  <input type="text" id="localSearch" placeholder="Search make, model, year…" oninput="doFilter()">
  <button type="button" class="search-btn" onclick="doFilter()"><i class="fas fa-search"></i></button>
</div>
    <select id="fCat" onchange="doFilter()"><option value="">All Categories</option><?php foreach($categories as $c):?><option value="<?=htmlspecialchars($c)?>"><?=htmlspecialchars($c)?></option><?php endforeach;?></select>
    <select id="fMake" onchange="doFilter()"><option value="">All Makes</option><?php foreach($makes as $m):?><option value="<?=htmlspecialchars($m)?>"><?=htmlspecialchars($m)?></option><?php endforeach;?></select>
    <select id="fSort" onchange="doFilter()"><option value="newest">Newest</option><option value="price_asc">Price ↑</option><option value="price_desc">Price ↓</option></select>
    <div class="fcount" id="fCount"><?=count($vehicles)?> vehicles</div>
  </div>

  <!-- VEHICLE GRID -->
  <div class="vg" id="vGrid">
    <?php foreach($vehicles as $i=>$v):
      $vehicleJson = htmlspecialchars(json_encode($v, JSON_HEX_APOS|JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');
    ?>
    <div class="vc"
      data-name="<?=htmlspecialchars(strtolower($v['make'].' '.$v['model'].' '.$v['year']), ENT_QUOTES, 'UTF-8')?>"
      data-cat="<?=htmlspecialchars(strtolower($v['category']??''), ENT_QUOTES, 'UTF-8')?>"
      data-make="<?=htmlspecialchars(strtolower($v['make']??''), ENT_QUOTES, 'UTF-8')?>"
      data-price="<?=htmlspecialchars($v['price_per_day'], ENT_QUOTES, 'UTF-8')?>"
      data-year="<?=$v['year']?>"
      style="animation-delay:<?=($i%12)*.06?>s"
      onclick="<?= $has_active_rental ? "showToast('error', 'Active Rental Exists', 'You cannot book another vehicle while you have an active rental.');" : "oModal(" . $vehicleJson . ")" ?>">
      <div class="vi">
        <?php if(!empty($v['image_url'])):?>
        <img src="../../uploads/<?= htmlspecialchars(basename($v['image_url'])) ?>" loading="lazy">
        <?php else:?><div class="vni"><i class="fas fa-car"></i><span>No Image</span></div><?php endif;?>
        <div class="v-cat"><?=htmlspecialchars($v['category']??'Rental')?></div>
        <div class="v-cond available">Available</div>
      </div>
      <div class="vbody">
        <div class="vmake"><?=htmlspecialchars($v['make'])?></div>
        <div class="vmodel"><?=htmlspecialchars($v['model'])?> <span class="vyear"><?= htmlspecialchars($v['year']) ?></span></div>
        <div class="vspecs">
          <?php if(!empty($v['transmission'])):?><div class="vsp"><i class="fas fa-gears"></i><?=htmlspecialchars($v['transmission'])?></div><?php endif;?>
          <?php if(!empty($v['fuel_type'])):?><div class="vsp"><i class="fas fa-gas-pump"></i><?=htmlspecialchars($v['fuel_type'])?></div><?php endif;?>
          <?php if(!empty($v['seats'])):?><div class="vsp"><i class="fas fa-users"></i><?=$v['seats']?> seats</div><?php endif;?>
        </div>
        <div class="vfooter">
          <div class="vprice">₦<?=number_format($v['price_per_day'])?><small>/day</small></div>
          <button class="vbtn" <?= $has_active_rental ? "style='opacity:0.5; cursor:not-allowed;'" : "" ?> onclick='event.stopPropagation(); <?= $has_active_rental ? "showToast(\"error\", \"Active Rental Exists\", \"You cannot book another vehicle while you have an active rental.\");" : "oModal(" . $vehicleJson . ")" ?>'>Rent Now</button>
        </div>
      </div>
    </div>
    <?php endforeach;?>
    <?php if(count($vehicles)===0):?>
    <div class="empty" style="grid-column:1/-1"><i class="fas fa-car-side"></i><p>No vehicles available at the moment.</p></div>
    <?php endif;?>
  </div>
  <div id="noResults" class="empty" style="display:none"><i class="fas fa-search"></i><p>No vehicles found</p></div>

  <!-- RENTAL HISTORY -->
  <div id="purchases">
    <div class="sec-hd"><div class="sec-title"><i class="fas fa-receipt"></i>My Rentals</div></div>
    <div class="pur-card">
      <div class="pur-hd"><div class="pur-hd-t"><i class="fas fa-clock-rotate-left"></i>All Rentals</div><span style="font-size:12px;color:var(--muted);font-weight:600"><?=$total_rentals?> total</span></div>
      <?php if(count($all_rentals)>0): foreach($all_rentals as $r):
        $status_class = strtolower($r['status']);
        $status_icon = ['pending'=>'fa-clock','confirmed'=>'fa-check','active'=>'fa-car-side','returned'=>'fa-check-double','cancelled'=>'fa-ban'][$status_class] ?? 'fa-circle';
      ?>
      <div class="pr">
        <div class="pr-thumb"><?php if(!empty($r['image_url'])):?><img src="../../uploads/<?=htmlspecialchars(basename($r['image_url']))?>" alt=""><?php else:?><i class="fas fa-car-side"></i><?php endif;?></div>
        <div class="pr-info">
          <div class="pr-vn"><?=htmlspecialchars($r['car_model'].' ('.$r['year'].')')?></div>
          <div class="pr-meta">
            <span><i class="fas fa-calendar-alt"></i><?=date('M d', strtotime($r['pickup_date']))?> → <?=date('M d', strtotime($r['return_date']))?></span>
            <?php if(!empty($r['pickup_location'])):?><span><i class="fas fa-location-dot"></i><?=htmlspecialchars($r['pickup_location'])?></span><?php endif;?>
            <span><i class="fas fa-user-check"></i><?=$r['driver_option']?'With Driver':'Self‑drive'?></span>
          </div>
          <span class="pill pl-<?=$status_class?>"><i class="fas <?=$status_icon?>"></i> <?=ucfirst($r['status'])?></span>
        </div>
        <div class="pr-right"><div class="pr-amt">₦<?=number_format($r['total_price'])?></div><div class="pr-date"><?=date('M d, Y',strtotime($r['created_at']))?></div></div>
      </div>
      <?php endforeach; else:?>
      <div class="empty"><i class="fas fa-key"></i><p>No rentals yet</p><small>Browse the fleet and book your first ride.</small></div>
      <?php endif;?>
    </div>
  </div>

</main></div>

<!-- ========== INTEGRATED FOOTER (gold/black theme) ========== -->
<footer class="app-footer">
  <div class="footer-container">
    <div class="footer-grid">
      <!-- Brand column -->
      <div class="footer-brand">
        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
          <div style="width: 32px; height: 32px; border-radius: 8px; background: linear-gradient(135deg, var(--amber-d), var(--amber)); display: flex; align-items: center; justify-content: center;">
            <i class="fas fa-compass" style="color: #000; font-size: 1rem;"></i>
          </div>
          <span style="font-weight: 800; font-size: 1.2rem; background: linear-gradient(135deg, var(--amber-l), var(--amber)); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">TransNet X</span>
        </div>
        <p>Your one-stop travel & lifestyle platform. Book rides, flights, trips, rentals, and more with ease.</p>
      </div>

      <!-- Quick Links -->
      <div>
        <h4 class="footer-title">Quick Links</h4>
        <ul class="footer-links">
          <li><a href="../about.php">About Us</a></li>
          <li><a href="../profile.php">Contact Us</a></li>
          <li><a href="#">Privacy Policy</a></li>
          <li><a href="#">Terms of Service</a></li>
        </ul>
      </div>

      <!-- Support -->
      <div>
        <h4 class="footer-title">Support</h4>
        <ul class="footer-links">
          <li><a href="#">FAQ</a></li>
          <li><a href="#">Help Center</a></li>
          <li><a href="#">Refund Policy</a></li>
        </ul>
      </div>

      <!-- Connect -->
      <div>
        <h4 class="footer-title">Connect With Us</h4>
        <div class="social-icons">
          <a href="#"><i class="fab fa-facebook-f"></i></a>
          <a href="#"><i class="fab fa-twitter"></i></a>
          <a href="#"><i class="fab fa-instagram"></i></a>
          <a href="#"><i class="fab fa-linkedin-in"></i></a>
        </div>
        <p class="copyright">© 2025 TransNet X. All rights reserved.</p>
      </div>
    </div>
  </div>
</footer>

<script>
// ── Sidebar Toggle ──
function tSB() {
  const sb = document.getElementById('sidebar');
  const overlay = document.getElementById('sbo');
  if (window.innerWidth <= 1024) {
    sb.classList.toggle('mob');
    overlay.classList.toggle('show');
  } else {
    sb.classList.toggle('hidden');
  }
}
function cSB() {
  document.getElementById('sidebar').classList.remove('mob','hidden');
  document.getElementById('sbo').classList.remove('show');
}

// ── Scroll to section ──
function gTo(id){document.getElementById(id).scrollIntoView({behavior:'smooth'});}

// ── Vehicle Filter (identical to vehicle sale) ──
function doFilter() {
  const qTop = document.getElementById('topSearch').value.toLowerCase().trim();
  const qLocal = document.getElementById('localSearch').value.toLowerCase().trim();
  const search = (qTop + ' ' + qLocal).trim();
  const fCat = document.getElementById('fCat').value.toLowerCase();
  const fMake = document.getElementById('fMake').value.toLowerCase();
  const fSort = document.getElementById('fSort').value;

  const grid = document.getElementById('vGrid');
  const cards = Array.from(document.querySelectorAll('.vc'));
  let visibleCount = 0;

  cards.forEach(card => {
    const name = card.dataset.name || '';
    const cat = card.dataset.cat || '';
    const make = card.dataset.make || '';
    const matchSearch = !search || name.includes(search) || name.includes(qTop) || name.includes(qLocal);
    const matchCat = !fCat || cat === fCat;
    const matchMake = !fMake || make === fMake;

    if (matchSearch && matchCat && matchMake) {
      card.style.display = '';
      visibleCount++;
    } else {
      card.style.display = 'none';
    }
  });

  const visibleCards = cards.filter(card => card.style.display !== 'none');
  if (fSort === 'price_asc') visibleCards.sort((a,b) => Number(a.dataset.price) - Number(b.dataset.price));
  else if (fSort === 'price_desc') visibleCards.sort((a,b) => Number(b.dataset.price) - Number(a.dataset.price));
  else visibleCards.sort((a,b) => (b.dataset.year||0) - (a.dataset.year||0));

  visibleCards.forEach(card => grid.appendChild(card));
  document.getElementById('fCount').textContent = visibleCount + ' vehicles';
  document.getElementById('noResults').style.display = visibleCount === 0 ? 'block' : 'none';
}
document.getElementById('topSearch').addEventListener('keypress', e => {if(e.key==='Enter')doFilter();});
document.getElementById('localSearch').addEventListener('keypress', e => {if(e.key==='Enter')doFilter();});

// ── Notification Toggle ──
function toggleNotif() {
  document.getElementById('notifPanel').classList.toggle('open');
}
document.addEventListener('click', e => {
  const np = document.getElementById('notifPanel');
  if (!np.contains(e.target) && !e.target.closest('.tb-btn[title="Rental Updates"]')) {
    np.classList.remove('open');
  }
});

// ── Rental Modal ──
let currentVehicle = null;
function oModal(vehicle) {
  currentVehicle = vehicle;
  document.getElementById('fVid').value = vehicle.id;
  document.getElementById('pickDate').value = '';
  document.getElementById('retDate').value = '';
  document.getElementById('withDriver').checked = false;
  document.getElementById('mPrice').textContent = '₦0';
  document.getElementById('daysCount').textContent = '0';

  const hero = document.getElementById('mHero');
  hero.innerHTML = `
    <div class="mh-img">
      ${vehicle.image_url ? `<img src="../../uploads/${escHtml(vehicle.image_url)}" alt="">` : `<div class="mh-noimg"><i class="fas fa-car"></i><span>No Image</span></div>`}
    </div>
    <div class="mh-info">
      <div class="mh-cat">${escHtml(vehicle.category||'Rental')}</div>
      <div class="mh-name">${escHtml(vehicle.make)} ${escHtml(vehicle.model)} <span style="font-size:20px;color:var(--muted)">${vehicle.year}</span></div>
      <div class="mh-specs">
        ${vehicle.transmission ? `<div class="mh-spec"><i class="fas fa-gears"></i>${escHtml(vehicle.transmission)}</div>` : ''}
        ${vehicle.fuel_type ? `<div class="mh-spec"><i class="fas fa-gas-pump"></i>${escHtml(vehicle.fuel_type)}</div>` : ''}
        ${vehicle.seats ? `<div class="mh-spec"><i class="fas fa-users"></i>${vehicle.seats} seats</div>` : ''}
      </div>
      <div class="mh-price">₦${Number(vehicle.price_per_day).toLocaleString('en-NG')} <small>/day</small></div>
      ${vehicle.description ? `<div class="mh-desc">${escHtml(vehicle.description)}</div>` : ''}
    </div>
  `;

  document.getElementById('ov').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function cModal(){
  document.getElementById('ov').classList.remove('open');
  document.body.style.overflow = '';
}

// ── Total Price Calculator ──
function updateTotal() {
  if (!currentVehicle) return;
  const pickup = document.getElementById('pickDate').value;
  const ret = document.getElementById('retDate').value;
  const wd = document.getElementById('withDriver').checked;
  let days = 0;
  if (pickup && ret) {
    days = Math.max(1, Math.round((new Date(ret) - new Date(pickup)) / 86400000));
  }
  const daily = parseFloat(currentVehicle.price_per_day);
  const total = (daily + (wd ? 15000 : 0)) * days;
  document.getElementById('mPrice').textContent = '₦' + total.toLocaleString('en-NG');
  document.getElementById('daysCount').textContent = days;
}

// ── Utilities ──
function escHtml(str){ return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// ── Toast ──
function showToast(type,title,msg){
  const t = document.getElementById('toast');
  document.getElementById('tIco').innerHTML = type==='success'?'<i class="fas fa-circle-check"></i>':'<i class="fas fa-circle-xmark"></i>';
  document.getElementById('tTitle').textContent = title;
  document.getElementById('tMsg').textContent = msg;
  t.className = `toast ${type} show`;
  setTimeout(()=>t.classList.remove('show'),4000);
}
<?php if($msg_s): ?>showToast('success','Booking Successful!',<?=json_encode($msg_s)?>);<?php endif;?>
<?php if($msg_e): ?>showToast('error','Booking Failed',<?=json_encode($msg_e)?>);<?php endif;?>

// Initial card stagger
document.querySelectorAll('.vc').forEach((c,i)=>c.style.animationDelay=(i%12)*.06+'s');
</script>
</body>
</html>