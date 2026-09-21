<?php
declare(strict_types=1);

/**
 * farm/partials/farm_header.php
 * Reusable Tycoon Header & Navigation for the Modular Farm System
 */
if (!isset($pdo) || !isset($user)) {
    exit('Direct access not permitted.');
}

// Current active sub-page
$farmSubPage = $farmSubPage ?? 'meadow';
?>

<!-- ── SFX SYNTHESIZER ENGINE (Web Audio API) ── -->
<script>
(function() {
  let audioCtx = null;
  let isMuted = localStorage.getItem('farm_sfx_muted') === 'true';

  function getAudioContext() {
    if (!audioCtx) {
      const AudioContext = window.AudioContext || window.webkitAudioContext;
      if (AudioContext) {
        audioCtx = new AudioContext();
      }
    }
    if (audioCtx && audioCtx.state === 'suspended') {
      audioCtx.resume();
    }
    return audioCtx;
  }

  window.FarmAudio = {
    isMuted: function() { return isMuted; },
    toggle: function() {
      isMuted = !isMuted;
      localStorage.setItem('farm_sfx_muted', isMuted ? 'true' : 'false');
      const btn = document.getElementById('btnAudioToggle');
      if (btn) {
        btn.innerHTML = isMuted 
          ? '<i class="ph-bold ph-speaker-simple-slash"></i> <span class="d-none d-sm-inline">Bisu</span>'
          : '<i class="ph-fill ph-speaker-high"></i> <span class="d-none d-sm-inline">SFX On</span>';
        btn.classList.toggle('btn-muted', isMuted);
      }
      if (!isMuted) {
        this.playPop();
      }
      return !isMuted;
    },

    // 1. Realistic Bee Buzz (Osilator ganda modulasi sayap lebah)
    playBee: function(duration = 0.8) {
      if (isMuted) return;
      const ctx = getAudioContext();
      if (!ctx) return;

      try {
        const now = ctx.currentTime;
        // Carrier oscillator
        const osc1 = ctx.createOscillator();
        const osc2 = ctx.createOscillator();
        const gainNode = ctx.createGain();
        const filter = ctx.createBiquadFilter();

        // Tremolo / flutter (kecepatan kepakan sayap ~150-200Hz)
        osc1.type = 'sawtooth';
        osc1.frequency.setValueAtTime(185, now);
        osc1.frequency.linearRampToValueAtTime(195, now + duration * 0.5);
        osc1.frequency.linearRampToValueAtTime(180, now + duration);

        osc2.type = 'triangle';
        osc2.frequency.setValueAtTime(187, now);
        osc2.frequency.linearRampToValueAtTime(192, now + duration * 0.5);
        osc2.frequency.linearRampToValueAtTime(182, now + duration);

        filter.type = 'bandpass';
        filter.frequency.setValueAtTime(320, now);
        filter.Q.setValueAtTime(2.5, now);

        gainNode.gain.setValueAtTime(0.01, now);
        gainNode.gain.linearRampToValueAtTime(0.08, now + 0.1);
        gainNode.gain.linearRampToValueAtTime(0.07, now + duration - 0.15);
        gainNode.gain.exponentialRampToValueAtTime(0.001, now + duration);

        osc1.connect(filter);
        osc2.connect(filter);
        filter.connect(gainNode);
        gainNode.connect(ctx.destination);

        osc1.start(now);
        osc2.start(now);
        osc1.stop(now + duration);
        osc2.stop(now + duration);
      } catch (e) {}
    },

    // 2. Honey Harvest Drop Plop (Cipratan madu pekat & renyah)
    playHarvest: function() {
      if (isMuted) return;
      const ctx = getAudioContext();
      if (!ctx) return;

      try {
        const now = ctx.currentTime;
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();

        osc.type = 'sine';
        // Pitch sweep meluncur ke bawah menyerupai tetesan kental
        osc.frequency.setValueAtTime(650, now);
        osc.frequency.exponentialRampToValueAtTime(220, now + 0.14);

        gain.gain.setValueAtTime(0.2, now);
        gain.gain.exponentialRampToValueAtTime(0.001, now + 0.18);

        osc.connect(gain);
        gain.connect(ctx.destination);

        osc.start(now);
        osc.stop(now + 0.18);

        // Sedikit nada tinggi susulan untuk efek berkilau
        setTimeout(() => {
          if (isMuted) return;
          try {
            const osc2 = ctx.createOscillator();
            const gain2 = ctx.createGain();
            const t = ctx.currentTime;
            osc2.type = 'triangle';
            osc2.frequency.setValueAtTime(880, t);
            osc2.frequency.exponentialRampToValueAtTime(1200, t + 0.1);
            gain2.gain.setValueAtTime(0.12, t);
            gain2.gain.exponentialRampToValueAtTime(0.001, t + 0.12);
            osc2.connect(gain2);
            gain2.connect(ctx.destination);
            osc2.start(t);
            osc2.stop(t + 0.12);
          } catch(e) {}
        }, 60);
      } catch (e) {}
    },

    // 3. Cash Register / Coin Chime (Gemerincing koin penjualan madu)
    playCoin: function() {
      if (isMuted) return;
      const ctx = getAudioContext();
      if (!ctx) return;

      try {
        const now = ctx.currentTime;
        [987.77, 1318.51, 1975.53].forEach((freq, idx) => {
          const osc = ctx.createOscillator();
          const gain = ctx.createGain();
          const t = now + (idx * 0.08);

          osc.type = 'sine';
          osc.frequency.setValueAtTime(freq, t);

          gain.gain.setValueAtTime(0.18, t);
          gain.gain.exponentialRampToValueAtTime(0.001, t + 0.35);

          osc.connect(gain);
          gain.connect(ctx.destination);

          osc.start(t);
          osc.stop(t + 0.35);
        });
      } catch (e) {}
    },

    // 4. Cheerful Click / Pop
    playPop: function() {
      if (isMuted) return;
      const ctx = getAudioContext();
      if (!ctx) return;

      try {
        const now = ctx.currentTime;
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();

        osc.type = 'sine';
        osc.frequency.setValueAtTime(400, now);
        osc.frequency.exponentialRampToValueAtTime(800, now + 0.08);

        gain.gain.setValueAtTime(0.12, now);
        gain.gain.exponentialRampToValueAtTime(0.001, now + 0.09);

        osc.connect(gain);
        gain.connect(ctx.destination);

        osc.start(now);
        osc.stop(now + 0.09);
      } catch(e) {}
    }
  };
})();
</script>

