<?php
// P9: Pembayaran DP oleh penyewa (lihat pembayaran, unggah bukti transfer).
require_once APPPATH . 'models/Pembayaran_model.php';
require_once APPPATH . 'models/Transaksi_model.php';
require_once APPPATH . 'helpers/auth_helper.php';
require_once APPPATH . 'helpers/bukti_helper.php';

class Pembayaran_controller extends Controller
{
    private const MAKS_UKURAN = 2 * 1024 * 1024; // 2 MB
    private const MIME_DIIZINKAN = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    private const EKSTENSI_DIIZINKAN = ['jpg', 'jpeg', 'png', 'webp'];

    private Pembayaran_model $model;

    public function __construct()
    {
        $this->model = new Pembayaran_model();
    }

    // Daftar pembayaran milik penyewa yang login.
    public function index(): void
    {
        $userPlg = $this->wajibLoginPenyewa();
        $this->wajibMetode('GET');
        try {
            $daftar = $this->model->getByPenyewa($userPlg);
            $this->view('pembayaran/daftar', [
                'daftar' => $daftar,
                'jumlahKeranjang' => (new Transaksi_model())->getJumlahMobilKeranjang($userPlg),
            ]);
        } catch (Throwable $e) {
            error_log('Daftar pembayaran gagal: ' . $e->getMessage());
            http_response_code(500);
            exit('Daftar pembayaran belum dapat ditampilkan. Silakan coba kembali.');
        }
    }

    // Halaman pembayaran satu transaksi: ?kode_bayar=PAY/YYYYMMDD/NN
    public function detail(): void
    {
        $userPlg = $this->wajibLoginPenyewa();
        $this->wajibMetode('GET');
        $kodeBayar = trim((string) ($_GET['kode_bayar'] ?? ''));
        $pembayaran = $this->ambilMilikSendiri($kodeBayar, $userPlg);
        try {
            $this->view('pembayaran/detail', [
                'pembayaran' => $pembayaran,
                'maksMb' => self::MAKS_UKURAN / (1024 * 1024),
                'jumlahKeranjang' => (new Transaksi_model())->getJumlahMobilKeranjang($userPlg),
            ]);
        } catch (Throwable $e) {
            error_log('Tampilan pembayaran gagal: ' . $e->getMessage());
            http_response_code(500);
            exit('Halaman pembayaran belum dapat ditampilkan. Silakan coba kembali.');
        }
    }

    // Mengirim gambar bukti milik penyewa sendiri.
    public function bukti(): void
    {
        $userPlg = $this->wajibLoginPenyewa();
        $this->wajibMetode('GET');
        $kodeBayar = trim((string) ($_GET['kode_bayar'] ?? ''));
        $pembayaran = $this->ambilMilikSendiri($kodeBayar, $userPlg);
        kirim_bukti_pembayaran($pembayaran['ft_byr'] ?? null);
    }

    // Memproses unggah bukti (POST) lalu PRG ke halaman pembayaran.
    // Nominal sengaja tidak dibaca dari form: nilai berasal dari t_bayar.
    public function unggah(): void
    {
        $userPlg = $this->wajibLoginPenyewa();
        $this->wajibMetode('POST');
        $kodeBayar = trim((string) ($_POST['kode_bayar'] ?? ''));
        $pembayaran = $this->ambilMilikSendiri($kodeBayar, $userPlg);

        if ($pembayaran['sttus_byr'] !== 'pending' || $pembayaran['status_sewa'] !== 'diajukan') {
            $this->kembali($kodeBayar, 'error', Pembayaran_model::PESAN_SUDAH_DIPROSES);
        }

        $upload = $this->prosesBerkas('ft_byr');
        if (!$upload['success']) {
            $this->kembali($kodeBayar, 'error', $upload['error']);
        }

        try {
            $berkasLama = $this->model->simpanBukti($kodeBayar, $userPlg, $upload['filename']);
        } catch (Pembayaran_exception $e) {
            hapus_bukti_pembayaran($upload['filename']);
            $this->kembali($kodeBayar, 'error', $e->getMessage());
        } catch (Throwable $e) {
            hapus_bukti_pembayaran($upload['filename']);
            error_log('Simpan bukti pembayaran gagal: ' . $e->getMessage());
            $this->kembali($kodeBayar, 'error', 'Bukti pembayaran gagal disimpan. Silakan coba kembali.');
        }

        // Berkas lama baru dihapus setelah UPDATE berhasil.
        hapus_bukti_pembayaran($berkasLama);
        $this->kembali(
            $kodeBayar,
            'success',
            'Bukti pembayaran berhasil diunggah. Status pembayaran tetap pending sampai diverifikasi admin.'
        );
    }

