<?php
session_start();
require_once '../config/db.php';

// Auth
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];

// Fetch user
$stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $userId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$user) {
    session_destroy();
    header('Location: ../index.php');
    exit;
}

// Count helper
function countRows($conn, $sql, $userId) {
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return 0;
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $count);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);
    return $count ?? 0;
}

$totalBookings   = countRows($conn, "SELECT COUNT(*) FROM bookings WHERE user_id = ?", $userId);
$totalFlights    = countRows($conn, "SELECT COUNT(*) FROM flight_bookings WHERE user_id = ?", $userId); // assumes flight_bookings table exists
$totalOrders     = countRows($conn, "SELECT COUNT(*) FROM orders WHERE user_id = ?", $userId);          // assumes orders table exists
$totalDeliveries = countRows($conn, "SELECT COUNT(*) FROM delivery_requests WHERE user_id = ?", $userId); // assumes delivery_orders

// Recent bookings (fixed columns)
$recentBookings = [];
$stmt = mysqli_prepare($conn, "
    SELECT b.*, CONCAT(d.name, ' ', d.surname) AS driver_name
    FROM bookings b
    LEFT JOIN drivers d ON d.driver_id = b.driver_id
    WHERE b.user_id = ?
    ORDER BY b.id DESC
    LIMIT 5
");
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $recentBookings[] = $row;
    }
    mysqli_stmt_close($stmt);
}

