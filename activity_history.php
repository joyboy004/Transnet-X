<?php
session_start();
require_once '../config/db.php';

$account = null;
$account_role = 'user';

if (isset($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];
    $stmt = mysqli_prepare($conn, "SELECT name, email FROM users WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $uid);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $account = mysqli_fetch_assoc($result);
    $account_role = 'user';
    $dashboard_href = 'dashboard.php';
} elseif (isset($_SESSION['driver_id'])) {
    $did = (int)$_SESSION['driver_id'];
    $stmt = mysqli_prepare($conn, "SELECT name, email FROM drivers WHERE driver_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $did);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $account = mysqli_fetch_assoc($result);
    $account_role = 'driver';
    $dashboard_href = '../driver/dashboard.php';
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

if ($account_role === 'driver') {
    $stmt = mysqli_prepare($conn, "
        SELECT b.*, u.name AS user_name
        FROM bookings b
        LEFT JOIN users u ON b.user_id = u.id
        WHERE b.driver_id = ?
        ORDER BY b.created_at DESC
        LIMIT 20
    ");
    mysqli_stmt_bind_param($stmt, "i", $did);
    mysqli_stmt_execute($stmt);
    $history_items = mysqli_stmt_get_result($stmt);
    $history_rows = [];
    while ($row = mysqli_fetch_assoc($history_items)) {
        $history_rows[] = $row;
    }
} else {
    $stmt = mysqli_prepare($conn, "
        SELECT b.*, d.name AS driver_name
        FROM bookings b
        LEFT JOIN drivers d ON b.driver_id = d.driver_id
        WHERE b.user_id = ?
        ORDER BY b.created_at DESC
        LIMIT 20
    ");
    mysqli_stmt_bind_param($stmt, "i", $uid);
    mysqli_stmt_execute($stmt);
    $history_items = mysqli_stmt_get_result($stmt);
    $history_rows = [];
    while ($row = mysqli_fetch_assoc($history_items)) {
        $history_rows[] = $row;
    }
}

$summary = [
    'accepted' => 0,
    'completed' => 0,
    'pending' => 0,
    'declined' => 0,
];
foreach ($history_rows as $row) {
    if (isset($summary[$row['status']])) {
        $summary[$row['status']]++;
    }
}
?>
<!doctype html>
<html lang="en" class="h-full">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Activity History - Transnet X</title>
  <script src="https://cdn.tailwindcss.com/3.4.17"></script>
  <script src="../assets/offline-icons.js"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Merriweather:wght@400;700&display=swap" rel="stylesheet">
  <style>
    html, body { height: 100%; margin: 0; font-family: 'Inter', sans-serif; overflow-x: hidden; background: #0f1219; color: #e5e7eb; }
    .glass-card { background: rgba(22, 27, 38, 0.7); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.05); }
    .sidebar { width: 280px; background: #161b26; border-right: 1px solid #242e42; display: flex; flex-direction: column; transition: transform 0.3s ease; z-index: 40; }
    @media (max-width: 768px) { .sidebar { position: fixed; top: 0; left: 0; height: 100%; transform: translateX(-100%); } .sidebar.open { transform: translateX(0); } .sidebar-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 35; opacity: 0; visibility: hidden; transition: 0.2s; } .sidebar-overlay.show { opacity: 1; visibility: visible; } }
    .nav-link.active { background: #3b82f6; color: #fff; } .nav-link.active i { color: #fff; }
    .nav-link { color: #cbd5e1; transition: all 0.2s; }
    .nav-link:hover { background: rgba(255,255,255,0.08); color: #fff; }
    .nav-link:hover i { color: #fff; }
    .header-gradient { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
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
          <div><p class="text-sm font-semibold text-white"><?= htmlspecialchars($account_name) ?></p><p class="text-xs text-gray-400"><?= htmlspecialchars($account_email) ?></p></div>
        </div>
      </div>
      <nav class="flex-1 py-6 px-4 space-y-1.5 overflow-y-auto">
        <a href="<?= htmlspecialchars($dashboard_href) ?>" class="nav-link flex items-center gap-3 px-4 py-2.5 rounded-lg text-gray-300 hover:bg-white/10 transition-all duration-200"><i data-lucide="layout-dashboard" class="w-5 h-5"></i> <span>Dashboard</span></a>
        <a href="privacy.php" class="nav-link flex items-center gap-3 px-4 py-2.5 rounded-lg text-gray-300 hover:bg-white/10 transition-all duration-200"><i data-lucide="shield" class="w-5 h-5"></i> <span>Privacy</span></a>
        <a href="terms.php" class="nav-link flex items-center gap-3 px-4 py-2.5 rounded-lg text-gray-300 hover:bg-white/10 transition-all duration-200"><i data-lucide="file-text" class="w-5 h-5"></i> <span>Terms</span></a>
        <a href="contact.php" class="nav-link flex items-center gap-3 px-4 py-2.5 rounded-lg text-gray-300 hover:bg-white/10 transition-all duration-200"><i data-lucide="mail" class="w-5 h-5"></i> <span>Contact</span></a>
        <a href="emergency.php" class="nav-link flex items-center gap-3 px-4 py-2.5 rounded-lg text-gray-300 hover:bg-white/10 transition-all duration-200"><i data-lucide="alert-triangle" class="w-5 h-5"></i> <span>Emergency</span></a>
      </nav>
      <div class="p-4 border-t border-gray-200 text-xs text-gray-400"><p>© 2025 Transnet X. All rights reserved.</p></div>
    </aside>

    <div class="flex-1 flex flex-col overflow-auto relative">
      <nav class="sticky top-0 z-30 bg-[#0f1219]/90 backdrop-blur-md border-b border-[#242e42] shadow-sm">
        <div class="px-4 sm:px-6 lg:px-8 py-2 md:py-3 flex items-center justify-between">
          <div class="flex items-center gap-3">
            <button id="mobileMenuBtn" class="md:hidden p-2 rounded-lg text-gray-300 hover:bg-white/10 focus:outline-none"><i data-lucide="menu" class="w-6 h-6"></i></button>
            <h1 class="text-xl md:text-2xl font-bold text-white" style="font-family: 'Merriweather', serif;">Activity History</h1>
          </div>
        </div>
      </nav>

      <div class="flex-1 px-4 sm:px-6 lg:px-8 py-8 w-full max-w-6xl mx-auto">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
          <div class="glass-card rounded-2xl p-5">
            <div class="text-sm text-gray-400">Accepted</div>
            <div class="text-2xl font-bold text-white mt-1"><?= (int)$summary['accepted'] ?></div>
          </div>
          <div class="glass-card rounded-2xl p-5">
            <div class="text-sm text-gray-400">Completed</div>
            <div class="text-2xl font-bold text-white mt-1"><?= (int)$summary['completed'] ?></div>
          </div>
          <div class="glass-card rounded-2xl p-5">
            <div class="text-sm text-gray-400">Pending</div>
            <div class="text-2xl font-bold text-white mt-1"><?= (int)$summary['pending'] ?></div>
          </div>
          <div class="glass-card rounded-2xl p-5">
            <div class="text-sm text-gray-400">Declined</div>
            <div class="text-2xl font-bold text-white mt-1"><?= (int)$summary['declined'] ?></div>
          </div>
        </div>

        <div class="glass-card rounded-2xl overflow-hidden">
          <div class="p-6 border-b border-gray-800">
            <h2 class="text-xl font-bold text-white">Recent activity</h2>
            <p class="text-sm text-gray-400 mt-1">Your latest booking events and updates are shown here.</p>
          </div>
          <div class="divide-y divide-gray-800">
            <?php if (empty($history_rows)): ?>
              <div class="p-8 text-center text-gray-400">No activity logged yet. Once you receive or complete a booking, it will appear here.</div>
            <?php else: ?>
              <?php foreach ($history_rows as $row): ?>
                <?php
                  $status = $row['status'] ?? 'pending';
                  $status_labels = [
                    'accepted' => ['label' => 'Accepted', 'tone' => 'bg-green-500/15 text-green-400'],
                    'completed' => ['label' => 'Completed', 'tone' => 'bg-blue-500/15 text-blue-400'],
                    'pending' => ['label' => 'Pending', 'tone' => 'bg-amber-500/15 text-amber-400'],
                    'declined' => ['label' => 'Declined', 'tone' => 'bg-red-500/15 text-red-400'],
                  ];
                  $meta = $status_labels[$status] ?? ['label' => ucfirst($status), 'tone' => 'bg-gray-500/15 text-gray-300'];
                  $display_name = $account_role === 'driver' ? ($row['user_name'] ?? 'Passenger') : ($row['driver_name'] ?? 'Driver');
                ?>
                <div class="p-5 flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                  <div>
                    <div class="flex items-center gap-2 mb-1">
                      <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold <?= htmlspecialchars($meta['tone']) ?>"><?= htmlspecialchars($meta['label']) ?></span>
                      <span class="text-sm text-gray-400"><?= htmlspecialchars(date('M d, Y H:i', strtotime($row['created_at'] ?? now))) ?></span>
                    </div>
                    <div class="font-semibold text-white"><?= htmlspecialchars($display_name) ?> · <?= htmlspecialchars($row['pickup_location'] ?? 'Pickup location') ?> → <?= htmlspecialchars($row['dropoff_location'] ?? 'Destination') ?></div>
                    <div class="text-sm text-gray-400">Fare: ₦<?= number_format($row['fare'] ?? 0, 2) ?> · <?= htmlspecialchars($row['phone'] ?? '') ?></div>
                  </div>
                  <div class="text-sm text-gray-400"><?= htmlspecialchars($row['status'] ?? 'pending') ?></div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
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
  </script>
</body>
</html>
