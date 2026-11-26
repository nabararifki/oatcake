# Panduan Deployment Produksi Co-Hosting OATCAKE (`v1.0.0-rc`) di Hetzner & Cloudflare

Dokumen ini merupakan panduan operasional *end-to-end* untuk men-*deploy* aplikasi **OATCAKE (`v1.0.0-rc`)** pada URL **`https://oatcake.zonabara.com`** di server VPS Hetzner (**openSUSE Leap 16.0**) yang saat ini telah menjalankan aplikasi **Niham** (`https://niham.zonabara.com`).

Seluruh konfigurasi dirancang berdasarkan dua prinsip utama:
1. **Zero Downtime untuk Niham**: Tidak ada penghentian layanan global (`stop`/`restart` Nginx atau PostgreSQL secara paksa). Seluruh aktivasi menggunakan validasi konfigurasi terlebih dahulu dan *graceful reload*.
2. **Security by Isolation**: Pemisahan ketat pada level sertifikat TLS, Virtual Host Nginx, *socket* & *pool* PHP 8.4 FPM, *role* & *database* PostgreSQL 18, *session cookie domain*, berkas log, serta *cgroup* Systemd *background jobs*.

> [!NOTE]
> Untuk detail *tuning* kueri SQL, indeks komposit PostgreSQL 18, dan tolok ukur memori co-hosting pada OATCAKE, lihat dokumen pendamping [COHOSTING_OPTIMIZATION.md](COHOSTING_OPTIMIZATION.md).

---

## Arsitektur Co-Hosting & Matriks Isolasi

```text
Browser / PWA Client (HTTPS)
        │
        ▼
Cloudflare Edge Proxy (Orange Cloud, SSL/TLS Full Strict, Cache Bypass /sw.js)
        │  HTTPS + SNI (niham.zonabara.com | oatcake.zonabara.com)
        ▼
Hetzner VPS — openSUSE Leap 16.0 (Nginx Port 443)
   ├── server_name niham.zonabara.com   ──▶ Pool PHP-FPM Niham   ──▶ DB Niham (terpisah)
   └── server_name oatcake.zonabara.com ──▶ /run/php-fpm/oatcake.sock (PHP 8.4 FPM)
                                                │
                                                ▼
                                        PostgreSQL 18 (127.0.0.1:5432)
                                        DB: oatcake_db | Role: oatcake_user
                                                ▲
Systemd Background Jobs (user: wwwrun)          │
   ├── oatcake-queue.service  (php artisan queue:work database)
   └── oatcake-scheduler.timer (php artisan schedule:run setiap 1 menit)
```

| Komponen | Niham (Eksisting — Jangan Diubah) | OATCAKE (`v1.0.0-rc` — Baru) |
| :--- | :--- | :--- |
| **Domain Publik** | `https://niham.zonabara.com` | `https://oatcake.zonabara.com` |
| **Direktori Aplikasi** | Direktori eksisting Niham | `/srv/www/oatcake` (`root .../public`) |
| **Virtual Host Nginx** | Konfigurasi vhost eksisting Niham | `/etc/nginx/vhosts.d/oatcake.conf` |
| **Sertifikat SSL Origin** | Sertifikat eksisting Niham | `/etc/nginx/ssl/oatcake.zonabara.com.{pem,key}` |
| **Pool PHP 8.4 FPM** | Pool & socket eksisting Niham | `/etc/php8/fpm/php-fpm.d/oatcake.conf` (`/run/php-fpm/oatcake.sock`) |
| **Database & Role** | Database & user eksisting Niham | `oatcake_db` & `oatcake_user` |
| **Session Cookie & Domain** | Cookie & domain milik Niham | `oatcake-session` terikat ketat ke `oatcake.zonabara.com` |
| **Systemd Workers** | Service/cron milik Niham | `oatcake-queue.service` & `oatcake-scheduler.timer` |
| **Log Terpisah** | Log eksisting Niham | `/var/log/nginx/oatcake.{access,error}.log`, `/var/log/php-fpm/oatcake-error.log` |

---

## Prasyarat & Pemeriksaan Awal Server (*Pre-Flight Checks*)

Sebelum mengubah apa pun di server, jalankan pemeriksaan *read-only* berikut via SSH di VPS Hetzner untuk memverifikasi kondisi aktual openSUSE Leap 16.0, Nginx, PHP 8.4, dan PostgreSQL 18 (*No Assumptions*).

```bash
# 1. Cek versi OS & status SELinux
cat /etc/os-release | grep -E '^(NAME|VERSION)='
command -v getenforce >/dev/null && getenforce || echo "SELinux tools tidak terpasang"

# 2. Cek Nginx, user worker, direktori include vhost, dan modul real_ip
nginx -v
nginx -V 2>&1 | tr ' ' '\n' | grep -E 'with-http_realip_module|with-http_ssl_module|with-http_v2_module'
sudo grep -E '^\s*(user|include)' /etc/nginx/nginx.conf
sudo ls -la /etc/nginx/vhosts.d/

# 3. Cek PHP 8.4 CLI/FPM & ekstensi wajib OATCAKE
php -v
php-fpm -v
php -m | grep -iE '^(pdo_pgsql|pgsql|gd|openssl|zip|mbstring|xml|curl|intl|bcmath|fileinfo|opcache)$'
php -r 'echo "GD WebP: " . (function_exists("imagewebp") ? "SUPPORTED" : "MISSING") . PHP_EOL;'

# 4. Cek direktori konfigurasi PostgreSQL 18 & daftar database eksisting
sudo -u postgres psql -c "SELECT version();"
sudo -u postgres psql -tAc "SHOW data_directory;"
sudo -u postgres psql -tAc "SHOW hba_file;"
sudo -u postgres psql -c "\l+"

# 5. Cek Composer, Node.js, dan NPM
composer -V
node -v
npm -v
```

> [!IMPORTANT]
> Pastikan dari hasil pemeriksaan di atas:
> - **Nginx** memuat baris `include vhosts.d/*.conf;` (atau `/etc/nginx/vhosts.d/*.conf;`) di dalam blok `http { ... }` pada `/etc/nginx/nginx.conf`, dan mendukung `with-http_realip_module`.
> - **PHP 8.4** memiliki ekstensi `pdo_pgsql`, `pgsql`, `gd` (dengan dukungan `imagewebp` untuk kompresi swafoto WebP), `openssl` (untuk enkripsi W3C Web Push VAPID RFC 8291/8292), `zip` (`ZipArchive` untuk fitur *Multi-Tenant Backup & Restore*), `mbstring`, `xml`, `curl`, `intl`, `bcmath`, `fileinfo`, dan `opcache`.
> - Jika ada ekstensi PHP 8 yang belum terpasang di openSUSE Leap 16.0, pasang terlebih dahulu **tanpa me-restart layanan yang sedang berjalan** (cukup reload `php-fpm` nanti):
>   ```bash
>   sudo zypper install -y php8-pgsql php8-gd php8-openssl php8-zip php8-mbstring php8-intl php8-bcmath php8-curl php8-opcache php8-fileinfo
>   ```

