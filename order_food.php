<?php
session_start();
require_once '../config/db.php';

// Auth guard
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$user_id = intval($_SESSION['user_id']);

// Fetch user info
$uq = mysqli_query($conn, "SELECT * FROM users WHERE id = $user_id LIMIT 1");
$user = mysqli_fetch_assoc($uq);
if (!$user) {
    session_destroy();
    header("Location: ../auth/login.php?err=account_deleted");
    exit();
}
$uname  = htmlspecialchars($user['name'] ?? 'User', ENT_QUOTES, 'UTF-8');
$uemail = htmlspecialchars($user['email'] ?? '', ENT_QUOTES, 'UTF-8');
$uphone = htmlspecialchars($user['phone'] ?? '', ENT_QUOTES, 'UTF-8');

// All available foods
$foods_query = mysqli_query($conn, "SELECT * FROM foods WHERE is_available = 1 ORDER BY category, name");
if (!$foods_query) die("Query error: " . mysqli_error($conn));
$foods = [];
while ($r = mysqli_fetch_assoc($foods_query)) $foods[] = $r;

// Extract categories for filter
$categories = array_unique(array_column($foods, 'category'));
sort($categories);

// User orders (joined with foods)
$all_orders_query = mysqli_query($conn,
    "SELECT o.*, f.name AS food_name, f.image_url, f.category 
     FROM orders o 
     JOIN foods f ON o.food_id = f.id 
     WHERE o.user_id = $user_id 
     ORDER BY o.created_at DESC");
if (!$all_orders_query) die("Query error: " . mysqli_error($conn));
$all_orders = [];
while ($r = mysqli_fetch_assoc($all_orders_query)) $all_orders[] = $r;

$total_orders   = count($all_orders);
$pending_orders = count(array_filter($all_orders, fn($o) => in_array($o['status'], ['pending', 'processing'])));
$spent_food     = array_sum(array_column(array_filter($all_orders, fn($o) => $o['status'] === 'delivered'), 'total_price'));

// Handle order placement
$msg_s = '';
$msg_e = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
    // CSRF CHECK
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $msg_e = "Invalid request. Please refresh the page and try again.";
    } else {
        // Rate limit
        if (isset($_SESSION['last_order']) && (time() - $_SESSION['last_order']) < 10) {
            $msg_e = "Please wait a few seconds before submitting again.";
        } else {
            // Sanitize inputs
            $food_id          = intval($_POST['food_id']);
            $quantity         = max(1, intval($_POST['quantity']));
            $phone            = trim($_POST['phone']);
            $dropoff_address  = trim($_POST['dropoff_address']);
            $payment_method   = trim($_POST['payment_method']);
            $delivery_pref    = trim($_POST['delivery_preference']);
            $note             = trim($_POST['note']);

            // Allowed values
            $allowedMethods = ['cash', 'card', 'transfer', 'wallet'];
            $payment_method = in_array($payment_method, $allowedMethods, true) ? $payment_method : 'cash';

            // Validation
            if (!$phone || !$dropoff_address || !$food_id) {
                $msg_e = "Please fill all required fields.";
            } elseif (strlen($phone) > 20) {
                $msg_e = "Phone number is too long.";
            } elseif (strlen($dropoff_address) > 300) {
                $msg_e = "Address is too long.";
            } elseif (strlen($note) > 1000) {
                $msg_e = "Note is too long.";
            } elseif (!preg_match('/^[0-9+\-\s]{7,20}$/', $phone)) {
                $msg_e = "Invalid phone number.";
            } else {
                // Fetch food price
                $fp = mysqli_query($conn, "SELECT price FROM foods WHERE id = $food_id AND is_available = 1");
                $fd = mysqli_fetch_assoc($fp);
                if (!$fd) {
                    $msg_e = "This food item is no longer available.";
                } else {
                    $unit_price   = (float)$fd['price'];
                    $delivery_fee = 500.00;  // fixed delivery fee
                    $total_price  = ($unit_price * $quantity) + $delivery_fee;

                    // Insert order with a prepared statement
                    $ins_stmt = mysqli_prepare($conn,
                        "INSERT INTO orders (
                            user_id, food_id, quantity, unit_price, total_price,
                            delivery_fee, phone, dropoff_address,
                            special_note, payment_method, delivery_time_pref,
                            pickup_address, status, created_at
                        ) VALUES (
                            ?, ?, ?, ?, ?,
                            ?, ?, ?,
                            ?, ?, ?,
                            ?, 'pending', NOW()
                        )"
                    );

                    // pickup_address default
                    $pickup_address = 'TransNetX Kitchen HQ';

                    // Bind the 13 parameters
                    mysqli_stmt_bind_param($ins_stmt, "iiidddssssss",
                        $user_id, $food_id, $quantity, $unit_price, $total_price,
                        $delivery_fee, $phone, $dropoff_address,
                        $note, $payment_method, $delivery_pref,
                        $pickup_address
                    );

                    if (mysqli_stmt_execute($ins_stmt)) {
                        $_SESSION['last_order'] = time();
                        header("Location: order_food.php?ok=1");
                        exit();
                    } else {
                        $msg_e = "Failed to place order. Please try again.";
                    }
                }
            }
        }
    }
}

