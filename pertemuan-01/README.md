# pertemuan-01

Nama: Diandra Syahputra
NIM: 2522500001
Kelas: SI3A

 1. Kesinambungan PWD - DPW - DPWL
- PWD: mempelajar dasar web seperti HTML dan CSS dan webnya masih statis.
- DPW: mepeelajari pembentukan web dinamis menggunakan PHP native dan tersambung ke database MySQL.
- DPWL: ini adalah kelanjutannya, web menggunakan pola MVC agar kode lebih rapi dan terstruktur untuk project yang lebih besar.

 2. Perbedaan PHP Terstruktur dan MVC
Kalau PHP terstruktur kodenya tercampur menjadi satu file (HTML, PHP, Query SQL disatukan), sulit untuk mencari eror dimana. jika MVC, kodenya dipisah jadi 3 bagian utama (Model, View, Controller) agar  lebih gampang dibaca dan disusun.

 3. Fungsi Model, View, dan Controller
- Model: Bagian yang khusus mengurusi komunikasi ke database (CRUD).
- View: Bagian yang mengurusi tampilan web yang dilihat user (HTML/CSS).
- Controller: Bagian yang jadi pengatur lalu lintas antara View dan Model.

 4. Alur Request-Response MVC
User request ke browser -> diterima oleh Controller -> Controller minta data ke Model -> Model mengambil data dari database lalu diberikan balik ke Controller -> Controller mengirim data itu ke View -> View menampilkan halaman web ke user.

 5. Pemetaan Fitur Aplikasi ke MVC (Sistem Pendaftaran Les)
Berdasarkan aplikasi web pendaftaran les yang dikerjakan:
- Model: mengurusi query `INSERT` data pendaftaran ke database `db_les_uas` dan memengambil datanya.
- View: halaman form pendaftaran UI dan halaman tabel list pendaftar.
- Controller: menerima inputan submit dari user, memproses datanya, lalu memerintahkan model menyimpan datanya ke DB, dan mengarahkan user ke halaman sukses (View).

 6. Kesimpulan P1
Menggunakan arsitektur MVC itu penting untuk menulis kode yang bersih dan terorganisir. Walaupun setup awalnya agak sulit dibanding PHP native, tapi kalau aplikasinya semakin besar, kan jauh lebih mudah maintenance nya.