---

## 1. Konfigurasi DNS, SSL/TLS & Origin Certificate di Cloudflare

### 1.1 Pembuatan DNS A Record untuk `oatcake.zonabara.com`

1. Masuk ke **Cloudflare Dashboard** dan pilih domain (zone) **`zonabara.com`**.
2. Buka menu **DNS** $\rightarrow$ **Records** $\rightarrow$ klik **Add record**.
3. Isi parameter berikut (pastikan **IPv4** sama persis dengan IP publik server Hetzner yang dipakai oleh `niham.zonabara.com`):
   - **Type**: `A`
   - **Name**: `oatcake` (Cloudflare otomatis menjadikannya `oatcake.zonabara.com`)
   - **IPv4 address**: `<IP_PUBLIK_IPV4_HETZNER>`
   - **Proxy status**: **Proxied** (**Awan Oranye / Orange Cloud** aktif — wajib agar trafik melewati Cloudflare Edge, mendapatkan proteksi WAF/DDoS, serta validasi Origin Certificate).
   - **TTL**: `Auto`
4. Klik **Save**.
5. *(Opsional jika VPS Hetzner memiliki IPv6 aktif yang juga digunakan Niham)*: Tambahkan satu record lagi dengan **Type**: `AAAA`, **Name**: `oatcake`, **IPv6 address**: `<IP_PUBLIK_IPV6_HETZNER>`, **Proxy status**: **Proxied (Awan Oranye)**, **TTL**: `Auto`.

### 1.2 Pengaturan Mode Enkripsi SSL/TLS: `Full (Strict)`

Fitur PWA (*Service Worker*), kamera depan swafoto presensi, *Geolocation API*, dan *Web Push Notification* pada OATCAKE **wajib** berjalan di atas konteks HTTPS yang tepercaya (*secure context*).

1. Pada Cloudflare Dashboard zone `zonabara.com`, buka **SSL/TLS** $\rightarrow$ **Overview**.
2. Pilih mode enkripsi **`Full (strict)`**.

