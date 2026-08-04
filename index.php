<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TRANSNETX Transport — Login</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@300;400;500;600&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --black: #040507;
            --deep: #080c14;
            --navy: #0a1628;
            --accent: #e8b84b;
            --accent2: #f0c060;
            --electric: #4db8ff;
            --red: #ff3c3c;
            --white: #f4f2ec;
            --muted: #8a8f9e;
            --glass: rgba(255,255,255,0.04);
            --glass-border: rgba(255,255,255,0.08);
        }

        html, body {
            height: 100%;
            overflow: hidden;
            font-family: 'DM Sans', sans-serif;
            background: var(--black);
            color: var(--white);
        }

        /* ── CANVAS ROAD ANIMATION ── */
        #road-canvas {
            position: fixed;
            inset: 0;
            z-index: 0;
        }

        /* ── NOISE OVERLAY ── */
        .noise {
            position: fixed;
            inset: 0;
            z-index: 1;
            pointer-events: none;
            opacity: 0.035;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)'/%3E%3C/svg%3E");
            background-size: 200px 200px;
        }

        /* ── SCANLINES ── */
        .scanlines {
            position: fixed;
            inset: 0;
            z-index: 2;
            pointer-events: none;
            background: repeating-linear-gradient(
                to bottom,
                transparent,
                transparent 2px,
                rgba(0,0,0,0.08) 2px,
                rgba(0,0,0,0.08) 4px
            );
        }

        /* ── VIGNETTE ── */
        .vignette {
            position: fixed;
            inset: 0;
            z-index: 3;
            pointer-events: none;
            background: radial-gradient(ellipse at center,
                transparent 40%,
                rgba(4,5,7,0.7) 80%,
                rgba(4,5,7,0.95) 100%
            );
        }

        /* ── MAIN LAYOUT ── */
        .layout {
            position: relative;
            z-index: 10;
            display: grid;
            grid-template-columns: 1fr 480px;
            height: 100vh;
            overflow: hidden;
        }

        /* ── LEFT PANEL ── */
        .left-panel {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 48px 64px;
            position: relative;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 14px;
            opacity: 0;
            transform: translateY(-20px);
            animation: fadeUp 0.8s cubic-bezier(0.16,1,0.3,1) 0.2s forwards;
        }

        .brand-icon {
            width: 42px;
            height: 42px;
            position: relative;
        }

        .brand-icon svg {
            width: 100%;
            height: 100%;
        }

        .brand-name {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 28px;
            letter-spacing: 4px;
            color: var(--white);
        }

        .brand-name span {
            color: var(--accent);
        }

        .hero-text {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding-bottom: 40px;
        }

        .hero-label {
            font-family: 'Space Mono', monospace;
            font-size: 11px;
            letter-spacing: 5px;
            color: var(--accent);
            text-transform: uppercase;
            margin-bottom: 24px;
            opacity: 0;
            animation: fadeUp 0.8s cubic-bezier(0.16,1,0.3,1) 0.4s forwards;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .hero-label::before {
            content: '';
            width: 32px;
            height: 1px;
            background: var(--accent);
            display: block;
        }

        .hero-headline {
            font-family: 'Bebas Neue', sans-serif;
            font-size: clamp(64px, 6vw, 96px);
            line-height: 0.92;
            letter-spacing: 2px;
            color: var(--white);
            opacity: 0;
            animation: fadeUp 0.8s cubic-bezier(0.16,1,0.3,1) 0.5s forwards;
            margin-bottom: 28px;
        }

        .hero-headline em {
            font-style: normal;
            color: var(--accent);
            -webkit-text-stroke: 0px;
            position: relative;
        }

        .hero-sub {
            font-size: 16px;
            font-weight: 300;
            color: var(--muted);
            line-height: 1.65;
            max-width: 420px;
            opacity: 0;
            animation: fadeUp 0.8s cubic-bezier(0.16,1,0.3,1) 0.65s forwards;
        }

        /* ── STATS ROW ── */
        .stats-row {
            display: flex;
            gap: 40px;
            opacity: 0;
            animation: fadeUp 0.8s cubic-bezier(0.16,1,0.3,1) 0.8s forwards;
        }

        .stat {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .stat-num {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 36px;
            letter-spacing: 2px;
            color: var(--white);
            line-height: 1;
        }

        .stat-num span {
            color: var(--accent);
        }

        .stat-label {
            font-size: 11px;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: var(--muted);
            font-family: 'Space Mono', monospace;
        }

        .stat-divider {
            width: 1px;
            background: var(--glass-border);
            align-self: stretch;
        }

        /* ── RIGHT PANEL ── */
        .right-panel {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px 48px;
            position: relative;
        }

        /* Right panel gradient backdrop */
        .right-panel::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg,
                rgba(8,12,20,0.6) 0%,
                rgba(10,22,40,0.8) 100%
            );
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border-left: 1px solid var(--glass-border);
        }

        /* ── LOGIN CARD ── */
        .card {
            position: relative;
            z-index: 2;
            width: 100%;
            max-width: 380px;
        }

        .card-header {
            margin-bottom: 36px;
            opacity: 0;
            animation: fadeUp 0.8s cubic-bezier(0.16,1,0.3,1) 0.6s forwards;
        }

        .card-title {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 42px;
            letter-spacing: 3px;
            color: var(--white);
            line-height: 1;
            margin-bottom: 8px;
        }

        .card-sub {
            font-size: 13px;
            color: var(--muted);
            font-weight: 300;
        }

        /* ── ROLE TABS ── */
        .role-tabs {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            margin-bottom: 28px;
            opacity: 0;
            animation: fadeUp 0.8s cubic-bezier(0.16,1,0.3,1) 0.7s forwards;
        }

        .role-tab {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            padding: 14px 10px;
            border-radius: 10px;
            border: 1px solid var(--glass-border);
            background: var(--glass);
            cursor: pointer;
            transition: all 0.25s cubic-bezier(0.16,1,0.3,1);
            position: relative;
            overflow: hidden;
        }

        .role-tab::before {
            content: '';
            position: absolute;
            bottom: 0; left: 0; right: 0;
            height: 2px;
            background: var(--accent);
            transform: scaleX(0);
            transition: transform 0.3s cubic-bezier(0.16,1,0.3,1);
        }

        .role-tab:hover {
            border-color: rgba(232,184,75,0.3);
            background: rgba(232,184,75,0.06);
            transform: translateY(-2px);
        }

        .role-tab.active {
            border-color: rgba(232,184,75,0.5);
            background: rgba(232,184,75,0.1);
        }

        .role-tab.active::before {
            transform: scaleX(1);
        }

        .role-icon {
            font-size: 22px;
            line-height: 1;
        }

        .role-name {
            font-size: 11px;
            letter-spacing: 2px;
            text-transform: uppercase;
            font-family: 'Space Mono', monospace;
            color: var(--muted);
            transition: color 0.25s;
        }

        .role-tab.active .role-name {
            color: var(--accent);
        }

        /* ── FORM & INPUT STYLES (MATCH PAGE AESTHETIC) ── */
        .login-form {
            display: flex;
            flex-direction: column;
            gap: 18px;
            opacity: 0;
            animation: fadeUp 0.8s cubic-bezier(0.16,1,0.3,1) 0.8s forwards;
        }

        /* --- INPUT GROUP WITH ICON (email/password) --- */
        .input-group {
            position: relative;
            width: 100%;
        }

        .input-group .input-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            display: flex;
            align-items: center;
            justify-content: center;
            width: 18px;
            height: 18px;
            color: var(--muted);
            opacity: 0.6;
            transition: opacity 0.2s, color 0.2s;
            pointer-events: none;
            z-index: 2;
        }

        .inline-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 1em;
            height: 1em;
            color: inherit;
        }

        .inline-icon svg {
            width: 1em;
            height: 1em;
            display: block;
            stroke: currentColor;
            fill: none;
            stroke-width: 1.8;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .input-group input {
            width: 100%;
            padding: 15px 48px 15px 48px;
            background: var(--glass);
            border: 1px solid var(--glass-border);
            border-radius: 10px;
            font-size: 14px;
            font-family: 'DM Sans', sans-serif;
            font-weight: 400;
            color: var(--white);
            outline: none;
            transition: all 0.25s cubic-bezier(0.16,1,0.3,1);
            -webkit-appearance: none;
            letter-spacing: 0.3px;
        }

        .input-group input::placeholder {
            color: rgba(138,143,158,0.5);
            font-weight: 300;
            font-size: 13px;
        }

        .input-group input:focus {
            border-color: rgba(232,184,75,0.6);
            background: rgba(232,184,75,0.06);
            box-shadow: 0 0 0 3px rgba(232,184,75,0.1), 0 0 20px rgba(232,184,75,0.05);
        }

        .input-group input:focus + .input-icon,
        .input-group:focus-within .input-icon {
            color: var(--accent);
            opacity: 1;
        }

        /* optional password toggle inside group (keeps style) */
        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: var(--muted);
            opacity: 0.6;
            transition: opacity 0.2s, color 0.2s;
            z-index: 3;
            background: transparent;
            border: none;
            outline: none;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 50%;
        }
        .password-toggle:hover {
            opacity: 1;
            color: var(--accent);
            background: rgba(232,184,75,0.08);
        }

        .password-toggle .inline-icon {
            width: 16px;
            height: 16px;
        }

        .role-icon .inline-icon {
            width: 1.1em;
            height: 1.1em;
        }

        .btn-reg .inline-icon,
        .btn-login .inline-icon {
            width: 1em;
            height: 1em;
        }

        .loading-icon {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        /* ── SUBMIT BUTTON ── */
        .btn-login {
            width: 100%;
            padding: 16px;
            margin-top: 10px;
            background: var(--accent);
            border: none;
            border-radius: 10px;
            font-family: 'Bebas Neue', sans-serif;
            font-size: 18px;
            letter-spacing: 4px;
            color: var(--black);
            cursor: pointer;
            position: relative;
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.16,1,0.3,1);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn-login i {
            font-size: 16px;
            color: var(--black);
        }

        .btn-login::before {
            content: '';
            position: absolute;
            top: 50%; left: 50%;
            width: 0; height: 0;
            background: rgba(255,255,255,0.25);
            border-radius: 50%;
            transform: translate(-50%,-50%);
            transition: width 0.6s, height 0.6s, opacity 0.6s;
            opacity: 0;
        }

        .btn-login:hover {
            background: var(--accent2);
            box-shadow: 0 8px 30px rgba(232,184,75,0.4);
            transform: translateY(-2px);
        }

        .btn-login:active::before {
            width: 300px; height: 300px;
            opacity: 0;
            transition: 0s;
        }

        .btn-login.loading {
            pointer-events: none;
            opacity: 0.7;
        }

        /* ── DIVIDER ── */
        .divider {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 4px 0;
        }

        .divider::before, .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--glass-border);
        }

        .divider-text {
            font-size: 11px;
            color: var(--muted);
            font-family: 'Space Mono', monospace;
            letter-spacing: 2px;
        }

        /* ── REGISTER LINKS ── */
        .register-links {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            opacity: 0;
            animation: fadeUp 0.8s cubic-bezier(0.16,1,0.3,1) 0.9s forwards;
        }

        .btn-reg {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 13px 16px;
            border-radius: 10px;
            border: 1px solid var(--glass-border);
            background: var(--glass);
            color: var(--muted);
            font-family: 'Space Mono', monospace;
            font-size: 10px;
            letter-spacing: 2px;
            text-transform: uppercase;
            text-decoration: none;
            transition: all 0.25s cubic-bezier(0.16,1,0.3,1);
        }

        .btn-reg:hover {
            border-color: rgba(232,184,75,0.3);
            color: var(--accent);
            background: rgba(232,184,75,0.06);
            transform: translateY(-2px);
        }

        /* ── TICKER BAR ── */
        .ticker-bar {
            position: fixed;
            bottom: 0; left: 0; right: 0;
            z-index: 50;
            height: 36px;
            background: rgba(232,184,75,0.95);
            display: flex;
            align-items: center;
            overflow: hidden;
            border-top: 1px solid rgba(0,0,0,0.2);
        }

        .ticker-label {
            padding: 0 20px;
            font-family: 'Bebas Neue', sans-serif;
            font-size: 14px;
            letter-spacing: 3px;
            color: var(--black);
            background: var(--black);
            color: var(--accent);
            height: 100%;
            display: flex;
            align-items: center;
            flex-shrink: 0;
            border-right: 1px solid rgba(232,184,75,0.3);
        }

        .ticker-track {
            display: flex;
            animation: ticker 30s linear infinite;
            white-space: nowrap;
        }

        .ticker-item {
            padding: 0 48px;
            font-family: 'Space Mono', monospace;
            font-size: 10px;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: var(--black);
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .ticker-dot {
            width: 4px; height: 4px;
            border-radius: 50%;
            background: var(--black);
            opacity: 0.4;
        }

        /* ── LIVE BADGE ── */
        .live-badge {
            position: fixed;
            top: 24px; right: 24px;
            z-index: 50;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            background: rgba(255,60,60,0.15);
            border: 1px solid rgba(255,60,60,0.3);
            border-radius: 6px;
            font-family: 'Space Mono', monospace;
            font-size: 10px;
            letter-spacing: 3px;
            color: var(--red);
            opacity: 0;
            animation: fadeIn 0.8s 1.2s forwards;
        }

        .live-dot {
            width: 6px; height: 6px;
            border-radius: 50%;
            background: var(--red);
            animation: pulse-dot 1.5s ease-in-out infinite;
        }

        /* ── CORNER DECORATION ── */
        .corner-deco {
            position: absolute;
            opacity: 0.15;
            pointer-events: none;
        }

        .corner-deco.tl { top: 0; left: 0; }
        .corner-deco.br { bottom: 36px; right: 0; transform: rotate(180deg); }

        /* ── MOVING VEHICLE ── */
        .vehicle-track {
            position: fixed;
            bottom: 60px;
            left: -200px;
            z-index: 8;
            animation: driveAcross 12s linear infinite;
        }

        .vehicle-track svg {
            width: 120px;
            filter: drop-shadow(0 0 10px rgba(232,184,75,0.4));
        }

        /* ── KEYFRAMES ── */
        @keyframes fadeUp {
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes fadeIn {
            to { opacity: 1; }
        }

        @keyframes ticker {
            from { transform: translateX(0); }
            to   { transform: translateX(-50%); }
        }

        @keyframes pulse-dot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50%       { opacity: 0.4; transform: scale(1.5); }
        }

        @keyframes driveAcross {
            from { left: -200px; }
            to   { left: calc(100vw + 200px); }
        }

        /* ── SCROLL HINT ── */
        .scroll-hint {
            position: fixed;
            left: 40px;
            bottom: 60px;
            z-index: 20;
            display: flex;
            align-items: center;
            gap: 10px;
            opacity: 0;
            animation: fadeIn 0.8s 1.5s forwards;
        }

        .scroll-line {
            width: 1px;
            height: 50px;
            background: linear-gradient(to bottom, transparent, var(--accent), transparent);
            animation: scrollLine 2s ease-in-out infinite;
        }

        @keyframes scrollLine {
            0%, 100% { opacity: 0.3; transform: scaleY(1); }
            50%       { opacity: 1;   transform: scaleY(0.6); }
        }

        /* hidden role input */
        #roleInput {
            display: none;
        }

        /* ── RESPONSIVE ── */
        @media (max-width: 900px) {
            .layout {
                grid-template-columns: 1fr;
                overflow: auto;
            }
            .left-panel {
                display: none;
            }
            .right-panel {
                height: 100vh;
            }
            .right-panel::before {
                border-left: none;
            }
        }
    </style>
