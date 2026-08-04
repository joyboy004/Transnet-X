<?php
include("../config/db.php");

$error = $success = "";

if (isset($_POST['register'])) {
    // Retrieve and sanitize inputs
    $name    = trim($_POST['name'] ?? '');
    $surname = trim($_POST['surname'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $nin     = trim($_POST['nin'] ?? '');
    $country = trim($_POST['country'] ?? '');
    $state   = trim($_POST['state'] ?? '');
    $password = $_POST['password'] ?? '';

    // Validation
    $errors = [];
    if (strlen($name) < 2) $errors[] = "First name must be at least 2 characters.";
    if (strlen($surname) < 2) $errors[] = "Surname must be at least 2 characters.";
    if (!preg_match('/^[\+\d\s\-\(\)]{7,20}$/', $phone)) $errors[] = "Invalid phone number format.";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid email address.";
    if (strlen($nin) < 6) $errors[] = "NIN must be at least 6 characters.";
    if (empty($country)) $errors[] = "Country is required.";
    if (strlen($state) < 2) $errors[] = "State/Province is required.";
    if (strlen($password) < 8) $errors[] = "Password must be at least 8 characters.";

    if (empty($errors)) {
        // Check if email already exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $error = '❌ This email is already registered. Please <a href="../index.php">login</a> or use another email.';
        } else {
            // Hash password and insert
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (name, surname, phone, email, nin, country, state, password) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssssss", $name, $surname, $phone, $email, $nin, $country, $state, $hashed_password);
            if ($stmt->execute()) {
                $success = '✅ Registration successful! <a href="../index.php" style="color:#f0b429;">Login here</a>';
            } else {
                $error = '❌ Registration failed. Please try again later.';
            }
        }
        $stmt->close();
    } else {
        $error = '❌ ' . implode('<br>', $errors);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>TransitX — Register</title>
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,wght@0,300;0,400;0,500;0,600;1,400&display=swap" rel="stylesheet"/>
  <style>
    /* All CSS remains exactly as provided in the original */
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
      display: flex; align-items: center; justify-content: center;
      padding: 3rem 3.5rem 3rem 1.5rem;
    }
    .form-card {
      width: 100%; max-width: 530px; background: var(--glass);
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
      padding-right: 2.4rem;
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
    .ticker-track { display: flex; gap: 3.5rem; white-space: nowrap; animation: tickerMove 28s linear infinite; }
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
      .form-panel { padding: 1.5rem 1.5rem 4rem; }
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
  <span class="nav-tagline">Move Smarter. Move Faster.</span>
  <div class="nav-links">
    <a href="#">Routes</a><a href="#">Fleet</a><a href="#">Track</a><a href="../index.php">Login</a>
  </div>
</nav>

<main>
  <div class="hero-panel">
    <p class="hero-eyebrow">Join the network</p>
    <h1 class="hero-title">Your Journey<br><span class="accent">Starts Here.</span></h1>
    <p class="hero-desc">Register with TransNet X and gain access to real transport services, smart routing, digital tickets, and seamless transportation across the nation.</p>
    <div class="stats">
      <div class="stat-item"><div class="stat-num">2.4M+</div><div class="stat-label">Passengers</div></div>
      <div class="stat-item"><div class="stat-num">180+</div><div class="stat-label">Routes</div></div>
      <div class="stat-item"><div class="stat-num">36</div><div class="stat-label">States</div></div>
    </div>
    <svg class="deco-bus" viewBox="0 0 640 280" xmlns="http://www.w3.org/2000/svg" fill="white">
      <rect x="40" y="80" width="540" height="160" rx="18"/>
      <rect x="60" y="50" width="80" height="35" rx="6"/>
      <rect x="60" y="100" width="100" height="70" rx="4" fill="#08080d" opacity="0.3"/>
      <rect x="180" y="100" width="100" height="70" rx="4" fill="#08080d" opacity="0.3"/>
      <rect x="300" y="100" width="100" height="70" rx="4" fill="#08080d" opacity="0.3"/>
      <rect x="420" y="100" width="100" height="70" rx="4" fill="#08080d" opacity="0.3"/>
      <circle cx="130" cy="255" r="30"/><circle cx="490" cy="255" r="30"/>
      <rect x="40" y="185" width="540" height="8" rx="4" fill="#08080d" opacity="0.15"/>
    </svg>
  </div>

  <div class="form-panel">
    <div class="form-card">
      <div class="form-header">
        <h2 class="form-title">Create Account</h2>
        <p class="form-sub">Fill in your details to get started</p>
      </div>
      <div class="progress-bar"><div class="progress-fill" id="progressFill"></div></div>

      <form method="POST" id="regForm" novalidate>
        <div class="form-grid">
          <div class="field">
            <label for="fname">First Name</label>
            <div class="field-wrap">
              <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              <input type="text" id="fname" name="name" placeholder="Bello" autocomplete="given-name" value="<?php echo htmlspecialchars($name ?? ''); ?>" required/>
            </div>
            <span class="field-msg" id="nameMsg"></span>
          </div>
          <div class="field">
            <label for="surname">Surname</label>
            <div class="field-wrap">
              <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              <input type="text" id="surname" name="surname" placeholder="Mustapha" autocomplete="family-name" value="<?php echo htmlspecialchars($surname ?? ''); ?>" required/>
            </div>
            <span class="field-msg" id="surnameMsg"></span>
          </div>
          <div class="field">
            <label for="phone">Phone Number</label>
            <div class="field-wrap">
              <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 1.23h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.84a16 16 0 0 0 6 6l.96-.96a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
              <input type="tel" id="phone" name="phone" placeholder="+09163575436" autocomplete="tel" value="<?php echo htmlspecialchars($phone ?? ''); ?>" required/>
            </div>
            <span class="field-msg" id="phoneMsg"></span>
          </div>
          <div class="field">
            <label for="email">Email Address</label>
            <div class="field-wrap">
              <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
              <input type="email" id="email" name="email" placeholder="bello@example.com" autocomplete="email" value="<?php echo htmlspecialchars($email ?? ''); ?>" required/>
            </div>
            <span class="field-msg" id="emailMsg"></span>
          </div>
          <div class="field full">
            <label for="nin">National ID Number (NIN)</label>
            <div class="field-wrap">
              <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
              <input type="text" id="nin" name="nin" placeholder="e.g. 12345678901" maxlength="20" value="<?php echo htmlspecialchars($nin ?? ''); ?>" required/>
            </div>
            <span class="field-msg" id="ninMsg"></span>
          </div>
          <div class="field">
            <label for="country">Country</label>
            <div class="field-wrap">
              <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
              <input type="text" id="country" name="country" placeholder="e.g. Nigeria" value="<?php echo htmlspecialchars($country ?? ''); ?>" required/>
            </div>
            <span class="field-msg" id="countryMsg"></span>
          </div>
          <div class="field">
            <label for="state">State / Province</label>
            <div class="field-wrap">
              <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
              <input type="text" id="state" name="state" placeholder="e.g. Lagos" value="<?php echo htmlspecialchars($state ?? ''); ?>" required/>
            </div>
            <span class="field-msg" id="stateMsg"></span>
          </div>
          <div class="field full">
            <label for="password">Password</label>
            <div class="field-wrap">
              <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              <input type="password" id="password" name="password" placeholder="Min. 8 characters" required/>
              <button type="button" class="pw-toggle" id="pwToggle" aria-label="Toggle password visibility">
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
          <span class="btn-text">REGISTER NOW</span>
          <div class="spinner"></div>
        </button>
        <p class="login-link">Already have an account? <a href="../index.php">Sign in here</a></p>
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
  <div class="success-title"><?php echo $success ? "You're On Board!" : "Oops!"; ?></div>
  <div class="success-msg"><?php echo $success ? $success : $error; ?></div>
  <?php if ($success): ?>
  <p class="success-msg">Your TransitX account is ready. Welcome to smarter travel.</p>
  <?php endif; ?>
  <a href="../index.php" style="color:var(--gold); margin-top:1rem;">← Back to login</a>
</div>

<!-- Ticker (moved outside overlay) -->
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
  const msgs = ['🚌 Real-Time Tracking','🛣️ 180+ Routes','⚡ Instant Booking','🔒 Secure Rides','🌍 Cross-Border Service','📍 Live GPS','🎟️ Digital Tickets','💺 Seat Selection','🚐 Fleet Management','📱 Mobile Alerts'];
  const track = document.getElementById('tickerTrack');
  msgs.forEach(m => {
    const el = document.createElement('span');
    el.className = 'ticker-item';
    el.innerHTML = `<span class="ticker-dot"></span>${m}`;
    track.appendChild(el);
  });
})();

// Validation config
const fields = [
  { id:'fname',    msg:'nameMsg',     test: v => v.trim().length >= 2 ? '' : 'At least 2 characters required.' },
  { id:'surname',  msg:'surnameMsg',  test: v => v.trim().length >= 2 ? '' : 'At least 2 characters required.' },
  { id:'phone',    msg:'phoneMsg',    test: v => /^[\+\d\s\-\(\)]{7,20}$/.test(v.trim()) ? '' : 'Enter a valid phone number.' },
  { id:'email',    msg:'emailMsg',    test: v => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v.trim()) ? '' : 'Enter a valid email.' },
  { id:'nin',      msg:'ninMsg',      test: v => v.trim().length >= 6 ? '' : 'NIN must be at least 6 digits.' },
  { id:'country',  msg:'countryMsg',  test: v => v.trim().length >= 2 ? '' : 'Country is required.' },
  { id:'state',    msg:'stateMsg',    test: v => v.trim().length >= 2 ? '' : 'State/Province is required.' },
  { id:'password', msg:'passwordMsg', test: v => v.length >= 8 ? '' : 'Password must be at least 8 characters.' },
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

// Form submit validation
document.getElementById('regForm').addEventListener('submit', function(e){
  let allValid = true;
  fields.forEach(({ id, msg, test }) => {
    const input = document.getElementById(id);
    if(!input) return;
    const err = test(input.value);
    document.getElementById(msg).textContent = err;
    input.classList.toggle('invalid', !!err);
    input.classList.toggle('valid',   !err && input.value !== '');
    if(err) allValid = false;
  });
  if(!allValid){
    e.preventDefault();
    const card = document.querySelector('.form-card');
    const shakes = [-10,10,-7,7,-4,4,0];
    shakes.forEach((v,i) => setTimeout(() => card.style.transform = `translateX(${v}px)`, i*55));
  } else {
    // Show loading state (form will submit normally)
    document.getElementById('submitBtn').classList.add('loading');
  }
});
</script>
</body>
</html>