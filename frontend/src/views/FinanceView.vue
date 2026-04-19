<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useHotelStore } from '../stores/hotel'
import api from '../services/api'
import { encodeInvoicePrintOverrides } from '../utils/invoicePrintOverrides'
import { loadPrintTemplateSettings, PRINT_TEMPLATE_UPDATED_EVENT } from '../utils/printTemplate'

const hotel = useHotelStore()
const route = useRoute()
const router = useRouter()

const invoiceSearch = ref('')
const selectedInvoiceCode = ref(hotel.invoiceList[0]?.bookingCode ?? '')
const showInvoiceModal = ref(false)
const invoiceDocumentMode = ref('invoice')
const printTemplate = ref(loadPrintTemplateSettings())
const invoicePrintDraft = ref(null)
const invoiceHtmlPreviewUrl = ref('')
const showPaymentModal = ref(false)
const showPaymentActionModal = ref(false)
const paymentResult = ref({ tone: '', text: '' })
const paymentActionResult = ref({ tone: '', text: '' })
const paymentMethods = ['Cash', 'Bank Transfer', 'Credit Card', 'Debit Card', 'QRIS']
const paymentActionState = ref({
  id: null,
  paymentNumber: '',
  transactionLabel: '',
  amount: '',
  action: '',
  title: '',
  confirmLabel: '',
  warningText: '',
  note: '',
})

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

const MS_PER_DAY = 24 * 60 * 60 * 1000
const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
const toUtcDate = (value) => {
  const [year, month, day] = String(value ?? '').slice(0, 10).split('-').map(Number)
  return new Date(Date.UTC(year, (month || 1) - 1, day || 1))
}
const formatShortDate = (value) => {
  const date = toUtcDate(value)
  return `${String(date.getUTCDate()).padStart(2, '0')} ${monthNames[date.getUTCMonth()]} ${date.getUTCFullYear()}`
}
const diffNights = (start, end) => Math.max(1, Math.round((toUtcDate(end) - toUtcDate(start)) / MS_PER_DAY))

const invoicePrintView = computed(() => {
  if (!selectedInvoice.value) {
    return null
  }

  const invoice = selectedInvoice.value
  const nights = diffNights(invoice.checkIn, invoice.checkOut)
  const roomDetails = Array.isArray(invoice.roomDetails) ? invoice.roomDetails : []
  const rawAddonLines = Array.isArray(invoice.addons) && invoice.addons.length
    ? invoice.addons.map((item) => ({
        id: item.id,
        label: item.addonLabel,
        description: item.serviceName,
        serviceDate: item.serviceDateLabel ?? item.serviceDate ?? '-',
        status: item.status ?? '-',
        qty: Number(item.quantity ?? 1),
        amountValue: Number(item.totalPriceValue ?? 0),
        amountLabel: item.totalPrice ?? toCurrency(Number(item.totalPriceValue ?? 0)),
      }))
    : (invoice.items ?? [])
        .filter((item) => item.type !== 'room')
        .map((item) => ({
          id: item.id,
          label: item.label,
          description: item.description,
          serviceDate: '-',
          status: item.status ?? '-',
          qty: 1,
          amountValue: Number(item.amountValue ?? 0),
          amountLabel: item.amount ?? toCurrency(Number(item.amountValue ?? 0)),
        }))

  const addonTotalValue = rawAddonLines.reduce((total, item) => total + item.amountValue, 0)
  const roomOnlySubtotalValue = Math.max(Number(invoice.subtotalValue ?? 0) - addonTotalValue, 0)
  const roomCount = roomDetails.length || Math.max(1, Number(invoice.roomCount ?? 1))
  const perRoomDefault = roomCount > 0 ? Math.round(roomOnlySubtotalValue / roomCount) : roomOnlySubtotalValue
  const roomLines = roomDetails.length
    ? roomDetails.map((room, index) => {
        const roomRate = Number(room.rate ?? room.rateValue ?? 0)
        const totalValue = roomRate > 0 ? roomRate * nights : perRoomDefault
        return {
          id: `${invoice.bookingCode}-room-${room.room}-${index}`,
          room: room.room,
          roomType: room.roomType,
          pax: `${Number(room.adults ?? 0)} adult(s), ${Number(room.children ?? 0)} child(ren)`,
          rateValue: roomRate > 0 ? roomRate : Math.round(totalValue / nights),
          rateLabel: toCurrency(roomRate > 0 ? roomRate : Math.round(totalValue / nights)),
          nights,
          totalValue,
          totalLabel: toCurrency(totalValue),
        }
      })
    : [
        {
          id: `${invoice.bookingCode}-room-main`,
          room: invoice.room || '-',
          roomType: '-',
          pax: '-',
          rateValue: Math.round(roomOnlySubtotalValue / nights),
          rateLabel: toCurrency(Math.round(roomOnlySubtotalValue / nights)),
          nights,
          totalValue: roomOnlySubtotalValue,
          totalLabel: toCurrency(roomOnlySubtotalValue),
        },
      ]

  const roomTotalValue = roomLines.reduce((total, item) => total + item.totalValue, 0)
  const paymentLines = (invoice.payments ?? []).map((payment) => ({
    id: payment.id,
    date: payment.paymentDate,
    method: payment.method,
    type: payment.transactionLabel,
    reference: payment.referenceNo || '-',
    amountValue: Number(payment.signedAmountValue ?? payment.amountValue ?? 0),
    amountLabel: `${Number(payment.signedAmountValue ?? payment.amountValue ?? 0) < 0 ? '- ' : ''}${toCurrency(Math.abs(Number(payment.signedAmountValue ?? payment.amountValue ?? 0)))}`,
  }))

  return {
    ...invoice,
    stayLabel: `${formatShortDate(invoice.checkIn)} - ${formatShortDate(invoice.checkOut)}`,
    nightsLabel: `${nights} night(s)`,
    roomLines,
    roomTotalValue,
    roomTotalLabel: toCurrency(roomTotalValue),
    addonLines: rawAddonLines,
    addonTotalValue,
    addonTotalLabel: toCurrency(addonTotalValue),
    paymentLines,
  }
})

