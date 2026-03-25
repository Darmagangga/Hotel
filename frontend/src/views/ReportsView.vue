<script setup>
import { onMounted, ref } from 'vue'
import api from '../services/api'
import LoadingState from '../components/LoadingState.vue'

const loading = ref(false)
const activeTab = ref('labarugi')
const reportResult = ref({ tone: '', text: '' })

const profitLoss = ref({ revenues: [], expenses: [], total_revenue: 0, total_expense: 0, net_profit: 0 })
const balanceSheet = ref({ assets: [], liabilities: [], equities: [], total_asset: 0, total_liability_and_equity: 0 })
const cashFlow = ref({ inflows: [], outflows: [], total_inflow: 0, total_outflow: 0, net_cash_flow: 0 })
const reconciliation = ref({
  summary: {
    booking_issue_count: 0,
    payment_issue_count: 0,
    bookings_checked: 0,
    payments_checked: 0,
  },
  bookingRows: [],
  paymentRows: [],
})
const auditTrail = ref({
  rows: [],
  meta: { total: 0, current_page: 1, last_page: 1, per_page: 20 },
})
const auditSearch = ref('')
const auditModule = ref('')
const auditModules = [
  { value: '', label: 'Semua modul' },
  { value: 'auth', label: 'Auth' },
  { value: 'bookings', label: 'Bookings' },
  { value: 'finance', label: 'Finance' },
  { value: 'journals', label: 'Journals' },
  { value: 'users', label: 'Users' },
  { value: 'roles', label: 'Roles' },
]

const normalizeAuditRows = (rows) => {
  if (!Array.isArray(rows)) {
    return []
  }

  return rows
    .filter((item) => item && typeof item === 'object')
    .map((item, index) => ({
      id: item.id ?? `audit-${index}`,
      createdAt: item.createdAt ?? '-',
      module: item.module ?? '-',
      action: item.action ?? '-',
      userName: item.userName ?? 'System',
      userEmail: item.userEmail ?? '-',
      userRole: item.userRole ?? '-',
      entityType: item.entityType ?? '-',
      entityId: item.entityId ?? '-',
      entityLabel: item.entityLabel ?? '-',
      description: item.description ?? '-',
      metadata: item.metadata ?? {},
      ipAddress: item.ipAddress ?? '-',
    }))
}

const toCurrency = (amount) => {
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
  }).format(amount || 0)
}

const escapeCsv = (value) => {
  const normalized = String(value ?? '')
  if (normalized.includes('"') || normalized.includes(',') || normalized.includes('\n')) {
    return `"${normalized.replaceAll('"', '""')}"`
  }
  return normalized
}

const downloadTextFile = (content, filename, type = 'text/csv;charset=utf-8;') => {
  if (typeof window === 'undefined') {
    return
  }

  const blob = new Blob([content], { type })
  const url = window.URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
  window.URL.revokeObjectURL(url)
}

const loadAllReports = async () => {
  loading.value = true
  reportResult.value = { tone: '', text: '' }

  try {
    const [plRes, bsRes, cfRes, reconRes, auditRes] = await Promise.all([
      api.get('/reports/profit-loss'),
      api.get('/reports/balance-sheet'),
      api.get('/reports/cash-flow'),
      api.get('/reports/reconciliation'),
      api.get('/audit-trails'),
    ])

    profitLoss.value = plRes.data?.data || profitLoss.value
    balanceSheet.value = bsRes.data?.data || balanceSheet.value
    cashFlow.value = cfRes.data?.data || cashFlow.value
    reconciliation.value = reconRes.data?.data || reconciliation.value
    auditTrail.value = {
      rows: normalizeAuditRows(auditRes.data?.data),
      meta: auditRes.data?.meta || auditTrail.value.meta,
    }
    
  } catch (error) {
    reportResult.value = {
      tone: 'error',
      text: error?.response?.data?.message || 'Gagal memuat laporan akuntansi dari server.',
    }
  } finally {
    loading.value = false
  }
}

const loadAuditTrail = async () => {
  loading.value = true
  reportResult.value = { tone: '', text: '' }

  try {
    const response = await api.get('/audit-trails', {
      params: {
        search: auditSearch.value,
        module: auditModule.value,
      },
    })

    auditTrail.value = {
      rows: normalizeAuditRows(response.data?.data),
      meta: response.data?.meta || auditTrail.value.meta,
    }
  } catch (error) {
    reportResult.value = {
      tone: 'error',
      text: error?.response?.data?.message || 'Gagal memuat audit trail.',
    }
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  loadAllReports()
})