</head>
<body>

<!-- ── ANIMATED ROAD CANVAS ── -->
<canvas id="road-canvas"></canvas>

<!-- ── OVERLAYS ── -->
<div class="noise"></div>
<div class="scanlines"></div>
<div class="vignette"></div>

<!-- ── LIVE BADGE ── -->
<div class="live-badge">
    <div class="live-dot"></div>
    LIVE DISPATCH
</div>

<!-- ── CORNER DECORATIONS ── -->
<svg class="corner-deco tl" width="120" height="120" viewBox="0 0 120 120">
    <path d="M0,120 L0,0 L120,0" fill="none" stroke="#e8b84b" stroke-width="1"/>
    <circle cx="0" cy="0" r="6" fill="#e8b84b"/>
    <path d="M20,120 L20,20 L120,20" fill="none" stroke="#e8b84b" stroke-width="0.5" opacity="0.4"/>
</svg>
<svg class="corner-deco br" width="120" height="120" viewBox="0 0 120 120">
    <path d="M0,120 L0,0 L120,0" fill="none" stroke="#e8b84b" stroke-width="1"/>
    <circle cx="0" cy="0" r="6" fill="#e8b84b"/>
    <path d="M20,120 L20,20 L120,20" fill="none" stroke="#e8b84b" stroke-width="0.5" opacity="0.4"/>
