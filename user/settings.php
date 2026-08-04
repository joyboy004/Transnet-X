<?php
session_start();
require_once '../config/db.php';

// Auth check
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];

// Fetch user data
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

$msg = '';
$msgType = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --------------------------------------------
    // UPDATE PROFILE (name, surname, phone, country, state)
    // --------------------------------------------
    if ($action === 'update_profile') {
        $name    = trim($_POST['name'] ?? '');
        $surname = trim($_POST['surname'] ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $country = trim($_POST['country'] ?? '');
        $state   = trim($_POST['state'] ?? '');

        $errors = [];
        if (strlen($name) < 2) $errors[] = "First name must be at least 2 characters.";
        if (strlen($surname) < 2) $errors[] = "Surname must be at least 2 characters.";
        if (!preg_match('/^[\+\d\s\-\(\)]{7,20}$/', $phone)) $errors[] = "Invalid phone number format.";
        if (empty($country)) $errors[] = "Country is required.";
        if (empty($state)) $errors[] = "State/Province is required.";

        if (!empty($errors)) {
            $msg = implode("<br>", $errors);
            $msgType = "error";
        } else {
            $stmt = mysqli_prepare($conn, "
                UPDATE users 
                SET name = ?, surname = ?, phone = ?, country = ?, state = ?
                WHERE id = ?
            ");
            mysqli_stmt_bind_param($stmt, "sssssi", $name, $surname, $phone, $country, $state, $userId);
            if (mysqli_stmt_execute($stmt)) {
                $msg = "Profile updated successfully!";
                $msgType = "success";
                // Refresh displayed data
                $user['name'] = $name;
                $user['surname'] = $surname;
                $user['phone'] = $phone;
                $user['country'] = $country;
                $user['state'] = $state;
            } else {
                $msg = "Failed to update profile.";
                $msgType = "error";
            }
            mysqli_stmt_close($stmt);
        }
    }

    // --------------------------------------------
    // CHANGE PASSWORD
    // --------------------------------------------
    elseif ($action === 'change_password') {
        $current = $_POST['password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if ($new !== $confirm) {
            $msg = "Passwords do not match.";
            $msgType = "error";
        } elseif (strlen($new) < 8) {
            $msg = "Password must be at least 8 characters.";
            $msgType = "error";
        } elseif (!password_verify($current, $user['password'])) {
            $msg = "Current password is incorrect.";
            $msgType = "error";
        } else {
            $hashed = password_hash($new, PASSWORD_DEFAULT);
            $stmt = mysqli_prepare($conn, "UPDATE users SET password = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "si", $hashed, $userId);
            if (mysqli_stmt_execute($stmt)) {
                $msg = "Password changed successfully!";
                $msgType = "success";
            } else {
                $msg = "Password update failed.";
                $msgType = "error";
            }
            mysqli_stmt_close($stmt);
        }
    }

    // --------------------------------------------
    // DELETE ACCOUNT (with confirmation)
    // --------------------------------------------
    elseif ($action === 'delete_account') {
        $confirmPassword = $_POST['confirm_delete_password'] ?? '';
        if (empty($confirmPassword)) {
            $msg = "Please enter your password to confirm deletion.";
            $msgType = "error";
        } elseif (!password_verify($confirmPassword, $user['password'])) {
            $msg = "Incorrect password. Account not deleted.";
            $msgType = "error";
        } else {
            // 1. Manually delete rows that have no CASCADE FK
            $tables = ['trip_bookings', 'flight_bookings']; // bookings, purchases, rentals have CASCADE
            foreach ($tables as $table) {
                $stmt = mysqli_prepare($conn, "DELETE FROM `$table` WHERE user_id = ?");
                mysqli_stmt_bind_param($stmt, "i", $userId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }

            // 2. Now delete the user – CASCADE will handle bookings, purchases, rentals, etc.
            $stmt = mysqli_prepare($conn, "DELETE FROM users WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "i", $userId);
            if (mysqli_stmt_execute($stmt)) {
                session_destroy();
                header("Location: ../index.php?deleted=1");
                exit;
            } else {
                $msg = "Account deletion failed. Please try again.";
                $msgType = "error";
            }
            mysqli_stmt_close($stmt);
        }
    }
    elseif ($action === 'save_notifications') {
        $notifications = [
            'ride_updates' => isset($_POST['ride_updates']),
            'promotional_emails' => isset($_POST['promotional_emails']),
        ];
        $_SESSION['settings_notifications'] = $notifications;
        $msg = "Notification preferences saved.";
        $msgType = "success";
    }
    elseif ($action === 'save_privacy') {
        $privacy = [
            'share_analytics' => isset($_POST['share_analytics']),
        ];
        $_SESSION['settings_privacy'] = $privacy;
        $msg = "Privacy settings saved.";
        $msgType = "success";
    }
    elseif ($action === 'save_preferences') {
        $preferences = [
            'currency' => in_array($_POST['currency'] ?? 'NGN', ['NGN','USD']) ? $_POST['currency'] : 'NGN',
        ];
        $_SESSION['settings_preferences'] = $preferences;
        $msg = "Preferences updated.";
        $msgType = "success";
    }
}

$notifications = $_SESSION['settings_notifications'] ?? ['ride_updates' => true, 'promotional_emails' => false];
$privacy = $_SESSION['settings_privacy'] ?? ['share_analytics' => true];
$preferences = $_SESSION['settings_preferences'] ?? ['currency' => 'NGN'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Settings — TransNetX</title>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* (All original CSS unchanged, exactly as provided) */
:root{
  --gold:#f5c518;--gold-dim:rgba(245,197,24,0.1);--gold-glow:0 0 30px rgba(245,197,24,0.3);
  --bg:#0f1219;--bg3:#0d1119;--card:rgba(13,17,25,0.95);
  --border:rgba(245,197,24,0.15);--border2:rgba(245,197,24,0.3);
  --text:#e8eaf0;--text2:#8892a4;--text3:#5c6478;
  --green:#2ecc71;--red:#ff4757;--blue:#4a90d9;
  --radius:18px;--transition:all 0.3s cubic-bezier(0.4,0,0.2,1);
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
body::before, body::after{display:none}

.top-nav{position:sticky;top:0;z-index:100;background:var(--bg);border-bottom:1px solid rgba(255,255,255,0.08);padding:0 32px;height:68px;display:flex;align-items:center;justify-content:space-between}
.nav-brand{font-family:'Bebas Neue',sans-serif;font-size:22px;letter-spacing:2px;background:linear-gradient(135deg,var(--gold),#fff);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.sidebar-toggle{display:none;background:none;border:1px solid var(--border);border-radius:10px;color:var(--gold);font-size:18px;padding:8px 12px;cursor:pointer;transition:var(--transition)}
.sidebar-toggle:hover{background:var(--gold-dim)}

/* Sidebar */
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

@media(max-width:900px){
  .sidebar-toggle{display:block}
  .page-sidebar{transform:translateX(-100%)}
  .page-sidebar.open{transform:translateX(0)}
  .sidebar-backdrop.open{display:block}
  .page-wrapper{margin-left:0}
}

.container{max-width:1050px;margin:0 auto;padding:40px 24px;position:relative;z-index:1}

/* Sidebar user badge */
.sb-user{display:flex;align-items:center;gap:12px;padding:20px 22px;border-bottom:1px solid var(--border)}
.sb-avatar{width:40px;height:40px;border-radius:12px;background:linear-gradient(135deg,#63dcbe,#3b82f6);display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:800;color:#0f1219;flex-shrink:0}
.sb-user-info{min-width:0}
.sb-user-name{font-size:14px;font-weight:700;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sb-user-email{font-size:11px;color:var(--text3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

.toast{position:fixed;top:80px;right:24px;z-index:999;padding:14px 20px;border-radius:12px;border:1px solid;display:flex;align-items:center;gap:10px;font-size:14px;font-weight:500;animation:slideIn 0.4s cubic-bezier(0.34,1.56,0.64,1)}
.toast.success{background:rgba(46,204,113,0.15);border-color:rgba(46,204,113,0.4);color:var(--green)}
.toast.error{background:rgba(255,71,87,0.15);border-color:rgba(255,71,87,0.4);color:var(--red)}
@keyframes slideIn{from{transform:translateX(120%);opacity:0}to{transform:translateX(0);opacity:1}}

.settings-layout{display:grid;grid-template-columns:240px 1fr;gap:24px;align-items:start}
@media(max-width:700px){.settings-layout{grid-template-columns:1fr}}

.settings-nav{
  background:var(--card);border:1px solid var(--border);
  border-radius:var(--radius);overflow:hidden;
  position:sticky;top:88px;
}
.sn-header{
  padding:20px;border-bottom:1px solid var(--border);
  font-family:'Syne',sans-serif;font-size:14px;font-weight:700;
  color:var(--gold);letter-spacing:1px;text-transform:uppercase;
}
.sn-item{
  display:flex;align-items:center;gap:12px;
  padding:13px 20px;cursor:pointer;
  color:var(--text2);font-size:14px;
  transition:var(--transition);border:none;background:none;
  width:100%;text-align:left;
}
.sn-item:hover,.sn-item.active{color:var(--gold);background:var(--gold-dim)}
.sn-item.active{border-right:3px solid var(--gold)}
.sn-item i{width:18px;text-align:center;font-size:14px}

.settings-panel{display:none;animation:fadeUp 0.3s ease}
.settings-panel.active{display:block}
@keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}

.panel-title{
  font-family:'Syne',sans-serif;font-size:22px;font-weight:800;
  background:linear-gradient(135deg,var(--gold),#fff 60%);
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;
  margin-bottom:6px;
}
.panel-sub{font-size:13px;color:var(--text3);margin-bottom:24px}

.card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);margin-bottom:18px;overflow:hidden}
.card-header{padding:18px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px}
.card-header-icon{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:15px}
.card-header h3{font-family:'Syne',sans-serif;font-size:15px;font-weight:700;color:var(--text)}
.card-body{padding:24px}

.form-group{display:flex;flex-direction:column;gap:6px;margin-bottom:14px}
.form-group label{font-size:11px;font-weight:600;color:var(--text3);text-transform:uppercase;letter-spacing:1px}
.form-group input,.form-group select{background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:10px;padding:11px 14px;color:var(--text);font-family:'DM Sans',sans-serif;font-size:14px;outline:none;transition:var(--transition);width:100%}
.form-group input:focus,.form-group select:focus{border-color:var(--gold);background:rgba(245,197,24,0.04);box-shadow:0 0 0 3px rgba(245,197,24,0.1)}
.form-hint{font-size:11px;color:var(--text3);margin-top:3px}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}

.btn{display:inline-flex;align-items:center;gap:8px;padding:10px 20px;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;transition:var(--transition);border:none;font-family:'DM Sans',sans-serif}
.btn-gold{background:linear-gradient(135deg,var(--gold),#c8960c);color:#000;box-shadow:0 4px 15px rgba(245,197,24,0.3)}
.btn-gold:hover{transform:translateY(-2px);box-shadow:0 8px 25px rgba(245,197,24,0.45)}
.btn-danger{background:rgba(255,71,87,0.12);color:var(--red);border:1px solid rgba(255,71,87,0.3)}
.btn-danger:hover{background:rgba(255,71,87,0.2)}
.btn-outline{background:transparent;color:var(--gold);border:1px solid var(--border2)}
.btn-outline:hover{background:var(--gold-dim)}

.toggle-row{display:flex;align-items:center;justify-content:space-between;padding:14px 0;border-bottom:1px solid rgba(245,197,24,0.05)}
.toggle-row:last-child{border-bottom:none}
.toggle-info strong{display:block;font-size:14px;color:var(--text);margin-bottom:2px}
.toggle-info span{font-size:12px;color:var(--text3)}
.toggle{position:relative;width:46px;height:24px;flex-shrink:0}
.toggle input{opacity:0;width:0;height:0}
.slider{position:absolute;inset:0;border-radius:24px;background:rgba(255,255,255,0.08);border:1px solid var(--border);cursor:pointer;transition:var(--transition)}
.slider::before{content:'';position:absolute;height:16px;width:16px;border-radius:50%;left:3px;top:3px;background:var(--text3);transition:var(--transition)}
.toggle input:checked + .slider{background:var(--gold);border-color:var(--gold);box-shadow:0 0 10px rgba(245,197,24,0.3)}
.toggle input:checked + .slider::before{transform:translateX(22px);background:#000}

.security-item{
  display:flex;align-items:center;gap:14px;
  padding:16px;border-radius:12px;
  border:1px solid var(--border);
  background:rgba(255,255,255,0.02);
  margin-bottom:10px;
  transition:var(--transition);
}
.security-item:hover{border-color:var(--border2);background:var(--gold-dim)}
.si-icon{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0}
.si-body{flex:1}
.si-body strong{display:block;font-size:14px;color:var(--text)}
.si-body span{font-size:12px;color:var(--text3)}
.si-status{display:flex;align-items:center;gap:5px;font-size:12px;font-weight:600}
.si-status.enabled{color:var(--green)}.si-status.disabled{color:var(--text3)}

.strength-bar{height:4px;border-radius:2px;background:rgba(255,255,255,0.06);overflow:hidden;margin-top:6px}
.strength-fill{height:100%;border-radius:2px;transition:var(--transition);width:0%}
.strength-label{font-size:11px;margin-top:4px;color:var(--text3)}

.danger-zone{background:rgba(255,71,87,0.05);border:1px solid rgba(255,71,87,0.2);border-radius:var(--radius);padding:24px}
.danger-zone h3{color:var(--red);font-family:'Syne',sans-serif;margin-bottom:6px}
.danger-zone p{font-size:13px;color:var(--text3);margin-bottom:16px}

::-webkit-scrollbar{width:6px;height:6px}
::-webkit-scrollbar-thumb{background:var(--border2);border-radius:3px}
</style>
</head>
<body>

<?php if ($msg): ?>
<div class="toast <?= $msgType ?>">
  <i class="fas fa-<?= $msgType==='success'?'check-circle':'exclamation-circle' ?>"></i>
  <?= $msg ?>
</div>
<?php endif; ?>

<nav class="top-nav">
  <span class="nav-brand">TransNetX Settings</span>
  <button class="sidebar-toggle" id="sidebarToggle" onclick="toggleSidebar()">
    <i class="fas fa-bars"></i>
  </button>
</nav>

<!-- Page Sidebar -->
<aside class="page-sidebar" id="pageSidebar">
  <div class="sb-user">
    <div class="sb-avatar"><?= strtoupper(substr(trim(($user['name'] ?? '') . ' ' . ($user['surname'] ?? '')),0,2)) ?: 'TN' ?></div>
    <div class="sb-user-info">
      <div class="sb-user-name"><?= htmlspecialchars(trim(($user['name'] ?? '') . ' ' . ($user['surname'] ?? ''))) ?: 'User' ?></div>
      <div class="sb-user-email"><?= htmlspecialchars($user['email'] ?? '') ?></div>
    </div>
  </div>

  <div class="sb-header">Navigation</div>
  <a href="dashboard.php" class="sb-link"><i class="fas fa-gauge-high"></i><span>Dashboard</span></a>
  <a href="profile.php" class="sb-link"><i class="fas fa-user"></i><span>Profile</span></a>

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
  <div class="settings-layout">

    <!-- Settings Navigation -->
    <div class="settings-nav">
      <div class="sn-header">Settings</div>
      <button class="sn-item active" onclick="showPanel('profile',this)"><i class="fas fa-user"></i> Profile</button>
      <button class="sn-item" onclick="showPanel('security',this)"><i class="fas fa-shield-alt"></i> Security</button>
      <button class="sn-item" onclick="showPanel('notifications',this)"><i class="fas fa-bell"></i> Notifications</button>
      <button class="sn-item" onclick="showPanel('privacy',this)"><i class="fas fa-lock"></i> Privacy</button>
      <button class="sn-item" onclick="showPanel('preferences',this)"><i class="fas fa-sliders-h"></i> Preferences</button>
      <button class="sn-item" onclick="showPanel('danger',this)" style="color:var(--red)"><i class="fas fa-exclamation-triangle"></i> Delete Account</button>
    </div>

    <!-- Right Panels -->
    <div>

      <!-- PROFILE PANEL (updated to match users table) -->
      <div class="settings-panel active" id="panel-profile">
        <div class="panel-title">Profile Information</div>
        <div class="panel-sub">Manage your personal details</div>

        <div class="card">
          <div class="card-header">
            <div class="card-header-icon" style="background:linear-gradient(135deg,#63dcbe,#3b82f6);color:#0f1219;font-weight:700;font-size:16px">
              <?= strtoupper(substr(trim(($user['name'] ?? '') . ' ' . ($user['surname'] ?? '')),0,2)) ?: 'TN' ?>
            </div>
            <h3>Account Summary</h3>
          </div>
          <div class="card-body" style="display:flex;gap:18px;flex-wrap:wrap;align-items:center;">
            <div style="width:88px;height:88px;border-radius:22px;background:linear-gradient(135deg,#63dcbe,#3b82f6);display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:800;color:#0f1219;flex-shrink:0;">
              <?= strtoupper(substr(trim(($user['name'] ?? '') . ' ' . ($user['surname'] ?? '')),0,2)) ?: 'TN' ?>
            </div>
            <div style="min-width:200px;flex:1;">
              <div style="font-size:16px;font-weight:700;color:#fff;margin-bottom:8px"><?= htmlspecialchars(trim(($user['name'] ?? '') . ' ' . ($user['surname'] ?? ''))) ?: 'TransNetX User' ?></div>
              <div style="font-size:13px;color:var(--text3);margin-bottom:6px">Email: <?= htmlspecialchars($user['email'] ?? '') ?></div>
              <div style="font-size:13px;color:var(--text3);margin-bottom:6px">Member since: <?= isset($user['created_at']) ? date('F j, Y', strtotime($user['created_at'])) : '—' ?></div>
              <div style="font-size:13px;color:var(--text3)">Country / State: <?= htmlspecialchars($user['country'] ?? '—') ?> / <?= htmlspecialchars($user['state'] ?? '—') ?></div>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-header">
            <div class="card-header-icon" style="background:rgba(245,197,24,0.12);color:var(--gold)"><i class="fas fa-user-edit"></i></div>
            <h3>Edit Profile</h3>
          </div>
          <div class="card-body">
            <form method="POST">
              <input type="hidden" name="action" value="update_profile">
              <div class="form-row">
                <div class="form-group">
                  <label>First Name</label>
                  <input type="text" name="name" value="<?= htmlspecialchars($user['name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                  <label>Surname</label>
                  <input type="text" name="surname" value="<?= htmlspecialchars($user['surname'] ?? '') ?>" required>
                </div>
              </div>
              <div class="form-row">
                <div class="form-group">
                  <label>Phone Number</label>
                  <input type="tel" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                  <label>NIN (read-only)</label>
                  <input type="text" value="<?= htmlspecialchars($user['nin'] ?? '') ?>" disabled>
                  <span class="form-hint">Contact support to change NIN</span>
                </div>
              </div>
              <div class="form-row">
                <div class="form-group">
                  <label>Country</label>
                  <input type="text" name="country" value="<?= htmlspecialchars($user['country'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                  <label>State / Province</label>
                  <input type="text" name="state" value="<?= htmlspecialchars($user['state'] ?? '') ?>" required>
                </div>
              </div>
              <div class="form-group">
                <label>Email (read-only)</label>
                <input type="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" disabled>
              </div>
              <button type="submit" class="btn btn-gold"><i class="fas fa-save"></i> Update Profile</button>
            </form>
          </div>
        </div>
      </div>

      <!-- SECURITY PANEL (password change – unchanged) -->
      <div class="settings-panel" id="panel-security">
        <div class="panel-title">Security</div>
        <div class="panel-sub">Protect your account</div>

        <div class="card">
          <div class="card-header">
            <div class="card-header-icon" style="background:rgba(245,197,24,0.12);color:var(--gold)"><i class="fas fa-lock"></i></div>
            <h3>Change Password</h3>
          </div>
          <div class="card-body">
            <form method="POST">
              <input type="hidden" name="action" value="change_password">
              <div class="form-group">
                <label>Current Password</label>
                <input type="password" name="password" required>
              </div>
              <div class="form-group">
                <label>New Password</label>
                <input type="password" name="new_password" id="new_password" required onkeyup="checkStrength(this.value)">
                <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
                <div class="strength-label" id="strengthLabel"></div>
              </div>
              <div class="form-group">
                <label>Confirm New Password</label>
                <input type="password" name="confirm_password" required>
              </div>
              <button type="submit" class="btn btn-gold"><i class="fas fa-key"></i> Update Password</button>
            </form>
          </div>
        </div>
      </div>

      <!-- NOTIFICATIONS PANEL (unchanged) -->
      <div class="settings-panel" id="panel-notifications">
        <div class="panel-title">Notifications</div>
        <div class="panel-sub">Control how you receive alerts</div>
        <div class="card">
          <div class="card-header"><h3>Email Notifications</h3></div>
          <div class="card-body">
            <form method="POST">
              <input type="hidden" name="action" value="save_notifications">
              <div class="toggle-row">
                <div class="toggle-info"><strong>Ride updates</strong><span>When driver accepts/completes</span></div>
                <label class="toggle"><input type="checkbox" name="ride_updates" <?= $notifications['ride_updates'] ? 'checked' : '' ?>><span class="slider"></span></label>
              </div>
              <div class="toggle-row">
                <div class="toggle-info"><strong>Promotional emails</strong><span>Offers and discounts</span></div>
                <label class="toggle"><input type="checkbox" name="promotional_emails" <?= $notifications['promotional_emails'] ? 'checked' : '' ?>><span class="slider"></span></label>
              </div>
              <button type="submit" class="btn btn-gold"><i class="fas fa-save"></i> Save Notification Settings</button>
            </form>
          </div>
        </div>
      </div>

      <!-- PRIVACY PANEL (unchanged) -->
      <div class="settings-panel" id="panel-privacy">
        <div class="panel-title">Privacy</div>
        <div class="panel-sub">Manage your data</div>
        <div class="card">
          <div class="card-header"><h3>Data Sharing</h3></div>
          <div class="card-body">
            <form method="POST">
              <input type="hidden" name="action" value="save_privacy">
              <div class="toggle-row">
                <div class="toggle-info"><strong>Share usage analytics</strong><span>Help us improve TransNetX</span></div>
                <label class="toggle"><input type="checkbox" name="share_analytics" <?= $privacy['share_analytics'] ? 'checked' : '' ?>><span class="slider"></span></label>
              </div>
              <button type="submit" class="btn btn-gold"><i class="fas fa-save"></i> Save Privacy Settings</button>
            </form>
          </div>
        </div>
      </div>

      <!-- PREFERENCES PANEL (unchanged) -->
      <div class="settings-panel" id="panel-preferences">
        <div class="panel-title">Preferences</div>
        <div class="panel-sub">Customize your experience</div>
        <div class="card">
          <div class="card-header"><h3>Display</h3></div>
          <div class="card-body">
            <form method="POST">
              <input type="hidden" name="action" value="save_preferences">
              <div class="form-group">
                <label>Default Currency</label>
                <select name="currency">
                  <option value="NGN" <?= $preferences['currency'] === 'NGN' ? 'selected' : '' ?>>₦ Nigerian Naira (NGN)</option>
                  <option value="USD" <?= $preferences['currency'] === 'USD' ? 'selected' : '' ?>>$ US Dollar (USD)</option>
                </select>
              </div>
              <button type="submit" class="btn btn-gold"><i class="fas fa-save"></i> Save Preferences</button>
            </form>
          </div>
        </div>
      </div>

      <!-- DANGER ZONE (updated with password confirmation) -->
      <div class="settings-panel" id="panel-danger">
        <div class="panel-title">Danger Zone</div>
        <div class="panel-sub">Irreversible actions</div>
        <div class="danger-zone">
          <h3><i class="fas fa-exclamation-triangle"></i> Delete Account</h3>
          <p>Once you delete your account, there is no going back. All your data will be permanently removed.</p>
          <form method="POST" onsubmit="return confirm('Are you absolutely sure? This cannot be undone.')">
            <input type="hidden" name="action" value="delete_account">
            <div class="form-group">
              <label>Enter your password to confirm</label>
              <input type="password" name="confirm_delete_password" required placeholder="Your current password">
            </div>
            <button type="submit" class="btn btn-danger"><i class="fas fa-trash-alt"></i> Permanently Delete My Account</button>
          </form>
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

// Panel switching
function showPanel(id, el) {
  document.querySelectorAll('.settings-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.sn-item').forEach(i => i.classList.remove('active'));
  document.getElementById('panel-'+id).classList.add('active');
  if(el) el.classList.add('active');
}

// Password strength meter
function checkStrength(pw) {
  let score = 0;
  if (pw.length >= 8) score++;
  if (/[A-Z]/.test(pw)) score++;
  if (/[0-9]/.test(pw)) score++;
  if (/[^A-Za-z0-9]/.test(pw)) score++;
  const fill = document.getElementById('strengthFill');
  const label = document.getElementById('strengthLabel');
  const percent = (score/4)*100;
  fill.style.width = percent+'%';
  fill.style.background = ['#ef4444','#f0b429','#3b82f6','#22c55e'][score-1] || '#ef4444';
  label.textContent = ['Weak','Fair','Good','Strong'][score-1] || '';
}

// Auto-hide toast
setTimeout(() => {
  const toast = document.querySelector('.toast');
  if(toast) toast.style.animation = 'slideIn 0.4s reverse forwards';
}, 4000);
</script>
</body>
</html>