const createInvoicePrintDraft = () => {
  const invoice = invoicePrintView.value
  if (!invoice) {
    return null
  }

  const rawRoomDetails = Array.isArray(invoice.roomDetails) ? invoice.roomDetails : []

  return {
    invoice: {
      invoice_number: invoice.invoiceNo,
      issued_at: invoice.issueDate,
      due_at: invoice.dueDate,
      status: invoice.paymentStatus,
    },
    booking: {
      code: invoice.bookingCode,
      guest: invoice.guest,
      checkIn: invoice.checkIn,
      checkOut: invoice.checkOut,
      note: invoice.note || '',
      invoiceStatus: invoice.paymentStatus,
      roomDetails: invoice.roomLines.map((room, index) => ({
        room: room.room,
        roomType: room.roomType,
        adults: Number(rawRoomDetails[index]?.adults ?? 0),
        children: Number(rawRoomDetails[index]?.children ?? 0),
        rateValue: Number(room.rateValue ?? 0),
        lineTotalValue: Number(room.totalValue ?? 0),
      })),
      addons: invoice.addonLines.map((item) => ({
        addonLabel: item.label,
        serviceName: item.description,
        serviceDateLabel: item.serviceDate,
        quantity: Number(item.qty ?? 1),
        unitPriceValue: Number(item.qty ?? 0) > 0 ? Math.round(Number(item.amountValue ?? 0) / Number(item.qty ?? 1)) : Number(item.amountValue ?? 0),
        totalPriceValue: Number(item.amountValue ?? 0),
      })),
      payments: invoice.paymentLines.map((payment) => ({
        paymentDate: payment.date,
        transactionLabel: payment.type,
        method: payment.method,
        referenceNo: payment.reference,
        signedAmountValue: Number(payment.amountValue ?? 0),
      })),
    },
    document: {
      invoiceTitle: 'INVOICE',
      addonFooterNote: 'Please review this invoice carefully. Payments are considered settled after confirmed receipt.',
    },
  }
}

const openInvoiceModal = (bookingCode) => {
  selectedInvoiceCode.value = bookingCode
  invoiceDocumentMode.value = 'invoice'
  showInvoiceModal.value = true
  invoicePrintDraft.value = createInvoicePrintDraft()
  refreshInvoiceHtmlPreview()
}

