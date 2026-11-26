<?php
// UTS P8: Manajemen Penyewa pada sisi Admin (CRUD, pola MVC).
// Mengikuti pola Mobil_controller: satu View untuk tabel + form,
// validasi di Controller, SQL di Model, dan Post-Redirect-Get (PRG).
class Mpenyewa_controller extends Controller
{
  private Penyewa_model $model;

  public function __construct()
  {
    require_once APPPATH . 'models/Penyewa_model.php';
    require_once APPPATH . 'helpers/auth_helper.php';
    require_once APPPATH . 'helpers/flash_helper.php';
    $this->model = new Penyewa_model();
  }

  // READ: tabel data penyewa.
  public function index(): void
  {
    require_admin_login();

    try {
      $penyewa = $this->model->getAll();
    } catch (Throwable $e) {
      error_log('Daftar penyewa gagal dimuat: ' . $e->getMessage());
      $penyewa = [];
      set_flash_message('danger', 'Data penyewa belum dapat dimuat.');
    }

    $this->view('admin/penyewa_view', [
      'username' => $_SESSION['admin_username'],
      'penyewa' => $penyewa,
      'flash' => get_flash_message(),
      'mode' => null,
      'penyewa_dipilih' => null,
      'error' => null,
    ]);
  }

  // CREATE: GET menampilkan form, POST memproses penyimpanan.
  public function tambah(): void
  {
    require_admin_login();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
      $this->renderPenyewa('tambah');
      return;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      http_response_code(405);
      exit('Metode request tidak diizinkan.');
    }

    $data = $this->ambilInput();
    $data['user_plg'] = trim((string) ($_POST['user_plg'] ?? ''));
    $password = (string) ($_POST['sandi'] ?? '');
    $konfirmasi = (string) ($_POST['konfirmasi_sandi'] ?? '');

    $error = $this->validasiInput($data);

    if ($error === null) {
      if (!preg_match('/\A[A-Za-z0-9._-]{1,15}\z/', $data['user_plg'])) {
        $error = 'Username harus 1–15 karakter: huruf, angka, titik, garis bawah, atau tanda hubung.';
      } elseif (strlen($password) < 8) {
        $error = 'Password minimal 8 karakter.';
      } elseif (strlen($password) > 72) {
        $error = 'Password maksimal 72 byte.';
      } elseif ($password !== $konfirmasi) {
        $error = 'Konfirmasi password tidak sama.';
      }
    }

    if ($error !== null) {
      $this->renderPenyewa('tambah', $data, $error);
      return;
    }

    try {
      if ($this->model->getProfil($data['user_plg']) !== null) {
        $this->renderPenyewa('tambah', $data, 'Username sudah digunakan.');
        return;
      }
    } catch (Throwable $e) {
      error_log('Cek username penyewa gagal: ' . $e->getMessage());
      $this->renderPenyewa('tambah', $data, 'Data belum dapat diproses.');
      return;
    }

    // Unggah foto KTP dan SIM A (opsional pada saat menambah data).
    $uploadedFiles = [];
    foreach (['ft_ktp', 'ft_sim_a'] as $field) {
      $upload = $this->uploadFoto($field);

      if (!$upload['success']) {
        $this->hapusFoto($uploadedFiles);
        $this->renderPenyewa('tambah', $data, $upload['error']);
        return;
      }

      $data[$field] = $upload['filename'];

      if ($upload['filename'] !== null) {
        $uploadedFiles[] = $upload['filename'];
      }
    }

    // Password tidak pernah disimpan sebagai plainteks.
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $data['sandi'] = $hash;

    try {
      $success = $hash !== false && $this->model->createAdmin($data);
    } catch (Throwable $e) {
      error_log('Tambah penyewa gagal: ' . $e->getMessage());
      $success = false;
    }

    if (!$success) {
      $this->hapusFoto($uploadedFiles);
      $data['sandi'] = '';
      $this->renderPenyewa(
        'tambah',
        $data,
        'Data penyewa gagal disimpan. Periksa username dan NIK (mungkin sudah terdaftar).'
      );
      return;
    }

