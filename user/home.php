<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';

$user = auth_user($pdo);
$is_guest = false;
if (!$user) {
    $is_guest = true;
    $user = [
        'id' => 0, 'username' => 'Tamu', 'balance_wd' => 0, 'balance_dep' => 0,
        'membership_id' => null, 'membership_expires_at' => null, 'referral_code' => '-', 'is_promotor' => 0,
    ];
}

if (is_maintenance($pdo) && !auth_admin()) {
    $maintenance_msg = setting($pdo, 'maintenance_message', 'Sistem sedang dalam perbaikan.');
    require dirname(__DIR__) . '/user/maintenance.php'; exit;
}

track_pageview($pdo, parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

$watch_limit = $is_guest ? 0 : user_watch_limit($pdo, $user);
$watch_today = $is_guest ? 0 : user_watch_today($pdo, $user);

if ($is_guest) {
    $videos = $pdo->query("SELECT v.* FROM videos v WHERE v.is_active=1 ORDER BY v.sort_order ASC, v.id DESC LIMIT 6")->fetchAll();
    $history = []; $notif_preview = []; $notif_unread = 0;
} else {
    $videos = $pdo->prepare(
        "SELECT v.* FROM videos v WHERE v.is_active=1
           AND v.id NOT IN (SELECT video_id FROM watch_history WHERE user_id=? AND DATE(watched_at)=CURDATE())
         ORDER BY v.sort_order ASC, v.id DESC LIMIT 6"
    );
    $videos->execute([$user['id']]); $videos = $videos->fetchAll();

    $history = $pdo->prepare(
        "SELECT wh.reward_given, wh.watched_at, v.title FROM watch_history wh
         JOIN videos v ON v.id=wh.video_id WHERE wh.user_id=? ORDER BY wh.watched_at DESC LIMIT 4"
    );
    $history->execute([$user['id']]); $history = $history->fetchAll();

    $notif_preview = []; $notif_unread = 0;
    try {
        $uid = $user['id'];
        $np = $pdo->prepare(
            "SELECT n.* FROM notifications n LEFT JOIN notification_reads nr ON nr.notification_id=n.id AND nr.user_id=?
             WHERE nr.id IS NULL AND (n.target_type='all' OR (n.target_user_ids IS NOT NULL AND JSON_CONTAINS(n.target_user_ids, JSON_QUOTE(?))))
               AND (n.expires_at IS NULL OR n.expires_at > NOW()) ORDER BY n.created_at DESC LIMIT 3"
        );
        $np->execute([$uid, (string)$uid]); $notif_preview = $np->fetchAll();
        $nc = $pdo->prepare(
            "SELECT COUNT(*) FROM notifications n LEFT JOIN notification_reads nr ON nr.notification_id=n.id AND nr.user_id=?
             WHERE nr.id IS NULL AND (n.target_type='all' OR (n.target_user_ids IS NOT NULL AND JSON_CONTAINS(n.target_user_ids, JSON_QUOTE(?))))
               AND (n.expires_at IS NULL OR n.expires_at > NOW())"
        );
        $nc->execute([$uid, (string)$uid]); $notif_unread = (int)$nc->fetchColumn();
    } catch (\Throwable) {}
}

$membership_name = '';
if ($user['membership_id'] && $user['membership_expires_at'] && strtotime((string)$user['membership_expires_at']) > time()) {
    $ms = $pdo->prepare("SELECT name FROM memberships WHERE id=?");
    $ms->execute([$user['membership_id']]);
    $membership_name = $ms->fetchColumn();
}
if (!$membership_name) {
    $membership_name = $pdo->query("SELECT name FROM memberships WHERE price=0 AND is_active=1 ORDER BY sort_order ASC LIMIT 1")->fetchColumn();
    if (!$membership_name) $membership_name = 'Free';
}

$showcase_memberships = $pdo->query("SELECT * FROM memberships WHERE is_active=1 AND price > 0 ORDER BY sort_order ASC")->fetchAll();

$wd_require_level = setting($pdo, 'wd_require_level', '0') === '1';
$wd_min_level  = (int) setting($pdo, 'wd_min_level', '0');
$user_level    = user_membership_level($pdo, $user);
$level_blocked = $wd_require_level && $wd_min_level > 0 && $user_level < $wd_min_level;

$pageTitle  = 'Lobby';
$activePage = 'home';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ══════════════════════════════════════════════════════════
   HOME PAGE — CASUAL GAME UI v2 — FULL REDESIGN
   ══════════════════════════════════════════════════════════ */

body { background: #f97316 !important; }

/* Flash */
.flash-alert { margin: 10px 14px; padding: 10px 14px; border-radius: 14px; font-size: 12px; font-weight: 800; display: flex; align-items: center; gap: 8px; border: 2.5px solid; font-family: 'Nunito', sans-serif; }
.flash-alert--err { background: #fef2f2; color: #991b1b; border-color: #fca5a5; }

/* ── HERO ── */
.hero {
  background: linear-gradient(160deg, #fbbf24 0%, #f97316 55%, #ea580c 100%);
  padding: 14px 14px 0;
  position: relative; overflow: hidden;
}
.hero::before {
  content: ''; position: absolute;
  top: -60px; right: -40px;
  width: 180px; height: 180px;
  background: rgba(255,255,255,0.07);
  border-radius: 50%; pointer-events: none;
}
.hero::after {
  content: ''; position: absolute;
  bottom: 20px; left: -30px;
  width: 100px; height: 100px;
  background: rgba(255,255,255,0.05);
  border-radius: 50%; pointer-events: none;
}

/* Greeting row */
.hero-greet {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 14px;
}
.hero-greet__left { display: flex; align-items: center; gap: 10px; }
.hero-avatar {
  width: 48px; height: 48px;
  background: #fff;
  border: 3px solid #fde68a;
  border-radius: 16px;
  display: flex; align-items: center; justify-content: center;
  font-size: 24px; font-weight: 900; color: #ea580c;
  box-shadow: 0 5px 0 #d97706;
  flex-shrink: 0;
}
.hero-name { font-size: 16px; font-weight: 900; color: #fff; text-shadow: 0 2px 4px rgba(0,0,0,0.15); }
.hero-badge {
  display: inline-flex; align-items: center; gap: 3px;
  background: rgba(255,255,255,0.22);
  border: 1.5px solid rgba(255,255,255,0.4);
  border-radius: 20px; padding: 2px 8px;
  font-size: 10px; font-weight: 900; color: #fff;
  margin-top: 2px;
}
.hero-login-btn {
  display: flex; align-items: center; gap: 5px;
  background: #fff;
  border: 3px solid #fde68a;
  border-radius: 14px; padding: 8px 14px;
  color: #ea580c; font-size: 12px; font-weight: 900;
  text-decoration: none;
  box-shadow: 0 4px 0 #d97706;
  transition: transform 0.1s;
  animation: pulse-btn 2.5s ease infinite;
  font-family: 'Nunito', sans-serif;
}
.hero-login-btn:active { transform: translateY(3px); box-shadow: none; }
@keyframes pulse-btn {
  0%,100% { box-shadow: 0 4px 0 #d97706; }
  50% { box-shadow: 0 4px 0 #d97706, 0 0 16px rgba(255,255,255,0.4); }
}

/* Big balance display */
.hero-balance {
  background: rgba(0,0,0,0.12);
  border: 2px solid rgba(255,255,255,0.25);
  border-radius: 20px;
  padding: 14px 16px;
  margin-bottom: 12px;
  display: flex; align-items: center; gap: 12px;
}
.hero-balance__mascot { font-size: 44px; flex-shrink: 0; animation: bob 3s ease-in-out infinite; }
@keyframes bob { 0%,100% { transform: translateY(0) rotate(-3deg); } 50% { transform: translateY(-6px) rotate(3deg); } }
.hero-balance__info { flex: 1; }
.hero-balance__label { font-size: 10px; font-weight: 800; color: rgba(255,255,255,0.75); text-transform: uppercase; letter-spacing: 0.5px; }
.hero-balance__amount { font-size: 30px; font-weight: 900; color: #fff; text-shadow: 0 2px 6px rgba(0,0,0,0.2); line-height: 1.1; }
.hero-balance__sub { font-size: 11px; font-weight: 700; color: rgba(255,255,255,0.7); margin-top: 2px; }

/* Action buttons row below balance card */
.hero-btn-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
  margin-bottom: 14px;
}
.hero-action-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 12px 10px;
  border-radius: 16px;
  text-decoration: none;
  font-family: 'Nunito', sans-serif;
  font-size: 13px;
  font-weight: 900;
  color: #fff;
  border: 3px solid #0f172a;
  box-shadow: 0 4px 0 #0f172a;
  transition: transform 0.1s, box-shadow 0.1s;
}
.hero-action-btn:active {
  transform: translateY(3px);
  box-shadow: 0 1px 0 #0f172a;
}
.hero-action-btn i {
  font-size: 18px;
}
.hero-action-btn--wd {
  background: linear-gradient(135deg, #10b981 0%, #059669 100%);
}
.hero-action-btn--dep {
  background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
}

/* Progress bar */
.hero-progress {
  background: rgba(255,255,255,0.15);
  border: 2px solid rgba(255,255,255,0.28);
  border-radius: 16px; padding: 10px 12px;
  margin-bottom: 14px;
}
.hero-progress__hd { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; }
.hero-progress__lbl { font-size: 10px; font-weight: 800; color: rgba(255,255,255,0.85); display: flex; align-items: center; gap: 4px; }
.hero-progress__ct { font-size: 12px; font-weight: 900; color: #fff; }
.hero-progress__track { height: 12px; background: rgba(0,0,0,0.2); border-radius: 20px; overflow: hidden; }
.hero-progress__fill {
  height: 100%;
  background: linear-gradient(90deg, #fff 0%, #fde68a 100%);
  border-radius: 20px; transition: width 0.6s ease;
  position: relative;
}
.hero-progress__fill::after {
  content: ''; position: absolute; top: 0; left: 0; right: 0;
  height: 50%; background: rgba(255,255,255,0.4); border-radius: 20px;
}

/* Wave divider */
.hero-wave {
  display: block; width: 100%;
  height: 28px; margin-bottom: -2px;
  background: transparent;
}

/* ── CONTENT AREA ── */
.page-content { display: flex; flex-direction: column; padding-bottom: 0 !important; }
.home-body { background: #fff8f0; padding: 16px 14px calc(var(--nav-h) + 24px); flex: 1; }

.info-bar {
  background: #fff;
  border: 3px solid #0f172a;
  border-radius: 16px; padding: 12px 14px;
  display: flex; align-items: center; gap: 12px;
  box-shadow: 0 5px 0 #0f172a;
  color: #0f172a;
}
.info-bar--orange { background: #fff8f0; border-color: #ea580c; box-shadow: 0 5px 0 #c2410c; }

/* ── SECTION HEADER ── */
.sh {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 10px;
}
.sh__title {
  display: flex; align-items: center; gap: 6px;
  font-size: 14px; font-weight: 900; color: #0f172a;
}
.sh__link {
  font-size: 10px; font-weight: 900;
  background: #fff3e0; color: #ea580c;
  border: 2px solid #fb923c;
  padding: 3px 10px; border-radius: 20px;
  text-decoration: none;
}

/* ══ BENTO QUICK ACTIONS ══ */
.bento-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  grid-template-rows: auto auto auto;
  gap: 8px;
  margin-bottom: 16px;
}
/* Big item: spans 2 cols × 2 rows */
.bento-big {
  grid-column: span 2;
  grid-row: span 2;
  border-radius: 22px;
  text-decoration: none;
  display: flex; flex-direction: column;
  align-items: flex-start; justify-content: flex-end;
  padding: 14px;
  min-height: 120px;
  position: relative; overflow: hidden;
  border: 3px solid rgba(255,255,255,0.25);
  box-shadow: 0 6px 0 rgba(0,0,0,0.2);
  transition: transform 0.1s;
}
.bento-big:active { transform: translateY(4px); box-shadow: none; }
.bento-big__emoji {
  position: absolute; top: 8px; right: 10px;
  font-size: 44px; opacity: 0.25; pointer-events: none;
  animation: float-slow 4s ease-in-out infinite;
}
@keyframes float-slow { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-6px)} }
.bento-big__icon {
  font-size: 28px; color: rgba(255,255,255,0.9);
  margin-bottom: 6px;
  filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2));
}
.bento-big__label {
  font-size: 13px; font-weight: 900; color: #fff;
  text-shadow: 0 1px 3px rgba(0,0,0,0.25);
  line-height: 1.2;
}
.bento-big__sub {
  font-size: 9px; font-weight: 700;
  color: rgba(255,255,255,0.75); margin-top: 2px;
}
/* Small item: 1 col × 1 row */
.bento-sm {
  border-radius: 18px;
  text-decoration: none;
  display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  gap: 5px;
  padding: 10px 4px;
  border: 2.5px solid rgba(255,255,255,0.22);
  box-shadow: 0 4px 0 rgba(0,0,0,0.18);
  transition: transform 0.1s;
  min-height: 76px;
}
.bento-sm:active { transform: translateY(3px); box-shadow: none; }
.bento-sm i { font-size: 22px; color: #fff; }
.bento-sm__label {
  font-size: 9px; font-weight: 900; color: rgba(255,255,255,0.92);
  text-align: center; line-height: 1.2;
  text-shadow: 0 1px 2px rgba(0,0,0,0.2);
}
/* Wide item: spans 2 cols */
.bento-wide {
  grid-column: span 2;
  border-radius: 18px;
  text-decoration: none;
  display: flex; align-items: center; gap: 10px;
  padding: 10px 14px;
  border: 2.5px solid rgba(255,255,255,0.22);
  box-shadow: 0 4px 0 rgba(0,0,0,0.18);
  transition: transform 0.1s;
}
.bento-wide:active { transform: translateY(3px); box-shadow: none; }
.bento-wide i { font-size: 22px; color: #fff; flex-shrink: 0; }
.bento-wide__txt { }
.bento-wide__label { font-size: 12px; font-weight: 900; color: #fff; text-shadow: 0 1px 2px rgba(0,0,0,0.2); }
.bento-wide__sub { font-size: 9px; font-weight: 700; color: rgba(255,255,255,0.75); }

/* ── BALANCE TILES ── */
.bal-row {
  display: grid; grid-template-columns: 1fr 1fr; gap: 10px;
  margin-bottom: 16px;
}
.bal-tile {
  background: #fff;
  border: 3px solid #0f172a;
  border-radius: 20px; padding: 14px 12px;
  box-shadow: 0 6px 0 #0f172a;
  text-decoration: none; color: inherit; display: block;
  transition: transform 0.1s; position: relative; overflow: hidden;
}
.bal-tile:active { transform: translateY(4px); box-shadow: 0 2px 0 #0f172a; }
.bal-tile--wd  { border-color: #064e3b; box-shadow: 0 6px 0 #064e3b; }
.bal-tile--dep { border-color: #1e3a8a; box-shadow: 0 6px 0 #1e3a8a; }
.bal-tile__deco { position: absolute; bottom: -10px; right: -10px; font-size: 50px; opacity: 0.07; pointer-events: none; }
.bal-tile__hd { display: flex; align-items: center; gap: 6px; margin-bottom: 6px; }
.bal-tile__ico { width: 30px; height: 30px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 15px; border: 2px solid rgba(0,0,0,0.08); }
.bal-tile--wd  .bal-tile__ico { background: #d1fae5; }
.bal-tile--dep .bal-tile__ico { background: #dbeafe; }
.bal-tile__lbl { font-size: 10px; font-weight: 800; color: #64748b; }
.bal-tile__val { font-size: 17px; font-weight: 900; color: #0f172a; line-height: 1; margin-bottom: 8px; }
.bal-tile__btn {
  display: inline-flex; align-items: center; gap: 4px;
  font-size: 11px; font-weight: 900;
  padding: 5px 12px; border-radius: 20px; border: 2px solid;
  font-family: 'Nunito', sans-serif;
}
.bal-tile--wd  .bal-tile__btn { background: #d1fae5; color: #065f46; border-color: #6ee7b7; }
.bal-tile--dep .bal-tile__btn { background: #dbeafe; color: #1e40af; border-color: #93c5fd; }

/* ── CARD SECTIONS ── */
.cg-card {
  background: #fff;
  border: 3px solid #0f172a;
  border-radius: 22px; padding: 14px;
  margin-bottom: 14px;
  box-shadow: 0 6px 0 #0f172a;
}
.cg-card--orange { border-color: #ea580c; box-shadow: 0 6px 0 #c2410c; background: #fff8f0; }
.cg-card--green  { border-color: #064e3b; box-shadow: 0 6px 0 #064e3b; background: #f0fdf4; }
.cg-card--yellow { border-color: #d97706; box-shadow: 0 6px 0 #b45309; background: #fffbeb; }

/* ══ VIDEO — FEATURED + MINI SCROLL ══ */
/* Featured big video */
.vid-featured {
  position: relative; border-radius: 20px; overflow: hidden;
  border: 3px solid #0f172a; box-shadow: 0 6px 0 #0f172a;
  text-decoration: none; display: block; margin-bottom: 10px;
  transition: transform 0.1s;
  aspect-ratio: 16/9;
  background: #000;
}
.vid-featured:active { transform: translateY(4px); box-shadow: none; }
.vid-featured img { width: 100%; height: 100%; object-fit: cover; display: block; opacity: 0.88; }
.vid-featured__overlay {
  position: absolute; inset: 0;
  background: linear-gradient(to top, rgba(0,0,0,0.75) 0%, rgba(0,0,0,0.1) 55%, transparent 100%);
  display: flex; flex-direction: column; justify-content: flex-end;
  padding: 12px;
}
.vid-featured__play {
  position: absolute; top: 50%; left: 50%;
  transform: translate(-50%,-50%);
  width: 52px; height: 52px;
  background: rgba(255,255,255,0.2);
  border: 3px solid rgba(255,255,255,0.6);
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  backdrop-filter: blur(4px);
}
.vid-featured__play i { font-size: 26px; color: #fff; margin-left: 3px; }
.vid-featured__badge {
  display: inline-flex; align-items: center; gap: 4px;
  background: linear-gradient(135deg, #22c55e, #16a34a);
  color: #fff; font-size: 10px; font-weight: 900;
  padding: 4px 10px; border-radius: 10px;
  border: 1.5px solid rgba(255,255,255,0.4);
  box-shadow: 0 2px 0 #15803d;
  width: fit-content; margin-bottom: 5px;
}
.vid-featured__title {
  font-size: 13px; font-weight: 900; color: #fff;
  text-shadow: 0 1px 4px rgba(0,0,0,0.5);
  line-height: 1.3;
  display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}
.vid-featured__meta {
  display: flex; align-items: center; gap: 8px;
  font-size: 10px; font-weight: 800; color: rgba(255,255,255,0.8);
  margin-top: 4px;
}
/* Mini video scroll */
.vid-mini-scroll {
  display: flex; gap: 8px; overflow-x: auto;
  scroll-snap-type: x mandatory; scrollbar-width: none;
  margin: 0 -14px; padding: 0 14px 4px;
}
.vid-mini-scroll::-webkit-scrollbar { display: none; }
.vid-mini {
  flex: 0 0 110px; scroll-snap-align: start;
  text-decoration: none; display: flex; flex-direction: column;
  background: #fff; border: 2.5px solid #0f172a;
  border-radius: 14px; overflow: hidden;
  box-shadow: 0 4px 0 #0f172a; transition: transform 0.1s;
}
.vid-mini:active { transform: translateY(3px); box-shadow: none; }
.vid-mini__thumb { position: relative; aspect-ratio: 16/9; background: #000; }
.vid-mini__thumb img { width: 100%; height: 100%; object-fit: cover; opacity: 0.9; }
.vid-mini__badge {
  position: absolute; bottom: 3px; left: 3px;
  background: #16a34a; color: #fff; font-size: 8px; font-weight: 900;
  padding: 2px 5px; border-radius: 6px;
}
.vid-mini__play { position: absolute; inset:0; display:flex; align-items:center; justify-content:center; background:rgba(0,0,0,0.12); }
.vid-mini__play i { font-size: 20px; color: #fff; filter: drop-shadow(0 1px 3px rgba(0,0,0,0.4)); }
.vid-mini__body { padding: 6px; }
.vid-mini__title { font-size: 9px; font-weight: 800; color: #0f172a; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; line-height: 1.3; }
/* Video empty */
.vid-done { background: linear-gradient(135deg, #d1fae5, #a7f3d0); border: 2.5px solid #6ee7b7; border-radius: 18px; padding: 16px; display: flex; align-items: center; gap: 12px; box-shadow: 0 4px 0 #6ee7b7; }

/* ── NOTIFICATIONS ── */
.notif-item { display: flex; align-items: flex-start; gap: 10px; padding: 10px 0; border-bottom: 1.5px dashed #fed7aa; }
.notif-item:last-child { border-bottom: none; padding-bottom: 0; }
.notif-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; margin-top: 3px; border: 2px solid rgba(0,0,0,0.08); }
.notif-body { flex: 1; min-width: 0; }
.notif-title { font-size: 11px; font-weight: 900; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.notif-msg { font-size: 10px; color: #64748b; font-weight: 700; margin-top: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* ── ACTIVITY ── */
.act-item { display: flex; align-items: center; gap: 10px; padding: 9px 0; border-bottom: 1.5px dashed #fed7aa; }
.act-item:last-child { border-bottom: none; padding-bottom: 0; }
.act-ico { width: 38px; height: 38px; background: linear-gradient(135deg, #fef3c7, #fde68a); border: 2px solid #fbbf24; border-radius: 13px; display: flex; align-items: center; justify-content: center; font-size: 17px; color: #92400e; flex-shrink: 0; box-shadow: 0 3px 0 #d97706; }
.act-txt { flex: 1; min-width: 0; }
.act-title { font-size: 11px; font-weight: 800; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.act-date { font-size: 10px; color: #94a3b8; font-weight: 700; }
.act-amt { font-size: 13px; font-weight: 900; color: #059669; white-space: nowrap; }

/* ══ MEMBERSHIP — HORIZONTAL SCROLL CARDS ══ */
.m-scroll {
  display: flex; gap: 10px; overflow-x: auto;
  scroll-snap-type: x mandatory; scrollbar-width: none;
  margin: 0 -14px; padding: 4px 14px 10px;
}
.m-scroll::-webkit-scrollbar { display: none; }
.m-card {
  flex: 0 0 200px; scroll-snap-align: start;
  background: #fff;
  border-radius: 22px; padding: 14px;
  position: relative; text-decoration: none;
  display: flex; flex-direction: column;
  transition: transform 0.1s;
  border: 3px solid #0f172a;
  box-shadow: 0 6px 0 #0f172a;
}
.m-card:active { transform: translateY(4px); box-shadow: 0 2px 0 #0f172a; }
.m-card--0 { border-color: #64748b; box-shadow: 0 6px 0 #475569; background: linear-gradient(160deg, #f8fafc, #e2e8f0); }
.m-card--1 { border-color: #0ea5e9; box-shadow: 0 6px 0 #0369a1; background: linear-gradient(160deg, #f0f9ff, #dbeafe); }
.m-card--2 { border-color: #f59e0b; box-shadow: 0 6px 0 #d97706; background: linear-gradient(160deg, #fefce8, #fde68a); }
.m-card--3 { border-color: #8b5cf6; box-shadow: 0 6px 0 #6d28d9; background: linear-gradient(160deg, #faf5ff, #ede9fe); }
.m-card--4 { border-color: #ef4444; box-shadow: 0 6px 0 #b91c1c; background: linear-gradient(160deg, #fef2f2, #fecaca); }
.m-badge-hot { position: absolute; top:-10px; right:-6px; background: linear-gradient(135deg,#ef4444,#b91c1c); color:#fff; font-size:8px; font-weight:900; padding:3px 8px; border-radius:12px; border:2px solid #fff; box-shadow:0 2px 0 #7f1d1d; transform:rotate(4deg); z-index:2; }
.m-badge-promo { position: absolute; top:-10px; left:-6px; background: linear-gradient(135deg,#22c55e,#16a34a); color:#fff; font-size:8px; font-weight:900; padding:3px 8px; border-radius:12px; border:2px solid #fff; box-shadow:0 2px 0 #15803d; transform:rotate(-4deg); z-index:2; }
.m-ico { width: 40px; height: 40px; border-radius: 14px; border: 2px solid; display: flex; align-items: center; justify-content: center; font-size: 22px; box-shadow: 0 3px 0 rgba(0,0,0,0.1); margin-bottom: 8px; }
.m-name { font-size: 13px; font-weight: 900; line-height: 1.1; margin-bottom: 2px; }
.m-dur { font-size: 9px; font-weight: 800; color: #64748b; display: flex; align-items: center; gap: 3px; margin-bottom: 8px; }
.m-divider { height: 1.5px; background: rgba(0,0,0,0.08); border-radius: 2px; margin-bottom: 8px; }
.m-price-old { font-size: 9px; font-weight: 800; color: #94a3b8; text-decoration: line-through; margin-bottom: 1px; }
.m-price { font-size: 18px; font-weight: 900; margin-bottom: 8px; }
.m-specs { display: flex; flex-direction: column; gap: 4px; font-size: 9px; font-weight: 800; color: #475569; margin-top: auto; }
.m-spec-row { display: flex; align-items: center; gap: 4px; }
.m-cta { display: flex; align-items: center; justify-content: center; gap: 4px; margin-top: 10px; padding: 7px 0; border-radius: 12px; font-size: 10px; font-weight: 900; color: #fff; background: linear-gradient(135deg, #f97316, #ea580c); border: 2px solid rgba(255,255,255,0.3); box-shadow: 0 3px 0 #c2410c; }

/* ── REFERRAL ── */
.ref-row { display: flex; align-items: center; gap: 10px; }
.ref-code { flex: 1; min-width: 0; }
.ref-code__lbl { font-size: 10px; font-weight: 700; color: #64748b; }
.ref-code__val { font-size: 17px; font-weight: 900; color: #0f172a; letter-spacing: 2px; }
.ref-copy-btn {
  background: linear-gradient(135deg, #fbbf24, #f59e0b);
  border: 2.5px solid #d97706; border-radius: 14px; padding: 8px 14px;
  font-size: 11px; font-weight: 900; color: #78350f;
  cursor: pointer; display: flex; align-items: center; gap: 5px;
  flex-shrink: 0; box-shadow: 0 4px 0 #b45309;
  transition: transform 0.1s; font-family: 'Nunito', sans-serif;
}
.ref-copy-btn:active { transform: translateY(3px); box-shadow: none; }
#ref-toast { text-align:center; font-size:11px; font-weight:800; color:#065f46; margin-top:8px; padding:8px; background: linear-gradient(135deg,#d1fae5,#a7f3d0); border:2px solid #6ee7b7; border-radius:12px; box-shadow:0 3px 0 #6ee7b7; }

/* ── LIMIT WARN / NEWCOMER ── */
.info-bar {
  display: flex; align-items: center; gap: 8px;
  background: rgba(255,255,255,0.18); border: 2px solid rgba(255,255,255,0.32);
  border-radius: 14px; padding: 10px 12px; margin-bottom: 12px;
  font-size: 11px; font-weight: 800; color: #fff;
}
.info-bar a { color: #fde68a; font-weight: 900; text-decoration: none; }

/* Threads Campaign Banner Card Styling */
.cg-card--threads {
  background: linear-gradient(135deg, #db2777 0%, #7c3aed 50%, #2563eb 100%) !important;
  border: 3px solid #701a75 !important;
  box-shadow: 0 6px 0 #4c0519 !important;
  border-radius: 22px;
  padding: 14px;
  position: relative;
  overflow: hidden;
  transition: transform 0.1s;
  animation: pulse-threads 3s ease infinite;
}
.cg-card--threads:active {
  transform: translateY(4px);
  box-shadow: 0 2px 0 #4c0519 !important;
}
.cg-card--threads::before {
  content: '🌀';
  position: absolute;
  top: -15px;
  right: -15px;
  font-size: 80px;
  opacity: 0.18;
  pointer-events: none;
  transform: rotate(-15deg);
}
@keyframes pulse-threads {
  0%, 100% { box-shadow: 0 6px 0 #4c0519; }
  50% { box-shadow: 0 6px 0 #4c0519, 0 0 15px rgba(219,39,119,0.5); }
}
.threads-pulse-dot {
  width: 6px; height: 6px;
  background: #22c55e;
  border-radius: 50%;
  animation: dot-pulse 1.5s infinite;
  display: inline-block;
}
@keyframes dot-pulse {
  0%, 100% { transform: scale(1); opacity: 1; }
  50% { transform: scale(1.4); opacity: 0.7; }
}
</style>

<?php if (!empty($_SESSION['flash_home_err'])): ?>
<div class="flash-alert flash-alert--err">
  <i class="ph-bold ph-warning-circle"></i>
  <?= htmlspecialchars($_SESSION['flash_home_err']) ?>
</div>
<?php unset($_SESSION['flash_home_err']); endif; ?>

<!-- ═══════════════════════════════════════════════════ -->
<!--  HERO SECTION                                      -->
<!-- ═══════════════════════════════════════════════════ -->
<div class="hero">
  <!-- Greeting -->
  <div class="hero-greet">
    <div class="hero-greet__left">
      <div class="hero-avatar"><?= strtoupper(substr($user['username'], 0, 1)) ?></div>
      <div>
        <div class="hero-name">Halo, <?= htmlspecialchars($user['username']) ?>! 👋</div>
        <div class="hero-badge">
          <i class="ph-fill ph-star" style="font-size:9px"></i>
          <?= htmlspecialchars($membership_name) ?>
        </div>
      </div>
    </div>
    <a href="<?= $is_guest ? '/login' : '/upgrade' ?>" class="hero-login-btn">
      <i class="ph-bold <?= $is_guest ? 'ph-sign-in' : 'ph-rocket-launch' ?>" style="font-size:13px"></i>
      <?= $is_guest ? 'MASUK' : 'NAIK LEVEL' ?>
    </a>
  </div>

  <!-- Balance + Mascot -->
  <div class="hero-balance">
    <div class="hero-balance__mascot"><img src="/assets/rooster_money.gif" style="width: 60px; height: 60px; object-fit: contain; transform: scale(1.3) translateY(4px);" alt="Mascot"></div>
    <div class="hero-balance__info">
      <div class="hero-balance__label">💎 Total Penghasilan</div>
      <div class="hero-balance__amount"><?= format_rp((float)$user['balance_wd']) ?></div>
      <div class="hero-balance__sub">🛒 Saldo Belanja: <?= format_rp((float)$user['balance_dep']) ?></div>
    </div>
  </div>

  <!-- Action Buttons Row -->
  <div class="hero-btn-row">
    <!-- Tarik Saldo -->
    <a href="/withdraw" class="hero-action-btn hero-action-btn--wd">
      <i class="ph-bold ph-hand-coins"></i> Tarik Saldo
    </a>
    <!-- Topup -->
    <a href="/deposit" class="hero-action-btn hero-action-btn--dep">
      <i class="ph-bold ph-wallet"></i> Topup Saldo
    </a>
  </div>

  <!-- Progress Bar -->
  <?php if (!$is_guest): ?>
  <?php $pct = $watch_limit > 0 ? min(100, round(($watch_today / $watch_limit) * 100)) : 0; ?>
  <div class="hero-progress">
    <div class="hero-progress__hd">
      <div class="hero-progress__lbl">
        <i class="ph-bold ph-video-camera" style="font-size:11px"></i>
        Video Hari Ini
      </div>
      <div class="hero-progress__ct"><?= $watch_today ?>/<?= $watch_limit ?></div>
    </div>
    <div class="hero-progress__track">
      <div class="hero-progress__fill" style="width:<?= $pct ?>%"></div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Wave -->
  <svg class="hero-wave" viewBox="0 0 390 28" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
    <path d="M0 28 C65 8, 130 0, 195 14 C260 28, 325 10, 390 28 Z" fill="#fff8f0"/>
  </svg>
</div>

<!-- LIVECHAT STRIP -->
<?php if (!$is_guest): ?>
<a href="/livechat" class="lc-strip">
  <div class="lc-strip__icon">
    <i class="ph-fill ph-chat-circle-dots"></i>
    <span class="lc-strip__dot"></span>
  </div>
  <div class="lc-strip__text">
    <div class="lc-strip__title">Live Chat Support</div>
    <div class="lc-strip__sub">Ada pertanyaan? Kami siap bantu kamu! 💬</div>
  </div>
  <i class="ph-bold ph-arrow-right lc-strip__arr"></i>
</a>
<style>
.lc-strip {
  display: flex; align-items: center; gap: 12px;
  background: linear-gradient(135deg, #fff7ed 0%, #ffedd5 100%);
  border: 2px solid #fed7aa;
  border-radius: 16px;
  margin: 0 14px 0;
  padding: 12px 14px;
  text-decoration: none;
  box-shadow: 0 3px 0 #fdba74;
  transition: transform 0.12s, box-shadow 0.12s;
  position: relative;
  overflow: hidden;
}
.lc-strip:active { transform: translateY(2px); box-shadow: 0 1px 0 #fdba74; }
.lc-strip::before {
  content: '';
  position: absolute; inset: 0;
  background: linear-gradient(135deg, rgba(249,115,22,0.05) 0%, transparent 60%);
  pointer-events: none;
}
.lc-strip__icon {
  position: relative; flex-shrink: 0;
  width: 42px; height: 42px;
  background: linear-gradient(135deg, #f97316, #ea580c);
  border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  font-size: 22px; color: #fff;
  box-shadow: 0 3px 8px rgba(234,88,12,0.35);
}
.lc-strip__dot {
  position: absolute; top: -2px; right: -2px;
  width: 11px; height: 11px;
  background: #22c55e; border: 2px solid #fff;
  border-radius: 50%;
  animation: lc-pulse 2s infinite;
}
@keyframes lc-pulse {
  0%, 100% { transform: scale(1); opacity: 1; }
  50% { transform: scale(1.3); opacity: 0.8; }
}
.lc-strip__text { flex: 1; min-width: 0; }
.lc-strip__title { font-size: 13px; font-weight: 900; color: #c2410c; font-family: 'Nunito', sans-serif; }
.lc-strip__sub { font-size: 10px; font-weight: 700; color: #78350f; margin-top: 1px; font-family: 'Nunito', sans-serif; }
.lc-strip__arr { font-size: 18px; color: #f97316; flex-shrink: 0; }
</style>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════ -->
<!--  BODY CONTENT                                      -->
<!-- ═══════════════════════════════════════════════════ -->
<div class="home-body">

  <!-- Info bars -->
  <?php if (!$is_guest && $watch_today >= $watch_limit): ?>
  <div class="info-bar">
    <i class="ph-bold ph-warning-circle" style="font-size:18px;flex-shrink:0"></i>
    Kuota nonton hari ini habis!
    <a href="/upgrade">Upgrade →</a>
  </div>
  <?php endif; ?>

  <?php
  $is_newcomer = !$is_guest && (empty($history) || (isset($user['created_at']) && strtotime($user['created_at']) > time() - 3 * 86400) || ($user['balance_wd'] == 0 && $user['balance_dep'] == 0));
  if ($is_newcomer): ?>
  <div class="info-bar info-bar--orange" style="margin-bottom:14px">
    <i class="ph-fill ph-book-open-text" style="font-size:20px;flex-shrink:0;color:#ea580c"></i>
    <div style="flex:1">
      <div style="font-size:11px;font-weight:900;color:#0f172a;">Pemain Baru? Selamat Datang! 🎉</div>
      <div style="font-size:10px;color:#475569;font-weight:700">Baca panduan dapetin reward dulu yuk</div>
    </div>
    <a href="/panduan" style="background:#ea580c;color:#fff;padding:6px 12px;border-radius:20px;font-size:10px;font-weight:900;text-decoration:none;border:2px solid #c2410c;box-shadow:0 3px 0 #9a3412;white-space:nowrap;font-family:'Nunito',sans-serif">Panduan</a>
  </div>
  <?php endif; ?>

  <!-- ── Campaign Banner (Threads) ── -->
  <?php if (setting($pdo, 'threads_campaign_enabled', '1') === '1'): ?>
  <a href="/threads" class="cg-card cg-card--threads" style="display:block; text-decoration:none; margin-bottom:16px;">
    <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; position:relative; z-index:2;">
      <div style="flex:1">
        <div class="threads-event-badge" style="display:inline-flex; align-items:center; gap:6px; background:rgba(255,255,255,0.2); border:1.5px solid rgba(255,255,255,0.4); border-radius:20px; padding:2px 8px; font-size:9px; font-weight:900; color:#fff; text-transform:uppercase; letter-spacing:1px; margin-bottom:6px;">
          <span class="threads-pulse-dot"></span> Event Spesial 🌀
        </div>
        <div style="font-size:15px; font-weight:900; color:#fff; line-height:1.2; margin-bottom:4px; text-shadow: 0 2px 4px rgba(0,0,0,0.35);">Promosi Threads & Raih Cuan!</div>
        <div style="font-size:10px; color:rgba(255,255,255,0.9); font-weight:700; line-height:1.3; text-shadow: 0 1px 2px rgba(0,0,0,0.35);">Bagikan ke Threads, upload screenshot, dapatkan bonus instan!</div>
      </div>
      <div class="threads-badge-price" style="flex-shrink:0; text-align:center; background:#fff; border:2.5px solid #db2777; border-radius:18px; padding:8px 10px; box-shadow:0 4px 0 #701a75; transform:rotate(2deg); transition: transform 0.2s;">
        <div style="font-size:8px; font-weight:900; color:#db2777; text-transform:uppercase; letter-spacing:0.5px; line-height:1.1; margin-bottom:2px;">REWARD</div>
        <div style="font-size:15px; font-weight:900; color:#10b981; line-height:1.1;"><?= format_rp((float)setting($pdo, 'threads_campaign_reward', '5000')) ?></div>
      </div>
    </div>
  </a>
  <?php endif; ?>

  <!-- ── BEE FARM HERO BANNER ── -->
  <a href="/farm" class="cg-card" style="display:block; text-decoration:none; margin-bottom:16px; background:linear-gradient(135deg, #f59e0b 0%, #d97706 60%, #b45309 100%); border:3.5px solid #78350f; border-radius:24px; box-shadow:0 6px 0 #78350f; position:relative; overflow:hidden; padding:16px 14px;">
    <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; position:relative; z-index:2;">
      <div style="flex:1">
        <div style="display:inline-flex; align-items:center; gap:6px; background:rgba(255,255,255,0.25); border:1.5px solid rgba(255,255,255,0.4); border-radius:20px; padding:2px 8px; font-size:9px; font-weight:900; color:#fff; text-transform:uppercase; letter-spacing:0.8px; margin-bottom:6px;">
          <span style="display:inline-block; width:6px; height:6px; background:#fde047; border-radius:50%; box-shadow:0 0 6px #fde047;"></span> FITUR TERNAK LEBAH 🐝
        </div>
        <div style="font-size:16px; font-weight:900; color:#fff; line-height:1.2; margin-bottom:4px; text-shadow: 0 2px 4px rgba(0,0,0,0.35);">Peternakan Lebah Cuan</div>
        <div style="font-size:10px; color:#fef3c7; font-weight:700; line-height:1.3; text-shadow: 0 1px 2px rgba(0,0,0,0.35);">
          Pelihara lebah, panen madu di sarang hexagon 3D, & jual ke lapak untuk raup saldo penarikan!
        </div>
      </div>
      <div style="flex-shrink:0; position:relative;">
        <img src="/assets/game/beehive_royal.png" style="width:72px; height:72px; object-fit:contain; filter:drop-shadow(0 6px 8px rgba(0,0,0,0.3)); transform:rotate(-4deg);" alt="Beehive">
      </div>
    </div>
  </a>

  <!-- ── Bento Quick Actions ── -->
  <div class="sh" style="margin-bottom:10px">
    <div class="sh__title">🎮 Menu Cepat</div>
  </div>
  <div class="bento-grid" style="margin-bottom:16px">

    <!-- BIG: Tonton Video -->
    <?php
    $bento_bg_style = "background:#0f172a;";
    if (!empty($videos)) {
        $rand_vid = $videos[array_rand($videos)];
        $thumb_url = yt_thumb($rand_vid['youtube_id']);
        $bento_bg_style = "background: linear-gradient(to top, rgba(15,23,42,0.95) 0%, rgba(15,23,42,0.2) 100%), url('{$thumb_url}') center/cover;";
    }
    ?>
    <a href="/videos" class="bento-big" style="<?= $bento_bg_style ?> border-color:#1e293b; box-shadow:0 6px 0 #020617;">
      <i class="ph-bold ph-play-circle bento-big__emoji" style="color:rgba(255,255,255,0.1)"></i>
      <i class="ph-fill ph-video-camera bento-big__icon" style="color:#a5b4fc; text-shadow:0 2px 4px rgba(0,0,0,0.5);"></i>
      <div class="bento-big__label" style="text-shadow:0 1px 4px rgba(0,0,0,0.8);">Tonton Video</div>
      <div class="bento-big__sub" style="text-shadow:0 1px 2px rgba(0,0,0,0.8); color:rgba(255,255,255,0.9);">Mulai hasilkan uang!</div>
    </a>

    <!-- SM: Tantangan -->
    <a href="/missions" class="bento-sm"
       style="background:linear-gradient(135deg,#f97316,#ea580c);box-shadow:0 4px 0 #c2410c">
      <i class="ph-fill ph-target"></i>
      <span class="bento-sm__label">Tantangan</span>
    </a>

    <!-- SM: Hadir -->
    <a href="/checkin" class="bento-sm"
       style="background:linear-gradient(135deg,#f472b6,#db2777);box-shadow:0 4px 0 #9d174d">
      <i class="ph-fill ph-calendar-check"></i>
      <span class="bento-sm__label">Absen</span>
    </a>

    <!-- SM: Riwayat -->
    <a href="/history" class="bento-sm"
       style="background:linear-gradient(135deg,#a78bfa,#7c3aed);box-shadow:0 4px 0 #5b21b6">
      <i class="ph-fill ph-receipt"></i>
      <span class="bento-sm__label">Riwayat</span>
    </a>

    <!-- SM: Squad -->
    <a href="/referral" class="bento-sm"
       style="background:linear-gradient(135deg,#34d399,#059669);box-shadow:0 4px 0 #047857">
      <i class="ph-fill ph-users-three"></i>
      <span class="bento-sm__label">Squad</span>
    </a>

    <!-- WIDE: Tukar Poin -->
    <a href="/redeem" class="bento-wide"
       style="background:linear-gradient(135deg,#60a5fa,#1d4ed8);box-shadow:0 4px 0 #1e3a8a">
      <i class="ph-fill ph-gift"></i>
      <div class="bento-wide__txt">
        <div class="bento-wide__label">Tukar Kode</div>
        <div class="bento-wide__sub">Klaim hadiahmu 🎁</div>
      </div>
    </a>



    <?php if (setting($pdo, 'investment_enabled', '1') === '1'): ?>
    <!-- SM: Investasi -->
    <a href="/invest" class="bento-sm"
       style="background:linear-gradient(135deg,#fbbf24,#d97706);box-shadow:0 4px 0 #b45309">
      <i class="ph-fill ph-trend-up"></i>
      <span class="bento-sm__label">Invest</span>
    </a>
    <?php else: ?>
    <!-- SM: Panduan (fallback) -->
    <a href="/panduan" class="bento-sm"
       style="background:linear-gradient(135deg,#6ee7b7,#0891b2);box-shadow:0 4px 0 #0e7490">
      <i class="ph-fill ph-book-open"></i>
      <span class="bento-sm__label">Panduan</span>
    </a>
    <?php endif; ?>

    <!-- SM: Chicky Run -->
    <a href="/chicky" class="bento-sm"
       style="background:linear-gradient(135deg,#fde047,#eab308);box-shadow:0 4px 0 #a16207">
      <i class="ph-fill ph-game-controller"></i>
      <span class="bento-sm__label">Chicky Run</span>
    </a>

    <!-- SM: Ternak Lebah -->
    <a href="/farm" class="bento-sm"
       style="background:linear-gradient(135deg,#f59e0b,#d97706);box-shadow:0 4px 0 #78350f">
      <i class="ph-fill ph-drop" style="color:#fef08a"></i>
      <span class="bento-sm__label">Ternak</span>
    </a>





  </div>



  <!-- ── Notifications ── -->
  <?php if (!empty($notif_preview)):
  $notif_dot_colors = ['info'=>'#0284c7','success'=>'#16a34a','warning'=>'#d97706','alert'=>'#e11d48','congrats'=>'#ca8a04']; ?>
  <div class="cg-card cg-card--orange" style="margin-bottom:14px">
    <div class="sh">
      <div class="sh__title">
        <i class="ph-fill ph-bell-ringing" style="color:#ef4444"></i>
        Inbox
        <?php if ($notif_unread > 0): ?>
          <span style="background:#ef4444;color:#fff;font-size:9px;font-weight:900;padding:1px 7px;border-radius:10px"><?= $notif_unread > 9 ? '9+' : $notif_unread ?></span>
        <?php endif; ?>
      </div>
      <a href="/notifications" class="sh__link">Lihat Semua →</a>
    </div>
    <?php foreach ($notif_preview as $nf):
      $dot_color = $notif_dot_colors[$nf['type']] ?? '#0284c7'; ?>
    <div class="notif-item">
      <div class="notif-dot" style="background:<?= $dot_color ?>"></div>
      <div class="notif-body">
        <div class="notif-title"><?= htmlspecialchars($nf['title']) ?></div>
        <div class="notif-msg"><?= htmlspecialchars($nf['message']) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- ── Videos: Featured + Mini Scroll ── -->
  <?php if (!empty($videos)): ?>
  <?php $vid_featured = $videos[0]; $vid_rest = array_slice($videos, 1); ?>
  <div class="cg-card" style="margin-bottom:14px;padding:12px">
    <div class="sh" style="margin-bottom:10px">
      <div class="sh__title"><i class="ph-fill ph-video-camera" style="color:#7c3aed"></i> Video Reward 🎬</div>
      <a href="/videos" class="sh__link">Semua →</a>
    </div>

    <!-- Featured Video -->
    <a href="/watch?id=<?= $vid_featured['id'] ?>" class="vid-featured">
      <img src="<?= yt_thumb($vid_featured['youtube_id']) ?>" alt="<?= htmlspecialchars($vid_featured['title']) ?>"
           loading="lazy" onerror="this.src='https://img.youtube.com/vi/<?= $vid_featured['youtube_id'] ?>/hqdefault.jpg'">
      <div class="vid-featured__play"><i class="ph-fill ph-play"></i></div>
      <div class="vid-featured__overlay">
        <div class="vid-featured__badge">
          <i class="ph-bold ph-coins"></i> +<?= format_rp((float)$vid_featured['reward_amount']) ?>
        </div>
        <div class="vid-featured__title"><?= htmlspecialchars($vid_featured['title']) ?></div>
        <div class="vid-featured__meta">
          <span><i class="ph-bold ph-clock"></i> <?= $vid_featured['watch_duration'] ?>s</span>
          <span style="background:rgba(255,255,255,0.2);padding:2px 8px;border-radius:10px;font-size:9px">Tonton Sekarang ▶</span>
        </div>
      </div>
    </a>

    <!-- Mini scroll for remaining videos -->
    <?php if (!empty($vid_rest)): ?>
    <div class="vid-mini-scroll" style="margin-top:8px">
      <?php foreach ($vid_rest as $v): ?>
      <a href="/watch?id=<?= $v['id'] ?>" class="vid-mini">
        <div class="vid-mini__thumb">
          <img src="<?= yt_thumb($v['youtube_id']) ?>" alt="<?= htmlspecialchars($v['title']) ?>" loading="lazy"
               onerror="this.src='https://img.youtube.com/vi/<?= $v['youtube_id'] ?>/hqdefault.jpg'">
          <div class="vid-mini__play"><i class="ph-fill ph-play-circle"></i></div>
          <div class="vid-mini__badge">+<?= format_rp((float)$v['reward_amount']) ?></div>
        </div>
        <div class="vid-mini__body">
          <div class="vid-mini__title"><?= htmlspecialchars($v['title']) ?></div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php elseif (!$is_guest): ?>
  <div class="vid-done" style="margin-bottom:14px">
    <i class="ph-fill ph-check-circle" style="font-size:30px;color:#059669;flex-shrink:0"></i>
    <div>
      <div style="font-size:13px;font-weight:900;color:#065f46">Semua video sudah ditonton! 🎉</div>
      <div style="font-size:11px;color:#059669;font-weight:700;margin-top:2px">Video baru datang besok pagi</div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Referral ── -->
  <?php if (!$is_guest): ?>
  <div class="cg-card cg-card--yellow" style="margin-bottom:14px">
    <div class="sh" style="margin-bottom:10px">
      <div class="sh__title" style="color:#78350f">
        <i class="ph-fill ph-share-network" style="color:#d97706"></i>
        Kode Undangan 🔗
      </div>
    </div>
    <div class="ref-row">
      <i class="ph-fill ph-gift" style="font-size:28px;color:#d97706;flex-shrink:0"></i>
      <div class="ref-code">
        <div class="ref-code__lbl">Kode kamu</div>
        <div class="ref-code__val"><?= htmlspecialchars($user['referral_code']) ?></div>
      </div>
      <button type="button" class="ref-copy-btn" onclick="copyRef('<?= htmlspecialchars($user['referral_code']) ?>')">
        <i class="ph-bold ph-copy"></i> Salin
      </button>
    </div>
    <div id="ref-toast" style="display:none">✓ Kode berhasil disalin!</div>
  </div>
  <?php endif; ?>



  <!-- ── Recent Activity ── -->
  <?php if (!empty($history)): ?>
  <div class="cg-card" style="margin-bottom:14px">
    <div class="sh">
      <div class="sh__title"><i class="ph-fill ph-clock-counter-clockwise" style="color:#ea580c"></i> Aktivitas Terbaru ⚡</div>
    </div>
    <?php foreach ($history as $h): ?>
    <div class="act-item">
      <div class="act-ico"><i class="ph-fill ph-monitor-play"></i></div>
      <div class="act-txt">
        <div class="act-title"><?= htmlspecialchars($h['title']) ?></div>
        <div class="act-date"><i class="ph-bold ph-calendar-blank" style="font-size:9px"></i> <?= date('d M H:i', strtotime($h['watched_at'])) ?></div>
      </div>
      <div class="act-amt">+<?= format_rp((float)$h['reward_given']) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div><!-- /home-body -->

<!-- ── Popup Panduan ── -->
<?php
$popup_enabled     = setting($pdo, 'popup_enabled', '1') === '1';
$popup_title       = setting($pdo, 'popup_title', 'Hei, sudah baca panduan?');
$popup_body        = setting($pdo, 'popup_body', 'Biar makin lancar dapat reward, yuk baca dulu cara kerja TontonCuan!');
$popup_cta_text    = setting($pdo, 'popup_cta_text', 'Baca Panduan');
$popup_cta_url     = setting($pdo, 'popup_cta_url', '/panduan');
$popup_delay       = max(0, (int) setting($pdo, 'popup_delay', '1500'));
$popup_reset_hours = max(0, (int) setting($pdo, 'popup_reset_hours', '0'));
?>
<?php if ($popup_enabled): ?>
<div id="guide-popup" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.65);backdrop-filter:blur(4px);z-index:100000;align-items:center;justify-content:center;padding:20px;">
  <div style="background:#fff;border-radius:28px;padding:24px 20px 20px;max-width:320px;width:100%;transform:scale(0.8);opacity:0;transition:all 0.4s cubic-bezier(0.175,0.885,0.32,1.275);position:relative;border:4px solid #f97316;box-shadow:0 10px 0 #ea580c,0 18px 36px rgba(0,0,0,0.3);">
    <button onclick="closePopup()" style="position:absolute;top:-14px;right:-14px;background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;border:3px solid #fff;width:38px;height:38px;border-radius:50%;font-size:16px;font-weight:900;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 4px 0 #b91c1c;">
      <i class="ph-bold ph-x"></i>
    </button>
    <div style="width:68px;height:68px;background:linear-gradient(135deg,#fbbf24,#f97316);border:3px solid #ea580c;box-shadow:0 5px 0 #c2410c;border-radius:22px;display:flex;align-items:center;justify-content:center;font-size:34px;margin:-50px auto 16px;">📖</div>
    <h3 style="font-size:18px;font-weight:900;text-align:center;margin:0 0 8px;color:#0f172a;line-height:1.2;font-family:'Nunito',sans-serif"><?= htmlspecialchars($popup_title) ?></h3>
    <p style="font-size:13px;line-height:1.5;color:#475569;font-weight:700;text-align:center;margin:0 0 20px;font-family:'Nunito',sans-serif"><?= nl2br(htmlspecialchars($popup_body)) ?></p>
    <div style="display:flex;flex-direction:column;gap:8px;">
      <a href="<?= htmlspecialchars($popup_cta_url) ?>" style="display:flex;align-items:center;justify-content:center;gap:8px;width:100%;font-size:14px;font-weight:900;padding:14px;border-radius:18px;background:linear-gradient(135deg,#f97316,#ea580c);border:3px solid #fde68a;box-shadow:0 6px 0 #c2410c;color:#fff;text-decoration:none;font-family:'Nunito',sans-serif">
        <i class="ph-bold ph-book-bookmark"></i> <?= htmlspecialchars($popup_cta_text) ?>
      </a>
      <button type="button" onclick="closePopup()" style="width:100%;padding:10px;background:transparent;border:none;font-size:12px;font-weight:800;color:#94a3b8;cursor:pointer;font-family:'Nunito',sans-serif">Nanti Saja</button>
    </div>
  </div>
</div>
<script>
function closePopup() {
  const p = document.getElementById('guide-popup');
  const c = p.querySelector('div');
  c.style.transform = 'scale(0.8)'; c.style.opacity = '0';
  setTimeout(() => p.style.display = 'none', 300);
  try { localStorage.setItem('tonton_popup_seen', JSON.stringify({ts: Date.now()})); } catch(e){}
}
document.addEventListener('DOMContentLoaded', () => {
  const p = document.getElementById('guide-popup');
  if(!p) return;
  const c = p.querySelector('div');
  const resetMs = <?= $popup_reset_hours ?> * 3600000;
  try {
    const raw = localStorage.getItem('tonton_popup_seen');
    if (raw) {
      const data = JSON.parse(raw);
      if (resetMs <= 0 || (Date.now() - data.ts) < resetMs) return;
    }
  } catch(e){}
  setTimeout(() => {
    p.style.display = 'flex'; p.offsetHeight;
    c.style.transform = 'scale(1)'; c.style.opacity = '1';
  }, <?= $popup_delay ?>);
});
</script>
<?php endif; ?>

<script>
function copyRef(code) {
  navigator.clipboard.writeText(code).then(() => {
    const t = document.getElementById('ref-toast');
    t.style.display = 'block';
    setTimeout(() => t.style.display = 'none', 2000);
  }).catch(() => {});
}
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