<!-- ── STYLING TYCOON HEADER & SUB-NAV ── -->
<style>
/* Tycoon Master Bar */
.tycoon-top-bar {
  background: linear-gradient(135deg, #f59e0b 0%, #d97706 60%, #b45309 100%);
  padding: 14px 14px 12px;
  border-bottom: 3.5px solid #78350f;
  box-shadow: 0 4px 16px rgba(180,83,9,0.25);
  position: relative;
  z-index: 20;
}
.tycoon-top-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 12px;
}
.tycoon-brand {
  display: flex;
  align-items: center;
  gap: 8px;
  text-decoration: none;
}
.tycoon-brand__icon {
  width: 38px; height: 38px;
  background: #fff;
  border: 2.5px solid #78350f;
  border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 3px 0 #78350f;
  flex-shrink: 0;
}
.tycoon-brand__icon img {
  width: 26px; height: 26px; object-fit: contain;
}
.tycoon-brand__title {
  font-size: 16px; font-weight: 900; color: #fff;
  line-height: 1.1; text-shadow: 0 2px 0 #78350f;
}
.tycoon-brand__sub {
  font-size: 10px; font-weight: 800; color: #fef3c7;
}

/* Audio & Back Action */
.tycoon-top-actions {
  display: flex;
  align-items: center;
  gap: 6px;
}
.tycoon-btn-ctrl {
  background: #fff;
  border: 2.5px solid #78350f;
  border-radius: 12px;
  padding: 6px 10px;
  font-size: 11px; font-weight: 900;
  color: #78350f;
  display: inline-flex; align-items: center; gap: 5px;
  box-shadow: 0 3px 0 #78350f;
  text-decoration: none;
  cursor: pointer;
  transition: transform 0.1s;
}
.tycoon-btn-ctrl:active {
  transform: translateY(2px);
  box-shadow: 0 1px 0 #78350f;
}
.tycoon-btn-ctrl.btn-muted {
  background: #fee2e2;
  color: #991b1b;
  border-color: #991b1b;
  box-shadow: 0 3px 0 #991b1b;
}