const closeInvoiceModal = () => {
  showInvoiceModal.value = false
  invoiceHtmlPreviewUrl.value = ''
  if (route.query.invoice) {
    const nextQuery = { ...route.query }
    delete nextQuery.invoice
    router.replace({ query: nextQuery })
  }
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

const buildInvoicePdfUrl = (bookingCode, inline = false) => {
  const token = localStorage.getItem('pms_token') || ''
  const baseUrl = String(api.defaults.baseURL || '').replace(/\/+$/, '')
  const params = new URLSearchParams()
  params.set('size', 'A5')
  params.set('document', invoiceDocumentMode.value)
  if (inline) {
    params.set('inline', '1')
  }
  if (token) {
    params.set('token', token)
  }
  if (invoiceDocumentMode.value === 'invoice' && invoicePrintDraft.value) {
    const encodedOverrides = encodeInvoicePrintOverrides(invoicePrintDraft.value)
    if (encodedOverrides) {
      params.set('overrides', encodedOverrides)
    }
  }
  const query = params.toString()
  return `${baseUrl}/finance/invoices/${bookingCode}/pdf${query ? `?${query}` : ''}`
}

const buildInvoiceHtmlPreviewUrl = (bookingCode) => {
  const token = localStorage.getItem('pms_token') || ''
  const baseUrl = String(api.defaults.baseURL || '').replace(/\/+$/, '')
  const params = new URLSearchParams()
  if (token) {
    params.set('token', token)
  }
  if (invoicePrintDraft.value) {
    const encodedOverrides = encodeInvoicePrintOverrides(invoicePrintDraft.value)
    if (encodedOverrides) {
      params.set('overrides', encodedOverrides)
    }
  }
  const query = params.toString()
  return `${baseUrl}/finance/invoices/${bookingCode}/print${query ? `?${query}` : ''}`
}

const refreshInvoiceHtmlPreview = () => {
  if (!selectedInvoice.value || invoiceDocumentMode.value !== 'invoice') {
    invoiceHtmlPreviewUrl.value = ''
    return
  }

  invoiceHtmlPreviewUrl.value = buildInvoiceHtmlPreviewUrl(selectedInvoice.value.bookingCode)
}

const resetInvoicePrintDraft = () => {
  invoicePrintDraft.value = createInvoicePrintDraft()
  refreshInvoiceHtmlPreview()
}

const openPaymentActionModal = (payment, action) => {
  if (!payment?.id) {
    return
  }

  paymentActionResult.value = { tone: '', text: '' }
  paymentActionState.value = {
    id: payment.id,
    paymentNumber: payment.paymentNumber,
    transactionLabel: payment.transactionLabel ?? 'Payment',
    amount: payment.amount,
    action,
    title: action === 'refund' ? 'Refund payment' : 'Void payment',
    confirmLabel: action === 'refund' ? 'Posting refund' : 'Posting void',
    warningText: action === 'refund'
      ? 'Refund will reverse cash received and reopen the related invoice receivable.'
      : 'Void will cancel this payment and reverse the settlement journal that has already been posted.',
    note: '',
  }
  showPaymentActionModal.value = true
}

const closePaymentActionModal = () => {
  showPaymentActionModal.value = false
  paymentActionState.value = {
    id: null,
    paymentNumber: '',
    transactionLabel: '',
    amount: '',
    action: '',
    title: '',
    confirmLabel: '',
    warningText: '',
    note: '',
  }
}

const submitPaymentAction = async () => {
  if (!paymentActionState.value.id || !paymentActionState.value.action) {
    closePaymentActionModal()
    return
  }

  paymentActionResult.value = { tone: '', text: '' }

  try {
    const endpoint = paymentActionState.value.action === 'refund'
      ? `/payments/${paymentActionState.value.id}/refund`
      : `/payments/${paymentActionState.value.id}/void`

    const response = await api.post(endpoint, {
      note: paymentActionState.value.note,
    })

    await Promise.all([loadBookings(), loadPayments(), loadReports()])

    paymentActionResult.value = {
      tone: 'success',
      text: response.data?.message || 'Payment action was posted successfully.',
    }

    setTimeout(() => {
      closePaymentActionModal()
    }, 1000)
  } catch (error) {
    paymentActionResult.value = {
      tone: 'error',
      text: error?.response?.data?.message || error?.message || 'Failed to process the payment action.',
    }
  }
}

const submitPayment = async () => {
  paymentResult.value = { tone: '', text: '' }

  if (!selectedInvoice.value) {
    paymentResult.value = { tone: 'error', text: 'No invoice has been selected yet.' }
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
      text: `Payment ${payment.amount} was posted successfully to ${payment.invoiceNo}.`,
    }
    showPaymentModal.value = false
  } catch (error) {
    paymentResult.value = {
      tone: 'error',
      text: error?.response?.data?.message || (error instanceof Error ? error.message : 'Failed to post the payment.'),
    }
  }
}

const loadPayments = async () => {
  try {
    const response = await api.get('/payments')
    hotel.setPaymentTransactions(Array.isArray(response.data?.data) ? response.data.data : [])
  } catch (e) {
    console.error('Failed to load payments:', e)
  }
}