const runAccountingSync = async () => {
  loading.value = true
  reportResult.value = { tone: '', text: '' }

  try {
    const response = await api.post('/accounting/sync-history')
    reportResult.value = {
      tone: 'success',
      text: response.data?.message || 'Sinkronisasi accounting historis berhasil dijalankan.',
    }
    await loadAllReports()
  } catch (error) {
    reportResult.value = {
      tone: 'error',
      text: error?.response?.data?.message || 'Gagal menjalankan sinkronisasi accounting historis.',
    }
  } finally {
    loading.value = false
  }
}

const exportReconciliationCsv = () => {
  const lines = [
    ['Accounting Reconciliation Summary'],
    ['Bookings checked', reconciliation.value.summary.bookings_checked],
    ['Booking issue count', reconciliation.value.summary.booking_issue_count],
    ['Payments checked', reconciliation.value.summary.payments_checked],
    ['Payment issue count', reconciliation.value.summary.payment_issue_count],
    [],
    ['Booking Reconciliation'],
    ['Booking Code', 'Invoice No', 'Booking Total', 'Invoice Total', 'Invoice Paid', 'Invoice Balance', 'Has Invoice Journal', 'Issues'],
    ...reconciliation.value.bookingRows.map((item) => ([
      item.bookingCode,
      item.invoiceNo || '',
      item.bookingGrandTotal,
      item.invoiceGrandTotal ?? '',
      item.invoicePaidAmount ?? '',
      item.invoiceBalanceDue ?? '',
      item.hasInvoiceJournal ? 'OK' : 'Missing',
      item.issues.join(' | '),
    ])),
    [],
    ['Payment Reconciliation'],
    ['Payment No', 'Booking Code', 'Invoice No', 'Payment Amount', 'Allocated Amount', 'Has Payment Journal', 'Issues'],
    ...reconciliation.value.paymentRows.map((item) => ([
      item.paymentNumber,
      item.bookingCode || '',
      item.invoiceNo || '',
      item.amount,
      item.allocatedAmount,
      item.hasPaymentJournal ? 'OK' : 'Missing',
      item.issues.join(' | '),
    ])),
  ]

  const csvContent = lines
    .map((row) => row.map((cell) => escapeCsv(cell)).join(','))
    .join('\n')

  downloadTextFile(csvContent, 'accounting-reconciliation.csv')
}

const exportAuditTrailCsv = () => {
  const lines = [
    ['Audit Trail'],
    ['Timestamp', 'User', 'Email', 'Role', 'Module', 'Action', 'Entity', 'Description', 'IP'],
    ...auditTrail.value.rows.map((item) => ([
      item.createdAt,
      item.userName,
      item.userEmail,
      item.userRole,
      item.module,
      item.action,
      `${item.entityType} ${item.entityLabel}`,
      item.description,
      item.ipAddress,
    ])),
  ]

  const csvContent = lines
    .map((row) => row.map((cell) => escapeCsv(cell)).join(','))
    .join('\n')

  downloadTextFile(csvContent, 'audit-trail.csv')
}

