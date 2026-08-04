<?php
session_start();
require_once '../config/db.php';

// Auth check
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

$uid = (int)$_SESSION['user_id'];

// Fetch user info
$stmt = mysqli_prepare($conn, "SELECT name, email FROM users WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $uid);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($result);

if (!$user) {
    session_destroy();
    header('Location: ../index.php');
    exit();
}
$user_name = explode(' ', $user['name'])[0];
$user_email = $user['email'];
?>
<!doctype html>
<html lang="en" class="h-full">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>About Us - Transnet X</title>
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
    .content-prose h2 { font-family: 'Merriweather', serif; color: #fff; font-size: 1.5rem; margin-top: 2rem; margin-bottom: 1rem; }
    .content-prose p { margin-bottom: 1rem; color: #9ca3af; line-height: 1.6; }
  </style>
</head>
<body class="bg-[#0f1219] text-[#e5e7eb] h-full flex flex-col">
  <div id="sidebarOverlay" class="sidebar-overlay" onclick="toggleSidebar(false)"></div>
  <div class="flex flex-1 overflow-hidden">
    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">
      <div class="p-6 border-b border-[#242e42] flex items-center gap-3">
        <div class="w-10 h-10 rounded-lg header-gradient flex items-center justify-center text-white shadow-md"><i data-lucide="compass" class="w-6 h-6"></i></div>
        <h2 class="text-xl font-bold text-white">Transnet X</h2>
      </div>
      <div class="p-4 border-b border-[#242e42] bg-[#161b26]">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-full bg-[#3b82f6]/15 flex items-center justify-center"><i data-lucide="user" class="w-5 h-5 text-[#3b82f6]"></i></div>
          <div>
            <p class="text-sm font-semibold text-white"><?= htmlspecialchars($user['name']) ?></p>
            <p class="text-xs text-gray-400"><?= htmlspecialchars($user_email) ?></p>
          </div>
        </div>
      </div>
      <nav class="flex-1 py-6 px-4 space-y-1.5 overflow-y-auto">
        <a href="dashboard.php" class="nav-link flex items-center gap-3 px-4 py-2.5 rounded-lg text-gray-300 hover:bg-white/10 transition-all duration-200"><i data-lucide="layout-dashboard" class="w-5 h-5"></i> <span>Dashboard</span></a>
        <a href="about.php" class="nav-link active flex items-center gap-3 px-4 py-2.5 rounded-lg text-gray-300 hover:bg-white/10 transition-all duration-200"><i data-lucide="info" class="w-5 h-5"></i> <span>About Us</span></a>
        <a href="settings.php" class="nav-link flex items-center gap-3 px-4 py-2.5 rounded-lg text-gray-300 hover:bg-white/10 transition-all duration-200"><i data-lucide="settings" class="w-5 h-5"></i> <span>Settings</span></a>
        <a href="profile.php" class="nav-link flex items-center gap-3 px-4 py-2.5 rounded-lg text-gray-300 hover:bg-white/10 transition-all duration-200"><i data-lucide="user-circle" class="w-5 h-5"></i> <span>Profile</span></a>
        <a href="records.php" class="nav-link flex items-center gap-3 px-4 py-2.5 rounded-lg text-gray-300 hover:bg-white/10 transition-all duration-200"><i data-lucide="history" class="w-5 h-5"></i> <span>Activity History</span></a>
          <a href="privacy.php" class="nav-link flex items-center gap-3 px-4 py-2.5 rounded-lg text-gray-300 hover:bg-white/10 transition-all duration-200"><i data-lucide="shield" class="w-5 h-5"></i> <span>Privacy</span></a>
          <a href="terms.php" class="nav-link flex items-center gap-3 px-4 py-2.5 rounded-lg text-gray-300 hover:bg-white/10 transition-all duration-200"><i data-lucide="file-text" class="w-5 h-5"></i> <span>Terms</span></a>
          <a href="contact.php" class="nav-link flex items-center gap-3 px-4 py-2.5 rounded-lg text-gray-300 hover:bg-white/10 transition-all duration-200"><i data-lucide="mail" class="w-5 h-5"></i> <span>Contact</span></a>
          <a href="emergency.php" class="nav-link flex items-center gap-3 px-4 py-2.5 rounded-lg text-gray-300 hover:bg-white/10 transition-all duration-200"><i data-lucide="alert-triangle" class="w-5 h-5"></i> <span>Emergency</span></a>
        <div class="pt-6 mt-6 border-t border-gray-200">
          <a href="../auth/logout.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-red-300 hover:bg-red-600/10 transition-all duration-200"><i data-lucide="log-out" class="w-5 h-5"></i> <span>Logout</span></a>
        </div>
      </nav>
      <div class="p-4 border-t border-gray-200 text-xs text-gray-400"><p>© 2025 Transnet X. All rights reserved.</p></div>
    </aside>

    <!-- MAIN CONTENT -->
    <div class="flex-1 flex flex-col overflow-auto relative">
      <nav class="sticky top-0 z-30 bg-[#0f1219]/90 backdrop-blur-md border-b border-[#242e42] shadow-sm">
        <div class="px-4 sm:px-6 lg:px-8 py-2 md:py-3 flex items-center justify-between">
          <div class="flex items-center gap-3">
            <button id="mobileMenuBtn" class="md:hidden p-2 rounded-lg text-gray-300 hover:bg-white/10 focus:outline-none"><i data-lucide="menu" class="w-6 h-6"></i></button>
            <div class="w-10 h-10 rounded-lg header-gradient flex items-center justify-center text-white md:hidden"><i data-lucide="info" class="w-6 h-6"></i></div>
            <h1 class="text-xl md:text-2xl font-bold text-white" style="font-family: 'Merriweather', serif;">About Us</h1>
          </div>
          <div class="relative">
            <button id="profileBtn" class="flex items-center gap-2 px-4 py-2 rounded-lg bg-[#161b26] hover:bg-[#1f2937] transition-colors font-medium text-sm text-[#e5e7eb]">
              <i data-lucide="user" class="w-4 h-4"></i><span class="hidden sm:inline"><?= htmlspecialchars($user_name) ?></span><i data-lucide="chevron-down" class="w-4 h-4"></i>
            </button>
            <div id="dropdownMenu" class="hidden dropdown-menu absolute top-full right-0 mt-2 w-48 bg-[#121826] border border-[#2b3651] rounded-lg shadow-lg overflow-hidden z-20">
              <a href="dashboard.php" class="flex items-center gap-3 px-4 py-3 hover:bg-white/5 transition-colors text-gray-200"><i data-lucide="home" class="w-4 h-4"></i> <span>Dashboard</span></a>
              <a href="../auth/logout.php" class="w-full text-left flex items-center gap-3 px-4 py-3 hover:bg-red-600/10 transition-colors text-red-300 font-medium border-t border-[#2b3651]"><i data-lucide="log-out" class="w-4 h-4"></i> <span>Logout</span></a>
            </div>
          </div>
        </div>
      </nav>

      <div class="flex-1 px-4 sm:px-6 lg:px-8 py-8 w-full max-w-4xl mx-auto">
        <div class="glass-card rounded-2xl p-8 md:p-12 shadow-2xl content-prose">
          <h1 class="text-3xl font-bold text-white mb-6" style="font-family: 'Merriweather', serif;">Welcome to Transnet X</h1>
          <p class="text-lg">Transnet X is a next-generation mobility and lifestyle platform designed to streamline your daily movements and essential services.</p>
          
          <h2>Our Mission</h2>
          <p>We believe that moving from point A to point B shouldn't be a hassle. It should be seamless, reliable, and integrated. Our mission is to combine ride-hailing, intercity transit, vehicle rentals, and emergency services into one beautifully designed platform.</p>
          
          <h2>What We Offer</h2>
          <ul class="list-disc pl-5 text-gray-400 space-y-2 mb-6">
            <li><strong>Rides & Transit:</strong> Fast and reliable local rides, plus seamless bookings for bus, train, and flight tickets.</li>
            <li><strong>Vehicle Rentals & Sales:</strong> Direct access to premium rental vehicles and verified vehicles for purchase.</li>
            <li><strong>Lifestyle Services:</strong> Quick food orders and on-demand package delivery.</li>
            <li><strong>Safety First:</strong> An integrated SOS emergency system to keep you secure at all times.</li>
          </ul>
          
          <h2>Our Promise</h2>
          <p>We prioritize user experience, reliability, and security. Every interaction with Transnet X is designed to be frictionless, giving you peace of mind so you can focus on what matters most.</p>
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
