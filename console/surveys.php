<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

if (!staff_can('surveys') && !staff_can('analytics') && !staff_can('users')) {
    staff_require('surveys');
}

$flash = '';
$flashType = '';

// Handle Delete Response (Opsional)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $delId = (int)$_POST['delete_id'];
    if ($delId > 0) {
        $delStmt = $pdo->prepare("DELETE FROM user_surveys WHERE id = ?");
        $delStmt->execute([$delId]);
        $flash = "Data respon survei #{$delId} berhasil dihapus.";
        $flashType = "success";
    }
}

// Handle Export CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=laporan_survei_lebahcuan_' . date('Ymd_His') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Waktu Submit (WIB)', 'User ID', 'Username', 'Email', 'WhatsApp', 'Sumber Info', 'Rating Kepuasan (1-5)', 'Pengalaman Pengguna', 'Pesan / Keluhan', 'Hadiah Saldo (Rp)', 'IP Address']);

    $qExp = $pdo->query("
        SELECT s.*, u.username, u.email, u.whatsapp 
        FROM user_surveys s 
        LEFT JOIN users u ON u.id = s.user_id 
        ORDER BY s.id DESC
    ");
    while ($r = $qExp->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $r['id'],
            $r['created_at'],
            $r['user_id'],
            $r['username'] ?? 'User Dihapus',
            $r['email'] ?? '-',
            $r['whatsapp'] ?? '-',
            $r['source_info'],
            $r['satisfaction_rating'],
            $r['experience'],
            $r['feedback_message'],
            $r['reward_amount'],
            $r['ip_address'] ?? '-'
        ]);
    }
    fclose($output);
    exit;
}

// ── Ringkasan Statistik ──
$totalRespondents = (int)$pdo->query("SELECT COUNT(*) FROM user_surveys")->fetchColumn();
$totalRewards     = (float)$pdo->query("SELECT COALESCE(SUM(reward_amount), 0) FROM user_surveys")->fetchColumn();
$avgRating        = (float)$pdo->query("SELECT COALESCE(AVG(satisfaction_rating), 0) FROM user_surveys")->fetchColumn();
$positiveCount    = (int)$pdo->query("SELECT COUNT(*) FROM user_surveys WHERE satisfaction_rating >= 4")->fetchColumn();
$csatPct          = $totalRespondents > 0 ? round(($positiveCount / $totalRespondents) * 100, 1) : 0;

// Distribusi Rating (1 - 5)
$ratingCounts = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
$qRating = $pdo->query("SELECT satisfaction_rating, COUNT(*) as cnt FROM user_surveys GROUP BY satisfaction_rating")->fetchAll(PDO::FETCH_KEY_PAIR);
foreach ($ratingCounts as $star => $v) {
    if (isset($qRating[$star])) {
        $ratingCounts[$star] = (int)$qRating[$star];
    }
}

// Distribusi Sumber Info
$sourceBreakdown = [
    'Threads'   => 0,
    'Facebook'  => 0,
    'Instagram' => 0,
    'Google'    => 0,
    'Teman'     => 0,
    'Lainnya'   => 0,
];
$allSurveysRaw = $pdo->query("SELECT source_info FROM user_surveys")->fetchAll(PDO::FETCH_COLUMN);
foreach ($allSurveysRaw as $src) {
    $srcLower = strtolower(trim((string)$src));
    if (str_contains($srcLower, 'threads')) {
        $sourceBreakdown['Threads']++;
    } elseif (str_contains($srcLower, 'facebook') || str_contains($srcLower, 'fb')) {
        $sourceBreakdown['Facebook']++;
    } elseif (str_contains($srcLower, 'instagram') || str_contains($srcLower, 'ig')) {
        $sourceBreakdown['Instagram']++;
    } elseif (str_contains($srcLower, 'google')) {
        $sourceBreakdown['Google']++;
    } elseif (str_contains($srcLower, 'teman') || str_contains($srcLower, 'kawan') || str_contains($srcLower, 'rekan')) {
        $sourceBreakdown['Teman']++;
    } else {
        $sourceBreakdown['Lainnya']++;
    }
}