const syncBookingCollections = (rows) => {
  hotel.setBookings(rows)

  const allAddons = []
  rows.forEach((booking) => {
    if (Array.isArray(booking.addons)) {
      allAddons.push(
        ...booking.addons.map((addon) => ({
          ...addon,
          bookingCode: booking.code,
        })),
      )
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
    console.error('Failed to load reservations from the database:', e)
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
    console.error('Failed to load accounting reports:', e)
  }
}

const syncPrintTemplate = () => {
  printTemplate.value = loadPrintTemplateSettings()
}

onMounted(async () => {
  if (typeof window !== 'undefined') {
    window.addEventListener(PRINT_TEMPLATE_UPDATED_EVENT, syncPrintTemplate)
  }
  await Promise.all([loadBookings(), loadPayments(), loadReports()])
})

onBeforeUnmount(() => {
  if (typeof window === 'undefined') {
    return
  }

  window.removeEventListener(PRINT_TEMPLATE_UPDATED_EVENT, syncPrintTemplate)
})

watch(
  () => [route.query.invoice, hotel.invoiceList.length],
  ([invoiceQuery]) => {
    const bookingCode = String(invoiceQuery ?? '').trim()
    if (!bookingCode) {
      return
    }

    const exists = hotel.invoiceList.some((item) => item.bookingCode === bookingCode)
    if (exists) {
      openInvoiceModal(bookingCode)
    }
  },
  { immediate: true },
)

watch(
  () => [selectedInvoiceCode.value, showInvoiceModal.value, invoiceDocumentMode.value],
  () => {
    if (!showInvoiceModal.value || invoiceDocumentMode.value !== 'invoice') {
      return
    }

    invoicePrintDraft.value = createInvoicePrintDraft()
    refreshInvoiceHtmlPreview()
  },
)

const downloadInvoicePdf = async () => {
  if (!selectedInvoice.value) {
    return
  }

  const params = { size: 'A5', document: invoiceDocumentMode.value }
  if (invoiceDocumentMode.value === 'invoice' && invoicePrintDraft.value) {
    const encodedOverrides = encodeInvoicePrintOverrides(invoicePrintDraft.value)
    if (encodedOverrides) {
      params.overrides = encodedOverrides
    }
  }

  const response = await api.get(`/finance/invoices/${selectedInvoice.value.bookingCode}/pdf`, {
    params,
    responseType: 'blob',
  })
  const blob = new Blob([response.data], { type: 'application/pdf' })
  const url = window.URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = `${invoiceDocumentMode.value}-${selectedInvoice.value.invoiceNo || selectedInvoice.value.bookingCode}.pdf`
  document.body.appendChild(link)
  link.click()
  link.remove()
  window.URL.revokeObjectURL(url)
}

const openInvoicePrintPage = () => {
  if (!selectedInvoice.value || typeof window === 'undefined') {
    return
  }

  window.open(buildInvoicePdfUrl(selectedInvoice.value.bookingCode, true), '_blank', 'noopener')
}
</script>

<template>
  <section class="page-grid two finance-page-grid">
    <article class="panel-card panel-dense">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Finance summary</p>
          <h3>Invoice and payment summary</h3>
        </div>
        <span class="status-badge warning">{{ hotel.financeOpenFolios.length }} open balances</span>
      </div>

      <div class="table-scroll">
        <table v-smart-table class="data-table finance-table">
          <thead>
            <tr>
              <th>Posting type</th>
              <th>Volume</th>
              <th>Amount</th>
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
      </div>
    </article>

    <article class="panel-card panel-dense">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Open folios</p>
          <h3>Outstanding balance queue</h3>
        </div>
        <span class="status-badge info">Synced from bookings</span>
      </div>

      <div class="table-scroll">
        <table v-smart-table class="data-table finance-table">
          <thead>
            <tr>
              <th>Guest</th>
              <th>Balance</th>
              <th>Reference</th>
              <th>Due date</th>
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
      </div>
    </article>
  </section>

  <section class="page-grid">
    <article class="panel-card panel-dense">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Invoice list</p>
          <h3>Guest invoices</h3>
        </div>
        <div class="kpi-inline">
          <span>{{ filteredInvoices.length }}, visible invoices</span>
          <span>{{ hotel.invoiceList.length }} total invoice</span>
        </div>
      </div>

      <div class="table-toolbar">
        <div class="toolbar-tabs">
          <button class="toolbar-tab active">Invoice</button>
          <button class="toolbar-tab">Payments</button>
          <button class="toolbar-tab">General Journals</button>
        </div>
        <div class="topbar-actions">
          <input
            v-model="invoiceSearch"
            class="toolbar-search"
            placeholder="Search invoice / booking / guest"
          />
          <RouterLink class="action-button primary" :to="{ name: 'journals' }">General journals</RouterLink>
        </div>
      </div>

      <div v-if="paymentResult.text" class="booking-feedback" :class="paymentResult.tone">
        {{ paymentResult.text }}
      </div>

      <div class="table-scroll">
        <table v-smart-table class="data-table finance-table">
          <thead>
            <tr>
              <th>Invoice</th>
              <th>Guest</th>
              <th>Stay</th>
              <th>Total</th>
              <th>Paid</th>
              <th>Balance</th>
              <th>Status</th>
              <th>Action</th>
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
                  <button class="action-button" @click="openInvoiceModal(item.bookingCode)">Preview</button>
                  <button class="action-button primary" @click="openPaymentModal(item.bookingCode)">Payment</button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </article>
  </section>

  <section class="page-grid two finance-page-grid">
    <article class="panel-card panel-dense">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Recent payments</p>
          <h3>Payment postings</h3>
        </div>
        <span class="status-badge success">{{ hotel.paymentTransactions.length }} posted</span>
      </div>

      <div class="table-scroll">
        <table v-smart-table class="data-table finance-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Invoice</th>
              <th>Type</th>
              <th>Method</th>
              <th>Amount</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in recentPayments" :key="item.id">
              <td>{{ item.paymentDate }}</td>
              <td><strong>{{ item.invoiceNo }}</strong></td>
              <td>{{ item.transactionLabel }}</td>
              <td>{{ item.method }}</td>
              <td>{{ item.transactionType === 'payment' ? item.amount : `- ${item.amount}` }}</td>
              <td>
                <div class="modal-actions">
                  <button v-if="item.canRefund" class="action-button" @click="openPaymentActionModal(item, 'refund')">Refund</button>
                  <button v-if="item.canVoid" class="action-button" @click="openPaymentActionModal(item, 'void')">Void</button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </article>

    <article class="panel-card panel-dense">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">General journals</p>
          <h3>Latest manual journals</h3>
        </div>
        <span class="status-badge info">{{ hotel.generalJournalList.length }} posted</span>
      </div>

      <div class="table-scroll">
        <table v-smart-table class="data-table finance-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Journal no.</th>
              <th>Reference</th>
              <th>Description</th>
              <th>Debit</th>
              <th>Credit</th>
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
      </div>
    </article>

    <article class="panel-card panel-dense">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Accounting report</p>
          <h3>Profit & Loss</h3>
        </div>
        <span class="status-badge success">Real-time</span>
      </div>

      <div class="compact-list">
        <div class="list-row list-row-tight">
          <strong>Room Revenue</strong>
          <span>{{ toCurrency(profitLossReq.revenue.room) }}</span>
        </div>
        <div class="list-row list-row-tight">
          <strong>Service Revenue (Add-ons)</strong>
          <span>{{ toCurrency(profitLossReq.revenue.addon) }}</span>
        </div>
        <div class="list-row list-row-tight">
          <strong>Total Revenue</strong>
          <span style="font-weight: bold;">{{ toCurrency(profitLossReq.revenue.total) }}</span>
        </div>
        <div class="list-row list-row-tight">
          <strong>Operating Expenses (Journals)</strong>
          <span style="color: darkred;">- {{ toCurrency(profitLossReq.expense.total) }}</span>
        </div>
        <div class="list-row list-row-tight" style="border-top: 1px solid var(--border); padding-top: 16px; margin-top: 8px;">
          <strong style="color: var(--primary);">Net Profit</strong>
          <strong style="color: var(--primary); font-size: 1.1rem;">{{ toCurrency(profitLossReq.netProfit) }}</strong>
        </div>
      </div>
    </article>

    <article class="panel-card panel-dense">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Cash report</p>
          <h3>Cash Flow</h3>
        </div>
        <span class="status-badge info">Real-time</span>
      </div>

      <div class="compact-list">
        <div class="list-row list-row-tight">
          <div>
            <strong>Cash Inflow</strong>
            <p class="subtle">Guest invoice payments and related receipts</p>
          </div>
          <span style="font-weight: bold;">{{ toCurrency(cashFlowReq.inflow.guest_payments) }}</span>
        </div>
        <div class="list-row list-row-tight">
          <div>
            <strong>Cash Outflow</strong>
            <p class="subtle">Expense journal postings</p>
          </div>
          <span style="font-weight: bold; color: darkred;">- {{ toCurrency(cashFlowReq.outflow.expenses) }}</span>
        </div>
        <div class="list-row list-row-tight" style="border-top: 1px solid var(--border); padding-top: 16px; margin-top: 8px;">
          <strong style="color: var(--primary);">Net Running Cash</strong>
          <strong style="color: var(--primary); font-size: 1.1rem;">{{ toCurrency(cashFlowReq.netCashFlow) }}</strong>
        </div>
      </div>
    </article>
  
  </section>

  <div v-if="showInvoiceModal && selectedInvoice && invoicePrintView" class="modal-backdrop" @click.self="closeInvoiceModal()">
    <section class="modal-card invoice-modal-card">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">{{ invoiceDocumentMode === 'folio' ? 'Folio preview' : 'Invoice preview' }}</p>
          <h3>{{ selectedInvoice.invoiceNo }} | {{ selectedInvoice.guest }}</h3>
        </div>
        <button class="action-button" @click="closeInvoiceModal()">Close</button>
      </div>

      <div class="table-toolbar" style="margin-top: 1rem;">
        <div class="toolbar-tabs">
          <button class="toolbar-tab" :class="{ active: invoiceDocumentMode === 'invoice' }" @click="invoiceDocumentMode = 'invoice'">Invoice</button>
          <button class="toolbar-tab" :class="{ active: invoiceDocumentMode === 'folio' }" @click="invoiceDocumentMode = 'folio'">Folio</button>
        </div>
      </div>

      <article class="invoice-print-sheet">
        <header class="invoice-print-header">
          <div class="invoice-brand-block">
            <p class="eyebrow-dark">{{ printTemplate.documentLabel }}</p>
            <h2>{{ printTemplate.documentTitle || hotel.hotelName }}</h2>
            <p class="subtle">{{ printTemplate.tagline }}</p>
          </div>
          <div class="invoice-print-meta invoice-doc-meta">
            <div>
              <span class="invoice-meta-label">Invoice No.</span>
              <strong>{{ invoicePrintView.invoiceNo }}</strong>
            </div>
            <div>
              <span class="invoice-meta-label">Booking Ref.</span>
              <strong>{{ invoicePrintView.bookingCode }}</strong>
            </div>
            <div>
              <span class="invoice-meta-label">{{ invoiceDocumentMode === 'folio' ? 'Folio Status' : 'Status' }}</span>
              <strong>{{ invoicePrintView.paymentStatus }}</strong>
            </div>
          </div>
        </header>

        <section class="invoice-doc-grid">
          <div class="note-cell">
            <strong>Bill to</strong>
            <p class="subtle">{{ invoicePrintView.guest }}</p>
            <p class="subtle">{{ invoicePrintView.channel }} booking</p>
          </div>
          <div class="note-cell">
            <strong>Stay details</strong>
            <p class="subtle">{{ invoicePrintView.stayLabel }}</p>
            <p class="subtle">{{ invoicePrintView.nightsLabel }} | {{ invoicePrintView.roomCount }} room(s)</p>
          </div>
          <div class="note-cell">
            <strong>Document dates</strong>
            <p class="subtle">{{ invoicePrintView.issueDate }} / {{ invoicePrintView.dueDate }}</p>
          </div>
          <div class="note-cell">
            <strong>Room</strong>
            <p class="subtle">{{ invoicePrintView.roomCount }} room(s) | {{ invoicePrintView.room }}</p>
          </div>
        </section>

        <section class="invoice-section">
          <div class="panel-head panel-head-tight">
            <div>
              <p class="eyebrow-dark">Room charges</p>
              <h3>Room details</h3>
            </div>
            <strong>{{ invoicePrintView.roomTotalLabel }}</strong>
          </div>

          <div class="table-scroll">
            <table class="data-table invoice-lines-table">
              <thead>
                <tr>
                  <th>Room</th>
                  <th>Pax</th>
                  <th>Rate</th>
                  <th>Nights</th>
                  <th>Total</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="room in invoicePrintView.roomLines" :key="room.id">
                  <td><strong>{{ room.room }}</strong></td>
                  <td>{{ room.pax }}</td>
                  <td>{{ room.rateLabel }}</td>
                  <td>{{ room.nights }}</td>
                  <td>{{ room.totalLabel }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section class="invoice-section">
          <div class="panel-head panel-head-tight">
            <div>
              <p class="eyebrow-dark">Add-ons</p>
              <h3>Purchases and add-on services</h3>
            </div>
            <strong>{{ invoicePrintView.addonTotalLabel }}</strong>
          </div>

          <div v-if="invoicePrintView.addonLines.length" class="table-scroll">
            <table class="data-table invoice-lines-table">
              <thead>
                <tr>
                  <th>Item</th>
                  <th>Service</th>
                  <th>Date</th>
                  <th>Status</th>
                  <th>Qty</th>
                  <th>Total</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="item in invoicePrintView.addonLines" :key="item.id">
                  <td><strong>{{ item.label }}</strong></td>
                  <td>{{ item.description }}</td>
                  <td>{{ item.serviceDate }}</td>
                  <td>{{ item.status }}</td>
                  <td>{{ item.qty }}</td>
                  <td>{{ item.amountLabel }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-else class="subtle booking-addon-empty">No add-ons found in this invoice.</p>
        </section>

        <section class="invoice-charge-summary">
          <div class="invoice-charge-row">
            <span>Room charges</span>
            <strong>{{ invoicePrintView.roomTotalLabel }}</strong>
          </div>
          <div class="invoice-charge-row">
            <span>Add-on services</span>
            <strong>{{ invoicePrintView.addonTotalLabel }}</strong>
          </div>
          <div class="invoice-charge-row">
            <span>Total invoice</span>
            <strong>{{ invoicePrintView.subtotal }}</strong>
          </div>
          <template v-if="invoiceDocumentMode === 'folio'">
            <div class="invoice-charge-row">
              <span>Paid</span>
              <strong>{{ invoicePrintView.paid }}</strong>
            </div>
            <div class="invoice-charge-row balance">
              <span>Outstanding balance</span>
              <strong>{{ invoicePrintView.balance }}</strong>
            </div>
          </template>
        </section>

        <section class="invoice-print-grid invoice-summary-grid">
          <div class="note-cell">
            <strong>Prepared by</strong>
            <p class="subtle">Front Office</p>
          </div>
          <div class="note-cell">
            <strong>Guest acknowledgment</strong>
            <p class="subtle">Signature upon request</p>
          </div>
          <div class="note-cell">
            <strong>{{ invoiceDocumentMode === 'folio' ? 'Settlement status' : 'Invoice status' }}</strong>
            <p class="subtle">{{ invoicePrintView.paymentStatus }}</p>
          </div>
        </section>

        <section v-if="invoiceDocumentMode === 'folio'" class="invoice-section">
          <div class="panel-head panel-head-tight">
            <div>
              <p class="eyebrow-dark">Payment history</p>
              <h3>Payment history</h3>
            </div>
          </div>

          <div v-if="invoicePrintView.paymentLines.length" class="table-scroll">
            <table class="data-table invoice-lines-table">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Type</th>
                  <th>Method</th>
                  <th>Reference</th>
                  <th>Amount</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="payment in invoicePrintView.paymentLines" :key="payment.id">
                  <td>{{ payment.date }}</td>
                  <td><strong>{{ payment.type }}</strong></td>
                  <td>{{ payment.method }}</td>
                  <td>{{ payment.reference }}</td>
                  <td>{{ payment.amountLabel }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-else class="subtle booking-addon-empty">No payments have been posted for this invoice.</p>
        </section>

        <p v-if="invoiceDocumentMode === 'folio' && invoicePrintView.note" class="subtle invoice-print-note">
          Booking note: {{ invoicePrintView.note }}
        </p>

        <p v-if="false && printTemplate.footerNote" class="subtle invoice-print-note invoice-print-note-footer">
          {{ printTemplate.footerNote }}
        </p>

        <footer v-if="false" class="invoice-print-footer">
          <div class="invoice-signature-box">
            <span>{{ printTemplate.preparedByLabel }}</span>
          </div>
          <div class="invoice-signature-box">
            <span>{{ printTemplate.approvalLabel }}</span>
          </div>
        </footer>
      </article>

      <section v-if="false && invoiceDocumentMode === 'invoice' && invoicePrintDraft" class="invoice-editor-shell">
        <article class="panel-card panel-dense invoice-editor-panel">
          <div class="panel-head panel-head-tight">
            <div>
              <p class="eyebrow-dark">TCPDF editor</p>
              <h3>Manual invoice override</h3>
              <p class="panel-note">Edit field manual, lalu refresh preview. Download PDF akan memakai nilai override ini.</p>
            </div>
          </div>

          <div class="settings-designer-grid">
            <label class="field-stack">
              <span>Document title</span>
              <input v-model="invoicePrintDraft.document.invoiceTitle" class="form-control" type="text" />
            </label>
            <label class="field-stack">
              <span>Invoice number</span>
              <input v-model="invoicePrintDraft.invoice.invoice_number" class="form-control" type="text" />
            </label>
            <label class="field-stack">
              <span>Invoice date</span>
              <input v-model="invoicePrintDraft.invoice.issued_at" class="form-control" type="date" />
            </label>
            <label class="field-stack">
              <span>Due date</span>
              <input v-model="invoicePrintDraft.invoice.due_at" class="form-control" type="date" />
            </label>
            <label class="field-stack">
              <span>Status</span>
              <input v-model="invoicePrintDraft.invoice.status" class="form-control" type="text" />
            </label>
            <label class="field-stack">
              <span>Guest</span>
              <input v-model="invoicePrintDraft.booking.guest" class="form-control" type="text" />
            </label>
            <label class="field-stack">
              <span>Check-in</span>
              <input v-model="invoicePrintDraft.booking.checkIn" class="form-control" type="date" />
            </label>
            <label class="field-stack">
              <span>Check-out</span>
              <input v-model="invoicePrintDraft.booking.checkOut" class="form-control" type="date" />
            </label>
            <label class="field-stack field-span-2">
              <span>Footer note</span>
              <textarea v-model="invoicePrintDraft.document.addonFooterNote" class="form-control form-textarea"></textarea>
            </label>
          </div>

          <div class="compact-list">
            <div class="list-row list-row-tight invoice-editor-listhead">
              <strong>Room rows</strong>
              <span class="subtle">Nilai ini akan dipakai oleh preview backend dan file PDF.</span>
            </div>
            <div v-for="(room, index) in invoicePrintDraft.booking.roomDetails" :key="`room-${index}`" class="invoice-editor-grid">
              <input v-model="room.room" class="form-control" type="text" placeholder="Room" />
              <input v-model="room.roomType" class="form-control" type="text" placeholder="Room type" />
              <input v-model.number="room.rateValue" class="form-control" type="number" min="0" step="1" placeholder="Rate" />
              <input v-model.number="room.lineTotalValue" class="form-control" type="number" min="0" step="1" placeholder="Total" />
            </div>
          </div>

          <div class="compact-list" style="margin-top: 12px;">
            <div class="list-row list-row-tight invoice-editor-listhead">
              <strong>Add-on rows</strong>
              <span class="subtle">Jumlah dan nominal add-on bisa diubah manual.</span>
            </div>
            <div v-for="(addon, index) in invoicePrintDraft.booking.addons" :key="`addon-${index}`" class="invoice-editor-grid invoice-editor-grid-addon">
              <input v-model="addon.addonLabel" class="form-control" type="text" placeholder="Item" />
              <input v-model="addon.serviceName" class="form-control" type="text" placeholder="Service" />
              <input v-model="addon.serviceDateLabel" class="form-control" type="text" placeholder="Service date" />
              <input v-model.number="addon.quantity" class="form-control" type="number" min="0" step="1" placeholder="Qty" />
              <input v-model.number="addon.totalPriceValue" class="form-control" type="number" min="0" step="1" placeholder="Total" />
            </div>
          </div>

          <div class="modal-actions">
            <button class="action-button" @click="resetInvoicePrintDraft">Reset manual edit</button>
            <button class="action-button primary" @click="refreshInvoiceHtmlPreview">Refresh TCPDF preview</button>
          </div>
        </article>

        <article class="panel-card panel-dense invoice-editor-preview">
          <div class="panel-head panel-head-tight">
            <div>
              <p class="eyebrow-dark">Backend preview</p>
              <h3>HTML preview from invoice-print</h3>
            </div>
          </div>

          <iframe
            v-if="invoiceHtmlPreviewUrl"
            :src="invoiceHtmlPreviewUrl"
            class="invoice-preview-frame"
            title="Invoice TCPDF preview"
          ></iframe>
          <p v-else class="subtle">Preview belum dimuat.</p>
        </article>
      </section>

      <div class="compact-list invoice-payment-actions">
        <div v-for="payment in selectedInvoice.payments" :key="payment.id" class="list-row list-row-tight">
          <div>
            <strong>{{ payment.paymentDate }} | {{ payment.method }} | {{ payment.transactionLabel }}</strong>
            <p class="subtle">{{ payment.transactionType === 'payment' ? payment.amount : `- ${payment.amount}` }} | {{ payment.referenceNo }}</p>
          </div>
          <div class="modal-actions">
            <button v-if="payment.canRefund" class="action-button" @click="openPaymentActionModal(payment, 'refund')">Refund</button>
            <button v-if="payment.canVoid" class="action-button" @click="openPaymentActionModal(payment, 'void')">Void</button>
          </div>
        </div>
      </div>

      <div class="modal-actions">
            <button class="action-button" @click="openInvoicePrintPage">Open PDF Print</button>
          <button class="action-button" @click="downloadInvoicePdf">Download PDF</button>
          <button class="action-button primary" @click="closeInvoiceModal(); openPaymentModal(selectedInvoice.bookingCode)">
            Record payment
          </button>
      </div>
    </section>
  </div>

  <div v-if="showPaymentModal && selectedInvoice" class="modal-backdrop" @click.self="closePaymentModal()">
    <section class="modal-card">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Payment entry</p>
          <h3>{{ selectedInvoice.invoiceNo }} | {{ selectedInvoice.guest }}</h3>
        </div>
        <button class="action-button" @click="closePaymentModal()">Close</button>
      </div>

      <div class="booking-inline-summary">
        <div class="note-cell">
          <strong>Total invoice</strong>
          <p class="subtle">{{ selectedInvoice.subtotal }}</p>
        </div>
        <div class="note-cell">
          <strong>Paid</strong>
          <p class="subtle">{{ selectedInvoice.paid }}</p>
        </div>
        <div class="note-cell">
          <strong>Outstanding</strong>
          <p class="subtle">{{ selectedInvoice.balance }}</p>
        </div>
      </div>

      <div class="booking-form-grid">
        <label class="field-stack">
          <span>Payment date</span>
          <input v-model="paymentForm.paymentDate" class="form-control" type="date" />
        </label>

        <label class="field-stack">
          <span>Method</span>
          <select v-model="paymentForm.method" class="form-control">
            <option v-for="item in paymentMethods" :key="item" :value="item">{{ item }}</option>
          </select>
        </label>

        <label class="field-stack">
          <span>Amount</span>
          <input 
            v-model="displayAmount" 
            class="form-control" 
            type="text" 
            @focus="isAmountFocused = true"
            @blur="isAmountFocused = false"
          />
        </label>

        <label class="field-stack">
          <span>Reference no.</span>
          <input v-model="paymentForm.referenceNo" class="form-control" placeholder="TRX number / receipt" />
        </label>

        <label class="field-stack field-span-2">
          <span>Notes</span>
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
        <button class="action-button" @click="closePaymentModal()">Cancel</button>
        <button class="action-button primary" @click="submitPayment">Post payment</button>
      </div>
    </section>
  </div>

  <div v-if="showPaymentActionModal" class="modal-backdrop" @click.self="closePaymentActionModal()">
    <section class="modal-card">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Payment action</p>
          <h3>{{ paymentActionState.title }}</h3>
        </div>
        <button class="action-button" @click="closePaymentActionModal()">Close</button>
      </div>

      <div class="booking-inline-summary">
        <div class="note-cell">
          <strong>No payment</strong>
          <p class="subtle">{{ paymentActionState.paymentNumber }}</p>
        </div>
        <div class="note-cell">
          <strong>Original type</strong>
          <p class="subtle">{{ paymentActionState.transactionLabel }}</p>
        </div>
        <div class="note-cell">
          <strong>Amount</strong>
          <p class="subtle">{{ paymentActionState.amount }}</p>
        </div>
      </div>

      <div class="booking-feedback error">
        {{ paymentActionState.warningText }}
      </div>

      <label class="field-stack" style="margin-top: 12px;">
        <span>Notes</span>
        <textarea
          v-model="paymentActionState.note"
          class="form-control form-textarea"
          placeholder="Reason for refund / void"
        ></textarea>
      </label>

      <div v-if="paymentActionResult.text" class="booking-feedback" :class="paymentActionResult.tone">
        {{ paymentActionResult.text }}
      </div>

      <div class="modal-actions">
        <button class="action-button" @click="closePaymentActionModal()">Back</button>
        <button class="action-button primary" @click="submitPaymentAction">{{ paymentActionState.confirmLabel }}</button>
      </div>
    </section>
  </div>

</template>
