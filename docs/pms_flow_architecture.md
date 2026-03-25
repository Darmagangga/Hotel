# End-to-End Hotel PMS Booking Flow

Dokumen ini menjelaskan alur siklus tamu (Guest Cycle) dan manajemen tagihan (Folio Management) yang diimplementasikan pada HOTEL-BOOK (terinspirasi dari standar industri seperti eZee Absolute).

## 1. Tahap Reservasi (Booking & Pre-arrival)
* **Penciptaan Booking**: Data pesanan tamu dibuat (via OTA/Website atau manual/walk-in).
* **Status**: 
  * `Tentative` / `Pending Payment`: Jika belum ada DP atau garansi kartu kredit.
  * `Confirmed` / `Guaranteed`: Jika DP sudah dibayar atau garansi OTA sudah diverifikasi.
* **Pembayaran (DP/Advance Deposit)**: Transaksi DP dimasukkan sebagai kredit awal di Folio tamu, namun belum diakui sebagai *revenue* kamar.

## 2. Modifikasi Sebelum Kedatangan (Change / Cancel / No-Show)
* **Edit**: Perubahan tipe kamar, tanggal menginap, atau identitas tamu.
* **Pembatalan (Cancel)**: Jika dibatalkan, status berubah menjadi `Cancelled`. Jika ada DP, sebagian/seluruh DP ditahan sebagai *cancellation fee*, sisanya di-*refund*.
* **No-Show**: Jika tamu tidak hadir pada hari Check-In, status menjadi `No-Show`. DP (minimal 1 malam) ditarik sebagai penalti. Kamar dilepas agar bisa disewakan kembali.

## 3. Kedatangan (Check-In)
* **Verifikasi**: Pendataan KTP/Paspor, registrasi fisik.
* **Alokasi Kamar**: Penetapan ke nomor kamar aktual (contoh: 104). Check-in ditolak jika status kamar kotor (VD).
* **Security Deposit**: Penarikan uang jaminan (insidental) untuk jaga-jaga (kerusakan/minibar).
* **Status Berubah**: Dari `Confirmed` ke `Checked-In` (In-House).

## 4. Selama Menginap (In-House & Folio Postings)
* **Guest Folio (Keranjang Tagihan)**: Keranjang tagihan terus aktif selama tamu menginap.
  * **Master Folio**: Beban Room Rate + Pajak kamar.
  * **Extra Folio**: Beban tambahan personal (misal dibayar beda metode).
* **Postings (Add-ons)**: Pembelian ekstra (Sewa Motor, Tiket Kapal, Minibar, Spa). Tagihan ini dikirim langsung (Charge to Room) ke folio tamu.
* **Night Audit**: Sistem otomatis menambahkan *Room Charge* ke dalam tagihan tamu setiap tengah malam agar *revenue* diakui per malam.

## 5. Kepulangan & Pelunasan (Check-out & Settlement)
* **Settlement**: Penyatuan seluruh tagihan (Room + Addons) dikurangi DP dan Uang Jaminan. Tagihan akhir (Balance) wajib `Rp 0` sebelum Check-out diperbolehkan.
* **Pemisahan Tagihan (Split Bill)**: Tamu membayar dengan Kartu Kredit untuk sisa kamar, dan Cash untuk layanan ekstra.
* **Status Berubah**: Dari `Checked-In` ke `Checked-Out`.
* **Kamar Menjadi Kotor**: Status kamar otomatis berubah menjadi Vacant Dirty (VD) untuk ditangani Housekeeping.

## 6. Post-Departure & Piutang (City Ledger / Receivables)
* Jika reservasi bersumber dari OTA (misal Agoda Collect) atau *Corporate*, tagihan tidak ditagihkan ke tamu saat check-out, melainkan dialihkan ke buku piutang perusahaan (City Ledger/Account Receivable) untuk ditagih ke Travel Agent secara berkala.