// Handle profile update
$msg = '';
$msgType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    $name    = trim($_POST['name'] ?? '');
    $surname = trim($_POST['surname'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $state   = trim($_POST['state'] ?? '');
    $country = trim($_POST['country'] ?? '');

    $stmt = mysqli_prepare($conn, "
        UPDATE users
        SET name = ?, surname = ?, phone = ?, state = ?, country = ?
        WHERE id = ?
    ");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "sssssi", $name, $surname, $phone, $state, $country, $userId);
        if (mysqli_stmt_execute($stmt)) {
            $msg = "Profile updated successfully!";
            $msgType = "success";
            // Refresh user data
            $user['name'] = $name;
            $user['surname'] = $surname;
            $user['phone'] = $phone;
            $user['state'] = $state;
            $user['country'] = $country;
        } else {
            $msg = "Failed to update profile.";
            $msgType = "error";
        }
        mysqli_stmt_close($stmt);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Profile — TransNetX</title>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ========== GLOBAL THEME (matching dashboard) ========== */
:root{
  --gold:#f5c518;
  --gold-dim:rgba(245,197,24,0.12);
  --accent:#00e5a0;
  --accent2:#6c5ce7;
  --bg:#0f1219;
  --bg2:#161b26;
  --bg3:#1a2035;
  --card:#161b26;
  --border:rgba(255,255,255,0.08);
  --text:#e8eaf0;
  --text2:#8892a4;
  --text3:#5c6478;
  --green:#2ecc71;
  --red:#ff4757;
  --blue:#4a90d9;
  --radius:18px;
  --transition:all 0.3s ease;
}

*{margin:0;padding:0;box-sizing:border-box}
body{
  font-family:'DM Sans',sans-serif;
  background:var(--bg);
  color:var(--text);
  min-height:100vh;
  overflow-x:hidden;
  position:relative;
}

/* ========== TOP NAVIGATION ========== */
.top-nav{
  position:sticky;
  top:0;
  z-index:100;
  background:var(--bg);
  border-bottom:1px solid rgba(255,255,255,0.08);
  padding:0 32px;
  height:68px;
  display:flex;
  align-items:center;
  justify-content:space-between;
}
.nav-brand{
  font-family:'Bebas Neue',sans-serif;
  font-size:22px;
  letter-spacing:2px;
  background:linear-gradient(135deg,var(--gold),#fff);
  -webkit-background-clip:text;
  -webkit-text-fill-color:transparent;
}
.sidebar-toggle{display:none;background:none;border:1px solid var(--border);border-radius:10px;color:var(--gold);font-size:18px;padding:8px 12px;cursor:pointer;transition:var(--transition)}
.sidebar-toggle:hover{background:var(--gold-dim)}

/* ========== PAGE SIDEBAR ========== */
.page-sidebar{position:fixed;top:68px;left:0;bottom:0;width:260px;background:var(--card);border-right:1px solid var(--border);z-index:90;overflow-y:auto;transform:translateX(0);transition:transform 0.35s cubic-bezier(0.4,0,0.2,1);display:flex;flex-direction:column}
.page-sidebar .sb-header{padding:22px 22px 10px;font-family:'Syne',sans-serif;font-size:11px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:1.5px}
.page-sidebar .sb-link{display:flex;align-items:center;gap:14px;padding:12px 22px;color:var(--text2);font-size:14px;text-decoration:none;transition:var(--transition);border-left:3px solid transparent;position:relative}
.page-sidebar .sb-link:hover{color:var(--gold);background:var(--gold-dim);border-left-color:var(--gold)}
.page-sidebar .sb-link.active{color:var(--gold);background:var(--gold-dim);border-left-color:var(--gold)}
.page-sidebar .sb-link i{width:20px;text-align:center;font-size:15px;opacity:0.85}
.page-sidebar .sb-link span{font-weight:500}
.page-sidebar .sb-divider{height:1px;background:var(--border);margin:12px 22px}
.page-sidebar .sb-link.danger{color:var(--red)}
.page-sidebar .sb-link.danger:hover{background:rgba(255,71,87,0.08);border-left-color:var(--red)}
.sidebar-backdrop{display:none;position:fixed;inset:0;top:68px;background:rgba(0,0,0,0.5);z-index:89;backdrop-filter:blur(3px)}

.page-wrapper{margin-left:260px;transition:margin-left 0.35s cubic-bezier(0.4,0,0.2,1)}

/* Sidebar user badge */
.sb-user{display:flex;align-items:center;gap:12px;padding:20px 22px;border-bottom:1px solid var(--border)}
.sb-avatar{width:40px;height:40px;border-radius:12px;background:linear-gradient(135deg,var(--accent),var(--accent2));display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:800;color:#fff;flex-shrink:0}
.sb-user-info{min-width:0}
.sb-user-name{font-size:14px;font-weight:700;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sb-user-email{font-size:11px;color:var(--text3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

/* ========== CONTAINER ========== */
.container{
  max-width:1100px;
  margin:0 auto;
  padding:40px 24px;
  position:relative;
  z-index:1;
}

/* ========== PROFILE HERO ========== */
.profile-hero{
  background:var(--bg2);
  border:1px solid rgba(255,255,255,0.06);
  border-radius:var(--radius);
  padding:32px;
  margin-bottom:24px;
  position:relative;
  overflow:hidden;
}
.hero-layout{
  display:flex;
  gap:28px;
  flex-wrap:wrap;
  margin-bottom:24px;
}
.avatar-wrap{
  flex-shrink:0;
}
.avatar{
  width:100px;
  height:100px;
  border-radius:50%;
  background:linear-gradient(135deg,var(--accent),var(--accent2));
  display:flex;
  align-items:center;
  justify-content:center;
  font-size:36px;
  font-weight:700;
  color:#fff;
  position:relative;
  box-shadow:0 0 25px rgba(0,229,160,0.3);
}
.online-dot{
  position:absolute;
  bottom:5px;
  right:5px;
  width:14px;
  height:14px;
  background:var(--green);
  border-radius:50%;
  border:2px solid var(--bg2);
  animation:pulse 2s infinite;
}
@keyframes pulse{
  0%,100%{opacity:1;transform:scale(1)}
  50%{opacity:0.6;transform:scale(1.1)}
}
.profile-info{
  flex:1;
}
.profile-name{
  font-size:26px;
  font-weight:700;
  margin-bottom:5px;
}
.profile-email{
  font-size:14px;
  color:var(--text2);
  margin-bottom:12px;
}
.profile-tags{
  display:flex;
  gap:10px;
  flex-wrap:wrap;
  margin-bottom:12px;
}
.tag{
  display:inline-flex;
  align-items:center;
  gap:5px;
  padding:4px 12px;
  border-radius:20px;
  font-size:12px;
  font-weight:600;
}
.tag-gold{
  background:rgba(245,197,24,0.15);
  color:var(--gold);
  border:1px solid rgba(245,197,24,0.3);
}
.tag-green{
  background:rgba(46,204,113,0.12);
  color:var(--green);
  border:1px solid rgba(46,204,113,0.2);
}
.tag-blue{
  background:rgba(74,144,217,0.12);
  color:var(--blue);
  border:1px solid rgba(74,144,217,0.2);
}
.profile-bio{
  font-size:13px;
  color:var(--text3);
  border-top:1px solid var(--border);
  padding-top:12px;
  margin-top:8px;
}
.profile-stats-row{
  display:grid;
  grid-template-columns:repeat(4,1fr);
  gap:12px;
  margin-top:20px;
  padding-top:20px;
  border-top:1px solid var(--border);
}
.pstat{
  text-align:center;
}
.pstat-val{
  font-size:28px;
  font-weight:800;
  font-family:'Syne',sans-serif;
  background:linear-gradient(135deg,var(--gold),#fff);
  -webkit-background-clip:text;
  -webkit-text-fill-color:transparent;
}
.pstat-lbl{
  font-size:11px;
  color:var(--text3);
  text-transform:uppercase;
  letter-spacing:1px;
}

/* ========== CARD ========== */
.card{
  background:var(--card);
  border:1px solid rgba(255,255,255,0.06);
  border-radius:var(--radius);
  overflow:hidden;
  transition:var(--transition);
  margin-bottom:20px;
}
.card:hover{
  border-color:rgba(0,229,160,0.3);
  box-shadow:0 10px 40px rgba(0,229,160,0.1);
}
.card-header{
  padding:18px 24px;
  border-bottom:1px solid var(--border);
  display:flex;
  justify-content:space-between;
  align-items:center;
}
.card-title{
  font-size:16px;
  font-weight:600;
  color:var(--text);
  display:flex;
  align-items:center;
  gap:8px;
}
.card-body{
  padding:24px;
}

/* ========== FORMS ========== */
.form-grid{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:14px;
}
.form-grid .full{
  grid-column:span 2;
}
.form-group{
  display:flex;
  flex-direction:column;
  gap:6px;
  margin-bottom:6px;
}
.form-group label{
  font-size:11px;
  color:var(--text3);
  text-transform:uppercase;
  letter-spacing:1px;
}
.form-group input, .form-group textarea{
  background:rgba(255,255,255,0.05);
  border:1px solid var(--border);
  border-radius:10px;
  padding:11px 14px;
  color:var(--text);
  font-size:14px;
  outline:none;
  transition:var(--transition);
}
.form-group input:focus, .form-group textarea:focus{
  border-color:var(--gold);
  box-shadow:0 0 0 2px rgba(245,197,24,0.2);
}
textarea{
  resize:vertical;
  min-height:80px;
}

/* ========== BUTTONS ========== */
.btn{
  display:inline-flex;
  align-items:center;
  gap:8px;
  padding:10px 20px;
  border-radius:10px;
  font-size:14px;
  font-weight:600;
  cursor:pointer;
  transition:var(--transition);
  border:none;
}
.btn-gold{
  background:linear-gradient(135deg,var(--gold),#c8960c);
  color:#000;
  box-shadow:0 4px 15px rgba(245,197,24,0.3);
}
.btn-gold:hover{
  transform:translateY(-2px);
  box-shadow:0 8px 30px rgba(245,197,24,0.45);
}

/* ========== BADGES ========== */
.badge{
  display:inline-block;
  padding:4px 12px;
  border-radius:12px;
  font-size:11px;
  font-weight:600;
  text-transform:uppercase;
}
.badge.bs{ background:rgba(46,204,113,0.12); color:var(--green); border:1px solid rgba(46,204,113,0.2); }
.badge.bi{ background:rgba(0,229,160,0.12); color:var(--accent); border:1px solid rgba(0,229,160,0.2); }
.badge.bw{ background:rgba(245,197,24,0.12); color:var(--gold); border:1px solid rgba(245,197,24,0.2); }
.badge.bd{ background:rgba(255,71,87,0.12); color:var(--red); border:1px solid rgba(255,71,87,0.2); }

/* ========== TABLE ========== */
table{
  width:100%;
  border-collapse:collapse;
}
th{
  text-align:left;
  padding:12px 8px;
  font-size:11px;
  color:var(--text3);
  border-bottom:1px solid var(--border);
}
td{
  padding:12px 8px;
  font-size:13px;
  border-bottom:1px solid rgba(255,255,255,0.04);
}
tr:last-child td{
  border-bottom:none;
}

/* ========== TIMELINE ========== */
.timeline{
  display:flex;
  flex-direction:column;
  gap:16px;
}
.t-item{
  display:flex;
  gap:14px;
}
.t-dot{
  width:36px;
  height:36px;
  border-radius:50%;
  display:flex;
  align-items:center;
  justify-content:center;
  font-size:14px;
  flex-shrink:0;
}
.t-dot.gold{ background:rgba(245,197,24,0.15); color:var(--gold); }
.t-dot.green{ background:rgba(46,204,113,0.12); color:var(--green); }
.t-dot.blue{ background:rgba(74,144,217,0.12); color:var(--blue); }
.t-body strong{
  display:block;
  font-size:14px;
  margin-bottom:2px;
}
.t-body p{
  font-size:12px;
  color:var(--text3);
}

/* ========== TOAST ========== */
.toast{
  position:fixed;
  top:80px;
  right:24px;
  z-index:999;
  padding:14px 20px;
  border-radius:12px;
  background:rgba(46,204,113,0.15);
  backdrop-filter:blur(20px);
  border:1px solid rgba(46,204,113,0.4);
  color:var(--green);
  display:flex;
  align-items:center;
  gap:10px;
  font-size:14px;
  font-weight:500;
  animation:slideIn 0.4s cubic-bezier(0.34,1.56,0.64,1);
  transition:opacity 0.3s;
}
@keyframes slideIn{
  from{transform:translateX(120%);opacity:0}
  to{transform:translateX(0);opacity:1}
}

/* ========== RESPONSIVE ========== */
.grid-2{display:grid;grid-template-columns:1fr 380px;gap:24px;}

@media(max-width:1024px){
  .grid-2{grid-template-columns:1fr;}
}
@media(max-width:900px){
  .sidebar-toggle{display:block}
  .page-sidebar{transform:translateX(-100%)}
  .page-sidebar.open{transform:translateX(0)}
  .sidebar-backdrop.open{display:block}
  .page-wrapper{margin-left:0}
}
@media(max-width:768px){
  .form-grid{grid-template-columns:1fr;}
  .form-grid .full{grid-column:span 1;}
  .hero-layout{flex-direction:column;align-items:center;text-align:center;}
  .profile-stats-row{grid-template-columns:repeat(2,1fr);}
  .top-nav{padding:0 16px;}
}
</style>
</head>
<body>

<?php if ($msg): ?>
<div class="toast">
  <i class="fas fa-<?= $msgType==='success'?'check-circle':'exclamation-circle' ?>"></i>
  <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<nav class="top-nav">
  <div class="nav-brand">TransNetX</div>
  <button class="sidebar-toggle" id="sidebarToggle" onclick="toggleSidebar()">
    <i class="fas fa-bars"></i>
  </button>
</nav>

<!-- Page Sidebar -->
<aside class="page-sidebar" id="pageSidebar">
  <div class="sb-user">
    <div class="sb-avatar"><?= strtoupper(substr($user['name'] ?? 'U', 0, 1)) ?></div>
    <div class="sb-user-info">
      <div class="sb-user-name"><?= htmlspecialchars(trim(($user['name'] ?? '') . ' ' . ($user['surname'] ?? ''))) ?: 'User' ?></div>
      <div class="sb-user-email"><?= htmlspecialchars($user['email'] ?? '') ?></div>
    </div>
  </div>

  <div class="sb-header">Navigation</div>
  <a href="dashboard.php" class="sb-link"><i class="fas fa-gauge-high"></i><span>Dashboard</span></a>
  <a href="profile.php" class="sb-link active"><i class="fas fa-user"></i><span>Profile</span></a>
  <a href="settings.php" class="sb-link"><i class="fas fa-cog"></i><span>Settings</span></a>

  <div class="sb-divider"></div>
  <div class="sb-header">Support</div>
  <a href="help.php" class="sb-link"><i class="fas fa-circle-question"></i><span>Help</span></a>
  <a href="faq.php" class="sb-link"><i class="fas fa-question"></i><span>FAQ</span></a>
  <a href="contact.php" class="sb-link"><i class="fas fa-envelope"></i><span>Contact</span></a>

  <div class="sb-divider"></div>
  <div class="sb-header">Legal</div>
  <a href="privacy.php" class="sb-link"><i class="fas fa-user-shield"></i><span>Privacy</span></a>
  <a href="terms.php" class="sb-link"><i class="fas fa-file-contract"></i><span>Terms</span></a>
  <a href="refund.php" class="sb-link"><i class="fas fa-money-bill"></i><span>Refund</span></a>

  <div class="sb-divider"></div>
  <a href="emergency.php" class="sb-link danger"><i class="fas fa-triangle-exclamation"></i><span>Emergency</span></a>
  <a href="../auth/logout.php" class="sb-link danger"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
</aside>
<div class="sidebar-backdrop" id="sidebarBackdrop" onclick="toggleSidebar()"></div>

<div class="page-wrapper">
<div class="container">

  <!-- PROFILE HERO -->
  <div class="profile-hero">
    <div class="hero-layout">
      <div class="avatar-wrap">
        <div class="avatar">
          <?= strtoupper(substr($user['name'] ?? 'U', 0, 1)) ?>
          <span class="online-dot"></span>
        </div>
      </div>
      <div class="profile-info">
        <div class="profile-name"><?= htmlspecialchars($user['name'] . ' ' . ($user['surname'] ?? '')) ?></div>
        <div class="profile-email"><i class="fas fa-envelope"></i> <?= htmlspecialchars($user['email'] ?? '-') ?></div>
        <div class="profile-tags">
          <span class="tag tag-gold"><i class="fas fa-star"></i> Premium Member</span>
          <span class="tag tag-green"><i class="fas fa-circle"></i> Active</span>
          <?php if(!empty($user['country'])): ?>
          <span class="tag tag-blue"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($user['country']) ?></span>
          <?php endif; ?>
        </div>
        <div class="profile-bio">
          <?= htmlspecialchars($user['bio'] ?? 'Hello! I love traveling with TransNetX.') ?>
        </div>
      </div>
    </div>
    <div class="profile-stats-row">
      <div class="pstat"><div class="pstat-val"><?= $totalBookings ?></div><div class="pstat-lbl">Rides</div></div>
      <div class="pstat"><div class="pstat-val"><?= $totalFlights ?></div><div class="pstat-lbl">Flights</div></div>
      <div class="pstat"><div class="pstat-val"><?= $totalOrders ?></div><div class="pstat-lbl">Orders</div></div>
      <div class="pstat"><div class="pstat-val"><?= $totalDeliveries ?></div><div class="pstat-lbl">Deliveries</div></div>
    </div>
  </div>

  <div class="grid-2">
    <!-- LEFT COLUMN: EDIT PROFILE + PERSONAL INFO -->
    <div>
      <!-- EDIT FORM -->
      <div class="card">
        <div class="card-header">
          <div class="card-title"><i class="fas fa-edit"></i> Edit Profile</div>
        </div>
        <div class="card-body">
          <form method="POST">
            <input type="hidden" name="action" value="update_profile">
            <div class="form-grid">
              <div class="form-group">
                <label>First Name</label>
                <input type="text" name="name" value="<?= htmlspecialchars($user['name'] ?? '') ?>" required>
              </div>
              <div class="form-group">
                <label>Surname</label>
                <input type="text" name="surname" value="<?= htmlspecialchars($user['surname'] ?? '') ?>">
              </div>
              <div class="form-group">
                <label>Phone</label>
                <input type="tel" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
              </div>
              <div class="form-group">
                <label>Country</label>
                <input type="text" name="country" value="<?= htmlspecialchars($user['country'] ?? '') ?>">
              </div>
              <div class="form-group">
                <label>State</label>
                <input type="text" name="state" value="<?= htmlspecialchars($user['state'] ?? '') ?>">
              </div>
              <div class="form-group full">
                <label>Email (read‑only)</label>
                <input type="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" disabled>
              </div>
            </div>
            <div style="margin-top:20px;">
              <button type="submit" class="btn btn-gold"><i class="fas fa-save"></i> Save Changes</button>
            </div>
          </form>
        </div>
      </div>

      <!-- PERSONAL INFO PANEL -->
      <div class="card">
        <div class="card-header">
          <div class="card-title"><i class="fas fa-id-card"></i> Personal Info</div>
        </div>
        <div class="card-body">
          <div class="info-row">
            <div class="info-icon"><i class="fas fa-fingerprint"></i></div>
            <div><span class="info-label">NIN</span><div class="info-value"><?= htmlspecialchars($user['nin'] ?? '—') ?></div></div>
          </div>
          <div class="info-row">
            <div class="info-icon"><i class="fas fa-clock"></i></div>
            <div><span class="info-label">Member Since</span><div class="info-value"><?= isset($user['created_at']) ? date('F j, Y', strtotime($user['created_at'])) : '—' ?></div></div>
          </div>
        </div>
      </div>
    </div>

    <!-- RIGHT COLUMN: RECENT BOOKINGS + TIMELINE -->
    <div>
      <!-- RECENT BOOKINGS -->
      <div class="card">
        <div class="card-header">
          <div class="card-title"><i class="fas fa-clock"></i> Recent Bookings</div>
        </div>
        <div class="card-body" style="padding: 0;">
          <?php if (empty($recentBookings)): ?>
            <div style="text-align:center; padding:40px; color:var(--text3);">
              <i class="fas fa-car" style="font-size:40px; margin-bottom:10px; opacity:0.4;"></i>
              <p>No bookings yet.<br>Book your first ride!</p>
            </div>
          <?php else: ?>
            <div style="overflow-x:auto;">
              <table>
                <thead>
                  <tr><th>#</th><th>Driver</th><th>Pickup → Dropoff</th><th>Status</th></tr>
                </thead>
                <tbody>
                  <?php foreach ($recentBookings as $b): ?>
                    <tr>
                      <td><strong>#<?= $b['id'] ?></strong></td>
                      <td><?= htmlspecialchars($b['driver_name'] ?? 'Pending') ?></td>
                      <td>
                        <?= htmlspecialchars($b['pickup_location'] ?? '—') ?>
                        <i class="fas fa-arrow-right" style="font-size:10px; margin:0 4px;"></i>
                        <?= htmlspecialchars($b['dropoff_location'] ?? '—') ?>
                      </td>
                      <td>
                        <?php
                        $status = strtolower($b['status'] ?? 'pending');
                        $badgeClass = match($status) {
                          'completed' => 'bs',
                          'accepted'  => 'bi',
                          'pending'   => 'bw',
                          default     => 'bd'
                        };
                        ?>
                        <span class="badge <?= $badgeClass ?>"><?= ucfirst($status) ?></span>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- TIMELINE -->
      <div class="card">
        <div class="card-header">
          <div class="card-title"><i class="fas fa-history"></i> Activity Timeline</div>
        </div>
        <div class="card-body">
          <div class="timeline">
            <div class="t-item">
              <div class="t-dot gold"><i class="fas fa-user-plus"></i></div>
              <div class="t-body">
                <strong>Account Created</strong>
                <p><?= isset($user['created_at']) ? date('M j, Y', strtotime($user['created_at'])) : 'N/A' ?></p>
              </div>
            </div>
            <?php if ($totalBookings > 0): ?>
            <div class="t-item">
              <div class="t-dot green"><i class="fas fa-car"></i></div>
              <div class="t-body">
                <strong>First Ride Booked</strong>
                <p>You have completed <?= $totalBookings ?> ride(s)</p>
              </div>
            </div>
            <?php endif; ?>
            <?php if ($totalFlights > 0): ?>
            <div class="t-item">
              <div class="t-dot blue"><i class="fas fa-plane"></i></div>
              <div class="t-body">
                <strong>Air Traveler</strong>
                <p><?= $totalFlights ?> flight booking(s)</p>
              </div>
            </div>
            <?php endif; ?>
            <?php if ($totalOrders > 0): ?>
            <div class="t-item">
              <div class="t-dot gold"><i class="fas fa-utensils"></i></div>
              <div class="t-body">
                <strong>Food Lover</strong>
                <p><?= $totalOrders ?> order(s) placed</p>
              </div>
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
</div><!-- end page-wrapper -->

<script>
// Sidebar toggle
function toggleSidebar() {
  document.getElementById('pageSidebar').classList.toggle('open');
  document.getElementById('sidebarBackdrop').classList.toggle('open');
}

// Auto-hide toast after 4 seconds
setTimeout(() => {
  const toast = document.querySelector('.toast');
  if (toast) {
    toast.style.opacity = '0';
    setTimeout(() => toast.remove(), 400);
  }
}, 4000);
</script>
</body>
</html>