</svg>

<!-- ── MOVING VEHICLE ── -->
<div class="vehicle-track">
    <svg viewBox="0 0 120 40" fill="none" xmlns="http://www.w3.org/2000/svg">
        <rect x="2" y="8" width="110" height="26" rx="4" fill="#0a1628" stroke="#e8b84b" stroke-width="1.5"/>
        <rect x="10" y="12" width="14" height="10" rx="2" fill="#4db8ff" opacity="0.6"/>
        <rect x="30" y="12" width="14" height="10" rx="2" fill="#4db8ff" opacity="0.6"/>
        <rect x="50" y="12" width="14" height="10" rx="2" fill="#4db8ff" opacity="0.6"/>
        <rect x="70" y="12" width="14" height="10" rx="2" fill="#4db8ff" opacity="0.6"/>
        <rect x="90" y="12" width="16" height="10" rx="2" fill="#e8b84b" opacity="0.8"/>
        <circle cx="22" cy="34" r="6" fill="#1a1a2e" stroke="#e8b84b" stroke-width="1.5"/>
        <circle cx="22" cy="34" r="2.5" fill="#e8b84b"/>
        <circle cx="90" cy="34" r="6" fill="#1a1a2e" stroke="#e8b84b" stroke-width="1.5"/>
        <circle cx="90" cy="34" r="2.5" fill="#e8b84b"/>
        <rect x="108" y="14" width="6" height="4" rx="1" fill="#fff" opacity="0.9"/>
        <text x="40" y="26" font-family="sans-serif" font-size="7" fill="#e8b84b" letter-spacing="3" font-weight="bold">TRANSNET X</text>
    </svg>
