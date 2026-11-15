# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [v0.5.0] - 2026-11-15

### Added
- **Buku Besar Cuti Append-Only & Deplesi Saldo FIFO (`LeaveLedgerService`)**: Penambahan tabel `leave_types`, `leave_ledger_entries`, dan `company_leave_type_settings` beserta seeder `LeaveTypeSeeder` untuk 5 tipe cuti standar (`AL`, `DP`, `SL`, `SPL`, `UL`). Seluruh mutasi saldo (`accrual`, `credit`, `debit`, `expired`, `adjustment`) bersifat *append-only* yang tidak dapat dihapus/diubah pada kolom inti, dilengkapi deplesi kuota secara **FIFO (*First-Expiring, First-Out*)** dengan *row-level locking* (`lockForUpdate`), konsol riwayat mutasi (50 transaksi terakhir), rekonsiliasi saldo manual oleh HR/Admin (`POST /leave/ledger/adjust`), serta konfigurasi masa kedaluwarsa (`validity_months`) dan kuota tahunan default (`default_annual_quota`) per perusahaan (`PUT /leave/types/{leaveType}/settings`).
- **Two-Phase DP Commit Engine (`DpCommitService`)**: Penambahan kolom `dp_committed_at` dan `dp_ledger_entry_id` pada tabel `attendances` untuk mengonversi status *Provisional DP* menjadi kredit resmi `+1.00` hari `DP` di buku besar secara otomatis dan idempoten apabila presensi hari libur/event memiliki *clock-in* & *clock-out* sah (`on_time`, `late`, `early_leave`) dengan durasi kerja minimal 4,0 jam—terintegrasi pada alur *clock-out* (`AttendanceController::clockOut`), koreksi administratif (`AttendanceController::update`), persetujuan dinas luar (`AttendanceVerificationController::approve`), dan sinkronisasi perubahan kalender libur.
- **Modul Pengajuan Cuti Mandiri & Reservasi Kuota (`LeaveRequestService`, `LeaveAttachmentService`)**: Halaman `/leave` dengan ringkasan saldo buku besar, kuota tereservasi, dan saldo tersedia (`AL` & `DP`), pratinjau perhitungan hari kerja efektif berbasis roster terpublikasi (`GET /leave/calculate-days`) yang otomatis mengecualikan jadwal `OFF` reguler, validasi anti-overlap tanggal, serta mekanisme reservasi kuota saat pengajuan tanpa pemotongan prematur sebelum persetujuan final.
- **Lampiran Bukti Cuti Terkompresi & Aman (`LeaveAttachmentService`)**: Unggahan bukti wajib untuk tipe cuti `SL`/`SPL` dan opsional lainnya dalam format gambar (JPG, PNG, WebP terkompresi $\le 250$ KB via Intervention Image v4) maupun PDF ($\le 500$ KB) yang disimpan di direktori privat `leave_attachments/{company_id}/...`, diperhitungkan ke dalam kuota penyimpanan tenant, dan disajikan melalui endpoint otorisasi ketat `GET /leave/{leaveRequest}/attachment`.
- **Alur Persetujuan Berjenjang 2-Tier, Edit & Pembatalan Cuti (`LeaveApprovalService`, `LeaveRequestPolicy`)**: Penambahan tabel `leave_request_approvals` serta kolom audit persetujuan/penolakan pada `leave_requests`. Mendukung persetujuan **Tier 1 (Manajer Departemen)** (`pending_manager` $\rightarrow$ `pending_hr`) dengan pencegahan *self-approval*, **Tier 2 (Final HR/Admin)** (`pending_hr` $\rightarrow$ `approved`) yang memotong saldo FIFO secara atomik dan mengunci rentang tanggal cuti di roster menjadi `OFF` bertanda `LEAVE:`, penolakan beralasan minimal 5 karakter (`POST /leave/{leaveRequest}/reject`), serta pengeditan (`PUT /leave/{leaveRequest}`) dan pembatalan (`POST /leave/{leaveRequest}/cancel`) termasuk pada cuti yang telah `approved` dengan *append-only cancellation refund* dan pelepasan kunci roster.
- **SPK Metode SMART untuk Keputusan Cuti (`SmartDecisionService`, `SmartDepartmentWeight`)**: Penambahan tabel `smart_department_weights` dan sistem pendukung keputusan berbasis 5 kriteria telemetri kuantitatif: `C1` (*Manpower Coverage* / Benefit), `C2` (*Balance Expiry Proximity* / Cost), `C3` (*Attendance Discipline* 90 hari / Benefit), `C4` (*Recent Leave Frequency* 90 hari / Cost), dan `C5` (*Submission Lead Time* / Benefit). Mendukung pembobotan dinamis per departemen (`PUT /leave/smart-weights`) dengan normalisasi tepat `1.0000`, evaluasi mandiri (**Mode B**), dan komparasi multi-staf beserta simulasi kuota formasi departemen (**Mode A**) pada `GET /leave/{leaveRequest}/smart-analysis`.
- **Otomasi Scheduler Akrual Tahunan & Penyapuan Saldo Kedaluwarsa**: Penambahan command Artisan `leave:accrue-annual` (`--year=`) untuk akrual cuti tahunan (`AL`) idempoten dan `leave:prune-expired` untuk menyapu *bucket* saldo kedaluwarsa serta mencatat entri `expired`, keduanya terjadwal berjalan harian (`->daily()`) pada `routes/console.php`.
- **Manajemen Kalender Hari Libur & Event Khusus Per Perusahaan (`CompanySettingsController`, `PublicHoliday`)**: Isolasi `public_holidays` per tenant (`company_id` NOT NULL dan kolom `is_national`) dengan replikasi otomatis libur nasional bawaan saat perusahaan baru dibuat, antarmuka CRUD kalender libur/event khusus di `/settings/company` (`POST /settings/holidays`, `PUT/PATCH /settings/holidays/{publicHoliday}`, `DELETE /settings/holidays/{publicHoliday}`), sinkronisasi flag `is_provisional_dp` pada presensi terkait, serta penanda visual libur/event di header tanggal matriks Roster.
- **Auto-Assign Shift Terdekat saat Clock-In Tanpa Jadwal (`AttendanceGeofenceService`)**: Penugasan otomatis shift operasional aktif terdekat menggunakan jarak waktu sirkular 24 jam (`findNearestShiftForClockIn`, `autoAssignNearestShift`) ketika staf melakukan *clock-in* tanpa jadwal atau pada hari libur reguler non-cuti (dengan pengecualian shift `MOD` untuk staf non-`MOD_ELIGIBLE`).
- **Rangkaian Pengujian Fitur Baru**: Penambahan 7 test suite fitur baru (`AutoAssignNearestShiftTest`, `CompanyHolidayManagementTest`, `LeaveApprovalWorkflowTest`, `LeaveLedgerTest`, `LeaveRequestTest`, `SmartDecisionEngineTest`, `TwoPhaseDpCommitTest`) dengan total keseluruhan kini 188 pengujian dan 4.838 asersi lolos 100%.

