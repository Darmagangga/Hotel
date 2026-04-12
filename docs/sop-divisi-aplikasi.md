# SOP Per Divisi Aplikasi Udara Hideaway Villa

Dokumen ini menjelaskan alur kerja harian per divisi agar aplikasi lebih mudah dipahami secara operasional.

## 1. Front Office (FO)

### Tugas utama
- menerima booking
- membuat reservasi
- check-in tamu
- update add-on
- check-out tamu
- cancel atau no-show

### SOP harian FO

#### 1. Booking baru

1. Buka menu `Reservasi`
2. Klik `Booking baru`
3. Isi data tamu
4. Pilih tanggal check-in dan check-out
5. Pilih kamar
6. Pilih channel booking
7. Simpan reservasi

Hasil:
- booking tercatat
- invoice dasar otomatis dibuat
- jurnal invoice otomatis dibuat

#### 2. Sebelum tamu datang

1. Buka daftar booking
2. Cek status `Tentative` atau `Confirmed`
3. Pastikan kamar sudah tepat
4. Tambahkan add-on bila sudah dipesan
5. Cek grand total dan saldo

#### 3. Check-in

1. Pilih booking
2. Klik `Check-in`
3. Pastikan status berubah menjadi `Checked-in`

Hasil:
- booking aktif
- kamar menjadi `occupied`

#### 4. Selama tamu menginap

1. Tambahkan add-on bila ada permintaan baru
2. Pantau invoice dan outstanding
3. Koordinasikan issue item kamar bila diperlukan

#### 5. Check-out

1. Pastikan tagihan sudah lunas
2. Klik `Check-out`
3. Pastikan kamar berubah menjadi `dirty`

Hasil:
- tamu selesai menginap
- kamar masuk alur housekeeping

#### 6. Cancel booking

1. Pilih booking status `Tentative` atau `Confirmed`
2. Klik `Cancel`
3. Baca penalti cancel pada modal konfirmasi
4. Konfirmasi pembatalan

Hasil:
- booking menjadi `Cancelled`
- penalti owner diterapkan bila aktif
- invoice dan jurnal menyesuaikan

#### 7. No-show

1. Pilih booking yang tamunya tidak datang
2. Klik `No-show`
3. Konfirmasi

Hasil:
- booking ditandai `No-Show`

## 2. Kasir / Finance

### Tugas utama
- posting payment
- refund payment
- void payment
- cek invoice
- cek jurnal
- cek laporan keuangan dasar

### SOP harian kasir

#### 1. Posting payment

1. Buka menu `Keuangan`
2. Pilih invoice
3. Klik `Pembayaran`
4. Isi tanggal, metode, nominal, referensi
5. Posting payment

Hasil:
- payment masuk
- saldo invoice turun
- jurnal kas vs piutang otomatis dibuat

#### 2. Refund

1. Buka `Keuangan`
2. Cari payment yang ingin direfund
3. Klik `Refund`
4. Isi alasan refund
5. Konfirmasi

Hasil:
- payment reversal bertipe `refund`
- paid amount invoice turun
- saldo invoice naik lagi
- jurnal pembalik otomatis dibuat

#### 3. Void payment

1. Buka `Keuangan`
2. Cari payment yang salah input
3. Klik `Void`
4. Isi alasan void
5. Konfirmasi

Hasil:
- payment reversal bertipe `void`
- settlement dibalik
- jurnal pembalik otomatis dibuat

#### 4. Cek invoice dan outstanding

1. Buka daftar invoice
2. Lihat status `Unpaid`, `Partial`, `Paid`
3. Prioritaskan invoice dengan saldo terbuka

#### 5. Cek jurnal

1. Buka menu `Jurnal`
2. Pastikan jurnal invoice, payment, refund, void sudah muncul
3. Pastikan debit dan kredit seimbang

#### 6. Cek laporan

1. Buka menu `Laporan`
2. Cek:
   - laba rugi
   - neraca
   - arus kas
   - rekonsiliasi

## 3. Housekeeping (HK)