// Ambil Seluruh Data Respon Survei
$surveysList = $pdo->query("
    SELECT s.*, u.username, u.email, u.whatsapp 
    FROM user_surveys s 
    LEFT JOIN users u ON u.id = s.user_id 
    ORDER BY s.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$activePage = 'surveys';
$pageTitle  = 'Hasil Survei Pengguna';
require_once __DIR__ . '/partials/header.php';
?>

<div class="c-content">

  <!-- Top Title & Action Bar -->
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
    <div>
      <h1 class="h4 fw-bold text-white mb-1 d-flex align-items-center gap-2">
        <i class="ph-bold ph-clipboard-text text-warning"></i> Hasil Survei Pengguna
        <span class="badge bg-warning text-dark fs-6 ms-2"><?= $totalRespondents ?> Respon</span>
      </h1>
      <div class="text-secondary small">
        Rekap data survei kepuasan, sumber lalu lintas pengunjung, serta kritik dan saran pengguna dengan reward Rp 15.000 saldo tarik.
      </div>
    </div>
    
    <div class="d-flex align-items-center gap-2">
      <a href="/console/surveys.php?export=csv" class="btn btn-outline-warning btn-sm d-flex align-items-center gap-2 px-3 py-2 fw-bold" style="border-radius:10px;">
        <i class="ph-bold ph-download-simple"></i> Export CSV
      </a>
      <a href="/survey" target="_blank" class="btn btn-secondary btn-sm d-flex align-items-center gap-2 px-3 py-2 fw-bold" style="border-radius:10px;">
        <i class="ph-bold ph-arrow-square-out"></i> Buka Halaman Survei User
      </a>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flashType) ?> d-flex align-items-center gap-2 mb-4" style="border-radius:12px;font-size:13.5px;">
      <i class="ph-bold <?= $flashType==='success'?'ph-check-circle':'ph-warning-circle' ?>"></i>
      <span><?= htmlspecialchars($flash) ?></span>
    </div>
  <?php endif; ?>

  <!-- ── 4 STAT CARDS ── -->
  <div class="row g-3 mb-4">
    
    <!-- 1. Total Responden -->
    <div class="col-6 col-lg-3">
      <div class="c-stat">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <span class="c-stat__lbl">Total Responden</span>
          <div class="c-stat__icon" style="background:rgba(59,130,246,0.15);color:#3b82f6;">
            <i class="ph-bold ph-users fs-4"></i>
          </div>
        </div>
        <div class="c-stat__val text-info"><?= number_format($totalRespondents) ?></div>
        <div class="text-secondary small mt-1">Pengguna unik mengisi survei</div>
      </div>
    </div>

    <!-- 2. Total Reward Dibagikan -->
    <div class="col-6 col-lg-3">
      <div class="c-stat">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <span class="c-stat__lbl">Total Reward Saldo</span>
          <div class="c-stat__icon" style="background:rgba(245,158,11,0.15);color:#f59e0b;">
            <i class="ph-bold ph-coins fs-4"></i>
          </div>
        </div>
        <div class="c-stat__val text-warning">Rp <?= number_format($totalRewards, 0, ',', '.') ?></div>
        <div class="text-secondary small mt-1">@Rp 15.000 saldo tarik terkredit</div>
      </div>
    </div>

    <!-- 3. Rata-Rata Rating -->
    <div class="col-6 col-lg-3">
      <div class="c-stat">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <span class="c-stat__lbl">Rata-Rata Rating</span>
          <div class="c-stat__icon" style="background:rgba(234,179,8,0.15);color:#eab308;">
            <i class="ph-bold ph-star fs-4"></i>
          </div>
        </div>
        <div class="c-stat__val text-white d-flex align-items-baseline gap-2">
          <span><?= number_format($avgRating, 1) ?></span>
          <span style="font-size:14px;color:#94a3b8;font-weight:normal;">/ 5.0</span>
        </div>
        <div class="text-secondary small mt-1">
          <?php for($i=1; $i<=5; $i++): ?>
            <i class="ph-fill ph-star" style="color: <?= $i <= round($avgRating) ? '#f59e0b' : '#334155' ?>; font-size:12px;"></i>
          <?php endfor; ?>
        </div>
      </div>
    </div>

    <!-- 4. Kepuasan Pelanggan (CSAT) -->
    <div class="col-6 col-lg-3">
      <div class="c-stat">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <span class="c-stat__lbl">Tingkat Kepuasan (CSAT)</span>
          <div class="c-stat__icon" style="background:rgba(16,185,129,0.15);color:#10b981;">
            <i class="ph-bold ph-smiley fs-4"></i>
          </div>
        </div>
        <div class="c-stat__val text-success"><?= $csatPct ?>%</div>
        <div class="text-secondary small mt-1">Memberi rating 4 atau 5 (Puas)</div>
      </div>
    </div>

  </div>

  <!-- ── 2 COLUMN ANALYTICS BREAKDOWN ── -->
  <div class="row g-3 mb-4">
    
    <!-- Kolom Kiri: Sumber Informasi -->
    <div class="col-12 col-lg-6">
      <div class="c-card h-100">
        <div class="c-card-header d-flex align-items-center justify-content-between">
          <span class="c-card-title d-flex align-items-center gap-2">
            <i class="ph-bold ph-share-network text-warning"></i> Dari Mana User Tahu LebahCuan?
          </span>
          <span class="badge bg-dark border border-secondary text-secondary small">Traffic Sources</span>
        </div>
        <div class="c-card-body p-3">
          
          <?php
            $sourceIcons = [
              'Threads'   => ['icon' => 'ph-at', 'color' => '#f59e0b', 'bg' => 'rgba(245,158,11,0.15)'],
              'Facebook'  => ['icon' => 'ph-facebook-logo', 'color' => '#3b82f6', 'bg' => 'rgba(59,130,246,0.15)'],
              'Instagram' => ['icon' => 'ph-instagram-logo', 'color' => '#ec4899', 'bg' => 'rgba(236,72,153,0.15)'],
              'Google'    => ['icon' => 'ph-google-logo', 'color' => '#10b981', 'bg' => 'rgba(16,185,129,0.15)'],
              'Teman'     => ['icon' => 'ph-users-three', 'color' => '#8b5cf6', 'bg' => 'rgba(139,92,246,0.15)'],
              'Lainnya'   => ['icon' => 'ph-pencil-simple-line', 'color' => '#64748b', 'bg' => 'rgba(100,116,139,0.15)']
            ];
          ?>

          <div class="d-flex flex-column gap-3">
            <?php foreach ($sourceBreakdown as $name => $count): 
              $pct = $totalRespondents > 0 ? round(($count / $totalRespondents) * 100, 1) : 0;
              $meta = $sourceIcons[$name] ?? ['icon' => 'ph-dot', 'color' => '#64748b', 'bg' => 'rgba(255,255,255,0.1)'];
            ?>
              <div>
                <div class="d-flex align-items-center justify-content-between small mb-1">
                  <span class="d-flex align-items-center gap-2 fw-bold text-white">
                    <span style="width:24px;height:24px;border-radius:6px;background:<?= $meta['bg'] ?>;color:<?= $meta['color'] ?>;display:inline-flex;align-items:center;justify-content:center;font-size:13px;">
                      <i class="ph-bold <?= $meta['icon'] ?>"></i>
                    </span>
                    <?= htmlspecialchars($name) ?>
                  </span>
                  <span class="text-secondary fw-bold">
                    <span class="text-white"><?= $count ?></span> respon (<?= $pct ?>%)
                  </span>
                </div>
                <div class="progress" style="height: 7px; background: #1e2438; border-radius: 4px;">
                  <div class="progress-bar" role="progressbar" style="width: <?= $pct ?>%; background: <?= $meta['color'] ?>;" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

        </div>
      </div>
    </div>

    <!-- Kolom Kanan: Distribusi Rating Kepuasan -->
    <div class="col-12 col-lg-6">
      <div class="c-card h-100">
        <div class="c-card-header d-flex align-items-center justify-content-between">
          <span class="c-card-title d-flex align-items-center gap-2">
            <i class="ph-bold ph-smiley text-warning"></i> Distribusi Rating Kepuasan
          </span>
          <span class="badge bg-dark border border-secondary text-secondary small">Emoji Scale 1-5</span>
        </div>
        <div class="c-card-body p-3">
          
          <?php
            $ratingLabels = [
              5 => ['emoji' => '🤩', 'label' => 'Sangat Puas (Bintang 5)', 'color' => '#10b981'],
              4 => ['emoji' => '🙂', 'label' => 'Puas (Bintang 4)', 'color' => '#34d399'],
              3 => ['emoji' => '😐', 'label' => 'Biasa Saja (Bintang 3)', 'color' => '#f59e0b'],
              2 => ['emoji' => '🙁', 'label' => 'Kurang Puas (Bintang 2)', 'color' => '#f97316'],
              1 => ['emoji' => '😡', 'label' => 'Sangat Buruk (Bintang 1)', 'color' => '#ef4444'],
            ];
          ?>

          <div class="d-flex flex-column gap-3">
            <?php foreach ($ratingLabels as $star => $info): 
              $cnt = $ratingCounts[$star] ?? 0;
              $pct = $totalRespondents > 0 ? round(($cnt / $totalRespondents) * 100, 1) : 0;
            ?>
              <div>
                <div class="d-flex align-items-center justify-content-between small mb-1">
                  <span class="d-flex align-items-center gap-2 fw-bold text-white">
                    <span style="font-size:16px;"><?= $info['emoji'] ?></span>
                    <span><?= $info['label'] ?></span>
                  </span>
                  <span class="text-secondary fw-bold">
                    <span class="text-white"><?= $cnt ?></span> user (<?= $pct ?>%)
                  </span>
                </div>
                <div class="progress" style="height: 7px; background: #1e2438; border-radius: 4px;">
                  <div class="progress-bar" role="progressbar" style="width: <?= $pct ?>%; background: <?= $info['color'] ?>;" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

        </div>
      </div>
    </div>

  </div>

  <!-- ── TABEL JAWABAN LENGKAP ── -->
  <div class="c-card">
    <div class="c-card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
      <div class="d-flex align-items-center gap-2">
        <i class="ph-bold ph-list-dashes text-warning fs-5"></i>
        <span class="c-card-title mb-0">Daftar Lengkap Respon Survei</span>
      </div>
      <div class="text-secondary small">
        Menampilkan <?= count($surveysList) ?> respon terbaru
      </div>
    </div>

    <div class="c-card-body p-0">
      <div class="table-responsive p-3">
        <table class="table table-dark table-hover c-table mb-0" id="surveysTable">
          <thead>
            <tr>
              <th style="width:50px;">No</th>
              <th>Waktu (WIB)</th>
              <th>Pengguna</th>
              <th>Sumber Info</th>
              <th>Rating Kepuasan</th>
              <th>Pengalaman Web</th>
              <th>Pesan / Keluhan</th>
              <th>Reward</th>
              <th style="width:80px;text-align:center;">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($surveysList)): ?>
              <tr>
                <td colspan="9" class="text-center py-5 text-secondary">
                  <i class="ph-bold ph-tray fs-1 d-block mb-2 text-muted"></i>
                  Belum ada respon survei dari pengguna.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($surveysList as $idx => $s): 
                $r = (int)$s['satisfaction_rating'];
                $rInfo = $ratingLabels[$r] ?? ['emoji' => '⭐', 'label' => "Rating {$r}", 'color' => '#94a3b8'];
              ?>
                <tr>
                  <td class="text-secondary fw-bold small"><?= $idx + 1 ?></td>
                  <td style="white-space:nowrap;">
                    <div class="text-white small fw-bold"><?= date('d M Y', strtotime($s['created_at'])) ?></div>
                    <div class="text-secondary" style="font-size:11px;"><?= date('H:i:s', strtotime($s['created_at'])) ?> WIB</div>
                  </td>
                  <td>
                    <?php if (!empty($s['username'])): ?>
                      <div class="fw-bold text-white small d-flex align-items-center gap-1">
                        <i class="ph-bold ph-user text-warning" style="font-size:12px;"></i>
                        <a href="/console/users.php?search=<?= urlencode((string)$s['username']) ?>" class="text-warning text-decoration-none" title="Lihat Profil Pengguna">
                          <?= htmlspecialchars((string)$s['username']) ?>
                        </a>
                      </div>
                      <div class="text-secondary" style="font-size:11px;"><?= htmlspecialchars((string)($s['email'] ?: '-')) ?></div>
                      <?php if (!empty($s['whatsapp'])): ?>
                        <a href="https://wa.me/<?= preg_replace('/\D/', '', $s['whatsapp']) ?>" target="_blank" class="badge bg-success-subtle text-success text-decoration-none mt-1 d-inline-flex align-items-center gap-1" style="font-size:10px;">
                          <i class="ph-bold ph-whatsapp-logo"></i> <?= htmlspecialchars((string)$s['whatsapp']) ?>
                        </a>
                      <?php endif; ?>
                    <?php else: ?>
                      <span class="text-muted small">User ID #<?= $s['user_id'] ?> (Dihapus)</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="badge bg-dark border border-secondary text-white py-1 px-2" style="font-size:11px;">
                      <?= htmlspecialchars((string)$s['source_info']) ?>
                    </span>
                  </td>
                  <td>
                    <div class="d-flex align-items-center gap-1" style="color:<?= $rInfo['color'] ?>;font-weight:700;font-size:12.5px;">
                      <span style="font-size:15px;"><?= $rInfo['emoji'] ?></span>
                      <span><?= $r ?> / 5</span>
                    </div>
                  </td>
                  <td style="max-width:220px;">
                    <div class="text-truncate text-secondary small" title="<?= htmlspecialchars((string)$s['experience']) ?>">
                      <?= htmlspecialchars(mb_substr((string)$s['experience'], 0, 70)) . (mb_strlen((string)$s['experience']) > 70 ? '...' : '') ?>
                    </div>
                  </td>
                  <td style="max-width:240px;">
                    <div class="text-truncate text-secondary small" title="<?= htmlspecialchars((string)$s['feedback_message']) ?>">
                      <?= htmlspecialchars(mb_substr((string)$s['feedback_message'], 0, 75)) . (mb_strlen((string)$s['feedback_message']) > 75 ? '...' : '') ?>
                    </div>
                  </td>
                  <td>
                    <span class="badge bg-success text-white fw-bold" style="font-size:11px;">
                      <i class="ph-bold ph-check"></i> +Rp <?= number_format((float)$s['reward_amount'], 0, ',', '.') ?>
                    </span>
                  </td>
                  <td class="text-center">
                    <button type="button" class="btn btn-sm btn-outline-warning" style="border-radius:8px;padding:4px 8px;font-size:11px;" onclick='showSurveyDetail(<?= json_encode($s, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' title="Lihat Detail Jawaban">
                      <i class="ph-bold ph-eye"></i> Detail
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