const printReconciliationReport = () => {
  if (typeof window === 'undefined') {
    return
  }

  const bookingRows = reconciliation.value.bookingRows
    .map((item) => `
      <tr>
        <td>${item.bookingCode}</td>
        <td>${item.invoiceNo || '-'}</td>
        <td>${toCurrency(item.bookingGrandTotal)}</td>
        <td>${item.invoiceGrandTotal == null ? '-' : toCurrency(item.invoiceGrandTotal)}</td>
        <td>${item.hasInvoiceJournal ? 'OK' : 'Missing'}</td>
        <td>${item.issues.length ? item.issues.join(' | ') : 'OK'}</td>
      </tr>
    `)
    .join('')

  const paymentRows = reconciliation.value.paymentRows
    .map((item) => `
      <tr>
        <td>${item.paymentNumber}</td>
        <td>${item.bookingCode || '-'}</td>
        <td>${item.invoiceNo || '-'}</td>
        <td>${toCurrency(item.amount)}</td>
        <td>${item.hasPaymentJournal ? 'OK' : 'Missing'}</td>
        <td>${item.issues.length ? item.issues.join(' | ') : 'OK'}</td>
      </tr>
    `)
    .join('')

  const printWindow = window.open('', '_blank', 'width=1200,height=900')
  if (!printWindow) {
    return
  }

  printWindow.document.write(`
    <html>
      <head>
        <title>Accounting Reconciliation Report</title>
        <style>
          body { font-family: Arial, sans-serif; padding: 24px; color: #1f2937; }
          h1, h2 { margin: 0 0 12px; }
          .summary { margin: 16px 0 24px; }
          .summary div { margin-bottom: 6px; }
          table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
          th, td { border: 1px solid #d1d5db; padding: 8px; text-align: left; vertical-align: top; font-size: 12px; }
          th { background: #f3f4f6; }
        </style>
      </head>
      <body>
        <h1>Accounting Reconciliation Report</h1>
        <div class="summary">
          <div>Bookings checked: ${reconciliation.value.summary.bookings_checked}</div>
          <div>Booking issues: ${reconciliation.value.summary.booking_issue_count}</div>
          <div>Payments checked: ${reconciliation.value.summary.payments_checked}</div>
          <div>Payment issues: ${reconciliation.value.summary.payment_issue_count}</div>
        </div>
        <h2>Booking Reconciliation</h2>
        <table>
          <thead>
            <tr>
              <th>Booking</th>
              <th>Invoice</th>
              <th>Booking Total</th>
              <th>Invoice Total</th>
              <th>Journal</th>
              <th>Issues</th>
            </tr>
          </thead>
          <tbody>${bookingRows}</tbody>
        </table>
        <h2>Payment Reconciliation</h2>
        <table>
          <thead>
            <tr>
              <th>Payment</th>
              <th>Booking</th>
              <th>Invoice</th>
              <th>Amount</th>
              <th>Journal</th>
              <th>Issues</th>
            </tr>
          </thead>
          <tbody>${paymentRows}</tbody>
        </table>
      </body>
    </html>
  `)
  printWindow.document.close()
  printWindow.focus()
  printWindow.print()
}
</script>

