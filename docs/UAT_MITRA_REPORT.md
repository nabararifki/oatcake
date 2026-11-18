# Berita Acara & Laporan Pelaksanaan User Acceptance Testing (UAT) Mitra

**Mitra Implementasi:** Novotel Yogyakarta International Airport Kulon Progo  
**Sistem:** OATCAKE — Hospitality HRIS, 24-Hour Roster, Geospatial Attendance & Leave Ledger (`v1.0.0`)  
**Zona Waktu Operasional:** `Asia/Jakarta` (WIB)  
**Kebijakan Privasi Data:** *Strict Zero NIK* (Seluruh skema, API, log audit, dan antarmuka tidak menyimpan maupun menampilkan NIK kependudukan).

---

## 1. Ringkasan Lingkungan & Kesiapan UAT Mitra

Lingkungan pengujian UAT mitra disiapkan secara idempoten melalui perintah:
```bash
php artisan oatcake:prepare-uat
```

Konfigurasi tenant properti yang terpasang:
- **Nama Properti:** Novotel Yogyakarta International Airport Kulon Progo (`NOVOTEL-YIA`)
- **Titik Koordinat Geofence:** `-7.9048250, 110.0572110` (Radius `100` meter)
- **Kuota Penyimpanan Swafoto/Lampiran:** `1024` MB (Retensi `3` bulan)
- **Kalender Libur:** 12 Hari Libur Nasional 2026 + 1 Event Khusus Hotel (`Anniversary Novotel YIA`)
- **Struktur Organisasi:** 8 Departemen (`FO`, `HK`, `FBS`, `FBP`, `ENG`, `FIN`, `HR`, `SEC`) dengan bobot kriteria SPK SMART (`C1`–`C5`) masing-masing.
- **Master Shift:** `MO` (07:00–15:00), `AF` (15:00–23:00), `NI` (23:00–07:00 lintas hari), `MD` (11:00–19:00), `MOD` (08:00–17:00), `OFF` (Day Off).

---

## 2. Daftar Akun Pengujian Lintas 5 Tingkat Peran (RBAC)

| Peran RBAC | Username | Email Login | Departemen / Scope | Keterangan Skenario | Password Default |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `super_admin` | `superadmin_uat` | `superadmin.uat@oatcake.test` | Platform Global | Pengelolaan multi-tenant & kuota storage | `Password123!` |
| `super_user` | `gm_uat` | `gm.uat@oatcake.test` | FO / Eksekutif | General Manager & pengawasan properti | `Password123!` |
| `admin` | `hr_uat` | `hr.uat@oatcake.test` | HR (Talent & Culture) | Persetujuan Cuti Tier 2, Buku Besar & Log Audit JSONB | `Password123!` |
| `manager` | `fom_uat` | `fom.uat@oatcake.test` | Front Office (`MOD_ELIGIBLE`) | Roster FO, SPK SMART Mode A, Verifikasi Dinas Luar, Tier 1 | `Password123!` |
| `manager` | `hkm_uat` | `hkm.uat@oatcake.test` | Housekeeping (`MOD_ELIGIBLE`) | Verifikasi Dinas Luar HK (`pending_verification`) & Roster HK | `Password123!` |
| `user` | `fo_staff1` | `fo.staff1@oatcake.test` | Front Office | Kandidat #1 pengajuan cuti bentrok Mode A (AL mendekati kedaluwarsa) | `Password123!` |
| `user` | `fo_staff2` | `fo.staff2@oatcake.test` | Front Office | Kandidat #2 pengajuan cuti bentrok Mode A | `Password123!` |
| `user` | `fo_staff3` | `fo.staff3@oatcake.test` | Front Office | Contoh Split Shift, Committed DP (+1.00), & Cuti `pending_hr` | `Password123!` |
| `user` | `hk_staff1` | `hk.staff1@oatcake.test` | Housekeeping | Pengajuan Clock-In Dinas Luar menunggu verifikasi Manajer HK | `Password123!` |
| `user` | `fbs_staff1` | `fbs.staff1@oatcake.test` | F&B Service | Staf operasional F&B | `Password123!` |
| `user` | `eng_staff1` | `eng.staff1@oatcake.test` | Engineering | Staf operasional Engineering | `Password123!` |

---

## 3. Matriks Ketertelusuran Pengujian (*User Story Traceability Matrix* US-01 s.d. US-29)

