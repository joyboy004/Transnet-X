<?php
session_start();
require_once '../config/db.php';

// ---------------- AUTH ----------------
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

$user_id = (int)$_SESSION['user_id'];

// ---------------- USER INFO ----------------
$stmt = mysqli_prepare($conn, "SELECT name, email FROM users WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($result);
$userName = $user['name'] ?? 'User';
$userInitial = strtoupper(substr($userName, 0, 1));

// ---------------- QUICK STATS ----------------
$ridesCount = 0;
$stmt = mysqli_prepare($conn, "SELECT COUNT(*) as cnt FROM bookings WHERE user_id = ? AND status IN ('accepted','completed')");
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($res);
$ridesCount = $row['cnt'] ?? 0;

$activeNow = 0;
$stmt = mysqli_prepare($conn, "SELECT COUNT(*) as cnt FROM bookings WHERE user_id = ? AND status = 'accepted'");
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($res);
$activeNow = $row['cnt'] ?? 0;

$monthStart = date('Y-m-01');
$spent = 0;
$spentTables = [
    ['bookings', 'fare', "user_id = $user_id AND status='completed' AND created_at >= '$monthStart'"],
    ['flight_bookings', 'total_price', "user_id = $user_id AND status='confirmed' AND created_at >= '$monthStart'"],
    ['trip_bookings', 'total_price', "user_id = $user_id AND status='confirmed' AND created_at >= '$monthStart'"],
    ['rentals', 'total_price', "user_id = $user_id AND status='completed' AND created_at >= '$monthStart'"],
    ['orders', 'total_price', "user_id = $user_id AND status IN ('delivered','completed') AND created_at >= '$monthStart'"],
    ['purchases', 'amount', "user_id = $user_id AND status='completed' AND created_at >= '$monthStart'"],
];
foreach ($spentTables as $t) {
    $q = mysqli_query($conn, "SELECT SUM({$t[1]}) as total FROM {$t[0]} WHERE {$t[2]}");
    $r = mysqli_fetch_assoc($q);
    $spent += $r['total'] ?? 0;
}
$monthSpent = number_format($spent, 0);

$lastMonthStart = date('Y-m-01', strtotime('-1 month'));
$lastMonthEnd = date('Y-m-t', strtotime('-1 month'));
$q = mysqli_query($conn, "SELECT SUM(fare) as total FROM bookings WHERE user_id = $user_id AND status='completed' AND created_at BETWEEN '$lastMonthStart' AND '$lastMonthEnd'");
$lastSpent = mysqli_fetch_assoc($q)['total'] ?? 0;
$monthChange = $lastSpent > 0 ? round(($spent - $lastSpent) / $lastSpent * 100) : ($spent > 0 ? 100 : 0);

$nextArrival = '—';
$q = mysqli_query($conn, "
    SELECT departure_date, from_city, to_city FROM trips 
    WHERE departure_date >= CURDATE() AND status='confirmed' AND available_seats > 0 
    ORDER BY departure_date ASC LIMIT 1
");
if ($r = mysqli_fetch_assoc($q)) {
    $departure = strtotime($r['departure_date']);
    $mins = round(($departure - time()) / 60);
    $nextArrival = $mins > 1440 ? floor($mins/1440).'d' : ($mins > 60 ? floor($mins/60).'h '.($mins%60).'m' : $mins.'min');
}

// ---------------- HERO SLIDES (random vehicles, foods, rentals; trips + flights by schedule) ----------------
$heroSlides = [];

function resolveSlideImage($path) {
    if (empty($path)) return null;
    if (preg_match('#^(https?://|//)#', $path)) return $path;
    if (strpos($path, '../uploads/') === 0 || strpos($path, '../../uploads/') === 0) return $path;
    return '../uploads/' . basename($path);
}

// Vehicles – random 2 items (each refresh shows different cars)
$q = mysqli_query($conn, "SELECT id, make, model, year, price, image_url FROM vehicles ORDER BY RAND() LIMIT 2");
while ($row = mysqli_fetch_assoc($q)) {
    $heroSlides[] = [
        'icon' => '<i data-lucide="car" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i>', 'title' => "{$row['year']} {$row['make']} {$row['model']}", 'sub' => 'Clean title · Low mileage',
        'price' => '₦' . number_format($row['price']), 'color' => '#6366f1', 'cat' => 'Car Sale',
        'link' => 'vehicle_sale.php?id=' . $row['id'], 'image_url' => resolveSlideImage($row['image_url'] ?? '')
    ];
}

// Foods – random 1 item
$q = mysqli_query($conn, "SELECT id, name, description, price, image_url FROM foods ORDER BY RAND() LIMIT 1");
while ($row = mysqli_fetch_assoc($q)) {
    $heroSlides[] = [
        'icon' => '<i data-lucide="utensils" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i>', 'title' => $row['name'], 'sub' => $row['description'] ?? 'Delicious meal',
        'price' => '₦' . number_format($row['price']), 'color' => '#f59e0b', 'cat' => 'Food',
        'link' => 'order_food.php?id=' . $row['id'], 'image_url' => resolveSlideImage($row['image_url'] ?? '')
    ];
}

// Trips – nearest upcoming (no image)
$q = mysqli_query($conn, "SELECT id, from_city, to_city, price_per_seat, departure_date FROM trips WHERE departure_date >= CURDATE() AND status='confirmed' AND available_seats > 0 ORDER BY departure_date ASC LIMIT 1");
if ($row = mysqli_fetch_assoc($q)) {
    $heroSlides[] = [
        'icon' => '<i data-lucide="bus" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i>', 'title' => "{$row['from_city']} → {$row['to_city']}",
        'sub' => date('M d', strtotime($row['departure_date'])) . ' · Economy',
        'price' => '₦' . number_format($row['price_per_seat']), 'color' => '#00d4aa', 'cat' => 'Trip',
        'link' => 'TransNet/trip.php?id=' . $row['id'], 'image_url' => null
    ];
}

// Rentals – random 1 item
$q = mysqli_query($conn, "SELECT id, car_model, daily_rate, pickup_location FROM rentals WHERE status IN ('approved','active') ORDER BY RAND() LIMIT 1");
while ($row = mysqli_fetch_assoc($q)) {
    $heroSlides[] = [
        'icon' => '<i data-lucide="car-front" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i>', 'title' => $row['car_model'], 'sub' => "Daily rental · {$row['pickup_location']}",
        'price' => '₦' . number_format($row['daily_rate']) . '/day', 'color' => '#ec4899', 'cat' => 'Rental',
        'link' => 'rental.php?id=' . $row['id'], 'image_url' => resolveSlideImage('')
    ];
}

// Flights – nearest upcoming (no image)
$q = mysqli_query($conn, "SELECT id, airline, origin_city, dest_city, price_per_seat, departure_time 
                          FROM flights 
                          WHERE departure_time >= NOW() AND status = 'confirmed' AND available_seats > 0 
                          ORDER BY departure_time ASC LIMIT 1");
if ($row = mysqli_fetch_assoc($q)) {
    $heroSlides[] = [
        'icon' => '<i data-lucide="plane" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i>️', 'title' => "{$row['airline']} · {$row['origin_city']} → {$row['dest_city']}",
        'sub' => date('M d, H:i', strtotime($row['departure_time'])),
        'price' => '₦' . number_format($row['price_per_seat']), 'color' => '#3b82f6', 'cat' => 'Flight',
        'link' => 'flights.php?id=' . $row['id'], 'image_url' => null
    ];
}

// Limit to 5 slides maximum
$heroSlides = array_slice($heroSlides, 0, 5);

// ---------------- NOTIFICATIONS & ACTIVITIES (unchanged) ----------------
$tableConfigs = [
    [
        'table' => 'bookings', 'user_col' => 'user_id', 'time_col' => 'created_at', 'status_col' => 'status',
        'title' => 'Ride Booking', 'message_fields' => ['pickup_location', 'dropoff_location'],
        'message_pattern' => '{pickup_location} → {dropoff_location}',
        'activity_statuses' => ['accepted', 'declined', 'completed'], 'icon' => 'car', 'color' => 'green'
    ],
    [
        'table' => 'flight_bookings', 'user_col' => 'user_id', 'time_col' => 'created_at', 'status_col' => 'status',
        'title' => 'Flight Booking', 'message_fields' => ['from_city', 'to_city'],
        'message_pattern' => '{from_city} → {to_city}',
        'activity_statuses' => ['confirmed', 'cancelled', 'completed'], 'icon' => 'plane', 'color' => 'blue'
    ],
    [
        'table' => 'trip_bookings', 'user_col' => 'user_id', 'time_col' => 'created_at', 'status_col' => 'status',
        'title' => 'Trip Booking', 'message_fields' => ['from_city', 'to_city'],
        'message_pattern' => '{from_city} → {to_city}',
        'activity_statuses' => ['confirmed', 'approved', 'cancelled', 'completed'], 'icon' => 'map', 'color' => 'cyan'
    ],
    [
        'table' => 'orders', 'user_col' => 'user_id', 'time_col' => 'created_at', 'status_col' => 'status',
        'title' => 'Food Order', 'message_fields' => ['dropoff_address'],
        'message_pattern' => 'Delivery to {dropoff_address}',
        'activity_statuses' => ['delivered', 'cancelled'], 'icon' => 'utensils', 'color' => 'orange'
    ],
    [
        'table' => 'purchases', 'user_col' => 'user_id', 'time_col' => 'created_at', 'status_col' => 'status',
        'title' => 'Vehicle Purchase', 'message_fields' => ['full_name', 'vehicle_id'],
        'message_pattern' => 'Purchase #{vehicle_id} by {full_name}',
        'activity_statuses' => ['approved', 'completed', 'cancelled', 'rejected'], 'icon' => 'shopping-bag', 'color' => 'purple'
    ],
    [
        'table' => 'rentals', 'user_col' => 'user_id', 'time_col' => 'created_at', 'status_col' => 'status',
        'title' => 'Rental', 'message_fields' => ['car_model'],
        'message_pattern' => '{car_model} rental',
        'activity_statuses' => ['confirmed', 'active', 'returned', 'cancelled'], 'icon' => 'car', 'color' => 'pink'
    ]
];

function fetchAllEntries($conn, $user_id, $configs, $limit = 10) {
    $entries = [];
    foreach ($configs as $cfg) {
        $table = $cfg['table'];
        $userCol = $cfg['user_col'];
        $timeCol = $cfg['time_col'];
        $statusCol = $cfg['status_col'];
        $fields = implode(', ', $cfg['message_fields']);
        $sql = "SELECT id, $statusCol as status, $timeCol as created_at, $fields FROM $table WHERE $userCol = ? ORDER BY $timeCol DESC LIMIT $limit";
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) continue;
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            $msg = $cfg['message_pattern'];
            foreach ($cfg['message_fields'] as $f) {
                $msg = str_replace('{' . $f . '}', htmlspecialchars($row[$f] ?? ''), $msg);
            }
            $entries[] = [
                'type' => $cfg['title'], 'status' => $row['status'], 'created_at' => $row['created_at'],
                'message' => $msg, 'icon' => $cfg['icon'], 'color' => $cfg['color'],
                'activity_statuses' => $cfg['activity_statuses']
            ];
        }
    }
    usort($entries, function ($a, $b) { return strtotime($b['created_at']) - strtotime($a['created_at']); });
    return $entries;
}

$allEntries = fetchAllEntries($conn, $user_id, $tableConfigs, 15);
$notifications = array_slice($allEntries, 0, 20);
$activities = [];
foreach ($allEntries as $entry) {
    if (in_array($entry['status'], $entry['activity_statuses'])) $activities[] = $entry;
}
$activities = array_slice($activities, 0, 5);

if (!isset($_SESSION['last_notif_seen'])) $_SESSION['last_notif_seen'] = date('Y-m-d H:i:s');
$unreadCount = 0;
foreach ($notifications as $n) {
    if (strtotime($n['created_at']) > strtotime($_SESSION['last_notif_seen'])) $unreadCount++;
}

$notificationsJson = json_encode($notifications, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
$heroSlidesJson = json_encode($heroSlides, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
$activitiesJson = json_encode($activities, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
?>
<!doctype html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TransNetX Dashboard</title>
    <script src="https://cdn.tailwindcss.com/3.4.17"></script>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'DM Sans', sans-serif; }
        .mono { font-family: 'Space Mono', monospace; }
        html, body { height: 100%; margin: 0; }
        .carousel-slide {
            animation: fadeIn 0.6s ease;
            position: absolute; inset: 0; overflow: hidden; border-radius: 1rem;
            display: flex;
        }
        .slide-image-left { 
            height: 100%; object-fit: cover;
            width: 100%;
        }
        @keyframes fadeIn { from { opacity: 0; transform: scale(1.02); } to { opacity: 1; transform: scale(1); } }
        @keyframes pulse-dot { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }
        .pulse-dot { animation: pulse-dot 1.5s infinite; }
        .sidebar-item:hover { background: rgba(255,255,255,0.08); }
        .sidebar-item.active { background: rgba(99,220,190,0.15); border-right: 3px solid #63dcbe; }
        .ai-bubble { animation: slideUp 0.3s ease; }
        @keyframes slideUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .card-hover { transition: all 0.2s ease; }
        .card-hover:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.3); }
        ::-webkit-scrollbar { width: 6px; } ::-webkit-scrollbar-track { background: #1a1f2e; } ::-webkit-scrollbar-thumb { background: #3a4158; border-radius: 3px; }
        @media (max-width: 768px) {
            #sidebar { position: fixed; left: -100%; z-index: 50; transition: left 0.3s ease; }
            #sidebar.open { left: 0; }
            #sidebar-overlay { display: none; }
            #sidebar-overlay.show { display: block; }
        }
    </style>
</head>
<body class="h-full bg-[#0f1219] text-gray-100 overflow-hidden">
    <div id="sidebar-overlay" class="fixed inset-0 bg-black/60 z-40 hidden" onclick="closeSidebar()"></div>

    <div class="flex h-full w-full">
        <!-- Sidebar -->
        <aside id="sidebar" class="w-64 bg-[#161b26] border-r border-gray-800 flex flex-col flex-shrink-0 h-full overflow-y-auto z-50">
            <div class="p-5 border-b border-gray-800">
                <a href="user.php" class="flex items-center gap-2 no-underline">
                    <div class="w-9 h-9 rounded-lg bg-gradient-to-br from-[#63dcbe] to-[#3b82f6] flex items-center justify-center"><i data-lucide="truck" class="w-5 h-5 text-[#0f1219]"></i></div>
                    <span class="font-bold text-lg tracking-tight text-white">TransNetX</span>
                </a>
            </div>
            <nav class="flex-1 py-4 px-3 space-y-1">
                <a href="user.php" class="sidebar-item active flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-300 no-underline"><i data-lucide="layout-dashboard" class="w-4 h-4"></i> Dashboard</a>
                <a href="TransNet/uber.php" class="sidebar-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-300 no-underline"><span class="inline-flex items-center justify-center" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4"><path d="M4 10h12a2 2 0 0 1 2 2v4H4v-4a2 2 0 0 1 2-2Z"></path><path d="M6 10V7a2 2 0 0 1 2-2h4"></path><path d="M8 16h1"></path><path d="M15 16h1"></path><circle cx="8" cy="16" r="1.5"></circle><circle cx="16" cy="16" r="1.5"></circle></svg></span> Uber</a>
                <a href="TransNet/trip.php" class="sidebar-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-300 no-underline"><i data-lucide="map" class="w-4 h-4"></i> Trips</a>
                <a href="TransNet/flight.php" class="sidebar-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-300 no-underline"><i data-lucide="plane" class="w-4 h-4"></i> Flights</a>
                <a href="delivery.php" class="sidebar-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-300 no-underline"><i data-lucide="package" class="w-4 h-4"></i> Delivery</a>
                <a href="vehicle_sale.php" class="sidebar-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-300 no-underline"><i data-lucide="shopping-cart" class="w-4 h-4"></i> Market</a>
                <a href="order_food.php" class="sidebar-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-300 no-underline"><i data-lucide="utensils" class="w-4 h-4"></i> Food Order</a>
                <a href="records.php" class="sidebar-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-300 no-underline"><i data-lucide="calendar" class="w-4 h-4"></i> My Bookings</a>
                <a href="emergency.php" class="sidebar-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-300 no-underline"><i data-lucide="alert-triangle" class="w-4 h-4 text-red-400"></i> Emergency</a>
                    <div class="border-t border-gray-800 my-2"></div>
                    <a href="contact.php" class="sidebar-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-300 no-underline"><i data-lucide="mail" class="w-4 h-4"></i> Contact</a>
                <div class="border-t border-gray-800 my-2"></div>
                <a href="settings.php" class="sidebar-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-300 no-underline"><i data-lucide="settings" class="w-4 h-4"></i> Settings</a>
                <a href="profile.php" class="sidebar-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-300 no-underline"><i data-lucide="user" class="w-4 h-4"></i> Profile</a>
            </nav>
            <div class="p-4 border-t border-gray-800">
                <a href="profile.php" class="flex items-center gap-3 no-underline hover:bg-white/5 rounded-lg p-2 -m-2 transition">
                    <div class="w-9 h-9 rounded-full bg-gradient-to-br from-purple-500 to-pink-500 flex items-center justify-center text-sm font-bold text-white"><?= $userInitial ?></div>
                    <div class="flex-1 min-w-0"><p class="text-sm font-medium text-white truncate"><?= htmlspecialchars($userName) ?></p><p class="text-xs text-gray-500">Premium Member</p></div>
                </a>
                <a href="../auth/logout.php" class="flex items-center gap-2 mt-3 text-xs text-gray-500 hover:text-red-400 transition no-underline"><i data-lucide="log-out" class="w-3.5 h-3.5"></i> Sign Out</a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 h-full overflow-y-auto">
            <header class="sticky top-0 z-20 bg-[#0f1219]/80 backdrop-blur-lg border-b border-gray-800 px-4 md:px-6 py-3 flex items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <button onclick="toggleSidebar()" class="md:hidden text-gray-400 hover:text-white"><i data-lucide="menu" class="w-6 h-6"></i></button>
                    <div>
                        <h1 class="text-lg md:text-xl font-bold">Welcome back, <span class="text-[#63dcbe]"><?= htmlspecialchars($userName) ?></span></h1>
                        <p class="text-xs text-gray-500 mono" id="current-date"></p>
                    </div>
                </div>
                <div class="flex items-center gap-2 md:gap-4">
                    <button onclick="toggleAI()" class="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-gradient-to-r from-[#63dcbe]/20 to-[#3b82f6]/20 border border-[#63dcbe]/30 text-sm hover:border-[#63dcbe]/60 transition whitespace-nowrap"><i data-lucide="sparkles" class="w-4 h-4 text-[#63dcbe]"></i><span class="hidden sm:inline">Ask AI</span></button>
                    <button onclick="toggleNotifications()" class="relative p-1.5 rounded-lg hover:bg-white/10 transition"><i data-lucide="bell" class="w-5 h-5 text-gray-400 hover:text-white"></i><span id="notif-dot" class="absolute -top-1 -right-1 w-2.5 h-2.5 bg-red-500 rounded-full pulse-dot <?= $unreadCount > 0 ? '' : 'hidden' ?>"></span></button>
                    <button onclick="toggleSearch()" class="hidden sm:block p-1.5 rounded-lg hover:bg-white/10 transition"><i data-lucide="search" class="w-5 h-5 text-gray-400 hover:text-white"></i></button>
                </div>
            </header>

            <div class="p-4 md:p-6 space-y-6">
                <!-- Carousel -->
                <section class="relative rounded-2xl overflow-hidden h-44 md:h-52 bg-[#1a2035]">
                    <div id="carousel-container" class="h-full relative"></div>
                    <div class="absolute bottom-3 left-1/2 -translate-x-1/2 flex gap-2" id="carousel-dots"></div>
                </section>

                <!-- Stats -->
                <section class="grid grid-cols-2 md:grid-cols-4 gap-3 md:gap-4">
                    <a href="records.php" class="bg-[#161b26] border border-gray-800 rounded-xl p-4 card-hover no-underline text-white block">
                        <div class="flex items-center justify-between mb-2"><i data-lucide="map-pin" class="w-5 h-5 text-[#63dcbe]"></i><span class="text-xs text-green-400 mono"><?= $monthChange >= 0 ? '+' : '' ?><?= $monthChange ?>%</span></div>
                        <p class="text-2xl font-bold"><?= $ridesCount ?></p><p class="text-xs text-gray-500">Total Rides</p>
                    </a>
                    <a href="TransNet/uber.php" class="bg-[#161b26] border border-gray-800 rounded-xl p-4 card-hover no-underline text-white block">
                        <div class="flex items-center justify-between mb-2"><i data-lucide="truck" class="w-5 h-5 text-blue-400"></i><span class="pulse-dot w-2 h-2 bg-green-400 rounded-full inline-block <?= $activeNow > 0 ? '' : 'hidden' ?>"></span></div>
                        <p class="text-2xl font-bold"><?= $activeNow ?></p><p class="text-xs text-gray-500">Active Now</p>
                    </a>
                    <a href="records.php" class="bg-[#161b26] border border-gray-800 rounded-xl p-4 card-hover no-underline text-white block">
                        <div class="flex items-center justify-between mb-2"><i data-lucide="wallet" class="w-5 h-5 text-purple-400"></i><span class="text-xs text-gray-500 mono">₦</span></div>
                        <p class="text-2xl font-bold">₦<?= $monthSpent ?></p><p class="text-xs text-gray-500">This Month</p>
                    </a>
                    <a href="TransNet/trip.php" class="bg-[#161b26] border border-gray-800 rounded-xl p-4 card-hover no-underline text-white block">
                        <div class="flex items-center justify-between mb-2"><i data-lucide="clock" class="w-5 h-5 text-amber-400"></i><span class="text-xs text-amber-400 mono">ETA</span></div>
                        <p class="text-2xl font-bold"><?= $nextArrival ?></p><p class="text-xs text-gray-500">Next Departure</p>
                    </a>
                </section>

                <!-- Quick Access -->
                <section>
                    <h3 class="text-sm font-semibold text-gray-400 uppercase tracking-wider mb-3">Quick Access</h3>
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
                        <a href="TransNet/transnet.php" class="bg-[#161b26] border border-gray-800 rounded-xl p-4 card-hover group no-underline block">
                            <div class="w-10 h-10 rounded-lg bg-[#63dcbe]/10 flex items-center justify-center mb-3"><i data-lucide="car" class="w-5 h-5 text-[#63dcbe]"></i></div>
                            <p class="text-sm font-medium text-white">TransNet Rides</p><p class="text-xs text-gray-500 mt-0.5">Book a ride</p>
                        </a>
                        <a href="TransNet/trip.php" class="bg-[#161b26] border border-gray-800 rounded-xl p-4 card-hover group no-underline block">
                            <div class="w-10 h-10 rounded-lg bg-green-500/10 flex items-center justify-center mb-3"><i data-lucide="map" class="w-5 h-5 text-green-400"></i></div>
                            <p class="text-sm font-medium text-white">Trips</p><p class="text-xs text-gray-500 mt-0.5">Bus & train travel</p>
                        </a>
                        <a href="flights.php" class="bg-[#161b26] border border-gray-800 rounded-xl p-4 card-hover group no-underline block">
                            <div class="w-10 h-10 rounded-lg bg-blue-500/10 flex items-center justify-center mb-3"><i data-lucide="plane" class="w-5 h-5 text-blue-400"></i></div>
                            <p class="text-sm font-medium text-white">Flights</p><p class="text-xs text-gray-500 mt-0.5">Airline tickets</p>
                        </a>
                        <a href="delivery.php" class="bg-[#161b26] border border-gray-800 rounded-xl p-4 card-hover group no-underline block">
                            <div class="w-10 h-10 rounded-lg bg-purple-500/10 flex items-center justify-center mb-3"><i data-lucide="package" class="w-5 h-5 text-purple-400"></i></div>
                            <p class="text-sm font-medium text-white">Delivery</p><p class="text-xs text-gray-500 mt-0.5">Send & receive</p>
                        </a>
                        <a href="vehicle_sale.php" class="bg-[#161b26] border border-gray-800 rounded-xl p-4 card-hover group no-underline block">
                            <div class="w-10 h-10 rounded-lg bg-amber-500/10 flex items-center justify-center mb-3"><i data-lucide="shopping-cart" class="w-5 h-5 text-amber-400"></i></div>
                            <p class="text-sm font-medium text-white">Market</p><p class="text-xs text-gray-500 mt-0.5">Buy vehicles</p>
                        </a>
                        <a href="order_food.php" class="bg-[#161b26] border border-gray-800 rounded-xl p-4 card-hover group no-underline block">
                            <div class="w-10 h-10 rounded-lg bg-orange-500/10 flex items-center justify-center mb-3"><i data-lucide="utensils" class="w-5 h-5 text-orange-400"></i></div>
                            <p class="text-sm font-medium text-white">Food Order</p><p class="text-xs text-gray-500 mt-0.5">Meals & groceries</p>
                        </a>
                        <a href="records.php" class="bg-[#161b26] border border-gray-800 rounded-xl p-4 card-hover group no-underline block">
                            <div class="w-10 h-10 rounded-lg bg-cyan-500/10 flex items-center justify-center mb-3"><i data-lucide="calendar" class="w-5 h-5 text-cyan-400"></i></div>
                            <p class="text-sm font-medium text-white">My Bookings</p><p class="text-xs text-gray-500 mt-0.5">All reservations</p>
                        </a>
                        <a href="emergency.php" class="bg-[#161b26] border border-gray-800 rounded-xl p-4 card-hover group no-underline block">
                            <div class="w-10 h-10 rounded-lg bg-red-500/10 flex items-center justify-center mb-3"><i data-lucide="alert-triangle" class="w-5 h-5 text-red-400"></i></div>
                            <p class="text-sm font-medium text-white">Emergency</p><p class="text-xs text-gray-500 mt-0.5">SOS & help</p>
                        </a>
                    </div>
                </section>

                <!-- Recent Activity + AI Mini -->
                <section class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div class="bg-[#161b26] border border-gray-800 rounded-xl p-5">
                        <div class="flex items-center justify-between mb-4"><h3 class="font-semibold text-white">Recent Activity</h3><a href="records.php" class="text-xs text-[#63dcbe] hover:underline no-underline">View All</a></div>
                        <div id="recent-activity-list" class="space-y-3 max-h-72 overflow-y-auto"></div>
                    </div>
                    <div class="bg-[#161b26] border border-gray-800 rounded-xl p-5 flex flex-col">
                        <div class="flex items-center gap-3 mb-4"><div class="w-10 h-10 rounded-xl bg-gradient-to-br from-[#63dcbe] to-[#3b82f6] flex items-center justify-center"><i data-lucide="sparkles" class="w-5 h-5 text-[#0f1219]"></i></div><div><h3 class="font-semibold text-white">TransNetX AI</h3><p class="text-xs text-gray-500">Your smart assistant</p></div></div>
                        <div id="ai-mini-chat" class="flex-1 space-y-3 max-h-48 overflow-y-auto mb-3"><div class="ai-bubble bg-[#0f1219] rounded-xl rounded-tl-sm p-3 text-sm text-gray-300"><i data-lucide="hand" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i> Hi <?= htmlspecialchars($userName) ?>! Ask me about routes, costs, or travel plans.</div></div>
                        <div class="flex gap-2 mt-auto">
                            <input id="ai-mini-input" type="text" placeholder="Ask anything..." class="flex-1 bg-[#0f1219] border border-gray-700 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-[#63dcbe]" onkeydown="if(event.key==='Enter')sendAiMiniMsg()">
                            <button onclick="sendAiMiniMsg()" class="w-9 h-9 rounded-lg bg-[#63dcbe] flex items-center justify-center hover:bg-[#4fc9ab]"><i data-lucide="send" class="w-4 h-4 text-[#0f1219]"></i></button>
                        </div>
                    </div>
                </section>
            </div>
        </main>

        <!-- Notification Panel -->
        <div id="notif-overlay" class="fixed inset-0 z-50 hidden">
            <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" onclick="toggleNotifications()"></div>
            <div id="notif-panel" class="absolute right-0 top-0 h-full w-full max-w-sm bg-[#161b26] border-l border-gray-800 shadow-2xl overflow-y-auto">
                <div class="p-5 border-b border-gray-800 flex items-center justify-between sticky top-0 bg-[#161b26] z-10"><h2 class="font-bold text-lg">Notifications</h2><button onclick="toggleNotifications()" class="text-gray-500 hover:text-white"><i data-lucide="x" class="w-5 h-5"></i></button></div>
                <div id="notif-list" class="p-4 space-y-3"></div>
            </div>
        </div>

        <!-- AI Full Panel -->
        <div id="ai-panel" class="hidden fixed right-0 top-0 h-full w-80 bg-[#161b26] border-l border-gray-800 z-50 flex flex-col shadow-2xl">
            <div class="p-4 border-b border-gray-800 flex items-center justify-between"><div class="flex items-center gap-2"><div class="w-7 h-7 rounded-lg bg-gradient-to-br from-[#63dcbe] to-[#3b82f6] flex items-center justify-center"><i data-lucide="sparkles" class="w-4 h-4 text-[#0f1219]"></i></div><span class="font-semibold text-sm">TransNetX AI</span></div><button onclick="toggleAI()" class="text-gray-500 hover:text-white"><i data-lucide="x" class="w-5 h-5"></i></button></div>
            <div id="ai-full-messages" class="flex-1 overflow-y-auto p-4 space-y-3"><div class="ai-bubble bg-[#0f1219] rounded-xl rounded-tl-sm p-3 text-sm text-gray-300"><i data-lucide="hand" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i> Hi <?= htmlspecialchars($userName) ?>! How can I help you today?</div></div>
            <div class="p-4 border-t border-gray-800"><div class="flex gap-2"><input id="ai-full-input" type="text" placeholder="Ask anything..." class="flex-1 bg-[#0f1219] border border-gray-700 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-[#63dcbe]" onkeydown="if(event.key==='Enter')sendAiFullMsg()"><button onclick="sendAiFullMsg()" class="w-9 h-9 rounded-lg bg-[#63dcbe] flex items-center justify-center"><i data-lucide="send" class="w-4 h-4 text-[#0f1219]"></i></button></div></div>
        </div>
    </div>

    <script>
        const heroSlidesData = <?= $heroSlidesJson ?>;
        const activitiesData = <?= $activitiesJson ?>;
        let notificationsData = <?= $notificationsJson ?>;
        let currentSlide = 0, carouselInterval, notifOpen = false, lastNotifHash = JSON.stringify(notificationsData);

        document.addEventListener('DOMContentLoaded', () => {
            document.getElementById('current-date').textContent = new Date().toLocaleDateString('en-US', {weekday:'long', year:'numeric', month:'long', day:'numeric'});
            initCarousel();
            renderRecentActivities();
            renderNotifications();
            updateNotifBadge();
            renderOfflineIcons(document);
            setInterval(fetchNotifications, 10000);
        });

        function initCarousel() {
            const container = document.getElementById('carousel-container'), dots = document.getElementById('carousel-dots');
            if (!container || !heroSlidesData.length) return;
            container.innerHTML = heroSlidesData.map((s, i) => {
                if (s.image_url) {
                    return `
                    <div class="carousel-slide ${i>0?'hidden':''} flex h-full">
                        <div class="w-1/2 h-full flex-shrink-0">
                            <img src="${s.image_url}" alt="${s.title}" class="slide-image-left">
                        </div>
                        <div class="w-1/2 h-full flex flex-col justify-center p-5 md:p-6" style="background:linear-gradient(135deg,${s.color}22,${s.color}08);">
                            <div>
                                <p class="text-xs uppercase tracking-wider mb-1" style="color:${s.color}">${s.cat}</p>
                                <h2 class="text-xl md:text-2xl font-bold mb-2 text-white">${s.title}</h2>
                                <p class="text-sm text-gray-400 mb-3 hidden sm:block">${s.sub}</p>
                                <a href="${s.link}" class="inline-block px-4 py-1.5 rounded-lg text-sm font-semibold no-underline" style="background:${s.color};color:${(s.color==='#f59e0b'||s.color==='#00d4aa')?'#0f1219':'#fff'}">${s.price} →</a>
                            </div>
                        </div>
                    </div>`;
                } else {
                    return `
                    <div class="carousel-slide ${i>0?'hidden':''} flex h-full items-center p-5 md:p-6" style="background:linear-gradient(135deg,${s.color}22,${s.color}08);">
                        <div class="flex-1">
                            <p class="text-xs uppercase tracking-wider mb-1" style="color:${s.color}">${s.cat}</p>
                            <h2 class="text-xl md:text-2xl font-bold mb-2 text-white">${s.title}</h2>
                            <p class="text-sm text-gray-400 mb-3 hidden sm:block">${s.sub}</p>
                            <a href="${s.link}" class="inline-block px-4 py-1.5 rounded-lg text-sm font-semibold no-underline" style="background:${s.color};color:${(s.color==='#f59e0b'||s.color==='#00d4aa')?'#0f1219':'#fff'}">${s.price} →</a>
                        </div>
                        <div class="hidden md:flex items-center justify-center w-40">
                            <span class="text-7xl opacity-40">${s.icon}</span>
                        </div>
                    </div>`;
                }
            }).join('');

            dots.innerHTML = heroSlidesData.map((_, i) => `<button class="slide-dot w-2 h-2 rounded-full cursor-pointer" data-slide="${i}" style="background:${i===0?'#fff':'rgba(255,255,255,0.3)'}"></button>`).join('');

            document.querySelectorAll('.slide-dot').forEach(d => d.addEventListener('click', () => {
                clearInterval(carouselInterval);
                goToSlide(parseInt(d.dataset.slide));
                carouselInterval = setInterval(nextSlide, 5000);
            }));
            container.addEventListener('mouseenter', () => clearInterval(carouselInterval));
            container.addEventListener('mouseleave', () => carouselInterval = setInterval(nextSlide, 5000));
            carouselInterval = setInterval(nextSlide, 5000);
        }

        function nextSlide() { goToSlide((currentSlide + 1) % heroSlidesData.length); }
        function goToSlide(n) {
            document.querySelectorAll('.carousel-slide').forEach((s, i) => s.classList.toggle('hidden', i !== n));
            document.querySelectorAll('.slide-dot').forEach((d, i) => d.style.background = i === n ? '#fff' : 'rgba(255,255,255,0.3)');
            currentSlide = n;
        }

        function toggleSidebar() { document.getElementById('sidebar').classList.toggle('open'); document.getElementById('sidebar-overlay').classList.toggle('show'); }
        function closeSidebar() { document.getElementById('sidebar').classList.remove('open'); document.getElementById('sidebar-overlay').classList.remove('show'); }

        function toggleNotifications() {
            notifOpen = !notifOpen;
            document.getElementById('notif-overlay').classList.toggle('hidden', !notifOpen);
            if (notifOpen) {
                renderNotifications();
                fetch('mark_notifications_read.php').catch(()=>{});
                updateNotifBadge();
            }
        }
        function renderNotifications() {
            const container = document.getElementById('notif-list');
            if (!container) return;
            if (!notificationsData.length) { container.innerHTML = '<p class="text-sm text-gray-500 text-center py-8">No notifications</p>'; return; }
            container.innerHTML = notificationsData.map(n => {
                const time = new Date(n.created_at.replace(' ','T'));
                return `<div class="p-4 rounded-xl bg-[#0f1219] border border-gray-800"><div class="flex items-center gap-2 mb-1"><span class="text-sm font-semibold text-white">${n.type}</span><span class="text-xs text-gray-500 ml-auto">${time.toLocaleDateString('en-US',{month:'short',day:'numeric'})}</span></div><p class="text-sm text-gray-400">${n.message} (${n.status})</p></div>`;
            }).join('');
        }
        function updateNotifBadge() {
            const dot = document.getElementById('notif-dot');
            const unread = notificationsData.filter(n => new Date(n.created_at.replace(' ','T')) > new Date('<?= $_SESSION['last_notif_seen'] ?>')).length;
            if (dot) dot.classList.toggle('hidden', unread === 0);
        }
        async function fetchNotifications() {
            try {
                const res = await fetch('fetch_notifications.php');
                const data = await res.json();
                if (JSON.stringify(data) !== lastNotifHash) {
                    notificationsData = data;
                    lastNotifHash = JSON.stringify(data);
                    if (notifOpen) renderNotifications();
                    updateNotifBadge();
                }
            } catch(e) {}
        }

        function renderRecentActivities() {
            const container = document.getElementById('recent-activity-list');
            if (!container || !activitiesData.length) { container.innerHTML = '<p class="text-sm text-gray-500 text-center py-6">No recent activity</p>'; return; }
            container.innerHTML = activitiesData.map(a => `
                <div class="flex items-center gap-3 p-3 rounded-lg bg-[#0f1219]/50">
                    <div class="w-8 h-8 rounded-full bg-${a.color}-500/20 flex items-center justify-center"><i data-lucide="${a.icon}" class="w-4 h-4 text-${a.color}-400"></i></div>
                    <div class="flex-1 min-w-0"><p class="text-sm font-medium text-white">${a.type} ${a.status}</p><p class="text-xs text-gray-500">${a.message} · ${new Date(a.created_at.replace(' ','T')).toLocaleDateString('en-US',{month:'short',day:'numeric'})}</p></div>
                </div>`).join('');
            renderOfflineIcons(container);
        }

        const offlineIconMap = {
            car: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 10h12a2 2 0 0 1 2 2v4H4v-4a2 2 0 0 1 2-2Z"></path><path d="M6 10V7a2 2 0 0 1 2-2h4"></path><path d="M8 16h1"></path><path d="M15 16h1"></path><circle cx="8" cy="16" r="1.5"></circle><circle cx="16" cy="16" r="1.5"></circle></svg>',
            truck: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h11a2 2 0 0 1 2 2v5H3z"></path><path d="M16 10h3l2 2v3h-2"></path><circle cx="8" cy="17" r="1.5"></circle><circle cx="18" cy="17" r="1.5"></circle></svg>',
            map: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 4 3 6v14l6-2 6 2 6-2V4l-6 2z"></path><path d="M9 4v14"></path><path d="M15 6v14"></path></svg>',
            plane: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h18"></path><path d="m6 8 6 4-6 4"></path><path d="m12 4 2 8-2 8"></path></svg>',
            package: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 4 7v10l8 4 8-4V7z"></path><path d="m4 7 8 4 8-4"></path><path d="M12 11v10"></path></svg>',
            'shopping-cart': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="20" r="1"></circle><circle cx="19" cy="20" r="1"></circle><path d="M1 3h2l2.8 10.4a1 1 0 0 0 1 .8h8.4a1 1 0 0 0 1-.8L17 7H5"></path></svg>',
            utensils: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3v7"></path><path d="M5 3v7"></path><path d="M11 3v7"></path><path d="M8 10c0 2.2 1.8 4 4 4h1v7"></path></svg>',
            calendar: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M16 3v4"></path><path d="M8 3v4"></path><path d="M3 10h18"></path></svg>',
            'alert-triangle': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h14.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><path d="M12 9v4"></path><path d="M12 17h.01"></path></svg>',
            mail: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1Z"></path><path d="m4 8 8 6 8-6"></path></svg>',
            settings: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 0 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 0 1-4 0v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 0 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 0 1 0-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3h.1a1.7 1.7 0 0 0 1-1.5V3a2 2 0 0 1 4 0v.1a1.7 1.7 0 0 0 1 1.5h.1a1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8v.1a1.7 1.7 0 0 0 1.5 1H21a2 2 0 0 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"></path></svg>',
            user: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21a8 8 0 1 0-16 0"></path><circle cx="12" cy="8" r="4"></circle></svg>',
            'log-out': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><path d="m16 17 5-5-5-5"></path><path d="M21 12H9"></path></svg>',
            menu: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16"></path><path d="M4 12h16"></path><path d="M4 18h16"></path></svg>',
            sparkles: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3 1.5 4.5L18 9l-4.5 1.5L12 15l-1.5-4.5L6 9l4.5-1.5L12 3Z"></path><path d="m19 15 1 2.5L22.5 19 20 20.5 19 23l-1-2.5L15.5 19 18 17.5 19 15Z"></path></svg>',
            bell: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M15 17h5l-1.4-1.4a2 2 0 0 1-.6-1.4V11a6 6 0 1 0-12 0v3.2a2 2 0 0 1-.6 1.4L4 17h5"></path><path d="M9 17a3 3 0 0 0 6 0"></path></svg>',
            search: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="6"></circle><path d="m20 20-4.2-4.2"></path></svg>',
            'map-pin': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s6-5.6 6-11a6 6 0 1 0-12 0c0 5.4 6 11 6 11Z"></path><circle cx="12" cy="10" r="2.5"></circle></svg>',
            wallet: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h18a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2Z"></path><path d="M16 13h4"></path><path d="M3 10h18"></path></svg>',
            clock: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8"></circle><path d="M12 7v5l3 2"></path></svg>',
            x: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"></path><path d="m6 6 12 12"></path></svg>',
            send: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h18"></path><path d="m13 6 6 6-6 6"></path></svg>',
            hand: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M8 14V5a2 2 0 1 1 4 0v7"></path><path d="M12 5v9"></path><path d="M16 7v7"></path><path d="M8 14c0 3 2.2 5 4 5 1.8 0 4-2 4-5"></path></svg>',
            'shopping-bag': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V10a2 2 0 0 1 2-2Z"></path><path d="M9 8V7a3 3 0 1 1 6 0v1"></path></svg>',
            'car-front': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 11h14a2 2 0 0 1 2 2v3H2v-3a2 2 0 0 1 2-2Z"></path><path d="M6 11V8a2 2 0 0 1 2-2h4"></path><path d="M14 11V8h3"></path><circle cx="8" cy="16" r="1.5"></circle><circle cx="16" cy="16" r="1.5"></circle></svg>',
            bus: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h12a2 2 0 0 1 2 2v5H4V7Z"></path><path d="M8 14h8"></path><path d="M5 19h2"></path><path d="M15 19h2"></path><path d="M4 10h16"></path><circle cx="8" cy="17" r="1.5"></circle><circle cx="16" cy="17" r="1.5"></circle></svg>',
            'layout-dashboard': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="7" height="7" rx="1"></rect><rect x="13" y="4" width="7" height="4" rx="1"></rect><rect x="13" y="12" width="7" height="8" rx="1"></rect><rect x="4" y="13" width="7" height="7" rx="1"></rect></svg>',
            default: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8"></circle><path d="M12 8v4l2 2"></path></svg>'
        };

        function renderOfflineIcons(root = document) {
            root.querySelectorAll('i[data-lucide]').forEach((icon) => {
                const name = icon.getAttribute('data-lucide');
                const svg = offlineIconMap[name] || offlineIconMap.default;
                if (!svg) return;
                const span = document.createElement('span');
                span.setAttribute('aria-hidden', 'true');
                span.className = 'inline-flex items-center justify-center ' + (icon.className || '');
                if (icon.getAttribute('style')) span.setAttribute('style', icon.getAttribute('style'));
                span.innerHTML = svg;
                const svgEl = span.querySelector('svg');
                if (svgEl) {
                    svgEl.setAttribute('width', '100%');
                    svgEl.setAttribute('height', '100%');
                    svgEl.style.display = 'block';
                }
                icon.replaceWith(span);
            });
        }

        function toggleAI() { document.getElementById('ai-panel').classList.toggle('hidden'); }
        async function sendAiFullMsg() {
            const input = document.getElementById('ai-full-input'), msg = input.value.trim();
            if (!msg) return;
            appendAiMsg('ai-full-messages', msg, 'user'); input.value = '';
            await aiReply('ai-full-messages', msg);
        }
        async function sendAiMiniMsg() {
            const input = document.getElementById('ai-mini-input'), msg = input.value.trim();
            if (!msg) return;
            appendAiMsg('ai-mini-chat', msg, 'user'); input.value = '';
            await aiReply('ai-mini-chat', msg);
        }
        function appendAiMsg(containerId, msg, role) {
            const c = document.getElementById(containerId);
            c.innerHTML += `<div class="ai-bubble flex ${role==='user'?'justify-end':''}"><div class="${role==='user'?'bg-[#63dcbe]/20 rounded-xl rounded-tr-sm':'bg-[#0f1219] rounded-xl rounded-tl-sm'} p-3 text-sm max-w-[85%] text-white">${escapeHtml(msg)}</div></div>`;
            c.scrollTop = c.scrollHeight;
        }
        async function aiReply(containerId, msg) {
            const c = document.getElementById(containerId);
            const tid = 'typing-'+Date.now();
            c.innerHTML += `<div class="ai-bubble bg-[#0f1219] rounded-xl rounded-tl-sm p-3 text-sm text-gray-400" id="${tid}">Thinking...</div>`;
            try {
                const res = await fetch('ai_handler.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({message:msg})});
                const data = await res.json();
                document.getElementById(tid)?.remove();
                c.innerHTML += `<div class="ai-bubble bg-[#0f1219] rounded-xl rounded-tl-sm p-3 text-sm text-gray-300">${data.reply || 'Sorry, try again.'}</div>`;
            } catch {
                document.getElementById(tid)?.remove();
                c.innerHTML += `<div class="ai-bubble bg-red-500/10 rounded-xl rounded-tl-sm p-3 text-sm text-red-300">Error connecting to AI.</div>`;
            }
            c.scrollTop = c.scrollHeight;
        }
        function escapeHtml(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
        function toggleSearch() { /* optional */ }
    </script>


</body>
</html>