    // ===== P10: Pelunasan dan Dokumen Transaksi (admin) =====

    /**
     * P10: Menampilkan daftar transaksi yang masih memiliki kewajiban pelunasan.
     */
    public function pelunasanIndex(): void
    {
        require_admin_login();
        $this->wajibMetode('GET');

        try {
            $daftarPelunasan = $this->model
                ->getDaftarPelunasan();

            $flash = $_SESSION['flash_pembayaran'] ?? null;
            unset($_SESSION['flash_pembayaran']);

            $this->view('admin/pelunasan', [
                'username' => $_SESSION['admin_username'],
                'daftarPelunasan' => $daftarPelunasan,
                'flash' => $flash
            ]);
        } catch (Throwable $e) {
            error_log(
                'Daftar pelunasan gagal ditampilkan: ' . $e->getMessage()
            );
            http_response_code(500);
            exit('Daftar transaksi pelunasan belum dapat ditampilkan. '
                . 'Silakan coba kembali.');
        }
    }

    /**
     * P10: Menampilkan detail transaksi, riwayat pembayaran, dan sisa kewajiban.
     */
    public function pelunasanDetail(string $kodeSewa = ''): void
    {
        require_admin_login();
        $this->wajibMetode('GET');

        $kodeSewa = trim(str_replace('~', '/', $kodeSewa));
        if ($kodeSewa === '') {
            http_response_code(400);
            exit('Kode transaksi sewa wajib tersedia.');
        }

        try {
            $detailPelunasan = $this->model
                ->getDetailPelunasan($kodeSewa);

            if ($detailPelunasan === null) {
                http_response_code(404);
                exit('Transaksi sewa tidak ditemukan.');
            }

            $flash = $_SESSION['flash_pembayaran'] ?? null;
            unset($_SESSION['flash_pembayaran']);

            $this->view('admin/pelunasan_detail', [
                'username' => $_SESSION['admin_username'],
                'detailPelunasan' => $detailPelunasan,
                'flash' => $flash
            ]);
        } catch (Throwable $e) {
            error_log(
                'Detail pelunasan gagal ditampilkan: ' . $e->getMessage()
            );
            http_response_code(500);
            exit('Detail transaksi pelunasan belum dapat ditampilkan. '
                . 'Silakan coba kembali.');
        }
    }

    /**
     * P10: Mencatat pelunasan yang diterima langsung oleh admin.
     *
     * Tunai: tidak memerlukan unggahan bukti.
     * Transfer: admin mengunggah bukti yang diterima dari penyewa.
     * Model menghitung nominal sisa kewajiban dan menyimpan pembayaran
     * dengan status valid dalam satu transaksi database.
     */
    public function prosesPelunasan(string $kodeSewa = ''): void
    {
        require_admin_login();
        $this->wajibMetode('POST');

        $kodeSewa = trim(str_replace('~', '/', $kodeSewa));
        $metodeBayar = trim((string) ($_POST['mtode_byr'] ?? ''));

        if ($kodeSewa === '') {
            http_response_code(400);
            exit('Kode transaksi sewa wajib tersedia.');
        }

        if (!in_array($metodeBayar, ['tunai', 'transfer'], true)) {
            $this->redirectDenganPesan(
                'admin/pembayaran/pelunasan/' . str_replace('/', '~', $kodeSewa),
                'error',
                'Metode pembayaran harus tunai atau transfer.'
            );
        }

        $namaFileBukti = null;
        if ($metodeBayar === 'transfer') {
            $hasilUpload = $this->simpanFileBukti('ft_byr');
            if (!$hasilUpload['success']) {
                $this->redirectDenganPesan(
                    'admin/pembayaran/pelunasan/' . str_replace('/', '~', $kodeSewa),
                    'error',
                    $hasilUpload['error']
                );
            }
            $namaFileBukti = (string) $hasilUpload['filename'];
        }

        try {
            $kodeBayar = $this->model->simpanPelunasan(
                $kodeSewa,
                $metodeBayar,
                $namaFileBukti
            );

            $this->redirectDenganPesan(
                'admin/pembayaran/pelunasan/' . str_replace('/', '~', $kodeSewa),
                'success',
                'Pelunasan berhasil dicatat dengan kode pembayaran ' . $kodeBayar
                    . '. Status pembayaran: valid; status transaksi: lunas.'
            );
        } catch (InvalidArgumentException $e) {
            if ($namaFileBukti !== null) {
                $this->hapusBuktiBaru($namaFileBukti);
            }
            $this->redirectDenganPesan(
                'admin/pembayaran/pelunasan/' . str_replace('/', '~', $kodeSewa),
                'error',
                $e->getMessage()
            );
        } catch (Throwable $e) {
            error_log(
                'Proses pelunasan gagal: ' . $e->getMessage()
            );
            if ($namaFileBukti !== null) {
                $this->hapusBuktiBaru($namaFileBukti);
            }
            $this->redirectDenganPesan(
                'admin/pembayaran/pelunasan/' . str_replace('/', '~', $kodeSewa),
                'error',
                'Pelunasan belum berhasil dicatat. Pastikan transaksi masih memiliki '
                    . 'sisa kewajiban dan belum pernah dilunasi.'
            );
        }
    }

