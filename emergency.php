<?php
session_start();
require_once '../config/db.php';

$account = null;
$account_role = 'user';
$dashboard_href = 'dashboard.php';

if (isset($_SESSION['user_id'])) {
  $uid = (int)$_SESSION['user_id'];
  $stmt = mysqli_prepare($conn, "SELECT name, email, phone FROM users WHERE id = ?");
  mysqli_stmt_bind_param($stmt, "i", $uid);
  mysqli_stmt_execute($stmt);
  $result = mysqli_stmt_get_result($stmt);
  $account = mysqli_fetch_assoc($result);
  $account_role = 'user';
} elseif (isset($_SESSION['driver_id'])) {
  $did = (int)$_SESSION['driver_id'];
  $dashboard_href = '../driver/dashboard.php';
  $stmt = mysqli_prepare($conn, "SELECT name, email, phone FROM drivers WHERE driver_id = ?");
  mysqli_stmt_bind_param($stmt, "i", $did);
  mysqli_stmt_execute($stmt);
  $result = mysqli_stmt_get_result($stmt);
  $account = mysqli_fetch_assoc($result);
  $account_role = 'driver';
} else {
  header('Location: ../index.php');
  exit();
}

if (!$account) {
  session_destroy();
  header('Location: ../index.php');
  exit();
}

$account_name = $account['name'] ?? 'User';
$account_email = $account['email'] ?? '';
$account_phone = $account['phone'] ?? '';
?>
<!doctype html>
<html lang="en" class="h-full">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Emergency SOS - Transnet X</title>
  
  <!-- Font Awesome for icons -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <script src="https://cdn.tailwindcss.com/3.4.17"></script>
  <script src="../assets/offline-icons.js"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Merriweather:wght@400;700&display=swap" rel="stylesheet">
  
  <style>
    /* Base styles */
    html, body { height: 100%; margin: 0; font-family: 'Inter', sans-serif; overflow-x: hidden; background: #0f1219; color: #e5e7eb; }
    
    @keyframes pulse-red {
      0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7); }
      70% { box-shadow: 0 0 0 20px rgba(239, 68, 68, 0); }
      100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
    }
    
    .panic-btn {
      animation: pulse-red 2s infinite;
      transition: all 0.3s ease;
    }
    .panic-btn:hover {
      transform: scale(1.05);
      background-color: #dc2626;
    }
    .panic-btn:active {
      transform: scale(0.95);
    }

    .glass-card {
        background: rgba(22, 27, 38, 0.7);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        border: 1px solid rgba(255, 255, 255, 0.05);
    }

    .service-card {
        transition: all 0.3s ease;
        cursor: pointer;
    }
    .service-card:hover {
        transform: translateY(-4px);
        border-color: rgba(239, 68, 68, 0.5);
        box-shadow: 0 10px 25px -5px rgba(239, 68, 68, 0.2);
    }

    /* Sidebar Styles */
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
  </style>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: { primary: '#3b82f6', primaryDark: '#1d4ed8' }
        }
      }
    }
  </script>