if (isset($_GET['ok'])) {
    $msg_s = "Order placed successfully! Your food will be delivered shortly.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>TransNetX — Food Order</title>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<style>
/* (Your existing CSS kept intact) */
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

/* SIDEBAR */
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

/* TOPBAR */
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
/* SEARCH BUTTONS */
.search-btn{
  width:38px;
  height:38px;
  border:none;
  border-radius:8px;
  background:linear-gradient(135deg,var(--amber-l),var(--amber));
  color:#000;
  cursor:pointer;
  display:grid;
  place-items:center;
  font-size:13px;
  transition:var(--tr);
  flex-shrink:0;
}

.search-btn:hover{
  transform:translateY(-1px);
  box-shadow:0 6px 18px rgba(232,160,32,.35);
}

.filter-search{
  display:flex;
  align-items:center;
  gap:8px;
  flex:1;
  min-width:220px;
}

.filter-search input{
  flex:1;
}

/* LAYOUT */
.layout{margin-left:var(--sw);padding-top:var(--nh);transition:margin-left .38s cubic-bezier(.4,0,.2,1);flex:1;}
.sidebar.hidden~.layout{margin-left:0}
.main{padding:28px;position:relative;z-index:1}

/* TOAST */
.toast{position:fixed;top:78px;right:22px;z-index:9000;display:flex;align-items:flex-start;gap:12px;background:var(--bg2);border:1px solid var(--border2);border-radius:var(--r);padding:16px 20px;min-width:300px;box-shadow:0 20px 60px rgba(0,0,0,.5);transform:translateX(120%);transition:transform .4s cubic-bezier(.4,0,.2,1)}
.toast.show{transform:translateX(0)}
.t-ico{font-size:20px;flex-shrink:0}
.toast.success .t-ico{color:var(--green)}.toast.error .t-ico{color:var(--red)}
.t-title{font-size:14px;font-weight:800;margin-bottom:2px}.t-msg{font-size:12px;color:var(--muted)}
.t-bar{position:absolute;bottom:0;left:0;height:2px;border-radius:0 0 var(--r) var(--r);animation:tb 3.8s linear forwards}
.toast.success .t-bar{background:var(--green)}.toast.error .t-bar{background:var(--red)}
@keyframes tb{from{width:100%}to{width:0%}}

/* PAGE HERO */
.ph{background:linear-gradient(135deg,var(--bg2) 0%,var(--bg3) 100%);border:1px solid var(--border);border-radius:var(--rl);padding:34px 38px;margin-bottom:26px;position:relative;overflow:hidden;animation:fu .5s ease}
.ph::before{content:'';position:absolute;right:-50px;top:-50px;width:280px;height:280px;border-radius:50%;background:radial-gradient(circle,rgba(232,160,32,.1),transparent);pointer-events:none}
.ph::after{content:'FOOD DELIVERY';position:absolute;right:30px;bottom:10px;font-family:'Bebas Neue',sans-serif;font-size:68px;color:rgba(255,255,255,.03);pointer-events:none;letter-spacing:4px;line-height:1}
.ph-label{font-size:10px;font-weight:700;color:var(--amber);letter-spacing:3px;text-transform:uppercase;margin-bottom:8px}
.ph-title{font-family:'Bebas Neue',sans-serif;font-size:clamp(34px,4.5vw,58px);letter-spacing:2px;line-height:1;color:var(--text)}
.ph-title span{color:var(--amber)}
.ph-sub{font-size:13px;color:var(--muted);margin-top:8px;max-width:480px;line-height:1.65}
.ph-chips{display:flex;gap:12px;margin-top:18px;flex-wrap:wrap}
.ph-chip{display:flex;align-items:center;gap:7px;background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:7px 12px;font-size:12px;color:var(--muted)}
.ph-chip i{color:var(--amber);font-size:11px}
.ph-chip strong{color:var(--text)}

/* STATS */
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:26px}
@media(max-width:900px){.stats{grid-template-columns:repeat(2,1fr)}}
.st{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r);padding:18px;animation:fu .5s ease both;transition:var(--tr)}
.st:hover{border-color:var(--border2);transform:translateY(-2px)}
.st-i{width:36px;height:36px;border-radius:9px;display:grid;place-items:center;font-size:15px;margin-bottom:10px}
.st-n{font-family:'JetBrains Mono',monospace;font-size:24px;font-weight:700;line-height:1}
.st-l{font-size:10px;color:var(--muted);font-weight:600;margin-top:3px;letter-spacing:.5px;text-transform:uppercase}

