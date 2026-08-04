<?php
session_start();
require_once '../config/db.php';

// <i data-lucide="check-circle-2" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i> Auth check
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

$uid = (int)$_SESSION['user_id'];

// <i data-lucide="check-circle-2" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i> Fetch user info (name + email for sidebar)
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

// ============================================================
// FETCH ALL ACTIVITIES FROM VARIOUS TABLES (matched to schema)
// ============================================================
$activities = [];

// 1. Ride Bookings (uber)
$stmt = mysqli_prepare($conn, "
    SELECT 
        'uber' as type,
        CONCAT(pickup_location, ' → ', dropoff_location) as title,
        DATE(created_at) as date,
        TIME(created_at) as time,
        CONCAT(ride_type, ' · ', COALESCE(notes, 'No notes')) as details,
        status,
        '<i class=\"fas fa-car\"></i>' as icon,
        CONCAT('₦', FORMAT(fare, 0)) as amount
    FROM bookings
    WHERE user_id = ?
    ORDER BY created_at DESC
");
mysqli_stmt_bind_param($stmt, "i", $uid);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($res)) {
    $activities[] = $row;
}

// 2. Flight Bookings (with flight details)
$stmt = mysqli_prepare($conn, "
    SELECT 
        'flight' as type,
        CONCAT(f.origin_city, ' → ', f.dest_city) as title,
        DATE(fb.created_at) as date,
        TIME(fb.created_at) as time,
        CONCAT(f.airline, ' ', f.flight_number, ' · Seat ', fb.seat_number) as details,
        fb.status,
        '<i class=\"fas fa-plane\"></i>' as icon,
        CONCAT('₦', FORMAT(fb.total_price, 0)) as amount
    FROM flight_bookings fb
    JOIN flights f ON fb.flight_id = f.id
    WHERE fb.user_id = ?
    ORDER BY fb.created_at DESC
");
mysqli_stmt_bind_param($stmt, "i", $uid);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($res)) {
    $activities[] = $row;
}

// 3. Trip Bookings (bus/train/sea trips)
$stmt = mysqli_prepare($conn, "
    SELECT 
        'trip' as type,
        CONCAT(t.from_city, ' → ', t.to_city) as title,
        DATE(tb.created_at) as date,
        TIME(tb.created_at) as time,
        CONCAT(t.transport_type, ' · Seat ', tb.seat_number) as details,
        tb.status,
        '<i class=\"fas fa-map-marked-alt\"></i>' as icon,
        CONCAT('₦', FORMAT(tb.total_price, 0)) as amount
    FROM trip_bookings tb
    JOIN trips t ON tb.trip_id = t.id
    WHERE tb.user_id = ?
    ORDER BY tb.created_at DESC
");
mysqli_stmt_bind_param($stmt, "i", $uid);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($res)) {
    $activities[] = $row;
}

// 4. Vehicle Rentals
$stmt = mysqli_prepare($conn, "
    SELECT 
        'rental' as type,
        CONCAT(rv.make, ' ', rv.model, ' (', rv.year, ')') as title,
        DATE(r.created_at) as date,
        TIME(r.created_at) as time,
        CONCAT(r.pickup_location, ' · ', r.total_days, ' day(s)') as details,
        r.status,
        '<i class=\"fas fa-car-side\"></i>' as icon,
        CONCAT('₦', FORMAT(r.total_price, 0)) as amount
    FROM rentals r
    JOIN rental_vehicles rv ON r.vehicle_id = rv.id
    WHERE r.user_id = ?
    ORDER BY r.created_at DESC
");
mysqli_stmt_bind_param($stmt, "i", $uid);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($res)) {
    $activities[] = $row;
}

