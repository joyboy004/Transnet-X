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
$user_name = explode(' ', $user['name'])[0];
$user_email = $user['email'];
?>
<!doctype html>
<html lang="en" class="h-full">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Refund Policy - Transnet X</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <script src="https://cdn.tailwindcss.com/3.4.17"></script>
  <script src="../assets/offline-icons.js"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Merriweather:wght@400;700&display=swap" rel="stylesheet">
  <style>
    html, body { height: 100%; margin: 0; font-family: 'Inter', sans-serif; overflow-x: hidden; background: #0f1219; color: #e5e7eb; }
    .glass-card { background: rgba(22, 27, 38, 0.7); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.05); }
    .sidebar { width: 280px; background: #161b26; border-right: 1px solid #242e42; display: flex; flex-direction: column; transition: transform 0.3s ease; z-index: 40; }
    @media (max-width: 768px) {
      .sidebar { position: fixed; top: 0; left: 0; height: 100%; transform: translateX(-100%); }
      .sidebar.open { transform: translateX(0); }
      .sidebar-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 35; opacity: 0; visibility: hidden; transition: 0.2s; }
      .sidebar-overlay.show { opacity: 1; visibility: visible; }
    }
    .nav-link.active { background: #3b82f6; color: #fff; }
    .nav-link.active i { color: #fff; }
    .nav-link { color: #cbd5e1; transition: all 0.2s; }
    .nav-link:hover { background: rgba(255,255,255,0.08); color: #fff; }
    .nav-link:hover i { color: #fff; }
    .header-gradient { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
    .content-prose h2 { font-family: 'Inter', sans-serif; font-weight: 600; color: #fff; font-size: 1.25rem; margin-top: 2rem; margin-bottom: 0.75rem; }
    .content-prose p { margin-bottom: 1rem; color: #9ca3af; line-height: 1.6; }
    .content-prose ul { margin-bottom: 1rem; padding-left: 1.5rem; color: #9ca3af; list-style-type: disc; }
  </style>
</head>
<body class="bg-[#0f1219] text-[#e5e7eb] h-full flex flex-col">
  <div id="sidebarOverlay" class="sidebar-overlay" onclick="toggleSidebar(false)"></div>
  <div class="flex flex-1 overflow-hidden">
    <aside class="sidebar" id="sidebar">
      <div class="p-6 border-b border-[#242e42] flex items-center gap-3">
        <div class="w-10 h-10 rounded-lg header-gradient flex items-center justify-center text-white shadow-md"><i data-lucide="compass" class="w-6 h-6"></i></div>
        <h2 class="text-xl font-bold text-white">Transnet X</h2>
      </div>
      <div class="p-4 border-b border-[#242e42] bg-[#161b26]">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-full bg-[#3b82f6]/15 flex items-center justify-center"><i data-lucide="user" class="w-5 h-5 text-[#3b82f6]"></i></div>
          <div><p class="text-sm font-semibold text-white"><?= htmlspecialchars($user['name']) ?></p><p class="text-xs text-gray-400"><?= htmlspecialchars($user_email) ?></p></div>
        </div>
      </div>
      <nav class="flex-1 py-6 px-4 space-y-1.5 overflow-y-auto">
        <a href="dashboard.php" class="nav-link flex items-center gap-3 px-4 py-2.5 rounded-lg text-gray-300 hover:bg-white/10 transition-all duration-200"><i data-lucide="layout-dashboard" class="w-5 h-5"></i> <span>Dashboard</span></a>
        <a href="about.php" class="nav-link flex items-center gap-3 px-4 py-2.5 rounded-lg text-gray-300 hover:bg-white/10 transition-all duration-200"><i data-lucide="info" class="w-5 h-5"></i> <span>About Us</span></a>
        <a href="settings.php" class="nav-link flex items-center gap-3 px-4 py-2.5 rounded-lg text-gray-300 hover:bg-white/10 transition-all duration-200"><i data-lucide="settings" class="w-5 h-5"></i> <span>Settings</span></a>
        <div class="pt-6 mt-6 border-t border-gray-200">
        </div>
      </nav>
      <div class="p-4 border-t border-gray-200 text-xs text-gray-400"><p>© 2025 Transnet X. All rights reserved.</p></div>
    </aside>

    <div class="flex-1 flex flex-col overflow-auto relative">
      <nav class="sticky top-0 z-30 bg-[#0f1219]/90 backdrop-blur-md border-b border-[#242e42] shadow-sm">
        <div class="px-4 sm:px-6 lg:px-8 py-2 md:py-3 flex items-center justify-between">
          <div class="flex items-center gap-3">
            <button id="mobileMenuBtn" class="md:hidden p-2 rounded-lg text-gray-300 hover:bg-white/10 focus:outline-none"><i data-lucide="menu" class="w-6 h-6"></i></button>
            <h1 class="text-xl md:text-2xl font-bold text-white" style="font-family: 'Merriweather', serif;">Refund Policy</h1>
          </div>
          <div class="relative">
            
            </button>
            <div id="dropdownMenu" class="hidden dropdown-menu absolute top-full right-0 mt-2 w-48 bg-[#121826] border border-[#2b3651] rounded-lg shadow-lg overflow-hidden z-20">
            </div>
          </div>
        </div>
      </nav>

      <div class="flex-1 px-4 sm:px-6 lg:px-8 py-8 w-full max-w-4xl mx-auto">
        <div class="glass-card rounded-2xl p-8 md:p-12 content-prose">
          <h1 class="text-3xl font-bold text-white mb-2" style="font-family: 'Merriweather', serif;">Refund Policy</h1>
          <p class="text-sm mb-6 pb-6 border-b border-gray-800">Last updated: May 2026</p>
          
          <p>We want you to be satisfied with our services. Our refund policy applies differently depending on the service you have used. Please read carefully before requesting a refund.</p>

          <h2>1. Rides (Uber/Taxi)</h2>
          <p>If you cancel a ride request within 5 minutes of booking, no cancellation fee will be charged. If you cancel after 5 minutes, a standard cancellation fee applies. Refunds for completed rides are only issued in cases of verified route deviations or driver misconduct.</p>

          <h2>2. Flights and Intercity Trips</h2>
          <p>Cancellations made 24 hours prior to departure are eligible for a 90% refund. Cancellations made within 24 hours are non-refundable but may be eligible for a ticket exchange, subject to availability and a processing fee.</p>

          <h2>3. Food Orders and Delivery</h2>
          <p>Food orders cannot be cancelled once the restaurant has started preparation. If an order is delivered incorrect or damaged, please contact Support within 1 hour with photo evidence for a full refund or replacement.</p>

          <h2>4. Vehicle Rentals</h2>
          <p>Rental cancellations made at least 48 hours in advance will receive a full refund. Cancellations within 48 hours will be charged a one-day rental fee.</p>

          <h2>5. How to Request a Refund</h2>
          <p>To request a refund, please navigate to the Activity History page, select the specific transaction, and click on "Report Issue". Alternatively, you can contact us at <a href="mailto:refunds@transnetx.com" class="text-primary hover:underline">refunds@transnetx.com</a>.</p>
        </div>
      </div>
    </div>
  </div>

  <script>
    function toggleSidebar(show) {
      const sidebar = document.getElementById('sidebar');
      const overlay = document.getElementById('sidebarOverlay');
      if (show) { sidebar.classList.add('open'); overlay.classList.add('show'); document.body.style.overflow = 'hidden'; } 
      else { sidebar.classList.remove('open'); overlay.classList.remove('show'); document.body.style.overflow = ''; }
    }
    document.getElementById('mobileMenuBtn')?.addEventListener('click', () => toggleSidebar(true));
    document.getElementById('sidebarOverlay')?.addEventListener('click', () => toggleSidebar(false));
    document.getElementById('profileBtn')?.addEventListener('click', (e) => {
      e.stopPropagation(); document.getElementById('dropdownMenu')?.classList.toggle('hidden');
    });
    document.addEventListener('click', (e) => {
      const btn = document.getElementById('profileBtn');
      const menu = document.getElementById('dropdownMenu');
      if (!btn?.contains(e.target) && !menu?.contains(e.target)) menu?.classList.add('hidden');
    });
  </script>
</body>
</html>