### Changed
- **Proteksi Cuti Terintegrasi pada Presensi & Matriks Roster**: Pemblokiran otomatis *clock-in* masuk maupun dinas luar (`AttendanceController`, `AttendanceGeofenceService::isEmployeeOnApprovedLeave`) selama masa cuti disetujui, serta penguncian mutasi jadwal pada `RosterController` (`assign`, `resize`, `split`, `destroy`, `restore`) dan `resources/js/roster.js` untuk jadwal bertanda `LEAVE:`, disertai pengecualian jadwal cuti terkunci saat `unpublish` roster dan pembaruan `WorkSchedule::toMatrixPayload()`.
- **Pembaruan Dashboard, Navigasi & Kalkulasi Storage**: Penambahan kartu dan badge saldo aktif `AL` dan `DP` secara real-time pada dashboard utama (`DashboardController`, `resources/views/dashboard.blade.php`), pembaruan badge status `DP Committed +1.0` vs `Provisional DP`, aktivasi tautan modul *Cuti & Izin* (`/leave`) di sidebar dan mobile drawer, serta perluasan `Company::getStorageUsageBytes()` untuk menghitung berkas di `leave_attachments/{company_id}`.
- **Standardisasi Ikon SVG & Paritas Kamus Lokalisasi (i18n)**: Penggantian simbol Unicode/emoji dekoratif (`✦`, `×`, `«/»`, `←`, `→`, `↺`, `●`, `▲`, `Ø`) pada antarmuka dashboard, roster, presensi, verifikasi, staf, dan pemilih bahasa dengan ikon SVG konsisten, serta penambahan kamus `lang/id/leave.php` & `lang/en/leave.php` dan pembaruan kunci di `attendance.php`, `company.php`, `dashboard.php`, `nav.php`, `roster.php`, dan `staff.php` dengan paritas 100%.

