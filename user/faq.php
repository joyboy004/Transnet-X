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
  <title>FAQ - Transnet X</title>
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
    .faq-item { border-bottom: 1px solid rgba(255,255,255,0.05); padding: 1.5rem 0; }
    .faq-item:last-child { border-bottom: none; }
    .faq-question { font-weight: 600; color: #fff; cursor: pointer; display: flex; justify-content: space-between; align-items: center; }
    .faq-answer { margin-top: 1rem; color: #9ca3af; display: none; line-height: 1.6; }
    .faq-item.active .faq-answer { display: block; }
    .faq-item.active .faq-icon { transform: rotate(180deg); }
    .faq-icon { transition: transform 0.3s ease; color: #3b82f6; }
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
            <h1 class="text-xl md:text-2xl font-bold text-white" style="font-family: 'Merriweather', serif;">Frequently Asked Questions</h1>
          </div>
        </div>
      </nav>

      <div class="flex-1 px-4 sm:px-6 lg:px-8 py-8 w-full max-w-3xl mx-auto">
        <div class="glass-card rounded-2xl p-8">
            <h2 class="text-2xl font-bold text-white mb-6">How can we help?</h2>
            
            <div class="faq-item" onclick="this.classList.toggle('active')">
                <div class="faq-question">
                    How do I book a ride?
                    <i data-lucide="chevron-down" class="faq-icon w-5 h-5"></i>
                </div>
                <div class="faq-answer">
                    You can book a ride by navigating to the "Uber" section from your Dashboard, entering your pickup and drop-off locations, selecting the vehicle type, and confirming the booking.
                </div>
            </div>

            <div class="faq-item" onclick="this.classList.toggle('active')">
                <div class="faq-question">
                    Can I cancel a flight booking?
                    <i data-lucide="chevron-down" class="faq-icon w-5 h-5"></i>
                </div>
                <div class="faq-answer">
                    Yes, flight bookings can be cancelled through the Activity History page. Please note that cancellation fees may apply depending on the airline and how close the cancellation is to the departure time. Refer to our Refund Policy for more details.
                </div>
            </div>

            <div class="faq-item" onclick="this.classList.toggle('active')">
                <div class="faq-question">
                    How does the SOS Emergency feature work?
                    <i data-lucide="chevron-down" class="faq-icon w-5 h-5"></i>
                </div>
                <div class="faq-answer">
                    The SOS button immediately records your location and alerts the necessary local authorities (Police, Ambulance, Fire Service). You can trigger it from the Emergency section. Use this only in actual emergencies.
                </div>
            </div>

            <div class="faq-item" onclick="this.classList.toggle('active')">
                <div class="faq-question">
                    What payment methods are accepted?
                    <i data-lucide="chevron-down" class="faq-icon w-5 h-5"></i>
                </div>
                <div class="faq-answer">
                    We accept major credit and debit cards, secure bank transfers, and integrated mobile money services. You can manage your payment methods in the Settings page.
                </div>
            </div>
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
