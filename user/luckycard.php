<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

// Handle AJAX Play
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'play') {
    header('Content-Type: application/json');
    if (!csrf_verify()) {
        echo json_encode(['success' => false, 'message' => 'CSRF tidak valid.']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Get user's current spin tickets with lock
        $stmt = $pdo->prepare("SELECT spin_tickets FROM users WHERE id=? FOR UPDATE");
        $stmt->execute([$user['id']]);
        $tickets = (int)$stmt->fetchColumn();

        if ($tickets <= 0) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Tiket Spin kamu habis! Selesaikan misi untuk mendapatkannya.']);
            exit;
        }

        // Deduct 1 spin ticket
        $stmt = $pdo->prepare("UPDATE users SET spin_tickets = spin_tickets - 1 WHERE id = ?");
        $stmt->execute([$user['id']]);

        // Prizes Definition (Nominals)
        $prizes_pool = [
            ['val' => 0,     'weight' => 45],
            ['val' => 1000,  'weight' => 30],
            ['val' => 2500,  'weight' => 15],
            ['val' => 5000,  'weight' => 6],
            ['val' => 10000, 'weight' => 3],
            ['val' => 20000, 'weight' => 1],
        ];

        $totalWeight = array_sum(array_column($prizes_pool, 'weight'));
        $rand = random_int(1, $totalWeight);
        $prize_val = 0;
        $current = 0;
        foreach ($prizes_pool as $p) {
            $current += $p['weight'];
            if ($rand <= $current) {
                $prize_val = $p['val'];
                break;
            }
        }

        // Generate other 5 fake prizes for other cards
        $other_prizes = [];
        for ($i = 0; $i < 5; $i++) {
            $other_prizes[] = $prizes_pool[array_rand($prizes_pool)]['val'];
        }

        // Insert minigame log
        $stmt = $pdo->prepare("INSERT INTO minigame_logs (user_id, game_type, score, reward) VALUES (?, 'lucky_card', 0, ?)");
        $stmt->execute([$user['id'], $prize_val]);

        // Update user balance (balance_wd)
        if ($prize_val > 0) {
            $stmt = $pdo->prepare("UPDATE users SET balance_wd = balance_wd + ? WHERE id = ?");
            $stmt->execute([$prize_val, $user['id']]);
        }

        $pdo->commit();
        echo json_encode([
            'success' => true,
            'prize' => $prize_val,
            'others' => $other_prizes,
            'remaining_tickets' => $tickets - 1
        ]);
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => 'SysErr: ' . $e->getMessage()]);
    }
    exit;
}

// Fetch user's current spin tickets for UI
$stmt = $pdo->prepare("SELECT spin_tickets FROM users WHERE id=?");
$stmt->execute([$user['id']]);
$spin_tickets = (int)$stmt->fetchColumn();

$pageTitle = 'Lucky Card';
$activePage = 'missions';
require dirname(__DIR__) . '/partials/header.php';
?>