### Fixed
- **Penyelarasan Evaluasi Shift Lintas Tengah Malam (*Cross-Midnight*)**: Koreksi kalkulasi selisih waktu bertanda pada `AttendanceGeofenceService::evaluateClockIn()` ketika deviasi sirkular melebihi 12 jam (720 menit) agar status ketepatan waktu dan `late_minutes` akurat pada shift malam maupun shift hasil *auto-assign*.
- **Isolasi Multi-Tenant Kalender Hari Libur**: Penghapusan ketergantungan baris global `company_id IS NULL` pada `public_holidays` melalui migrasi replikasi per perusahaan dan trait `BelongsToCompany`, memastikan pengelolaan tanggal merah dan event properti sepenuhnya terisolasi antar-tenant.
- **Perbaikan Path SVG Kosong pada Roster**: Koreksi atribut `d` pada ikon SVG keadaan kosong di `resources/views/roster/index.blade.php`.

## [v0.4.0] - 2026-11-06

### Added
- **Presensi Geofencing GPS Real-Time (Attendance Core Engine)**: Layanan `AttendanceGeofenceService` berbasis formula Haversine untuk memvalidasi posisi presensi staf terhadap koordinat properti dan radius toleransi (20m–1000m), tabel `attendances` terindeks, widget presensi berpresisi tinggi pada dashboard utama dengan jam digital waktu nyata (WIB), dan radar jarak GPS dinamis.
- **Presensi Multi-Sesi Split-Shift**: Dukungan pencatatan presensi independen untuk shift kerja yang dibelah (*split-shift*), memungkinkan perekaman sesi 1 (*Session 1*) dan sesi 2 (*Session 2*) secara terpisah pada hari kalender yang sama dengan validasi geofence dan swafoto mandiri.
- **Universal Mandatory Selfie Capture**: Kewajiban pengambilan swafoto pada seluruh alur presensi (*Clock In*, *Clock Out*, dan Dinas Luar) dengan penguncian sensor kamera depan gawai (`capture="user"`), meniadakan dialog unggah berkas/galeri foto demi mencegah manipulasi identitas kehadiran.
- **Pipeline Kompresi Citra WebP & Sanitasi EXIF**: Optimasi swafoto berlapis menggunakan *pre-compression* HTML5 Canvas sisi klien ($\le 1280$px) dan kompresi server-side ke format WebP ($\le 250$ KB) via `Intervention\Image` (v4) dengan pembersihan seluruh metadata sensitif (EXIF).
- **Proteksi Kuota Storage Tenant & Auto-Erase Retention**: Penambahan atribut kuota penyimpanan berkas per perusahaan (`companies.storage_limit_mb`) yang dikendalikan eksklusif oleh *Super Admin*, kebijakan durasi retensi swafoto (`companies.photo_retention_months`: 1, 3, 6, 12 bulan), serta *scheduled command* harian `attendance:prune-photos` pada `routes/console.php` untuk menghapus berkas fisik kadaluarsa secara otomatis dan menandai status `photo_expired`.
- **Presensi Dinas Luar (Outside Duty Attendance)**: Endpoint `POST /attendance/outside-clock-in` bagi staf yang bertugas di luar radius geofence hotel, dilengkapi pilihan kategori tugas (*client meeting*, *airport transfer*, *purchasing*, *outdoor event*, *other*), destinasi tugas, catatan tujuan, dan swafoto wajib dengan status awal *Pending Verification*.
- **Konsol Verifikasi Presensi Dinas Luar Manajer**: Halaman verifikasi khusus (`/attendance/verifications`) bagi manajer departemen dan administrator untuk meninjau pengajuan tugas luar dengan inspeksi deviasi koordinat pada peta interaktif Leaflet.js, serta modal persetujuan dan penolakan dengan alasan penolakan wajib (*mandatory rejection notes*).
- **Streaming Foto Presensi Aman (Secure Proof Streamer)**: Endpoint terproteksi `GET /attendance/{attendance}/proof?type=in|out` dengan otorisasi ketat `AttendancePolicy` berbasis multi-tenancy, mengirimkan stream biner citra privat dengan *headers* keamanan `Cache-Control: private` dan `X-Content-Type-Options: nosniff`.
- **Log Audit & Rekonsiliasi Presensi Administratif**: Antarmuka riwayat presensi komprehensif (`/attendance`) dengan cuplikan thumbnail swafoto masuk/pulang, modal inspeksi resolusi tinggi, serta wewenang administratif penuh bagi *Admin*, *Super User*, dan *Super Admin* untuk mengoreksi cap waktu, jarak, dan status presensi (`PUT /attendance/{attendance}`) maupun menghapus rekod presensi (`DELETE /attendance/{attendance}`) disertai pembersihan fisik berkas swafoto.
- **Pelacakan Otomatis Provisional DP Hari Libur Nasional**: Tabel `public_holidays` dan seeder kalender hari libur nasional (`PublicHolidaySeeder`), dengan deteksi otomatis penugasan kerja pada tanggal merah yang menandai rekod presensi dengan bendera `is_provisional_dp = true` tanpa mengubah saldo buku besar cuti secara prematur (*ledger isolation*).
- **Manajemen Master Departemen & Safe-Delete Protection**: Modul pengelolaan departemen (`/departments`) dengan konfigurasi operasional 24 jam (`is_24_hours`), kuota minimum staf per shift (`min_staff_per_shift`), deskripsi, validasi keunikan kode per tenant, serta proteksi penghapusan aman (*safe delete*) yang memblokir penghapusan departemen jika masih memiliki staf aktif atau jadwal terikat.
- **Pengaturan Properti Multi-Tenant (Company Settings)**: Halaman konfigurasi properti (`/settings/company`) untuk memperbarui koordinat GPS, radius geofence, zona waktu Indonesia (`Asia/Jakarta`, `Asia/Makassar`, `Asia/Jayapura`), dan durasi retensi swafoto, dilengkapi bilah kemajuan (*progress bar*) visual penggunaan kuota penyimpanan server dengan indikator warna berbasis ambang batas kapasitas.
- **Rangkaian Pengujian Fitur Komprehensif**: Penambahan 10 test suite fitur baru (`AttendanceAdminTest`, `AttendanceGeofenceTest`, `AttendanceVerificationTest`, `CompanySettingsTest`, `DashboardTest`, `DepartmentManagementTest`, `OutsideAttendanceTest`, `ProvisionalDpTrackerTest`, `SplitShiftAttendanceTest`, `UniversalSelfieTest`) dengan total 146 pengujian dan 3.514 asersi lolos 100%.

