<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

// Auto-create table on live server
$pdo->exec("CREATE TABLE IF NOT EXISTS minigame_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT NOT NULL,
    game_type VARCHAR(50) NOT NULL,
    score INT NOT NULL,
    reward DECIMAL(10,2) NOT NULL,
    played_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Ensure settings exist for configuration
$pdo->exec("INSERT IGNORE INTO settings (`key`, `value`) VALUES 
    ('minigame_reward_per_click', '10'),
    ('minigame_base_bonus', '50')
");

// Handle AJAX Claim
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'claim') {
    header('Content-Type: application/json');
    $clicks = (int)($_POST['clicks'] ?? 0);
    
    // Anti-cheat limit (misal max 150 clicks in 10s)
    if ($clicks > 150) $clicks = 150;
    if ($clicks < 0) $clicks = 0;
    
    // Check if played today
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM minigame_logs WHERE user_id=? AND DATE(played_at)=CURDATE()");
    $stmt->execute([$user['id']]);
    $played_today = (int)$stmt->fetchColumn();
    
    if ($played_today > 0) {
        echo json_encode(['success' => false, 'message' => 'Hari ini sudah main! Besok lagi ya.']);
        exit;
    }
    
    $reward_per_click = (int)setting($pdo, 'minigame_reward_per_click', '10');
    $base_bonus = (int)setting($pdo, 'minigame_base_bonus', '50');
    $reward = $clicks * $reward_per_click;
    
    // Base bonus just for playing
    if ($reward > 0) {
        $reward += $base_bonus; 
    }
    
    try {
        $pdo->beginTransaction();
        
        // Log game
        $stmt = $pdo->prepare("INSERT INTO minigame_logs (user_id, game_type, score, reward) VALUES (?, 'tap_tap', ?, ?)");
        $stmt->execute([$user['id'], $clicks, $reward]);
        
        // Update user balance (balance_wd)
        $stmt = $pdo->prepare("UPDATE users SET balance_wd = balance_wd + ? WHERE id = ?");
        $stmt->execute([$reward, $user['id']]);
        
        $pdo->commit();
        echo json_encode(['success' => true, 'reward' => $reward]);
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => 'SysErr: ' . $e->getMessage()]);
    }
    exit;
}

// Check if played today for UI
$stmt = $pdo->prepare("SELECT COUNT(*) FROM minigame_logs WHERE user_id=? AND DATE(played_at)=CURDATE()");
$stmt->execute([$user['id']]);
$played_today = (int)$stmt->fetchColumn();

$pageTitle = 'Tap-Tap Game  ';
$activePage = 'missions'; // Keep missions active in bottom nav
require dirname(__DIR__) . '/partials/header.php';
?>

<div class="section-header" style="margin-bottom:20px; background: #fff; padding: 14px 16px; border: 3px solid #fde047; border-radius: 20px; box-shadow: 0 6px 0 #fde047; display:flex; align-items:center; justify-content:space-between;">
  <div>
      <div class="section-title" style="display:flex;align-items:center;gap:8px;font-size:18px; color: #78350f; font-weight: 900;">
        <div style="background:#fef08a; width:36px; height:36px; border-radius:12px; display:flex; align-items:center; justify-content:center; color:#d97706; font-size:20px;">
            <i class="ph-fill ph-coin"></i>
        </div>
        Tap-Tap Frenzy
      </div>
      <p style="font-size:12px;font-weight:700;color:#92400e;margin:6px 0 0">Pukul Karung Duit Secepatnya!</p>
  </div>
  <a href="/missions" style="background:#fef08a; padding:8px; border-radius:12px; color:#d97706;"><i class="ph-bold ph-x"></i></a>
</div>