// 5. Food Orders (with user name and food item)
$stmt = mysqli_prepare($conn, "
    SELECT 
        'food' as type,
        CONCAT('Order #', o.id) as title,
        DATE(o.created_at) as date,
        TIME(o.created_at) as time,
        CONCAT(u.name, ' · ', f.name, ' x', o.quantity, ' · ', o.dropoff_address) as details,
        o.status,
        '<i class=\"fas fa-utensils\"></i>' as icon,
        CONCAT('₦', FORMAT(o.total_price, 0)) as amount
    FROM orders o
    JOIN users u ON o.user_id = u.id
    JOIN foods f ON o.food_id = f.id
    WHERE o.user_id = ?
    ORDER BY o.created_at DESC
");
mysqli_stmt_bind_param($stmt, "i", $uid);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($res)) {
    $activities[] = $row;
}

// 6. Vehicle Purchases (with vehicle details)
$stmt = mysqli_prepare($conn, "
    SELECT 
        'purchase' as type,
        CONCAT(v.make, ' ', v.model) as title,
        DATE(p.created_at) as date,
        TIME(p.created_at) as time,
        CONCAT(p.full_name, ' · ', p.delivery_state, ' · ', p.payment_plan) as details,
        p.status,
        '<i class=\"fas fa-shopping-bag\"></i>' as icon,
        CONCAT('₦', FORMAT(p.amount, 0)) as amount
    FROM purchases p
    JOIN vehicles v ON p.vehicle_id = v.id
    WHERE p.user_id = ?
    ORDER BY p.created_at DESC
");
mysqli_stmt_bind_param($stmt, "i", $uid);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($res)) {
    $activities[] = $row;
}

// Sort all activities by date descending (most recent first)
usort($activities, function($a, $b) {
    return strtotime($b['date'] . ' ' . $b['time']) - strtotime($a['date'] . ' ' . $a['time']);
});