    set_flash_message('success', 'Data penyewa berhasil ditambahkan.');
    header('Location: ' . site_url('admin/penyewa'));
    exit;
  }

  // UPDATE: username adalah kunci sehingga tidak dapat diubah.
  public function ubah(string $userPlg): void
  {
    require_admin_login();

    $userPlg = rawurldecode($userPlg);

    try {
      $lama = $this->model->getProfil($userPlg);
    } catch (Throwable $e) {
      error_log('Ambil penyewa gagal: ' . $e->getMessage());
      $lama = null;
    }

    if ($lama === null) {
      set_flash_message('danger', 'Data penyewa tidak ditemukan.');
      header('Location: ' . site_url('admin/penyewa'));
      exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
      $this->renderPenyewa('ubah', $lama);
      return;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      http_response_code(405);
      exit('Metode request tidak diizinkan.');
    }

    $data = $this->ambilInput();
    $data['user_plg'] = $userPlg;
    $data['ft_ktp'] = $lama['ft_ktp'];
    $data['ft_sim_a'] = $lama['ft_sim_a'];

    $password = (string) ($_POST['sandi'] ?? '');
    $konfirmasi = (string) ($_POST['konfirmasi_sandi'] ?? '');

    $error = $this->validasiInput($data);

    // Password kosong = password lama dipertahankan.
    $hashBaru = null;
    if ($error === null && ($password !== '' || $konfirmasi !== '')) {
      if (strlen($password) < 8) {
        $error = 'Password baru minimal 8 karakter.';
      } elseif (strlen($password) > 72) {
        $error = 'Password baru maksimal 72 byte.';
      } elseif ($password !== $konfirmasi) {
        $error = 'Konfirmasi password tidak sama.';
      } else {
        $hashBaru = password_hash($password, PASSWORD_DEFAULT);
      }
    }

    if ($error !== null) {
      $this->renderPenyewa('ubah', $data, $error);
      return;
    }

    $uploadedFiles = [];
    $fotoLama = [];

    foreach (['ft_ktp', 'ft_sim_a'] as $field) {
      $upload = $this->uploadFoto($field);

      if (!$upload['success']) {
        $this->hapusFoto($uploadedFiles);
        $this->renderPenyewa('ubah', $data, $upload['error']);
        return;
      }

      if ($upload['filename'] !== null) {
        $uploadedFiles[] = $upload['filename'];

        if (!empty($lama[$field])) {
          $fotoLama[] = $lama[$field];
        }

        $data[$field] = $upload['filename'];
      }
    }

    try {
      $success = $this->model->updateAdmin($userPlg, $data, $hashBaru);
    } catch (Throwable $e) {
      error_log('Ubah penyewa gagal: ' . $e->getMessage());
      $success = false;
    }

    if (!$success) {
      $this->hapusFoto($uploadedFiles);
      $data['ft_ktp'] = $lama['ft_ktp'];
      $data['ft_sim_a'] = $lama['ft_sim_a'];
      $this->renderPenyewa(
        'ubah',
        $data,
        'Data penyewa gagal diperbarui. Periksa kembali data yang dimasukkan.'
      );
      return;
    }

    // Foto lama baru dihapus setelah database berhasil diperbarui.
    $this->hapusFoto($fotoLama);

    set_flash_message('success', 'Data penyewa berhasil diperbarui.');
    header('Location: ' . site_url('admin/penyewa'));
    exit;
  }

  // DELETE: wajib POST.
  public function hapus(string $userPlg): void
  {
    require_admin_login();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      http_response_code(405);
      exit('Metode request tidak diizinkan.');
    }

    $userPlg = rawurldecode($userPlg);

    try {
      $penyewa = $this->model->getProfil($userPlg);
    } catch (Throwable $e) {
      $penyewa = null;
    }

    if ($penyewa === null) {
      set_flash_message('danger', 'Data penyewa tidak ditemukan.');
      header('Location: ' . site_url('admin/penyewa'));
      exit;
    }

    try {
      $success = $this->model->deleteAdmin($userPlg);
    } catch (Throwable $e) {
      // Umumnya karena penyewa masih memiliki transaksi sewa (foreign key).
      error_log('Hapus penyewa gagal: ' . $e->getMessage());
      $success = false;
    }

    if (!$success) {
      set_flash_message(
        'danger',
        'Data penyewa gagal dihapus. Penyewa yang sudah memiliki transaksi sewa tidak dapat dihapus.'
      );
      header('Location: ' . site_url('admin/penyewa'));
      exit;
    }

    $this->hapusFoto(array_filter([
      $penyewa['ft_ktp'] ?? null,
      $penyewa['ft_sim_a'] ?? null,
    ]));

    set_flash_message('success', 'Data penyewa berhasil dihapus.');
    header('Location: ' . site_url('admin/penyewa'));
    exit;
  }

  // ------------------------- helper privat -------------------------

  private function ambilInput(): array
  {
    return [
      'nik' => trim((string) ($_POST['nik'] ?? '')),
      'nm_lngkp' => trim((string) ($_POST['nm_lngkp'] ?? '')),
      'no_hp' => trim((string) ($_POST['no_hp'] ?? '')),
      'almt_lngkp' => trim((string) ($_POST['almt_lngkp'] ?? '')),
      'ft_ktp' => null,
      'ft_sim_a' => null,
    ];
  }

  private function renderPenyewa(
    string $mode,
    ?array $dipilih = null,
    ?string $error = null
  ): void {
    try {
      $penyewa = $this->model->getAll();
    } catch (Throwable $e) {
      $penyewa = [];
    }

    $this->view('admin/penyewa_view', [
      'username' => $_SESSION['admin_username'],
      'penyewa' => $penyewa,
      'flash' => null,
      'mode' => $mode,
      'penyewa_dipilih' => $dipilih,
      'error' => $error,
    ]);
  }

  private function validasiInput(array $d): ?string
  {
    if (!preg_match('/\A[0-9]{16}\z/', $d['nik'])) {
      return 'NIK wajib terdiri dari tepat 16 digit angka.';
    }

    if (
      $d['nm_lngkp'] === '' ||
      mb_strlen($d['nm_lngkp']) > 100 ||
      !preg_match(
        "/\A[\p{L}\p{M}]+(?:[ .'-][\p{L}\p{M}]+)*\z/u",
        $d['nm_lngkp']
      )
    ) {
      return 'Nama lengkap wajib diisi, maksimal 100 karakter, dan hanya berisi huruf serta pemisah nama yang wajar.';
    }

    if (
      $d['no_hp'] === '' ||
      strlen($d['no_hp']) > 15 ||
      !preg_match('/\A[0-9+(). -]+\z/', $d['no_hp']) ||
      !preg_match('/[0-9]/', $d['no_hp'])
    ) {
      return 'Nomor HP wajib diisi, maksimal 15 karakter, dan hanya boleh berisi angka serta tanda + . ( ) spasi atau -.';
    }

    if (
      $d['almt_lngkp'] === '' ||
      mb_strlen($d['almt_lngkp']) > 300 ||
      preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $d['almt_lngkp'])
    ) {
      return 'Alamat wajib diisi, maksimal 300 karakter, dan tidak boleh mengandung karakter kontrol.';
    }

    return null;
  }

  // Validasi dan penyimpanan foto KTP / SIM A (JPEG, PNG, WEBP; maks 5 MB).
  private function uploadFoto(string $field): array
  {
    if (!in_array($field, ['ft_ktp', 'ft_sim_a'], true)) {
      return ['success' => false, 'filename' => null, 'error' => 'Field foto tidak valid.'];
    }

    if (
      !isset($_FILES[$field]) ||
      ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
    ) {
      return ['success' => true, 'filename' => null, 'error' => null];
    }

    $file = $_FILES[$field];
    $label = $field === 'ft_ktp' ? 'Foto KTP' : 'Foto SIM A';

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      return ['success' => false, 'filename' => null, 'error' => $label . ': unggah gagal. Periksa ukuran file dan konfigurasi PHP.'];
    }

    if (
      !isset($file['tmp_name'], $file['size']) ||
      !is_uploaded_file($file['tmp_name']) ||
      (int) $file['size'] < 1 ||
      (int) $file['size'] > 5 * 1024 * 1024
    ) {
      return ['success' => false, 'filename' => null, 'error' => $label . ' harus berukuran lebih dari 0 byte dan maksimal 5 MB.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);

    $allowed = [
      'image/jpeg' => 'jpg',
      'image/png' => 'png',
      'image/webp' => 'webp',
    ];

    if (!isset($allowed[$mime]) || @getimagesize($file['tmp_name']) === false) {
      return ['success' => false, 'filename' => null, 'error' => $label . ' harus berformat JPEG, PNG, atau WEBP yang valid.'];
    }

    $uploadDir = dirname(APPPATH) . DIRECTORY_SEPARATOR . 'uploads'
      . DIRECTORY_SEPARATOR . 'penyewa' . DIRECTORY_SEPARATOR;

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
      return ['success' => false, 'filename' => null, 'error' => 'Folder penyimpanan foto penyewa tidak dapat dibuat.'];
    }

    if (!is_writable($uploadDir)) {
      return ['success' => false, 'filename' => null, 'error' => 'Folder penyimpanan foto penyewa tidak dapat ditulis.'];
    }

    try {
      $filename = $field . '_' . date('YmdHis') . '_'
        . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
    } catch (Throwable $e) {
      return ['success' => false, 'filename' => null, 'error' => 'Nama file foto gagal dibuat.'];
    }

    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
      return ['success' => false, 'filename' => null, 'error' => 'Foto gagal dipindahkan ke folder penyimpanan.'];
    }

    return ['success' => true, 'filename' => $filename, 'error' => null];
  }

  // Hanya menghapus nama file yang sesuai pola hasil uploadFoto().
  private function hapusFoto(array $filenames): void
  {
    $uploadDir = dirname(APPPATH) . DIRECTORY_SEPARATOR . 'uploads'
      . DIRECTORY_SEPARATOR . 'penyewa' . DIRECTORY_SEPARATOR;

    foreach ($filenames as $filename) {
      if (
        is_string($filename) &&
        preg_match(
          '/^(ft_ktp|ft_sim_a)_\d{10,14}_[a-f0-9]{12,16}\.(jpg|jpeg|png|webp)$/',
          $filename
        )
      ) {
        $path = $uploadDir . $filename;

        if (is_file($path)) {
          @unlink($path);
        }
      }
    }
  }
}
