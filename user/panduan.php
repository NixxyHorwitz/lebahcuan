<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$pageTitle  = 'Panduan';
$activePage = 'panduan';

$free_limit  = (int)   setting($pdo, 'free_watch_limit', '7');
$min_wd      = (float) setting($pdo, 'min_withdraw', '50000');
$ref_bonus   = (float) setting($pdo, 'referral_bonus', '1000');

$panduan_intro    = setting($pdo, 'panduan_intro',      'Cara kerja platform peternakan lebah madu & reward LebahCuan');
$panduan_step1    = setting($pdo, 'panduan_step1',      'Buat akun gratis, tidak perlu verifikasi ribet. Langsung bisa mulai tonton.');
$panduan_step2    = setting($pdo, 'panduan_step2',      'Setiap video yang ditonton hingga selesai akan otomatis memberikan reward ke Saldo Penarikan kamu.');
$panduan_step3    = setting($pdo, 'panduan_step3',      'Reward terkumpul di Saldo Penarikan. Cek progresmu di halaman Beranda kapan saja.');
$panduan_step4    = setting($pdo, 'panduan_step4',      'Minimal withdraw ' . format_rp($min_wd) . '. Proses cepat ke rekening/e-wallet pilihanmu.');
$panduan_faq      = setting($pdo, 'panduan_faq_custom', '');
$panduan_cta_text = setting($pdo, 'panduan_cta_text',   'Mulai Tonton Sekarang');
$panduan_cta_url  = setting($pdo, 'panduan_cta_url',    '/videos');

try {
    $memberships = $pdo->query("SELECT name, price, watch_limit, duration_days, description FROM memberships WHERE is_active=1 ORDER BY sort_order ASC")->fetchAll();
} catch (\Throwable) { $memberships = []; }

$mem_colors = ['#e0f2fe','#d1fae5','#fef08a','#fce7f3','#ede9fe'];
$mem_borders = ['#3b82f6','#059669','#d97706','#db2777','#7c3aed'];
$mem_icons  = ['🆓','🌱','⚡','🗡️','💎'];