<div class="game-container">
    <?php if ($played_today > 0): ?>
        <div class="played-state">
            <div class="played-icon">😴</div>
            <h3 style="font-size:18px;font-weight:900;color:#334155;margin:0 0 8px;">Sudah Main Hari Ini</h3>
            <p style="font-size:13px;font-weight:700;color:#64748b;margin:0;line-height:1.5;">Otot jarimu butuh istirahat! Balik lagi besok buat nge-tap dan dapetin koin lagi.</p>
            <a href="/missions" class="btn-back">Kembali ke Misi</a>
        </div>
    <?php else: ?>
        <div id="game-ui">
            <div id="game-info">
                <div class="time-box">
                    <i class="ph-fill ph-timer"></i> <span id="time-left">10.0</span>s
                </div>
                <div class="score-box">
                    <img src="/assets/dollar.png" alt="koin"> <span id="score-count">0</span>
                </div>
            </div>
            
            <div id="start-overlay">
                <h2>Siap?</h2>
                <p>Tap celengan secepat mungkin dalam 10 detik!</p>
                <button id="start-btn">Mulai Main!</button>
            </div>
            
            <div id="play-area" style="display:none;">
                <div id="tap-target">
                    <img src="/assets/moneybag_v2.png" style="width: 140px; height: 140px; object-fit: contain;">
                </div>
                <div id="floating-texts"></div>
            </div>
            
            <div id="result-overlay" style="display:none;">
                <div class="result-box">
                    <h2 style="color:#d97706; font-size:24px; font-weight:900; margin:0 0 4px;">WAKTU HABIS!</h2>
                    <p style="font-size:14px; color:#78350f; font-weight:700; margin:0 0 20px;">Kamu berhasil tap <span id="final-taps" style="font-size:18px;font-weight:900;color:#059669;">0</span> kali!</p>
                    
                    <div id="reward-loading" style="display:block;">
                        <i class="ph-bold ph-spinner ph-spin" style="font-size:24px;color:#d97706;"></i>
                        <p style="font-size:12px; margin-top:8px; font-weight:700;">Menghitung Hadiah...</p>
                    </div>
                    
                    <div id="reward-success" style="display:none;">
                        <div style="background:#ecfdf5; border:2px dashed #10b981; padding:16px; border-radius:16px; margin-bottom:16px;">
                            <p style="font-size:12px; color:#047857; font-weight:700; margin:0 0 4px;">Selamat! Saldo Tarik bertambah:</p>
                            <h1 style="font-size:28px; font-weight:900; color:#059669; margin:0;">+ <span id="reward-amount">Rp 0</span></h1>
                        </div>
                        <button onclick="window.location.href='/missions'" class="btn-back" style="width:100%; text-align:center; border:none; cursor:pointer; font-family:inherit;">Mantap!</button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
/* Game Container */
.game-container {
    background: #fff;
    border: 3px solid #cbd5e1;
    border-radius: 24px;
    box-shadow: 0 8px 0 #cbd5e1;
    position: relative;
    overflow: hidden;
    min-height: 400px;
    display: flex;
    flex-direction: column;
}

.played-state {
    padding: 40px 20px;
    text-align: center;
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}
.played-icon {
    font-size: 64px;
    margin-bottom: 16px;
    animation: float 3s ease-in-out infinite;
}

#game-ui {
    flex: 1;
    display: flex;
    flex-direction: column;
    position: relative;
}

#game-info {
    padding: 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 2px dashed #e2e8f0;
    z-index: 10;
}
.time-box {
    background: #fee2e2;
    color: #b91c1c;
    border: 2px solid #ef4444;
    padding: 8px 16px;
    border-radius: 16px;
    font-weight: 900;
    font-size: 16px;
    display: flex;
    align-items: center;
    gap: 6px;
    box-shadow: 0 4px 0 #ef4444;
}
.score-box {
    background: #fef08a;
    color: #d97706;
    border: 2px solid #f59e0b;
    padding: 8px 16px;
    border-radius: 16px;
    font-weight: 900;
    font-size: 18px;
    display: flex;
    align-items: center;
    gap: 6px;
    box-shadow: 0 4px 0 #f59e0b;
}
.score-box img { width: 24px; height: 24px; }