</div>

<!-- ── TICKER ── -->
<div class="ticker-bar">
    <div class="ticker-label">LIVE</div>
    <div class="ticker-track" id="ticker-track"></div>
</div>

<!-- ── SCROLL HINT ── -->
<div class="scroll-hint">
    <div class="scroll-line"></div>
</div>

<!-- ── MAIN LAYOUT ── -->
<div class="layout">
    <div class="left-panel">
        <div class="brand">
            <div class="brand-icon">
                <svg viewBox="0 0 42 42" fill="none">
                    <polygon points="21,2 40,12 40,30 21,40 2,30 2,12" fill="none" stroke="#e8b84b" stroke-width="1.5"/>
                    <polygon points="21,8 34,15 34,28 21,35 8,28 8,15" fill="none" stroke="#e8b84b" stroke-width="0.5" opacity="0.4"/>
                    <circle cx="21" cy="21" r="5" fill="#e8b84b"/>
                    <line x1="21" y1="2" x2="21" y2="8" stroke="#e8b84b" stroke-width="1.5"/>
                    <line x1="21" y1="34" x2="21" y2="40" stroke="#e8b84b" stroke-width="1.5"/>
                </svg>
            </div>
            <div class="brand-name">TRANSNET<span>X</span></div>
        </div>

        <div class="hero-text">
            <div class="hero-label">Urban Mobility Platform</div>
            <h1 class="hero-headline">
                MOVE<br>
                THE<br>
                <em>CITY.</em>
            </h1>
            <p class="hero-sub">
                Real-time fleet intelligence. Precision dispatch. 
                Zero friction from first mile to last. The infrastructure 
                cities trust to keep millions moving.
            </p>
        </div>

        <div class="stats-row">
            <div class="stat">
                <div class="stat-num"><span id="cnt1">0</span>K</div>
                <div class="stat-label">Daily Rides</div>
            </div>
            <div class="stat-divider"></div>
            <div class="stat">
                <div class="stat-num"><span id="cnt2">0</span></div>
                <div class="stat-label">Active Drivers</div>
            </div>
            <div class="stat-divider"></div>
            <div class="stat">
                <div class="stat-num"><span id="cnt3">0</span>%</div>
                <div class="stat-label">On-Time Rate</div>
            </div>
        </div>
    </div>

    <div class="right-panel">
        <div class="card">
            <div class="card-header">
                <div class="card-title">SIGN IN</div>
                <div class="card-sub">Access the dispatch network</div>
            </div>

            <!-- ROLE SELECTOR -->
            <div class="role-tabs" id="role-tabs">
                <div class="role-tab active" data-role="user">
                    <div class="role-icon"><span class="inline-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><path d="M12 2v4"></path><path d="M12 18v4"></path><path d="M2 12h4"></path><path d="M18 12h4"></path><path d="M4.93 4.93l2.83 2.83"></path><path d="M16.24 16.24l2.83 2.83"></path><path d="M4.93 19.07l2.83-2.83"></path><path d="M16.24 7.76l2.83-2.83"></path></svg></span></div>
                    <div class="role-name">Customer</div>
                </div>
                <div class="role-tab" data-role="driver">
                    <div class="role-icon"><span class="inline-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 7h12a2 2 0 0 1 2 2v5H4V7Z"></path><path d="M8 14h8"></path><path d="M5 19h2"></path><path d="M15 19h2"></path><path d="M4 10h16"></path><circle cx="8" cy="17" r="1.5"></circle><circle cx="16" cy="17" r="1.5"></circle></svg></span></div>
                    <div class="role-name">Driver</div>
                </div>
                <div class="role-tab" data-role="admin">
                    <div class="role-icon"><span class="inline-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M13 2 4 6v6c0 5 3.5 8.5 9 10 5.5-1.5 9-5 9-10V6l-9-4Z"></path><path d="m12 8 1.5 3.5L17 12l-3.5 1.5L12 17l-1.5-3.5L7 12l3.5-1.5L12 8Z"></path></svg></span></div>
                    <div class="role-name">Admin</div>
                </div>
            </div>

            <!-- LOGIN FORM — STYLED INPUTS WITH ICONS -->
            <form method="POST" action="auth/login.php" id="loginForm" class="login-form">
                <input type="hidden" name="role" id="roleInput" value="user">

                <!-- Email field -->
                <div class="input-group">
                    <span class="input-icon" aria-hidden="true"><span class="inline-icon"><svg viewBox="0 0 24 24"><path d="M4 6h16a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1Z"></path><path d="m5 8 7 5 7-5"></path></svg></span></span>
                    <input type="email" name="email" id="email" placeholder="Email address" required>
                </div>

                <!-- Password field with toggle -->
                <div class="input-group">
                    <span class="input-icon" aria-hidden="true"><span class="inline-icon"><svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="10" rx="2"></rect><path d="M8 10V8a4 4 0 1 1 8 0v2"></path></svg></span></span>
                    <input type="password" name="password" id="password" placeholder="Password" required>
                    <button type="button" class="password-toggle" id="togglePassword" aria-label="Show password"><span class="inline-icon" id="toggleIcon"><svg viewBox="0 0 24 24"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"></path><circle cx="12" cy="12" r="3"></circle></svg></span></button>
                </div>

                <button type="submit" class="btn-login" id="submit-btn">
                    <span class="inline-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 12h14"></path><path d="m13 6 6 6-6 6"></path></svg></span> Sign In
                </button>
            </form>

            <div class="divider" style="margin-top:20px;">
                <div class="divider-text">New to Nexus?</div>
            </div>

            <div class="register-links">
                <a href="auth/register_user.php" class="btn-reg">
                    <span class="inline-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><path d="M12 2v4"></path><path d="M12 18v4"></path><path d="M2 12h4"></path><path d="M18 12h4"></path><path d="M4.93 4.93l2.83 2.83"></path><path d="M16.24 16.24l2.83 2.83"></path><path d="M4.93 19.07l2.83-2.83"></path><path d="M16.24 7.76l2.83-2.83"></path></svg></span> Customer
                </a>
                <a href="auth/register_driver.php" class="btn-reg">
                    <span class="inline-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 7h12a2 2 0 0 1 2 2v5H4V7Z"></path><path d="M8 14h8"></path><path d="M5 19h2"></path><path d="M15 19h2"></path><path d="M4 10h16"></path><circle cx="8" cy="17" r="1.5"></circle><circle cx="16" cy="17" r="1.5"></circle></svg></span> Driver
                </a>
            </div>
        </div>
    </div>