<!-- ── MODAL DETAIL JAWABAN SURVEI ── -->
<div class="modal fade" id="surveyDetailModal" tabindex="-1" aria-labelledby="surveyDetailModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="background:#111422;border:1px solid #1d2238;border-radius:18px;color:#f8fafc;">
      <div class="modal-header border-secondary py-3">
        <h5 class="modal-title fw-bold text-warning d-flex align-items-center gap-2" id="surveyDetailModalLabel" style="font-size:15px;">
          <i class="ph-bold ph-clipboard-text"></i> Rincian Jawaban Survei
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-4">
        
        <div class="p-3 rounded mb-3" style="background:#090b14;border:1px solid #1d2238;">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <span class="text-secondary small fw-bold">Pengguna:</span>
            <span class="text-white fw-bold" id="m-user">-</span>
          </div>
          <div class="d-flex justify-content-between align-items-center mb-1">
            <span class="text-secondary small">Kontak WhatsApp:</span>
            <span class="text-success small fw-bold" id="m-wa">-</span>
          </div>
          <div class="d-flex justify-content-between align-items-center mb-1">
            <span class="text-secondary small">Waktu Submit:</span>
            <span class="text-secondary small" id="m-time">-</span>
          </div>
          <div class="d-flex justify-content-between align-items-center">
            <span class="text-secondary small">Hadiah Dikreditkan:</span>
            <span class="badge bg-success" id="m-reward">Rp 15.000 (Saldo Tarik)</span>
          </div>
        </div>

        <div class="mb-3">
          <label class="text-secondary small fw-bold text-uppercase" style="letter-spacing:0.5px;">1. Sumber Info:</label>
          <div class="p-2 rounded bg-black border border-secondary text-white fw-bold small" id="m-source">
            -
          </div>
        </div>

        <div class="mb-3">
          <label class="text-secondary small fw-bold text-uppercase" style="letter-spacing:0.5px;">2. Rating Kepuasan:</label>
          <div class="p-2 rounded bg-black border border-secondary text-warning fw-bold small d-flex align-items-center gap-2" id="m-rating">
            -
          </div>
        </div>

        <div class="mb-3">
          <label class="text-secondary small fw-bold text-uppercase" style="letter-spacing:0.5px;">3. Pengalaman Menggunakan Web:</label>
          <div class="p-3 rounded bg-black border border-secondary text-light small" style="line-height:1.6;white-space:pre-wrap;" id="m-experience">
            -
          </div>
        </div>

        <div class="mb-3">
          <label class="text-secondary small fw-bold text-uppercase" style="letter-spacing:0.5px;">4. Pesan / Keluhan untuk Web:</label>
          <div class="p-3 rounded bg-black border border-secondary text-warning small" style="line-height:1.6;white-space:pre-wrap;" id="m-feedback">
            -
          </div>
        </div>

        <div class="text-secondary" style="font-size:11px;" id="m-meta">
          IP: -
        </div>

      </div>
      <div class="modal-footer border-secondary py-2 justify-content-between">
        <form method="POST" id="formDeleteSurvey" onsubmit="return confirm('Yakin ingin menghapus catatan survei ini? (Data saldo user tidak akan terhapus)');">
          <input type="hidden" name="delete_id" id="m-delete-id" value="">
          <button type="submit" class="btn btn-outline-danger btn-sm d-flex align-items-center gap-1">
            <i class="ph-bold ph-trash"></i> Hapus Respon
          </button>
        </form>
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  if (typeof $.fn.DataTable !== 'undefined' && $('#surveysTable tbody tr td').length > 1) {
    $('#surveysTable').DataTable({
      order: [[0, 'asc']],
      pageLength: 25,
      language: {
        search: "Cari Respon:",
        lengthMenu: "Tampilkan _MENU_ baris",
        info: "Menampilkan _START_ s/d _END_ dari _TOTAL_ respon",
        infoEmpty: "Tidak ada data",
        paginate: {
          next: "Berikutnya",
          previous: "Sebelumnya"
        }
      }
    });
  }
});