#start-overlay {
    position: absolute;
    inset: 0;
    background: rgba(255, 255, 255, 0.9);
    backdrop-filter: blur(4px);
    z-index: 20;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 20px;
}
#start-overlay h2 { font-size: 32px; font-weight: 900; color: #0ea5e9; margin: 0 0 8px; }
#start-overlay p { font-size: 14px; font-weight: 700; color: #64748b; margin: 0 0 24px; }
#start-btn {
    background: linear-gradient(135deg, #fde047, #f59e0b);
    border: 3px solid #fff;
    color: #78350f;
    font-size: 18px;
    font-weight: 900;
    padding: 12px 32px;
    border-radius: 100px;
    box-shadow: 0 6px 0 #d97706;
    cursor: pointer;
    transition: transform 0.1s;
}
#start-btn:active { transform: translateY(6px); box-shadow: 0 0 0 #d97706; }

#play-area {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    background: radial-gradient(circle, #f8fafc 0%, #e2e8f0 100%);
    overflow: hidden;
    user-select: none;
    -webkit-user-select: none;
}
#tap-target {
    cursor: pointer;
    transition: transform 0.05s ease-out;
    filter: drop-shadow(0 10px 10px rgba(0,0,0,0.2));
    position: relative;
    z-index: 5;
    -webkit-tap-highlight-color: transparent;
}
.tapped { transform: scale(0.85) rotate(-5deg); filter: drop-shadow(0 2px 2px rgba(0,0,0,0.4)) !important; }

.floating-text {
    position: absolute;
    color: #10b981;
    font-weight: 900;
    font-size: 24px;
    pointer-events: none;
    animation: floatUp 0.8s ease-out forwards;
    text-shadow: 1px 1px 0 #fff, -1px -1px 0 #fff, 1px -1px 0 #fff, -1px 1px 0 #fff;
    z-index: 4;
}

#result-overlay {
    position: absolute;
    inset: 0;
    background: rgba(15, 23, 42, 0.8);
    backdrop-filter: blur(8px);
    z-index: 30;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.result-box {
    background: #fff;
    width: 100%;
    max-width: 300px;
    border-radius: 24px;
    padding: 24px;
    text-align: center;
    border: 4px solid #fde047;
    box-shadow: 0 8px 0 #f59e0b;
    animation: popIn 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.btn-back {
    display: inline-block;
    background: #0ea5e9;
    color: #fff;
    font-weight: 800;
    font-size: 14px;
    padding: 12px 24px;
    border-radius: 100px;
    text-decoration: none;
    border: 2px solid #fff;
    box-shadow: 0 4px 0 #0284c7;
    margin-top: 16px;
    transition: transform 0.1s;
}
.btn-back:active { transform: translateY(4px); box-shadow: 0 0 0 #0284c7; }

@keyframes floatUp {
    0% { transform: translateY(0) scale(1); opacity: 1; }
    100% { transform: translateY(-100px) scale(1.5); opacity: 0; }
}
@keyframes float {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-10px); }
}
@keyframes popIn {
    0% { transform: scale(0); opacity: 0; }
    100% { transform: scale(1); opacity: 1; }
}
</style>

<script>
const _csrf = '<?= csrf_token() ?>';

