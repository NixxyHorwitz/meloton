<div class="row g-2 mb-3">
  <div class="col-8">
    <label class="c-label">Nama Paket</label>
    <input type="text" name="name" class="c-form-control" placeholder="Contoh: Gold" required>
  </div>
  <div class="col-4">
    <label class="c-label">Icon (Emoji)</label>
    <input type="text" name="icon" class="c-form-control" value="⭐" required>
  </div>
</div>
<div class="row g-2 mb-3">
  <div class="col-6">
    <label class="c-label">Harga Jual (Rp)</label>
    <input type="number" name="price" class="c-form-control" value="0" min="0" step="1">
  </div>
  <div class="col-6">
    <label class="c-label">Harga Asli/Coret (Rp)</label>
    <input type="number" name="original_price" class="c-form-control" value="0" min="0" step="1">
  </div>
  <div class="col-6">
    <label class="c-label">Limit Tonton/Hari</label>
    <input type="number" name="watch_limit" class="c-form-control" value="10" min="1">
  </div>
</div>
<div class="row g-2 mb-3">
  <div class="col-6">
    <label class="c-label">Durasi (hari)</label>
    <input type="number" name="duration_days" class="c-form-control" value="30" min="1">
  </div>
  <div class="col-6">
    <label class="c-label">Urutan Tampil</label>
    <input type="number" name="sort_order" class="c-form-control" value="0">
  </div>
</div>
<div class="row g-2 mb-3">
  <div class="col-6">
    <label class="c-label">Min. Withdraw (Rp)</label>
    <input type="number" name="min_wd" class="c-form-control" value="50000" min="0" step="1">
  </div>
  <div class="col-6">
    <label class="c-label">Max. Withdraw (Rp)</label>
    <input type="number" name="max_wd" class="c-form-control" value="0" min="0" step="1">
    <small style="font-size:10px;color:#888">0 = Tanpa batas max</small>
  </div>
</div>
<div class="c-form-group mb-3">
  <label class="c-label">Deskripsi Singkat (Bisa multi-baris)</label>
  <textarea name="description" class="c-form-control" rows="3" placeholder="Opsional"></textarea>
</div>
<div class="form-check ms-1 mb-2">
  <input class="form-check-input" type="checkbox" name="wd_hold" id="plan_wd_hold_add">
  <label class="form-check-label text-warning fw-bold" for="plan_wd_hold_add" style="font-size:13px">Tahan Withdraw (Auto Hold)</label>
</div>
<div class="form-check ms-1 mb-2">
  <input class="form-check-input" type="checkbox" name="allow_edit_bank" id="plan_allow_edit_bank_add">
  <label class="form-check-label text-info fw-bold" for="plan_allow_edit_bank_add" style="font-size:13px">✏️ Izinkan Edit Rekening Bank</label>
  <div style="font-size:10px;color:#888;margin-top:2px">User di level ini bisa edit rekening (dengan syarat saldo beli)</div>
</div>
<div class="form-check ms-1">
  <input class="form-check-input" type="checkbox" name="is_active" id="plan_active_add" checked>
  <label class="form-check-label text-secondary" for="plan_active_add" style="font-size:13px">Aktif</label>
</div>