### Tugas utama
- melihat kamar yang perlu dibersihkan
- memulai task housekeeping
- menyelesaikan task
- menyerahkan kamar kembali ke status siap jual

### SOP harian HK

#### 1. Cek turnaround queue

1. Buka menu `Kamar`
2. Lihat panel `Housekeeping > Turnaround queue`
3. Perhatikan:
   - room
   - task
   - owner
   - status

#### 2. Mulai pekerjaan

1. Pilih task yang masih `Pending`
2. Klik `Start`

Hasil:
- task menjadi `In Progress`
- room biasanya berubah menjadi `cleaning`

#### 3. Selesaikan pekerjaan

1. Setelah cleaning selesai, klik `Done`

Hasil:
- task menjadi `Done`
- room berubah menjadi `available`

#### 4. Follow-up maintenance

Kalau queue menunjukkan `Engineering follow-up`:
- HK dapat melihat task
- ownership diarahkan ke `Engineering`

## 4. Owner / General Manager

### Tugas utama
- memantau bisnis
- mengatur penalti cancel
- memantau outstanding
- membaca laporan keuangan dan audit

### SOP harian owner

#### 1. Buka dashboard

Periksa:
- occupancy
- arrivals
- outstanding
- ADR
- revenue mix

#### 2. Atur penalti cancel

1. Buka dashboard owner
2. Cari panel aturan cancel
3. Isi persentase penalti
4. Simpan

Hasil:
- semua pembatalan booking akan mengikuti aturan ini

#### 3. Pantau outstanding

1. Lihat open folio
2. Minta FO / kasir follow-up booking yang belum lunas

#### 4. Pantau laporan

Owner perlu rutin cek:
- `Laba rugi`
- `Neraca`
- `Arus kas`
- `Rekonsiliasi`
- `Audit trail`

#### 5. Night audit

1. Jalankan `Night audit`
2. Pastikan tidak ada tamu overstay yang belum selesai
3. Biarkan sistem memproses booking yang harus ditutup harian

## 5. SOP Lintas Divisi

### Kasus A: Tamu check-out

- FO check-out tamu
- sistem ubah room jadi `dirty`
- HK lihat task baru di turnaround queue
- HK klik `Start`
- HK klik `Done`
- room kembali `available`

### Kasus B: Booking batal sebelum datang

- FO klik `Cancel`
- sistem hitung penalti owner
- invoice menyesuaikan
- kasir follow-up jika masih ada penalti yang harus dibayar

### Kasus C: Salah input payment

- kasir buka `Keuangan`
- pilih payment yang salah
- klik `Void`
- sistem membalik jurnal dan membuka balance invoice lagi

### Kasus D: Ada kelebihan bayar

- kasir pilih payment terkait
- klik `Refund`
- sistem membuat transaksi refund
- kas keluar tercatat
- invoice balance ikut menyesuaikan

### Kasus E: Persediaan dipakai ke kamar

- staff issue item ke kamar
- stok turun
- untuk consumable, beban naik
- owner melihat dampaknya di laporan

## 6. Ringkasan Tanggung Jawab

- `FO`
  booking, add-on, cancel, no-show, check-in, check-out
- `Kasir`
  payment, refund, void, invoice, jurnal, laporan dasar
- `HK`
  turnaround queue, start task, done task
- `Owner`
  dashboard, penalti cancel, laporan, audit, monitoring bisnis

## 7. Cara Belajar Aplikasi Ini Per Peran

Kalau ingin mengenal aplikasi tanpa bingung:

### Jalur FO

1. booking baru
2. edit booking
3. add-on
4. cancel / no-show
5. check-in
6. check-out

### Jalur Kasir

1. lihat invoice
2. posting payment
3. refund
4. void
5. cek jurnal

### Jalur HK

1. buka kamar
2. lihat turnaround queue
3. start task
4. done task

### Jalur Owner

1. buka dashboard
2. atur penalti cancel
3. cek outstanding
4. cek laporan
5. cek rekonsiliasi
