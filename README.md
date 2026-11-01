<p align="center">
  <img src="storage/app/public/oatcake_logo_full.jpg" alt="OATCAKE Logo" width="220">
</p>

# OATCAKE

**Hospitality HRIS & 24-Hour Roster Scheduling Platform**

OATCAKE adalah platform manajemen SDM dan penjadwalan rotasi kerja 24 jam non-stop yang dirancang khusus untuk operasional industri perhotelan dan resort berskala enterprise.

---

## Fitur Utama

- **Multi-Tenancy**: Isolasi data antar properti hotel secara menyeluruh dengan dukungan kustomisasi tema dan warna aksen setiap tenant.
- **Dual-Authentication & Closed Provisioning**: Sistem login ganda berbasis Google OAuth dan kredensial manual (username/email). Pendaftaran publik ditutup untuk memastikan seluruh staf didaftarkan secara terpusat oleh manajemen.
- **5-Tier Role-Based Access Control (RBAC)**: Pembagian hak akses terstruktur mulai dari *Super Admin*, *Corporate Executive*, *HR Admin*, *Department Manager*, hingga *Staff*.
- **Hospitality Design System**: Antarmuka adaptif dengan dukungan Dark Mode bebas kedip (anti-FOUC), kontras warna yang memenuhi standar aksesibilitas WCAG AA (rasio kontras ≥ 4.5:1), serta tabel data berdensitas tinggi.
- **Manajemen Saldo DP (Day of Payment)**: Pengelolaan hak libur pengganti dan kompensasi shift untuk penugasan pada hari libur nasional atau lembur operasional.
- **Dukungan Multi-Bahasa**: Pilihan bahasa Indonesia dan Inggris dengan peralihan instan.

---

## Tech Stack

- **Backend**: Laravel 12 & PHP 8.4+
- **Database**: PostgreSQL 18
- **Frontend**: Tailwind CSS & Alpine.js
- **Autentikasi**: Laravel Socialite (Google OAuth 2.0) & Session Guard

---

## Instalasi & Menjalankan Aplikasi

### Prasyarat
- PHP >= 8.4
- PostgreSQL >= 18
- Composer
- Node.js & NPM

### Langkah Setup

1. **Clone repositori**:
   ```bash
   git clone git@github.com:nabararifki/oatcake.git
   cd oatcake
   ```

2. **Pasang dependensi**:
   ```bash
   composer install
   npm install
   ```

3. **Konfigurasi environment**:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```
   Sesuaikan kredensial database PostgreSQL dan Google OAuth di berkas `.env`.

4. **Migrasi dan seeding database**:
   ```bash
   php artisan migrate --seed
   ```

5. **Kompilasi aset frontend**:
   ```bash
   npm run build
   ```

6. **Jalankan server aplikasi**:
   ```bash
   php artisan serve
   ```

---

## Pengujian

Jalankan seluruh rangkaian pengujian otomatis:

```bash
php artisan test
```

Untuk menjalankan pengujian fitur tertentu:
```bash
# Pengujian autentikasi ganda
php artisan test --filter DualAuthenticationTest

# Pengujian hak akses dan RBAC
php artisan test --filter RoleAwareLayoutAndPolicyTest

# Pengujian kepatuhan kontras WCAG AA
php artisan test --filter AccentTest
```

---

## Lisensi

Platform OATCAKE dilisensikan di bawah lisensi [GNU General Public License v3.0 (GPL-3.0)](LICENSE).