> [!CAUTION]
> **PERINGATAN ZERO DOWNTIME UNTUK NIHAM**:
> Pengaturan **SSL/TLS Encryption Mode** di Cloudflare berlaku untuk **seluruh domain `zonabara.com`** (termasuk `niham.zonabara.com`).
> **Jangan mengubah mode ke `Full (strict)` sebelum memastikan `niham.zonabara.com` di Nginx sudah memiliki sertifikat SSL yang valid** (berupa Cloudflare Origin Certificate atau Let's Encrypt yang masih aktif). Jika server origin Niham belum memakai SSL valid atau masih memakai *self-signed certificate* biasa, mengubah ke `Full (strict)` akan langsung memunculkan galat **`526 Invalid SSL Certificate`** pada Niham!
> Verifikasi terlebih dahulu dari VPS Hetzner:
> ```bash
> openssl s_client -connect 127.0.0.1:443 -servername niham.zonabara.com </dev/null 2>/dev/null | openssl x509 -noout -issuer -subject -dates
> ```
> Jika `niham.zonabara.com` sudah menggunakan `Full (strict)` dengan Origin Cert / Let's Encrypt yang valid, biarkan pengaturan zone tetap `Full (strict)` dan jangan sentuh berkas sertifikat Niham.

### 1.3 Pembuatan & Instalasi Cloudflare Origin Certificate Khusus `oatcake.zonabara.com`

Untuk menjaga isolasi penuh agar tidak menyentuh atau menimpa berkas sertifikat milik Niham, buatlah **Cloudflare Origin Certificate** khusus untuk subdomain `oatcake.zonabara.com`:

1. Di Cloudflare Dashboard (`zonabara.com`), buka **SSL/TLS** $\rightarrow$ **Origin Server**.
2. Klik **Create Certificate**.
3. Pilih konfigurasi berikut:
   - **Generate private key and CSR with Cloudflare**: terpilih.
   - **Private key type**: `RSA (2048)` (atau `ECDSA`).
   - **Hostnames**: hapus entri *wildcard* (`*.zonabara.com`), lalu masukkan **`oatcake.zonabara.com`** saja agar sertifikat terisolasi ketat dan tidak tumpang tindih dengan Niham.
   - **Certificate Validity**: **`15 years`** (standar maksimal Cloudflare Origin CA).
4. Klik **Create**.
5. Salin **Origin Certificate** (PEM) dan **Private Key** (hanya tampil satu kali!).
6. Simpan ke direktori terisolasi di server Hetzner openSUSE Leap:

```bash
# Buat direktori SSL Nginx jika belum ada
sudo mkdir -p /etc/nginx/ssl
sudo chmod 755 /etc/nginx/ssl

# 1. Simpan Origin Certificate ke oatcake.zonabara.com.pem
sudo tee /etc/nginx/ssl/oatcake.zonabara.com.pem > /dev/null <<'EOF'
-----BEGIN CERTIFICATE-----
TEMPEL_ISI_ORIGIN_CERTIFICATE_CLOUDFLARE_DI_SINI
-----END CERTIFICATE-----
EOF

# 2. Simpan Private Key ke oatcake.zonabara.com.key
sudo tee /etc/nginx/ssl/oatcake.zonabara.com.key > /dev/null <<'EOF'
-----BEGIN PRIVATE KEY-----
TEMPEL_ISI_PRIVATE_KEY_CLOUDFLARE_DI_SINI
-----END PRIVATE KEY-----
EOF

# 3. Kunci hak akses berkas sertifikat & private key
sudo chown root:root /etc/nginx/ssl/oatcake.zonabara.com.pem /etc/nginx/ssl/oatcake.zonabara.com.key
sudo chmod 644 /etc/nginx/ssl/oatcake.zonabara.com.pem
sudo chmod 600 /etc/nginx/ssl/oatcake.zonabara.com.key

# 4. Verifikasi keabsahan pasangan sertifikat & hostname SAN
sudo openssl x509 -noout -subject -issuer -dates -ext subjectAltName -in /etc/nginx/ssl/oatcake.zonabara.com.pem
```

### 1.4 Pengaturan Edge Cloudflare untuk OATCAKE PWA & Laravel

Agar *Service Worker* PWA, *Live Roster InteractJS*, *Alpine.js*, *Leaflet.js*, dan fitur unggahan OATCAKE bekerja tanpa cacat (dan tanpa mengganggu perilaku Niham):

1. **Always Use HTTPS** (**SSL/TLS** $\rightarrow$ **Edge Certificates**): Aktifkan **ON** dan set **Minimum TLS Version** ke **`TLS 1.2`**.
2. **Matikan Rocket Loader untuk `oatcake.zonabara.com`** (**Speed** $\rightarrow$ **Optimization** $\rightarrow$ **Content Optimization**):
   - *Rocket Loader* menulis ulang skrip JavaScript secara asinkron sehingga dapat merusak inisialisasi `Alpine.js`, *drag-and-drop* Gantt Roster (`InteractJS`), peta `Leaflet.js`, dan registrasi `sw.js`.
   - Agar tidak memengaruhi pengaturan global Niham, buat **Configuration Rule** (**Rules** $\rightarrow$ **Configuration Rules** $\rightarrow$ **Create rule**) dengan filter **`Hostname equals oatcake.zonabara.com`** dan set **Rocket Loader = OFF**.
3. **Bypass Cache untuk Service Worker (`/sw.js`)** (**Caching** $\rightarrow$ **Cache Rules** $\rightarrow$ **Create rule**):
   - **Rule name**: `OATCAKE PWA Service Worker Bypass`
   - **Custom filter expression**:
     ```text
     (http.host eq "oatcake.zonabara.com" and http.request.uri.path eq "/sw.js")
     ```
   - **Cache eligibility**: **`Bypass cache`**
   - *Jangan* mengaktifkan rule *Cache Everything* pada domain `oatcake.zonabara.com`, karena halaman Blade dinamis, token CSRF, dan *API session* harus selalu diproses oleh origin.
4. **Batas Ukuran Upload Cloudflare**: Paket Cloudflare Free/Pro membatasi ukuran *request body* HTTP maksimal **100 MB**. Nilai ini selaras dengan validasi swafoto & lampiran cuti OATCAKE (`25 MB`) serta arsip *Backup/Restore* `.zip` (`100 MB`).

---

## 2. Persiapan Isolasi Database di PostgreSQL 18

Untuk keamanan *multi-application* di satu instance PostgreSQL 18, OATCAKE menggunakan *role* khusus (`oatcake_user`) dan *database* khusus (`oatcake_db`) dengan hak akses `PUBLIC` dicabut sepenuhnya serta pembatasan koneksi di `pg_hba.conf`.

### 2.1 Pembuatan Role & Database Terisolasi (`oatcake_user` & `oatcake_db`)

Pertama, buat kata sandi acak yang kuat untuk `oatcake_user` dan simpan sementara untuk diisikan ke `.env` pada Bagian 3:

```bash
openssl rand -base64 32
```

Masuk ke *prompt* PostgreSQL 18 sebagai user `postgres` (`sudo -u postgres psql`) dan jalankan perintah SQL berikut (ganti `<PASSWORD_OATCAKE_KUAT>` dengan hasil perintah di atas):

```sql
-- 1. Buat role khusus tanpa privilege superuser/createdb/createrole
CREATE ROLE oatcake_user WITH
    LOGIN
    PASSWORD '<PASSWORD_OATCAKE_KUAT>'
    NOSUPERUSER
    NOCREATEDB
    NOCREATEROLE
    NOINHERIT
    NOREPLICATION
    CONNECTION LIMIT 30;

-- 2. Buat database khusus OATCAKE dengan encoding UTF8 & owner oatcake_user
--    Catatan: Jika cluster PostgreSQL Anda menggunakan locale en_US.UTF-8,
--    Anda dapat menyesuaikan LC_COLLATE/LC_CTYPE sesuai hasil perintah `\l`.
CREATE DATABASE oatcake_db
    OWNER oatcake_user
    ENCODING 'UTF8'
    LC_COLLATE 'C.UTF-8'
    LC_CTYPE 'C.UTF-8'
    TEMPLATE template0;

-- 3. Cabut seluruh hak akses default dari role PUBLIC pada database oatcake_db
REVOKE ALL ON DATABASE oatcake_db FROM PUBLIC;
GRANT CONNECT, TEMP ON DATABASE oatcake_db TO oatcake_user;

-- 4. Setel timezone default database agar selaras dengan Laravel (Asia/Jakarta)
ALTER DATABASE oatcake_db SET timezone TO 'Asia/Jakarta';

-- 5. Masuk ke database oatcake_db untuk mengunci schema public (PostgreSQL 15+/18)
\c oatcake_db

ALTER SCHEMA public OWNER TO oatcake_user;
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT USAGE, CREATE ON SCHEMA public TO oatcake_user;

ALTER DEFAULT PRIVILEGES FOR ROLE oatcake_user IN SCHEMA public
    GRANT ALL ON TABLES TO oatcake_user;
ALTER DEFAULT PRIVILEGES FOR ROLE oatcake_user IN SCHEMA public
    GRANT ALL ON SEQUENCES TO oatcake_user;
```

### 2.2 Verifikasi & Penguatan Isolasi Lintas Database (Melindungi Niham)

Secara default di PostgreSQL, *role* `PUBLIC` memiliki hak `CONNECT` pada setiap database baru kecuali dicabut secara eksplisit. Lakukan pemeriksaan terlebih dahulu agar pencabutan hak akses tidak memutus koneksi Niham:

```sql
-- Jalankan di sudo -u postgres psql untuk melihat daftar database & hak aksesnya
\l+
```

> [!WARNING]
> **Lindungi Koneksi Niham sebelum `REVOKE`**:
> Pastikan kolom **Access privileges** milik database Niham sudah mencantumkan hak akses eksplisit untuk user database Niham (misal `niham_user=CTc/postgres`) **sebelum** mencabut hak `PUBLIC` pada database Niham! Jika belum eksplisit, berikan `GRANT CONNECT ON DATABASE <nama_db_niham> TO <nama_user_niham>;` terlebih dahulu sebelum menjalankan `REVOKE CONNECT ON DATABASE <nama_db_niham> FROM PUBLIC;`.

Selanjutnya, persempit akses autentikasi jaringan di berkas `pg_hba.conf` (lokasinya didapat dari `SHOW hba_file;` pada tahap Pre-Flight, umumnya `/var/lib/pgsql/data/pg_hba.conf`). Tambahkan dua baris berikut **di bagian paling atas sebelum aturan umum `host all all`** agar `oatcake_user` hanya boleh masuk ke `oatcake_db` melalui *loopback* `127.0.0.1` / `::1` dengan autentikasi `scram-sha-256`:

```text
# Isolasi ketat OATCAKE (tempatkan DI ATAS aturan `host all all`)
host    oatcake_db      oatcake_user    127.0.0.1/32            scram-sha-256
host    oatcake_db      oatcake_user    ::1/128                 scram-sha-256
```

Terapkan konfigurasi PostgreSQL secara **zero downtime** (tanpa `systemctl restart postgresql`):

```bash
# Reload konfigurasi PostgreSQL tanpa memutus koneksi aktif Niham
sudo -u postgres psql -c "SELECT pg_reload_conf();"

# Verifikasi koneksi POSITIF (harus BERHASIL masuk ke oatcake_db)
PGPASSWORD='<PASSWORD_OATCAKE_KUAT>' psql -h 127.0.0.1 -U oatcake_user -d oatcake_db -c "SELECT current_database(), current_user, current_setting('TimeZone');"

# Verifikasi isolasi NEGATIF (ganti <NAMA_DB_NIHAM>; harus DITOLAK oleh pg_hba.conf / privilege)
PGPASSWORD='<PASSWORD_OATCAKE_KUAT>' psql -h 127.0.0.1 -U oatcake_user -d <NAMA_DB_NIHAM> -c "SELECT 1;"
```

*(Opsional)* Untuk penyesuaian batas memori PostgreSQL (`shared_buffers`, `work_mem`, `max_connections`) di server co-hosting 2–4 GB RAM, rujuk profil pada [COHOSTING_OPTIMIZATION.md](COHOSTING_OPTIMIZATION.md#4-profil-konfigurasi-server-co-hosting-opensuse-leap) — ingat bahwa perubahan parameter `shared_buffers` pada `postgresql.conf` memerlukan jendela pemeliharaan terencana karena membutuhkan restart PostgreSQL.

---

## 3. Setup Direktori, Git, Permissions & Dependensi (openSUSE Leap 16.0)

### 3.1 Clone atau Transfer Repositori ke `/srv/www/oatcake`

#### Opsi A: Clone Langsung dari Repositori Git (`v1.0.0-rc`)

```bash
# Buat direktori induk dengan kepemilikan sementara untuk user deploy
sudo mkdir -p /srv/www/oatcake
sudo chown "$(whoami)":www /srv/www/oatcake

# Clone tag rilis v1.0.0-rc
git clone --branch v1.0.0-rc --single-branch git@github.com:nabararifki/oatcake.git /srv/www/oatcake
cd /srv/www/oatcake
```

*(Catatan: Jika repositori bersifat privat dan server menggunakan HTTPS atau Deploy Key, sesuaikan URL remote ke `https://github.com/nabararifki/oatcake.git` atau pasang SSH Deploy Key terlebih dahulu).*

#### Opsi B: Transfer Kode via `rsync` dari Mesin Lokal/Build

Jika kode ditransfer dari workstation atau CI server (tanpa menyertakan `.env`, `vendor/`, `node_modules/`, atau log lokal):

```bash
rsync -avz --delete \
  --exclude='.git' \
  --exclude='.env' \
  --exclude='vendor' \
  --exclude='node_modules' \
  --exclude='storage/logs/*' \
  --exclude='storage/framework/cache/data/*' \
  --exclude='storage/framework/sessions/*' \
  --exclude='storage/framework/views/*' \
  ./ user_ssh@<IP_SERVER_HETZNER>:/srv/www/oatcake/
```

### 3.2 Konfigurasi Berkas `.env` Produksi

Salin berkas `.env.example` menjadi `.env` dan sesuaikan untuk lingkungan produksi OATCAKE:

```bash
cd /srv/www/oatcake
cp .env.example .env
```

Isi `/srv/www/oatcake/.env` dengan konfigurasi produksi lengkap berikut (sesuaikan bagian `DB_PASSWORD`, `MAIL_*`, `VAPID_*`, dan `GOOGLE_*`):

```env
APP_NAME="OATCAKE"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://oatcake.zonabara.com
APP_TIMEZONE=Asia/Jakarta

APP_LOCALE=id
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=id_ID

APP_MAINTENANCE_DRIVER=file
# APP_MAINTENANCE_STORE=database

BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=daily
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=warning

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=oatcake_db
DB_USERNAME=oatcake_user
DB_PASSWORD="<PASSWORD_OATCAKE_KUAT>"
DB_SSLMODE=prefer

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_PATH=/
SESSION_DOMAIN=oatcake.zonabara.com
SESSION_COOKIE=oatcake-session
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database

CACHE_STORE=database
CACHE_PREFIX=oatcake_cache_

MAIL_MAILER=smtp
MAIL_SCHEME=tls
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS="noreply@zonabara.com"
MAIL_FROM_NAME="${APP_NAME}"

VITE_APP_NAME="${APP_NAME}"

# W3C Web Push (diisi dari output `php artisan webpush:vapid`)
VAPID_PUBLIC_KEY=
VAPID_PRIVATE_KEY=
VAPID_SUBJECT=mailto:admin@zonabara.com

# Google OAuth 2.0 (opsional jika login Google diaktifkan)
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=https://oatcake.zonabara.com/auth/google/callback
```

> [!IMPORTANT]
> **Isolasi Cookie Sesi dari Niham**:
> Pastikan **`SESSION_DOMAIN=oatcake.zonabara.com`** (bukan `.zonabara.com`) dan **`SESSION_COOKIE=oatcake-session`**. Hal ini menjamin cookie sesi dan token CSRF OATCAKE tidak pernah dikirim ke `niham.zonabara.com` maupun bertabrakan dengan cookie milik Niham.
> Selain itu, pastikan **`LOG_STACK=daily`** agar Laravel melakukan rotasi log harian (`storage/logs/laravel-YYYY-MM-DD.log`) dan tidak memenuhi disk server co-hosting.
> Jika menggunakan Google OAuth 2.0, daftarkan `https://oatcake.zonabara.com/auth/google/callback` di Google Cloud Console (*Authorized redirect URIs*).

### 3.3 Eksekusi Kompilasi Dependensi, Migrasi & Setup VAPID

Jalankan rangkaian perintah *build* dan inisialisasi berikut di dalam `/srv/www/oatcake`:

```bash
cd /srv/www/oatcake

# 1. Instal dependensi PHP produksi (tanpa dev packages, autoloader teroptimasi)
composer install --optimize-autoloader --no-dev

# 2. Instal dependensi Node.js & kompilasi aset frontend Vite (Tailwind + Alpine + InteractJS + Leaflet)
npm install
npm run build

# 3. Generate APP_KEY produksi (hanya jika belum terisi di .env)
php artisan key:generate --force

# 4. Buat symlink public/storage -> storage/app/public
php artisan storage:link

# 5. Jalankan migrasi skema PostgreSQL 18 (termasuk indeks komposit & GIN)
php artisan migrate --force

# 6. Generate pasangan kunci EC P-256 (prime256v1) VAPID untuk Web Push
php artisan webpush:vapid
```

> [!WARNING]
> **Catatan Penting Perintah `php artisan webpush:vapid`**:
> Perintah `php artisan webpush:vapid` mencetak baris `VAPID_PUBLIC_KEY=...`, `VAPID_PRIVATE_KEY=...`, dan `VAPID_SUBJECT=...` ke terminal (**tidak otomatis menulis ke berkas `.env`**).
> 1. Salin nilai `VAPID_PUBLIC_KEY` dan `VAPID_PRIVATE_KEY` yang tampil di terminal ke dalam `/srv/www/oatcake/.env`, serta pastikan `VAPID_SUBJECT=mailto:admin@zonabara.com`.
> 2. *(Opsional)* Untuk memastikan aset ikon PWA tersedia di `public/icons/`, Anda juga dapat menjalankan:
>    ```bash
>    php artisan pwa:generate-icons
>    ```
> 3. *(Opsional — Setup Database Awal Produksi)* Jika ini adalah instalasi bersih pertama kali (bukan restorasi dari backup), jalankan seeder data dasar yang dibutuhkan tanpa data dummy UAT:
>    ```bash
>    php artisan db:seed --class=LeaveTypeSeeder --force
>    php artisan db:seed --class=PublicHolidaySeeder --force
>    php artisan db:seed --class=SuperAdminSeeder --force
>    ```
>    **SEGERA UBAH** kata sandi bawaan akun `superadmin` (`admin@zonabara.com`, sandi awal `Password123!`) langsung setelah login pertama kali!

### 3.4 Pengaturan Ownership, Permissions & Konteks SELinux (openSUSE Leap)

Di openSUSE Leap, layanan PHP-FPM secara default berjalan sebagai user **`wwwrun`** dan group **`www`**, sedangkan Nginx dapat berjalan sebagai **`wwwrun`** atau **`nginx`** (sesuai hasil `grep user /etc/nginx/nginx.conf` pada Prasyarat). Terapkan *least-privilege permissions* berikut:

```bash
# 1. Pastikan direktori log & runtime dimiliki sesuai prinsip least privilege
#    Kepemilikan kode aplikasi: root:www (dapat dibaca PHP-FPM/wwwrun, tidak dapat ditulis proses web)
sudo chown -R root:www /srv/www/oatcake
sudo find /srv/www/oatcake -type d -exec chmod 0755 {} +
sudo find /srv/www/oatcake -type f -exec chmod 0644 {} +

# 2. Pastikan artisan & biner eksekusi tetap executable
sudo chmod 0750 /srv/www/oatcake/artisan
sudo chmod -R 0755 /srv/www/oatcake/node_modules/.bin 2>/dev/null || true

# 3. Kunci berkas .env agar hanya dapat dibaca oleh root & group www (wwwrun)
sudo chown root:www /srv/www/oatcake/.env
sudo chmod 0640 /srv/www/oatcake/.env

# 4. Berikan hak tulis HANYA pada storage/ (termasuk swafoto privat WebP) & bootstrap/cache/
sudo chown -R wwwrun:www /srv/www/oatcake/storage /srv/www/oatcake/bootstrap/cache
sudo find /srv/www/oatcake/storage /srv/www/oatcake/bootstrap/cache -type d -exec chmod 2775 {} +
sudo find /srv/www/oatcake/storage /srv/www/oatcake/bootstrap/cache -type f -exec chmod 0664 {} +

# 5. Pastikan symlink storage dapat ditelusuri Nginx
sudo chown -h wwwrun:www /srv/www/oatcake/public/storage

# 6. Buat cache konfigurasi, route, event, dan view Laravel sebagai user wwwrun
#    (jalankan SETELAH VAPID_PUBLIC_KEY & VAPID_PRIVATE_KEY disimpan ke .env!)
sudo -u wwwrun php /srv/www/oatcake/artisan optimize
```

#### Konfigurasi SELinux (Jika `getenforce` Mengembalikan `Enforcing` atau `Permissive`)

openSUSE Leap 16.0 mengaktifkan SELinux secara default pada profil server tertentu. Apabila hasil `getenforce` pada tahap Prasyarat menunjukkan **`Enforcing`**, terapkan pelabelan konteks SELinux dan boolean berikut agar Nginx, PHP-FPM, PostgreSQL, serta outbound Web Push tidak terblokir:

```bash
# Beri konteks read-only untuk seluruh proyek & read-write hanya untuk storage & bootstrap/cache
sudo semanage fcontext -a -t httpd_sys_content_t "/srv/www/oatcake(/.*)?"
sudo semanage fcontext -a -t httpd_sys_rw_content_t "/srv/www/oatcake/storage(/.*)?"
sudo semanage fcontext -a -t httpd_sys_rw_content_t "/srv/www/oatcake/bootstrap/cache(/.*)?"
sudo restorecon -Rv /srv/www/oatcake

# Izinkan PHP-FPM melakukan koneksi keluar ke endpoint W3C Web Push (FCM/Mozilla/Apple) & Google OAuth
sudo setsebool -P httpd_can_network_connect 1

# Izinkan PHP-FPM mengakses PostgreSQL 18 melalui TCP 127.0.0.1:5432
sudo setsebool -P httpd_can_network_connect_db 1
```

---

## 4. Konfigurasi PHP 8.4 FPM Pool & Server Block Nginx (*Virtual Host*)

### 4.1 Pembuatan Pool PHP 8.4 FPM Terisolasi (`/etc/php8/fpm/php-fpm.d/oatcake.conf`)

Memisahkan pool PHP-FPM OATCAKE dari pool milik Niham memastikan lonjakan lalu lintas (misalnya 30 staf mengunggah swafoto presensi secara bersamaan saat pergantian shift) tidak menghabiskan worker PHP milik Niham.

Buat direktori log PHP-FPM (jika belum ada) dan tulis konfigurasi pool berikut sesuai profil co-hosting di [COHOSTING_OPTIMIZATION.md](COHOSTING_OPTIMIZATION.md):

```bash
sudo mkdir -p /var/log/php-fpm /run/php-fpm
sudo chown wwwrun:www /var/log/php-fpm

sudo tee /etc/php8/fpm/php-fpm.d/oatcake.conf > /dev/null <<'EOF'
[oatcake]
user = wwwrun
group = www

listen = /run/php-fpm/oatcake.sock
listen.owner = wwwrun
listen.group = www
listen.mode = 0660
; Penting untuk openSUSE jika master/worker Nginx berjalan sebagai user `nginx` maupun `wwwrun`
listen.acl_users = nginx,wwwrun

pm = dynamic
pm.max_children = 16
pm.start_servers = 3
pm.min_spare_servers = 2
pm.max_spare_servers = 6
pm.max_requests = 500
pm.process_idle_timeout = 10s

; Batas sumber daya per worker & upload OATCAKE
php_admin_value[memory_limit] = 192M
php_admin_value[upload_max_filesize] = 25M
php_admin_value[post_max_size] = 28M
php_admin_value[max_execution_time] = 45
php_admin_value[error_log] = /var/log/php-fpm/oatcake-error.log
php_admin_flag[log_errors] = on
php_admin_value[date.timezone] = Asia/Jakarta
EOF
```

> [!TIP]
> **Jika Menggunakan Fitur Restore Arsip `.zip` Hingga 100 MB (`/admin/backups`)**:
> Nilai default `upload_max_filesize = 25M` dan `post_max_size = 28M` di atas optimal untuk operasional harian (swafoto presensi & lampiran cuti maksimal `25 MB`). Apabila Super Admin hendak mengunggah arsip backup `.zip` tenant berukuran besar (maksimal aplikasi `100 MB`), naikkan sementara nilai di atas ke `100M` / `105M` (dan `client_max_body_size 105M;` di Nginx), lalu reload PHP-FPM dan Nginx.

Validasi dan muat ulang PHP-FPM tanpa memutus *request* Niham:

```bash
sudo php-fpm -t && sudo systemctl reload php-fpm
ls -la /run/php-fpm/oatcake.sock
```

### 4.2 Bagaimana Nginx Memisahkan Traffic `niham.zonabara.com` vs `oatcake.zonabara.com`

Pada server co-hosting dengan satu alamat IP publik pada port `80` dan `443`, Nginx memisahkan lalu lintas kedua aplikasi secara deterministik melalui mekanisme berikut:
1. **TLS SNI (*Server Name Indication*)**: Saat Cloudflare Edge membuka koneksi TLS ke port `443` Hetzner, Cloudflare mengirimkan nama host tujuan dalam ekstensi SNI (`oatcake.zonabara.com` atau `niham.zonabara.com`). Nginx mencocokkan SNI tersebut dengan direktif `server_name` untuk memilih sertifikat Origin yang tepat (`/etc/nginx/ssl/oatcake.zonabara.com.pem` vs sertifikat milik Niham).
2. **HTTP `Host` Header**: Setelah koneksi TLS terbentuk, Nginx membaca header HTTP `Host` dan mengarahkan *request* ke blok `server { ... }` yang cocok (`root /srv/www/oatcake/public;` atau direktori root Niham).
3. **Aturan Tanpa `default_server`**: Berkas vhost `oatcake.conf` **dilarang keras** menambahkan parameter `default_server` pada `listen 80;` maupun `listen 443 ssl;`. Dengan demikian, *default server* eksisting milik Niham tidak pernah bentrok (`duplicate default server`), dan seluruh konfigurasi Niham tetap utuh 100%.

### 4.3 Pembuatan Snippet Header Keamanan & Cloudflare Real-IP

Untuk menghindari perilaku bawaan Nginx (di mana direktif `add_header` di level `server` otomatis terhapus jika suatu `location` seperti `= /sw.js` mendefinisikan `add_header` sendiri), pisahkan header keamanan ke berkas *snippet* agar dapat di-*include* kembali secara konsisten:

```bash
sudo mkdir -p /etc/nginx/snippets

# 1. Snippet Header Keamanan OATCAKE
#    Catatan: camera=(self) dan geolocation=(self) WAJIB diizinkan untuk swafoto & geofencing presensi!
sudo tee /etc/nginx/snippets/oatcake-security-headers.conf > /dev/null <<'EOF'
add_header X-Frame-Options "SAMEORIGIN" always;
add_header X-Content-Type-Options "nosniff" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header Permissions-Policy "camera=(self), geolocation=(self), microphone=()" always;
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
EOF
```

Selanjutnya, buat snippet pemulihan IP asli klien (`CF-Connecting-IP`) dari Cloudflare agar fitur *Rate Limiter* login (`LoginRequest`), pencatatan IP presensi (`AttendanceController`), dan forensik `AuditTrailService` di OATCAKE merekam IP perangkat pengguna asli (bukan IP node proxy Cloudflare):

```bash
# 2. Snippet Cloudflare Real-IP (dieksekusi di dalam konteks server oatcake.zonabara.com)
sudo tee /etc/nginx/snippets/cloudflare-real-ip.conf > /dev/null <<'EOF'
# IPv4 Cloudflare Resmi
set_real_ip_from 103.21.244.0/22;
set_real_ip_from 103.22.200.0/22;
set_real_ip_from 103.31.4.0/22;
set_real_ip_from 104.16.0.0/13;
set_real_ip_from 104.24.0.0/14;
set_real_ip_from 108.162.192.0/18;
set_real_ip_from 131.0.72.0/22;
set_real_ip_from 141.101.64.0/18;
set_real_ip_from 162.158.0.0/15;
set_real_ip_from 172.64.0.0/13;
set_real_ip_from 173.245.48.0/20;
set_real_ip_from 188.114.96.0/20;
set_real_ip_from 190.93.240.0/20;
set_real_ip_from 197.234.240.0/22;
set_real_ip_from 198.41.128.0/17;

# IPv6 Cloudflare Resmi
set_real_ip_from 2400:cb00::/32;
set_real_ip_from 2606:4700::/32;
set_real_ip_from 2803:f800::/32;
set_real_ip_from 2405:b500::/32;
set_real_ip_from 2405:8100::/32;
set_real_ip_from 2a06:98c0::/29;
set_real_ip_from 2c0f:f248::/32;

real_ip_header CF-Connecting-IP;
EOF
```

> [!TIP]
> Daftar CIDR resmi Cloudflare dapat diperbarui sewaktu-waktu secara otomatis dari endpoint `https://www.cloudflare.com/ips-v4` dan `https://www.cloudflare.com/ips-v6`.

### 4.4 Pembuatan Virtual Host Nginx (`/etc/nginx/vhosts.d/oatcake.conf`)

Buat berkas `/etc/nginx/vhosts.d/oatcake.conf` dengan templat produksi lengkap di bawah ini (mencakup pengalihan HTTP $\rightarrow$ HTTPS, TLS Origin Cert Cloudflare, *routing* Laravel `try_files`, pengecualian cache PWA `public/sw.js`, cache aset Vite `/build/`, header keamanan, dan proteksi berkas sensitif):

```bash
sudo tee /etc/nginx/vhosts.d/oatcake.conf > /dev/null <<'EOF'
# ==============================================================================
# OATCAKE v1.0.0-rc Virtual Host (Co-Hosted alongside niham.zonabara.com)
# PENTING: Jangan menambahkan `default_server` agar vhost Niham tidak terganggu.
# ==============================================================================

# 1. Redirect HTTP (Port 80) -> HTTPS
server {
    listen 80;
    # Aktifkan baris di bawah hanya jika IPv6 diaktifkan pada Nginx Niham/VPS:
    # listen [::]:80;
    server_name oatcake.zonabara.com;

    return 301 https://oatcake.zonabara.com$request_uri;
}

# 2. Server Block HTTPS Utama (Port 443)
server {
    listen 443 ssl;
    # Aktifkan baris di bawah hanya jika IPv6 diaktifkan pada Nginx Niham/VPS:
    # listen [::]:443 ssl;
    # Untuk Nginx >= 1.25.1 gunakan `http2 on;` (di Nginx lama, hapus baris ini dan ubah `listen 443 ssl http2;`)
    http2 on;

    server_name oatcake.zonabara.com;
    root /srv/www/oatcake/public;
    index index.php;

    charset utf-8;
    server_tokens off;

    # Sertifikat Cloudflare Origin khusus OATCAKE
    ssl_certificate /etc/nginx/ssl/oatcake.zonabara.com.pem;
    ssl_certificate_key /etc/nginx/ssl/oatcake.zonabara.com.key;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305;
    ssl_prefer_server_ciphers off;
    ssl_session_cache shared:OATCAKE_SSL:10m;
    ssl_session_timeout 1d;

    # Log terisolasi khusus OATCAKE
    access_log /var/log/nginx/oatcake.access.log;
    error_log /var/log/nginx/oatcake.error.log warn;

    # Batas unggahan (25 MB foto/lampiran + overhead form; naikkan ke 105M jika restore ZIP 100 MB)
    client_max_body_size 30M;

    # Pemulihan IP asli klien dari Cloudflare & Header Keamanan Dasar
    include /etc/nginx/snippets/cloudflare-real-ip.conf;
    include /etc/nginx/snippets/oatcake-security-headers.conf;

    # Kompresi Gzip untuk aset teks & JSON manifest
    gzip on;
    gzip_vary on;
    gzip_comp_level 5;
    gzip_min_length 1024;
    gzip_types text/plain text/css application/json application/javascript application/manifest+json image/svg+xml application/xml;

    # Routing standar Laravel
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # Pengecualian Cache PWA: public/sw.js TIDAK BOLEH di-cache oleh browser/Nginx
    location = /sw.js {
        include /etc/nginx/snippets/oatcake-security-headers.conf;
        add_header Cache-Control "no-cache, no-store, must-revalidate" always;
        add_header Pragma "no-cache" always;
        add_header Expires "0" always;
        add_header Service-Worker-Allowed "/" always;
        etag off;
        if_modified_since off;
        try_files $uri =404;
    }

    # Web App Manifest PWA
    location = /manifest.webmanifest {
        include /etc/nginx/snippets/oatcake-security-headers.conf;
        default_type application/manifest+json;
        add_header Cache-Control "public, max-age=3600" always;
        try_files $uri =404;
    }

    # Aset hasil kompilasi Vite (memiliki hash konten pada nama berkas)
    location ^~ /build/ {
        include /etc/nginx/snippets/oatcake-security-headers.conf;
        expires 1y;
        add_header Cache-Control "public, immutable" always;
        access_log off;
        try_files $uri =404;
    }

    # Ikon PWA
    location ^~ /icons/ {
        include /etc/nginx/snippets/oatcake-security-headers.conf;
        expires 30d;
        add_header Cache-Control "public" always;
        access_log off;
        try_files $uri =404;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    # Handler PHP 8.4 FPM via socket pool terisolasi OATCAKE
    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass unix:/run/php-fpm/oatcake.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param PATH_INFO $fastcgi_path_info;
        fastcgi_hide_header X-Powered-By;
        fastcgi_read_timeout 60;
        fastcgi_buffers 16 16k;
        fastcgi_buffer_size 32k;
    }

    # Blokir akses langsung ke berkas tersembunyi (.env, .git, .htaccess) & ekstensi sensitif
    location ~ /\.(?!well-known).* {
        deny all;
        access_log off;
        log_not_found off;
    }

    location ~* \.(env|log|sql|bak|ini|sh)$ {
        deny all;
        access_log off;
        log_not_found off;
    }
}
EOF
```

### 4.5 Validasi & Aktivasi Nginx Secara Zero Downtime

> [!CAUTION]
> **Jangan pernah** menjalankan `systemctl restart nginx` atau mengaktifkan vhost tanpa terlebih dahulu lolos `sudo nginx -t`. Jika `nginx -t` melaporkan galat (misalnya direktif `http2 on;` tidak didukung pada versi Nginx yang terpasang — ubah ke `listen 443 ssl http2;` dan hapus baris `http2 on;`), perbaiki terlebih dahulu sebelum me-reload!

```bash
# 1. Uji sintaks keseluruhan konfigurasi Nginx (termasuk konfigurasi Niham)
sudo nginx -t

# 2. Terapkan secara graceful reload (tidak memutus koneksi aktif Niham)
sudo systemctl reload nginx

# 3. Verifikasi cepat dari server lokal bahwa Niham tetap HTTP 200 & OATCAKE merespons /up
curl -sI --resolve niham.zonabara.com:443:127.0.0.1 https://niham.zonabara.com | head -n 5
curl -sI --resolve oatcake.zonabara.com:443:127.0.0.1 https://oatcake.zonabara.com/up | head -n 5
```

---

## 5. Orkestrasi Background Jobs (Systemd untuk Queue & Scheduler/Cron)

OATCAKE memerlukan dua proses latar belakang agar operasional berjalan penuh:
1. **Queue Worker (`QUEUE_CONNECTION=database`)**: Mengirimkan notifikasi *W3C Web Push* asinkron (`App\Jobs\SendWebPushNotificationJob`, dengan 3 kali percobaan ulang / *backoff* `10,30,60` detik) ke gateway *push* browser tanpa memperlambat respons halaman web.
2. **Laravel Scheduler (`schedule:run`)**: Mengeksekusi tugas pemeliharaan harian pada zona waktu `Asia/Jakarta` (sebagaimana didefinisikan pada `routes/console.php`):

| Waktu (WIB) | Perintah Artisan | Fungsi Operasional |
| :--- | :--- | :--- |
| `00:05` setiap hari | `attendance:prune-photos` | Menghapus berkas swafoto presensi kedaluwarsa sesuai retensi kuota masing-masing properti |
| `00:15` setiap hari | `leave:accrue-annual` | Akrual otomatis saldo cuti tahunan (*Annual Leave*) ke buku besar *append-only* |
| `00:30` setiap hari | `leave:prune-expired` | Pembersihan/kedaluwarsa saldo cuti berbatas waktu (DP/AL) secara idempoten |

### 5.1 Systemd Service untuk Queue Worker Web Push (`/etc/systemd/system/oatcake-queue.service`)

Unit Systemd di bawah ini menjalankan *worker* sebagai user `wwwrun` dengan pembatasan memori & CPU (*cgroup guardrails*) agar tidak mengganggu aplikasi Niham:

```bash
sudo tee /etc/systemd/system/oatcake-queue.service > /dev/null <<'EOF'
[Unit]
Description=OATCAKE Laravel Queue Worker (Web Push & Async Jobs)
After=network-online.target postgresql.service
Wants=network-online.target

[Service]
User=wwwrun
Group=www
WorkingDirectory=/srv/www/oatcake
Restart=always
RestartSec=5
KillSignal=SIGTERM
TimeoutStopSec=90

ExecStart=/usr/bin/php /srv/www/oatcake/artisan queue:work database --queue=default --sleep=3 --tries=3 --backoff=10,30,60 --timeout=60 --max-jobs=1000 --max-time=3600 --memory=128

# Pembatasan sumber daya Co-Hosting (Cgroup Guardrails)
MemoryMax=192M
CPUQuota=50%
Nice=5

# Security Hardening
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=/srv/www/oatcake/storage /srv/www/oatcake/bootstrap/cache

StandardOutput=journal
StandardError=journal
SyslogIdentifier=oatcake-queue

[Install]
WantedBy=multi-user.target
EOF
```

Aktifkan dan jalankan Queue Worker:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now oatcake-queue.service
sudo systemctl status oatcake-queue.service --no-pager
```

### 5.2 Systemd Timer untuk Laravel Scheduler (Direkomendasikan)

Menggunakan pasangan **Systemd Service + Timer** memberikan pencatatan terpusat di `journald`, isolasi proses yang konsisten dengan `oatcake-queue.service`, serta pelacakan status eksekusi per menit.

```bash
# 1. Buat unit service oneshot scheduler
sudo tee /etc/systemd/system/oatcake-scheduler.service > /dev/null <<'EOF'
[Unit]
Description=OATCAKE Laravel Task Scheduler Runner
After=network-online.target postgresql.service

[Service]
Type=oneshot
User=wwwrun
Group=www
WorkingDirectory=/srv/www/oatcake
ExecStart=/usr/bin/php /srv/www/oatcake/artisan schedule:run --no-interaction

# Security Hardening
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=/srv/www/oatcake/storage /srv/www/oatcake/bootstrap/cache

StandardOutput=journal
StandardError=journal
SyslogIdentifier=oatcake-scheduler
EOF

# 2. Buat unit timer setiap menit
sudo tee /etc/systemd/system/oatcake-scheduler.timer > /dev/null <<'EOF'
[Unit]
Description=Run OATCAKE Laravel Scheduler every minute

[Timer]
OnCalendar=*-*-* *:*:00
AccuracySec=1s
Persistent=true
Unit=oatcake-scheduler.service

[Install]
WantedBy=timers.target
EOF

# 3. Reload systemd, aktifkan timer, dan verifikasi jadwal
sudo systemctl daemon-reload
sudo systemctl enable --now oatcake-scheduler.timer
sudo systemctl list-timers oatcake-scheduler.timer --no-pager
sudo -u wwwrun php /srv/www/oatcake/artisan schedule:list
```

### 5.3 Alternatif: Menggunakan Crontab `wwwrun` (`crontab -u wwwrun -e`)

> [!WARNING]
> **Pilih SALAH SATU saja**: Gunakan **Systemd Timer (5.2)** *atau* **Crontab (5.3)** — **jangan pernah mengaktifkan keduanya secara bersamaan** agar perintah terjadwal tidak dipicu ganda!

Apabila tim operasional memilih cron standar ketimbang Systemd Timer, lewati langkah 5.2 lalu jalankan `sudo crontab -u wwwrun -e` dan tambahkan baris berikut:

```cron
* * * * * cd /srv/www/oatcake && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

Pastikan daemon `cron` aktif pada openSUSE (`sudo systemctl enable --now cron`).

---

## 6. Verifikasi End-to-End & Runbook Operasional

### 6.1 Uji Asap (*Smoke Test*) Produksi Lengkap

Setelah seluruh langkah pada Bagian 1–5 selesai (dan DNS Cloudflare sudah terpropagasi), jalankan rangkaian verifikasi berikut dari server VPS atau mesin lokal:

```bash
# 1. Pastikan Niham tetap berjalan normal tanpa downtime
curl -sI https://niham.zonabara.com | head -n 5

# 2. Uji pengalihan HTTP -> HTTPS pada OATCAKE (harus 301 ke https://oatcake.zonabara.com)
curl -sI http://oatcake.zonabara.com/up | head -n 5

# 3. Uji endpoint healthcheck Laravel OATCAKE melalui Cloudflare (harus 200 OK)
curl -sI https://oatcake.zonabara.com/up | grep -iE '^(HTTP|x-frame-options|x-content-type-options|permissions-policy|strict-transport-security)'

# 4. Verifikasi pengecualian cache PWA /sw.js (harus berisi no-cache, no-store, must-revalidate)
curl -sI https://oatcake.zonabara.com/sw.js | grep -iE '^(HTTP|cache-control|pragma|service-worker-allowed|cf-cache-status)'

# 5. Verifikasi Web App Manifest (harus Content-Type: application/manifest+json)
curl -sI https://oatcake.zonabara.com/manifest.webmanifest | grep -iE '^(HTTP|content-type|cache-control)'

# 6. Verifikasi layanan background jobs & antrean gagal
sudo systemctl is-active oatcake-queue.service
sudo systemctl is-active oatcake-scheduler.timer
sudo -u wwwrun php /srv/www/oatcake/artisan queue:failed

# 7. Jalankan diagnostik & benchmark co-hosting OATCAKE
sudo -u wwwrun php /srv/www/oatcake/artisan oatcake:optimize-cohosting --benchmark
```

Lakukan pula pengujian fungsional di browser (`https://oatcake.zonabara.com`):
- Buka *DevTools* $\rightarrow$ *Application* $\rightarrow$ *Service Workers* dan pastikan `sw.js` berstatus **activated and running**.
- Uji registrasi **Web Push** di menu notifikasi browser dan pantau log pengiriman di server:
  ```bash
  sudo journalctl -u oatcake-queue.service -f
  ```
- Uji fitur kamera swafoto & lokasi presensi untuk memastikan header `Permissions-Policy` tidak memblokir kamera atau GPS browser.

### 6.2 Runbook Pembaruan Rilis (*Zero-Downtime Redeploy*)

Saat merilis pembaruan kode OATCAKE di masa mendatang tanpa memengaruhi operasional Niham:

```bash
cd /srv/www/oatcake

# 1. Aktifkan mode pemeliharaan singkat hanya untuk OATCAKE
sudo -u wwwrun php artisan down --retry=30

# 2. Ambil tag rilis terbaru & bangun ulang dependensi
git fetch --tags
git checkout <TAG_RILIS_BARU>
composer install --optimize-autoloader --no-dev
npm ci && npm run build

# 3. Jalankan migrasi & segarkan cache Laravel
sudo -u wwwrun php artisan migrate --force
sudo -u wwwrun php artisan optimize:clear
sudo -u wwwrun php artisan optimize

# 4. Pastikan permission folder writable tetap terjaga
sudo chown -R wwwrun:www /srv/www/oatcake/storage /srv/www/oatcake/bootstrap/cache

# 5. Reload PHP-FPM (membersihkan OPcache jika validate_timestamps=0) & restart queue worker
sudo systemctl reload php-fpm
sudo -u wwwrun php artisan queue:restart

# 6. Buka kembali akses OATCAKE
sudo -u wwwrun php artisan up
```

### 6.3 Prosedur Darurat: Menonaktifkan OATCAKE Tanpa Menyentuh Niham

Apabila suatu saat OATCAKE perlu dinonaktifkan sementara untuk investigasi darurat tanpa menghentikan layanan Niham sedikit pun:

```bash
# Nonaktifkan vhost OATCAKE dan hentikan background worker
sudo mv /etc/nginx/vhosts.d/oatcake.conf /etc/nginx/vhosts.d/oatcake.conf.disabled
sudo nginx -t && sudo systemctl reload nginx
sudo systemctl stop oatcake-queue.service oatcake-scheduler.timer
```