</div>

<script>
/* ══════════════════════════════════════════════
   ROAD CANVAS — Animated night highway
══════════════════════════════════════════════ */
(function() {
    const canvas = document.getElementById('road-canvas');
    const ctx = canvas.getContext('2d');
    let W, H, dashes = [], stars = [];
    let frame = 0;

    function resize() {
        W = canvas.width  = window.innerWidth;
        H = canvas.height = window.innerHeight;
        initStars();
    }

    function initStars() {
        stars = [];
        for (let i = 0; i < 120; i++) {
            stars.push({
                x: Math.random() * W,
                y: Math.random() * H * 0.55,
                r: Math.random() * 1.2 + 0.2,
                a: Math.random(),
                speed: Math.random() * 0.005 + 0.002
            });
        }
    }

    const DASH_COUNT = 14;
    const ROAD_Y = 0.68;
    const VANISH_X = 0.5;

    function perspective(t) {
        return {
            y: H * ROAD_Y + (H * (1 - ROAD_Y)) * t,
            halfW: W * 0.01 + W * 0.38 * t
        };
    }

    for (let i = 0; i < DASH_COUNT; i++) {
        dashes.push({ t: i / DASH_COUNT });
    }

    function drawSky() {
        const grad = ctx.createLinearGradient(0, 0, 0, H * ROAD_Y + 20);
        grad.addColorStop(0, '#010306');
        grad.addColorStop(0.5, '#040c1a');
        grad.addColorStop(1, '#071428');
        ctx.fillStyle = grad;
        ctx.fillRect(0, 0, W, H * ROAD_Y + 20);
    }

    function drawStars() {
        stars.forEach(s => {
            s.a += s.speed;
            const alpha = (Math.sin(s.a) * 0.5 + 0.5) * 0.8 + 0.1;
            ctx.beginPath();
            ctx.arc(s.x, s.y, s.r, 0, Math.PI * 2);
            ctx.fillStyle = `rgba(200,220,255,${alpha})`;
            ctx.fill();
        });
    }

    function drawCityGlow() {
        const gx = ctx.createRadialGradient(W*0.5, H*ROAD_Y, 10, W*0.5, H*ROAD_Y, W*0.5);
        gx.addColorStop(0, 'rgba(77,184,255,0.18)');
        gx.addColorStop(0.4, 'rgba(232,184,75,0.05)');
        gx.addColorStop(1, 'transparent');
        ctx.fillStyle = gx;
        ctx.beginPath();
        ctx.ellipse(W*0.5, H*ROAD_Y, W*0.5, 80, 0, 0, Math.PI*2);
        ctx.fill();
    }

    function drawRoadSurface() {
        const p0 = perspective(0);
        const p1 = perspective(1);
        const rg = ctx.createLinearGradient(0, p0.y, 0, H);
        rg.addColorStop(0, '#060b14');
        rg.addColorStop(0.4, '#0a1120');
        rg.addColorStop(1, '#08101e');
        ctx.beginPath();
        ctx.moveTo(W*VANISH_X - p0.halfW, p0.y);
        ctx.lineTo(W*VANISH_X + p0.halfW, p0.y);
        ctx.lineTo(W*VANISH_X + p1.halfW, p1.y);
        ctx.lineTo(W*VANISH_X - p1.halfW, p1.y);
        ctx.closePath();
        ctx.fillStyle = rg;
        ctx.fill();
    }

    function drawRoadEdges() {
        const p0 = perspective(0);
        const p1 = perspective(1);
        const grad = ctx.createLinearGradient(0, p0.y, 0, H);
        grad.addColorStop(0, 'rgba(255,255,255,0)');
        grad.addColorStop(0.2, 'rgba(255,255,255,0.5)');
        grad.addColorStop(1, 'rgba(255,255,255,0.8)');
        ctx.beginPath();
        ctx.moveTo(W*VANISH_X - p0.halfW, p0.y);
        ctx.lineTo(W*VANISH_X - p1.halfW, p1.y);
        ctx.strokeStyle = grad;
        ctx.lineWidth = 2;
        ctx.stroke();
        ctx.beginPath();
        ctx.moveTo(W*VANISH_X + p0.halfW, p0.y);
        ctx.lineTo(W*VANISH_X + p1.halfW, p1.y);
        ctx.strokeStyle = grad;
        ctx.stroke();
    }

    function drawDashes(speed) {
        dashes.forEach(d => {
            d.t += speed;
            if (d.t > 1) d.t -= 1;
            const a0 = perspective(d.t);
            const a1 = perspective(Math.min(d.t + 0.04, 1));
            const alpha = d.t * 0.9 + 0.1;
            const thick = d.t * 3 + 0.5;
            ctx.beginPath();
            ctx.moveTo(W * VANISH_X, a0.y);
            ctx.lineTo(W * VANISH_X, a1.y);
            ctx.strokeStyle = `rgba(232,184,75,${alpha * 0.7})`;
            ctx.lineWidth = thick;
            ctx.stroke();
        });
    }

    function drawHeadlightReflection() {
        const lg = ctx.createRadialGradient(W*0.5, H, 0, W*0.5, H, H*0.4);
        lg.addColorStop(0, 'rgba(232,184,75,0.08)');
        lg.addColorStop(0.5, 'rgba(77,184,255,0.03)');
        lg.addColorStop(1, 'transparent');
        ctx.fillStyle = lg;
        ctx.fillRect(0, H * ROAD_Y, W, H * (1 - ROAD_Y));
    }

    function draw() {
        frame++;
        ctx.clearRect(0, 0, W, H);
        const speed = 0.004;
        drawSky();
        drawStars();
        drawCityGlow();
        drawRoadSurface();
        drawHeadlightReflection();
        drawRoadEdges();
        drawDashes(speed);
        requestAnimationFrame(draw);
    }

    window.addEventListener('resize', resize);
    resize();
    draw();
})();