    /**
     * P10: Menampilkan dokumen transaksi yang siap dicetak.
     * Data disusun Model dari t_sewa, t_penyewa, t_pilih, t_mobil,
     * dan t_bayar. Method ini hanya menyajikan data.
     */
    public function dokumenTransaksi(string $kodeSewa = ''): void
    {
        require_admin_login();
        $this->wajibMetode('GET');

        $kodeSewa = trim(str_replace('~', '/', $kodeSewa));
        if ($kodeSewa === '') {
            http_response_code(400);
            exit('Kode transaksi sewa wajib tersedia.');
        }

        try {
            $detailPelunasan = $this->model
                ->getDetailPelunasan($kodeSewa);

            if ($detailPelunasan === null) {
                http_response_code(404);
                exit('Data transaksi tidak ditemukan.');
            }

            $this->view('admin/dokumen_transaksi', [
                'detailPelunasan' => $detailPelunasan
            ]);
        } catch (Throwable $e) {
            error_log(
                'Dokumen transaksi gagal ditampilkan: ' . $e->getMessage()
            );
            http_response_code(500);
            exit('Dokumen transaksi belum dapat ditampilkan. '
                . 'Silakan coba kembali.');
        }
    }

    // ===== Pengendalian akses =====

    private function wajibLoginPenyewa(): string
    {
        $userPlg = trim((string) ($_SESSION['penyewa_user_plg'] ?? ''));
        if ($userPlg !== '') {
            return $userPlg;
        }
        if (is_admin_logged_in()) {
            http_response_code(403);
            exit('Akses ditolak. Halaman pembayaran ini khusus penyewa.');
        }
        $_SESSION['flash_penyewa_nav'] = 'Silakan login terlebih dahulu untuk mengakses pembayaran.';
        header('Location: ' . site_url('auth/login-penyewa'));
        exit;
    }

    private function wajibMetode(string $diizinkan): void
    {
        $aktif = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($aktif !== $diizinkan) {
            http_response_code(405);
            header('Allow: ' . $diizinkan);
            exit('Method Not Allowed');
        }
    }

    // Kode tidak valid, tidak ada, atau milik penyewa lain diperlakukan sama: akses ditolak.
    private function ambilMilikSendiri(string $kodeBayar, string $userPlg): array
    {
        $pembayaran = null;
        if (bukti_kode_bayar_valid($kodeBayar)) {
            try {
                $pembayaran = $this->model->findMilikPenyewa($kodeBayar, $userPlg);
            } catch (Throwable $e) {
                error_log('Pembacaan pembayaran gagal: ' . $e->getMessage());
                http_response_code(500);
                exit('Data pembayaran belum dapat dibaca. Silakan coba kembali.');
            }
        }
        if ($pembayaran === null) {
            http_response_code(403);
            exit('Akses ditolak. Pembayaran tidak ditemukan atau bukan milik akun Anda.');
        }
        return $pembayaran;
    }