| ID | Sprint | Deskripsi Fitur / User Story | Skenario Uji & Verifikasi | Hasil yang Diharapkan | Status |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **US-01** | Sprint 0 | Inisialisasi Proyek, Skema Inti Multi-Tenant & `BelongsToCompany` | Isolasi data tenant A vs B melalui `CompanyScope` | Data terisolasi ketat per `company_id` | **PASS** |
| **US-02** | Sprint 0 | Dual Autentikasi (Username/Email & Google OAuth) + Zero NIK | Login akun valid, tolak akun nonaktif & email tak terdaftar | Sesi valid terbentuk tanpa kolom `nik` | **PASS** |
| **US-03** | Sprint 0 | Manajemen Struktur Organisasi (8 Departemen & Tag Fungsional) | CRUD departemen, kuota minimum shift & tag `MOD_ELIGIBLE` | Data departemen & tag tersimpan konsisten | **PASS** |
| **US-04** | Sprint 0 | Manajemen Staf & Otorisasi RBAC 5 Tingkat | Provisioning staf oleh Manajer (terbatas departemen sendiri) & Admin | Akses lintas departemen manajer ditolak `403` | **PASS** |
| **US-05** | Sprint 0 | UI Shell Monolitik, Dark Mode & Paritas Dwibahasa (ID/EN) | Render dashboard, toggle tema, dan uji `LangParityTest` | Paritas kamus `id` dan `en` 100% simetris | **PASS** |
| **US-06** | Sprint 1 | Master Shift 24 Jam & Penanganan Shift Lintas Hari (`NI`) | Tambah/ubah shift `MO`, `AF`, `NI` (`cross_day = true`) | Durasi jam kerja lintas midnight dihitung akurat | **PASS** |
| **US-07** | Sprint 1 | Matriks Gantt Chart Roster 31 Hari & Drag-and-Drop Penugasan | Penugasan shift pada kalender draf & deteksi celah formasi | Matriks tampil tanpa *N+1 query* ($\le 15$ kueri) | **PASS** |
| **US-08** | Sprint 1 | Split Shift, Geser Jadwal & Penjagaan Istirahat 8 Jam (*Turnaround*) | Penugasan 2 shift dalam 1 hari & uji jeda istirahat $< 8$ jam | Peringatan turnaround muncul (override didukung) | **PASS** |
| **US-09** | Sprint 1 | Siklus Publikasi Roster (`draft` $\rightarrow$ `published` / `pending_delete`) | Terbit jadwal via `/roster/publish` & buang draf `/roster/discard` | Staf biasa hanya melihat jadwal berstatus `published` | **PASS** |
| **US-10** | Sprint 1 | Filter Manager on Duty (MOD) & Tag Akses Lintas Departemen | Filter `mod_only` & staf bertag `VIEW_ALL_SCHEDULES` | Staf bertag hanya memiliki akses baca lintas departemen | **PASS** |
| **US-11** | Sprint 2 | Pengaturan Geofence Properti & Kalkulasi Jarak Haversine | Ubah koordinat/radius di `/settings/company` | Jarak dihitung akurat terhadap koordinat properti | **PASS** |
| **US-12** | Sprint 2 | Presensi Clock-In & Clock-Out dalam Radius Geofence + Swafoto | Unggah swafoto pada radius $\le 100$m dan tolak radius $> 100$m | Dalam radius berhasil; luar radius ditolak `422` | **PASS** |
| **US-13** | Sprint 2 | Presensi Dinas Luar & Konsol Verifikasi Manajer/Admin | Submit dinas luar (`pending_verification`) & approve/reject | Manajer hanya memverifikasi departemennya sendiri | **PASS** |
| **US-14** | Sprint 2 | Kompresi Swafoto WebP $\le 250\text{ KB}$, Sanitasi EXIF & Streaming Privat | Upload foto beresolusi besar & akses `/attendance/{id}/proof` | Foto terkompresi $\le 250\text{ KB}$; akses ilegal ditolak `403` | **PASS** |
| **US-15** | Sprint 2 | Auto-Assign Shift Terdekat, Guard Pulang Awal & Multi-Sesi Split Shift | Clock-In tanpa jadwal & Clock-In/Out 2 sesi pada hari sama | Shift terdekat dipilih otomatis; konfirmasi pulang awal aktif | **PASS** |
| **US-16** | Sprint 3 | Kalender Libur Nasional 2026 & Event Khusus Properti | Tambah event khusus hotel di `/settings/company` | Event terisolasi per properti | **PASS** |
| **US-17** | Sprint 3 | Two-Phase DP Commit pada Presensi Hari Libur | Clock-In pada tanggal merah (`is_provisional_dp`) lalu Clock-Out $\ge 4$ jam | Saldo `+1.00 DP` baru masuk setelah Clock-Out memenuhi syarat | **PASS** |
| **US-18** | Sprint 3 | Buku Besar Cuti Append-Only (*FIFO Ledger*) & Penyesuaian Manual | Akrual, debit FIFO berdasarkan `expires_at` terdekat, & `adjustLedger` | Baris buku besar *immutable*; `balance_after` presisi | **PASS** |
| **US-19** | Sprint 3 | Pengajuan Cuti Berbasis Roster (Eksklusi Hari `OFF`) & Reservasi Kuota | Ajukan cuti melintasi hari `OFF` & anti-overlap | Hari `OFF` tidak memotong kuota; double-booking ditolak `422` | **PASS** |
| **US-20** | Sprint 3 | Persetujuan Cuti 2-Tier (Manajer $\rightarrow$ HR), Penolakan & Pengeditan/Pembatalan | Approve Tier 1 $\rightarrow$ Tier 2 $\rightarrow$ Cancel `approved` | Pembatalan cuti `approved` mengembalikan saldo append-only | **PASS** |
| **US-21** | Sprint 3 | Roster Lock (`LEAVE:...`) & *Roster Conflict Guard* | Coba timpa jadwal staf yang sedang cuti `approved` | Penimpaan shift di Roster ditolak `422` | **PASS** |
| **US-22** | Sprint 3 | Mesin Keputusan SPK SMART Mode B (Mandiri) & Konfigurasi Bobot Dinamis | Ubah bobot `C1`..`C5` (total 100) & analisis 1 pengajuan | Skor utilitas & kategori rekomendasi dihitung otomatis | **PASS** |
| **US-23** | Sprint 3 | Mesin SPK SMART Mode A (Komparatif Multi-Kandidat Bentrok) | 2+ staf departemen sama mengajukan cuti pada tanggal beririsan | Peringkat objektif #1..#N + simulasi kuota formasi tampil akurat | **PASS** |
| **US-24** | Sprint 4 | Manajemen Kuota Storage Tenant, Prune Otomatis & Pemolesan Mobile/UI | Jalankan `attendance:prune-photos` & cek drawer/tabel responsif | Kuota terpantau & aset lama dibersihkan sesuai retensi | **PASS** |
| **US-25** | Sprint 5 | Progressive Web App (PWA), Service Worker & Offline Snapshot 14 Hari | Manifest, 4 ikon PNG, `/offline`, `/pwa/offline-snapshot`, & kunci presensi offline | Jadwal 14 hari terbaca offline; Clock-In/Out tetap terkunci offline | **PASS** |
| **US-26** | Sprint 5 | Notifikasi Web Push Real-Time (VAPID RFC 8291/8292) & Notification Center | Trigger cuti/dinas luar/publikasi roster & pruning endpoint `410 Gone` | Notifikasi real-time terkirim terenkripsi & terisolasi per akun | **PASS** |
| **US-27** | Sprint 5 | Audit Trail Logging PostgreSQL `JSONB` + Indeks `GIN` & Diff Console | Observer 11 model, guard `LogicException`, filter `@>`, & modal diff | Seluruh mutasi terekam immutable dengan sanitasi Zero-NIK | **PASS** |
| **US-28** | Sprint 5 | Optimasi Co-Hosting, Eliminasi N+1 Query & Stress Benchmarking | Uji $\le 15$ kueri/halaman, burst 30 swafoto, & `oatcake:optimize-cohosting` | Seluruh target latensi, query budget & batas RAM terpenuhi | **PASS** |
| **US-29** | Sprint 5 | Pelaksanaan UAT Mitra End-to-End (Novotel YIA Kulon Progo) | Uji `oatcake:prepare-uat` & seluruh alur lintas 5 peran RBAC | 100% skenario penerimaan mitra lulus tanpa cacat kritis | **PASS** |

---

## 4. Lembar Pengesahan UAT (*Sign-Off Sheet*)

| Pihak | Nama / Jabatan | Peran | Tanggal | Tanda Tangan / Status |
| :--- | :--- | :--- | :--- | :--- |
| Tim Pengembang OATCAKE | Principal Software Architect & Lead QA | Pelaksana & Verifikator Teknis | 2026-11-18 | **APPROVED (Automated Suite 100% Pass)** |
| Mitra Properti Hotel | Perwakilan Talent & Culture / HR Manager | Reviewer Operasional HRIS | ____-__-__ | ________________________ |
| Mitra Properti Hotel | Perwakilan Front Office / Operational HOD | Reviewer Roster & Presensi | ____-__-__ | ________________________ |
| Manajemen Eksekutif Hotel | General Manager Novotel YIA | Pengesahan Akhir Produksi (`v1.0.0`) | ____-__-__ | ________________________ |