/* ══════════════════════════════════════════════
   ROLE TABS
══════════════════════════════════════════════ */
const tabs = document.querySelectorAll('.role-tab');
const roleInput = document.getElementById('roleInput');
tabs.forEach(tab => {
    tab.addEventListener('click', () => {
        tabs.forEach(t => t.classList.remove('active'));
        tab.classList.add('active');
        roleInput.value = tab.getAttribute('data-role');
    });
});

/* ══════════════════════════════════════════════
   PASSWORD TOGGLE (enhanced)
══════════════════════════════════════════════ */
const toggleBtn = document.getElementById('togglePassword');
const passwordInput = document.getElementById('password');
const toggleIcon = document.getElementById('toggleIcon');
const eyeOpenIcon = `<svg viewBox="0 0 24 24"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"></path><circle cx="12" cy="12" r="3"></circle></svg>`;
const eyeClosedIcon = `<svg viewBox="0 0 24 24"><path d="M3 3l18 18"></path><path d="M10.6 10.6A3 3 0 0 0 13.4 13.4"></path><path d="M9.9 5.1A13.2 13.2 0 0 1 12 5c6.5 0 10 7 10 7a20.4 20.4 0 0 1-4.4 5.2"></path><path d="M6.4 6.4A20.4 20.4 0 0 0 2 12s3.5 6 10 6a11.8 11.8 0 0 0 4.2-.8"></path></svg>`;