@keyframes fu{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}

/* FILTER BAR */
.filter-bar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:var(--bg2);border:1px solid var(--border);border-radius:var(--r);padding:14px 18px;margin-bottom:22px;animation:fu .55s ease}
.filter-bar select,.filter-bar input[type=text]{height:38px;background:var(--bg3);border:1px solid var(--border);border-radius:9px;padding:0 12px;color:var(--text);font-family:inherit;font-size:13px;font-weight:500;outline:none;transition:var(--tr)}
.filter-bar select{cursor:pointer;min-width:130px}
.filter-bar select option{background:var(--bg2)}
.filter-bar input[type=text]{min-width:180px;flex:1}
.filter-bar select:focus,.filter-bar input:focus{border-color:var(--amber-d)}
.fcount{font-size:12px;color:var(--muted);font-weight:600;margin-left:auto;white-space:nowrap}

/* FOOD GRID (same as vehicle grid) */
.vg{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:20px;margin-bottom:30px}
.vc{background:var(--bg2);border:1px solid var(--border);border-radius:var(--rl);overflow:hidden;cursor:pointer;transition:transform .32s cubic-bezier(.4,0,.2,1),box-shadow .32s,border-color .32s;animation:fu .5s ease both;position:relative}
.vc:hover{transform:translateY(-7px) scale(1.013);border-color:var(--amber);box-shadow:0 22px 60px rgba(0,0,0,.5),0 0 0 1px rgba(232,160,32,.15)}
.vc.reserved{opacity:.6;cursor:default}.vc.reserved:hover{transform:none;border-color:var(--border);box-shadow:none}
.vi{position:relative;height:195px;background:var(--bg3);overflow:hidden}
.vi img{width:100%;height:100%;object-fit:cover;transition:transform .5s ease}
.vc:hover .vi img{transform:scale(1.06)}
.vni{width:100%;height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px}
.vni i{font-size:44px;color:var(--muted2)}
.vni span{font-size:11px;color:var(--muted)}
.v-cat{position:absolute;top:12px;left:12px;background:linear-gradient(135deg,var(--amber-l),var(--amber));color:#000;font-size:10px;font-weight:800;padding:3px 9px;border-radius:5px;letter-spacing:1px;text-transform:uppercase}
.v-cond{position:absolute;top:12px;right:12px;background:rgba(9,9,11,.75);backdrop-filter:blur(6px);border:1px solid var(--border);border-radius:5px;padding:3px 9px;font-size:11px;font-weight:700}
.v-cond.new{color:var(--green)}.v-cond.used{color:#C8C8D4}
.v-rsv{position:absolute;inset:0;background:rgba(9,9,11,.7);display:flex;align-items:center;justify-content:center;font-family:'Bebas Neue',sans-serif;font-size:26px;letter-spacing:3px;color:rgba(255,255,255,.4)}
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
.vbtn:disabled{opacity:.5;cursor:not-allowed;transform:none;box-shadow:none}
.vrating{display:flex;align-items:center;gap:3px;margin-top:6px;font-size:10px;color:var(--muted)}
.vrating i{font-size:9px}

/* PURCHASES */
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
.pl-approved {background:rgba(59,130,246,.15);color:var(--blue)}
.pl-completed{background:rgba(34,197,94,.15);color:var(--green)}
.pl-cancelled{background:rgba(255,255,255,.07);color:var(--muted)}
.pl-rejected {background:rgba(239,68,68,.15);color:var(--red)}
.empty{padding:50px;text-align:center;color:var(--muted)}
.empty i{font-size:44px;color:var(--muted2);display:block;margin-bottom:12px}
.empty p{font-size:14px;font-weight:600}

/* MODAL */
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
/* Quantity selector styled as plan grid */
.plan-grid{display:flex;gap:9px;flex-wrap:wrap}
.pc{flex:1;min-width:100px;padding:12px 8px;border:1px solid var(--border);border-radius:10px;background:var(--bg3);cursor:pointer;transition:var(--tr);text-align:center}
.pc:hover{border-color:var(--border2)}
.pc.sel{border-color:var(--amber);background:var(--amber-g)}
.pc i{font-size:18px;display:block;margin-bottom:6px;color:var(--muted)}
.pc.sel i{color:var(--amber)}
.pc span{font-size:11px;font-weight:700;color:var(--muted)}
.pc.sel span{color:var(--amber)}
.pay-opts{display:flex;gap:9px;flex-wrap:wrap}
.po{flex:1;min-width:90px;padding:10px;border:1px solid var(--border);border-radius:10px;background:var(--bg3);cursor:pointer;transition:var(--tr);display:flex;flex-direction:column;align-items:center;gap:4px}
.po:hover{border-color:var(--border2)}
.po.sel{border-color:var(--amber);background:var(--amber-g)}
.po i{font-size:16px;color:var(--muted)}
.po.sel i{color:var(--amber)}
.po span{font-size:10px;font-weight:700;color:var(--muted)}
.po.sel span{color:var(--amber)}
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

.sb-overlay{position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:490;opacity:0;pointer-events:none;transition:opacity .35s}
.sb-overlay.show{opacity:1;pointer-events:all}

/* ── FOOTER (gold/black theme) ── */
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

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
  <div class="sb-brand">
    <div class="sb-logo">T</div>
    <div><div class="sb-title">TransNet X</div><div class="sb-sub">Food Delivery</div></div>
    <button class="sb-x" onclick="tSB()"><i class="fas fa-times"></i></button>
  </div>
  <div class="sb-user">
    <div class="sb-urow">
      <div class="sb-av"><?= strtoupper(substr($uname,0,1)) ?></div>
      <div style="flex:1;min-width:0"><div class="sb-uname"><?= $uname ?></div><div class="sb-email"><?= $uemail ?></div></div>
    </div>
    <div class="sb-sr">
      <div class="sb-sn"><div class="sb-snn"><?= $total_orders ?></div><div class="sb-snl">Orders</div></div>
      <div class="sb-sn"><div class="sb-snn"><?= $pending_orders ?></div><div class="sb-snl">Pending</div></div>
      <div class="sb-sn"><div class="sb-snn" style="font-size:13px">₦<?= number_format($spent_food/1000,0)?>k</div><div class="sb-snl">Spent</div></div>
    </div>
  </div>
  <nav class="sb-nav">
    <div class="sb-lbl">Food Delivery</div>
    <button class="sb-item active" onclick="gTo('vehicles')"><i class="fas fa-utensils sb-ico"></i><span>Browse Menu</span></button>
    <button class="sb-item" onclick="gTo('purchases')"><i class="fas fa-receipt sb-ico"></i><span>My Orders</span><?php if($pending_orders>0):?><span class="sb-badge"><?=$pending_orders?></span><?php endif;?></button>
    <a href="vehicle_sale.php" class="sb-item"><i class="fas fa-car-side sb-ico"></i><span>Vehicle Sales</span></a>
    <div class="sb-lbl">Other Services</div>
    <a href="dashboard.php" class="sb-item"><i class="fas fa-table-cells-large sb-ico"></i><span>Dashboard</span></a>
    <a href="Transnet/uber.php" class="sb-item"><i class="fas fa-car sb-ico"></i><span>Uber</span></a>
    <a href="Transnet/trip.php" class="sb-item"><i class="fas fa-bus sb-ico"></i><span>Coach Trip</span></a>
    <a href="Transnet/flight.php" class="sb-item"><i class="fas fa-plane sb-ico"></i><span>Flights</span></a>
    <a href="delivery.php" class="sb-item"><i class="fas fa-box sb-ico"></i><span>Delivery</span></a>
    <a href="emergency.php" class="sb-item"><i class="fas fa-triangle-exclamation sb-ico" style="color:var(--red)"></i><span>Emergency</span></a>
    <div class="sb-lbl">Account</div>
    <a href="profile.php" class="sb-item"><i class="fas fa-user sb-ico"></i><span>Profile</span></a>
    <a href="settings.php" class="sb-item"><i class="fas fa-gear sb-ico"></i><span>Settings</span></a>
  </nav>
  <div class="sb-foot"><a href="../auth/logout.php" class="sb-logout"><i class="fas fa-right-from-bracket"></i>Sign Out</a></div>
</aside>

<!-- TOPBAR -->
<header class="topbar">
  <div class="tb-l">
    <button class="mbtn" onclick="tSB()"><i class="fas fa-bars"></i></button>
    <div><div class="tb-title">Food Delivery</div></div>
  </div>
  <div class="tb-r">
    <div class="tb-search">
      <input type="text" id="topSearch" placeholder="Search food…" oninput="doFilter()">
      <button type="button" class="search-btn" onclick="doFilter()"><i class="fas fa-magnifying-glass"></i></button>
    </div>
    <a href="vehicle_sale.php" class="tb-btn" title="Vehicle Sales"><i class="fas fa-car-side"></i></a>
    <a href="dashboard.php" class="tb-btn" title="Dashboard"><i class="fas fa-table-cells-large"></i></a>
    <a href="emergency.php" class="tb-btn" style="color:var(--red)" title="SOS"><i class="fas fa-triangle-exclamation"></i></a>
  </div>
</header>

<!-- TOAST -->
<div class="toast" id="toast"><div class="t-ico" id="tIco"></div><div><div class="t-title" id="tTitle"></div><div class="t-msg" id="tMsg"></div></div><div class="t-bar"></div></div>

<!-- MODAL -->
<div class="ov" id="ov" onclick="if(event.target===this)cModal()">
  <div class="modal" id="mInner">
    <button class="mclose" onclick="cModal()"><i class="fas fa-times"></i></button>
    <div class="mhero" id="mHero"></div>
    <div class="mform">
      <div class="mftitle"><i class="fas fa-cart-shopping"></i>Place Your Order</div>
      <form method="POST" action="">
        <input type="hidden" name="place_order" value="1">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <input type="hidden" name="food_id" id="fVid">
        <input type="hidden" name="payment_method" id="fPm" value="cash">
        <input type="hidden" name="quantity" id="fQuantity" value="1">
        <div class="fg">
          <div><label class="flbl">Phone <span class="req">*</span></label><input class="fc" type="tel" name="phone" required placeholder="+234 800 000 0000" value="<?= $uphone ?>"></div>
          <div><label class="flbl">Delivery Preference</label>
            <select class="fc" name="delivery_preference"><option value="asap">As soon as possible</option><option value="30min">Within 30 minutes</option><option value="1hr">Within 1 hour</option><option value="schedule">Schedule for later</option></select></div>
          <div class="full"><label class="flbl">Delivery Address <span class="req">*</span></label><input class="fc" type="text" name="dropoff_address" required placeholder="Street, area, landmarks"></div>
          <div class="full"><label class="flbl">Quantity</label>
            <div class="plan-grid" id="quantitySelector">
              <div class="pc sel" onclick="sQty(1,this)"><i class="fas fa-1"></i><span>1 plate</span></div>
              <div class="pc" onclick="sQty(2,this)"><i class="fas fa-2"></i><span>2 plates</span></div>
              <div class="pc" onclick="sQty(3,this)"><i class="fas fa-3"></i><span>3 plates</span></div>
              <div class="pc" onclick="sQty(4,this)"><i class="fas fa-4"></i><span>4 plates</span></div>
            </div></div>
          <div class="full"><label class="flbl">Payment Method</label>
            <div class="pay-opts">
              <div class="po sel" onclick="sPay('cash',this)"><i class="fas fa-money-bill-wave"></i><span>Cash</span></div>
              <div class="po" onclick="sPay('card',this)"><i class="fas fa-credit-card"></i><span>Card</span></div>
              <div class="po" onclick="sPay('transfer',this)"><i class="fas fa-building-columns"></i><span>Transfer</span></div>
              <div class="po" onclick="sPay('wallet',this)"><i class="fas fa-wallet"></i><span>Wallet</span></div>
            </div></div>
          <div class="full"><label class="flbl">Special Instructions</label><textarea class="fc" name="note" placeholder="E.g., no pepper, extra sauce…"></textarea></div>
          <div class="full"><div class="pbox"><div><div class="pb-lbl">Total Price</div><div style="font-size:10px;color:var(--muted);margin-top:2px">incl. delivery fee (₦500)</div></div><div class="pb-amt" id="mPrice">₦0</div></div></div>
          <div class="full"><div class="btn-row"><button type="button" class="btn-c" onclick="cModal()">Cancel</button><button type="submit" class="btn-s">Place Order Now</button></div></div>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- LAYOUT -->
<div class="layout" id="layout">
<main class="main">

  <!-- HERO -->
  <div class="ph">
    <div class="ph-label">TransNet · X</div>
    <div class="ph-title">Delicious Meals<br><span>At Your Door</span></div>
    <div class="ph-sub">Freshly prepared dishes delivered hot. Browse our menu and order in seconds.</div>
    <div class="ph-chips">
      <div class="ph-chip"><i class="fas fa-utensils"></i><strong><?= count($foods) ?></strong> Items</div>
      <div class="ph-chip"><i class="fas fa-shield-check"></i>Quality <strong>Guaranteed</strong></div>
      <div class="ph-chip"><i class="fas fa-truck"></i>Fast <strong>Delivery</strong></div>
      <div class="ph-chip"><i class="fas fa-tag"></i>Daily <strong>Offers</strong></div>
    </div>
  </div>

  <!-- STATS -->
  <div class="stats">
    <div class="st" style="animation-delay:.1s">
      <div class="st-i" style="background:rgba(232,160,32,.12);color:var(--amber)"><i class="fas fa-utensils"></i></div>
      <div class="st-n" style="color:var(--amber)"><?= count($foods) ?></div>
      <div class="st-l">Menu Items</div>
    </div>
    <div class="st" style="animation-delay:.2s">
      <div class="st-i" style="background:rgba(34,197,94,.1);color:var(--green)"><i class="fas fa-check-circle"></i></div>
      <div class="st-n" style="color:var(--green)"><?= $total_orders ?></div>
      <div class="st-l">My Orders</div>
    </div>
    <div class="st" style="animation-delay:.3s">
      <div class="st-i" style="background:rgba(232,160,32,.1);color:var(--amber)"><i class="fas fa-clock"></i></div>
      <div class="st-n" style="color:var(--amber)"><?= $pending_orders ?></div>
      <div class="st-l">Pending</div>
    </div>
    <div class="st" style="animation-delay:.4s">
      <div class="st-i" style="background:rgba(239,68,68,.1);color:var(--red)"><i class="fas fa-wallet"></i></div>
      <div class="st-n" style="color:var(--red);font-size:18px">₦<?= number_format($spent_food/1000,1)?>k</div>
      <div class="st-l">Spent</div>
    </div>
  </div>

  <!-- FILTER BAR -->
  <div class="filter-bar" id="vehicles">
    <i class="fas fa-sliders" style="color:var(--amber);font-size:15px;flex-shrink:0"></i>
    <div class="filter-search">
      <input type="text" id="localSearch" placeholder="Search food…" oninput="doFilter()">
      <button type="button" class="search-btn" onclick="doFilter()"><i class="fas fa-search"></i></button>
    </div>
    <select id="fCat" onchange="doFilter()"><option value="">All Categories</option><?php foreach($categories as $c):?><option value="<?=htmlspecialchars($c)?>"><?=htmlspecialchars($c)?></option><?php endforeach;?></select>
    <div class="fcount" id="fCount"><?=count($foods)?> items</div>
  </div>

  <!-- FOOD GRID -->
  <div class="vg" id="vGrid">
    <?php foreach($foods as $i=>$f):
      $stars = min(5, max(1, round($f['rating'] ?? 4.5)));
      $foodJson = htmlspecialchars(json_encode($f, JSON_HEX_APOS|JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');
    ?>
    <div class="vc"
      data-name="<?=htmlspecialchars(strtolower($f['name']), ENT_QUOTES, 'UTF-8')?>"
      data-cat="<?=htmlspecialchars(strtolower($f['category']??''), ENT_QUOTES, 'UTF-8')?>"
      data-price="<?=htmlspecialchars($f['price'], ENT_QUOTES, 'UTF-8')?>"
      style="animation-delay:<?=($i%12)*.06?>s"
      onclick="oModal(<?=$foodJson?>)">
      <div class="vi">
        <?php if(!empty($f['image_url'])):?>
        <img src="../uploads/<?= htmlspecialchars(basename($f['image_url'])) ?>" loading="lazy">
        <?php else:?><div class="vni"><i class="fas fa-bowl-food"></i><span>No Image</span></div><?php endif;?>
        <div class="v-cat"><?=htmlspecialchars($f['category']??'Food')?></div>
        <div class="v-cond"><?=htmlspecialchars($f['prep_time']??'25-35')?> mins</div>
      </div>
      <div class="vbody">
        <div class="vmake"><?=htmlspecialchars($f['category'])?></div>
        <div class="vmodel"><?=htmlspecialchars($f['name'])?> <span class="vyear"><?= htmlspecialchars($f['calories'] ?? '') ?></span></div>
        <div class="vrating"><?php for($s=1;$s<=5;$s++):?><i class="fas fa-star" style="<?=$s<=$stars?'color:var(--amber)':''?>"></i><?php endfor;?><span style="margin-left:4px"><?=number_format($f['rating']??4.5,1)?></span></div>
        <div class="vfooter">
          <div class="vprice">₦<?=number_format($f['price'])?><small>/plate</small></div>
          <button class="vbtn" onclick='event.stopPropagation();oModal(<?=$foodJson?>)'>Order Now</button>
        </div>
      </div>
    </div>
    <?php endforeach;?>
    <?php if(count($foods)===0):?>
    <div class="empty" style="grid-column:1/-1"><i class="fas fa-utensils"></i><p>No food items available right now.</p></div>
    <?php endif;?>
  </div>
  <div id="noResults" class="empty" style="display:none"><i class="fas fa-search"></i><p>No items found</p></div>

  <!-- ORDER HISTORY -->
  <div id="purchases">
    <div class="sec-hd"><div class="sec-title"><i class="fas fa-receipt"></i>My Orders</div></div>
    <div class="pur-card">
      <div class="pur-hd"><div class="pur-hd-t"><i class="fas fa-clock-rotate-left"></i>All Orders</div><span style="font-size:12px;color:var(--muted);font-weight:600"><?=$total_orders?> total</span></div>
      <?php if(count($all_orders)>0): foreach($all_orders as $o):?>
      <div class="pr">
        <div class="pr-thumb"><?php if(!empty($o['image_url'])):?><img src="../uploads/<?=htmlspecialchars(basename($o['image_url']))?>" alt=""><?php else:?><i class="fas fa-utensils"></i><?php endif;?></div>
        <div class="pr-info">
          <div class="pr-vn"><?=htmlspecialchars($o['food_name'])?> ×<?=$o['quantity']?></div>
          <div class="pr-meta">
            <span><i class="fas fa-credit-card"></i><?=ucfirst($o['payment_method']??'—')?></span>
            <?php if(!empty($o['dropoff_address'])):?><span><i class="fas fa-location-dot"></i><?=htmlspecialchars(substr($o['dropoff_address'],0,30))?></span><?php endif;?>
          </div>
          <?php $pico=['pending'=>'fa-clock','processing'=>'fa-spinner','delivered'=>'fa-circle-check','cancelled'=>'fa-ban'];?>
          <span class="pill pl-<?=str_replace(['processing','delivered'],['approved','completed'],$o['status'])?>"><i class="fas <?=$pico[$o['status']]??'fa-circle'?>"></i><?=ucfirst($o['status'])?></span>
        </div>
        <div class="pr-right"><div class="pr-amt">₦<?=number_format($o['total_price'])?></div><div class="pr-date"><?=date('M d, Y',strtotime($o['created_at']))?></div></div>
      </div>
      <?php endforeach; else:?>
      <div class="empty"><i class="fas fa-utensils"></i><p>No orders yet</p><small>Browse the menu and place your first order.</small></div>
      <?php endif;?>
    </div>
  </div>

</main>
</div>

<!-- FOOTER -->
<footer class="app-footer">
  <div class="footer-container">
    <div class="footer-grid">
      <div class="footer-brand">
        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
          <div style="width: 32px; height: 32px; border-radius: 8px; background: linear-gradient(135deg, var(--amber-d), var(--amber)); display: flex; align-items: center; justify-content: center;">
            <i class="fas fa-compass" style="color: #000; font-size: 1rem;"></i>
          </div>
          <span style="font-weight: 800; font-size: 1.2rem; background: linear-gradient(135deg, var(--amber-l), var(--amber)); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">TransNet X</span>
        </div>
        <p>Your one-stop travel & lifestyle platform. Book rides, flights, trips, rentals, and more with ease.</p>
      </div>
      <div>
        <h4 class="footer-title">Quick Links</h4>
        <ul class="footer-links">
          <li><a href="about.php">About Us</a></li>
          <li><a href="profile.php">Contact Us</a></li>
          <li><a href="#">Privacy Policy</a></li>
          <li><a href="#">Terms of Service</a></li>
        </ul>
      </div>
      <div>
        <h4 class="footer-title">Support</h4>
        <ul class="footer-links">
          <li><a href="#">FAQ</a></li>
          <li><a href="#">Help Center</a></li>
          <li><a href="#">Refund Policy</a></li>
        </ul>
      </div>
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
// ── Sidebar Toggle ──────────────────
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

// ── Scroll to section ────────────────────────
function gTo(id){document.getElementById(id).scrollIntoView({behavior:'smooth'})}

// ── Food Filter Logic ─────────────────────
function doFilter() {
  const qTop = document.getElementById('topSearch').value.toLowerCase().trim();
  const qLocal = document.getElementById('localSearch').value.toLowerCase().trim();
  const search = (qTop + ' ' + qLocal).trim();
  const fCat = document.getElementById('fCat').value.toLowerCase();

  const grid = document.getElementById('vGrid');
  const cards = Array.from(document.querySelectorAll('.vc'));
  let visibleCount = 0;

  cards.forEach(card => {
    const name = card.dataset.name || '';
    const cat = card.dataset.cat || '';
    const matchSearch = !search || name.includes(search);
    const matchCat = !fCat || cat === fCat;

    if (matchSearch && matchCat) {
      card.style.display = '';
      visibleCount++;
    } else {
      card.style.display = 'none';
    }
  });

  document.getElementById('fCount').textContent = visibleCount + ' items';
  document.getElementById('noResults').style.display = visibleCount === 0 ? 'block' : 'none';
}
document.getElementById('topSearch').addEventListener('keypress', function(e){if(e.key==='Enter')doFilter()});
document.getElementById('localSearch').addEventListener('keypress', function(e){if(e.key==='Enter')doFilter()});

// ── Modal Functions (Food) ────────────────
let currentFoodPrice = 0;
function oModal(food){
  document.getElementById('fVid').value = food.id;
  sQty(1, document.querySelector('#quantitySelector .pc'));
  sPay('cash', document.querySelector('.pay-opts .po'));

  const hero = document.getElementById('mHero');
  const stars = '<i data-lucide="star" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i>'.repeat(Math.round(food.rating||4));
  const specItems = [];
  if(food.prep_time) specItems.push(`<div class="mh-spec"><i class="fas fa-clock"></i>${escHtml(food.prep_time)} mins</div>`);
  if(food.calories) specItems.push(`<div class="mh-spec"><i class="fas fa-fire"></i>${escHtml(food.calories)} kcal</div>`);

  hero.innerHTML = `
    <div class="mh-img">
      ${food.image_url ? `<img src="../uploads/${escHtml(food.image_url)}" alt="">` : `<div class="mh-noimg"><i class="fas fa-bowl-food"></i><span>No Image</span></div>`}
    </div>
    <div class="mh-info">
      <div class="mh-cat">${escHtml(food.category||'Food')}</div>
      <div class="mh-name">${escHtml(food.name)}</div>
      <div class="mh-specs">${specItems.join('')}</div>
      <div style="display:flex;align-items:center;gap:6px;margin-top:4px"><span style="color:var(--amber);font-size:14px">${stars}</span><span style="font-size:12px;color:var(--muted)">${Number(food.rating||4.5).toFixed(1)}</span></div>
      <div class="mh-price">₦${Number(food.price).toLocaleString('en-NG')} <small>/plate</small></div>
      ${food.description ? `<div class="mh-desc">${escHtml(food.description)}</div>` : ''}
    </div>
  `;

  currentFoodPrice = parseFloat(food.price);
  updateTotal();
  document.getElementById('ov').classList.add('open');
  document.body.style.overflow = 'hidden';
}

function cModal(){
  document.getElementById('ov').classList.remove('open');
  document.body.style.overflow = '';
}

// ── Quantity Selector ─────────────────────
function sQty(qty, el){
  document.querySelectorAll('#quantitySelector .pc').forEach(p=>p.classList.remove('sel'));
  el.classList.add('sel');
  document.getElementById('fQuantity').value = qty;
  updateTotal();
}

function updateTotal(){
  const qty = parseInt(document.getElementById('fQuantity').value) || 1;
  const total = (currentFoodPrice * qty) + 500;
  document.getElementById('mPrice').textContent = '₦' + total.toLocaleString('en-NG');
}

// ── Payment Method Selector ──────────────────
function sPay(method,el){
  document.querySelectorAll('.pay-opts .po').forEach(p=>p.classList.remove('sel'));
  el.classList.add('sel');
  document.getElementById('fPm').value = method;
}

// ── Utilities ─────────────────────────────────
function escHtml(str){return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

// ── Toast Notification ───────────────────────
function showToast(type,title,msg){
  const t = document.getElementById('toast');
  document.getElementById('tIco').innerHTML = type==='success'?'<i class="fas fa-circle-check"></i>':'<i class="fas fa-circle-xmark"></i>';
  document.getElementById('tTitle').textContent = title;
  document.getElementById('tMsg').textContent = msg;
  t.className = `toast ${type} show`;
  setTimeout(()=>t.classList.remove('show'),4000);
}

<?php if($msg_s): ?>showToast('success','Order Placed!',<?=json_encode($msg_s)?>);<?php endif;?>
<?php if($msg_e): ?>showToast('error','Order Failed',<?=json_encode($msg_e)?>);<?php endif;?>

document.querySelectorAll('.vc').forEach((c,i)=>c.style.animationDelay=(i%12)*.06+'s');
</script>

<script src="../assets/offline-icons.js"></script>
</body>
</html>