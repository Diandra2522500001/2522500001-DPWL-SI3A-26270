<?php
require APPPATH . '/views/templates/header.php';
require APPPATH . '/views/templates/sidebar.php';

$e = static function ($value): string {
  return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$penyewa = $penyewa ?? [];
$flash = $flash ?? null;
$flashClass = (($flash['type'] ?? '') === 'success') ? 'alert-success' : 'alert-danger';
$error = $error ?? null;

$mode = $mode ?? null;
$penyewa_dipilih = $penyewa_dipilih ?? null;

$isForm = in_array($mode, ['tambah', 'ubah'], true);
$isEdit = $mode === 'ubah';
$userDipilih = $penyewa_dipilih['user_plg'] ?? '';

$formAction = $isEdit
  ? site_url('admin/penyewa/ubah/' . rawurlencode((string) $userDipilih))
  : site_url('admin/penyewa/tambah');

// Ringkasan untuk kotak statistik.
$total = count($penyewa);
$lengkap = 0;
foreach ($penyewa as $p) {
  if (!empty($p['ft_ktp']) && !empty($p['ft_sim_a'])) {
    $lengkap++;
  }
}
$belumLengkap = $total - $lengkap;

$warnaAvatar = ['bg-aqua', 'bg-green', 'bg-yellow', 'bg-purple', 'bg-red', 'bg-teal', 'bg-maroon'];
?>

<style>
  .pw-avatar {
    width: 42px; height: 42px; border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 18px; font-weight: 700; color: #fff; flex: 0 0 42px;
  }
  .pw-person { display: flex; align-items: center; gap: 10px; }
  .pw-person .pw-name { font-weight: 600; line-height: 1.2; }
  .pw-person .pw-user { color: #8a8a8a; font-size: 12px; }
  .pw-meta i { width: 16px; color: #999; }
  .pw-docs { display: flex; gap: 8px; }
  .pw-doc { text-align: center; font-size: 11px; color: #777; }
  .pw-doc img, .pw-doc .pw-empty {
    width: 70px; height: 46px; object-fit: cover; border-radius: 4px;
    border: 1px solid #ddd; display: block; margin-bottom: 2px;
  }
  .pw-doc .pw-empty {
    background: repeating-linear-gradient(45deg, #f6f6f6, #f6f6f6 6px, #efefef 6px, #efefef 12px);
    line-height: 46px; color: #b5b5b5;
  }
  .pw-table > tbody > tr > td { vertical-align: middle !important; }
  .pw-form .box-header { background: #00a65a; color: #fff; }
  .pw-form .box-header .box-title { color: #fff; }
  .pw-section-title {
    font-size: 13px; text-transform: uppercase; letter-spacing: .06em;
    color: #8a8a8a; border-bottom: 1px solid #eee; padding-bottom: 6px; margin: 0 0 15px;
  }
  .pw-upload {
    border: 2px dashed #d2d6de; border-radius: 6px; padding: 12px; margin-bottom: 15px;
    background: #fafafa;
  }
  .pw-upload img { width: 100%; max-width: 220px; height: 130px; object-fit: cover; border-radius: 4px; }
</style>

<!-- Content Wrapper -->
<div class="content-wrapper">
  <section class="content-header">
    <h1>
      <i class="fa fa-address-card-o"></i> Manajemen Penyewa
      <small>Data akun dan dokumen penyewa</small>
    </h1>
    <ol class="breadcrumb">
      <li><a href="<?= $e(site_url('admin')) ?>"><i class="fa fa-dashboard"></i> Dashboard</a></li>
      <li class="active">Manajemen Penyewa</li>
    </ol>
  </section>

  <section class="content">

    <?php if (!empty($flash)): ?>
      <div class="alert <?= $flashClass ?> alert-dismissible">
        <button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>
        <i class="fa <?= (($flash['type'] ?? '') === 'success') ? 'fa-check' : 'fa-warning' ?>"></i>
        <?= $e($flash['message'] ?? '') ?>
      </div>
    <?php endif; ?>

    <?php if ($error !== null): ?>
      <div class="alert alert-danger alert-dismissible">
        <button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>
        <i class="fa fa-warning"></i>
        <?= $e($error) ?>
      </div>
    <?php endif; ?>

    <!-- Kotak ringkasan -->
    <div class="row">
      <div class="col-md-4 col-sm-6 col-xs-12">
        <div class="info-box">
          <span class="info-box-icon bg-aqua"><i class="fa fa-users"></i></span>
          <div class="info-box-content">
            <span class="info-box-text">Total Penyewa</span>
            <span class="info-box-number"><?= (int) $total ?></span>
          </div>
        </div>
      </div>
      <div class="col-md-4 col-sm-6 col-xs-12">
        <div class="info-box">
          <span class="info-box-icon bg-green"><i class="fa fa-check-circle"></i></span>
          <div class="info-box-content">
            <span class="info-box-text">Dokumen Lengkap</span>
            <span class="info-box-number"><?= (int) $lengkap ?></span>
          </div>
        </div>
      </div>
      <div class="col-md-4 col-sm-12 col-xs-12">
        <div class="info-box">
          <span class="info-box-icon bg-yellow"><i class="fa fa-exclamation-circle"></i></span>
          <div class="info-box-content">
            <span class="info-box-text">Dokumen Belum Lengkap</span>
            <span class="info-box-number"><?= (int) $belumLengkap ?></span>
          </div>
        </div>
      </div>
    </div>

    <?php if (!$isForm): ?>
      <!-- Tabel penyewa -->
      <div class="box box-info">
        <div class="box-header with-border">
          <h3 class="box-title"><i class="fa fa-list"></i> Daftar Penyewa</h3>
          <div class="box-tools pull-right">
            <a href="<?= $e(site_url('admin/penyewa/tambah')) ?>" class="btn btn-success btn-sm">
              <i class="fa fa-user-plus"></i> Penyewa Baru
            </a>
          </div>
        </div>

        <div class="box-body">
          <div class="table-responsive">
            <table id="penyewaTable" class="table table-hover pw-table js-data-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Penyewa</th>
                  <th>Kontak</th>
                  <th>Alamat</th>
                  <th class="no-sort">Dokumen</th>
                  <th class="no-sort text-center">Aksi</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($penyewa as $no => $item): ?>
                  <?php
                  $nama = (string) $item['nm_lngkp'];
                  $inisial = mb_strtoupper(mb_substr($nama !== '' ? $nama : $item['user_plg'], 0, 1, 'UTF-8'), 'UTF-8');
                  $warna = $warnaAvatar[abs(crc32((string) $item['user_plg'])) % count($warnaAvatar)];
                  $docLengkap = !empty($item['ft_ktp']) && !empty($item['ft_sim_a']);
                  ?>
                  <tr>
                    <td><?= (int) $no + 1 ?></td>
                    <td>
                      <div class="pw-person">
                        <span class="pw-avatar <?= $warna ?>"><?= $e($inisial) ?></span>
                        <div>
                          <div class="pw-name"><?= $e($nama) ?></div>
                          <div class="pw-user">@<?= $e($item['user_plg']) ?></div>
                        </div>
                      </div>
                    </td>
                    <td class="pw-meta">
                      <div><i class="fa fa-id-card-o"></i> <?= $e($item['nik']) ?></div>
                      <div><i class="fa fa-phone"></i> <?= $e($item['no_hp']) ?></div>
                    </td>
                    <td style="max-width: 220px;"><?= $e($item['almt_lngkp']) ?></td>
                    <td>
                      <div class="pw-docs">
                        <?php foreach (['ft_ktp' => 'KTP', 'ft_sim_a' => 'SIM A'] as $field => $label): ?>
                          <div class="pw-doc">
                            <?php if (!empty($item[$field])): ?>
                              <?php $src = base_url('uploads/penyewa/' . rawurlencode($item[$field])); ?>
                              <a href="<?= $e($src) ?>" target="_blank" rel="noopener">
                                <img src="<?= $e($src) ?>" alt="Foto <?= $e($label) ?> <?= $e($nama) ?>">
                              </a>
                            <?php else: ?>
                              <span class="pw-empty"><i class="fa fa-image"></i></span>
                            <?php endif; ?>
                            <?= $e($label) ?>
                          </div>
                        <?php endforeach; ?>
                      </div>
                      <span class="label <?= $docLengkap ? 'label-success' : 'label-warning' ?>" style="display:inline-block;margin-top:4px;">
                        <?= $docLengkap ? 'Lengkap' : 'Belum lengkap' ?>
                      </span>
                    </td>
                    <td class="text-center" style="white-space: nowrap;">
                      <a class="btn btn-default btn-sm" title="Ubah"
                        href="<?= $e(site_url('admin/penyewa/ubah/' . rawurlencode((string) $item['user_plg']))) ?>">
                        <i class="fa fa-pencil text-orange"></i>
                      </a>
                      <form method="post" style="display:inline"
                        action="<?= $e(site_url('admin/penyewa/hapus/' . rawurlencode((string) $item['user_plg']))) ?>"
                        onsubmit="return confirm('Hapus penyewa <?= $e($item['user_plg']) ?>? Data dan foto terkait akan dihapus.');">
                        <button type="submit" class="btn btn-default btn-sm" title="Hapus">
                          <i class="fa fa-trash text-red"></i>
                        </button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($isForm): ?>
      <!-- Form tambah/ubah -->
      <form role="form" method="post" enctype="multipart/form-data" autocomplete="off"
        action="<?= $e($formAction) ?>">
        <div class="box box-success pw-form">
          <div class="box-header with-border">
            <h3 class="box-title">
              <i class="fa <?= $isEdit ? 'fa-pencil-square-o' : 'fa-user-plus' ?>"></i>
              <?= $isEdit ? 'Ubah Data Penyewa' : 'Tambah Penyewa Baru' ?>
            </h3>
          </div>

          <div class="box-body">
            <div class="row">
              <!-- Kolom kiri: akun & identitas -->
              <div class="col-md-7">
                <h4 class="pw-section-title">Akun &amp; Identitas</h4>

                <div class="form-group">
                  <label for="user_plg">Username</label>
                  <div class="input-group">
                    <span class="input-group-addon"><i class="fa fa-at"></i></span>
                    <input type="text" class="form-control" id="user_plg" name="user_plg"
                      maxlength="15" pattern="[A-Za-z0-9._\-]{1,15}"
                      value="<?= $e($penyewa_dipilih['user_plg'] ?? '') ?>"
                      <?= $isEdit ? 'readonly' : '' ?> required>
                  </div>
                  <p class="help-block">Maks. 15 karakter: huruf, angka, titik, garis bawah, atau tanda hubung.</p>
                </div>

                <div class="row">
                  <div class="col-sm-6">
                    <div class="form-group">
                      <label for="sandi">Password <?= $isEdit ? '(opsional)' : '' ?></label>
                      <div class="input-group">
                        <span class="input-group-addon"><i class="fa fa-lock"></i></span>
                        <input type="password" class="form-control" id="sandi" name="sandi"
                          minlength="8" maxlength="72" autocomplete="new-password"
                          <?= $isEdit ? '' : 'required' ?>>
                      </div>
                    </div>
                  </div>
                  <div class="col-sm-6">
                    <div class="form-group">
                      <label for="konfirmasi_sandi">Ulangi Password</label>
                      <div class="input-group">
                        <span class="input-group-addon"><i class="fa fa-lock"></i></span>
                        <input type="password" class="form-control" id="konfirmasi_sandi" name="konfirmasi_sandi"
                          minlength="8" maxlength="72" autocomplete="new-password"
                          <?= $isEdit ? '' : 'required' ?>>
                      </div>
                    </div>
                  </div>
                </div>
                <p class="help-block" style="margin-top:-8px;">
                  <?= $isEdit
                    ? 'Kosongkan jika password tidak ingin diganti.'
                    : 'Minimal 8 karakter, disimpan dalam bentuk hash.' ?>
                </p>

                <div class="form-group">
                  <label for="nik">NIK</label>
                  <div class="input-group">
                    <span class="input-group-addon"><i class="fa fa-id-card-o"></i></span>
                    <input type="text" class="form-control" id="nik" name="nik"
                      maxlength="16" minlength="16" inputmode="numeric" pattern="[0-9]{16}"
                      value="<?= $e($penyewa_dipilih['nik'] ?? '') ?>" required>
                  </div>
                  <p class="help-block">Tepat 16 digit angka.</p>
                </div>

                <div class="row">
                  <div class="col-sm-7">
                    <div class="form-group">
                      <label for="nm_lngkp">Nama Lengkap</label>
                      <div class="input-group">
                        <span class="input-group-addon"><i class="fa fa-user"></i></span>
                        <input type="text" class="form-control" id="nm_lngkp" name="nm_lngkp"
                          maxlength="100" value="<?= $e($penyewa_dipilih['nm_lngkp'] ?? '') ?>" required>
                      </div>
                    </div>
                  </div>
                  <div class="col-sm-5">
                    <div class="form-group">
                      <label for="no_hp">Nomor HP</label>
                      <div class="input-group">
                        <span class="input-group-addon"><i class="fa fa-phone"></i></span>
                        <input type="text" class="form-control" id="no_hp" name="no_hp"
                          maxlength="15" value="<?= $e($penyewa_dipilih['no_hp'] ?? '') ?>" required>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="form-group">
                  <label for="almt_lngkp">Alamat Lengkap</label>
                  <textarea class="form-control" id="almt_lngkp" name="almt_lngkp"
                    maxlength="300" rows="3" required><?= $e($penyewa_dipilih['almt_lngkp'] ?? '') ?></textarea>
                </div>
              </div>

              <!-- Kolom kanan: dokumen -->
              <div class="col-md-5">
                <h4 class="pw-section-title">Dokumen Penyewa</h4>

                <?php foreach (['ft_ktp' => 'Foto KTP', 'ft_sim_a' => 'Foto SIM A'] as $field => $label): ?>
                  <div class="pw-upload form-group">
                    <label for="<?= $e($field) ?>"><i class="fa fa-camera"></i> <?= $e($label) ?></label>
                    <input type="file" id="<?= $e($field) ?>" name="<?= $e($field) ?>"
                      accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                    <p class="help-block" style="margin-bottom:8px;">JPG, PNG, atau WEBP, maks. 5 MB.</p>

                    <?php if (!empty($penyewa_dipilih[$field]) && is_string($penyewa_dipilih[$field])): ?>
                      <p class="help-block current-photo-label" style="margin-bottom:4px;">Foto saat ini:</p>
                      <img class="current-photo"
                        src="<?= $e(base_url('uploads/penyewa/' . rawurlencode($penyewa_dipilih[$field]))) ?>"
                        alt="<?= $e($label) ?> saat ini">
                      <p class="help-block current-photo-name">Kosongkan jika tidak ingin mengganti.</p>
                    <?php else: ?>
                      <p class="help-block current-photo-label">Belum ada foto tersimpan.</p>
                    <?php endif; ?>

                    <div class="new-photo-preview" style="display: none; margin-top: 6px;">
                      <p class="help-block" style="margin-bottom:4px;">Pratinjau foto baru:</p>
                      <img class="new-photo-image" alt="Pratinjau <?= $e($label) ?>">
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <div class="box-footer text-right">
            <a href="<?= $e(site_url('admin/penyewa')) ?>" class="btn btn-default">
              <i class="fa fa-arrow-left"></i> Kembali
            </a>
            <button type="submit" class="btn btn-success">
              <i class="fa fa-save"></i> Simpan Data
            </button>
          </div>
        </div>
      </form>
    <?php endif; ?>

  </section>
</div>

<?php
require APPPATH . '/views/templates/footer.php';
?>

<?php if ($isForm): ?>
  <script>
    (function() {
      'use strict';

      document.querySelectorAll('.pw-upload input[type="file"][name^="ft_"]').forEach(function(input) {
        var container = input.closest('.pw-upload');
        var preview = container ? container.querySelector('.new-photo-preview') : null;
        var image = preview ? preview.querySelector('.new-photo-image') : null;
        var previousUrl = null;

        if (!preview || !image) {
          return;
        }

        input.addEventListener('change', function() {
          if (previousUrl) {
            URL.revokeObjectURL(previousUrl);
            previousUrl = null;
          }

          var file = input.files && input.files.length ? input.files[0] : null;
          if (!file ||
            ['image/jpeg', 'image/png', 'image/webp'].indexOf(file.type) === -1 ||
            file.size < 1 || file.size > 5 * 1024 * 1024) {
            preview.style.display = 'none';
            image.removeAttribute('src');
            if (file) {
              alert('Foto harus JPEG, PNG, atau WEBP dan maksimal 5 MB.');
              input.value = '';
            }
            return;
          }

          previousUrl = URL.createObjectURL(file);
          image.src = previousUrl;
          preview.style.display = 'block';
        });
      });
    })();
  </script>
<?php endif; ?>
