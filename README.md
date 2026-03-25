# Hotel Book System

Starter blueprint untuk sistem hotel booking berbasis Vue.js di frontend dan PHP di backend.

## Cakupan Sistem

Project ini dirancang untuk mendukung:

- Manajemen kamar hotel
- Reservasi dan check-in/check-out
- Data tamu
- Keuangan dan invoice
- Inventory barang untuk kamar hotel
- Add-on service:
  - Antar jemput
  - Sewa scooter
  - Paket wisata perjalanan
  - Boat ticket

## Rekomendasi Stack

### Frontend

- Vue 3
- Vite
- Vue Router
- Pinia
- Axios

### Backend

- PHP 8.2+
- Laravel 11
- MySQL / MariaDB
- Laravel Sanctum untuk auth API

Laravel dipilih karena lebih cepat untuk pengembangan sistem bisnis seperti booking, billing, inventory, dan reporting.

## Struktur Project

```text
HOTEL-BOOK/
├── backend/
│   └── .gitkeep
├── frontend/
│   └── .gitkeep
├── database/
│   └── schema.sql
└── docs/
    └── architecture.md
```

## Modul Utama

1. Master data hotel
2. Room management
3. Booking and reservation
4. Guest management
5. Billing and finance
6. Inventory management
7. Add-on services
8. Reporting

## Alur Bisnis Inti

1. Admin membuat data kamar, tipe kamar, dan harga.
2. Staff membuat booking untuk tamu.
3. Booking dapat memiliki add-on layanan.
4. Saat check-in, sistem mencatat okupansi kamar.
5. Barang inventory per kamar dapat dipantau untuk kebutuhan operasional atau replacement.
6. Semua transaksi masuk ke invoice dan jurnal keuangan sederhana.
7. Setelah check-out, sistem menutup booking dan transaksi.

## Prioritas Pengembangan

### Phase 1

- Authentication dan role user
- Room type dan room
- Guest
- Booking
- Invoice dasar

### Phase 2

- Inventory per kamar
- Add-on services
- Payment tracking
- Dashboard dan laporan

### Phase 3

- Akuntansi lebih detail
- Multi-branch hotel
- Integrasi payment gateway
- Integrasi WhatsApp/email notification

## Langkah Berikutnya

Langkah paling aman setelah blueprint ini:

1. Generate backend Laravel di folder `backend`
2. Generate Vue 3 + Vite di folder `frontend`
3. Implementasi auth dan master data
4. Lanjut ke booking flow
5. Sambungkan invoice, add-on, dan inventory

Jika diinginkan, langkah berikutnya saya bisa langsung bantu:

1. Scaffold struktur Laravel + Vue
2. Buat ERD dan migrasi database Laravel
3. Buat dashboard admin awal
4. Buat modul booking end-to-end pertama