### Changed
- **Penyaringan Ketat Tag MOD pada Modal Jadwal Hari Ini**: Logika rendering badge `MOD` pada modal linimasa 24 jam dashboard (`resources/views/dashboard.blade.php`) kini secara ketat dievaluasi berdasarkan shift yang dijalankan staf pada hari bersangkutan (`$schedule->shift->code === 'MOD' || $schedule->shift->is_mod`), meniadakan badge MOD jika staf berkualifikasi MOD sedang bertugas pada shift reguler (`MO`, `MD`, `AF`, `NI`, `GO`).
- **Optimalisasi Visibilitas Tombol Presensi & Early Clock-Out Guard**: Tombol aksi *Clock In* dan *Clock Out* pada widget dashboard diperbesar (*high affordance* `w-full py-4 px-6 text-base sm:text-lg rounded-2xl`). Menekan tombol Clock Out sebelum jadwal berakhir ($T_{\text{now}} < T_{\text{end}}$) memicu dialog konfirmasi peringatan (*early clockout guard*) yang merinci jam kepulangan resmi sebelum membuka antarmuka kamera.
- **Peningkatan Kapasitas Payload Nginx**: Konfigurasi batas ukuran berkas unggahan `client_max_body_size` pada Nginx ditingkatkan menjadi `50M` untuk mengantisipasi unggahan swafoto resolusi tinggi dari kamera perangkat seluler modern.
- **Paritas Kamus Lokalisasi Dwi-Bahasa (i18n)**: Penambahan berkas lokalisasi baru `lang/id/attendance.php`, `lang/en/attendance.php`, `lang/id/departments.php`, `lang/en/departments.php`, `lang/id/company.php`, dan `lang/en/company.php` dengan paritas kunci 100%.

### Fixed
- **Penanganan Kesalahan Parsing JSON pada Submit Swafoto**: Penambahan inspeksi header `Content-Type` dan mitigasi pra-kompresi canvas di sisi klien pada `resources/views/dashboard.blade.php` sebelum memanggil `response.json()`, mencegah eksepsi *SyntaxError: Unexpected token '<' in JSON* saat server mengembalikan respons kesalahan non-JSON atau galat ukuran payload HTTP 413.
- **Izin Berkas Log Aplikasi Monolog**: Penyesuaian konfigurasi kanal `single` dan `daily` pada `config/logging.php` dengan opsi `'permission' => 0666` untuk mencegah kesalahan *Permission Denied* pada berkas `storage/logs/laravel.log`.
- **Sinkronisasi Skema Database Produksi PostgreSQL**: Eksekusi penambahan kolom `is_24_hours`, `description`, `geofence_radius`, `storage_limit_mb`, `photo_retention_months`, dan tabel `attendances` pada skema database PostgreSQL server.