// Convert to JSON for JavaScript
$activities_json = json_encode($activities);
?>
<!doctype html>
<html lang="en" class="h-full">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Activity History</title>
  <!-- Font Awesome for icons -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <script src="https://cdn.tailwindcss.com/3.4.17"></script>
  <script src="../assets/offline-icons.js"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&amp;family=Merriweather:wght@400;700&amp;display=swap" rel="stylesheet">
  <style>
    /* Base styles */
    html, body { height: 100%; margin: 0; font-family: 'Inter', sans-serif; overflow-x: hidden; background: #0f1219; color: #e5e7eb; }
    @keyframes slideInUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
    @keyframes slideInLeft { from { opacity: 0; transform: translateX(-30px); } to { opacity: 1; transform: translateX(0); } }
    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
    @keyframes scaleIn { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }
    .slide-in-up { animation: slideInUp 0.6s ease-out; }
    .slide-in-left { animation: slideInLeft 0.6s ease-out; }
    .fade-in { animation: fadeIn 0.5s ease-out; }
    .scale-in { animation: scaleIn 0.4s ease-out; }
    .record-card { transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); border-left: 4px solid transparent; background: #161b26; color: #e5e7eb; border: 1px solid rgba(148,163,184,0.1); }
    .record-card:hover { transform: translateX(4px) translateY(-2px); box-shadow: 0 12px 40px rgba(59, 130, 246, 0.15); }
    .record-card.flight { border-left-color: #3b82f6; }
    .record-card.trip { border-left-color: #10b981; }
    .record-card.uber { border-left-color: #f59e0b; }
    .record-card.rental { border-left-color: #8b5cf6; }
    .record-card.purchase { border-left-color: #06b6d4; }
    .record-card.food { border-left-color: #f97316; }
    /* Status badges */
    .status-badge { transition: all 0.3s ease; display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.4rem 0.8rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; }
    .status-badge.completed, .status-badge.delivered, .status-badge.approved, .status-badge.returned { background: rgba(16, 185, 129, 0.1); color: #059669; }
    .status-badge.pending, .status-badge.processing { background: rgba(245, 158, 11, 0.1); color: #d97706; }
    .status-badge.cancelled, .status-badge.rejected, .status-badge.declined { background: rgba(239, 68, 68, 0.1); color: #dc2626; }
    .status-badge.in-progress, .status-badge.active, .status-badge.confirmed, .status-badge.accepted, .status-badge.dispatched { background: rgba(59, 130, 246, 0.1); color: #1d4ed8; }
    .search-input { transition: all 0.3s ease; background: #161b26; color: #e5e7eb; border-color: rgba(148,163,184,0.15); }
    .search-input::placeholder { color: #94a3b8; }
    .search-input:focus { outline: none; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1), inset 0 0 0 1px rgba(59, 130, 246, 0.3); }
    .dropdown-menu { animation: slideInUp 0.3s ease-out; transform-origin: top right; }
    .type-chip { transition: all 0.3s ease; cursor: pointer; }
    .type-chip:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(59, 130, 246, 0.2); }
    .type-chip.active { background: #3b82f6; color: white; box-shadow: 0 8px 20px rgba(59, 130, 246, 0.3); }
    .icon-badge { display: flex; align-items: center; justify-content: center; width: 3rem; height: 3rem; border-radius: 12px; font-size: 1.5rem; }
    .icon-badge.flight { background: rgba(59, 130, 246, 0.1); }
    .icon-badge.trip { background: rgba(16, 185, 129, 0.1); }
    .icon-badge.uber { background: rgba(245, 158, 11, 0.1); }
    .icon-badge.rental { background: rgba(139, 92, 246, 0.1); }
    .icon-badge.purchase { background: rgba(6, 182, 212, 0.1); }
    .icon-badge.food { background: rgba(249, 115, 22, 0.1); }
    ::-webkit-scrollbar { width: 8px; }
    ::-webkit-scrollbar-track { background: #f3f4f6; }
    ::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 4px; }
    ::-webkit-scrollbar-thumb:hover { background: #9ca3af; }
    .header-gradient { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
    input::placeholder { color: #9ca3af; }
    
    /* Sidebar Styles - clean, fixed width on desktop */
    .sidebar {
      width: 280px;
      background: #161b26;
      border-right: 1px solid #242e42;
      display: flex;
      flex-direction: column;
      transition: transform 0.3s ease;
      z-index: 40;
    }
    @media (max-width: 768px) {
      .sidebar {
        position: fixed;
        top: 0;
        left: 0;
        height: 100%;
        transform: translateX(-100%);
      }
      .sidebar.open {
        transform: translateX(0);
      }
      .sidebar-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.4);
        z-index: 35;
        opacity: 0;
        visibility: hidden;
        transition: 0.2s;
      }
      .sidebar-overlay.show {
        opacity: 1;
        visibility: visible;
      }
    }
    .nav-link.active {
      background: #3b82f6;
      color: #fff;
    }
    .nav-link.active i {
      color: #fff;
    }
    .nav-link {
      color: #cbd5e1;
      transition: all 0.2s;
    }
    .nav-link:hover {
      background: rgba(255,255,255,0.08);
      color: #fff;
    }
    .nav-link:hover i {
      color: #fff;
    }
  </style>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            primary: '#3b82f6',
            primaryDark: '#1d4ed8',
          }
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
      
      <!-- User Info -->
      <div class="p-4 border-b border-[#242e42] bg-[#161b26]">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-full bg-[#3b82f6]/15 flex items-center justify-center"><i data-lucide="user" class="w-5 h-5 text-[#3b82f6]"></i></div>
          <div>
            <p class="text-sm font-semibold text-white"><?= htmlspecialchars($user['name']) ?></p>
            <p class="text-xs text-gray-400"><?= htmlspecialchars($user_email) ?></p>
          </div>
        </div>
      </div>
      
      <!-- Navigation - using valid Lucide icons -->
      <nav class="flex-1 py-6 px-4 space-y-1.5 overflow-y-auto">
        <a href="dashboard.php" class="nav-link flex items-center gap-3 px-4 py-2.5 rounded-lg text-gray-300 hover:bg-white/10 transition-all duration-200">
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
          <a href="privacy.php" class="nav-link flex items-center gap-3 px-4 py-2.5 rounded-lg text-gray-300 hover:bg-white/10 transition-all duration-200"><i data-lucide="shield" class="w-5 h-5"></i> <span>Privacy</span></a>
          <a href="terms.php" class="nav-link flex items-center gap-3 px-4 py-2.5 rounded-lg text-gray-300 hover:bg-white/10 transition-all duration-200"><i data-lucide="file-text" class="w-5 h-5"></i> <span>Terms</span></a>
          <a href="contact.php" class="nav-link flex items-center gap-3 px-4 py-2.5 rounded-lg text-gray-300 hover:bg-white/10 transition-all duration-200"><i data-lucide="mail" class="w-5 h-5"></i> <span>Contact</span></a>
          <a href="emergency.php" class="nav-link flex items-center gap-3 px-4 py-2.5 rounded-lg text-gray-300 hover:bg-white/10 transition-all duration-200"><i data-lucide="alert-triangle" class="w-5 h-5"></i> <span>Emergency</span></a>
        <a href="activity_history.php" class="nav-link active flex items-center gap-3 px-4 py-2.5 rounded-lg bg-primary text-white shadow-sm transition-all duration-200">
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

    <!-- MAIN CONTENT (flex-1 to take remaining space) -->
    <div class="flex-1 flex flex-col overflow-auto">
      <!-- Top Navbar -->
      <nav class="sticky top-0 z-30 bg-[#0f1219] border-b border-[#242e42] shadow-sm">
        <div class="px-4 sm:px-6 lg:px-8 py-2 md:py-3 flex items-center justify-between">
          <div class="flex items-center gap-3">
            <button id="mobileMenuBtn" class="md:hidden p-2 rounded-lg text-gray-300 hover:bg-white/10 focus:outline-none">
              <i data-lucide="menu" class="w-6 h-6"></i>
            </button>
            <div class="w-10 h-10 rounded-lg header-gradient flex items-center justify-center text-white shadow-md md:hidden"><i data-lucide="history" class="w-6 h-6"></i></div>
            <h1 class="text-xl md:text-2xl font-bold text-white" style="font-family: 'Merriweather', serif;">Records</h1>
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

      <!-- Content Area - no extra spacing -->
        <div class="flex-1 px-4 sm:px-6 lg:px-8 py-6 w-full">
        <div class="mb-6 slide-in-up">
          <h2 class="text-gray-300 text-base md:text-lg">View and manage all your records</h2>
        </div>

        <!-- Search & Filters -->
        <div class="mb-6 slide-in-up">
          <div class="flex flex-col gap-4">
            <div class="relative">
              <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400"></i>
              <input id="searchInput" type="text" placeholder="Search by type, name, or details..." class="w-full pl-10 pr-4 py-3 border border-[#242e42] rounded-lg search-input">
            </div>
            <div class="flex flex-wrap gap-2">
              <button onclick="filterByType('all')" class="type-chip active px-4 py-2 rounded-full bg-primary text-white font-medium text-sm"> All Records </button>
              <button onclick="filterByType('uber')" class="type-chip px-4 py-2 rounded-full bg-amber-100 text-amber-700 font-medium text-sm hover:bg-amber-200"> <i class="fas fa-car"></i> Uber Rides </button>
              <button onclick="filterByType('flight')" class="type-chip px-4 py-2 rounded-full bg-blue-100 text-blue-700 font-medium text-sm hover:bg-blue-200"> <i class="fas fa-plane"></i> Flights </button>
              <button onclick="filterByType('trip')" class="type-chip px-4 py-2 rounded-full bg-green-100 text-green-700 font-medium text-sm hover:bg-green-200"> <i class="fas fa-map-marked-alt"></i> Trips </button>
              <button onclick="filterByType('rental')" class="type-chip px-4 py-2 rounded-full bg-purple-100 text-purple-700 font-medium text-sm hover:bg-purple-200"> <i class="fas fa-car-side"></i> Rentals </button>
              <button onclick="filterByType('food')" class="type-chip px-4 py-2 rounded-full bg-orange-100 text-orange-700 font-medium text-sm hover:bg-orange-200"> <i class="fas fa-utensils"></i> Food Orders </button>
              <button onclick="filterByType('purchase')" class="type-chip px-4 py-2 rounded-full bg-cyan-100 text-cyan-700 font-medium text-sm hover:bg-cyan-200"> <i class="fas fa-shopping-bag"></i> Car Purchases </button>
            </div>
          </div>
        </div>

        <!-- Records List -->
        <div id="recordsContainer" class="space-y-4"></div>
        <div id="emptyState" class="hidden text-center py-16">
          <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-[#161b26] mb-4"><i data-lucide="inbox" class="w-8 h-8 text-gray-400"></i></div>
          <h3 class="text-lg font-semibold text-white mb-2">No records found</h3>
          <p class="text-gray-400">Try adjusting your search or filters</p>
        </div>
      </div>

      <!-- Footer -->
      <footer class="bg-[#0f1219] border-t border-[#242e42] mt-auto text-gray-300">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
          <div class="grid grid-cols-1 md:grid-cols-4 gap-8">
            <div>
              <div class="flex items-center gap-2 mb-4">
                <div class="w-8 h-8 rounded-lg header-gradient flex items-center justify-center text-white"><i data-lucide="compass" class="w-4 h-4"></i></div>
                <span class="font-bold text-gray-800">Transnet X</span>
              </div>
              <p class="text-sm text-gray-600">Your one-stop travel & lifestyle platform. Book rides, flights, trips, rentals, and more with ease.</p>
            </div>
            <div>
              <h4 class="font-semibold text-gray-800 mb-3">Quick Links</h4>
              <ul class="space-y-2 text-sm text-gray-600">
                <li><a href="about.php" class="hover:text-primary transition">About Us</a></li>
                <li><a href="contact.php" class="hover:text-primary transition">Contact</a></li>
                <li><a href="privacy.php" class="hover:text-primary transition">Privacy Policy</a></li>
                <li><a href="terms.php" class="hover:text-primary transition">Terms of Service</a></li>
              </ul>
            </div>
            <div>
              <h4 class="font-semibold text-gray-800 mb-3">Support</h4>
              <ul class="space-y-2 text-sm text-gray-600">
                <li><a href="faq.php" class="hover:text-primary transition">FAQ</a></li>
                <li><a href="help.php" class="hover:text-primary transition">Help Center</a></li>
                <li><a href="refund.php" class="hover:text-primary transition">Refund Policy</a></li>
              </ul>
            </div>
            <div>
              <h4 class="font-semibold text-gray-800 mb-3">Connect With Us</h4>
              <div class="flex gap-4">
                <a href="#" class="text-gray-500 hover:text-primary transition"><i class="fab fa-facebook-f text-xl"></i></a>
                <a href="#" class="text-gray-500 hover:text-primary transition"><i class="fab fa-twitter text-xl"></i></a>
                <a href="#" class="text-gray-500 hover:text-primary transition"><i class="fab fa-instagram text-xl"></i></a>
                <a href="#" class="text-gray-500 hover:text-primary transition"><i class="fab fa-linkedin-in text-xl"></i></a>
              </div>
              <p class="text-xs text-gray-400 mt-4">© 2025 Transnet X. All rights reserved.</p>
            </div>
          </div>
        </div>
      </footer>
    </div>
  </div>

<script>
const allRecords = <?= $activities_json ?: '[]' ?>;

function getStatusClass(status) {
  const completedStates = ['completed', 'delivered', 'approved', 'returned'];
  const pendingStates = ['pending', 'processing'];
  const cancelledStates = ['cancelled', 'rejected', 'declined'];
  const progressStates = ['in-progress', 'active', 'confirmed', 'accepted', 'dispatched'];
  if (completedStates.includes(status)) return 'completed';
  if (pendingStates.includes(status)) return 'pending';
  if (cancelledStates.includes(status)) return 'cancelled';
  if (progressStates.includes(status)) return 'in-progress';
  return status;
}

function getStatusIcon(status) {
  const cls = getStatusClass(status);
  if (cls === 'completed') return 'check-circle-2';
  if (cls === 'pending') return 'clock';
  if (cls === 'cancelled') return 'x-circle';
  if (cls === 'in-progress') return 'loader';
  return 'circle';
}

allRecords.forEach((record, index) => { if (!record.id) record.id = index + 1; });

let currentFilter = 'all';
let filteredRecords = [...allRecords];

function formatDate(dateStr) {
  const date = new Date(dateStr);
  return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function renderRecords() {
  const container = document.getElementById('recordsContainer');
  const emptyState = document.getElementById('emptyState');
  if (filteredRecords.length === 0) {
    container.innerHTML = '';
    emptyState.classList.remove('hidden');
    return;
  }
  emptyState.classList.add('hidden');
  container.innerHTML = filteredRecords.map((record, i) => {
    const statusClass = getStatusClass(record.status);
    const statusIcon = getStatusIcon(record.status);
    return `
    <div class="record-card ${record.type} bg-white rounded-lg p-5 border border-gray-200 shadow-sm scale-in" style="animation-delay: ${i * 0.05}s">
      <div class="flex items-start gap-4">
        <div class="icon-badge ${record.type}">${record.icon}</div>
        <div class="flex-1 min-w-0">
          <div class="flex items-start justify-between gap-2 mb-1">
            <h3 class="text-base font-semibold text-gray-900">${escapeHtml(record.title)}</h3>
            <span class="status-badge ${statusClass}">
              <i data-lucide="${statusIcon}" class="w-3.5 h-3.5"></i>
              ${escapeHtml(record.status.charAt(0).toUpperCase() + record.status.slice(1).replace('-', ' '))}
            </span>
          </div>
          <p class="text-sm text-gray-600 mb-2">${escapeHtml(record.details)}</p>
          <div class="flex items-center justify-between text-xs text-gray-500">
            <span><i class="fas fa-calendar-alt"></i> ${formatDate(record.date)} at ${record.time}</span>
            <span class="font-semibold text-gray-900">${escapeHtml(record.amount)}</span>
          </div>
        </div>
      </div>
    </div>`;
  }).join('');
  renderOfflineIcons(document);
}

function filterByType(type) {
  currentFilter = type;
  document.querySelectorAll('.type-chip').forEach(chip => {
    chip.classList.remove('active', 'bg-primary', 'text-white');
  });
  const activeChip = document.querySelector(`button[onclick="filterByType('${type}')"]`);
  if (activeChip) activeChip.classList.add('active', 'bg-primary', 'text-white');
  filteredRecords = (type === 'all') ? [...allRecords] : allRecords.filter(r => r.type === type);
  renderRecords();
}

function applySearch() {
  const query = document.getElementById('searchInput').value.toLowerCase();
  let typeFiltered = (currentFilter === 'all') ? allRecords : allRecords.filter(r => r.type === currentFilter);
  filteredRecords = query ? typeFiltered.filter(r => r.title.toLowerCase().includes(query) || r.details.toLowerCase().includes(query) || r.type.toLowerCase().includes(query)) : typeFiltered;
  renderRecords();
}

function escapeHtml(str) {
  if (!str) return '';
  return str.replace(/[&<>]/g, function(m) {
    if (m === '&') return '&amp;';
    if (m === '<') return '&lt;';
    if (m === '>') return '&gt;';
    return m;
  });
}

// Sidebar toggle
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
window.addEventListener('resize', () => { if (window.innerWidth >= 768) toggleSidebar(false); });

// Profile dropdown
document.getElementById('profileBtn')?.addEventListener('click', (e) => {
  e.stopPropagation();
  document.getElementById('dropdownMenu')?.classList.toggle('hidden');
});
document.addEventListener('click', (e) => {
  const btn = document.getElementById('profileBtn');
  const menu = document.getElementById('dropdownMenu');
  if (!btn?.contains(e.target) && !menu?.contains(e.target)) menu?.classList.add('hidden');
});

document.getElementById('searchInput')?.addEventListener('input', applySearch);
renderRecords();
</script>
</body>
</html>