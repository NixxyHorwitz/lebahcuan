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

    // 1. Realistic Bee Buzz (Osilator ganda modulasi sayap lebah - louder & textured)
    playBee: function(duration = 1.0, pitch = 190) {
      if (isMuted) return;
      const ctx = getAudioContext();
      if (!ctx) return;

      try {
        const now = ctx.currentTime;
        const osc1 = ctx.createOscillator();
        const osc2 = ctx.createOscillator();
        const lfo = ctx.createOscillator();
        const lfoGain = ctx.createGain();
        const gainNode = ctx.createGain();
        const filter = ctx.createBiquadFilter();

        // Flutter modulation (kepakan sayap 160-240Hz dengan tremolo 24Hz)
        osc1.type = 'sawtooth';
        osc1.frequency.setValueAtTime(pitch, now);
        osc1.frequency.linearRampToValueAtTime(pitch * 1.15, now + duration * 0.4);
        osc1.frequency.linearRampToValueAtTime(pitch * 0.95, now + duration);

        osc2.type = 'triangle';
        osc2.frequency.setValueAtTime(pitch * 1.02, now);
        osc2.frequency.linearRampToValueAtTime(pitch * 1.18, now + duration * 0.4);
        osc2.frequency.linearRampToValueAtTime(pitch * 0.97, now + duration);

        lfo.type = 'sine';
        lfo.frequency.setValueAtTime(26, now);
        lfoGain.gain.setValueAtTime(pitch * 0.12, now);
        lfo.connect(lfoGain);
        lfoGain.connect(osc1.frequency);
        lfoGain.connect(osc2.frequency);

        filter.type = 'bandpass';
        filter.frequency.setValueAtTime(pitch * 1.8, now);
        filter.Q.setValueAtTime(3.2, now);

        // Louder, punchier gain envelope
        gainNode.gain.setValueAtTime(0.02, now);
        gainNode.gain.linearRampToValueAtTime(0.24, now + 0.12);
        gainNode.gain.linearRampToValueAtTime(0.22, now + duration - 0.2);
        gainNode.gain.exponentialRampToValueAtTime(0.001, now + duration);

        osc1.connect(filter);
        osc2.connect(filter);
        filter.connect(gainNode);
        gainNode.connect(ctx.destination);

        osc1.start(now);
        osc2.start(now);
        lfo.start(now);
        osc1.stop(now + duration);
        osc2.stop(now + duration);
        lfo.stop(now + duration);
      } catch (e) {}
    },

    // 2. Beekeeper Smoker Puff (Hembusan asap pengasap lebah)
    playSmoker: function() {
      if (isMuted) return;
      const ctx = getAudioContext();
      if (!ctx) return;
      try {
        const now = ctx.currentTime;
        const dur = 0.35;
        const bSize = ctx.sampleRate * dur;
        const buf = ctx.createBuffer(1, bSize, ctx.sampleRate);
        const data = buf.getChannelData(0);
        for (let i = 0; i < bSize; i++) data[i] = (Math.random() * 2 - 1);

        const src = ctx.createBufferSource();
        src.buffer = buf;
        const filter = ctx.createBiquadFilter();
        filter.type = 'bandpass';
        filter.frequency.setValueAtTime(650, now);
        filter.frequency.exponentialRampToValueAtTime(300, now + dur);
        filter.Q.setValueAtTime(1.8, now);

        const gain = ctx.createGain();
        gain.gain.setValueAtTime(0.01, now);
        gain.gain.linearRampToValueAtTime(0.26, now + 0.05);
        gain.gain.exponentialRampToValueAtTime(0.001, now + dur);

        src.connect(filter);
        filter.connect(gain);
        gain.connect(ctx.destination);
        src.start(now);
        src.stop(now + dur);
      } catch (e) {}
    },

    // 3. Honey Harvest Drop Plop (Kombinasi asap + tuangan madu kental)
    playHarvest: function() {
      if (isMuted) return;
      this.playSmoker();
      const ctx = getAudioContext();
      if (!ctx) return;

      try {
        const now = ctx.currentTime + 0.12;
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();

        osc.type = 'sine';
        osc.frequency.setValueAtTime(720, now);
        osc.frequency.exponentialRampToValueAtTime(260, now + 0.22);

        gain.gain.setValueAtTime(0.3, now);
        gain.gain.exponentialRampToValueAtTime(0.001, now + 0.25);

        osc.connect(gain);
        gain.connect(ctx.destination);

        osc.start(now);
        osc.stop(now + 0.25);

        // Suara gemerincing madu berkilau (sparkle chime)
        setTimeout(() => {
          if (isMuted) return;
          try {
            [1046, 1318, 1568, 2093].forEach((f, idx) => {
              const osc2 = ctx.createOscillator();
              const gain2 = ctx.createGain();
              const t = ctx.currentTime + (idx * 0.06);
              osc2.type = 'triangle';
              osc2.frequency.setValueAtTime(f, t);
              gain2.gain.setValueAtTime(0.18, t);
              gain2.gain.exponentialRampToValueAtTime(0.001, t + 0.25);
              osc2.connect(gain2);
              gain2.connect(ctx.destination);
              osc2.start(t);
              osc2.stop(t + 0.25);
            });
          } catch(e) {}
        }, 120);
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