<div class="wd-body">

  <!-- Header Card -->
  <div class="lc-header-card">
    <div style="display:flex; align-items:center; gap:12px;">
      <div class="lc-header-icon">
        <i class="ph-fill ph-cards"></i>
      </div>
      <div>
        <div class="lc-header-title">Lucky Card</div>
        <div class="lc-header-sub">Tebak 1 kartu keberuntunganmu!</div>
      </div>
    </div>
    <a href="/missions" class="lc-close-btn" title="Tutup"><i class="ph-bold ph-x"></i></a>
  </div>

  <!-- Ticket Display Panel -->
  <div class="ticket-display">
    <div class="ticket-icon-wrap">
      <i class="ph-fill ph-ticket"></i>
    </div>
    <div class="ticket-text">
      <span class="ticket-title">Tiket Spin Tersisa</span>
      <span class="ticket-val"><span id="ticket-count"><?= $spin_tickets ?></span> Tiket</span>
    </div>
  </div>

  <!-- Game Box -->
  <div class="game-container" id="game-container">
    <?php if ($spin_tickets <= 0): ?>
      <div class="played-state" id="no-tickets-state">
        <div class="played-icon">🎟️</div>
        <h3 style="font-size:18px;font-weight:900;color:#1e1b4b;margin:0 0 8px;">Tiket Spin Habis</h3>
        <p style="font-size:13px;font-weight:700;color:#64748b;margin:0 0 20px;line-height:1.5;max-width:300px;text-align:center;">
          Kamu membutuhkan 1 tiket spin untuk membuka kartu keberuntungan. Selesaikan misi untuk mendapatkannya!
        </p>
        <a href="/missions" class="btn-back">Lihat Misi</a>
      </div>
    <?php else: ?>
      <div id="game-active-panel">
        <!-- Game Instruction Prompt -->
        <div class="game-prompt">
          <div class="game-prompt-badge">🃏 PILIH 1 KARTU</div>
          <div class="game-prompt-text">Ketuk salah satu kartu untuk membuka hadiahmu!</div>
        </div>

        <!-- 6 Cards Grid (2 rows x 3 cols) -->
        <div class="cards-grid">
          <?php for($i = 0; $i < 6; $i++): ?>
          <div class="card-scene" onclick="flipCard(this, <?= $i ?>)">
            <div class="card-obj" id="card-<?= $i ?>">
              <!-- Front Face (Tertutup) -->
              <div class="card-face card-front">
                <div class="card-front-pattern"></div>
                <div class="card-front-inner">
                  <div class="card-question-icon">
                    <i class="ph-bold ph-question"></i>
                  </div>
                  <span class="card-front-text">LUCKY</span>
                </div>
              </div>
              <!-- Back Face (Terbuka) -->
              <div class="card-face card-back" id="card-back-<?= $i ?>">
                <div class="prize-icon-wrap" id="prize-icon-<?= $i ?>">
                  <i class="ph-fill ph-coin-vertical"></i>
                </div>
                <div class="prize-amt" id="prize-<?= $i ?>">Rp 0</div>
                <div class="prize-tag" id="prize-tag-<?= $i ?>">HADIAH</div>
              </div>
            </div>
          </div>
          <?php endfor; ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- Result Popup Modal (Overlay) -->
    <div id="result-overlay" style="display:none;">
      <div class="result-box">
        <div id="reward-loading" style="display:block;">
          <i class="ph-bold ph-spinner ph-spin" style="font-size:36px;color:#9333ea;"></i>
          <p style="font-size:14px; margin-top:12px; font-weight:800; color:#6b21a8;">Membuka Hadiah...</p>
        </div>
        
        <div id="reward-success" style="display:none;">
          <div id="result-emoji" style="font-size:48px; margin-bottom:4px;">🎉</div>
          <h2 id="result-title" style="color:#d97706; font-size:22px; font-weight:900; margin:0 0 10px;">SELAMAT!</h2>
          
          <div class="reward-card-display">
            <p style="font-size:12px; color:#7e22ce; font-weight:800; margin:0 0 4px; text-transform:uppercase;">Kamu Mendapatkan:</p>
            <h1 style="font-size:26px; font-weight:900; color:#9333ea; margin:0;"><span id="reward-amount">Rp 0</span></h1>
          </div>

          <div class="result-actions">
            <button id="btn-play-again" onclick="playAgain()" class="btn-action btn-primary">
              <i class="ph-bold ph-arrows-clockwise"></i> Main Lagi
            </button>
            <button onclick="window.location.href='/missions'" class="btn-action btn-secondary">
              Kembali
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>

</div>

<style>
/* ── Container Layout ─────────────────── */
.wd-body {
  flex: 1;
  background: #f97316;
  padding: 14px 14px 100px;
  position: relative;
  z-index: 2;
  min-height: 80vh;
}
.wd-body::before {
  content: ''; position: absolute; inset: 0;
  background: radial-gradient(circle, rgba(255,255,255,0.08) 10%, transparent 10%), radial-gradient(circle, rgba(255,255,255,0.08) 10%, transparent 10%);
  background-size: 50px 50px; background-position: 0 0, 25px 25px; pointer-events: none;
  z-index: -1;
}

/* ── Header ──────────────────────────── */
.lc-header-card {
  margin-bottom: 16px;
  background: #fff;
  padding: 12px 16px;
  border: 3px solid #c084fc;
  border-radius: 20px;
  box-shadow: 0 5px 0 #a855f7;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.lc-header-icon {
  background: #f3e8ff;
  width: 40px;
  height: 40px;
  border-radius: 14px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #9333ea;
  font-size: 22px;
  box-shadow: inset 0 -2px 0 #d8b4fe;
}
.lc-header-title {
  font-size: 17px;
  color: #4c1d95;
  font-weight: 900;
  line-height: 1.2;
}
.lc-header-sub {
  font-size: 11px;
  font-weight: 800;
  color: #7e22ce;
  margin-top: 2px;
}
.lc-close-btn {
  background: #f3e8ff;
  width: 34px;
  height: 34px;
  border-radius: 12px;
  color: #9333ea;
  display: flex;
  align-items: center;
  justify-content: center;
  text-decoration: none;
  font-size: 16px;
  transition: transform 0.1s;
}
.lc-close-btn:active { transform: scale(0.95); }

