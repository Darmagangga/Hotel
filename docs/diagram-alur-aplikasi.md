# Diagram ASCII dan Flowchart Aplikasi Udara Hideaway Villa

Dokumen ini adalah versi visual dari alur data aplikasi.

## 1. Peta Modul

```text
                 +-------------------+
                 |     DASHBOARD     |
                 | owner summary     |
                 +---------+---------+
                           |
                           v
+------------+    +--------+--------+    +-------------+
| RESERVASI  +--->+    KEUANGAN     +--->+   JURNAL    |
| booking    |    | invoice/payment |    | accounting  |
+------+-----+    +--------+--------+    +------+------+ 
       |                     |                    |
       |                     v                    v
       |              +------+-------+      +-----+------+
       |              |   LAPORAN    |      |    COA     |
       |              | PL/BS/CF/Rec |      | akun-akun  |
       |              +--------------+      +------------+
       |
       +------------------+
       |                  |
       v                  v
+------+-----+     +------+------+
|   KAMAR    |     | PERSEDIAAN  |
| room/HK    |     | stock flow  |
+------+-----+     +------+------+
       |                  |
       v                  v
+------+-----------+   +--+---------------+
| HOUSEKEEPING TASK|   | INVENTORY MOVE   |
| turnaround queue |   | purchase/issue   |
+------------------+   +------------------+
```

## 2. ERD Ringan

```text
GUESTS
  |
  | 1..n
  v
BOOKINGS --------------------------+
  |                                |
  | 1..n                           | 1..n
  v                                v
BOOKING_ROOMS                   BOOKING_ADDONS
  |                                |
  | n..1                           |
  v                                |
ROOMS                              |
  |                                |
  | 1..n                           |
  v                                |
HOUSEKEEPING_TASKS                 |
                                   |
                                   v
                                INVOICES
                                   |
                                   | 1..n
                                   v
                           PAYMENT_ALLOCATIONS
                                   ^
                                   |
                                PAYMENTS
                                   |
                                   v
                                JOURNALS
                                   |
                                   v
                              JOURNAL_LINES
```

```text
INVENTORY_ITEMS
   |
   | 1..n
   v
INVENTORY_MOVEMENTS
   |
   v
JOURNALS
   |
   v
JOURNAL_LINES
```

## 3. Flowchart Booking Sampai Laporan

```text
[Mulai]
   |
   v
[Buat Booking]
   |
   v
[Simpan Guest + Booking + Booking Rooms]
   |
   v
[Hitung Grand Total]
   |
   v
[Buat / Update Invoice]
   |
   v
[Posting Jurnal Invoice]
   |
   v
[Check-in]
   |
   v
[Room Status = Occupied]
   |
   v
[Tambah Add-on bila perlu]
   |
   v
[Posting Payment]
   |
   +----------------------+
   |                      |
   v                      v
[Refund?]               [Void?]
   |                      |
   +----------+-----------+
              |
              v
     [Update Invoice Balance]
              |
              v
         [Check-out]
              |
              v
       [Room Status = Dirty]
              |
              v
   [Masuk Housekeeping Queue]
              |
              v
        [Task HK Selesai]
              |
              v
     [Room Status = Available]
              |
              v
        [Laporan Terbentuk]
              |
              v
            [Selesai]
```

## 4. Flowchart Cancel Booking

```text
[Booking Aktif]
   |
   v
[User pilih Cancel]
   |
   v
[Cek aturan penalti owner]
   |
   +-------------------------------+
   |                               |
   v                               v
[Tidak ada penalti]          [Ada penalti]
   |                               |
   v                               v
[Grand total = 0]            [Grand total = nilai penalti]
   |                               |
   v                               v
[Invoice dibersihkan]        [Invoice tetap hidup]
   |                               |
   v                               v
[Jurnal invoice dibersihkan] [Jurnal penalti diposting]
   |                               |
   +---------------+---------------+
                   |
                   v
         [Booking status = Cancelled]
```

## 5. Flowchart Payment, Refund, Void

```text
                +------------------+
                |  PAYMENT POSTED  |
                +---------+--------+
                          |
                          v
                 [Invoice Paid Amount Naik]
                          |
                          v
                  [Jurnal Kas vs Piutang]
                          |
         +----------------+----------------+
         |                                 |
         v                                 v
      [REFUND]                           [VOID]
         |                                 |
         v                                 v
 [Payment type = refund]          [Payment type = void]
         |                                 |
         v                                 v
 [Allocation negatif]             [Allocation negatif]
         |                                 |
         v                                 v
 [Paid amount turun]              [Paid amount turun]
         |                                 |
         v                                 v
 [Balance due naik]               [Balance due naik]
         |                                 |
         v                                 v
 [Dr Piutang / Cr Kas]            [Dr Piutang / Cr Kas]
```

## 6. Flowchart Housekeeping Nyata

```text
[Room status berubah]
   |
   v
[Sistem cek status kamar]
   |
   +------------------------------------------------+
   |                                                |
   v                                                v
[Dirty / Cleaning / Blocked / Maintenance]       [Available / Occupied]
   |                                                |
   v                                                v
[Buat / update housekeeping task]             [Task aktif ditutup]
   |
   v
[Task status = Pending / In Progress]
   |
   +------------------------+
   |                        |
   v                        v
[Start task]             [Done task]
   |                        |
   v                        v
[Room = Cleaning]       [Room = Available]
```

## 7. Flowchart Persediaan

```text
[Purchase Inventory]
   |
   v
[Inventory Movements: purchase]
   |
   v
[Stok naik]
   |
   v
[Dr Persediaan / Cr Kas]


[Issue Item ke Kamar]
   |
   v
[Inventory Movements: issue_room]
   |
   v
[Stok turun]
   |
   v
[Dr Beban / Cr Persediaan]


[Return dari Kamar]
   |
   v
[Inventory Movements: return]
   |
   v
[Stok naik lagi]
   |
   v
[Dr Persediaan / Cr Beban]
```

## 8. Dampak Transaksi ke Modul

```text
BOOKING BARU
  -> Reservasi
  -> Invoice
  -> Jurnal
  -> Laporan

PAYMENT
  -> Keuangan
  -> Invoice
  -> Jurnal
  -> Arus Kas
  -> Rekonsiliasi

REFUND / VOID
  -> Keuangan
  -> Invoice
  -> Jurnal
  -> Arus Kas
  -> Rekonsiliasi

CHECK-OUT
  -> Kamar
  -> Housekeeping Queue

PURCHASE INVENTORY
  -> Persediaan
  -> Jurnal
  -> Neraca
  -> Arus Kas

ISSUE INVENTORY
  -> Persediaan
  -> Jurnal
  -> Laba Rugi
```

## 9. Cara Membaca Sistem Ini Dengan Cepat

Kalau ingin memahami sistem dari yang paling penting:

1. `Reservasi`
2. `Invoice`
3. `Payment / Refund / Void`
4. `Jurnal`
5. `Laporan`
6. `Kamar`
7. `Housekeeping`
8. `Persediaan`
