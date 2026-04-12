# Peta dan Alur Data Aplikasi Udara Hideaway Villa

Dokumen ini dibuat untuk membantu memahami:
- modul aplikasi
- hubungan data utama
- alur transaksi
- dampak transaksi ke invoice, jurnal, laporan, kamar, housekeeping, dan inventory

## 1. Peta Modul Aplikasi

- `Dashboard`
  Menampilkan ringkasan bisnis, occupancy, outstanding, revenue mix, dan aturan penalti cancel.
- `Reservasi`
  Mengelola booking, kamar yang dipakai, add-on, status stay, cancel, no-show, check-in, check-out.
- `Kamar`
  Mengelola master kamar, room rack, status kamar, dan housekeeping turnaround queue.
- `Keuangan`
  Mengelola invoice, payment, refund, void, dan ringkasan finansial operasional.
- `Jurnal`
  Menampilkan jurnal umum dan jurnal otomatis dari transaksi.
- `COA`
  Menyimpan chart of accounts untuk semua posting akuntansi.
- `Persediaan`
  Mengelola item, pembelian, issue ke kamar, return dari kamar, dan retur pembelian.
- `Transportasi`
  Master tarif layanan transport.
- `Aktivitas`
  Master layanan add-on seperti scooter, island tour, boat ticket.
- `Laporan`
  Menyediakan laba rugi, neraca, arus kas, rekonsiliasi, dan audit trail.
- `Pengguna` dan `Hak Akses`
  Mengatur user dan permission aplikasi.

## 2. Peta Entitas Inti

```text
guests
  |
  v
bookings ----------------------> booking_addons
  |
  v
booking_rooms -----------------> rooms
  |
  v
invoices <--------------------- payments
  |                              |
  |                              v
  |                        payment_allocations
  |
  v
journals ---------------------> journal_lines
```

```text
rooms ------------------------> housekeeping_tasks
```

```text
inventory_items -------------> inventory_movements
                                   |
                                   v
                               journals
                                   |
                                   v
                              journal_lines
```

## 3. Hubungan Antar Tabel

- `guests` -> `bookings`
  Satu tamu bisa punya banyak booking.
- `bookings` -> `booking_rooms`
  Satu booking bisa punya satu atau lebih kamar.
- `bookings` -> `booking_addons`
  Satu booking bisa punya banyak add-on.
- `bookings` -> `invoices`
  Satu booking umumnya menghasilkan satu invoice aktif.
- `payments` -> `payment_allocations`
  Payment, refund, dan void dihubungkan ke invoice lewat allocation.
- `invoices` / `payments` / `inventory_movements` -> `journals` -> `journal_lines`
  Semua transaksi utama diposting ke jurnal.
- `rooms` -> `housekeeping_tasks`
  Kamar yang perlu tindak lanjut akan masuk queue housekeeping.

## 4. Alur Data Utama

### 4.1 Booking Baru

Contoh:
- Tamu: Budi
- Menginap 2 malam
- Room charge: Rp1.500.000

Alur:

```text
guests
  -> bookings
  -> booking_rooms
  -> invoices
  -> journals
  -> journal_lines
```

Dampak:
- `bookings` bertambah
- `booking_rooms` bertambah
- `invoices` dibuat
- jurnal invoice dibuat:
  - Dr Piutang
  - Cr Pendapatan kamar

### 4.2 Tambah Add-on

Contoh:
- Airport pickup Rp150.000
- Scooter Rp200.000

Alur:

```text
booking_addons
  -> update bookings.grand_total
  -> update invoices.grand_total
  -> rebuild journals invoice
```

Dampak:
- grand total booking naik
- grand total invoice naik
- jurnal invoice ikut naik:
  - Dr Piutang
  - Cr Pendapatan add-on

### 4.3 Pembayaran

Contoh:
- Tamu bayar Rp1.000.000 tunai

Alur:

```text
payments
  -> payment_allocations
  -> update invoices.paid_amount
  -> update invoices.balance_due
  -> journals
  -> journal_lines
```

Jurnal:
- Dr Kas/Bank
- Cr Piutang

Dampak:
- invoice outstanding turun
- arus kas naik
- rekonsiliasi payment berubah

### 4.4 Refund

Contoh:
- Ada kelebihan bayar Rp200.000
- dilakukan refund

Alur:

```text
payments (transaction_type = refund)
  -> payment_allocations (negatif)
  -> update invoices.paid_amount
  -> update invoices.balance_due
  -> journals
  -> journal_lines
```

Jurnal:
- Dr Piutang
- Cr Kas/Bank