/* ── Ticket Display ──────────────────── */
.ticket-display {
  background: linear-gradient(135deg, #a855f7, #7e22ce);
  border: 3px solid #fff;
  border-radius: 20px;
  padding: 12px 18px;
  display: flex;
  align-items: center;
  gap: 14px;
  box-shadow: 0 6px 0 #6b21a8;
  margin-bottom: 18px;
}
.ticket-icon-wrap {
  width: 42px;
  height: 42px;
  background: rgba(255, 255, 255, 0.22);
  border-radius: 14px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  font-size: 22px;
  backdrop-filter: blur(4px);
}
.ticket-text {
  display: flex;
  flex-direction: column;
}
.ticket-title {
  font-size: 11px;
  font-weight: 800;
  color: #f3e8ff;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}
.ticket-val {
  font-size: 19px;
  font-weight: 900;
  color: #fff;
  line-height: 1.2;
}

/* ── Game Box Container ──────────────── */
.game-container {
  background: #ffffff;
  border: 3px solid #cbd5e1;
  border-radius: 24px;
  box-shadow: 0 8px 0 #94a3b8;
  position: relative;
  padding: 16px 14px 20px;
  box-sizing: border-box;
}

.game-prompt {
  text-align: center;
  margin-bottom: 14px;
}
.game-prompt-badge {
  display: inline-block;
  background: #f3e8ff;
  color: #7e22ce;
  border: 2px solid #d8b4fe;
  padding: 3px 12px;
  border-radius: 20px;
  font-size: 11px;
  font-weight: 900;
  letter-spacing: 0.5px;
}
.game-prompt-text {
  font-size: 12px;
  font-weight: 800;
  color: #64748b;
  margin-top: 4px;
}

/* ── Cards Grid ──────────────────────── */
.cards-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 12px;
  width: 100%;
  margin: 0 auto;
}

@media(max-width: 360px) {
  .cards-grid { gap: 8px; }
}

/* ── Card Scene & 3D Object ──────────── */
.card-scene {
  width: 100%;
  aspect-ratio: 1 / 1.32;
  perspective: 1000px;
  cursor: pointer;
  -webkit-tap-highlight-color: transparent;
  user-select: none;
}
.card-scene:active {
  transform: scale(0.96);
}

.card-obj {
  width: 100%;
  height: 100%;
  position: relative;
  transform-style: preserve-3d;
  transition: transform 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
  border-radius: 18px;
}
.card-obj.is-flipped {
  transform: rotateY(180deg);
}
.card-obj.is-selected {
  transform: rotateY(180deg) scale(1.03);
  z-index: 5;
}

/* ── Card Faces ──────────────────────── */
.card-face {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  -webkit-backface-visibility: hidden;
  backface-visibility: hidden;
  border-radius: 18px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  box-sizing: border-box;
  padding: 8px 4px;
}

/* ── Front Face ──────────────────────── */
.card-front {
  background: linear-gradient(145deg, #7c3aed, #6d28d9, #4c1d95);
  border: 3px solid #fff;
  box-shadow: 0 5px 0 #4c1d95, 0 8px 15px rgba(109, 40, 217, 0.25);
  position: relative;
  overflow: hidden;
}
.card-front-pattern {
  position: absolute;
  inset: 3px;
  border: 1.5px dashed rgba(255, 255, 255, 0.35);
  border-radius: 13px;
  pointer-events: none;
}
.card-front-inner {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
  z-index: 1;
}
.card-question-icon {
  width: 38px;
  height: 38px;
  background: rgba(255, 255, 255, 0.2);
  border: 2px solid rgba(255, 255, 255, 0.4);
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fde047;
  font-size: 22px;
  text-shadow: 0 2px 4px rgba(0,0,0,0.3);
}
.card-front-text {
  font-size: 10px;
  font-weight: 900;
  color: #fff;
  letter-spacing: 1px;
  text-shadow: 0 1px 2px rgba(0,0,0,0.4);
}

/* ── Back Face (Prize) ───────────────── */
.card-back {
  background: linear-gradient(145deg, #fef08a, #facc15, #eab308);
  border: 3px solid #fff;
  box-shadow: 0 5px 0 #ca8a04, 0 8px 15px rgba(234, 179, 8, 0.25);
  transform: rotateY(180deg);
  gap: 2px;
}
.card-back.zonk {
  background: linear-gradient(145deg, #f1f5f9, #e2e8f0, #cbd5e1);
  box-shadow: 0 5px 0 #94a3b8;
}

.prize-icon-wrap {
  font-size: 24px;
  color: #b45309;
}
.card-back.zonk .prize-icon-wrap {
  color: #94a3b8;
}

.prize-amt {
  font-size: 14px;
  font-weight: 900;
  color: #78350f;
  text-align: center;
  word-break: break-word;
  line-height: 1.15;
}
.card-back.zonk .prize-amt {
  color: #64748b;
  font-size: 15px;
}

.prize-tag {
  font-size: 8px;
  font-weight: 900;
  color: #92400e;
  background: rgba(255, 255, 255, 0.6);
  padding: 1px 6px;
  border-radius: 8px;
  margin-top: 2px;
}
.card-back.zonk .prize-tag {
  color: #64748b;
  background: rgba(0, 0, 0, 0.05);
}

/* ── Result Modal Overlay ────────────── */
#result-overlay {
  position: absolute;
  inset: 0;
  background: rgba(15, 23, 42, 0.72);
  backdrop-filter: blur(6px);
  z-index: 50;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
  border-radius: 20px;
}
.result-box {
  background: #fff;
  width: 100%;
  max-width: 300px;
  border-radius: 24px;
  padding: 24px 20px;
  text-align: center;
  border: 3px solid #fff;
  box-shadow: 0 16px 30px rgba(0, 0, 0, 0.3);
  animation: popIn 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.reward-card-display {
  background: #faf5ff;
  border: 2.5px dashed #c084fc;
  padding: 14px;
  border-radius: 18px;
  margin-bottom: 16px;
}

.result-actions {
  display: flex;
  flex-direction: column;
  gap: 8px;
  width: 100%;
}
.btn-action {
  width: 100%;
  font-weight: 900;
  font-size: 13px;
  padding: 12px;
  border-radius: 14px;
  border: 2px solid #fff;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  transition: transform 0.1s;
}
.btn-action:active { transform: translateY(2px); }
.btn-primary {
  background: linear-gradient(135deg, #22c55e, #16a34a);
  color: #fff;
  box-shadow: 0 4px 0 #15803d;
}
.btn-secondary {
  background: #f1f5f9;
  color: #475569;
  border-color: #e2e8f0;
  box-shadow: 0 4px 0 #cbd5e1;
}

/* ── No Ticket State ─────────────────── */
.played-state {
  padding: 30px 14px;
  text-align: center;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
}
.played-icon {
  font-size: 54px;
  margin-bottom: 12px;
}
.btn-back {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #a855f7, #7e22ce);
  color: #fff;
  font-weight: 900;
  font-size: 14px;
  padding: 12px 28px;
  border-radius: 16px;
  text-decoration: none;
  border: 3px solid #fff;
  box-shadow: 0 5px 0 #6b21a8;
  transition: transform 0.1s;
}
.btn-back:active { transform: translateY(3px); box-shadow: 0 2px 0 #6b21a8; }

@keyframes popIn {
  0% { transform: scale(0.6); opacity: 0; }
  100% { transform: scale(1); opacity: 1; }
}
</style>

<script>
const _csrf = '<?= csrf_token() ?>';
let isPlaying = false;
let currentTickets = <?= $spin_tickets ?>;

async function flipCard(el, idx) {
    if (isPlaying) return;
    isPlaying = true;
    
    const cardObj = document.getElementById('card-' + idx);
    const overlay = document.getElementById('result-overlay');
    
    try {
        const formData = new FormData();
        formData.append('action', 'play');
        formData.append('_csrf', _csrf);
        
        const res = await fetch('', { method: 'POST', body: formData });
        const data = await res.json();
        
        if (!data.success) {
            if (typeof nToast !== 'undefined') {
                nToast(data.message, 'error');
            }
            setTimeout(() => window.location.reload(), 1200);
            return;
        }
        
        const prize = data.prize;
        const others = data.others;
        currentTickets = data.remaining_tickets;
        
        // Update ticket balance in DOM
        document.getElementById('ticket-count').innerText = currentTickets;
        
        // Setup & Flip selected card
        const prizeEl = document.getElementById('prize-' + idx);
        const cardBack = document.getElementById('card-back-' + idx);
        const prizeIcon = document.getElementById('prize-icon-' + idx);
        const prizeTag = document.getElementById('prize-tag-' + idx);
        
        if (prize > 0) {
            prizeEl.innerText = 'Rp ' + prize.toLocaleString('id-ID');
            cardBack.classList.remove('zonk');
            prizeIcon.innerHTML = '<i class="ph-fill ph-coin-vertical"></i>';
            prizeTag.innerText = 'PILIHANMU ⭐';
        } else {
            prizeEl.innerText = 'ZONK';
            cardBack.classList.add('zonk');
            prizeIcon.innerHTML = '<i class="ph-fill ph-smiley-sad"></i>';
            prizeTag.innerText = 'PILIHANMU';
        }
        
        cardObj.classList.add('is-flipped', 'is-selected');
        
        // Flip audio effect
        try {
            const actx = new (window.AudioContext || window.webkitAudioContext)();
            const osc = actx.createOscillator(), gain = actx.createGain();
            osc.connect(gain); gain.connect(actx.destination);
            osc.frequency.value = 540;
            gain.gain.setValueAtTime(0, actx.currentTime);
            gain.gain.linearRampToValueAtTime(0.12, actx.currentTime + 0.04);
            gain.gain.exponentialRampToValueAtTime(0.01, actx.currentTime + 0.18);
            osc.start(); osc.stop(actx.currentTime + 0.18);
        } catch(e) {}
        
        // Reveal other cards cascadingly
        setTimeout(() => {
            let otherIdx = 0;
            for(let i = 0; i < 6; i++) {
                if (i !== idx) {
                    const cObj = document.getElementById('card-' + i);
                    const pEl = document.getElementById('prize-' + i);
                    const cBack = document.getElementById('card-back-' + i);
                    const pIcon = document.getElementById('prize-icon-' + i);
                    const pTag = document.getElementById('prize-tag-' + i);
                    const pVal = others[otherIdx++];
                    
                    if (pVal > 0) {
                        pEl.innerText = 'Rp ' + pVal.toLocaleString('id-ID');
                        cBack.classList.remove('zonk');
                        pIcon.innerHTML = '<i class="ph-fill ph-coin-vertical"></i>';
                        pTag.innerText = 'KARTU LAIN';
                    } else {
                        pEl.innerText = 'ZONK';
                        cBack.classList.add('zonk');
                        pIcon.innerHTML = '<i class="ph-fill ph-smiley-sad"></i>';
                        pTag.innerText = 'KARTU LAIN';
                    }
                    cObj.classList.add('is-flipped');
                }
            }
            
            // Show result modal
            setTimeout(() => {
                overlay.style.display = 'flex';
                document.getElementById('reward-loading').style.display = 'none';
                
                const title = document.getElementById('result-title');
                const emoji = document.getElementById('result-emoji');
                
                if (prize === 0) {
                    emoji.innerText = '😢';
                    title.innerText = 'YAHH ZONK!';
                    title.style.color = '#64748b';
                } else {
                    emoji.innerText = '🎉';
                    title.innerText = 'SELAMAT!';
                    title.style.color = '#d97706';
                    
                    // Play win audio
                    try {
                        const actx = new (window.AudioContext || window.webkitAudioContext)();
                        const osc = actx.createOscillator(), gain = actx.createGain();
                        osc.connect(gain); gain.connect(actx.destination);
                        osc.frequency.setValueAtTime(523.25, actx.currentTime);
                        osc.frequency.setValueAtTime(659.25, actx.currentTime + 0.1);
                        osc.frequency.setValueAtTime(783.99, actx.currentTime + 0.2);
                        gain.gain.setValueAtTime(0.15, actx.currentTime);
                        gain.gain.exponentialRampToValueAtTime(0.01, actx.currentTime + 0.45);
                        osc.start(); osc.stop(actx.currentTime + 0.45);
                    } catch(e) {}
                }
                
                document.getElementById('reward-amount').innerText = prize > 0 ? 'Rp ' + prize.toLocaleString('id-ID') : 'Zonk';
                document.getElementById('reward-success').style.display = 'block';
                
                const playAgainBtn = document.getElementById('btn-play-again');
                if (currentTickets <= 0) {
                    playAgainBtn.style.display = 'none';
                } else {
                    playAgainBtn.style.display = 'flex';
                }
            }, 850);
            
        }, 600);
        
    } catch (err) {
        if (typeof nToast !== 'undefined') {
            nToast("Terjadi kesalahan jaringan.", "error");
        }
        isPlaying = false;
    }
}

function playAgain() {
    document.getElementById('result-overlay').style.display = 'none';
    document.getElementById('reward-success').style.display = 'none';
    document.getElementById('reward-loading').style.display = 'block';

    const cards = document.querySelectorAll('.card-obj');
    cards.forEach((card, i) => {
        setTimeout(() => {
            card.classList.remove('is-flipped', 'is-selected');
        }, i * 60);
    });

    setTimeout(() => {
        if (currentTickets <= 0) {
            window.location.reload();
        } else {
            isPlaying = false;
        }
    }, cards.length * 60 + 400);
}
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
