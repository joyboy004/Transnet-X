<?php
include("../config/db.php");

$error = $success = "";
$image_url_path = "";

if (isset($_POST['register'])) {
    // Sanitize inputs
    $name            = trim($_POST['name'] ?? '');
    $surname         = trim($_POST['surname'] ?? '');
    $phone           = trim($_POST['phone'] ?? '');
    $email           = trim($_POST['email'] ?? '');
    $license_number  = trim($_POST['license_number'] ?? '');
    $vehicle_make    = trim($_POST['vehicle_make'] ?? '');
    $vehicle_model   = trim($_POST['vehicle_model'] ?? '');
    $vehicle_year    = trim($_POST['vehicle_year'] ?? '');
    $plate_number    = trim($_POST['plate_number'] ?? '');
    $account_name    = trim($_POST['account_name'] ?? '');
    $account_number  = trim($_POST['account_number'] ?? '');
    $bank_name       = trim($_POST['bank_name'] ?? '');
    $password        = $_POST['password'] ?? '';

    // Validate required fields
    $required = [
        'name' => $name,
        'surname' => $surname,
        'phone' => $phone,
        'email' => $email,
        'license_number' => $license_number,
        'plate_number' => $plate_number,
        'account_name' => $account_name,
        'account_number' => $account_number,
        'bank_name' => $bank_name,
        'password' => $password
    ];
    $missing = array_keys(array_filter($required, fn($v) => empty($v)));
    if (!empty($missing)) {
        $error = '❌ Missing fields: ' . implode(', ', $missing);
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = '❌ Invalid email format.';
    } elseif (strlen($password) < 8) {
        $error = '❌ Password must be at least 8 characters.';
    } else {
        // Validate and format vehicle_year for YEAR(4) column
        $year_int = null;
        if (!empty($vehicle_year) && is_numeric($vehicle_year)) {
            $year_int = (int)$vehicle_year;
            if ($year_int < 1900 || $year_int > 2100) {
                    $error = '❌ Invalid vehicle year. Use a 4-digit year like 2022.';
        }

        if (empty($error)) {
            // Handle car photo upload - store with original name pattern
            if (!empty($_FILES['car_photo']['name'])) {
                $upload_dir = '../uploads/cars/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                $allowed = ['jpg', 'jpeg', 'png', 'webp'];
                $ext = strtolower(pathinfo($_FILES['car_photo']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed)) {
                    $error = '❌ Invalid file type. Allowed: JPG, PNG, WEBP.';
                } elseif ($_FILES['car_photo']['size'] > 5 * 1024 * 1024) {
                    $error = '❌ File size exceeds 5MB.';
                } else {
                    // Create filename: timestamp_original_sanitized_name
                    $original_name = pathinfo($_FILES['car_photo']['name'], PATHINFO_FILENAME);
                    $sanitized_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $original_name);
                    $final_filename = time() . '_' . $sanitized_name . '.' . $ext;
                    $image_url_path = $upload_dir . $final_filename;
                    
                    if (!move_uploaded_file($_FILES['car_photo']['tmp_name'], $image_url_path)) {
                        $error = '❌ Failed to upload car photo.';
                    }
                }
            }

            // If no upload error, proceed with database insertion
            if (empty($error)) {
                // Check email existence
                $stmt = $conn->prepare("SELECT driver_id FROM drivers WHERE email = ?");
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $stmt->store_result();
                if ($stmt->num_rows > 0) {
                    $error = '❌ Email already exists!';
                } else {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                    // Prepare INSERT with all required columns matching the schema
                    $query = "INSERT INTO drivers 
                        (name, surname, phone, email, license_number, 
                         vehicle_make, vehicle_model, vehicle_year, plate_number,
                         image_url, account_name, account_number, bank_name, password,
                         status, balance, rating, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'offline', 0.00, 0.00, NOW())";

                    $stmt = $conn->prepare($query);
                    $stmt->bind_param(
                        "ssssssssssssss",
                        $name,
                        $surname,
                        $phone,
                        $email,
                        $license_number,
                        $vehicle_make,
                        $vehicle_model,
                        $year_int,
                        $plate_number,
                        $image_url_path,
                        $account_name,
                        $account_number,
                        $bank_name,
                        $hashed_password
                    );

                    if ($stmt->execute()) {
                        $success = '✅ Driver account created! <a href="../index.php" style="color:#f0b429;">Login</a>';
                    } else {
                        $error = '❌ Registration failed: ' . $conn->error;
                    }
                }
                $stmt->close();
            }
        }
    }
}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>TransitX — Driver Registration</title>
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,wght@0,300;0,400;0,500;0,600;1,400&display=swap" rel="stylesheet"/>
  <style>
    /* All CSS unchanged (same as previous version) */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --black:  #08080d; --navy:   #0d1117; --gold:   #f0b429; --gold2:  #ffd166;
      --white:  #f4f4f0; --muted:  #6b7280; --border: rgba(240,180,41,0.18);
      --glass:  rgba(255,255,255,0.04); --red:    #ef4444; --green:  #22c55e;
    }
    html { scroll-behavior: smooth; }
    body {
      font-family: 'DM Sans', sans-serif; background: var(--black);
      color: var(--white); min-height: 100vh; overflow-x: hidden;
    }
    .bg-scene { position: fixed; inset: 0; z-index: 0; overflow: hidden; pointer-events: none; }
    .bg-scene::before {
      content: ''; position: absolute; inset: 0;
      background: radial-gradient(ellipse 80% 55% at 50% 0%, rgba(240,180,41,0.08) 0%, transparent 70%),
                  radial-gradient(ellipse 60% 40% at 0% 100%, rgba(240,180,41,0.04) 0%, transparent 60%),
                  linear-gradient(180deg, #08080d 0%, #0d1420 50%, #08080d 100%);
    }
    .road-lane {
      position: absolute; left: 50%; transform: translateX(-50%);
      width: 4px; height: 100%;
      background: repeating-linear-gradient(to top, var(--gold) 0px, var(--gold) 40px, transparent 40px, transparent 80px);
      opacity: 0.1; animation: roadScroll 1.8s linear infinite;
    }
    .road-lane:nth-child(2) { left: calc(50% - 90px); opacity: 0.055; animation-delay: -0.6s; }
    .road-lane:nth-child(3) { left: calc(50% + 90px); opacity: 0.055; animation-delay: -1.2s; }
    @keyframes roadScroll { to { background-position: 0 80px; } }
    .particles { position: absolute; inset: 0; }
    .particle {
      position: absolute; border-radius: 50%; background: var(--gold); opacity: 0;
      animation: floatUp linear infinite;
    }
    @keyframes floatUp {
      0% { transform: translateY(100vh) scale(0); opacity: 0; }
      10% { opacity: 0.35; } 90% { opacity: 0.08; }
      100% { transform: translateY(-5vh) scale(1); opacity: 0; }
    }
    nav {
      position: fixed; top: 0; left: 0; right: 0; z-index: 100;
      display: flex; align-items: center; justify-content: space-between;
      padding: 1.1rem 3rem; background: rgba(8,8,13,0.75);
      backdrop-filter: blur(20px); border-bottom: 1px solid var(--border);
    }
    .logo {
      font-family: 'Bebas Neue', sans-serif; font-size: 1.9rem; letter-spacing: 4px;
      color: var(--gold); text-decoration: none; display: flex; align-items: center; gap: 0.55rem;
    }
    .logo svg { width: 26px; height: 26px; fill: var(--gold); }
    .nav-tagline { font-size: 0.68rem; letter-spacing: 3px; text-transform: uppercase; color: var(--muted); }
    .nav-links { display: flex; gap: 2rem; }
    .nav-links a {
      color: var(--muted); font-size: 0.82rem; letter-spacing: 1px;
      text-transform: uppercase; text-decoration: none; transition: color 0.3s;
    }
    .nav-links a:hover { color: var(--gold); }
    main {
      position: relative; z-index: 10; min-height: 100vh;
      display: grid; grid-template-columns: 1fr 1fr; padding-top: 68px;
    }
    .hero-panel {
      display: flex; flex-direction: column; justify-content: center;
      padding: 5rem 4rem; position: relative; overflow: hidden;
    }
    .hero-eyebrow {
      font-size: 0.7rem; letter-spacing: 5px; text-transform: uppercase;
      color: var(--gold); margin-bottom: 1.4rem; display: flex; align-items: center;
      gap: 0.75rem; opacity: 0; animation: slideUp 0.8s 0.2s forwards;
    }
    .hero-eyebrow::before { content: ''; width: 32px; height: 2px; background: var(--gold); }
    .hero-title {
      font-family: 'Bebas Neue', sans-serif; font-size: clamp(3.5rem, 5.5vw, 6rem);
      line-height: 0.92; letter-spacing: 2px; margin-bottom: 1.4rem;
      opacity: 0; animation: slideUp 0.8s 0.35s forwards;
    }
    .hero-title .accent { color: var(--gold); display: block; }
    .hero-desc {
      font-size: 0.97rem; line-height: 1.75; color: rgba(244,244,240,0.55);
      max-width: 380px; margin-bottom: 3rem; opacity: 0; animation: slideUp 0.8s 0.5s forwards;
    }
    .stats { display: flex; gap: 2.8rem; opacity: 0; animation: slideUp 0.8s 0.65s forwards; }
    .stat-num { font-family: 'Bebas Neue', sans-serif; font-size: 2.1rem; color: var(--gold); letter-spacing: 2px; }
    .stat-label { font-size: 0.68rem; letter-spacing: 2px; text-transform: uppercase; color: var(--muted); }
    .deco-bus {
      position: absolute; bottom: -30px; right: -80px; width: 480px;
      opacity: 0.035; animation: busDrift 9s ease-in-out infinite alternate;
    }
    @keyframes busDrift { from { transform: translateX(0) rotate(-1deg); } to { transform: translateX(18px) rotate(1deg); } }
    .form-panel {
      display: flex; align-items: flex-start; justify-content: center;
      padding: 3rem 3.5rem 5rem 1.5rem; overflow-y: auto;
    }
    .form-card {
      width: 100%; max-width: 560px; background: var(--glass);
      border: 1px solid var(--border); border-radius: 26px; padding: 3rem 2.8rem;
      backdrop-filter: blur(32px);
      box-shadow: 0 40px 80px rgba(0,0,0,0.65), 0 0 0 1px rgba(255,255,255,0.03) inset,
                  0 1px 0 rgba(255,255,255,0.06) inset;
      opacity: 0; animation: fadeIn 0.9s 0.4s forwards;
    }
    .form-header { margin-bottom: 2rem; }
    .form-title { font-family: 'Bebas Neue', sans-serif; font-size: 1.95rem; letter-spacing: 3px; color: var(--white); margin-bottom: 0.25rem; }
    .form-sub { font-size: 0.8rem; color: var(--muted); letter-spacing: 0.3px; }
    .progress-bar { height: 3px; background: rgba(255,255,255,0.07); border-radius: 99px; margin-bottom: 2rem; overflow: hidden; }
    .progress-fill { height: 100%; width: 0%; background: linear-gradient(90deg, var(--gold), var(--gold2)); border-radius: 99px; transition: width 0.45s; }
    .section-label {
      font-size: 0.65rem; letter-spacing: 3px; text-transform: uppercase;
      color: var(--gold); margin: 1.6rem 0 1rem; display: flex; align-items: center; gap: 0.7rem;
    }
    .section-label::after { content: ''; flex: 1; height: 1px; background: var(--border); }
    .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
    .form-grid .full { grid-column: 1 / -1; }
    .field { display: flex; flex-direction: column; gap: 0.35rem; }
    .field label { font-size: 0.68rem; letter-spacing: 2px; text-transform: uppercase; color: var(--muted); transition: color 0.3s; }
    .field:focus-within label { color: var(--gold); }
    .field-wrap { position: relative; display: flex; align-items: center; }
    .field-icon { position: absolute; left: 13px; width: 15px; height: 15px; color: var(--muted); transition: color 0.3s; pointer-events: none; }
    .field:focus-within .field-icon { color: var(--gold); }
    .field input, .field select {
      width: 100%; background: rgba(255,255,255,0.05);
      border: 1px solid rgba(255,255,255,0.09); border-radius: 12px;
      padding: 0.82rem 0.9rem 0.82rem 2.5rem; color: var(--white);
      font-family: 'DM Sans', sans-serif; font-size: 0.9rem; outline: none;
      transition: border-color 0.3s, background 0.3s, box-shadow 0.3s;
      appearance: none; -webkit-appearance: none;
    }
    .field select {
      cursor: pointer;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
      background-repeat: no-repeat; background-position: right 13px center;
      padding-right: 2.4rem; background-color: rgba(255,255,255,0.05);
    }
    .field select option { background: #1a1a2e; color: var(--white); }
    .field input::placeholder { color: rgba(255,255,255,0.18); font-size: 0.83rem; }
    .field input:focus, .field select:focus {
      border-color: var(--gold); background: rgba(240,180,41,0.06);
      box-shadow: 0 0 0 3px rgba(240,180,41,0.13), 0 4px 16px rgba(0,0,0,0.25);
    }
    .field input.valid   { border-color: var(--green); }
    .field input.invalid { border-color: var(--red); }
    .field-msg { font-size: 0.67rem; letter-spacing: 0.5px; min-height: 14px; color: var(--red); }
    .strength-bar { display: flex; gap: 4px; margin-top: 5px; }
    .strength-seg { flex: 1; height: 3px; border-radius: 99px; background: rgba(255,255,255,0.08); transition: background 0.35s; }
    .strength-label { font-size: 0.67rem; color: var(--muted); margin-top: 3px; letter-spacing: 1px; }
    .pw-toggle {
      position: absolute; right: 11px; background: none; border: none;
      cursor: pointer; color: var(--muted); transition: color 0.3s;
      display: flex; align-items: center; padding: 0;
    }
    .pw-toggle:hover { color: var(--gold); }
    .upload-wrap {
      position: relative; width: 100%; background: rgba(255,255,255,0.05);
      border: 1px dashed rgba(240,180,41,0.3); border-radius: 12px;
      padding: 1.4rem 1rem; text-align: center; cursor: pointer;
      transition: border-color 0.3s, background 0.3s;
    }
    .upload-wrap:hover, .upload-wrap.dragover { border-color: var(--gold); background: rgba(240,180,41,0.06); }
    .upload-wrap input[type="file"] { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
    .upload-icon-svg { width: 24px; height: 24px; color: var(--muted); margin: 0 auto 0.45rem; display: block; }
    .upload-hint { font-size: 0.8rem; color: var(--muted); line-height: 1.5; }
    .upload-hint strong { color: var(--gold); }
    .upload-filename { margin-top: 0.5rem; font-size: 0.75rem; color: var(--green); display: none; letter-spacing: 0.3px; }
    .btn-submit {
      width: 100%; margin-top: 1.7rem; padding: 1rem;
      background: linear-gradient(135deg, var(--gold) 0%, var(--gold2) 100%);
      color: #08080d; font-family: 'Bebas Neue', sans-serif;
      font-size: 1.18rem; letter-spacing: 4px; border: none; border-radius: 14px;
      cursor: pointer; position: relative; overflow: hidden;
      transition: transform 0.2s, box-shadow 0.3s;
      box-shadow: 0 8px 28px rgba(240,180,41,0.28);
    }
    .btn-submit::before {
      content: ''; position: absolute; inset: 0;
      background: linear-gradient(135deg, transparent 0%, rgba(255,255,255,0.22) 50%, transparent 100%);
      transform: translateX(-100%); transition: transform 0.55s;
    }
    .btn-submit:hover::before { transform: translateX(100%); }
    .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 16px 44px rgba(240,180,41,0.42); }
    .btn-submit:active { transform: translateY(0); }
    .btn-submit.loading { pointer-events: none; opacity: 0.8; }
    .spinner {
      display: none; width: 18px; height: 18px; margin: 0 auto;
      border: 2px solid rgba(8,8,13,0.3); border-top-color: #08080d;
      border-radius: 50%; animation: spin 0.7s linear infinite;
    }
    .btn-submit.loading .btn-text { display: none; }
    .btn-submit.loading .spinner { display: block; }
    @keyframes spin { to { transform: rotate(360deg); } }
    .login-link { text-align: center; margin-top: 1.3rem; font-size: 0.8rem; color: var(--muted); }
    .login-link a { color: var(--gold); text-decoration: none; font-weight: 500; }
    .login-link a:hover { text-decoration: underline; }
    .success-overlay {
      display: none; position: fixed; inset: 0; z-index: 999;
      background: rgba(8,8,13,0.93); backdrop-filter: blur(12px);
      align-items: center; justify-content: center;
      flex-direction: column; gap: 1.4rem; text-align: center;
    }
    .success-overlay.show { display: flex; animation: fadeIn 0.5s forwards; }
    .success-icon {
      width: 78px; height: 78px; border-radius: 50%;
      background: linear-gradient(135deg, var(--gold), var(--gold2));
      display: flex; align-items: center; justify-content: center;
      animation: popIn 0.45s 0.1s both;
    }
    .success-icon svg { width: 34px; height: 34px; stroke: #08080d; stroke-width: 2.5; }
    .success-title { font-family: 'Bebas Neue', sans-serif; font-size: 2.6rem; letter-spacing: 4px; color: var(--gold); animation: slideUp 0.5s 0.2s both; }
    .success-msg { color: var(--muted); font-size: 0.93rem; max-width: 300px; animation: slideUp 0.5s 0.3s both; }
    .ticker {
      position: fixed; bottom: 0; left: 0; right: 0; z-index: 50;
      background: var(--gold); padding: 0.45rem 0; overflow: hidden;
    }
    .ticker-track { display: flex; gap: 3.5rem; white-space: nowrap; animation: tickerMove 30s linear infinite; }
    .ticker-item {
      font-family: 'Bebas Neue', sans-serif; font-size: 0.82rem;
      letter-spacing: 3px; color: #08080d; display: flex; align-items: center; gap: 0.6rem;
    }
    .ticker-dot { width: 5px; height: 5px; border-radius: 50%; background: rgba(8,8,13,0.35); }
    @keyframes tickerMove { from { transform: translateX(0); } to { transform: translateX(-50%); } }
    @keyframes slideUp { from { opacity: 0; transform: translateY(28px); } to { opacity: 1; transform: translateY(0); } }
    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
    @keyframes popIn  { from { transform: scale(0); } to { transform: scale(1); } }
    @media (max-width: 900px) {
      main { grid-template-columns: 1fr; }
      .hero-panel { padding: 3rem 2rem 2rem; }
      .deco-bus { display: none; }
      nav { padding: 1rem 1.5rem; }
      .nav-links { display: none; }
      .form-panel { padding: 1.5rem 1.5rem 5rem; }
      .form-card { padding: 2rem 1.6rem; }
    }
    @media (max-width: 480px) {
      .form-grid { grid-template-columns: 1fr; }
      .form-grid .full { grid-column: 1; }
    }
    ::-webkit-scrollbar { width: 5px; }
    ::-webkit-scrollbar-track { background: var(--black); }
    ::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }
  </style>
</head>
<body>
<div class="bg-scene">
  <div class="road-lane"></div><div class="road-lane"></div><div class="road-lane"></div>
  <div class="particles" id="particles"></div>
</div>

<nav>
  <a href="#" class="logo">
    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.21.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.5 16c-.83 0-1.5-.67-1.5-1.5S5.67 13 6.5 13s1.5.67 1.5 1.5S7.33 16 6.5 16zm11 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zM5 11l1.5-4.5h11L19 11H5z"/></svg>
    TransNet X
  </a>
  <span class="nav-tagline">Drive Smarter. Earn More.</span>
  <div class="nav-links">
    <a href="#">Routes</a><a href="#">Fleet</a><a href="#">Track</a><a href="../index.php">Login</a>
  </div>
</nav>

<main>
  <div class="hero-panel">
    <p class="hero-eyebrow">Join the network</p>
    <h1 class="hero-title">Your Wheel.<br><span class="accent">Your Income.</span></h1>
    <p class="hero-desc">Register as a TransNet X driver and gain access to live trip requests, deliver goods, instant payouts, and a fleet of passengers waiting for you right now.</p>
    <div class="stats">
      <div class="stat-item"><div class="stat-num">12K+</div><div class="stat-label">Active Drivers</div></div>
      <div class="stat-item"><div class="stat-num">98%</div><div class="stat-label">Satisfaction</div></div>
      <div class="stat-item"><div class="stat-num">$48</div><div class="stat-label">Avg / Hour</div></div>
    </div>
    <svg class="deco-bus" viewBox="0 0 640 280" xmlns="http://www.w3.org/2000/svg" fill="white">
      <rect x="40" y="90" width="480" height="140" rx="18"/>
      <rect x="460" y="70" width="100" height="50" rx="8"/>
      <rect x="60" y="110" width="90" height="60" rx="4" fill="#08080d" opacity="0.3"/>
      <rect x="170" y="110" width="90" height="60" rx="4" fill="#08080d" opacity="0.3"/>
      <rect x="280" y="110" width="90" height="60" rx="4" fill="#08080d" opacity="0.3"/>
      <rect x="390" y="110" width="90" height="60" rx="4" fill="#08080d" opacity="0.3"/>
      <circle cx="130" cy="245" r="28"/><circle cx="460" cy="245" r="28"/>
      <rect x="20" y="185" width="520" height="8" rx="4" fill="#08080d" opacity="0.15"/>
    </svg>
  </div>

  <div class="form-panel">
    <div class="form-card">
      <div class="form-header">
        <h2 class="form-title">Driver Registration</h2>
        <p class="form-sub">Complete all fields to activate your driver account</p>
      </div>
      <div class="progress-bar"><div class="progress-fill" id="progressFill"></div></div>

      <form method="POST" enctype="multipart/form-data" id="regForm" novalidate>
        <!-- PERSONAL INFO -->
        <div class="section-label">Personal Information</div>
        <div class="form-grid">
          <div class="field">
            <label for="name">First Name</label>
            <div class="field-wrap">
              <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              <input type="text" id="name" name="name" placeholder="Muslim" autocomplete="given-name" value="<?php echo htmlspecialchars($name ?? ''); ?>" required/>
            </div>
            <span class="field-msg" id="firstNameMsg"></span>
          </div>
          <div class="field">
            <label for="surname">Surname</label>
            <div class="field-wrap">
              <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              <input type="text" id="surname" name="surname" placeholder="Bello" autocomplete="family-name" value="<?php echo htmlspecialchars($surname ?? ''); ?>" required/>
            </div>
            <span class="field-msg" id="surnameMsg"></span>
          </div>
          <div class="field">
            <label for="phone">Phone Number</label>
            <div class="field-wrap">
              <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 1.23h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.84a16 16 0 0 0 6 6l.96-.96a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
              <input type="tel" id="phone" name="phone" placeholder="091234455" autocomplete="tel" value="<?php echo htmlspecialchars($phone ?? ''); ?>" required/>
            </div>
            <span class="field-msg" id="phoneMsg"></span>
          </div>
          <div class="field">
            <label for="email">Email Address</label>
            <div class="field-wrap">
              <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
              <input type="email" id="email" name="email" placeholder="Muslim@example.com" autocomplete="email" value="<?php echo htmlspecialchars($email ?? ''); ?>" required/>
            </div>
            <span class="field-msg" id="emailMsg"></span>
          </div>
        </div>

        <!-- VEHICLE & LICENSE -->
        <div class="section-label">Vehicle &amp; License</div>
        <div class="form-grid">
          <div class="field full">
            <label for="license_number">License Registration Number</label>
            <div class="field-wrap">
              <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
              <input type="text" id="license_number" name="license_number" placeholder="e.g. DL-2024-XXXX-XXXXX" value="<?php echo htmlspecialchars($license_number ?? ''); ?>" required/>
            </div>
            <span class="field-msg" id="licenseMsg"></span>
          </div>
          <div class="field">
            <label for="vehicle_make">Vehicle Make</label>
            <div class="field-wrap">
              <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 17H3a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11a2 2 0 0 1 2 2v3"/><rect x="9" y="11" width="14" height="10" rx="2"/><circle cx="12" cy="21" r="1"/><circle cx="20" cy="21" r="1"/></svg>
              <input type="text" id="vehicle_make" name="vehicle_make" placeholder="e.g. Toyota" value="<?php echo htmlspecialchars($vehicle_make ?? ''); ?>"/>
            </div>
          </div>
          <div class="field">
            <label for="vehicle_model">Vehicle Model</label>
            <div class="field-wrap">
              <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 17H3a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11a2 2 0 0 1 2 2v3"/><rect x="9" y="11" width="14" height="10" rx="2"/><circle cx="12" cy="21" r="1"/><circle cx="20" cy="21" r="1"/></svg>
              <input type="text" id="vehicle_model" name="vehicle_model" placeholder="e.g. Camry" value="<?php echo htmlspecialchars($vehicle_model ?? ''); ?>"/>
            </div>
          </div>
          <div class="field">
            <label for="vehicle_year">Year</label>
            <div class="field-wrap">
              <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
              <input type="number" id="vehicle_year" name="vehicle_year" placeholder="2022" min="1900" max="2100" value="<?php echo htmlspecialchars($vehicle_year ?? ''); ?>"/>
            </div>
          </div>
          <div class="field full">
            <label for="plate_number">Plate Number</label>
            <div class="field-wrap">
              <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="14" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
              <input type="text" id="plate_number" name="plate_number" placeholder="e.g. ABC-1234" value="<?php echo htmlspecialchars($plate_number ?? ''); ?>" required/>
            </div>
            <span class="field-msg" id="plateMsg"></span>
          </div>
          <div class="field full">
            <label>Car Photo</label>
            <div class="upload-wrap" id="uploadZone">
              <input type="file" name="car_photo" id="car_photo" accept="image/*" onchange="handleUpload(this)"/>
              <svg class="upload-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
              <p class="upload-hint">Drag &amp; drop or <strong>click to upload</strong><br><span style="font-size:0.72rem;">JPG, PNG, WEBP — Max 5MB</span></p>
              <div class="upload-filename" id="uploadFilename"></div>
            </div>
          </div>
        </div>

        <!-- BANKING -->
        <div class="section-label">Banking &amp; Payments</div>
        <div class="form-grid">
          <div class="field full">
            <label for="account_name">Credit Account Name</label>
            <div class="field-wrap">
              <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              <input type="text" id="account_name" name="account_name" placeholder="Full name as on bank account" value="<?php echo htmlspecialchars($account_name ?? ''); ?>" required/>
            </div>
            <span class="field-msg" id="accountNameMsg"></span>
          </div>
          <div class="field full">
            <label for="account_number">Account Number</label>
            <div class="field-wrap">
              <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
              <input type="text" id="account_number" name="account_number" placeholder="XXXX XXXX XXXX XXXX" maxlength="19" value="<?php echo htmlspecialchars($account_number ?? ''); ?>" required/>
            </div>
            <span class="field-msg" id="accountNumberMsg"></span>
          </div>
          <div class="field full">
            <label for="bank_name">Bank Name</label>
            <div class="field-wrap">
              <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="22" x2="21" y2="22"/><line x1="6" y1="18" x2="6" y2="11"/><line x1="10" y1="18" x2="10" y2="11"/><line x1="14" y1="18" x2="14" y2="11"/><line x1="18" y1="18" x2="18" y2="11"/><polygon points="12 2 20 7 4 7"/></svg>
              <select id="bank_name" name="bank_name" required>
                <option value="" disabled <?php echo empty($bank_name) ? 'selected' : ''; ?>>Select your bank</option>
                <?php
                $banks = ['Bank of America','Chase Bank','Wells Fargo','Citibank','Capital One','US Bank','TD Bank','PNC Bank','Barclays','HSBC','First National Bank','Standard Bank','Zenith Bank','GTBank','Access Bank','Other'];
                foreach ($banks as $b) {
                  $selected = ($bank_name === $b) ? 'selected' : '';
                  echo "<option $selected>$b</option>";
                }
                ?>
              </select>
            </div>
            <span class="field-msg" id="bankMsg"></span>
          </div>
        </div>

        <!-- SECURITY -->
        <div class="section-label">Security</div>
        <div class="form-grid">
          <div class="field full">
            <label for="password">Password</label>
            <div class="field-wrap">
              <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              <input type="password" id="password" name="password" placeholder="Min. 8 characters" required/>
              <button type="button" class="pw-toggle" id="pwToggle" aria-label="Toggle password">
                <svg id="eyeIcon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                </svg>
              </button>
            </div>
            <div class="strength-bar">
              <div class="strength-seg" id="s1"></div><div class="strength-seg" id="s2"></div>
              <div class="strength-seg" id="s3"></div><div class="strength-seg" id="s4"></div>
            </div>
            <div class="strength-label" id="strengthLabel"></div>
            <span class="field-msg" id="passwordMsg"></span>
          </div>
        </div>

        <button type="submit" class="btn-submit" id="submitBtn" name="register">
          <span class="btn-text">REGISTER AS DRIVER</span>
          <div class="spinner"></div>
        </button>
        <p class="login-link">Already registered? <a href="../index.php">Sign in here</a></p>
      </form>
    </div>
  </div>
</main>

<!-- Success/Error Overlay -->
<div class="success-overlay <?php echo ($success || $error) ? 'show' : ''; ?>" id="successOverlay">
  <div class="success-icon">
    <svg viewBox="0 0 24 24" fill="none" stroke-linecap="round" stroke-linejoin="round">
      <polyline points="20 6 9 17 4 12"/>
    </svg>
  </div>
  <div class="success-title"><?php echo $success ? "You're On The Road!" : "Oops!"; ?></div>
  <div class="success-msg"><?php echo $success ? $success : $error; ?></div>
  <?php if ($success): ?>
  <p class="success-msg">Your TransNetX driver account is live. Start accepting trips and earning today.</p>
  <?php endif; ?>
  <a href="../index.php" style="color:var(--gold); margin-top:1rem;">← Back to login</a>
</div>

<!-- Ticker -->
<div class="ticker">
  <div class="ticker-track" id="tickerTrack"></div>
</div>

<script>
// Particles
(function(){
  const c = document.getElementById('particles');
  for(let i=0; i<20; i++){
    const p = document.createElement('div');
    p.className = 'particle';
    const sz = Math.random()*4+2;
    p.style.cssText = `width:${sz}px;height:${sz}px;left:${Math.random()*100}%;animation-duration:${Math.random()*10+8}s;animation-delay:${Math.random()*12}s;`;
    c.appendChild(p);
  }
})();

// Ticker
(function(){
  const msgs = ['🚗 Live Trip Dispatch','🛣️ 180+ Routes','⚡ Instant Payouts','🔒 Secure Rides','🌍 Cross-Border Trips','📍 Live GPS','💳 Weekly Earnings','🛠️ Fleet Support','📱 Driver App','🏆 Top Driver Bonuses'];
  const track = document.getElementById('tickerTrack');
  msgs.forEach(m => {
    const el = document.createElement('span');
    el.className = 'ticker-item';
    el.innerHTML = `<span class="ticker-dot"></span>${m}`;
    track.appendChild(el);
  });
})();

// Validation config
fields = [
  { id:'name',            msg:'firstNameMsg',     test: v => v.trim().length >= 2 ? '' : 'At least 2 characters required.' },
  { id:'surname',         msg:'surnameMsg',       test: v => v.trim().length >= 2 ? '' : 'At least 2 characters required.' },
  { id:'phone',           msg:'phoneMsg',         test: v => /^[\+\d\s\-\(\)]{7,20}$/.test(v.trim()) ? '' : 'Enter a valid phone number.' },
  { id:'email',           msg:'emailMsg',         test: v => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v.trim()) ? '' : 'Enter a valid email.' },
  { id:'license_number',  msg:'licenseMsg',       test: v => v.trim().length >= 4 ? '' : 'Enter a valid license number.' },
  { id:'plate_number',    msg:'plateMsg',         test: v => v.trim().length >= 2 ? '' : 'Enter a valid plate number.' },
  { id:'account_name',    msg:'accountNameMsg',   test: v => v.trim().length >= 2 ? '' : 'Account name is required.' },
  { id:'account_number',  msg:'accountNumberMsg', test: v => v.replace(/\s/g,'').length >= 8 ? '' : 'Enter a valid account number.' },
  { id:'bank_name',       msg:'bankMsg',          test: v => v ? '' : 'Please select your bank.' },
  { id:'password',        msg:'passwordMsg',      test: v => v.length >= 8 ? '' : 'Password must be at least 8 characters.' },
];

function updateProgress(){
  const done = fields.filter(f => {
    const el = document.getElementById(f.id);
    return el && el.value.trim() && !f.test(el.value);
  }).length;
  document.getElementById('progressFill').style.width = (done / fields.length * 100) + '%';
}

fields.forEach(({ id, msg, test }) => {
  const input = document.getElementById(id);
  if(!input) return;
  const msgEl = document.getElementById(msg);
  const check = () => {
    const err = test(input.value);
    if(msgEl) msgEl.textContent = err;
    input.classList.toggle('invalid', !!err && input.value !== '');
    input.classList.toggle('valid',   !err && input.value !== '');
    updateProgress();
  };
  input.addEventListener('input', check);
  input.addEventListener('blur', check);
});

// Account number formatting
document.getElementById('account_number').addEventListener('input', function(){
  this.value = this.value.replace(/\D/g,'').replace(/(.{4})/g,'$1 ').trim().slice(0,19);
});

// Password strength
document.getElementById('password').addEventListener('input', function(){
  const v = this.value;
  let score = 0;
  if(v.length >= 8) score++;
  if(/[A-Z]/.test(v)) score++;
  if(/[0-9]/.test(v)) score++;
  if(/[^A-Za-z0-9]/.test(v)) score++;
  const colors = ['','#ef4444','#f0b429','#3b82f6','#22c55e'];
  const labels = ['','Weak','Fair','Good','Strong'];
  ['s1','s2','s3','s4'].forEach((s,i) => {
    document.getElementById(s).style.background = i < score ? colors[score] : 'rgba(255,255,255,0.08)';
  });
  const lbl = document.getElementById('strengthLabel');
  lbl.textContent = v ? labels[score] : '';
  lbl.style.color = colors[score] || 'var(--muted)';
});

// Password toggle
document.getElementById('pwToggle').addEventListener('click', function(){
  const pw = document.getElementById('password');
  const show = pw.type === 'password';
  pw.type = show ? 'text' : 'password';
  document.getElementById('eyeIcon').innerHTML = show
    ? '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/>'
    : '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
});

// File upload
function handleUpload(input){
  const file = input.files[0];
  if(!file) return;
  const zone = document.getElementById('uploadZone');
  const fn   = document.getElementById('uploadFilename');
  fn.textContent = '✔️ ' + file.name;
  fn.style.display = 'block';
  zone.style.borderColor = 'var(--green)';
  zone.style.background  = 'rgba(34,197,94,0.05)';
}
const uploadZone = document.getElementById('uploadZone');
uploadZone.addEventListener('dragover', e => { e.preventDefault(); uploadZone.classList.add('dragover'); });
uploadZone.addEventListener('dragleave', () => uploadZone.classList.remove('dragover'));
uploadZone.addEventListener('drop', e => {
  e.preventDefault(); uploadZone.classList.remove('dragover');
  const file = e.dataTransfer.files[0];
  if(file){ document.getElementById('car_photo').files = e.dataTransfer.files; handleUpload(document.getElementById('car_photo')); }
});

// Form submit validation
document.getElementById('regForm').addEventListener('submit', function(e){
  let allValid = true;
  fields.forEach(({ id, msg, test }) => {
    const input = document.getElementById(id);
    if(!input) return;
    const err = test(input.value);
    const msgEl = document.getElementById(msg);
    if(msgEl) msgEl.textContent = err;
    input.classList.toggle('invalid', !!err);
    input.classList.toggle('valid',   !err && input.value !== '');
    if(err) allValid = false;
  });
  if(!allValid){
    e.preventDefault();
    const card = document.querySelector('.form-card');
    const shakes = [-10,10,-7,7,-4,4,0];
    shakes.forEach((v,i) => setTimeout(() => card.style.transform = `translateX(${v}px)`, i*55));
  }
});
</script>
</body>
</html>