require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* PANDUAN PAGE - CASUAL GAME UI (ORANGE) matching withdraw.php */
html body { background: #f97316 !important; background-image: none !important; }

/* BLUE TOP BANNER */
.pan-top {
  background: #38bdf8;
  background-image: linear-gradient(rgba(255,255,255,0.1) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.1) 1px, transparent 1px);
  background-size: 40px 20px;
  background-position: 0 0, 20px 10px;
  position: relative;
  padding: 16px 14px 40px;
  border-bottom: 3px solid #0284c7;
  text-align: center;
}
.pan-title { font-size: 20px; font-weight: 900; color: #fff; text-shadow: 0 3px 0 #0284c7; display: flex; align-items: center; justify-content: center; gap: 8px; margin-bottom: 8px; }
.pan-desc { font-size: 11px; font-weight: 800; color: #e0f2fe; max-width: 300px; margin: 0 auto; line-height: 1.3; }

/* BODY */
.pan-body { flex: 1; background: #f97316; padding: 20px 14px calc(var(--nav-h) + 24px); position: relative; z-index: 2; margin-top: -20px; }
.pan-body::before { content: ''; position: absolute; inset: 0; background: radial-gradient(circle, rgba(255,255,255,0.08) 10%, transparent 10%), radial-gradient(circle, rgba(255,255,255,0.08) 10%, transparent 10%); background-size: 40px 40px; background-position: 0 0, 20px 20px; pointer-events: none; z-index: -1; }

/* C-GROUP (White Box) */
.c-group { background: #ffffff; border: 2.5px solid #1e3a8a; border-radius: 14px; box-shadow: 0 4px 0 #1e3a8a; overflow: hidden; margin-bottom: 16px; }
.c-hdr { background: linear-gradient(135deg, #fbbf24, #f59e0b); padding: 10px 12px; font-size: 14px; font-weight: 900; color: #78350f; border-bottom: 2.5px solid #d97706; display: flex; align-items: center; gap: 8px; }
.c-hdr i { font-size: 18px; color: #b45309; }
.c-hdr--blue { background: linear-gradient(135deg, #60a5fa, #3b82f6); color: #fff; border-bottom-color: #1d4ed8; text-shadow: 0 2px 0 #1e40af; }
.c-hdr--blue i { color: #bfdbfe; }
.c-hdr--green { background: linear-gradient(135deg, #34d399, #10b981); color: #fff; border-bottom-color: #047857; text-shadow: 0 2px 0 #064e3b; }
.c-hdr--green i { color: #a7f3d0; }
.c-body { padding: 12px; background: #fff; }

/* STEPS */
.p-step { display: flex; gap: 10px; margin-bottom: 10px; align-items: flex-start; }
.p-step:last-child { margin-bottom: 0; }
.p-step-n { width: 22px; height: 22px; border-radius: 8px; background: #3b82f6; color: #fff; font-size: 11px; font-weight: 900; display: flex; align-items: center; justify-content: center; flex-shrink: 0; border: 2px solid #1e3a8a; box-shadow: 0 2px 0 #1e3a8a; }
.p-step-t { font-size: 11px; font-weight: 800; color: #334155; line-height: 1.4; padding-top: 2px; }

/* TIPS */
.p-tip { display: flex; gap: 8px; background: #f8fafc; border: 2px solid #e2e8f0; padding: 8px; border-radius: 10px; margin-bottom: 8px; align-items: center; }
.p-tip:last-child { margin-bottom: 0; }
.p-tip i { font-size: 20px; flex-shrink: 0; }
.p-tip-t { font-size: 11px; font-weight: 800; color: #475569; line-height: 1.3; }
.p-tip-t strong { color: #0f172a; font-weight: 900; }

/* MEMBERSHIP COMPACT */
.mem-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; }
.mem-c { background: #fff; border: 2px solid #e2e8f0; border-radius: 10px; padding: 8px; display: flex; flex-direction: column; gap: 4px; box-shadow: 0 3px 0 #e2e8f0; }
.mem-c-head { display: flex; justify-content: space-between; align-items: center; }
.mem-c-name { font-size: 11px; font-weight: 900; display: flex; align-items: center; gap: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.mem-c-price { font-size: 9px; font-weight: 900; color: #fff; background: #ef4444; padding: 2px 6px; border-radius: 6px; border: 1.5px solid #991b1b; box-shadow: 0 2px 0 #991b1b; }
.mem-c-price.free { background: #10b981; border-color: #047857; box-shadow: 0 2px 0 #047857; }
.mem-c-stat { font-size: 9px; font-weight: 800; color: #64748b; display: flex; align-items: center; gap: 4px; }

/* FAQ COMPACT */
.faq-i { border: 2px solid #cbd5e1; border-radius: 10px; margin-bottom: 8px; background: #f8fafc; overflow: hidden; }
.faq-i:last-child { margin-bottom: 0; }
.faq-q { padding: 10px; font-size: 11px; font-weight: 900; color: #1e293b; display: flex; justify-content: space-between; align-items: center; cursor: pointer; }
.faq-q::after { content: '\25BC'; font-size: 9px; color: #94a3b8; transition: transform 0.2s; }
.faq-a { padding: 0 10px; font-size: 11px; font-weight: 800; color: #475569; line-height: 1.4; max-height: 0; opacity: 0; transition: all 0.2s; }
.faq-i.open .faq-q::after { transform: rotate(180deg); color: #3b82f6; }
.faq-i.open .faq-q { border-bottom: 2px dashed #cbd5e1; background: #fff; color: #3b82f6; }
.faq-i.open .faq-a { padding: 10px; max-height: 500px; opacity: 1; background: #fff; }

/* CTA */
.pan-cta { background: linear-gradient(135deg, #10b981, #059669); border: 3px solid #fff; border-radius: 16px; box-shadow: 0 5px 0 #047857; color: #fff; display: flex; align-items: center; justify-content: center; gap: 8px; padding: 14px; font-size: 16px; font-weight: 900; text-decoration: none; transition: transform 0.1s; }
.pan-cta:active { transform: translateY(4px); box-shadow: 0 1px 0 #047857; }
</style>

<div class="pan-top">
  <div class="pan-title"><i class="ph-bold ph-book-open"></i> Panduan LebahCuan</div>
  <div class="pan-desc"><?= htmlspecialchars($panduan_intro) ?></div>
</div>

<div class="pan-body">

  <!-- CARA KERJA -->
  <div class="c-group">
    <div class="c-hdr c-hdr--blue"><i class="ph-bold ph-info"></i> Cara Kerja</div>
    <div class="c-body">
      <div class="p-step">
        <div class="p-step-n">1</div>
        <div class="p-step-t"><?= htmlspecialchars($panduan_step1) ?></div>
      </div>
      <div class="p-step">
        <div class="p-step-n">2</div>
        <div class="p-step-t"><?= htmlspecialchars($panduan_step2) ?></div>
      </div>
      <div class="p-step">
        <div class="p-step-n">3</div>
        <div class="p-step-t"><?= htmlspecialchars($panduan_step3) ?></div>
      </div>
      <div class="p-step">
        <div class="p-step-n">4</div>
        <div class="p-step-t"><?= htmlspecialchars($panduan_step4) ?></div>
      </div>
    </div>
  </div>

  <!-- TENTANG SALDO -->
  <div class="c-group">
    <div class="c-hdr c-hdr--green"><i class="ph-bold ph-wallet"></i> Tentang Saldo</div>
    <div class="c-body">
      <div class="p-tip">
        <i class="ph-fill ph-hand-coins" style="color: #059669;"></i>
        <div class="p-tip-t"><strong>Saldo Tarik (WD):</strong> Berasal dari reward video & misi. Bisa dicairkan ke rekening/e-wallet.</div>
      </div>
      <div class="p-tip">
        <i class="ph-fill ph-credit-card" style="color: #2563eb;"></i>
        <div class="p-tip-t"><strong>Saldo Beli:</strong> Diisi via deposit. Hanya untuk upgrade paket membership (tidak bisa ditarik).</div>
      </div>
    </div>
  </div>

  <!-- MEMBERSHIP -->
  <div class="c-group">
    <div class="c-hdr"><i class="ph-bold ph-crown"></i> Paket Membership</div>
    <div class="c-body">
      <div style="font-size:11px; font-weight:800; color:#64748b; margin-bottom:12px; text-align:center;">Upgrade paket untuk limit tonton lebih tinggi!</div>
      
      <?php if (!empty($memberships)): ?>
      <div class="mem-grid">
        <?php foreach ($memberships as $i => $mem):
          $color = $mem_colors[$i % count($mem_colors)];
          $border = $mem_borders[$i % count($mem_borders)];
          $icon  = $mem_icons[$i % count($mem_icons)];
          $isFree = (float)$mem['price'] === 0.0;
        ?>
        <div class="mem-c" style="border-color: <?= $border ?>;">
          <div class="mem-c-head">
            <div class="mem-c-name" style="color: <?= $border ?>;"><?= $icon ?> <?= htmlspecialchars($mem['name']) ?></div>
            <div class="mem-c-price <?= $isFree ? 'free' : '' ?>"><?= $isFree ? 'GRATIS' : format_rp((float)$mem['price']) ?></div>
          </div>
          <div class="mem-c-stat"><i class="ph-fill ph-video-camera"></i> <?= $mem['watch_limit'] ?> video/hari</div>
          <?php if (!$isFree): ?>
          <div class="mem-c-stat"><i class="ph-fill ph-calendar"></i> Aktif <?= $mem['duration_days'] ?> hari</div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <a href="/upgrade" style="display:block; text-align:center; font-size:12px; font-weight:900; color:#ea580c; margin-top:12px; text-decoration:none;">Beli Paket Sekarang <i class="ph-bold ph-arrow-right"></i></a>
    </div>
  </div>

  <!-- REFERRAL -->
  <div class="c-group">
    <div class="c-hdr"><i class="ph-bold ph-users-three"></i> Program Referral</div>
    <div class="c-body">
      <div class="p-tip">
        <i class="ph-fill ph-gift" style="color: #ea580c;"></i>
        <div class="p-tip-t">Ajak teman pakai kode referralmu dan dapatkan <strong><?= format_rp($ref_bonus) ?></strong> per teman. Plus nikmati komisi berlapis hingga 3 level!</div>
      </div>
      <a href="/referral" style="display:block; text-align:center; font-size:12px; font-weight:900; color:#ea580c; margin-top:10px; text-decoration:none;">Bagikan Kodemu <i class="ph-bold ph-arrow-right"></i></a>
    </div>
  </div>

  <!-- FAQ -->
  <div class="c-group">
    <div class="c-hdr c-hdr--blue"><i class="ph-bold ph-question"></i> Pertanyaan Umum</div>
    <div class="c-body">
      <div class="faq-i">
        <div class="faq-q">Kapan reward masuk ke saldo?</div>
        <div class="faq-a">Otomatis masuk setelah video selesai ditonton sesuai durasi minimum.</div>
      </div>
      <div class="faq-i">
        <div class="faq-q">Apakah bisa skip video?</div>
        <div class="faq-a">Tidak bisa. Reward hanya diberikan jika ditonton tanpa skip.</div>
      </div>
      <div class="faq-i">
        <div class="faq-q">Apakah daftar gratis?</div>
        <div class="faq-a">Ya! Daftar gratis. Deposit hanya diperlukan jika ingin upgrade paket.</div>
      </div>
      <div class="faq-i">
        <div class="faq-q">Withdraw ke mana saja?</div>
        <div class="faq-a">Ke semua bank & e-wallet Indonesia. Min penarikan <?= format_rp($min_wd) ?>.</div>
      </div>
      <?php if ($panduan_faq): ?>
      <div class="faq-i">
        <div class="faq-q">Informasi tambahan</div>
        <div class="faq-a"><?= nl2br(htmlspecialchars($panduan_faq)) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <a href="<?= htmlspecialchars($panduan_cta_url) ?>" class="pan-cta">
    <i class="ph-fill ph-play-circle" style="font-size:22px;"></i> <?= htmlspecialchars($panduan_cta_text) ?>
  </a>

</div>

<script>
document.querySelectorAll('.faq-q').forEach(q => {
  q.addEventListener('click', () => {
    const item = q.closest('.faq-i');
    const wasOpen = item.classList.contains('open');
    document.querySelectorAll('.faq-i').forEach(i => i.classList.remove('open'));
    if (!wasOpen) item.classList.add('open');
  });
});
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
