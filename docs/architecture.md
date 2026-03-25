# Arsitektur Sistem

## Tujuan

Membangun sistem operasional hotel yang menggabungkan reservasi, keuangan, inventory, dan penjualan layanan tambahan dalam satu platform.

## Aktor Sistem

- Super Admin
- Admin Hotel
- Resepsionis
- Staff Keuangan
- Staff Operasional

## Modul Inti

### 1. Authentication and Authorization

- Login
- Role dan permission
- Audit aktivitas

### 2. Master Data

- Hotel profile
- Room type
- Room
- Add-on service master
- Inventory item master
- Customer / guest

### 3. Booking

- Reservasi manual
- Check availability kamar
- Check-in
- Check-out
- Booking status:
  - pending
  - confirmed
  - checked_in
  - checked_out
  - cancelled

### 4. Billing and Finance

- Invoice booking
- Invoice add-on
- Payment
- Metode pembayaran
- Ringkasan pendapatan

### 5. Inventory

- Master barang
- Penempatan barang per kamar
- Kondisi barang
- Penggantian / maintenance
- Riwayat pergerakan barang

### 6. Add-On Services

- Antar jemput
- Sewa scooter
- Paket wisata
- Boat ticket

Setiap add-on dapat:

- Memiliki harga
- Memiliki tanggal layanan
- Memiliki quantity
- Ditautkan ke booking

## Arsitektur Teknis

### Frontend

Vue SPA untuk dashboard admin dan operasional:

- Auth pages
- Dashboard
- Booking pages
- Room pages
- Finance pages
- Inventory pages
- Add-on pages

### Backend

REST API PHP dengan domain modular:

- `/api/auth`
- `/api/rooms`
- `/api/room-types`
- `/api/guests`
- `/api/bookings`
- `/api/invoices`
- `/api/payments`
- `/api/inventory-items`
- `/api/room-inventories`
- `/api/service-categories`
- `/api/service-bookings`

## Relasi Penting

- Satu `room_type` memiliki banyak `rooms`
- Satu `guest` memiliki banyak `bookings`
- Satu `booking` memakai satu `room`
- Satu `booking` memiliki banyak `booking_services`
- Satu `booking` memiliki satu atau banyak invoice/payment
- Satu `room` memiliki banyak inventory assignment

## Saran Struktur Backend Laravel

```text
backend/app/
├── Http/Controllers/Api/
├── Models/
├── Services/
├── Repositories/
├── Actions/
└── Enums/
```

## Saran Struktur Frontend Vue

```text
frontend/src/
├── api/
├── router/
├── stores/
├── layouts/
├── views/
├── components/
├── modules/
└── utils/
```

## Modul yang Sebaiknya Dikerjakan Lebih Dulu

1. Auth
2. Room type dan room
3. Guest
4. Booking
5. Invoice dan payment
6. Inventory
7. Add-on services

## Catatan Desain Bisnis

- Harga kamar sebaiknya disimpan ke booking saat transaksi dibuat agar perubahan harga master tidak merusak histori.
- Add-on juga perlu menyimpan snapshot harga saat dipesan.
- Inventory barang kamar sebaiknya dibedakan antara master barang dan assignment ke kamar.
- Pembayaran sebaiknya mendukung partial payment.
