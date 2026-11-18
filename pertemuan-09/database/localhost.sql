-- ============================================================
-- Ekspor basis data UTS P8 DPWL - db_dpwl_diandra
-- Impor: phpMyAdmin > Import, atau
--   mysql -u root < database/localhost.sql
-- Akun awal admin  : username "admin"  | password "password"
-- Akun awal penyewa: "budi01", "sari02" | password "password"
-- (ganti lewat menu Manajemen Admin / Manajemen Penyewa)
-- ============================================================
CREATE DATABASE IF NOT EXISTS `db_dpwl_diandra`
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `db_dpwl_diandra`;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `t_bayar`, `t_pilih`, `t_sewa`, `t_mobil`, `t_penyewa`, `t_admin`;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `t_admin` (
  `username` varchar(15) NOT NULL,
  `password` varchar(255) NOT NULL,
  `status_akun` enum('aktif','nonaktif') NOT NULL DEFAULT 'aktif',
  PRIMARY KEY (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `t_penyewa` (
  `user_plg` varchar(15) NOT NULL,
  `sandi` varchar(255) NOT NULL,
  `nik` char(16) NOT NULL,
  `nm_lngkp` varchar(100) NOT NULL,
  `no_hp` varchar(15) NOT NULL,
  `almt_lngkp` varchar(300) NOT NULL,
  `ft_ktp` varchar(100) DEFAULT NULL,
  `ft_sim_a` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`user_plg`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `t_mobil` (
  `no_plat` varchar(15) NOT NULL,
  `nm_mobil` varchar(40) NOT NULL,
  `nkursi` tinyint NOT NULL,
  `nbagasi` tinyint NOT NULL,
  `jns_mobil` enum('SUV','MPV','City Car') NOT NULL,
  `transmisi` enum('AT','MT') NOT NULL,
  `thn_buat` year NOT NULL,
  `ft_depan` varchar(100) DEFAULT NULL,
  `ft_blkg` varchar(100) DEFAULT NULL,
  `ft_interior` varchar(100) DEFAULT NULL,
  `hrg_hari` mediumint unsigned NOT NULL,
  `cttn_unit` varchar(500) NOT NULL,
  `status_unit` enum('tersedia','disewa','tidaktersedia') NOT NULL DEFAULT 'tersedia',
  PRIMARY KEY (`no_plat`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `t_sewa` (
  `kode_sewa` varchar(20) NOT NULL,
  `tgl_mulai` date NOT NULL,
  `tgl_selesai` date NOT NULL,
  `wkt_ambil` time NOT NULL,
  `status_sewa` varchar(20) NOT NULL DEFAULT 'keranjang',
  `user_plg` varchar(15) NOT NULL,
  PRIMARY KEY (`kode_sewa`),
  KEY `fk_sewa_penyewa` (`user_plg`),
  CONSTRAINT `fk_sewa_penyewa` FOREIGN KEY (`user_plg`) REFERENCES `t_penyewa` (`user_plg`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `t_pilih` (
  `kode_sewa` varchar(20) NOT NULL,
  `no_plat` varchar(15) NOT NULL,
  `hrg_sewa` int unsigned NOT NULL,
  `n_hari` smallint unsigned NOT NULL,
  PRIMARY KEY (`kode_sewa`,`no_plat`),
  KEY `fk_pilih_mobil` (`no_plat`),
  CONSTRAINT `fk_pilih_sewa` FOREIGN KEY (`kode_sewa`) REFERENCES `t_sewa` (`kode_sewa`) ON DELETE CASCADE,
  CONSTRAINT `fk_pilih_mobil` FOREIGN KEY (`no_plat`) REFERENCES `t_mobil` (`no_plat`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `t_bayar` (
  `kode_bayar` varchar(25) NOT NULL,
  `tgl_wkt_byr` datetime NOT NULL,
  `ft_byr` varchar(100) DEFAULT NULL,
  `mtode_byr` varchar(20) NOT NULL,
  `jns_byr` varchar(20) NOT NULL,
  `nominal` int unsigned NOT NULL,
  `sttus_byr` varchar(20) NOT NULL DEFAULT 'pending',
  `tgl_wkt_ver` datetime DEFAULT NULL,
  `kode_sewa` varchar(20) NOT NULL,
  PRIMARY KEY (`kode_bayar`),
  KEY `fk_bayar_sewa` (`kode_sewa`),
  CONSTRAINT `fk_bayar_sewa` FOREIGN KEY (`kode_sewa`) REFERENCES `t_sewa` (`kode_sewa`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Data awal (hash bcrypt dari password "password")
INSERT INTO `t_admin` (`username`, `password`, `status_akun`) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'aktif');

INSERT INTO `t_penyewa`
(`user_plg`, `sandi`, `nik`, `nm_lngkp`, `no_hp`, `almt_lngkp`, `ft_ktp`, `ft_sim_a`) VALUES
('budi01', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '3171010101900001', 'Budi Santoso', '081234567890', 'Jl. Melati No. 12, Jakarta Selatan', 'ft_ktp_20261021050909_6908beecd85eba25.jpg', 'ft_sim_a_20261021050909_61858f0b78ef60b6.jpg'),
('sari02', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '3171010202950002', 'Sari Wulandari', '085712345678', 'Jl. Kenanga No. 5, Jakarta Timur', 'ft_ktp_20261022031946_23e8812660aea9cf.png', 'ft_sim_a_20261022031946_92e2760e01c2bd8e.png');