function setPasswordToggle(type) {
    toggleIcon.innerHTML = type === 'password' ? eyeOpenIcon : eyeClosedIcon;
}

setPasswordToggle(passwordInput.getAttribute('type'));

toggleBtn.addEventListener('click', function() {
    const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
    passwordInput.setAttribute('type', type);
    setPasswordToggle(type);
});

/* ══════════════════════════════════════════════
   FORM SUBMIT (optional validation)
══════════════════════════════════════════════ */
document.getElementById('loginForm').addEventListener('submit', function(e) {
    const email = document.getElementById('email');
    const pw = document.getElementById('password');
    let valid = true;
    // basic check
    if (!email.value || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value)) {
        email.style.borderColor = 'var(--red)';
        valid = false;
    } else { email.style.borderColor = ''; }
    if (!pw.value) {
        pw.style.borderColor = 'var(--red)';
        valid = false;
    } else { pw.style.borderColor = ''; }
    if (!valid) {
        e.preventDefault();
        return;
    }
    const btn = document.getElementById('submit-btn');
    btn.classList.add('loading');
    btn.innerHTML = `<span class="inline-icon loading-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"></circle><path d="M12 4v3"></path><path d="M12 17v3"></path><path d="M4 12h3"></path><path d="M17 12h3"></path><path d="M6.3 6.3l2.1 2.1"></path><path d="M15.6 15.6l2.1 2.1"></path><path d="M6.3 17.7l2.1-2.1"></path><path d="M15.6 8.4l2.1-2.1"></path></svg></span> CONNECTING…`;
});