function showSurveyDetail(data) {
  const modalEl = document.getElementById('surveyDetailModal');
  const modal   = new bootstrap.Modal(modalEl);

  document.getElementById('m-user').textContent       = (data.username || 'User ID #' + data.user_id) + (data.email ? ' (' + data.email + ')' : '');
  document.getElementById('m-wa').textContent         = data.whatsapp || '-';
  document.getElementById('m-time').textContent       = data.created_at + ' WIB';
  document.getElementById('m-source').textContent     = data.source_info || '-';
  
  const ratingLabels = {
    1: '😡 Sangat Buruk (1/5)',
    2: '🙁 Kurang Puas (2/5)',
    3: '😐 Biasa Saja (3/5)',
    4: '🙂 Puas (4/5)',
    5: '🤩 Sangat Puas (5/5)'
  };
  document.getElementById('m-rating').textContent     = ratingLabels[data.satisfaction_rating] || (data.satisfaction_rating + ' / 5');
  document.getElementById('m-experience').textContent = data.experience || '-';
  document.getElementById('m-feedback').textContent   = data.feedback_message || '-';
  document.getElementById('m-meta').textContent       = 'IP Address: ' + (data.ip_address || '-') + ' | User Agent: ' + (data.user_agent || '-');
  document.getElementById('m-delete-id').value        = data.id;

  modal.show();
}
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