/* 3-Pillar Balances Card (Deposit, WD, Honey) */
.tycoon-stats-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 8px;
  margin-bottom: 12px;
}
.tycoon-stat-box {
  background: #ffffff;
  border: 2.5px solid #78350f;
  border-radius: 14px;
  padding: 7px 6px;
  box-shadow: 0 3px 0 #78350f;
  text-align: center;
}
.tycoon-stat-box__lbl {
  font-size: 9px; font-weight: 900;
  text-transform: uppercase;
  color: #64748b;
  margin-bottom: 2px;
}
.tycoon-stat-box__val {
  font-size: 12.5px; font-weight: 900;
  line-height: 1.1;
}
.tycoon-stat-box__val--dep { color: #1d4ed8; }
.tycoon-stat-box__val--wd  { color: #059669; }
.tycoon-stat-box__val--honey { color: #d97706; }

/* Sub-Nav Tycoon Tabs (Modular Pages) */
.tycoon-nav-tabs {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 6px;
}
.tycoon-tab {
  background: rgba(255, 255, 255, 0.25);
  border: 2px solid rgba(255, 255, 255, 0.4);
  border-radius: 12px;
  padding: 8px 4px;
  text-decoration: none;
  text-align: center;
  color: #fff;
  font-size: 11px; font-weight: 900;
  display: flex; flex-direction: column; align-items: center; gap: 3px;
  transition: all 0.15s ease;
}
.tycoon-tab i {
  font-size: 18px;
}
.tycoon-tab:active {
  transform: translateY(2px);
}
.tycoon-tab.active {
  background: #fff;
  color: #78350f;
  border: 2.5px solid #78350f;
  box-shadow: 0 3px 0 #78350f;
  transform: translateY(-2px);
}
.tycoon-tab.active i {
  color: #d97706;
}
</style>

<!-- ── TYCOON MASTER TOP BAR ── -->
<div class="tycoon-top-bar">
  <div class="tycoon-top-row">
    <a href="/farm" class="tycoon-brand">
      <div class="tycoon-brand__icon">
        <img src="/assets/game/bee_queen.png" onerror="this.src='/assets/game/bee_worker.png'" alt="Tycoon">
      </div>
      <div>
        <div class="tycoon-brand__title">Peternakan Lebah Cuan 🍯</div>
        <div class="tycoon-brand__sub">Simulator Penghasil Pasif</div>
      </div>
    </a>

    <div class="tycoon-top-actions">
      <!-- SFX Audio Toggle Button -->
      <button type="button" id="btnAudioToggle" class="tycoon-btn-ctrl" onclick="FarmAudio.toggle()">
        <i class="ph-fill ph-speaker-high"></i>
        <span class="d-none d-sm-inline">SFX On</span>
      </button>

      <!-- Back to Main Home -->
      <a href="/home" class="tycoon-btn-ctrl" title="Kembali ke Beranda">
        <i class="ph-bold ph-house"></i>
        <span class="d-none d-sm-inline">Home</span>
      </a>
    </div>
  </div>

  <!-- Real-Money Balances & Honey HUD -->
  <div class="tycoon-stats-grid">
    <div class="tycoon-stat-box">
      <div class="tycoon-stat-box__lbl">Saldo Beli</div>
      <div class="tycoon-stat-box__val tycoon-stat-box__val--dep" id="hudBalDep">
        Rp <?= number_format((float)$user['balance_dep'], 0, ',', '.') ?>
      </div>
      <a href="/deposit" style="font-size:9.5px;font-weight:900;color:#2563eb;text-decoration:none;display:inline-block;margin-top:2px;">
        + Top Up
      </a>
    </div>

    <div class="tycoon-stat-box">
      <div class="tycoon-stat-box__lbl">Saldo Tarik</div>
      <div class="tycoon-stat-box__val tycoon-stat-box__val--wd" id="hudBalWd">
        Rp <?= number_format((float)$user['balance_wd'], 0, ',', '.') ?>
      </div>
      <a href="/withdraw" style="font-size:9.5px;font-weight:900;color:#059669;text-decoration:none;display:inline-block;margin-top:2px;">
        &uarr; Tarik
      </a>
    </div>

    <div class="tycoon-stat-box">
      <div class="tycoon-stat-box__lbl">Stok Madu</div>
      <div class="tycoon-stat-box__val tycoon-stat-box__val--honey" id="hudHoneyStock">
        <?= number_format((float)$user['honey_stock'], 1, ',', '.') ?> ml
      </div>
      <span style="font-size:9.5px;font-weight:800;color:#92400e;display:inline-block;margin-top:2px;">
        Siap Jual
      </span>
    </div>
  </div>

  <!-- Sub-Nav Tycoon Modular Pages -->
  <nav class="tycoon-nav-tabs">
    <a href="/farm" class="tycoon-tab <?= $farmSubPage === 'meadow' ? 'active' : '' ?>">
      <i class="ph-fill ph-plant"></i>
      <span>Kebun</span>
    </a>
    <a href="/farm/stall" class="tycoon-tab <?= $farmSubPage === 'stall' ? 'active' : '' ?>">
      <i class="ph-fill ph-storefront"></i>
      <span>Lapak Madu</span>
    </a>
    <a href="/farm/shop" class="tycoon-tab <?= $farmSubPage === 'shop' ? 'active' : '' ?>">
      <i class="ph-fill ph-shopping-bag-open"></i>
      <span>Toko Bibit</span>
    </a>
    <a href="/farm/logs" class="tycoon-tab <?= $farmSubPage === 'logs' ? 'active' : '' ?>">
      <i class="ph-fill ph-receipt"></i>
      <span>Riwayat</span>
    </a>
  </nav>
</div>

<script>
// Sync initial audio button state
document.addEventListener('DOMContentLoaded', function() {
  const isMuted = localStorage.getItem('farm_sfx_muted') === 'true';
  const btn = document.getElementById('btnAudioToggle');
  if (btn && isMuted) {
    btn.innerHTML = '<i class="ph-bold ph-speaker-simple-slash"></i> <span class="d-none d-sm-inline">Bisu</span>';
    btn.classList.add('btn-muted');
  }
});
</script>
