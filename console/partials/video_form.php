<div class="c-form-group mb-3">
  <label class="c-label">Judul Video</label>
  <input type="text" name="title" class="c-form-control" placeholder="Judul video yang menarik" required>
</div>
<div class="c-form-group mb-3">
  <label class="c-label">YouTube URL / ID</label>
  <input type="text" name="youtube_url" class="c-form-control" placeholder="https://youtube.com/watch?v=xxx atau ID saja" required>
  <div style="font-size:11px;color:#666;margin-top:4px">Support: youtube.com/watch?v=, youtu.be/, atau ID langsung</div>
</div>
<div class="row g-2 mb-3">
  <div class="col-6">
    <label class="c-label">Reward (Rp)</label>
    <input type="number" name="reward_amount" class="c-form-control" value="100" min="1" step="any" required>
  </div>
  <div class="col-6">
    <label class="c-label">Durasi Minimum (detik)</label>
    <input type="number" name="watch_duration" class="c-form-control" value="30" min="5" required>
  </div>
</div>
<div class="row g-2 mb-3">
  <div class="col-6">
    <label class="c-label">Urutan Tampil</label>
    <input type="number" name="sort_order" class="c-form-control" value="0">
  </div>
  <div class="col-6 d-flex align-items-end pb-1">
    <div class="form-check ms-2">
      <input class="form-check-input" type="checkbox" name="is_active" id="is_active_add" checked>
      <label class="form-check-label text-secondary" for="is_active_add" style="font-size:13px">Aktif</label>
    </div>
  </div>
</div>
<div class="p-3 mb-2 rounded" style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08)">
  <div class="d-flex align-items-center justify-content-between mb-2">
    <label class="c-label mb-0 fw-bold" style="font-size:12px;color:#fbbf24">👍 Setting Fake Likes Awal</label>
    <span class="badge" style="background:rgba(251,191,36,0.15);color:#fbbf24;font-size:10px">Acak Range Min-Max</span>
  </div>
  <div class="row g-2">
    <div class="col-6">
      <label class="c-label" style="font-size:11px">Min Fake Likes</label>
      <input type="number" name="fake_likes_min" class="c-form-control form-control-sm" value="50" min="0" required>
    </div>
    <div class="col-6">
      <label class="c-label" style="font-size:11px">Max Fake Likes</label>
      <input type="number" name="fake_likes_max" class="c-form-control form-control-sm" value="250" min="0" required>
    </div>
  </div>
  <div style="font-size:10.5px;color:#94a3b8;margin-top:4px">
    Sistem akan mengacak jumlah like awal antara nilai min & max (isi angka sama jika ingin jumlah pasti).
  </div>
</div>