</head>
<body class="bg-[#0f1219] text-[#e5e7eb] h-full flex flex-col">

  <!-- Mobile overlay -->
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
            <p class="text-sm font-semibold text-white"><?= htmlspecialchars($account_name) ?></p>
            <p class="text-xs text-gray-400"><?= htmlspecialchars($account_email) ?></p>
          </div>
        </div>
      </div>
      
      <nav class="flex-1 py-6 px-4 space-y-1.5 overflow-y-auto">
        <a href="<?= htmlspecialchars($dashboard_href) ?>" class="nav-link flex items-center gap-3 px-4 py-2.5 rounded-lg text-gray-300 hover:bg-white/10 transition-all duration-200">
          <i data-lucide="layout-dashboard" class="w-5 h-5"></i> <span>Dashboard</span>
        </a>
        <a href="about.php" class="nav-link flex items-center gap-3 px-4 py-2.5 rounded-lg text-gray-300 hover:bg-white/10 transition-all duration-200">
          <i data-lucide="info" class="w-5 h-5"></i> <span>About Us</span>
        </a>
        <a href="settings.php" class="nav-link flex items-center gap-3 px-4 py-2.5 rounded-lg text-gray-300 hover:bg-white/10 transition-all duration-200">
          <i data-lucide="settings" class="w-5 h-5"></i> <span>Settings</span>
        </a>
        <a href="profile.php" class="nav-link flex items-center gap-3 px-4 py-2.5 rounded-lg text-gray-300 hover:bg-white/10 transition-all duration-200">
          <i data-lucide="user-circle" class="w-5 h-5"></i> <span>Profile</span>
        </a>
        <a href="records.php" class="nav-link flex items-center gap-3 px-4 py-2.5 rounded-lg text-gray-300 hover:bg-white/10 transition-all duration-200">
          <i data-lucide="history" class="w-5 h-5"></i> <span>Activity History</span>
        </a>
        <div class="pt-6 mt-6 border-t border-gray-200">
          <a href="../auth/logout.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-red-300 hover:bg-red-600/10 transition-all duration-200">
            <i data-lucide="log-out" class="w-5 h-5"></i> <span>Logout</span>
          </a>
        </div>
      </nav>
      
      <div class="p-4 border-t border-gray-200 text-xs text-gray-400">
        <p>© 2025 Transnet X. All rights reserved.</p>
      </div>
    </aside>

    <!-- MAIN CONTENT -->
    <div class="flex-1 flex flex-col overflow-auto relative">
      
      <!-- Top Navbar -->
      <nav class="sticky top-0 z-30 bg-[#0f1219]/90 backdrop-blur-md border-b border-[#242e42] shadow-sm">
        <div class="px-4 sm:px-6 lg:px-8 py-2 md:py-3 flex items-center justify-between">
          <div class="flex items-center gap-3">
            <button id="mobileMenuBtn" class="md:hidden p-2 rounded-lg text-gray-300 hover:bg-white/10 focus:outline-none">
              <i data-lucide="menu" class="w-6 h-6"></i>
            </button>
            <div class="w-10 h-10 rounded-lg bg-red-500/20 flex items-center justify-center text-red-500 md:hidden"><i data-lucide="alert-triangle" class="w-6 h-6"></i></div>
            <h1 class="text-xl md:text-2xl font-bold text-red-500" style="font-family: 'Merriweather', serif;">Emergency SOS</h1>
          </div>
          <div class="relative">
            <button id="profileBtn" class="flex items-center gap-2 px-4 py-2 rounded-lg bg-[#161b26] hover:bg-[#1f2937] transition-colors font-medium text-sm text-[#e5e7eb]">
              <i data-lucide="user" class="w-4 h-4"></i>
              <span class="hidden sm:inline"><?= htmlspecialchars($user_name) ?></span>
              <i data-lucide="chevron-down" class="w-4 h-4"></i>
            </button>
            <div id="dropdownMenu" class="hidden dropdown-menu absolute top-full right-0 mt-2 w-48 bg-[#121826] border border-[#2b3651] rounded-lg shadow-lg overflow-hidden z-20">
              <a href="dashboard.php" class="flex items-center gap-3 px-4 py-3 hover:bg-white/5 transition-colors text-gray-200">
                <i data-lucide="home" class="w-4 h-4"></i> <span>Dashboard</span>
              </a>
              <a href="../auth/logout.php" class="w-full text-left flex items-center gap-3 px-4 py-3 hover:bg-red-600/10 transition-colors text-red-300 font-medium border-t border-[#2b3651]">
                <i data-lucide="log-out" class="w-4 h-4"></i> <span>Logout</span>
              </a>
            </div>
          </div>
        </div>
      </nav>

      <!-- Content Area -->
      <div class="flex-1 px-4 sm:px-6 lg:px-8 py-8 w-full max-w-4xl mx-auto">
        
        <div class="text-center mb-10">
            <h2 class="text-3xl font-bold text-white mb-3">Are you in an emergency?</h2>
            <p class="text-gray-400">Press the panic button to immediately notify authorities and share your live location.</p>
        </div>

        <div class="flex justify-center mb-12">
            <button onclick="triggerSOS()" class="panic-btn w-48 h-48 rounded-full bg-red-600 text-white shadow-2xl flex flex-col items-center justify-center gap-3 border-4 border-red-500/50">
                <i data-lucide="shield-alert" class="w-16 h-16"></i>
                <span class="text-2xl font-bold tracking-widest uppercase">SOS</span>
            </button>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10">
            <!-- Police -->
            <a href="tel:112" class="glass-card service-card rounded-xl p-6 flex flex-col items-center text-center">
                <div class="w-16 h-16 rounded-full bg-blue-500/20 text-blue-500 flex items-center justify-center mb-4">
                    <i class="fas fa-shield-alt text-2xl"></i>
                </div>
                <h3 class="text-lg font-bold text-white mb-1">Police</h3>
                <p class="text-sm text-gray-400 mb-4">Call local police for security and crime</p>
                <span class="inline-block px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold w-full">Call 112</span>
            </a>

            <!-- Ambulance -->
            <a href="tel:112" class="glass-card service-card rounded-xl p-6 flex flex-col items-center text-center">
                <div class="w-16 h-16 rounded-full bg-red-500/20 text-red-500 flex items-center justify-center mb-4">
                    <i class="fas fa-ambulance text-2xl"></i>
                </div>
                <h3 class="text-lg font-bold text-white mb-1">Ambulance</h3>
                <p class="text-sm text-gray-400 mb-4">Medical emergencies and health crises</p>
                <span class="inline-block px-4 py-2 rounded-lg bg-red-600 hover:bg-red-700 text-white text-sm font-semibold w-full">Call 112</span>
            </a>

            <!-- Fire -->
            <a href="tel:112" class="glass-card service-card rounded-xl p-6 flex flex-col items-center text-center">
                <div class="w-16 h-16 rounded-full bg-orange-500/20 text-orange-500 flex items-center justify-center mb-4">
                    <i class="fas fa-fire-extinguisher text-2xl"></i>
                </div>
                <h3 class="text-lg font-bold text-white mb-1">Fire Service</h3>
                <p class="text-sm text-gray-400 mb-4">Fire emergencies and rescues</p>
                <span class="inline-block px-4 py-2 rounded-lg bg-orange-600 hover:bg-orange-700 text-white text-sm font-semibold w-full">Call 112</span>
            </a>
        </div>

        <div class="glass-card rounded-xl p-6 mb-10 border-l-4 border-l-amber-500">
            <h3 class="flex items-center gap-2 text-lg font-bold text-white mb-2">
                <i data-lucide="map-pin" class="text-amber-500"></i> Location Tracking
            </h3>
            <p class="text-gray-400 text-sm mb-4">For the best emergency response, Transnet X requires access to your current location. Make sure GPS is enabled on your device.</p>
            <div id="locationStatus" class="flex items-center gap-2 text-sm text-amber-500 font-medium">
                <i data-lucide="loader" class="animate-spin w-4 h-4"></i> Detecting location...
            </div>
        </div>

      </div>

    </div>
  </div>

  <script>
    // UI Interactions
    function toggleSidebar(show) {
      const sidebar = document.getElementById('sidebar');
      const overlay = document.getElementById('sidebarOverlay');
      if (show) {
        sidebar.classList.add('open');
        overlay.classList.add('show');
        document.body.style.overflow = 'hidden';
      } else {
        sidebar.classList.remove('open');
        overlay.classList.remove('show');
        document.body.style.overflow = '';
      }
    }
    document.getElementById('mobileMenuBtn')?.addEventListener('click', () => toggleSidebar(true));
    document.getElementById('sidebarOverlay')?.addEventListener('click', () => toggleSidebar(false));
    
    document.getElementById('profileBtn')?.addEventListener('click', (e) => {
      e.stopPropagation();
      document.getElementById('dropdownMenu')?.classList.toggle('hidden');
    });
    document.addEventListener('click', (e) => {
      const btn = document.getElementById('profileBtn');
      const menu = document.getElementById('dropdownMenu');
      if (!btn?.contains(e.target) && !menu?.contains(e.target)) menu?.classList.add('hidden');
    });

    // Geolocation
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            (position) => {
                const status = document.getElementById('locationStatus');
                status.innerHTML = `<i data-lucide="check-circle-2" class="w-4 h-4"></i> Location acquired (Lat: ${position.coords.latitude.toFixed(4)}, Lng: ${position.coords.longitude.toFixed(4)})`;
                status.className = "flex items-center gap-2 text-sm text-green-500 font-medium";
                renderOfflineIcons(document);
            },
            (error) => {
                const status = document.getElementById('locationStatus');
                status.innerHTML = `<i data-lucide="x-circle" class="w-4 h-4"></i> Unable to access location. Please check your permissions.`;
                status.className = "flex items-center gap-2 text-sm text-red-500 font-medium";
                renderOfflineIcons(document);
            }
        );
    } else {
        document.getElementById('locationStatus').innerHTML = "Geolocation is not supported by this browser.";
    }

    // SOS Action
    function triggerSOS() {
        if(confirm("Are you sure you want to trigger an Emergency SOS? This will alert authorities to your current location.")) {
            alert("SOS Triggered! Authorities and emergency contacts have been notified. Please stay calm and stay where you are if it's safe to do so.");
            const btn = document.querySelector('.panic-btn');
            btn.innerHTML = `<i data-lucide="check-circle-2" class="w-16 h-16"></i><span class="text-lg font-bold uppercase mt-2">Dispatched</span>`;
            btn.classList.remove('bg-red-600');
            btn.classList.add('bg-green-600', 'border-green-500');
            btn.style.animation = 'none';
            renderOfflineIcons(document);
        }
    }

    renderOfflineIcons(document);
  </script>
</body>
</html>