<template>
  <section class="page-grid">
    <article class="panel-card panel-dense">
      <LoadingState v-if="loading" label="Membuat laporan akuntansi..." overlay />

      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Laporan Akuntansi sesuai COA</p>
          <h3>Buku Besar (General Ledger Reports)</h3>
        </div>
        <div class="kpi-inline">
          <button class="action-button primary" @click="loadAllReports">Muat Ulang (Refresh)</button>
        </div>
      </div>

      <div class="table-toolbar">
        <div class="toolbar-tabs">
          <button
            v-for="item in [{ id:'labarugi', label:'Laba Rugi'}, { id:'neraca', label:'Neraca' }, { id:'aruskas', label:'Arus Kas' }, { id:'rekonsiliasi', label:'Rekonsiliasi' }, { id:'audittrail', label:'Audit Trail' }]"
            :key="item.id"
            class="toolbar-tab"
            :class="{ active: activeTab === item.id }"
            @click="activeTab = item.id"
          >
            {{ item.label }}
          </button>
        </div>
      </div>

      <div v-if="reportResult.text" class="booking-feedback" :class="reportResult.tone">
        {{ reportResult.text }}
      </div>

      <!-- LABA RUGI -->
      <div v-if="activeTab === 'labarugi'">
        <div class="booking-inline-summary" style="margin-top: 1rem;">
          <div class="note-cell">
            <strong>Pendapatan Bersih</strong>
            <p class="subtle" style="font-size: 1.2rem; color: var(--primary); font-weight: bold;">{{ toCurrency(profitLoss.net_profit) }}</p>
          </div>
          <div class="note-cell">
            <strong>Total Pendapatan</strong>
            <p class="subtle">{{ toCurrency(profitLoss.total_revenue) }}</p>
          </div>
          <div class="note-cell">
            <strong>Total Pengeluaran</strong>
            <p class="subtle" style="color: darkred;">{{ toCurrency(profitLoss.total_expense) }}</p>
          </div>
        </div>

        <h4 style="margin: 1.5rem 0 0.5rem 0; color: var(--text-main);">Pendapatan (Revenue)</h4>
        <table class="data-table">
          <thead>
            <tr>
              <th>Kode COA</th>
              <th>Nama Akun</th>
              <th>Normal Saldo</th>
              <th style="text-align: right;">Nilai Laporan</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!loading && !profitLoss.revenues.length">
              <td colspan="4" class="table-empty-cell">Belum ada data jurnal pendapatan.</td>
            </tr>
            <tr v-for="item in profitLoss.revenues" :key="item.code">
              <td>{{ item.code }}</td>
              <td><strong>{{ item.name }}</strong></td>
              <td>{{ item.normal_balance }}</td>
              <td style="text-align: right;">{{ toCurrency(item.balance) }}</td>
            </tr>
          </tbody>
        </table>

        <h4 style="margin: 1.5rem 0 0.5rem 0; color: var(--text-main);">Pengeluaran (Expense)</h4>
        <table class="data-table">
          <thead>
            <tr>
              <th>Kode COA</th>
              <th>Nama Akun</th>
              <th>Normal Saldo</th>
              <th style="text-align: right;">Nilai Laporan</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!loading && !profitLoss.expenses.length">
              <td colspan="4" class="table-empty-cell">Belum ada data jurnal pengeluaran.</td>
            </tr>
            <tr v-for="item in profitLoss.expenses" :key="item.code">
              <td>{{ item.code }}</td>
              <td><strong>{{ item.name }}</strong></td>
              <td>{{ item.normal_balance }}</td>
              <td style="text-align: right; color: darkred;">{{ toCurrency(item.balance) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- NERACA -->
      <div v-if="activeTab === 'neraca'">
        <div class="booking-inline-summary" style="margin-top: 1rem;">
          <div class="note-cell">
            <strong>Total Aset</strong>
            <p class="subtle" style="font-size: 1.2rem; color: var(--primary); font-weight: bold;">{{ toCurrency(balanceSheet.total_asset) }}</p>
          </div>
          <div class="note-cell">
            <strong>Total Kewajiban & Ekuitas</strong>
            <p class="subtle" style="font-size: 1.2rem; font-weight: bold;">{{ toCurrency(balanceSheet.total_liability_and_equity) }}</p>
          </div>
        </div>

        <h4 style="margin: 1.5rem 0 0.5rem 0; color: var(--text-main);">Aset (Asset)</h4>
        <table class="data-table">
          <thead>
            <tr>
              <th>Kode COA</th>
              <th>Nama Akun</th>
              <th>Normal Saldo</th>
              <th style="text-align: right;">Nilai Laporan</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!loading && !balanceSheet.assets.length">
              <td colspan="4" class="table-empty-cell">Belum ada data jurnal aset.</td>
            </tr>
            <tr v-for="item in balanceSheet.assets" :key="item.code">
              <td>{{ item.code }}</td>
              <td><strong>{{ item.name }}</strong></td>
              <td>{{ item.normal_balance }}</td>
              <td style="text-align: right;">{{ toCurrency(item.balance) }}</td>
            </tr>
          </tbody>
        </table>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
          <div>
            <h4 style="margin: 1.5rem 0 0.5rem 0; color: var(--text-main);">Kewajiban (Liability)</h4>
            <table class="data-table">
              <thead>
                <tr>
                  <th>Akun</th>
                  <th style="text-align: right;">Nilai</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="!loading && !balanceSheet.liabilities.length">
                  <td colspan="2" class="table-empty-cell">Belum ada jurnal hutang.</td>
                </tr>
                <tr v-for="item in balanceSheet.liabilities" :key="item.code">
                  <td><strong>{{ item.name }}</strong> ({{ item.code }})</td>
                  <td style="text-align: right;">{{ toCurrency(item.balance) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          
          <div>
            <h4 style="margin: 1.5rem 0 0.5rem 0; color: var(--text-main);">Ekuitas (Equity)</h4>
            <table class="data-table">
              <thead>
                <tr>
                  <th>Akun</th>
                  <th style="text-align: right;">Nilai</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="!loading && !balanceSheet.equities.length">
                  <td colspan="2" class="table-empty-cell">Belum ada jurnal modal.</td>
                </tr>
                <tr v-for="item in balanceSheet.equities" :key="item.code">
                  <td><strong>{{ item.name }}</strong> ({{ item.code }})</td>
                  <td style="text-align: right;">{{ toCurrency(item.balance) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ARUS KAS -->
      <div v-if="activeTab === 'aruskas'">
        <div class="booking-inline-summary" style="margin-top: 1rem;">
          <div class="note-cell">
            <strong>Kas Masuk Bersih Berjalan</strong>
            <p class="subtle" style="font-size: 1.2rem; color: var(--primary); font-weight: bold;">{{ toCurrency(cashFlow.net_cash_flow) }}</p>
          </div>
          <div class="note-cell">
            <strong>Total Pemasukan (Debit Kas)</strong>
            <p class="subtle">{{ toCurrency(cashFlow.total_inflow) }}</p>
          </div>
          <div class="note-cell">
            <strong>Total Pengeluaran (Kredit Kas)</strong>
            <p class="subtle" style="color: darkred;">{{ toCurrency(cashFlow.total_outflow) }}</p>
          </div>
        </div>

        <h4 style="margin: 1.5rem 0 0.5rem 0; color: var(--text-main);">Histori Kas Masuk (Inflow)</h4>
        <table class="data-table">
          <thead>
            <tr>
              <th>Tanggal</th>
              <th>COA (Asset/Kas)</th>
              <th>Keterangan Referensi</th>
              <th style="text-align: right;">Nominal</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!loading && !cashFlow.inflows.length">
              <td colspan="4" class="table-empty-cell">Belum ada riwayat jurnal kas masuk.</td>
            </tr>
            <tr v-for="(item, index) in cashFlow.inflows" :key="index">
              <td>{{ item.date }}</td>
              <td><strong>{{ item.coa }}</strong></td>
              <td>{{ item.description }}</td>
              <td style="text-align: right;">{{ toCurrency(item.amount) }}</td>
            </tr>
          </tbody>
        </table>

        <h4 style="margin: 1.5rem 0 0.5rem 0; color: var(--text-main);">Histori Kas Keluar (Outflow)</h4>
        <table class="data-table">
          <thead>
            <tr>
              <th>Tanggal</th>
              <th>COA (Asset/Kas)</th>
              <th>Keterangan Referensi</th>
              <th style="text-align: right;">Nominal</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!loading && !cashFlow.outflows.length">
              <td colspan="4" class="table-empty-cell">Belum ada riwayat jurnal pengeluaran kas.</td>
            </tr>
            <tr v-for="(item, index) in cashFlow.outflows" :key="index">
              <td>{{ item.date }}</td>
              <td><strong>{{ item.coa }}</strong></td>
              <td>{{ item.description }}</td>
              <td style="text-align: right; color: darkred;">{{ toCurrency(item.amount) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="activeTab === 'rekonsiliasi'">
        <div class="booking-inline-summary" style="margin-top: 1rem;">
          <div class="note-cell">
            <strong>Booking bermasalah</strong>
            <p class="subtle" style="font-size: 1.2rem; color: var(--primary); font-weight: bold;">{{ reconciliation.summary.booking_issue_count }}</p>
          </div>
          <div class="note-cell">
            <strong>Payment bermasalah</strong>
            <p class="subtle" style="font-size: 1.2rem; color: darkred; font-weight: bold;">{{ reconciliation.summary.payment_issue_count }}</p>
          </div>
          <div class="note-cell">
            <strong>Data diperiksa</strong>
            <p class="subtle">{{ reconciliation.summary.bookings_checked }} booking | {{ reconciliation.summary.payments_checked }} payment</p>
          </div>
        </div>

        <div class="modal-actions" style="margin: 1rem 0;">
          <button class="action-button primary" @click="runAccountingSync">Sync accounting historis</button>
          <button class="action-button" @click="loadAllReports">Refresh audit</button>
          <button class="action-button" @click="exportReconciliationCsv">Export Excel (CSV)</button>
          <button class="action-button" @click="printReconciliationReport">Print audit</button>
        </div>

        <h4 style="margin: 1.5rem 0 0.5rem 0; color: var(--text-main);">Booking Reconciliation</h4>
        <table class="data-table">
          <thead>
            <tr>
              <th>Booking</th>
              <th>Invoice</th>
              <th>Total booking</th>
              <th>Total invoice</th>
              <th>Paid / Balance</th>
              <th>Journal</th>
              <th>Issues</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!loading && !reconciliation.bookingRows.length">
              <td colspan="7" class="table-empty-cell">Belum ada data audit booking.</td>
            </tr>
            <tr v-for="item in reconciliation.bookingRows" :key="item.bookingCode">
              <td><strong>{{ item.bookingCode }}</strong></td>
              <td>{{ item.invoiceNo || '-' }}</td>
              <td>{{ toCurrency(item.bookingGrandTotal) }}</td>
              <td>{{ item.invoiceGrandTotal == null ? '-' : toCurrency(item.invoiceGrandTotal) }}</td>
              <td>
                <div>{{ item.invoicePaidAmount == null ? '-' : toCurrency(item.invoicePaidAmount) }}</div>
                <div class="subtle">{{ item.invoiceBalanceDue == null ? '-' : toCurrency(item.invoiceBalanceDue) }}</div>
              </td>
              <td>{{ item.hasInvoiceJournal ? 'OK' : 'Missing' }}</td>
              <td>
                <span v-if="!item.issues.length">OK</span>
                <span v-else>{{ item.issues.join(' | ') }}</span>
              </td>
            </tr>
          </tbody>
        </table>

        <h4 style="margin: 1.5rem 0 0.5rem 0; color: var(--text-main);">Payment Reconciliation</h4>
        <table class="data-table">
          <thead>
            <tr>
              <th>Payment</th>
              <th>Booking</th>
              <th>Invoice</th>
              <th>Amount</th>
              <th>Allocated</th>
              <th>Journal</th>
              <th>Issues</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!loading && !reconciliation.paymentRows.length">
              <td colspan="7" class="table-empty-cell">Belum ada data audit payment.</td>
            </tr>
            <tr v-for="item in reconciliation.paymentRows" :key="item.paymentNumber">
              <td><strong>{{ item.paymentNumber }}</strong></td>
              <td>{{ item.bookingCode || '-' }}</td>
              <td>{{ item.invoiceNo || '-' }}</td>
              <td>{{ toCurrency(item.amount) }}</td>
              <td>{{ toCurrency(item.allocatedAmount) }}</td>
              <td>{{ item.hasPaymentJournal ? 'OK' : 'Missing' }}</td>
              <td>
                <span v-if="!item.issues.length">OK</span>
                <span v-else>{{ item.issues.join(' | ') }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="activeTab === 'audittrail'">
        <div class="booking-inline-summary" style="margin-top: 1rem;">
          <div class="note-cell">
            <strong>Total log</strong>
            <p class="subtle" style="font-size: 1.2rem; color: var(--primary); font-weight: bold;">{{ auditTrail.meta.total }}</p>
          </div>
          <div class="note-cell">
            <strong>Halaman aktif</strong>
            <p class="subtle">{{ auditTrail.meta.current_page }} / {{ auditTrail.meta.last_page }}</p>
          </div>
          <div class="note-cell">
            <strong>Filter aktif</strong>
            <p class="subtle">{{ auditModule || 'Semua modul' }} | {{ auditSearch || 'Tanpa kata kunci' }}</p>
          </div>
        </div>

        <div class="table-toolbar" style="margin-top: 1rem;">
          <div class="utility-group">
            <input v-model="auditSearch" class="toolbar-search" placeholder="Cari user, entitas, atau deskripsi..." />
            <select v-model="auditModule" class="form-control" style="min-width: 180px;">
              <option v-for="item in auditModules" :key="item.value" :value="item.value">{{ item.label }}</option>
            </select>
          </div>
          <div class="utility-group">
            <button class="action-button primary" @click="loadAuditTrail">Filter audit</button>
            <button class="action-button" @click="exportAuditTrailCsv">Export audit</button>
          </div>
        </div>

        <table class="data-table">
          <thead>
            <tr>
              <th>Waktu</th>
              <th>User</th>
              <th>Modul</th>
              <th>Aksi</th>
              <th>Entitas</th>
              <th>Deskripsi</th>
              <th>IP</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!loading && !auditTrail.rows.length">
              <td colspan="7" class="table-empty-cell">Belum ada data audit trail.</td>
            </tr>
            <tr v-for="(item, index) in auditTrail.rows" :key="item.id ?? `audit-row-${index}`">
              <td>{{ item.createdAt }}</td>
              <td>
                <div><strong>{{ item.userName }}</strong></div>
                <div class="subtle">{{ item.userEmail }}</div>
              </td>
              <td>{{ item.module }}</td>
              <td>{{ item.action }}</td>
              <td>
                <div><strong>{{ item.entityLabel }}</strong></div>
                <div class="subtle">{{ item.entityType }} #{{ item.entityId }}</div>
              </td>
              <td>{{ item.description }}</td>
              <td>{{ item.ipAddress }}</td>
            </tr>
          </tbody>
        </table>
      </div>

    </article>
  </section>
</template>
