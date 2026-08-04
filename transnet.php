<?php
session_start();
require_once '../../config/db.php';

// ---------------- AUTH ----------------
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit();
}

$user_id = (int)$_SESSION['user_id'];

// =========================
// <i data-lucide="user" style="width: 1em; height: 1em; display: inline-block; vertical-align: middle;"></i> USER INFO
// =========================
$stmt = mysqli_prepare($conn, "SELECT name FROM users WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($result);

if (!$user) {
    session_destroy();
    header('Location: ../../index.php');
    exit();
}

$userName    = $user['name'];
$fname       = explode(' ', $userName)[0];
$userInitial = strtoupper(substr($userName, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>TransNet X — Portal</title>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
<!-- Font Awesome 6 -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
:root{
  --gold:#D4A843;--gold2:#F0C96A;--gold3:#9A6F1E;
  --black:#06060A;--card:rgba(255,255,255,.04);
  --border:rgba(212,168,67,.15);--text:#EDE8DC;--muted:rgba(237,232,220,.45);
  --r:16px;--r2:10px;
  --uber:#34D399;--trip:#60A5FA;--flight:#A78BFA;--rental:#FB923C;
}
html{scroll-behavior:smooth}
body{font-family:'Outfit',sans-serif;background:var(--black);color:var(--text);min-height:100vh;overflow-x:hidden;display:flex;flex-direction:column;}

/* ── CANVAS BG ── */
#bgCanvas{position:fixed;inset:0;z-index:0;pointer-events:none}

/* ── SIDEBAR (left side) ── */
.sidebar {
  position: fixed; left: -280px; top: 0; width: 280px; height: 100vh;
  background: rgba(6,6,10,0.98); backdrop-filter: blur(20px);
  border-right: 1px solid var(--border); z-index: 1000;
  transition: left 0.3s cubic-bezier(0.16,1,0.3,1);
  padding: 24px 0; display: flex; flex-direction: column;
}
.sidebar.open { left: 0; }
.sidebar-overlay {
  position: fixed; inset: 0; background: rgba(0,0,0,0.5);
  backdrop-filter: blur(3px); z-index: 999;
  opacity: 0; visibility: hidden; transition: all 0.3s;
}
.sidebar-overlay.show { opacity: 1; visibility: visible; }

.sidebar-header {
  padding: 0 20px 20px; border-bottom: 1px solid var(--border); margin-bottom: 16px;
}
.sidebar-logo {
  font-family: 'Bebas Neue', sans-serif; font-size: 24px; letter-spacing: 3px;
  color: var(--text); text-decoration: none;
}
.sidebar-logo span {
  background: linear-gradient(135deg, var(--gold2), var(--gold));
  -webkit-background-clip: text; -webkit-text-fill-color: transparent;
}
.sidebar-user {
  display: flex; align-items: center; gap: 12px; padding: 12px 20px; margin-bottom: 16px;
}
.sidebar-avatar {
  width: 48px; height: 48px; border-radius: 50%;
  background: linear-gradient(135deg, var(--gold3), var(--gold2));
  display: flex; align-items: center; justify-content: center;
  font-weight: 700; font-size: 18px; color: var(--black);
}
.sidebar-user-info h4 { font-size: 15px; font-weight: 600; }
.sidebar-user-info p { font-size: 11px; color: var(--muted); text-transform: uppercase; letter-spacing: 1px; }

.sidebar-nav {
  flex: 1; display: flex; flex-direction: column; gap: 4px; padding: 0 12px;
}
.sidebar-item {
  display: flex; align-items: center; gap: 14px; padding: 14px 16px;
  border-radius: 10px; color: var(--muted); text-decoration: none;
  font-size: 14px; font-weight: 500; transition: all 0.2s; border: 1px solid transparent;
}
.sidebar-item i {
  width: 22px;
  font-size: 1.2rem;
  color: currentColor;
}
.sidebar-item:hover {
  background: rgba(212,168,67,0.08); color: var(--text); border-color: var(--border);
}
.sidebar-item.active {
  background: rgba(212,168,67,0.12); color: var(--gold); border-color: rgba(212,168,67,0.3);
}
.sidebar-item.logout { color: #F87171; margin-top: auto; }
.sidebar-item.logout:hover { background: rgba(248,113,113,0.08); border-color: rgba(248,113,113,0.2); }

.sidebar-footer {
  padding: 20px; border-top: 1px solid var(--border); margin-top: 16px;
  font-size: 11px; color: var(--muted); text-align: center;
}

/* ── HAMBURGER ── */
.hamburger {
  width: 40px; height: 40px;
  display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  gap: 6px; cursor: pointer; border-radius: 8px;
  transition: all 0.2s; margin-right: 12px;
}
.hamburger:hover { background: rgba(255,255,255,0.05); }
.hamburger span {
  width: 22px; height: 2px; background: var(--text); border-radius: 2px;
  transition: all 0.3s;
}
.hamburger.open span:nth-child(1) { transform: rotate(45deg) translate(5px,6px); }
.hamburger.open span:nth-child(2) { opacity: 0; }
.hamburger.open span:nth-child(3) { transform: rotate(-45deg) translate(5px,-6px); }

/* ── TOPBAR ── */
.topbar{
  position:fixed; top:0; left:0; right:0; z-index:200;
  display:flex; align-items:center; justify-content:space-between;
  padding:0 24px; height:66px;
  background:rgba(6,6,10,.82); backdrop-filter:blur(24px);
  border-bottom:1px solid var(--border);
}
.topbar-left { display: flex; align-items: center; }
.logo{
  font-family:'Bebas Neue',sans-serif; font-size:26px; letter-spacing:2px;
  background:linear-gradient(135deg,var(--gold2),var(--gold));
  -webkit-background-clip:text; -webkit-text-fill-color:transparent;
  text-decoration:none;
}
.logo span{color:rgba(212,168,67,.4); font-size:13px; margin-left:4px; letter-spacing:4px; vertical-align:middle; -webkit-text-fill-color:rgba(212,168,67,.4);}

.user-avatar {
  width: 38px; height: 38px; border-radius: 50%;
  background: linear-gradient(135deg, var(--gold3), var(--gold2));
  display: flex; align-items: center; justify-content: center;
  font-weight: 700; font-size: 14px; color: var(--black);
  cursor: pointer; border: 1.5px solid rgba(212,168,67,0.3);
}

/* ── HERO ── */
.hero{
  position:relative; z-index:1;
  min-height:100vh; display:flex; align-items:center; justify-content:center;
  padding-top:66px; flex-direction:column; text-align:center;
  padding:120px 40px 60px;
  flex: 1;
}
.hero-eyebrow{
  font-size:11px; letter-spacing:4px; text-transform:uppercase;
  color:var(--gold); border:1px solid var(--border);
  border-radius:20px; padding:5px 16px; display:inline-block;
  margin-bottom:28px; animation:fadeUp .6s ease both;
}
.hero-title{
  font-family:'Bebas Neue',sans-serif;
  font-size:clamp(64px,9vw,130px); line-height:.9;
  letter-spacing:2px; margin-bottom:20px;
  animation:fadeUp .7s .1s ease both;
}
.hero-title .gold{
  background:linear-gradient(135deg,var(--gold2),var(--gold),var(--gold3));
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;
}
.hero-sub{
  font-size:16px; color:var(--muted); max-width:480px; margin:0 auto 48px;
  line-height:1.7; font-weight:300;
  animation:fadeUp .7s .2s ease both;
}

/* service grid */
.services-grid{
  display:grid; grid-template-columns:repeat(4,1fr); gap:16px;
  max-width:800px; width:100%; margin:0 auto;
  animation:fadeUp .7s .35s ease both;
}
.service-card{
  position:relative; overflow:hidden; border-radius:var(--r);
  border:1px solid rgba(255,255,255,.06);
  background:var(--card); backdrop-filter:blur(16px);
  padding:28px 20px; cursor:pointer;
  text-decoration:none; color:var(--text);
  transition:transform .3s cubic-bezier(.34,1.56,.64,1), box-shadow .3s;
  group:true;
}
.service-card::before{
  content:'';position:absolute;inset:0;
  background:linear-gradient(135deg,var(--sc,rgba(52,211,153,.06)),transparent 60%);
  opacity:0;transition:opacity .3s;
}
.service-card:hover{transform:translateY(-6px) scale(1.02);}
.service-card:hover::before{opacity:1;}
.service-card:hover{box-shadow:0 20px 60px rgba(0,0,0,.4),0 0 0 1px var(--sbc,rgba(52,211,153,.3));}

.service-card.uber   {--sc:rgba(52,211,153,.08); --sbc:rgba(52,211,153,.3); --ic:var(--uber);}
.service-card.trip   {--sc:rgba(96,165,250,.08); --sbc:rgba(96,165,250,.3); --ic:var(--trip);}
.service-card.flight {--sc:rgba(167,139,250,.08);--sbc:rgba(167,139,250,.3);--ic:var(--flight);}
.service-card.rental {--sc:rgba(251,146,60,.08); --sbc:rgba(251,146,60,.3); --ic:var(--rental);}

.sc-icon{
  width:52px;height:52px;border-radius:14px;
  display:flex;align-items:center;justify-content:center;
  font-size:26px;margin-bottom:16px;
  background:rgba(255,255,255,.05);
  border:1px solid rgba(255,255,255,.06);
  transition:transform .3s;
}
.service-card:hover .sc-icon{transform:scale(1.12) rotate(-5deg);}
.sc-name{
  font-family:'Bebas Neue',sans-serif;font-size:22px;
  letter-spacing:1.5px;margin-bottom:6px;color:var(--ic);
}
.sc-desc{font-size:12px;color:var(--muted);line-height:1.6;font-weight:300;}
.sc-arrow{
  position:absolute;bottom:16px;right:16px;
  font-size:18px;opacity:0;transform:translateX(-6px);
  transition:all .3s;color:var(--ic);
}
.service-card:hover .sc-arrow{opacity:1;transform:none;}

.service-card::after{
  content:'';position:absolute;bottom:0;left:0;right:0;height:2px;
  background:linear-gradient(90deg,transparent,var(--ic),transparent);
  transform:scaleX(0);transition:transform .35s ease;
}
.service-card:hover::after{transform:scaleX(1);}

@keyframes fadeUp{0%{opacity:0;transform:translateY(20px)}100%{opacity:1;transform:none}}

/* ── FOOTER STYLES (gold theme) ── */
.app-footer {
  position: relative;
  z-index: 2;
  background: rgba(6,6,10,0.96);
  backdrop-filter: blur(12px);
  border-top: 1px solid var(--border);
  margin-top: 2rem;
  padding: 2rem 0 1.5rem;
  width: 100%;
  font-size: 0.875rem;
}
.footer-container {
  max-width: 1280px;
  margin: 0 auto;
  padding: 0 24px;
}
.footer-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 2rem;
  margin-bottom: 2rem;
}
.footer-brand p {
  color: var(--muted);
  font-size: 0.8rem;
  line-height: 1.5;
  margin-top: 0.75rem;
}
.footer-title {
  font-weight: 700;
  font-size: 0.85rem;
  text-transform: uppercase;
  letter-spacing: 1px;
  color: var(--gold);
  margin-bottom: 1rem;
}
.footer-links {
  list-style: none;
  padding: 0;
}
.footer-links li {
  margin-bottom: 0.5rem;
}
.footer-links a {
  color: var(--muted);
  text-decoration: none;
  transition: color 0.2s ease;
  font-size: 0.8rem;
}
.footer-links a:hover {
  color: var(--gold2);
}
.social-icons {
  display: flex;
  gap: 1rem;
  margin-bottom: 1rem;
}
.social-icons a {
  color: var(--muted);
  font-size: 1.2rem;
  transition: all 0.2s;
}
.social-icons a:hover {
  color: var(--gold2);
  transform: translateY(-2px);
}
.copyright {
  text-align: center;
  padding-top: 1.5rem;
  border-top: 1px solid rgba(212,168,67,0.1);
  font-size: 0.7rem;
  color: var(--muted);
}

@media(max-width:700px){ .services-grid{grid-template-columns:1fr 1fr;} }
@media(max-width:480px){ .services-grid{grid-template-columns:1fr;} }
</style>
</head>
<body>
<canvas id="bgCanvas"></canvas>

<!-- SIDEBAR OVERLAY -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<!-- LEFT SIDEBAR -->
<aside class="sidebar" id="sidebar">
  <div class="sidebar-header">
    <a href="TransNet.php" class="sidebar-logo"><span>TransNet X</span></a>
  </div>
  
  <div class="sidebar-user">
    <div class="sidebar-avatar"><?= $userInitial ?></div>
    <div class="sidebar-user-info">
      <h4><?= htmlspecialchars($fname) ?></h4>
      <p>Traveler</p>
    </div>
  </div>
  
  <nav class="sidebar-nav">
    <a href="TransNet.php" class="sidebar-item active"><i class="fas fa-home"></i> Portal</a>
    <a href="uber.php"     class="sidebar-item"><i class="fas fa-car"></i> Rides</a>
    <a href="trip.php"     class="sidebar-item"><i class="fas fa-bus"></i> Trips</a>
    <a href="flight.php"   class="sidebar-item"><i class="fas fa-plane"></i> Flights</a>
    <a href="rental.php"   class="sidebar-item"><i class="fas fa-key"></i> Rentals</a>
    <a href="../dashboard.php" class="sidebar-item"><i class="fas fa-chart-line"></i> Dashboard</a>
    <a href="../records.php"  class="sidebar-item"><i class="fas fa-clipboard-list"></i> Records</a>
    <a href="../settings.php" class="sidebar-item"><i class="fas fa-cog"></i> Settings</a>
    <a href="../../index.php" class="sidebar-item logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
  </nav>
  
  <div class="sidebar-footer">
    © 2025 TransNet X<br>Version 2.1.0
  </div>
</aside>

<!-- TOPBAR -->
<nav class="topbar">
  <div class="topbar-left">
    <div class="hamburger" id="hamburger" onclick="toggleSidebar()">
      <span></span><span></span><span></span>
    </div>
    <a href="TransNet.php" class="logo">TransNet<span>X</span></a>
  </div>
  <div class="user-avatar" onclick="window.location.href='../profile.php'" title="Your Profile">
    <?= $userInitial ?>
  </div>
</nav>

<!-- HERO -->
<section class="hero">
  <div class="hero-eyebrow">Welcome back, <?= htmlspecialchars($fname) ?></div>
  <h1 class="hero-title">
    MOVE<br><span class="gold">SMARTER.</span>
  </h1>
  <p class="hero-sub">
    One platform for rides, intercity trips, flights, and rentals. 
    Where do you want to go today?
  </p>

  <div class="services-grid">
    <a href="uber.php" class="service-card uber">
      <div class="sc-icon"><i class="fas fa-car"></i></div>
      <div class="sc-name">TransRide</div>
      <div class="sc-desc">Instant rides with verified drivers.</div>
      <div class="sc-arrow"><i class="fas fa-arrow-right"></i></div>
    </a>
    <a href="trip.php" class="service-card trip">
      <div class="sc-icon"><i class="fas fa-bus"></i></div>
      <div class="sc-name">TransTrip</div>
      <div class="sc-desc">Intercity coach travel. Comfort seats.</div>
      <div class="sc-arrow"><i class="fas fa-arrow-right"></i></div>
    </a>
    <a href="flight.php" class="service-card flight">
      <div class="sc-icon"><i class="fas fa-plane"></i></div>
      <div class="sc-name">TransFly</div>
      <div class="sc-desc">Flights across Africa.</div>
      <div class="sc-arrow"><i class="fas fa-arrow-right"></i></div>
    </a>
    <a href="rental.php" class="service-card rental">
      <div class="sc-icon"><i class="fas fa-key"></i></div>
      <div class="sc-name">TransRent</div>
      <div class="sc-desc">Premium vehicle rentals.</div>
      <div class="sc-arrow"><i class="fas fa-arrow-right"></i></div>
    </a>
  </div>
</section>

<!-- ========== INTEGRATED FOOTER (gold/black theme) ========== -->
<footer class="app-footer">
  <div class="footer-container">
    <div class="footer-grid">
      <!-- Brand column -->
      <div class="footer-brand">
        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
          <div style="width: 32px; height: 32px; border-radius: 8px; background: linear-gradient(135deg, var(--gold3), var(--gold)); display: flex; align-items: center; justify-content: center;">
            <i class="fas fa-compass" style="color: var(--black); font-size: 1rem;"></i>
          </div>
          <span style="font-weight: 800; font-size: 1.2rem; background: linear-gradient(135deg, var(--gold2), var(--gold)); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">TransNet X</span>
        </div>
        <p>Your one-stop travel & lifestyle platform. Book rides, flights, trips, rentals, and more with ease.</p>
      </div>

      <!-- Quick Links -->
      <div>
        <h4 class="footer-title">Quick Links</h4>
        <ul class="footer-links">
          <li><a href="../about.php">About Us</a></li>
          <li><a href="../contact.php">Contact</a></li>
          <li><a href="../privacy.php">Privacy Policy</a></li>
          <li><a href="../terms.php">Terms of Service</a></li>
        </ul>
      </div>

      <!-- Support -->
      <div>
        <h4 class="footer-title">Support</h4>
        <ul class="footer-links">
          <li><a href="../faq.php">FAQ</a></li>
          <li><a href="../help.php">Help Center</a></li>
          <li><a href="../refund.php">Refund Policy</a></li>
        </ul>
      </div>

      <!-- Connect -->
      <div>
        <h4 class="footer-title">Connect With Us</h4>
        <div class="social-icons">
          <a href="#"><i class="fab fa-facebook-f"></i></a>
          <a href="#"><i class="fab fa-twitter"></i></a>
          <a href="#"><i class="fab fa-instagram"></i></a>
          <a href="#"><i class="fab fa-linkedin-in"></i></a>
        </div>
        <p class="copyright">© 2025 TransNet X. All rights reserved.</p>
      </div>
    </div>
  </div>
</footer>

<script>
// ── SIDEBAR TOGGLE ──
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('show');
    document.getElementById('hamburger').classList.toggle('open');
}

// ── CANVAS BACKGROUND ──
const canvas=document.getElementById('bgCanvas');
const ctx=canvas.getContext('2d');
let W,H,nodes=[];
function resize(){W=canvas.width=innerWidth;H=canvas.height=innerHeight;}
resize(); window.addEventListener('resize',()=>{resize();init();});

function init(){
  nodes=[];
  const n=Math.floor(W*H/18000);
  for(let i=0;i<n;i++) nodes.push({
    x:Math.random()*W, y:Math.random()*H,
    vx:(Math.random()-.5)*.4, vy:(Math.random()-.5)*.4,
    r:Math.random()*2+1, o:Math.random()*.5+.1
  });
}
init();

const GOLD='rgba(212,168,67,';
function draw(){
  ctx.clearRect(0,0,W,H);
  nodes.forEach((a,i)=>{
    nodes.slice(i+1).forEach(b=>{
      const dx=a.x-b.x, dy=a.y-b.y, d=Math.sqrt(dx*dx+dy*dy);
      if(d<140){
        ctx.beginPath();
        ctx.moveTo(a.x,a.y); ctx.lineTo(b.x,b.y);
        ctx.strokeStyle=GOLD+(0.06*(1-d/140))+')';
        ctx.lineWidth=.6; ctx.stroke();
      }
    });
    ctx.beginPath();
    ctx.arc(a.x,a.y,a.r,0,Math.PI*2);
    ctx.fillStyle=GOLD+a.o+')';
    ctx.fill();
    a.x+=a.vx; a.y+=a.vy;
    if(a.x<0||a.x>W) a.vx*=-1;
    if(a.y<0||a.y>H) a.vy*=-1;
  });
  requestAnimationFrame(draw);
}
draw();
</script>

<script src="../../assets/offline-icons.js"></script>
</body>
</html>