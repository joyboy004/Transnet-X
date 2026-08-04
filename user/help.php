<?php
session_start();
require_once '../config/db.php';
if (!isset($_SESSION['user_id'])) { header('Location: ../index.php'); exit(); }
$uid = (int)$_SESSION['user_id'];
$stmt = mysqli_prepare($conn, "SELECT name, email FROM users WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $uid);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($result);
if (!$user) { session_destroy(); header('Location: ../index.php'); exit(); }
$user_name  = explode(' ', $user['name'])[0];
$user_email = $user['email'];
$initial    = strtoupper(substr($user['name'], 0, 1));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Help Center — TransNet X</title>
  <meta name="description" content="Get help with your TransNet X account, rides, deliveries, payments, and more.">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=Bebas+Neue&display=swap" rel="stylesheet">
  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js" defer onerror="this.remove()"></script>
  <script src="../assets/offline-icons.js"></script>
  <style>
    *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
    :root {
      --ac: #10B981; --ac2: #34D399; --ac3: #6EE7B7; --ac-dk: #064E3B;
      --warn: #F59E0B; --red: #EF4444; --blue: #3B82F6;
      --black: #0f1219; --surface: #161b26; --card: rgba(22,27,38,0.7);
      --border: rgba(255,255,255,0.07); --border2: rgba(59,130,246,0.35);
      --text: #e5e7eb; --muted: #9ca3af; --r: 12px; --r2: 8px;
    }
    html, body { height: 100%; font-family: 'Sora', sans-serif; background: var(--black); color: var(--text); overflow-x: hidden; }
    body { display: flex; flex-direction: column; min-height: 100vh; }

    /* BG */
    .bg-grid {
      position: fixed; inset: 0; z-index: 0; pointer-events: none;
      background-image: linear-gradient(rgba(59,130,246,.03) 1px, transparent 1px),
                        linear-gradient(90deg, rgba(59,130,246,.03) 1px, transparent 1px);
      background-size: 48px 48px;
    }

    /* SIDEBAR */
    .sidebar {
      position: fixed; left: -290px; top: 0; width: 280px; height: 100vh;
      background: rgba(10,14,22,0.98); backdrop-filter: blur(20px);
      border-right: 1px solid var(--border); z-index: 1000;
      transition: left 0.3s cubic-bezier(0.16,1,0.3,1);
      display: flex; flex-direction: column; padding: 0;
    }
    .sidebar.open { left: 0; }
    .sidebar-overlay {
      position: fixed; inset: 0; background: rgba(0,0,0,0.5);
      backdrop-filter: blur(3px); z-index: 999;
      opacity: 0; visibility: hidden; transition: all 0.3s;
    }
    .sidebar-overlay.show { opacity: 1; visibility: visible; }

    .sb-header {
      padding: 20px; border-bottom: 1px solid var(--border);
      display: flex; align-items: center; gap: 10px;
    }
    .sb-logo-icon {
      width: 38px; height: 38px; border-radius: 10px;
      background: linear-gradient(135deg, #1d4ed8, #3b82f6);
      display: flex; align-items: center; justify-content: center;
      color: #fff; flex-shrink: 0;
    }
    .sb-logo-text { font-family: 'Bebas Neue', sans-serif; font-size: 20px; letter-spacing: 2px; }
    .sb-logo-text span { color: var(--ac2); }

    .sb-user {
      padding: 14px 20px; border-bottom: 1px solid var(--border);
      display: flex; align-items: center; gap: 10px;
    }
    .sb-avatar {
      width: 38px; height: 38px; border-radius: 50%;
      background: linear-gradient(135deg, #1e40af, var(--blue));
      display: flex; align-items: center; justify-content: center;
      font-weight: 700; font-size: 15px; color: #fff; flex-shrink: 0;
    }
    .sb-user-info p { font-size: 13px; font-weight: 600; }
    .sb-user-info small { font-size: 11px; color: var(--muted); }

    .sb-nav { flex: 1; padding: 12px; overflow-y: auto; display: flex; flex-direction: column; gap: 2px; }
    .nav-link {
      display: flex; align-items: center; gap: 12px;
      padding: 11px 14px; border-radius: var(--r2);
      color: var(--muted); text-decoration: none;
      font-size: 13.5px; font-weight: 500; transition: all 0.2s;
      border: 1px solid transparent;
    }
    .nav-link i { width: 18px; flex-shrink: 0; }
    .nav-link:hover { background: rgba(255,255,255,0.06); color: var(--text); }
    .nav-link.active { background: rgba(59,130,246,0.12); color: var(--blue); border-color: rgba(59,130,246,0.25); }
    .nav-link.logout { color: #f87171; margin-top: 8px; }
    .nav-link.logout:hover { background: rgba(248,113,113,0.08); }
    .nav-divider { height: 1px; background: var(--border); margin: 8px 0; }

    .sb-footer { padding: 14px 20px; border-top: 1px solid var(--border); font-size: 11px; color: var(--muted); }

    /* TOPBAR */
    .topbar {
      position: sticky; top: 0; z-index: 200;
      background: rgba(15,18,25,0.92); backdrop-filter: blur(20px);
      border-bottom: 1px solid var(--border);
      display: flex; align-items: center; justify-content: space-between;
      padding: 0 24px; height: 60px;
    }
    .topbar-left { display: flex; align-items: center; gap: 12px; }
    .hamburger-btn {
      width: 36px; height: 36px; border-radius: 8px;
      background: rgba(255,255,255,0.05); border: 1px solid var(--border);
      display: flex; align-items: center; justify-content: center;
      cursor: pointer; color: var(--text); transition: all 0.2s;
    }
    .hamburger-btn:hover { background: rgba(255,255,255,0.1); }
    .topbar-title { font-size: 18px; font-weight: 700; color: var(--text); }
    .topbar-right { display: flex; align-items: center; gap: 10px; }
    .avatar-btn {
      width: 34px; height: 34px; border-radius: 50%;
      background: linear-gradient(135deg, #1e40af, var(--blue));
      display: flex; align-items: center; justify-content: center;
      font-weight: 700; font-size: 13px; color: #fff;
      cursor: pointer; border: 1.5px solid rgba(59,130,246,0.4);
    }

    /* MAIN CONTENT */
    .main { position: relative; z-index: 1; flex: 1; padding: 32px 24px 60px; max-width: 1100px; margin: 0 auto; width: 100%; }

    /* HERO */
    .help-hero {
      background: linear-gradient(135deg, rgba(59,130,246,0.12) 0%, rgba(16,185,129,0.08) 100%);
      border: 1px solid rgba(59,130,246,0.2);
      border-radius: 20px; padding: 48px 40px; text-align: center; margin-bottom: 36px;
    }
    .help-hero h1 { font-size: 2rem; font-weight: 800; color: var(--text); margin-bottom: 8px; }
    .help-hero h1 span { color: var(--blue); }
    .help-hero p { color: var(--muted); font-size: 14px; margin-bottom: 24px; }
    .search-box {
      position: relative; max-width: 520px; margin: 0 auto;
    }
    .search-box i {
      position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
      color: var(--muted); width: 18px; height: 18px;
    }
    .search-box input {
      width: 100%; padding: 13px 13px 13px 46px;
      background: rgba(22,27,38,0.9); border: 1px solid rgba(59,130,246,0.3);
      border-radius: 12px; color: var(--text); font-family: inherit; font-size: 14px;
      outline: none; transition: border-color 0.2s;
    }
    .search-box input:focus { border-color: var(--blue); }
    .search-box input::placeholder { color: var(--muted); }
    #searchResults {
      margin-top: 12px; display: none;
      background: rgba(22,27,38,0.95); border: 1px solid var(--border);
      border-radius: var(--r); padding: 8px; text-align: left; max-height: 200px; overflow-y: auto;
    }
    .sr-item {
      padding: 10px 14px; border-radius: var(--r2); cursor: pointer;
      font-size: 13px; color: var(--text); transition: background 0.15s;
    }
    .sr-item:hover { background: rgba(59,130,246,0.1); color: var(--blue); }
    .sr-item .sr-cat { font-size: 10px; color: var(--muted); display: block; margin-top: 2px; }

    /* CATEGORY CARDS */
    .section-title { font-size: 15px; font-weight: 700; color: var(--text); margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
    .section-title::after { content: ''; flex: 1; height: 1px; background: var(--border); }
    .cat-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 14px; margin-bottom: 40px; }
    .cat-card {
      background: var(--card); border: 1px solid var(--border); border-radius: 16px;
      padding: 24px 20px; text-align: center; cursor: pointer;
      transition: transform 0.25s, border-color 0.25s, box-shadow 0.25s;
    }
    .cat-card:hover { transform: translateY(-5px); border-color: rgba(59,130,246,0.4); box-shadow: 0 12px 32px rgba(0,0,0,0.3); }
    .cat-icon {
      width: 52px; height: 52px; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      margin: 0 auto 14px; transition: transform 0.25s;
    }
    .cat-card:hover .cat-icon { transform: scale(1.1); }
    .cat-icon i { width: 24px; height: 24px; }
    .cat-card h3 { font-size: 14px; font-weight: 700; color: var(--text); margin-bottom: 6px; }
    .cat-card p { font-size: 12px; color: var(--muted); line-height: 1.5; }

    /* FAQ */
    .faq-list { display: flex; flex-direction: column; gap: 10px; margin-bottom: 40px; }
    .faq-item {
      background: var(--card); border: 1px solid var(--border); border-radius: var(--r);
      overflow: hidden; transition: border-color 0.2s;
    }
    .faq-item.open { border-color: rgba(59,130,246,0.3); }
    .faq-q {
      display: flex; align-items: center; justify-content: space-between;
      padding: 16px 20px; cursor: pointer; font-size: 14px; font-weight: 600; color: var(--text);
      gap: 12px; user-select: none;
    }
    .faq-q i.chevron { width: 18px; height: 18px; transition: transform 0.25s; flex-shrink: 0; color: var(--muted); }
    .faq-item.open .faq-q i.chevron { transform: rotate(180deg); color: var(--blue); }
    .faq-a {
      max-height: 0; overflow: hidden; transition: max-height 0.35s ease;
      font-size: 13px; color: var(--muted); line-height: 1.7;
    }
    .faq-a-inner { padding: 0 20px 18px; }
    .faq-item.open .faq-a { max-height: 300px; }

    /* CONTACT CTAS */
    .contact-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 14px; }
    .contact-card {
      background: var(--card); border: 1px solid var(--border); border-radius: 16px;
      padding: 24px 20px; text-align: center; text-decoration: none;
      color: var(--text); transition: all 0.25s;
    }
    .contact-card:hover { border-color: rgba(59,130,246,0.4); transform: translateY(-3px); }
    .contact-card .ci { width: 44px; height: 44px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 12px; }
    .contact-card h4 { font-size: 13px; font-weight: 700; margin-bottom: 4px; }
    .contact-card p { font-size: 11.5px; color: var(--muted); }

    @media(max-width:768px){
      .cat-grid { grid-template-columns: repeat(2,1fr); }
      .help-hero { padding: 32px 20px; }
      .help-hero h1 { font-size: 1.5rem; }
    }
  </style>
</head>
<body>
<div class="bg-grid"></div>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar(false)"></div>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
  <div class="sb-header">
    <div class="sb-logo-icon"><i data-lucide="compass" style="width:20px;height:20px"></i></div>
    <div class="sb-logo-text">Trans<span>Net</span> X</div>
  </div>
  <div class="sb-user">
    <div class="sb-avatar"><?= $initial ?></div>
    <div class="sb-user-info">
      <p><?= htmlspecialchars($user['name']) ?></p>
      <small><?= htmlspecialchars($user_email) ?></small>
    </div>
  </div>
  <nav class="sb-nav">
    <a href="dashboard.php" class="nav-link"><i data-lucide="layout-dashboard" style="width:18px;height:18px"></i> <span>Dashboard</span></a>
    <a href="profile.php" class="nav-link"><i data-lucide="user-circle" style="width:18px;height:18px"></i> <span>Profile</span></a>
    <a href="records.php" class="nav-link"><i data-lucide="history" style="width:18px;height:18px"></i> <span>Records</span></a>
    <a href="order_food.php" class="nav-link"><i data-lucide="utensils" style="width:18px;height:18px"></i> <span>Order Food</span></a>
    <a href="delivery.php" class="nav-link"><i data-lucide="package" style="width:18px;height:18px"></i> <span>Delivery</span></a>
    <a href="Transnet/rental.php" class="nav-link"><i data-lucide="key" style="width:18px;height:18px"></i> <span>Rentals</span></a>
    <a href="emergency.php" class="nav-link"><i data-lucide="alert-triangle" style="width:18px;height:18px"></i> <span>Emergency</span></a>
    <a href="settings.php" class="nav-link"><i data-lucide="settings" style="width:18px;height:18px"></i> <span>Settings</span></a>
    <div class="nav-divider"></div>
    <a href="about.php" class="nav-link"><i data-lucide="info" style="width:18px;height:18px"></i> <span>About Us</span></a>
    <a href="contact.php" class="nav-link"><i data-lucide="mail" style="width:18px;height:18px"></i> <span>Contact</span></a>
    <a href="faq.php" class="nav-link"><i data-lucide="help-circle" style="width:18px;height:18px"></i> <span>FAQ</span></a>
    <a href="privacy.php" class="nav-link"><i data-lucide="shield" style="width:18px;height:18px"></i> <span>Privacy</span></a>
    <a href="terms.php" class="nav-link"><i data-lucide="file-text" style="width:18px;height:18px"></i> <span>Terms</span></a>
    <a href="help.php" class="nav-link active"><i data-lucide="help-circle" style="width:18px;height:18px"></i> <span>Help Center</span></a>
    <a href="../auth/logout.php" class="nav-link logout"><i data-lucide="log-out" style="width:18px;height:18px"></i> <span>Logout</span></a>
  </nav>
  <div class="sb-footer">© 2026 TransNet X · v2.1.0</div>
</aside>

<!-- TOPBAR -->
<nav class="topbar">
  <div class="topbar-left">
    <button class="hamburger-btn" id="menuBtn" onclick="toggleSidebar(true)" aria-label="Menu">
      <i data-lucide="menu" style="width:20px;height:20px"></i>
    </button>
    <span class="topbar-title">Help Center</span>
  </div>
  <div class="topbar-right">
    <div class="avatar-btn" onclick="location.href='profile.php'" title="Profile"><?= $initial ?></div>
  </div>
</nav>

<!-- MAIN -->
<main class="main">

  <!-- HERO -->
  <div class="help-hero">
    <h1>Hello <?= htmlspecialchars($user_name) ?>, how can we <span>help?</span></h1>
    <p>Search for answers or browse by category below. We're here 24/7.</p>
    <div class="search-box">
      <i data-lucide="search" style="width:18px;height:18px"></i>
      <input type="text" id="searchInput" placeholder="Search — e.g. 'track delivery', 'change password'...">
    </div>
    <div id="searchResults"></div>
  </div>

  <!-- CATEGORIES -->
  <p class="section-title">Browse by Category</p>
  <div class="cat-grid">
    <div class="cat-card" onclick="openCat('account')">
      <div class="cat-icon" style="background:rgba(59,130,246,0.15);color:#60a5fa">
        <i data-lucide="user" style="width:24px;height:24px"></i>
      </div>
      <h3>Account &amp; Profile</h3>
      <p>Password, email, name, settings &amp; verification.</p>
    </div>
    <div class="cat-card" onclick="openCat('rides')">
      <div class="cat-icon" style="background:rgba(245,158,11,0.15);color:#fbbf24">
        <i data-lucide="car" style="width:24px;height:24px"></i>
      </div>
      <h3>Rides &amp; Transport</h3>
      <p>Bookings, driver issues, cancellations &amp; fares.</p>
    </div>
    <div class="cat-card" onclick="openCat('delivery')">
      <div class="cat-icon" style="background:rgba(16,185,129,0.15);color:#34d399">
        <i data-lucide="package" style="width:24px;height:24px"></i>
      </div>
      <h3>Delivery</h3>
      <p>Tracking parcels, re-delivery &amp; lost packages.</p>
    </div>
    <div class="cat-card" onclick="openCat('food')">
      <div class="cat-icon" style="background:rgba(239,68,68,0.15);color:#f87171">
        <i data-lucide="utensils" style="width:24px;height:24px"></i>
      </div>
      <h3>Food Orders</h3>
      <p>Order status, cancellation &amp; missing items.</p>
    </div>
    <div class="cat-card" onclick="openCat('payments')">
      <div class="cat-icon" style="background:rgba(139,92,246,0.15);color:#a78bfa">
        <i data-lucide="credit-card" style="width:24px;height:24px"></i>
      </div>
      <h3>Payments &amp; Billing</h3>
      <p>Charges, refunds, payment methods &amp; receipts.</p>
    </div>
    <div class="cat-card" onclick="openCat('safety')">
      <div class="cat-icon" style="background:rgba(239,68,68,0.15);color:#f87171">
        <i data-lucide="shield-alert" style="width:24px;height:24px"></i>
      </div>
      <h3>Safety &amp; Emergencies</h3>
      <p>SOS, incident reporting &amp; emergency contacts.</p>
    </div>
  </div>

  <!-- FAQ -->
  <p class="section-title">Frequently Asked Questions</p>
  <div class="faq-list" id="faqList">
    <?php
    $faqs = [
      ['How do I track my delivery?', 'Go to the Delivery page and click the "Track Request" section. Enter your tracking code (e.g. TXD…) and hit Track.', 'delivery'],
      ['How do I cancel an order?', 'Navigate to Records, find your order, and click "Cancel" if the status is still Pending. Once approved, please contact support.', 'orders'],
      ['Can I change my delivery address after placing an order?', 'Contact our support team immediately at support@transnetx.com. Changes can only be made before the package is dispatched.', 'delivery'],
      ['How do I change my account password?', 'Go to Settings → Security and click "Change Password". You will need your current password to proceed.', 'account'],
      ['What payment methods are accepted?', 'We accept Card payments, Bank Transfer, Cash on Delivery (COD), and Wallet top-ups across all our services.', 'payments'],
      ['My food order is late — what do I do?', 'Check the order status on your Records page. If it shows "Processing" for more than 45 minutes, contact support with your order ID.', 'food'],
      ['How do I report a safety incident?', 'Use the Emergency page (red SOS button) for immediate help. For non-urgent incidents, email safety@transnetx.com.', 'safety'],
      ['How do I rent a vehicle?', 'Visit the Rentals page, browse available vehicles, choose your pickup & return dates, and submit your booking.', 'rentals'],
      ['What is the refund policy?', 'Refunds are processed within 3–5 business days. Visit the Refund Policy page for detailed eligibility criteria.', 'payments'],
      ['How do I contact customer support?', 'Email us at support@transnetx.com or call 0800-TRANSNETX. Live chat is available Mon–Sat, 8am–10pm.', 'account'],
    ];
    foreach ($faqs as $i => $faq):
    ?>
    <div class="faq-item" data-cat="<?= $faq[2] ?>" data-q="<?= htmlspecialchars(strtolower($faq[0])) ?>" data-a="<?= htmlspecialchars(strtolower($faq[1])) ?>">
      <div class="faq-q" onclick="toggleFaq(this)">
        <span><?= htmlspecialchars($faq[0]) ?></span>
        <i data-lucide="chevron-down" class="chevron" style="width:18px;height:18px"></i>
      </div>
      <div class="faq-a"><div class="faq-a-inner"><?= htmlspecialchars($faq[1]) ?></div></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- CONTACT CTAs -->
  <p class="section-title">Still Need Help?</p>
  <div class="contact-grid">
    <a href="contact.php" class="contact-card">
      <div class="ci" style="background:rgba(59,130,246,0.15);color:#60a5fa">
        <i data-lucide="mail" style="width:22px;height:22px"></i>
      </div>
      <h4>Email Support</h4>
      <p>support@transnetx.com<br>Reply within 24 hours</p>
    </a>
    <a href="emergency.php" class="contact-card">
      <div class="ci" style="background:rgba(239,68,68,0.15);color:#f87171">
        <i data-lucide="alert-triangle" style="width:22px;height:22px"></i>
      </div>
      <h4>Emergency SOS</h4>
      <p>Immediate help for safety &amp; urgent situations</p>
    </a>
    <a href="faq.php" class="contact-card">
      <div class="ci" style="background:rgba(16,185,129,0.15);color:#34d399">
        <i data-lucide="help-circle" style="width:22px;height:22px"></i>
      </div>
      <h4>Full FAQ</h4>
      <p>Detailed answers to all common questions</p>
    </a>
  </div>

</main>

<script>
  // Sidebar
  function toggleSidebar(show) {
    const sb = document.getElementById('sidebar');
    const ov = document.getElementById('sidebarOverlay');
    if (show) { sb.classList.add('open'); ov.classList.add('show'); document.body.style.overflow = 'hidden'; }
    else { sb.classList.remove('open'); ov.classList.remove('show'); document.body.style.overflow = ''; }
  }

  // FAQ toggle
  function toggleFaq(el) {
    const item = el.closest('.faq-item');
    const isOpen = item.classList.contains('open');
    document.querySelectorAll('.faq-item.open').forEach(i => i.classList.remove('open'));
    if (!isOpen) item.classList.add('open');
  }

  // Category filter
  function openCat(cat) {
    const items = document.querySelectorAll('.faq-item');
    items.forEach(item => {
      item.style.display = item.dataset.cat === cat ? 'block' : 'none';
    });
    document.getElementById('faqList').scrollIntoView({ behavior: 'smooth', block: 'start' });
    // Add a reset button
    let resetBtn = document.getElementById('resetFaqBtn');
    if (!resetBtn) {
      resetBtn = document.createElement('button');
      resetBtn.id = 'resetFaqBtn';
      resetBtn.textContent = '← Show All FAQs';
      resetBtn.style.cssText = 'background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);color:#9ca3af;padding:6px 14px;border-radius:8px;cursor:pointer;font-size:12px;margin-bottom:12px;font-family:inherit;';
      resetBtn.onclick = () => {
        document.querySelectorAll('.faq-item').forEach(i => i.style.display = '');
        resetBtn.remove();
      };
      document.getElementById('faqList').before(resetBtn);
    }
  }

  // Search
  const allFaqs = Array.from(document.querySelectorAll('.faq-item')).map(el => ({
    q: el.querySelector('.faq-q span').textContent,
    a: el.querySelector('.faq-a-inner').textContent,
    cat: el.dataset.cat,
    el: el
  }));

  document.getElementById('searchInput').addEventListener('input', function () {
    const q = this.value.trim().toLowerCase();
    const res = document.getElementById('searchResults');
    if (!q) { res.style.display = 'none'; return; }
    const matches = allFaqs.filter(f => f.q.toLowerCase().includes(q) || f.a.toLowerCase().includes(q));
    if (!matches.length) {
      res.innerHTML = '<div class="sr-item">No results found.</div>';
    } else {
      res.innerHTML = matches.map(f =>
        `<div class="sr-item" onclick="scrollToFaq(this)" data-idx="${allFaqs.indexOf(f)}">
          ${f.q}<span class="sr-cat">${f.cat}</span>
        </div>`
      ).join('');
    }
    res.style.display = 'block';
  });

  function scrollToFaq(el) {
    const idx = el.dataset.idx;
    const target = allFaqs[idx];
    if (target) {
      document.getElementById('searchResults').style.display = 'none';
      document.getElementById('searchInput').value = '';
      document.querySelectorAll('.faq-item').forEach(i => i.style.display = '');
      target.el.scrollIntoView({ behavior: 'smooth', block: 'center' });
      setTimeout(() => target.el.classList.add('open'), 400);
    }
  }

  // Close search results on outside click
  document.addEventListener('click', e => {
    if (!e.target.closest('.search-box')) {
      document.getElementById('searchResults').style.display = 'none';
    }
  });

  // Init Lucide icons
  window.addEventListener('DOMContentLoaded', () => {
    if (typeof lucide !== 'undefined' && lucide.createIcons) {
      lucide.createIcons();
    } else if (typeof renderOfflineIcons !== 'undefined') {
      renderOfflineIcons(document);
    }
  });
</script>
</body>
</html>
