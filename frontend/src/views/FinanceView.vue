<script setup>
import { computed, reactive, ref, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { useHotelStore } from '../stores/hotel'
import api from '../services/api'

const hotel = useHotelStore()

const invoiceSearch = ref('')
const selectedInvoiceCode = ref(hotel.invoiceList[0]?.bookingCode ?? '')
const showInvoiceModal = ref(false)
const showPaymentModal = ref(false)
const paymentResult = ref({ tone: '', text: '' })
const paymentMethods = ['Tunai', 'Transfer Bank', 'Kartu Kredit', 'Kartu Debit', 'QRIS']

const paymentForm = reactive({
  paymentDate: new Date().toISOString().slice(0, 10),
  method: paymentMethods[0],
  amountValue: '',
  referenceNo: '',
  note: '',
})

const toCurrency = (amount) => {
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
  }).format(amount || 0)
}

const profitLossReq = ref({ revenue: {}, expense: {}, netProfit: 0 })
const cashFlowReq = ref({ inflow: {}, outflow: {}, netCashFlow: 0 })

const isAmountFocused = ref(false)

const displayAmount = computed({
  get: () => {
    if (isAmountFocused.value) {
      return paymentForm.amountValue
    }
    if (!paymentForm.amountValue && paymentForm.amountValue !== 0) return ''
    return new Intl.NumberFormat('id-ID', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(Number(paymentForm.amountValue))
  },
  set: (newValue) => {
    const cleanValue = String(newValue).replace(/[^0-9]/g, '')
    paymentForm.amountValue = cleanValue ? Number(cleanValue) : ''
  }
})

const filteredInvoices = computed(() => {
  const query = invoiceSearch.value.trim().toLowerCase()

  return hotel.invoiceList.filter((item) => {
    const haystack = [item.invoiceNo, item.bookingCode, item.guest, item.room, item.channel]
      .join(' ')
      .toLowerCase()

    return !query || haystack.includes(query)
  })
})

const selectedInvoice = computed(() =>
  hotel.invoiceList.find((item) => item.bookingCode === selectedInvoiceCode.value) ?? null,
)

const recentPayments = computed(() => hotel.paymentTransactions.slice(0, 8))
const recentJournals = computed(() => hotel.generalJournalList.slice(0, 8))

const openInvoiceModal = (bookingCode) => {
  selectedInvoiceCode.value = bookingCode
  showInvoiceModal.value = true
}

const closeInvoiceModal = () => {
  showInvoiceModal.value = false
}

const openPaymentModal = (bookingCode) => {
  selectedInvoiceCode.value = bookingCode
  paymentResult.value = { tone: '', text: '' }
  paymentForm.paymentDate = new Date().toISOString().slice(0, 10)
  paymentForm.method = paymentMethods[0]
  paymentForm.amountValue = selectedInvoice.value?.balanceValue ? String(selectedInvoice.value.balanceValue) : ''
  paymentForm.referenceNo = ''
  paymentForm.note = ''
  showPaymentModal.value = true
}

const closePaymentModal = () => {
  showPaymentModal.value = false
}

const submitPayment = async () => {
  paymentResult.value = { tone: '', text: '' }

  if (!selectedInvoice.value) {
    paymentResult.value = { tone: 'error', text: 'Invoice belum dipilih.' }
    return
  }

  try {
    const payment = await hotel.recordPayment({
      bookingCode: selectedInvoice.value.bookingCode,
      paymentDate: paymentForm.paymentDate,
      method: paymentForm.method,
      amountValue: paymentForm.amountValue,
      referenceNo: paymentForm.referenceNo,
      note: paymentForm.note,
    })

    await Promise.all([loadBookings(), loadPayments(), loadReports()])

    paymentResult.value = {
      tone: 'success',
      text: `Pembayaran ${payment.amount} berhasil diposting ke ${payment.invoiceNo}.`,
    }
    showPaymentModal.value = false
  } catch (error) {
    paymentResult.value = {
      tone: 'error',
      text: error?.response?.data?.message || (error instanceof Error ? error.message : 'Gagal memposting pembayaran.'),
    }
  }
}

const loadPayments = async () => {
  try {
    const response = await api.get('/payments')
    hotel.setPaymentTransactions(Array.isArray(response.data?.data) ? response.data.data : [])
  } catch (e) {
    console.error('Gagal memuat pembayaran:', e)
  }
}

const syncBookingCollections = (rows) => {
  hotel.setBookings(rows)

  const allAddons = []
  rows.forEach((booking) => {
    if (Array.isArray(booking.addons)) {
      allAddons.push(...booking.addons)
    }
  })

  hotel.setBookingAddons(allAddons)
}

const loadBookings = async () => {
  try {
    const response = await api.get('/bookings', { params: { per_page: 500 }})
    const realBookings = Array.isArray(response.data?.data) ? response.data.data : []

    syncBookingCollections(realBookings)
  } catch (e) {
    console.error('Gagal memuat reservasi dari database:', e)
  }
}

const loadReports = async () => {
  try {
    const plRes = await api.get('/reports/profit-loss')
    const profitLossData = plRes.data?.data || {}
    profitLossReq.value = {
      revenue: {
        room: Number(profitLossData.revenue?.room ?? 0),
        addon: Number(profitLossData.revenue?.addon ?? 0),
        total: Number(profitLossData.revenue?.total ?? profitLossData.total_revenue ?? 0),
      },
      expense: {
        total: Number(profitLossData.expense?.total ?? profitLossData.total_expense ?? 0),
      },
      netProfit: Number(profitLossData.netProfit ?? profitLossData.net_profit ?? 0),
    }

    const cfRes = await api.get('/reports/cash-flow')
    const cashFlowData = cfRes.data?.data || {}
    cashFlowReq.value = {
      inflow: {
        guest_payments: Number(cashFlowData.inflow?.guest_payments ?? 0),
        manual_journals: Number(cashFlowData.inflow?.manual_journals ?? 0),
        total: Number(cashFlowData.inflow?.total ?? cashFlowData.total_inflow ?? 0),
      },
      outflow: {
        expenses: Number(cashFlowData.outflow?.expenses ?? cashFlowData.total_outflow ?? 0),
        total: Number(cashFlowData.outflow?.total ?? cashFlowData.total_outflow ?? 0),
      },
      netCashFlow: Number(cashFlowData.netCashFlow ?? cashFlowData.net_cash_flow ?? 0),
    }
  } catch (e) {
    console.error('Gagal memuat laporan accounting:', e)
  }
}

onMounted(async () => {
  await Promise.all([loadBookings(), loadPayments(), loadReports()])
})

const printInvoice = () => {
  if (typeof window !== 'undefined') {
    window.print()
  }
}
</script>

<template>
  <section class="page-grid two">
    <article class="panel-card panel-dense">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Ringkasan keuangan</p>
          <h3>Ringkasan invoice dan pembayaran</h3>
        </div>
        <span class="status-badge warning">{{ hotel.financeOpenFolios.length }} saldo terbuka</span>
      </div>

      <table class="data-table">
        <thead>
          <tr>
            <th>Jenis posting</th>
            <th>Volume</th>
            <th>Nominal</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in hotel.financePaymentSummary" :key="item.type">
            <td><strong>{{ item.type }}</strong></td>
            <td>{{ item.count }}</td>
            <td>{{ item.amount }}</td>
          </tr>
        </tbody>
      </table>
    </article>

    <article class="panel-card panel-dense">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Folio terbuka</p>
          <h3>Antrian saldo outstanding</h3>
        </div>
        <span class="status-badge info">Tersambung dari booking</span>
      </div>

      <table class="data-table">
        <thead>
          <tr>
            <th>Tamu</th>
            <th>Saldo</th>
            <th>Referensi</th>
            <th>Jatuh tempo</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in hotel.financeOpenFolios" :key="item.invoiceNo">
            <td><strong>{{ item.guest }}</strong></td>
            <td>{{ item.balance }}</td>
            <td>{{ item.invoiceNo }}</td>
            <td>{{ item.due }}</td>
          </tr>
        </tbody>
      </table>
    </article>
  </section>

  <section class="page-grid">
    <article class="panel-card panel-dense">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Daftar invoice</p>
          <h3>Invoice tamu</h3>
        </div>
        <div class="kpi-inline">
          <span>{{ filteredInvoices.length }} invoice terlihat</span>
          <span>{{ hotel.invoiceList.length }} total invoice</span>
        </div>
      </div>

      <div class="table-toolbar">
        <div class="toolbar-tabs">
          <button class="toolbar-tab active">Invoice</button>
          <button class="toolbar-tab">Pembayaran</button>
          <button class="toolbar-tab">Jurnal Umum</button>
        </div>
        <div class="topbar-actions">
          <input
            v-model="invoiceSearch"
            class="toolbar-search"
            placeholder="Cari invoice / booking / tamu"
          />
          <RouterLink class="action-button primary" :to="{ name: 'journals' }">Jurnal umum</RouterLink>
        </div>
      </div>

      <div v-if="paymentResult.text" class="booking-feedback" :class="paymentResult.tone">
        {{ paymentResult.text }}
      </div>

      <table class="data-table">
        <thead>
          <tr>
            <th>Invoice</th>
            <th>Tamu</th>
            <th>Menginap</th>
            <th>Total</th>
            <th>Dibayar</th>
            <th>Saldo</th>
            <th>Status</th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in filteredInvoices" :key="item.invoiceNo">
            <td><strong>{{ item.invoiceNo }}</strong></td>
            <td>{{ item.guest }}</td>
            <td>{{ item.checkIn }} to {{ item.checkOut }}</td>
            <td>{{ item.subtotal }}</td>
            <td>{{ item.paid }}</td>
            <td>{{ item.balance }}</td>
            <td>{{ item.paymentStatus }}</td>
            <td>
              <div class="modal-actions">
                <button class="action-button" @click="openInvoiceModal(item.bookingCode)">Pratinjau</button>
                <button class="action-button primary" @click="openPaymentModal(item.bookingCode)">Pembayaran</button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </article>
  </section>

  <section class="page-grid two">
    <article class="panel-card panel-dense">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Pembayaran terbaru</p>
          <h3>Posting pembayaran</h3>
        </div>
        <span class="status-badge success">{{ hotel.paymentTransactions.length }} terposting</span>
      </div>

      <table class="data-table">
        <thead>
          <tr>
            <th>Tanggal</th>
            <th>Invoice</th>
            <th>Metode</th>
            <th>Nominal</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in recentPayments" :key="item.id">
            <td>{{ item.paymentDate }}</td>
            <td><strong>{{ item.invoiceNo }}</strong></td>
            <td>{{ item.method }}</td>
            <td>{{ item.amount }}</td>
          </tr>
        </tbody>
      </table>
    </article>

    <article class="panel-card panel-dense">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Jurnal umum</p>
          <h3>Jurnal manual terbaru</h3>
        </div>
        <span class="status-badge info">{{ hotel.generalJournalList.length }} terposting</span>
      </div>

      <table class="data-table">
        <thead>
          <tr>
            <th>Tanggal</th>
            <th>No jurnal</th>
            <th>Referensi</th>
            <th>Keterangan</th>
            <th>Debit</th>
            <th>Kredit</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in recentJournals" :key="item.id">
            <td>{{ item.journalDate }}</td>
            <td><strong>{{ item.journalNo }}</strong></td>
            <td>{{ item.referenceNo }}</td>
            <td>{{ item.description }}</td>
            <td>{{ item.debitTotal }}</td>
            <td>{{ item.creditTotal }}</td>
          </tr>
        </tbody>
      </table>
    </article>

    <article class="panel-card panel-dense">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Laporan Akuntansi</p>
          <h3>Laba Rugi (Profit & Loss)</h3>
        </div>
        <span class="status-badge success">Real-time</span>
      </div>

      <div class="compact-list">
        <div class="list-row list-row-tight">
          <strong>Pendapatan Kamar</strong>
          <span>{{ toCurrency(profitLossReq.revenue.room) }}</span>
        </div>
        <div class="list-row list-row-tight">
          <strong>Pendapatan Layanan (Add-ons)</strong>
          <span>{{ toCurrency(profitLossReq.revenue.addon) }}</span>
        </div>
        <div class="list-row list-row-tight">
          <strong>Total Pendapatan</strong>
          <span style="font-weight: bold;">{{ toCurrency(profitLossReq.revenue.total) }}</span>
        </div>
        <div class="list-row list-row-tight">
          <strong>Pengeluaran Operasional (Jurnal)</strong>
          <span style="color: darkred;">- {{ toCurrency(profitLossReq.expense.total) }}</span>
        </div>
        <div class="list-row list-row-tight" style="border-top: 1px solid var(--border); padding-top: 16px; margin-top: 8px;">
          <strong style="color: var(--primary);">Laba Bersih (Net Profit)</strong>
          <strong style="color: var(--primary); font-size: 1.1rem;">{{ toCurrency(profitLossReq.netProfit) }}</strong>
        </div>
      </div>
    </article>

    <article class="panel-card panel-dense">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Laporan Kas</p>
          <h3>Arus Kas (Cash Flow)</h3>
        </div>
        <span class="status-badge info">Real-time</span>
      </div>

      <div class="compact-list">
        <div class="list-row list-row-tight">
          <div>
            <strong>Kas Masuk (Inflow)</strong>
            <p class="subtle">Pembayaran tagihan tamu dsb</p>
          </div>
          <span style="font-weight: bold;">{{ toCurrency(cashFlowReq.inflow.guest_payments) }}</span>
        </div>
        <div class="list-row list-row-tight">
          <div>
            <strong>Kas Keluar (Outflow)</strong>
            <p class="subtle">Posting pengeluaran jurnal</p>
          </div>
          <span style="font-weight: bold; color: darkred;">- {{ toCurrency(cashFlowReq.outflow.expenses) }}</span>
        </div>
        <div class="list-row list-row-tight" style="border-top: 1px solid var(--border); padding-top: 16px; margin-top: 8px;">
          <strong style="color: var(--primary);">Kas Bersih Berjalan</strong>
          <strong style="color: var(--primary); font-size: 1.1rem;">{{ toCurrency(cashFlowReq.netCashFlow) }}</strong>
        </div>
      </div>
    </article>
  
  </section>

  <div v-if="showInvoiceModal && selectedInvoice" class="modal-backdrop" @click.self="closeInvoiceModal()">
    <section class="modal-card">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Pratinjau invoice</p>
          <h3>{{ selectedInvoice.invoiceNo }} | {{ selectedInvoice.guest }}</h3>
        </div>
        <button class="action-button" @click="closeInvoiceModal()">Tutup</button>
      </div>

      <div class="booking-inline-summary">
        <div class="note-cell">
          <strong>Booking</strong>
          <p class="subtle">{{ selectedInvoice.bookingCode }} | {{ selectedInvoice.room }}</p>
        </div>
        <div class="note-cell">
          <strong>Tanggal menginap</strong>
          <p class="subtle">{{ selectedInvoice.checkIn }} to {{ selectedInvoice.checkOut }}</p>
        </div>
        <div class="note-cell">
          <strong>Status pembayaran</strong>
          <p class="subtle">{{ selectedInvoice.paymentStatus }}</p>
        </div>
      </div>

      <table class="data-table">
        <thead>
          <tr>
            <th>Item</th>
            <th>Deskripsi</th>
            <th>Nominal</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in selectedInvoice.items" :key="item.id">
            <td><strong>{{ item.label }}</strong></td>
            <td>{{ item.description }}</td>
            <td>{{ item.amount }}</td>
          </tr>
        </tbody>
      </table>

      <div class="booking-inline-summary">
        <div class="note-cell">
          <strong>Subtotal</strong>
          <p class="subtle">{{ selectedInvoice.subtotal }}</p>
        </div>
        <div class="note-cell">
          <strong>Dibayar</strong>
          <p class="subtle">{{ selectedInvoice.paid }}</p>
        </div>
        <div class="note-cell">
          <strong>Saldo</strong>
          <p class="subtle">{{ selectedInvoice.balance }}</p>
        </div>
      </div>

      <div class="compact-list">
        <div v-for="payment in selectedInvoice.payments" :key="payment.id" class="list-row list-row-tight">
          <strong>{{ payment.paymentDate }} | {{ payment.method }}</strong>
          <p class="subtle">{{ payment.amount }} | {{ payment.referenceNo }}</p>
        </div>
        <p v-if="!selectedInvoice.payments.length" class="subtle booking-addon-empty">
          Belum ada pembayaran yang diposting untuk invoice ini.
        </p>
      </div>

      <div class="modal-actions">
        <button class="action-button" @click="printInvoice">Cetak invoice</button>
        <button class="action-button primary" @click="closeInvoiceModal(); openPaymentModal(selectedInvoice.bookingCode)">
          Catat pembayaran
        </button>
      </div>
    </section>
  </div>

  <div v-if="showPaymentModal && selectedInvoice" class="modal-backdrop" @click.self="closePaymentModal()">
    <section class="modal-card">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Input pembayaran</p>
          <h3>{{ selectedInvoice.invoiceNo }} | {{ selectedInvoice.guest }}</h3>
        </div>
        <button class="action-button" @click="closePaymentModal()">Tutup</button>
      </div>

      <div class="booking-inline-summary">
        <div class="note-cell">
          <strong>Total invoice</strong>
          <p class="subtle">{{ selectedInvoice.subtotal }}</p>
        </div>
        <div class="note-cell">
          <strong>Dibayar</strong>
          <p class="subtle">{{ selectedInvoice.paid }}</p>
        </div>
        <div class="note-cell">
          <strong>Outstanding</strong>
          <p class="subtle">{{ selectedInvoice.balance }}</p>
        </div>
      </div>

      <div class="booking-form-grid">
        <label class="field-stack">
          <span>Tanggal pembayaran</span>
          <input v-model="paymentForm.paymentDate" class="form-control" type="date" />
        </label>

        <label class="field-stack">
          <span>Metode</span>
          <select v-model="paymentForm.method" class="form-control">
            <option v-for="item in paymentMethods" :key="item" :value="item">{{ item }}</option>
          </select>
        </label>

        <label class="field-stack">
          <span>Nominal</span>
          <input 
            v-model="displayAmount" 
            class="form-control" 
            type="text" 
            @focus="isAmountFocused = true"
            @blur="isAmountFocused = false"
          />
        </label>

        <label class="field-stack">
          <span>No referensi</span>
          <input v-model="paymentForm.referenceNo" class="form-control" placeholder="Nomor TRX / kuitansi" />
        </label>

        <label class="field-stack field-span-2">
          <span>Catatan</span>
          <textarea
            v-model="paymentForm.note"
            class="form-control form-textarea"
            placeholder="Contoh: deposit, settlement at check-out, partial payment"
          ></textarea>
        </label>
      </div>

      <div v-if="paymentResult.text" class="booking-feedback" :class="paymentResult.tone">
        {{ paymentResult.text }}
      </div>

      <div class="modal-actions">
        <button class="action-button" @click="closePaymentModal()">Batal</button>
        <button class="action-button primary" @click="submitPayment">Posting pembayaran</button>
      </div>
    </section>
  </div>

</template>