## [v0.3.1] - 2026-11-04

### Added
- **Mesin Belah Shift (Split-Shift Engine)**: Endpoint `POST /roster/{workSchedule}/split` dan otorisasi `WorkSchedulePolicy` untuk membagi satu shift aktif menjadi dua jadwal kerja terpisah pada hari yang sama dengan kalkulasi otomatis titik tengah ($T_{\text{mid}}$) presisi, mendukung shift reguler maupun lintas hari (*cross-day*), serta menjaga imutabilitas durasi total ($Durasi_1 + Durasi_2 = Durasi_{\text{awal}}$).
- **Mesin Geser Shift Utuh (Shift Drag-to-Slide)**: Kemampuan menggeser balok shift terpasang secara horizontal di linimasa 24 jam menggunakan InteractJS (`lockAxis: 'x'`) dengan *snapping* kelipatan 15 menit, *floating tooltip* transformasi waktu real-time, dan preservasi durasi bersih shift tanpa perlu mengubah jam masuk dan jam pulang secara terpisah.
- **Modal Gantt Chart 24 Jam Hari Ini pada Dashboard**: Visualisasi linimasa operasional harian seluruh staf lintas departemen dalam modal native `<x-ui.modal maxWidth="4xl">` dengan sumbu waktu 24 jam (interval 2 jam), 12 garis kisi vertikal, pengelompokan per departemen, penanda balok proporsional, dan kolom staf *sticky*.
- **Badge Durasi Jam Bersih pada Balok Shift**: Penambahan indikator akumulasi jam kerja efektif (`8h`, `7.5h`, `12h`) di sudut kanan atas setiap balok shift pada kalender roster dengan tipografi mikro `text-3xs` dan penataan tata letak bebas tabrakan tombol aksi.
- **Menu Aksi Cepat Sentuh Mobile**: Popover interaksi cepat pada balok shift saat diketuk pada layar ponsel untuk mempermudah eksekusi *Split Shift* dan penghapusan jadwal.
- **Endpoint API Jadwal Harian Lintas Departemen**: Penambahan rute `GET /dashboard/today-schedules` dengan *eager loading* menyeluruh, isolasi multi-tenant, dan penyaringan berbasis peran (hanya jadwal `published` untuk staf biasa, dan penanda draf untuk manajer/admin).
- **Pengujian Otomatis Lanjutan**: Test suite `DashboardScheduleTest` untuk validasi isolasi multi-tenant serta skenario uji regresi 9 dan 10 pada `RosterInteractionTest`.

### Changed
- **Rekonstruksi Modal Jadwal Dashboard**: Menghapus seluruh kartu ringkasan statis, widget MOD, dan teks pengantar di dalam modal dashboard untuk mendedikasikan 100% ruang modal pada visualisasi linimasa Gantt 24 jam murni.
- **Dukungan Lebar Maksimum Modal**: Penambahan varian `maxWidth="3xl"` hingga `maxWidth="6xl"` (khususnya `sm:max-w-4xl`) pada komponen native dialog `<x-ui.modal>`.
- **Ekstensi Payload Matriks Jadwal**: Penambahan atribut `duration_hours` dan `duration_label` pada `WorkSchedule::toMatrixPayload()` serta alias metode `getDurationInHours()`.
- **Sinkronisasi Paritas Bahasa (i18n)**: Penambahan berkas lokalisasi dwi-bahasa baru `lang/id/dashboard.php` dan `lang/en/dashboard.php` serta kunci belah shift pada `roster.php`.

### Fixed
- **Eliminasi Overflow Horizontal Mobile pada Toolbar Roster**: Pembungkusan kontainer navigasi, badge status publikasi, dan bilah ringkasan aksi menggunakan `flex-wrap` dan kontainer scroll horizontal mandiri `.no-scrollbar` sehingga antarmuka tetap rapi pada layar gawai seluler beresolusi 360px–430px.
- **Pencegahan Konflik Event Gestur Geser & Klik**: Penambahan atribut penanda status geser (`justSlid`) untuk mencegah terbukanya popover aksi saat balok shift selesai diseret.

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
