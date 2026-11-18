# Panduan Optimasi Sumber Daya Server Co-Hosting & Tolok Ukur Performa (OATCAKE v1.0)

Dokumen ini merangkum arsitektur optimasi kueri database, manajemen memori PHP-FPM, kuota penyimpanan multi-tenant, serta profil tuning infrastruktur co-hosting pada sistem operasi **openSUSE Leap** (Nginx + PHP 8.4 FPM + PostgreSQL 18 + Laravel 12) sesuai hasil **Sprint 5 Fase 4 (US-28)**.

---

## 1. Strategi Indeks Komposit PostgreSQL 18 (*Hot Query Paths*)

Selain indeks B-Tree dasar dan indeks `GIN` pada tiga kolom `JSONB` di tabel `audit_logs`, migrasi [2026_11_12_000004_add_performance_composite_indexes.php](file:///srv/www/oatcake/database/migrations/2026_11_12_000004_add_performance_composite_indexes.php) menambahkan lima indeks komposit untuk mempercepat jalur kueri paling padat:

| Tabel | Nama Indeks | Kolom Komposit | Jalur Kueri yang Dipercepat |
| :--- | :--- | :--- | :--- |
| `work_schedules` | `ws_company_date_status_idx` | `(company_id, schedule_date, status)` | Pemuatan matriks Gantt 31 hari & perhitungan coverage departemen |
| `work_schedules` | `ws_emp_date_status_idx` | `(employee_id, schedule_date, status)` | Kalkulasi hari efektif cuti, snapshot offline 14 hari PWA & metrik $C_1$ |
| `attendances` | `att_company_status_clockin_idx` | `(company_id, status, clock_in_at)` | Konsol verifikasi dinas luar & riwayat presensi terfilter |
| `attendances` | `att_emp_status_clockin_idx` | `(employee_id, status, clock_in_at)` | Ekstraksi metrik disiplin presensi 90 hari ($C_3$) pada SPK SMART |
| `leave_requests` | `lr_company_status_dates_idx` | `(company_id, status, start_date, end_date)` | Deteksi irisan tanggal kandidat Mode A SPK SMART & Roster Conflict Guard |

---

## 2. Eliminasi *N+1 Query* & Anggaran Kueri Halaman ($\le 15$ Kueri/Request)

Optimasi *eager loading* dan hidrasi relasi terpusat diterapkan pada seluruh rute utama:

1. **Layout Shell Composer (`AppServiceProvider`)**:
   - View composer pada `layouts.app` dan `layouts.guest` melakukan `loadMissing(['company', 'employee.department'])` sekali di awal agar komponen sidebar, topbar, dan drawer tidak memicu kueri *lazy-loading*.
2. **Matriks Roster 31 Hari (`RosterController::index`)**:
   - Memuat daftar `Shift`, `Tag`, `Department`, dan `LeaveRequest` (`status = approved`) dalam kueri tunggal lalu menghidrasi relasi `department`, `shift`, dan `dutyTag` di memori tanpa kueri per sel atau per staf.
   - Pemeriksaan tag `VIEW_ALL_SCHEDULES` menggunakan koleksi yang telah dimuat (`$actor->employee->tags->contains(...)`).
3. **Portal Cuti & Mesin SPK SMART (`LeaveRequestController::index` & `SmartDecisionService`)**:
   - Pengambilan bobot SPK SMART lintas departemen dikonsolidasikan melalui `SmartDecisionService::getOrCreateWeightsForDepartments()` dalam satu kueri `WHERE department_id IN (...)`.
   - Pemeriksaan `LeaveType::STANDARD_TYPES` hanya memicu *query fallback* apabila tipe cuti belum tersedia di hasil kueri utama.
   - Evaluasi SPK SMART menggunakan `updateQuietly()` agar sinkronisasi skor internal tidak memicu *event* audit atau *observer* berulang, serta berbagi cache ketersediaan staf per tanggal saat memeringkat kandidat Mode A.
4. **Log Audit (`AuditLogController::index`)**:
   - Empat metrik KPI ringkasan digabung menjadi satu kueri agregasi `SELECT COUNT(*), SUM(CASE ...)` dan memanfaatkan indeks `GIN` pada operator JSONB `@>`.

---

## 3. Manajemen Memori Kompresi Swafoto WebP & Kuota Multi-Tenant

- **Pelepasan Buffer GD Segera**: Pada `AttendancePhotoService` dan `LeaveAttachmentService`, setelah `Intervention Image v4` (`GdDriver`) men-decode, melakukan `scaleDown(1280, 1280)`, dan meng-encode ke WebP ($\le 250\text{ KB}$), variabel objek gambar (`$encoded`, `$image`, `$manager`, `$rawBytes`) langsung dibebaskan dari memori (`unset(...)`) sebelum menulis berkas ke disk privat (`storage/app/private`).
- **Isolasi Kuota Disk per Properti**: Setiap tenant memiliki batas `companies.storage_limit_mb` (default `1024 MB`) dan retensi foto `photo_retention_months` (default `3 bulan`) yang dibersihkan berkala melalui command `php artisan attendance:prune-photos`.

---

## 4. Profil Konfigurasi Server Co-Hosting (openSUSE Leap)

Untuk server co-hosting yang berbagi sumber daya dengan layanan lain, gunakan profil konservatif dan deterministik berikut:

### A. PHP 8.4 FPM (`/etc/php8/fpm/php-fpm.d/oatcake.conf`)
```ini
[oatcake]
user = wwwrun
group = www
listen = /run/php-fpm/oatcake.sock
listen.mode = 0660

pm = dynamic
pm.max_children = 16
pm.start_servers = 3
pm.min_spare_servers = 2
pm.max_spare_servers = 6
pm.max_requests = 500
pm.process_idle_timeout = 10s

php_admin_value[memory_limit] = 192M
php_admin_value[upload_max_filesize] = 25M
php_admin_value[post_max_size] = 28M
php_admin_value[max_execution_time] = 45
```

### B. PHP OPcache (`/etc/php8/conf.d/opcache-oatcake.ini`)
```ini
opcache.enable=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=16229
opcache.validate_timestamps=0
opcache.revalidate_freq=0
opcache.save_comments=1
```

### C. PostgreSQL 18 (`/var/lib/pgsql/data/postgresql.conf` — Profil Co-Hosting 2–4 GB RAM)
```ini
shared_buffers = 512MB
effective_cache_size = 1536MB
work_mem = 8MB
maintenance_work_mem = 64MB
max_connections = 60
random_page_cost = 1.1
checkpoint_completion_target = 0.9
timezone = 'Asia/Jakarta'
```

### D. Nginx Virtual Host (`/etc/nginx/vhosts.d/oatcake.conf`)
```nginx
gzip on;
gzip_vary on;
gzip_comp_level 5;
gzip_types text/plain text/css application/json application/javascript application/manifest+json image/svg+xml;

location /build/ {
    expires 1y;
    add_header Cache-Control "public, immutable";
    access_log off;
}

location /icons/ {
    expires 30d;
    add_header Cache-Control "public";
    access_log off;
}

location = /sw.js {
    add_header Cache-Control "no-cache, no-store, must-revalidate";
}

location = /manifest.webmanifest {
    default_type application/manifest+json;
    add_header Cache-Control "public, max-age=3600";
}
```

### E. Systemd Scheduler & Prune Automation
Jalankan scheduler Laravel setiap menit via systemd timer atau cron:
```cron
* * * * * cd /srv/www/oatcake && php artisan schedule:run >> /dev/null 2>&1
```

---

## 5. Verifikasi Diagnostik & Benchmark

Jalankan perintah berikut setelah deploy atau migrasi:
```bash
php artisan oatcake:optimize-cohosting --benchmark
```

Seluruh tolok ukur halaman (`/roster`, `/leave`, `/attendance`, `/audit-logs`), lonjakan 30 swafoto pergantian shift, kalkulasi SPK SMART Mode A 10 kandidat, dan integritas buku besar diverifikasi secara otomatis melalui `tests/Feature/StressAndResourceOptimizationTest.php`.
