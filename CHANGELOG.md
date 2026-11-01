# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [v0.3.0] - 2026-11-01

### Added
- **Gantt Chart Roster Bulanan Dual-Axis Sticky**: Tampilan matriks kalender penjadwalan kerja 24 jam interaktif dengan header tanggal dan kolom staf *sticky* bebas *double-scroll* via slot `@slot('fullbleed')`.
- **Drag-and-Drop Shift Palette**: Penugasan jadwal cepat dari palet template shift dan tombol libur *OFF / DP* langsung ke sel kalender karyawan.
- **Mesin Resize Presisi InteractJS**: Penyesuaian mikro jam masuk dan pulang shift melalui handle penarik dengan *snapping* 15 menit, kalkulasi sirkular modulo 24 jam, dan *floating tooltip* real-time.
- **Visualisasi Shift Lintas Hari (Overnight / Cross-Day)**: Dukungan penuh shift melewati tengah malam (misal `NI` 23:00–07:00 dan `MOD` 19:00–07:00) lengkap dengan balok sambungan otomatis pada tanggal berikutnya.
- **Linimasa 24 Jam Expandable**: Kemampuan memperlebar kolom tanggal kalender menjadi linimasa 24 jam dengan 6 panduan segmen waktu (interval 4 jam) untuk audit visual *overlap* jadwal staf.
- **Mesin Touch-Drag & Touch-Tap Mobile**: Dukungan gestur seret layar sentuh pada perangkat Android dan iOS dengan pencegahan *native scroll*, elemen klon melayang *hardware-accelerated*, deteksi target *drop* via `elementFromPoint`, serta mode ketukan alternatif.
- **Deteksi Turnaround Istirahat (< 8 Jam)**: Mesin evaluasi *soft turnaround* non-blocking yang mendeteksi jeda istirahat tidak memadai antar-shift berdekatan dengan dialog konfirmasi persetujuan (*override*).
- **Formasi Operasional 24 Jam & Gap Detector**: Pelacakan otomatis kecukupan kuota minimum staf departemen 24 jam pada shift pagi (`MO`), siang (`AF`), dan malam (`NI`) dengan indikator badge `GAP`.
- **Audit Penugasan Manager on Duty (MOD)**: Baris audit harian ketersediaan penanggung jawab operasional hotel dan filter visual khusus staf bertag `MOD_ELIGIBLE`.
- **Siklus Hidup Draf & Ghost Pending-Delete**: Penandaan visual jadwal draf baru (transparansi 70% bergaris putus-putus) dan bayangan *ghost* jadwal terpublikasi yang dihapus (transparansi 30% bergaris coret merah) sebelum publikasi resmi.
- **Fitur Batalkan Ubahan Draf (Discard Changes)**: Endpoint `POST /roster/discard` dan tombol aksi toolbar dengan dialog konfirmasi untuk membatalkan seluruh mutasi draf seketika.
- **Modal Pengaturan Master Shift & Immutabilitas Historis**: Modal konfigurasi shift kerja dengan jaminan immutabilitas jadwal historis (snapshot jam kerja) serta formulir pembuatan master shift baru.
- **Modul Edit & Hapus Staf Direktori**: Halaman edit profil kepegawaian dan tombol hapus staf minimalis dengan dialog konfirmasi destruktif native `<dialog>`, dilengkapi proteksi RBAC ketat dan pencegahan penghapusan akun sendiri.
- **Tag Fungsional `VIEW_ALL_SCHEDULES`**: Akses peninjauan kalender roster lintas departemen (*read-only*) dengan proteksi isolasi mutasi tetap pada departemen manajer yang bersangkutan.
- **Multi-Superuser Provisioning**: Kemampuan pendaftaran multi *super user* setara di dalam satu entitas perusahaan.
- **Indikator Beban Kerja Mingguan**: Deteksi dan penanda visual otomatis karyawan dengan akumulasi jam kerja melebihi standar 40 jam/minggu.