document.addEventListener('DOMContentLoaded', () => {
    const startBtn = document.getElementById('start-btn');
    if(!startBtn) return; // If already played
    
    const tapTarget = document.getElementById('tap-target');
    const timeLeftEl = document.getElementById('time-left');
    const scoreCountEl = document.getElementById('score-count');
    const playArea = document.getElementById('play-area');
    
    let score = 0;
    let timeLeft = 10.0;
    let isPlaying = false;
    let timerInterval;
    let audioCtx;

    function initAudio() {
        if (!audioCtx) {
            const AudioContext = window.AudioContext || window.webkitAudioContext;
            if (AudioContext) audioCtx = new AudioContext();
        }
    }

    function playCoinSound() {
        if (!audioCtx) return;
        try {
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.connect(gain);
            gain.connect(audioCtx.destination);
            osc.frequency.value = 1200 + Math.random() * 400; // Random high pitch
            osc.type = 'sine';
            gain.gain.setValueAtTime(0, audioCtx.currentTime);
            gain.gain.linearRampToValueAtTime(0.05, audioCtx.currentTime + 0.01);
            gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.1);
            osc.start();
            osc.stop(audioCtx.currentTime + 0.1);
        } catch(e) {}
    }

    startBtn.addEventListener('click', () => {
        initAudio(); // Must be initialized inside user interaction
        document.getElementById('start-overlay').style.display = 'none';
        playArea.style.display = 'flex';
        startGame();
    });

    // Handle both click and touch for faster tapping without double firing
    const handleTap = (e) => {
        if (!isPlaying) return;
        e.preventDefault(); // prevent zoom / scroll
        
        score++;
        scoreCountEl.innerText = score;
        
        // Squish effect
        tapTarget.classList.remove('tapped');
        void tapTarget.offsetWidth; // trigger reflow
        tapTarget.classList.add('tapped');
        
        // Vibrate
        if (navigator.vibrate) navigator.vibrate(20);
        
        // SFX
        playCoinSound();
        
        // Spawn floating text
        spawnFloatingText(e);
    };

    tapTarget.addEventListener('touchstart', handleTap, {passive: false});
    tapTarget.addEventListener('mousedown', (e) => {
        if(e.sourceCapabilities && e.sourceCapabilities.firesTouchEvents) return; // avoid double fire
        handleTap(e);
    });
    
    tapTarget.addEventListener('touchend', () => tapTarget.classList.remove('tapped'));
    tapTarget.addEventListener('mouseup', () => tapTarget.classList.remove('tapped'));

    function startGame() {
        isPlaying = true;
        score = 0;
        timeLeft = 10.0;
        
        timerInterval = setInterval(() => {
            timeLeft -= 0.1;
            if (timeLeft <= 0) {
                timeLeft = 0;
                endGame();
            }
            timeLeftEl.innerText = timeLeft.toFixed(1);
        }, 100);
    }
    
    function endGame() {
        isPlaying = false;
        clearInterval(timerInterval);
        
        // Show result overlay
        document.getElementById('result-overlay').style.display = 'flex';
        document.getElementById('final-taps').innerText = score;
        
        // Submit score via AJAX
        submitScore(score);
    }
    
    function spawnFloatingText(e) {
        const text = document.createElement('img');
        text.className = 'floating-text';
        // Randomize dollar.png and dlr.png
        text.src = Math.random() > 0.5 ? '/assets/dollar.png' : '/assets/dlr.png';
        text.style.width = '40px';
        text.style.height = '40px';
        text.style.objectFit = 'contain';
        
        // Random slight rotation
        const rot = Math.floor(Math.random() * 60) - 30;
        text.style.transform = `rotate(${rot}deg)`;
        
        // Position
        let x, y;
        const rect = playArea.getBoundingClientRect();
        if (e.touches && e.touches.length > 0) {
            x = e.touches[0].clientX - rect.left;
            y = e.touches[0].clientY - rect.top;
        } else {
            x = e.clientX - rect.left;
            y = e.clientY - rect.top;
        }
        
        text.style.left = (x - 10) + 'px';
        text.style.top = (y - 20) + 'px';
        
        document.getElementById('floating-texts').appendChild(text);
        
        setTimeout(() => text.remove(), 800);
    }
    
    async function submitScore(finalScore) {
        try {
            const formData = new FormData();
            formData.append('action', 'claim');
            formData.append('clicks', finalScore);
            formData.append('_csrf', _csrf);
            
            const res = await fetch('', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();
            
            document.getElementById('reward-loading').style.display = 'none';
            document.getElementById('reward-success').style.display = 'block';
            
            if(data.success) {
                // format rp
                document.getElementById('reward-amount').innerText = 
                    'Rp ' + parseInt(data.reward).toLocaleString('id-ID');
            } else {
                document.getElementById('reward-amount').innerText = 'Gagal';
                if (typeof nToast === 'function') nToast(data.message || 'Terjadi kesalahan.', 'error');
            }
        } catch (err) {
            document.getElementById('reward-loading').style.display = 'none';
            document.getElementById('reward-success').style.display = 'block';
            document.getElementById('reward-amount').innerText = 'Error';
            
            // Debug the raw text in case of fatal HTML error
            if (typeof nToast === 'function') nToast('Terjadi kesalahan koneksi.', 'error');
        }
    }
});
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
