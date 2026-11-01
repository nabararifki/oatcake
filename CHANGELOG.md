# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