### Changed
- **Pembaruan Skema Relasional PostgreSQL 18**: Penambahan tabel `shifts` dan `work_schedules` dengan indeks gabungan `(company_id, schedule_date)` dan `(employee_id, schedule_date)`.
- **Dukungan Multi-Shift per Hari**: Pelepasan batasan *unique constraint* tunggal pada `work_schedules` untuk mendukung karyawan memiliki lebih dari satu shift dalam tanggal yang sama (*split-shift*).
- **Perbaikan Stacking Context Topbar**: Penataan ulang hirarki *z-index* header topbar (`z-50`) agar menu *dropdown* profil dan *Logout* tidak tertutup oleh sel *sticky* matriks kalender (`z-30`).
- **Optimalisasi Animasi Kolom Staf**: Penerapan transisi lebar terakselerasi perangkat keras dengan kurva *cubic-bezier* saat kolom nama staf diciutkan ke mode *rail* (`w-16`) maupun dilebarkan (`w-64`).
- **Paritas Lokalisasi (i18n) 100%**: Harmonisasi seluruh kunci terjemahan pada kamus `lang/id` dan `lang/en` untuk modul `roster` dan `staff`.

### Fixed
- **Preservasi Jadwal Published saat Batal Resize**: Penambahan kolom `published_start_time` dan `published_end_time` pada tabel `work_schedules` untuk memastikan jadwal terpublikasi yang di-*resize* lalu dibatalkan drafnya kembali ke ukuran awal alih-alih terhapus.
- **Propagasi Event Resize**: Perbaikan *event bubbling* pada pegangan resize kiri dan kanan dengan mengeliminasi `@mousedown.stop` yang memblokir penangkapan gesture oleh InteractJS.
- **Kalkulasi Durasi Modulo 24 Jam**: Koreksi rumus kalkulasi jam kerja untuk shift malam/lintas hari sehingga durasi dihitung akurat berdasarkan $(T_{\text{end}} + 24) - T_{\text{start}}$.
- **Pembersihan Integritas Referensi Hapus Staf**: Penanganan relasi bawahan (`manager_id = null`), jadwal kerja, dan akun pengguna saat seorang karyawan dihapus.

## [v0.2.0] - 2026-11-01

### Added
- **Infrastruktur Multi-Tenant**: Arsitektur server dan skema database relasional PostgreSQL 18 terisolasi untuk mendukung multi-tenancy antar properti.
- **Dual-Auth Engine**: Sistem autentikasi ganda yang mendukung Google OAuth 2.0 berbasis whitelist serta opsi login manual menggunakan username/email dan kata sandi.
- **Closed Provisioning**: Pengelolaan penambahan staf internal terisolasi per departemen dengan dukungan alamat email Google personal (@gmail.com).
- **5-Tier RBAC Policies**: Tata kelola otorisasi 5 tingkat (*Super Admin*, *Corporate Executive*, *HR Admin*, *Department Manager*, dan *Staff*).
- **Dynamic Tenant Branding**: Injeksi warna aksen dan identitas visual masing-masing properti hotel secara dinamis.
- **Design System Opsi C**: Token warna semantik mandiri, komponen dialog modal native, dan tabel data berdensitas tinggi.
- **WCAG AA Contrast Engine**: Mesin kalkulasi rasio kontras warna otomatis di sisi server untuk memastikan keterbacaan teks memenuhi standar WCAG AA (rasio ≥ 4.5:1).
- **Sistem Multi-Bahasa (i18n)**: Dukungan penuh dwi-bahasa (Indonesia dan Inggris) dengan resolusi preferensi berjenjang.
- **Unified Chrome Frame**: Tata letak layar penuh terpadu dengan navigasi mulus dan isolasi scrollbar konten.
- **Nomenklatur DP (Day of Payment)**: Standarisasi istilah DP untuk pencatatan hak libur pengganti dan kompensasi shift operasional.

### Changed
- **Pelepasan Dependensi DaisyUI**: Mengganti komponen bawaan dengan styling mandiri untuk mencegah konflik warna aksen korporat.
- **Peningkatan Kontras Dark Mode**: Optimalisasi palet warna tema gelap untuk kenyamanan visual dan kepatuhan aksesibilitas.
- **Pengalihan Rute Utama**: Akses rute utama otomatis diarahkan ke halaman login atau dashboard operasional.
- **Pencegahan Layout Shift (Anti-FOUS)**: Transisi mulus pada navigasi dan sidebar tanpa kedipan tampilan.

### Removed
- **Penutupan Registrasi Publik**: Penonaktifan pendaftaran mandiri demi keamanan tata kelola akun internal hotel.