/* ══════════════════════════════════════════════
   COUNTER ANIMATION
══════════════════════════════════════════════ */
function animateCount(el, target, duration) {
    let start = 0;
    const step = target / (duration / 16);
    const timer = setInterval(() => {
        start += step;
        if (start >= target) { el.textContent = target; clearInterval(timer); return; }
        el.textContent = Math.floor(start);
    }, 16);
}
setTimeout(() => {
    animateCount(document.getElementById('cnt1'), 248, 1800);
    animateCount(document.getElementById('cnt2'), 3420, 2000);
    animateCount(document.getElementById('cnt3'), 97, 1500);
}, 1000);

/* ══════════════════════════════════════════════
   TICKER
══════════════════════════════════════════════ */
const tickerMessages = [
    "Route 14 — On Schedule",
    "Fleet Dispatch: 3420 Active Units",
    "Downtown Corridor — Low Congestion",
    "New Booking: Airport Express",
    "ETA Accuracy: 97.4%",
    "Night Service Active — All Zones",
    "Driver Rating avg: 4.92 ⭐",
    "Live GPS Tracking Enabled",
    "Surge Pricing: Off",
    "Carbon Offset Miles: 1.2M",
];
const track = document.getElementById('ticker-track');
const doubled = [...tickerMessages, ...tickerMessages];
doubled.forEach(msg => {
    const item = document.createElement('div');
    item.className = 'ticker-item';
    item.innerHTML = `<span class="ticker-dot"></span>${msg}`;
    track.appendChild(item);
});

/* ambient particles etc kept for ambiance */
(function particleEffect() {
    const style = document.createElement('style');
    style.textContent = `
        .particle {
            position: fixed; border-radius: 50%; pointer-events: none; z-index: 4; background: var(--accent);
        }
        @keyframes float-particle {
            0% { transform: translateY(0) translateX(0) scale(1); opacity: 0.6; }
            50% { transform: translateY(-60px) translateX(20px) scale(1.2); opacity: 1; }
            100% { transform: translateY(-120px) translateX(-10px) scale(0); opacity: 0; }
        }
    `;
    document.head.appendChild(style);
    function spawnParticle() {
        const p = document.createElement('div');
        p.className = 'particle';
        const size = Math.random() * 3 + 1;
        const x = Math.random() * window.innerWidth;
        const y = window.innerHeight * 0.5 + Math.random() * window.innerHeight * 0.3;
        const dur = Math.random() * 3000 + 2000;
        Object.assign(p.style, {
            width: size + 'px', height: size + 'px', left: x + 'px', top: y + 'px',
            opacity: 0, animation: `float-particle ${dur}ms ease-out forwards`
        });
        document.body.appendChild(p);
        setTimeout(() => p.remove(), dur);
    }
    setInterval(spawnParticle, 600);
})();

// cursor glow
const glow = document.createElement('div');
Object.assign(glow.style, {
    position: 'fixed', pointerEvents: 'none', zIndex: '9999',
    width: '300px', height: '300px', borderRadius: '50%',
    background: 'radial-gradient(circle, rgba(232,184,75,0.06) 0%, transparent 70%)',
    transform: 'translate(-50%,-50%)', transition: 'left 0.12s ease, top 0.12s ease',
    left: '-999px', top: '-999px'
});
document.body.appendChild(glow);
document.addEventListener('mousemove', e => {
    glow.style.left = e.clientX + 'px';
    glow.style.top  = e.clientY + 'px';
});
</script>


</body>
</html>