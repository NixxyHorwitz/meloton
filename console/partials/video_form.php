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
<div class="row g-2">
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