Dampak:
- kas turun
- piutang naik lagi
- invoice outstanding bertambah lagi

### 4.5 Void Payment

Contoh:
- Kasir salah input payment Rp500.000
- payment di-void

Alur:

```text
payments (transaction_type = void)
  -> payment_allocations (negatif)
  -> update invoices.paid_amount
  -> update invoices.balance_due
  -> journals
  -> journal_lines
```

Dampak:
- settlement dibalik
- invoice kembali terbuka
- jurnal payment dibalik

### 4.6 Cancel Booking Dengan Penalti

Contoh:
- Total booking Rp2.000.000
- Penalti cancel owner 25%
- Penalti menjadi Rp500.000

Alur:

```text
bookings.status = cancelled
  -> sync booking financial state
  -> invoices.grand_total = penalti
  -> journals invoice dibentuk ulang
```

Jika ada penalti:
- invoice tetap hidup sebesar penalti
- jurnal menjadi:
  - Dr Piutang Rp500.000
  - Cr Pendapatan penalti cancel Rp500.000

Jika tidak ada penalti:
- invoice bisa menjadi nol
- jurnal invoice dibersihkan

### 4.7 Check-in dan Check-out

Check-in:

```text
bookings.status -> checked_in
rooms.status -> occupied
```

Check-out:

```text
bookings.status -> checked_out
rooms.status -> dirty
rooms -> housekeeping_tasks
```

Dampak:
- kamar selesai dipakai
- kamar masuk antrean housekeeping

### 4.8 Housekeeping

Saat kamar menjadi `dirty`, `cleaning`, `blocked`, atau `maintenance`:

```text
rooms
  -> housekeeping_tasks dibuat / diupdate
```

Saat task di-`Start`:

```text
housekeeping_tasks.status -> in_progress
rooms.status -> cleaning
```

Saat task di-`Done`:

```text
housekeeping_tasks.status -> done
rooms.status -> available
```

### 4.9 Pembelian Persediaan

Contoh:
- Beli shampoo 100 pcs @ Rp3.000

Alur:

```text
inventory_items
  -> inventory_movements (purchase)
  -> journals
  -> journal_lines
```

Jurnal:
- Dr Persediaan
- Cr Kas/Bank

Dampak:
- stok naik
- aset persediaan naik
- kas turun

### 4.10 Issue Item ke Kamar

Contoh:
- Issue shampoo 2 pcs ke room 101

Alur:

```text
inventory_movements (issue_room)
  -> journals
  -> journal_lines
```

Untuk consumable:
- Dr Beban amenities
- Cr Persediaan

Dampak:
- stok turun
- beban naik
- laba rugi terpengaruh

## 5. Peta Pengaruh Tiap Transaksi

### Booking baru

Pengaruh ke:
- `bookings`
- `booking_rooms`
- `invoices`
- `journals`
- `reports`

### Add-on

Pengaruh ke:
- `booking_addons`
- `bookings`
- `invoices`
- `journals`
- `reports`

### Payment

Pengaruh ke:
- `payments`
- `payment_allocations`
- `invoices`
- `journals`
- `cash flow`
- `reconciliation`

### Refund / Void

Pengaruh ke:
- `payments`
- `payment_allocations`
- `invoices`
- `journals`
- `cash flow`
- `reconciliation`

### Cancel booking

Pengaruh ke:
- `bookings`
- `invoices`
- `journals`
- `reports`

### Check-out

Pengaruh ke:
- `rooms`
- `housekeeping_tasks`

### Purchase inventory

Pengaruh ke:
- `inventory_movements`
- `journals`
- `balance sheet`
- `cash flow`

### Issue inventory

Pengaruh ke:
- `inventory_movements`
- `journals`
- `profit-loss`
- `balance sheet`

## 6. Versi Ringkas Alur Operasional Harian

1. Buat booking
2. Assign kamar
3. Check-in
4. Tambah add-on bila perlu
5. Issue item ke kamar bila perlu
6. Posting payment
7. Refund atau void bila ada koreksi
8. Check-out
9. Housekeeping menyelesaikan turnaround kamar
10. Owner/finance melihat laporan dan menjalankan night audit

## 7. Ringkasan Cepat

Kalau ingin memahami aplikasi ini paling cepat, lihat urutan berikut:

1. `Reservasi`
2. `Keuangan`
3. `Jurnal`
4. `Laporan`
5. `Kamar`
6. `Persediaan`

Urutan ini akan membantu memahami:
- transaksi operasional
- pengaruh ke invoice
- pengaruh ke jurnal
- pengaruh ke laporan
