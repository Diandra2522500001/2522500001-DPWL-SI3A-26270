<?php
// P9: Model pembayaran DP (tabel t_bayar) dan relasinya dengan transaksi (t_sewa, t_pilih).
// Semua query memakai prepared statement. Nominal TIDAK pernah diterima dari form.

// Pesan pada exception ini aman ditampilkan kepada pengguna.
class Pembayaran_exception extends RuntimeException
{
}

class Pembayaran_model
{
    public const PESAN_TIDAK_DITEMUKAN = 'Pembayaran tidak ditemukan.';
    public const PESAN_SUDAH_DIPROSES = 'Pembayaran ini sudah diverifikasi dan tidak dapat diproses kembali.';
    public const PESAN_TANPA_BUKTI = 'Bukti pembayaran belum diunggah, sehingga belum dapat diverifikasi.';

    private mysqli $db;

    public function __construct()
    {
        global $db;
        $this->db = $db;
    }

    private function jalankan(string $sql, string $tipe = '', array $param = []): mysqli_stmt
    {
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Query pembayaran gagal disiapkan.');
        }
        if ($tipe !== '') {
            $stmt->bind_param($tipe, ...$param);
        }
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Query pembayaran gagal dijalankan.');
        }
        return $stmt;
    }

    private function ambilSemua(string $sql, string $tipe = '', array $param = []): array
    {
        $stmt = $this->jalankan($sql, $tipe, $param);
        $hasil = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $hasil;
    }

    private function ambilSatu(string $sql, string $tipe = '', array $param = []): ?array
    {
        $stmt = $this->jalankan($sql, $tipe, $param);
        $baris = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $baris ?: null;
    }

    // Menjalankan INSERT/UPDATE dan mengembalikan jumlah baris terdampak.
    private function ubah(string $sql, string $tipe, array $param): int
    {
        $stmt = $this->jalankan($sql, $tipe, $param);
        $terdampak = $stmt->affected_rows;
        $stmt->close();
        return (int) $terdampak;
    }

    // Rincian kendaraan pada transaksi beserta total sewa (hrg_sewa x n_hari).
    private function lengkapiDetail(array $pembayaran): array
    {
        $detail = $this->ambilSemua(
            "SELECT p.no_plat, p.hrg_sewa, p.n_hari, m.nm_mobil
            FROM t_pilih p
            INNER JOIN t_mobil m ON m.no_plat = p.no_plat
            WHERE p.kode_sewa = ?
            ORDER BY m.nm_mobil ASC",
            's',
            [$pembayaran['kode_sewa']]
        );
        $total = 0;
        foreach ($detail as $item) {
            $total += (int) $item['hrg_sewa'] * (int) $item['n_hari'];
        }
        $pembayaran['detail'] = $detail;
        $pembayaran['total_sewa'] = $total;
        return $pembayaran;
    }

    // ===== Sisi penyewa =====

    // Dipakai checkout untuk mengarahkan penyewa ke halaman pembayaran DP-nya.
    public function findKodeDpBySewa(string $kodeSewa, string $userPlg): ?string
    {
        $baris = $this->ambilSatu(
            "SELECT b.kode_bayar
            FROM t_bayar b
            INNER JOIN t_sewa s ON s.kode_sewa = b.kode_sewa
            WHERE b.kode_sewa = ? AND s.user_plg = ? AND b.jns_byr = 'dp'
            LIMIT 1",
            'ss',
            [$kodeSewa, $userPlg]
        );
        return $baris === null ? null : (string) $baris['kode_bayar'];
    }

    public function getByPenyewa(string $userPlg): array
    {
        return $this->ambilSemua(
            "SELECT b.kode_bayar, b.kode_sewa, b.nominal, b.sttus_byr, b.ft_byr,
                b.tgl_wkt_byr, b.tgl_wkt_ver, s.status_sewa, s.tgl_mulai, s.tgl_selesai
            FROM t_bayar b
            INNER JOIN t_sewa s ON s.kode_sewa = b.kode_sewa
            WHERE s.user_plg = ? AND b.jns_byr = 'dp'
            ORDER BY b.kode_bayar DESC",
            's',
            [$userPlg]
        );
    }

    // Hanya mengembalikan pembayaran milik penyewa tersebut (kepemilikan lewat t_sewa.user_plg).
    public function findMilikPenyewa(string $kodeBayar, string $userPlg): ?array
    {
        $baris = $this->ambilSatu(
            "SELECT b.kode_bayar, b.kode_sewa, b.mtode_byr, b.jns_byr, b.nominal,
                b.sttus_byr, b.ft_byr, b.tgl_wkt_byr, b.tgl_wkt_ver,
                s.status_sewa, s.tgl_mulai, s.tgl_selesai, s.wkt_ambil
            FROM t_bayar b
            INNER JOIN t_sewa s ON s.kode_sewa = b.kode_sewa
            WHERE b.kode_bayar = ? AND s.user_plg = ? AND b.jns_byr = 'dp'
            LIMIT 1",
            'ss',
            [$kodeBayar, $userPlg]
        );
        return $baris === null ? null : $this->lengkapiDetail($baris);
    }

    // Memperbarui record DP yang sudah ada (bukan membuat record baru).
    // Mengembalikan nama berkas lama (atau null) agar controller dapat menghapusnya.
    public function simpanBukti(string $kodeBayar, string $userPlg, string $namaBerkas): ?string
    {
        $this->db->begin_transaction();
        try {
            $baris = $this->ambilSatu(
                "SELECT b.ft_byr, b.sttus_byr, s.status_sewa
                FROM t_bayar b
                INNER JOIN t_sewa s ON s.kode_sewa = b.kode_sewa
                WHERE b.kode_bayar = ? AND s.user_plg = ? AND b.jns_byr = 'dp'
                LIMIT 1
                FOR UPDATE",
                'ss',
                [$kodeBayar, $userPlg]
            );
            if ($baris === null) {
                throw new Pembayaran_exception(self::PESAN_TIDAK_DITEMUKAN);
            }
            if ($baris['sttus_byr'] !== 'pending' || $baris['status_sewa'] !== 'diajukan') {
                throw new Pembayaran_exception(self::PESAN_SUDAH_DIPROSES);
            }
            $terdampak = $this->ubah(
                "UPDATE t_bayar
                SET ft_byr = ?, tgl_wkt_byr = NOW()
                WHERE kode_bayar = ? AND jns_byr = 'dp' AND sttus_byr = 'pending'",
                'ss',
                [$namaBerkas, $kodeBayar]
            );
            if ($terdampak !== 1) {
                throw new RuntimeException('Pembaruan bukti pembayaran tidak mengubah data.');
            }
            $this->db->commit();
            return $baris['ft_byr'] === null || $baris['ft_byr'] === ''
                ? null
                : (string) $baris['ft_byr'];
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    // ===== Sisi admin =====

    private const SELECT_ADMIN =
        "SELECT b.kode_bayar, b.kode_sewa, b.mtode_byr, b.jns_byr, b.nominal,
            b.sttus_byr, b.ft_byr, b.tgl_wkt_byr, b.tgl_wkt_ver,
            s.status_sewa, s.tgl_mulai, s.tgl_selesai, s.wkt_ambil,
            s.user_plg, p.nm_lngkp, p.no_hp
        FROM t_bayar b
        INNER JOIN t_sewa s ON s.kode_sewa = b.kode_sewa
        INNER JOIN t_penyewa p ON p.user_plg = s.user_plg ";

    // Pembayaran DP yang masih menunggu keputusan admin (yang sudah ada bukti diurutkan lebih dulu).
    public function getMenungguVerifikasi(): array
    {
        return $this->ambilSemua(
            self::SELECT_ADMIN .
            "WHERE b.jns_byr = 'dp' AND b.sttus_byr = 'pending' AND s.status_sewa = 'diajukan'
            ORDER BY (b.ft_byr IS NULL) ASC, b.tgl_wkt_byr ASC, b.kode_bayar ASC"
        );
    }

    public function getRiwayatVerifikasi(int $batas = 50): array
    {
        return $this->ambilSemua(
            self::SELECT_ADMIN .
            "WHERE b.jns_byr = 'dp' AND b.sttus_byr IN ('valid', 'invalid')
            ORDER BY b.tgl_wkt_ver DESC, b.kode_bayar DESC
            LIMIT ?",
            'i',
            [$batas]
        );
    }

    public function findUntukAdmin(string $kodeBayar): ?array
    {
        $baris = $this->ambilSatu(
            self::SELECT_ADMIN . "WHERE b.kode_bayar = ? AND b.jns_byr = 'dp' LIMIT 1",
            's',
            [$kodeBayar]
        );
        return $baris === null ? null : $this->lengkapiDetail($baris);
    }

    // Verifikasi: t_bayar dan t_sewa diubah dalam SATU transaksi database.
    // terima -> sttus_byr=valid,   status_sewa=disetujui
    // tolak  -> sttus_byr=invalid, status_sewa=ditolak
    public function verifikasi(string $kodeBayar, string $keputusan): void
    {
        $peta = [
            'terima' => ['valid', 'disetujui'],
            'tolak' => ['invalid', 'ditolak'],
        ];
        if (!isset($peta[$keputusan])) {
            throw new Pembayaran_exception('Keputusan verifikasi tidak valid.');
        }
        [$statusBayar, $statusSewa] = $peta[$keputusan];

        $this->db->begin_transaction();
        try {
            $baris = $this->ambilSatu(
                "SELECT b.kode_sewa, b.ft_byr, b.sttus_byr, s.status_sewa
                FROM t_bayar b
                INNER JOIN t_sewa s ON s.kode_sewa = b.kode_sewa
                WHERE b.kode_bayar = ? AND b.jns_byr = 'dp'
                LIMIT 1
                FOR UPDATE",
                's',
                [$kodeBayar]
            );
            if ($baris === null) {
                throw new Pembayaran_exception(self::PESAN_TIDAK_DITEMUKAN);
            }
            if ($baris['sttus_byr'] !== 'pending' || $baris['status_sewa'] !== 'diajukan') {
                throw new Pembayaran_exception(self::PESAN_SUDAH_DIPROSES);
            }
            if ($baris['ft_byr'] === null || $baris['ft_byr'] === '') {
                throw new Pembayaran_exception(self::PESAN_TANPA_BUKTI);
            }

            $a = $this->ubah(
                "UPDATE t_bayar
                SET sttus_byr = ?, tgl_wkt_ver = NOW()
                WHERE kode_bayar = ? AND jns_byr = 'dp' AND sttus_byr = 'pending'",
                'ss',
                [$statusBayar, $kodeBayar]
            );
            $b = $this->ubah(
                "UPDATE t_sewa
                SET status_sewa = ?
                WHERE kode_sewa = ? AND status_sewa = 'diajukan'",
                'ss',
                [$statusSewa, $baris['kode_sewa']]
            );
            if ($a !== 1 || $b !== 1) {
                throw new RuntimeException('Perubahan status tidak konsisten; verifikasi dibatalkan.');
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    // ===== P10: Pelunasan =====

    /**
     * P10: Mengambil daftar transaksi yang dapat diproses pelunasannya.
     *
     * Total transaksi menggunakan snapshot tarif pada t_pilih.
     * Pembayaran yang mengurangi kewajiban hanya yang berstatus valid.
     */
    public function getDaftarPelunasan(): array
    {
        $stmt = $this->db->prepare(
            "SELECT
                s.kode_sewa,
                s.tgl_mulai,
                s.tgl_selesai,
                s.status_sewa,
                p.user_plg,
                p.nm_lngkp,
                p.no_hp,
                COALESCE((
                    SELECT SUM(tp.hrg_sewa * tp.n_hari)
                    FROM t_pilih tp
                    WHERE tp.kode_sewa = s.kode_sewa
                ), 0) AS total_transaksi,
                COALESCE((
                    SELECT SUM(b.nominal)
                    FROM t_bayar b
                    WHERE b.kode_sewa = s.kode_sewa
                    AND b.sttus_byr = 'valid'
                ), 0) AS total_terbayar
            FROM t_sewa s
            INNER JOIN t_penyewa p
                ON p.user_plg = s.user_plg
            WHERE s.status_sewa IN ('disetujui', 'lunas')
            AND EXISTS (
                SELECT 1
                FROM t_pilih tp
                WHERE tp.kode_sewa = s.kode_sewa
            )
            ORDER BY s.tgl_mulai DESC, s.kode_sewa DESC"
        );
        if (!$stmt) {
            throw new RuntimeException(
                'Query daftar pelunasan gagal disiapkan.'
            );
        }
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException(
                'Daftar pelunasan gagal diambil.'
            );
        }
        $daftar = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($daftar as &$item) {
            $item['total_transaksi'] = (int) $item['total_transaksi'];
            $item['total_terbayar'] = (int) $item['total_terbayar'];
            $item['sisa_kewajiban'] = max(
                0,
                $item['total_transaksi'] - $item['total_terbayar']
            );
        }
        unset($item);
        return array_values(array_filter(
            $daftar,
            static fn(array $item): bool =>
            $item['sisa_kewajiban'] > 0 ||
                $item['status_sewa'] === 'lunas'
        ));
    }

    /**
     * P10: Mengambil detail transaksi, penyewa, dan riwayat pembayaran
     * untuk halaman pelunasan.
     */
    public function getDetailPelunasan(string $kodeSewa): ?array
    {
        $kodeSewa = trim($kodeSewa);
        if ($kodeSewa === '') {
            throw new InvalidArgumentException(
                'Kode transaksi sewa wajib diisi.'
            );
        }
        $stmt = $this->db->prepare(
            "SELECT
                s.kode_sewa,
                s.tgl_mulai,
                s.tgl_selesai,
                s.wkt_ambil,
                s.status_sewa,
                p.user_plg,
                p.nm_lngkp,
                p.no_hp,
                p.nik,
                p.almt_lngkp,
                COALESCE((
                    SELECT SUM(tp.hrg_sewa * tp.n_hari)
                    FROM t_pilih tp
                    WHERE tp.kode_sewa = s.kode_sewa
                ), 0) AS total_transaksi,
                COALESCE((
                    SELECT SUM(b.nominal)
                    FROM t_bayar b
                    WHERE b.kode_sewa = s.kode_sewa
                    AND b.sttus_byr = 'valid'
                ), 0) AS total_terbayar
            FROM t_sewa s
            INNER JOIN t_penyewa p
                ON p.user_plg = s.user_plg
            WHERE s.kode_sewa = ?
            LIMIT 1"
        );
        if (!$stmt) {
            throw new RuntimeException(
                'Query detail pelunasan gagal disiapkan.'
            );
        }
        $stmt->bind_param('s', $kodeSewa);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException(
                'Detail transaksi pelunasan gagal diambil.'
            );
        }
        $detail = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($detail === null) {
            return null;
        }
        $detail['total_transaksi'] = (int) $detail['total_transaksi'];
        $detail['total_terbayar'] = (int) $detail['total_terbayar'];
        $detail['sisa_kewajiban'] = max(
            0,
            $detail['total_transaksi'] - $detail['total_terbayar']
        );

        $stmt = $this->db->prepare(
            "SELECT
                kode_bayar,
                tgl_wkt_byr,
                mtode_byr,
                jns_byr,
                nominal,
                sttus_byr,
                ft_byr,
                tgl_wkt_ver
            FROM t_bayar
            WHERE kode_sewa = ?
            ORDER BY tgl_wkt_byr ASC, kode_bayar ASC"
        );
        if (!$stmt) {
            throw new RuntimeException(
                'Query riwayat pembayaran gagal disiapkan.'
            );
        }
        $stmt->bind_param('s', $kodeSewa);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException(
                'Riwayat pembayaran gagal diambil.'
            );
        }
        $detail['riwayat_pembayaran'] =
            $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $stmt = $this->db->prepare(
            "SELECT
                m.no_plat,
                m.nm_mobil,
                tp.hrg_sewa,
                tp.n_hari,
                (tp.hrg_sewa * tp.n_hari) AS subtotal
            FROM t_pilih tp
            INNER JOIN t_mobil m
                ON m.no_plat = tp.no_plat
            WHERE tp.kode_sewa = ?
            ORDER BY m.nm_mobil ASC, m.no_plat ASC"
        );
        if (!$stmt) {
            throw new RuntimeException(
                'Query detail kendaraan pelunasan gagal disiapkan.'
            );
        }
        $stmt->bind_param('s', $kodeSewa);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException(
                'Detail kendaraan pelunasan gagal diambil.'
            );
        }
        $detail['daftar_kendaraan'] =
            $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $detail;
    }

    /**
     * P10: Membuat kode pembayaran dengan format PAY/YYYYMMDD/NN.
     * Pemanggil harus memegang named lock pembayaran harian.
     */
    private function buatKodeBayarPelunasan(string $tanggalKode): string
    {
        $pola = 'PAY/' . $tanggalKode . '/%';
        $stmt = $this->db->prepare(
            "SELECT kode_bayar
            FROM t_bayar
            WHERE kode_bayar LIKE ?
            ORDER BY kode_bayar DESC
            LIMIT 1"
        );
        if (!$stmt) {
            throw new RuntimeException(
                'Pembuatan kode pembayaran gagal disiapkan.'
            );
        }
        $stmt->bind_param('s', $pola);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException(
                'Pembuatan kode pembayaran gagal dijalankan.'
            );
        }
        $terakhir = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $nomorBerikutnya = 1;
        if ($terakhir !== null) {
            $bagianKode = explode('/', (string) $terakhir['kode_bayar']);
            $nomorBerikutnya = ((int) end($bagianKode)) + 1;
        }
        if ($nomorBerikutnya > 99) {
            throw new RuntimeException(
                'Nomor pembayaran harian telah mencapai batas 99.'
            );
        }
        return sprintf('PAY/%s/%02d', $tanggalKode, $nomorBerikutnya);
    }

    /**
     * P10: Mencatat pelunasan langsung oleh admin.
     *
     * Pembayaran langsung dicatat sebagai valid.
     * Status transaksi diperbarui menjadi lunas dalam transaksi database
     * yang sama. Status kendaraan tidak diubah pada P10.
     */
    public function simpanPelunasan(
        string $kodeSewa,
        string $metodeBayar,
        ?string $namaFileBukti = null
    ): string {
        $kodeSewa = trim($kodeSewa);
        $metodeBayar = trim($metodeBayar);
        if ($kodeSewa === '') {
            throw new InvalidArgumentException(
                'Kode transaksi sewa wajib diisi.'
            );
        }
        if (!in_array($metodeBayar, ['tunai', 'transfer'], true)) {
            throw new InvalidArgumentException(
                'Metode pembayaran tidak valid.'
            );
        }
        if ($metodeBayar === 'transfer') {
            $namaFileBukti = trim((string) $namaFileBukti);
            if ($namaFileBukti === '') {
                throw new InvalidArgumentException(
                    'Bukti transfer wajib tersedia.'
                );
            }
        } else {
            $namaFileBukti = null;
        }

        // Harus sama dengan kunci yang digunakan Transaksi_model::checkout()
        // ketika membuat kode pembayaran DP.
        $namaKunci = 'dpwl_payment_code_sequence';
        $kunciDiperoleh = false;
        $transaksiDimulai = false;

        try {
            $stmt = $this->db->prepare(
                'SELECT GET_LOCK(?, 10) AS lock_status'
            );
            if (!$stmt) {
                throw new RuntimeException(
                    'Kunci pembuatan kode pembayaran gagal disiapkan.'
                );
            }
            $stmt->bind_param('s', $namaKunci);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException(
                    'Kunci pembuatan kode pembayaran gagal dijalankan.'
                );
            }
            $hasilKunci = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ((int) ($hasilKunci['lock_status'] ?? 0) !== 1) {
                throw new RuntimeException(
                    'Proses pembayaran sedang digunakan. Silakan coba kembali.'
                );
            }
            $kunciDiperoleh = true;

            $this->db->begin_transaction();
            $transaksiDimulai = true;

            // Kunci transaksi agar dua permintaan pelunasan tidak berjalan
            // bersamaan untuk transaksi yang sama.
            $stmt = $this->db->prepare(
                "SELECT
                    s.kode_sewa,
                    s.status_sewa,
                    COALESCE((
                        SELECT SUM(tp.hrg_sewa * tp.n_hari)
                        FROM t_pilih tp
                        WHERE tp.kode_sewa = s.kode_sewa
                    ), 0) AS total_transaksi,
                    COALESCE((
                        SELECT SUM(b.nominal)
                        FROM t_bayar b
                        WHERE b.kode_sewa = s.kode_sewa
                        AND b.sttus_byr = 'valid'
                    ), 0) AS total_terbayar
                FROM t_sewa s
                WHERE s.kode_sewa = ?
                LIMIT 1
                FOR UPDATE"
            );
            if (!$stmt) {
                throw new RuntimeException(
                    'Pemeriksaan transaksi pelunasan gagal disiapkan.'
                );
            }
            $stmt->bind_param('s', $kodeSewa);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException(
                    'Pemeriksaan transaksi pelunasan gagal dijalankan.'
                );
            }
            $transaksi = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($transaksi === null) {
                throw new RuntimeException(
                    'Transaksi sewa tidak ditemukan.'
                );
            }
            if (!in_array(
                $transaksi['status_sewa'],
                ['disetujui', 'lunas'],
                true
            )) {
                throw new RuntimeException(
                    'Status transaksi belum memenuhi syarat pelunasan.'
                );
            }
            $totalTransaksi = (int) $transaksi['total_transaksi'];
            $totalTerbayar = (int) $transaksi['total_terbayar'];
            $sisaKewajiban = $totalTransaksi - $totalTerbayar;
            if ($totalTransaksi <= 0 || $sisaKewajiban <= 0) {
                throw new RuntimeException(
                    'Transaksi tidak memiliki sisa kewajiban untuk dilunasi.'
                );
            }
            if ($sisaKewajiban > 8388607) {
                throw new RuntimeException(
                    'Sisa kewajiban melebihi kapasitas kolom nominal pada database.'
                );
            }

            // Pastikan transaksi belum memiliki record pelunasan.
            $stmt = $this->db->prepare(
                "SELECT kode_bayar
                FROM t_bayar
                WHERE kode_sewa = ?
                AND jns_byr = 'pelunasan'
                LIMIT 1
                FOR UPDATE"
            );
            if (!$stmt) {
                throw new RuntimeException(
                    'Pemeriksaan pelunasan sebelumnya gagal disiapkan.'
                );
            }
            $stmt->bind_param('s', $kodeSewa);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException(
                    'Pemeriksaan pelunasan sebelumnya gagal dijalankan.'
                );
            }
            $pelunasanAda = $stmt->get_result()->fetch_assoc() !== null;
            $stmt->close();
            if ($pelunasanAda) {
                throw new RuntimeException(
                    'Transaksi ini sudah memiliki record pelunasan.'
                );
            }

            $hasilWaktu = $this->db->query(
                "SELECT
                    DATE_FORMAT(NOW(), '%Y%m%d') AS tanggal_kode,
                    NOW() AS waktu_pembayaran"
            );
            if (!$hasilWaktu) {
                throw new RuntimeException(
                    'Waktu pembayaran gagal dibaca.'
                );
            }
            $waktu = $hasilWaktu->fetch_assoc();
            $hasilWaktu->free();
            $tanggalKode = (string) ($waktu['tanggal_kode'] ?? '');
            $waktuPembayaran = (string) ($waktu['waktu_pembayaran'] ?? '');
            if (
                !preg_match('/^\d{8}$/', $tanggalKode) ||
                $waktuPembayaran === ''
            ) {
                throw new RuntimeException(
                    'Waktu pembayaran tidak valid.'
                );
            }

            $kodeBayar = $this->buatKodeBayarPelunasan($tanggalKode);

            $stmt = $this->db->prepare(
                "INSERT INTO t_bayar (
                    kode_bayar,
                    tgl_wkt_byr,
                    ft_byr,
                    mtode_byr,
                    jns_byr,
                    nominal,
                    sttus_byr,
                    tgl_wkt_ver,
                    kode_sewa
                ) VALUES (?, ?, ?, ?, 'pelunasan', ?, 'valid', NOW(), ?)"
            );
            if (!$stmt) {
                throw new RuntimeException(
                    'Pencatatan pelunasan gagal disiapkan.'
                );
            }
            $stmt->bind_param(
                'ssssis',
                $kodeBayar,
                $waktuPembayaran,
                $namaFileBukti,
                $metodeBayar,
                $sisaKewajiban,
                $kodeSewa
            );
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException(
                    'Pelunasan gagal disimpan.'
                );
            }
            $stmt->close();

            // Pelunasan berhasil dicatat; perbarui status transaksi
            // dalam transaksi database yang sama.
            $stmt = $this->db->prepare(
                "UPDATE t_sewa
                SET status_sewa = 'lunas'
                WHERE kode_sewa = ?
                AND status_sewa IN ('disetujui', 'lunas')"
            );
            if (!$stmt) {
                throw new RuntimeException(
                    'Pembaruan status transaksi menjadi lunas gagal disiapkan.'
                );
            }
            $stmt->bind_param('s', $kodeSewa);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException(
                    'Status transaksi gagal diperbarui menjadi lunas.'
                );
            }
            if ($stmt->affected_rows !== 1) {
                $stmt->close();
                throw new RuntimeException(
                    'Status transaksi tidak berubah menjadi lunas. '
                        . 'Periksa kembali status transaksi.'
                );
            }
            $stmt->close();

            $this->db->commit();
            $transaksiDimulai = false;

            return $kodeBayar;
        } catch (Throwable $e) {
            if ($transaksiDimulai) {
                $this->db->rollback();
            }
            throw $e;
        } finally {
            if ($kunciDiperoleh) {
                $stmt = $this->db->prepare(
                    'SELECT RELEASE_LOCK(?)'
                );
                if ($stmt) {
                    $stmt->bind_param('s', $namaKunci);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }
    }
}