    private function kembali(string $kodeBayar, string $jenis, string $pesan): never
    {
        $_SESSION['flash_transaksi'] = ['type' => $jenis, 'message' => $pesan];
        header('Location: ' . site_url('pembayaran/detail?kode_bayar=' . rawurlencode($kodeBayar)));
        exit;
    }

    // ===== P10: penghubung ke helper P9 yang sudah ada =====

    // Validasi dan simpan berkas bukti (memakai prosesBerkas() milik P9).
    private function simpanFileBukti(string $field): array
    {
        return $this->prosesBerkas($field);
    }

    // Hapus berkas bukti yang baru diunggah bila penyimpanan database gagal.
    private function hapusBuktiBaru(string $namaBerkas): void
    {
        hapus_bukti_pembayaran($namaBerkas);
    }

    // PRG untuk admin: simpan pesan ke session lalu alihkan ke URI tujuan.
    private function redirectDenganPesan(string $uri, string $jenis, string $pesan): never
    {
        $_SESSION['flash_pembayaran'] = ['type' => $jenis, 'message' => $pesan];
        header('Location: ' . site_url($uri));
        exit;
    }

    // ===== Validasi dan penyimpanan berkas =====

    // Validasi: berkas dipilih, tanpa galat unggah, ukuran, ekstensi, MIME asli, dan isi gambar.
    private function prosesBerkas(string $field): array
    {
        $gagal = static fn(string $pesan): array => [
            'success' => false, 'filename' => null, 'error' => $pesan,
        ];
        if (
            !isset($_FILES[$field]) ||
            !is_array($_FILES[$field]) ||
            ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
        ) {
            return $gagal('Pilih berkas bukti pembayaran terlebih dahulu.');
        }
        $file = $_FILES[$field];
        $galat = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($galat === UPLOAD_ERR_INI_SIZE || $galat === UPLOAD_ERR_FORM_SIZE) {
            return $gagal('Ukuran berkas melebihi batas 2 MB.');
        }
        if ($galat !== UPLOAD_ERR_OK) {
            return $gagal('Unggah berkas gagal. Silakan coba kembali.');
        }
        if (
            !isset($file['tmp_name'], $file['size'], $file['name']) ||
            !is_string($file['tmp_name']) ||
            !is_uploaded_file($file['tmp_name'])
        ) {
            return $gagal('Berkas unggahan tidak valid.');
        }
        $ukuran = (int) $file['size'];
        if ($ukuran < 1) {
            return $gagal('Berkas kosong tidak dapat diunggah.');
        }
        if ($ukuran > self::MAKS_UKURAN) {
            return $gagal('Ukuran berkas melebihi batas 2 MB.');
        }
        $ekstensi = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($ekstensi, self::EKSTENSI_DIIZINKAN, true)) {
            return $gagal('Jenis berkas tidak diizinkan. Gunakan gambar JPG, PNG, atau WEBP.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!is_string($mime) || !isset(self::MIME_DIIZINKAN[$mime])) {
            return $gagal('Jenis berkas tidak diizinkan. Gunakan gambar JPG, PNG, atau WEBP.');
        }
        if (@getimagesize($file['tmp_name']) === false) {
            return $gagal('Isi berkas bukan gambar yang valid.');
        }

        $folder = bukti_folder();
        if (!is_dir($folder) && !mkdir($folder, 0755, true) && !is_dir($folder)) {
            return $gagal('Folder penyimpanan bukti pembayaran tidak dapat dibuat.');
        }
        if (!is_writable($folder)) {
            return $gagal('Folder penyimpanan bukti pembayaran tidak dapat ditulis.');
        }
        try {
            // Nama dibuat server (ekstensi dari MIME asli), nama asal dari pengguna tidak dipakai.
            $nama = 'bayar_' . date('YmdHis') . '_' . bin2hex(random_bytes(8))
                . '.' . self::MIME_DIIZINKAN[$mime];
        } catch (Throwable $e) {
            return $gagal('Nama berkas gagal dibuat.');
        }
        if (!move_uploaded_file($file['tmp_name'], $folder . $nama)) {
            return $gagal('Berkas gagal dipindahkan ke folder penyimpanan.');
        }
        return ['success' => true, 'filename' => $nama, 'error' => null];
    }
}
