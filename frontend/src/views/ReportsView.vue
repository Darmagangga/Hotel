<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import * as XLSX from 'xlsx'
import { useRoute, useRouter } from 'vue-router'
import api from '../services/api'
import LoadingState from '../components/LoadingState.vue'

const route = useRoute()
const router = useRouter()
const loading = ref(false)
const activeTab = ref('labarugi')
const reportResult = ref({ tone: '', text: '' })
const reportTabs = [
  { id: 'labarugi', label: 'Profit & Loss' },
  { id: 'neraca', label: 'Balance Sheet' },
  { id: 'aruskas', label: 'Cash Flow' },
  { id: 'roomstatus', label: 'Room Status' },
  { id: 'bukubesar', label: 'General Ledger' },
  { id: 'rekonsiliasi', label: 'Reconciliation' },
  { id: 'audittrail', label: 'Audit Trail' },
]

const coaAccounts = ref([])
const loadingLedger = ref(false)
const profitLoss = ref({ revenues: [], expenses: [], total_revenue: 0, total_expense: 0, net_profit: 0 })
const balanceSheet = ref({ assets: [], liabilities: [], equities: [], total_asset: 0, total_liability_and_equity: 0 })
const cashFlow = ref({ inflows: [], outflows: [], total_inflow: 0, total_outflow: 0, net_cash_flow: 0 })
const roomStatusRows = ref([])
const generalLedger = ref({
  account: null,
  period: { from: '', to: '' },
  opening_balance: 0,
  total_debit: 0,
  total_credit: 0,
  closing_balance: 0,
  entries: [],
})
const ledgerFilters = ref({
  coaCode: '',
  fromDate: '',
  toDate: '',
})
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
const roomReportSearch = ref('')
const auditModules = [
  { value: '', label: 'All modules' },
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

const downloadWorkbook = (filename, sheets) => {
  const workbook = XLSX.utils.book_new()

  sheets.forEach((sheet) => {
    const worksheet = XLSX.utils.aoa_to_sheet(sheet.rows)
    applyWorksheetLayout(worksheet, {
      cols: sheet.cols,
      merges: sheet.merges,
      amountCols: sheet.amountCols,
      dataStartRow: sheet.dataStartRow,
    })
    XLSX.utils.book_append_sheet(workbook, worksheet, sheet.name)
  })

  XLSX.writeFile(workbook, filename)
}

const normalizeCoaAccounts = (rows) => {
  if (!Array.isArray(rows)) {
    return []
  }

  return rows.map((item) => ({
    code: item.code ?? '',
    name: item.name ?? '',
    category: String(item.category ?? '').toLowerCase(),
    normalBalance: item.normalBalance ?? item.normal_balance ?? '',
  }))
}

const buildBalanceMap = (rows) => {
  const map = new Map()

  rows.forEach((item) => {
    map.set(item.code, Number(item.balance || 0))
  })

  return map
}

const buildCategoryRows = (category, rows) => {
  const balances = buildBalanceMap(rows)

  return coaAccounts.value
    .filter((item) => item.category === category)
    .map((item) => ({
      code: item.code,
      name: item.name,
      normalBalance: item.normalBalance,
      balance: balances.get(item.code) ?? 0,
    }))
}

const buildCashAccountRows = () => {
  const inflowMap = new Map()
  const outflowMap = new Map()
  const assetBalances = buildBalanceMap(balanceSheet.value.assets)

  cashFlow.value.inflows.forEach((item) => {
    const code = item.coa ?? ''
    inflowMap.set(code, (inflowMap.get(code) ?? 0) + Number(item.amount || 0))
  })

  cashFlow.value.outflows.forEach((item) => {
    const code = item.coa ?? ''
    outflowMap.set(code, (outflowMap.get(code) ?? 0) + Number(item.amount || 0))
  })

  return coaAccounts.value
    .filter((item) => item.category === 'asset' && item.code.startsWith('111'))
    .map((item) => {
      const inflow = inflowMap.get(item.code) ?? 0
      const outflow = outflowMap.get(item.code) ?? 0

      return {
        code: item.code,
        name: item.name,
        normalBalance: item.normalBalance,
        balance: assetBalances.get(item.code) ?? 0,
        inflow,
        outflow,
        netMovement: inflow - outflow,
      }
    })
}

const normalizeRoomStatusRows = (rooms, housekeepingRows) => {
  const housekeepingMap = new Map(
    (Array.isArray(housekeepingRows) ? housekeepingRows : []).map((item) => [String(item.room ?? item.roomNo ?? ''), item]),
  )

  return (Array.isArray(rooms) ? rooms : []).map((room) => {
    const queueItem = housekeepingMap.get(String(room.code ?? ''))
    return {
      code: room.code ?? '-',
      name: room.name ?? '-',
      type: room.type ?? '-',
      floor: room.floor ?? '-',
      status: room.status ?? '-',
      housekeepingStatus: queueItem ? `${queueItem.taskStatus ?? '-'} | ${queueItem.taskType ?? '-'}` : (room.hk ?? 'No active task'),
      housekeepingTeam: queueItem?.ownerTeam ?? '-',
      note: room.note ?? '-',
    }
  })
}

const filteredRoomStatusRows = computed(() => {
  const query = roomReportSearch.value.trim().toLowerCase()
  if (!query) {
    return roomStatusRows.value
  }

  return roomStatusRows.value.filter((item) =>
    [item.code, item.name, item.type, item.floor, item.status, item.housekeepingStatus, item.note]
      .join(' ')
      .toLowerCase()
      .includes(query),
  )
})

const excelAmountFormat = '#,##0;[Red](#,##0)'

const getExportTimestamp = () =>
  new Intl.DateTimeFormat('en-GB', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(new Date())

const applyWorksheetLayout = (worksheet, options = {}) => {
  worksheet['!cols'] = options.cols ?? []

  if (options.merges?.length) {
    worksheet['!merges'] = options.merges
  }

  if (options.amountCols?.length) {
    const range = XLSX.utils.decode_range(worksheet['!ref'] || 'A1:A1')

    for (let row = options.dataStartRow ?? 0; row <= range.e.r; row += 1) {
      options.amountCols.forEach((colIndex) => {
        const cellRef = XLSX.utils.encode_cell({ r: row, c: colIndex })
        const cell = worksheet[cellRef]

        if (cell && typeof cell.v === 'number') {
          cell.z = excelAmountFormat
        }
      })
    }
  }
}

const appendSectionRows = (rows, title, items, totalLabel, totalValue) => {
  rows.push([title])
  rows.push(['No', 'COA Code', 'Account Name', 'Normal Balance', 'Balance'])

  items.forEach((item, index) => {
    rows.push([
      index + 1,
      item.code,
      item.name,
      item.normalBalance,
      Number(item.balance || 0),
    ])
  })

  rows.push(['', '', totalLabel, '', Number(totalValue || 0)])
  rows.push([])
}

const buildStatementSheet = (title, sections, summaryRows = []) => {
  const rows = [
    [title],
    [`Generated at: ${getExportTimestamp()}`],
    [],
  ]

  sections.forEach((section) => {
    appendSectionRows(rows, section.title, section.items, section.totalLabel, section.totalValue)
  })

  if (summaryRows.length) {
    rows.push(['Summary'])
    rows.push(['Description', '', '', '', 'Amount'])
    summaryRows.forEach((item) => {
      rows.push([item.label, '', '', '', Number(item.value || 0)])
    })
  }

  return {
    rows,
    merges: [
      { s: { r: 0, c: 0 }, e: { r: 0, c: 4 } },
      { s: { r: 1, c: 0 }, e: { r: 1, c: 4 } },
    ],
    cols: [
      { wch: 8 },
      { wch: 16 },
      { wch: 36 },
      { wch: 18 },
      { wch: 18 },
    ],
    amountCols: [4],
    dataStartRow: 4,
  }
}

const loadAllReports = async () => {
  loading.value = true
  reportResult.value = { tone: '', text: '' }

  try {
    const [plRes, bsRes, cfRes, reconRes, auditRes, coaRes, roomsRes, housekeepingRes] = await Promise.all([
      api.get('/reports/profit-loss'),
      api.get('/reports/balance-sheet'),
      api.get('/reports/cash-flow'),
      api.get('/reports/reconciliation'),
      api.get('/audit-trails'),
      api.get('/coa-accounts', { params: { per_page: 500 } }),
      api.get('/rooms', { params: { per_page: 500 } }),
      api.get('/housekeeping/queue'),
    ])

    profitLoss.value = plRes.data?.data || profitLoss.value
    balanceSheet.value = bsRes.data?.data || balanceSheet.value
    cashFlow.value = cfRes.data?.data || cashFlow.value
    reconciliation.value = reconRes.data?.data || reconciliation.value
    auditTrail.value = {
      rows: normalizeAuditRows(auditRes.data?.data),
      meta: auditRes.data?.meta || auditTrail.value.meta,
    }
    coaAccounts.value = normalizeCoaAccounts(coaRes.data?.data)
    roomStatusRows.value = normalizeRoomStatusRows(roomsRes.data?.data, housekeepingRes.data?.data)
    if (!ledgerFilters.value.coaCode && coaAccounts.value.length) {
      ledgerFilters.value.coaCode = coaAccounts.value[0].code
    }
    if (ledgerFilters.value.coaCode && !generalLedger.value.account) {
      await loadGeneralLedger()
    }
    
  } catch (error) {
    reportResult.value = {
      tone: 'error',
      text: error?.response?.data?.message || 'Failed to load accounting reports from the server.',
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
      text: error?.response?.data?.message || 'Failed to load audit trail.',
    }
  } finally {
    loading.value = false
  }
}

const loadGeneralLedger = async () => {
  if (!ledgerFilters.value.coaCode) {
    reportResult.value = {
      tone: 'error',
      text: 'Select a COA account first to view the general ledger.',
    }
    return
  }

  loadingLedger.value = true
  reportResult.value = { tone: '', text: '' }

  try {
    const response = await api.get('/reports/general-ledger', {
      params: {
        coa_code: ledgerFilters.value.coaCode,
        from_date: ledgerFilters.value.fromDate,
        to_date: ledgerFilters.value.toDate,
      },
    })

    generalLedger.value = response.data?.data || generalLedger.value
  } catch (error) {
    reportResult.value = {
      tone: 'error',
      text: error?.response?.data?.message || 'Failed to load the general ledger report.',
    }
  } finally {
    loadingLedger.value = false
  }
}

const syncActiveTabFromRoute = () => {
  const requestedTab = String(route.query.tab ?? '').trim()
  if (reportTabs.some((item) => item.id === requestedTab)) {
    activeTab.value = requestedTab
  }
}

const setActiveTab = async (tabId) => {
  activeTab.value = tabId
  await router.replace({
    query: {
      ...route.query,
      tab: tabId,
    },
  })
}

onMounted(() => {
  syncActiveTabFromRoute()
  loadAllReports()
})

watch(
  () => route.query.tab,
  () => {
    syncActiveTabFromRoute()
  },
)


const runAccountingSync = async () => {
  loading.value = true
  reportResult.value = { tone: '', text: '' }

  try {
    const response = await api.post('/accounting/sync-history')
    reportResult.value = {
      tone: 'success',
      text: response.data?.message || 'Historical accounting sync completed successfully.',
    }
    await loadAllReports()
  } catch (error) {
    reportResult.value = {
      tone: 'error',
      text: error?.response?.data?.message || 'Failed to run the historical accounting sync.',
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

const exportProfitLossExcel = () => {
  const revenueRows = buildCategoryRows('revenue', profitLoss.value.revenues)
  const expenseRows = buildCategoryRows('expense', profitLoss.value.expenses)
  const statementSheet = buildStatementSheet(
    'PROFIT & LOSS STATEMENT',
    [
      {
        title: 'Revenue',
        items: revenueRows,
        totalLabel: 'Total Revenue',
        totalValue: profitLoss.value.total_revenue,
      },
      {
        title: 'Expenses',
        items: expenseRows,
        totalLabel: 'Total Expenses',
        totalValue: profitLoss.value.total_expense,
      },
    ],
    [
      { label: 'Net Profit', value: profitLoss.value.net_profit },
    ],
  )

  downloadWorkbook('profit-loss-report.xlsx', [
    {
      name: 'Profit & Loss',
      ...statementSheet,
    },
  ])
}

const exportBalanceSheetExcel = () => {
  const assetRows = buildCategoryRows('asset', balanceSheet.value.assets)
  const liabilityRows = buildCategoryRows('liability', balanceSheet.value.liabilities)
  const equityRows = [
    ...buildCategoryRows('equity', balanceSheet.value.equities),
    ...balanceSheet.value.equities
      .filter((item) => item.code === 'CURRENT-YEAR')
      .map((item) => ({
        code: item.code,
        name: item.name,
        normalBalance: item.normal_balance,
        balance: Number(item.balance || 0),
      })),
  ]

  const rightRows = [
    ...liabilityRows.map((item) => ({
      section: 'Liabilities',
      ...item,
    })),
    {
      section: 'Liabilities',
      code: '',
      name: 'Total Liabilities',
      normalBalance: '',
      balance: liabilityRows.reduce((total, item) => total + Number(item.balance || 0), 0),
    },
    ...equityRows.map((item) => ({
      section: 'Equity',
      ...item,
    })),
    {
      section: 'Equity',
      code: '',
      name: 'Total Equity',
      normalBalance: '',
      balance: equityRows.reduce((total, item) => total + Number(item.balance || 0), 0),
    },
    {
      section: 'Summary',
      code: '',
      name: 'Total Liabilities & Equity',
      normalBalance: '',
      balance: balanceSheet.value.total_liability_and_equity,
    },
  ]

  const maxRows = Math.max(assetRows.length + 1, rightRows.length)
  const sheetRows = [
    ['BALANCE SHEET'],
    [`Generated at: ${getExportTimestamp()}`],
    [],
    ['Assets', '', '', '', 'Liabilities & Equity', '', '', ''],
    ['No', 'COA Code', 'Account Name', 'Balance', 'No', 'COA Code', 'Account Name', 'Balance'],
  ]

  for (let index = 0; index < maxRows; index += 1) {
    const leftItem = assetRows[index] ?? null
    const rightItem = rightRows[index] ?? null

    const leftRow = leftItem
      ? [index + 1, leftItem.code, leftItem.name, Number(leftItem.balance || 0)]
      : ['', '', '', '']

    const rightRow = rightItem
      ? [index + 1, rightItem.code, rightItem.name, Number(rightItem.balance || 0)]
      : ['', '', '', '']

    if (rightItem?.section === 'Equity' && index > 0 && rightRows[index - 1]?.section !== 'Equity') {
      sheetRows.push(['', '', '', '', 'Equity', '', '', ''])
      sheetRows.push(['No', 'COA Code', 'Account Name', 'Balance', '', '', '', ''])
    }

    sheetRows.push([...leftRow, ...rightRow])
  }

  sheetRows.push(['', '', 'Total Assets', Number(balanceSheet.value.total_asset || 0), '', '', '', ''])

  downloadWorkbook('balance-sheet-report.xlsx', [
    {
      name: 'Balance Sheet',
      rows: sheetRows,
      merges: [
        { s: { r: 0, c: 0 }, e: { r: 0, c: 7 } },
        { s: { r: 1, c: 0 }, e: { r: 1, c: 7 } },
        { s: { r: 3, c: 0 }, e: { r: 3, c: 3 } },
        { s: { r: 3, c: 4 }, e: { r: 3, c: 7 } },
      ],
      cols: [
        { wch: 8 },
        { wch: 14 },
        { wch: 30 },
        { wch: 16 },
        { wch: 8 },
        { wch: 14 },
        { wch: 30 },
        { wch: 18 },
      ],
      amountCols: [3, 7],
      dataStartRow: 4,
    },
  ])
}

const exportCashFlowExcel = () => {
  const cashAccountRows = buildCashAccountRows()
  const summarySheet = {
    rows: [
      ['CASH FLOW STATEMENT'],
      [`Generated at: ${getExportTimestamp()}`],
      [],
      ['No', 'COA Code', 'Account Name', 'Normal Balance', 'Balance', 'Inflow', 'Outflow', 'Net Movement'],
      ...cashAccountRows.map((item, index) => [
        index + 1,
        item.code,
        item.name,
        item.normalBalance,
        item.balance,
        item.inflow,
        item.outflow,
        item.netMovement,
      ]),
      ['', '', 'Total', '', '', cashFlow.value.total_inflow, cashFlow.value.total_outflow, cashFlow.value.net_cash_flow],
    ],
    merges: [
      { s: { r: 0, c: 0 }, e: { r: 0, c: 7 } },
      { s: { r: 1, c: 0 }, e: { r: 1, c: 7 } },
    ],
    cols: [
      { wch: 8 },
      { wch: 16 },
      { wch: 34 },
      { wch: 18 },
      { wch: 16 },
      { wch: 16 },
      { wch: 16 },
      { wch: 18 },
    ],
    amountCols: [4, 5, 6, 7],
    dataStartRow: 3,
  }

  downloadWorkbook('cash-flow-report.xlsx', [
    {
      name: 'Cash Flow',
      ...summarySheet,
    },
    {
      name: 'Cash Inflows',
      rows: [
        ['CASH INFLOW DETAILS'],
        [`Generated at: ${getExportTimestamp()}`],
        [],
        ['No', 'Date', 'COA', 'Reference Description', 'Amount'],
        ...cashFlow.value.inflows.map((item, index) => [index + 1, item.date, item.coa, item.description, Number(item.amount || 0)]),
      ],
      merges: [
        { s: { r: 0, c: 0 }, e: { r: 0, c: 4 } },
        { s: { r: 1, c: 0 }, e: { r: 1, c: 4 } },
      ],
      cols: [{ wch: 8 }, { wch: 14 }, { wch: 16 }, { wch: 42 }, { wch: 16 }],
      amountCols: [4],
      dataStartRow: 3,
    },
    {
      name: 'Cash Outflows',
      rows: [
        ['CASH OUTFLOW DETAILS'],
        [`Generated at: ${getExportTimestamp()}`],
        [],
        ['No', 'Date', 'COA', 'Reference Description', 'Amount'],
        ...cashFlow.value.outflows.map((item, index) => [index + 1, item.date, item.coa, item.description, Number(item.amount || 0)]),
      ],
      merges: [
        { s: { r: 0, c: 0 }, e: { r: 0, c: 4 } },
        { s: { r: 1, c: 0 }, e: { r: 1, c: 4 } },
      ],
      cols: [{ wch: 8 }, { wch: 14 }, { wch: 16 }, { wch: 42 }, { wch: 16 }],
      amountCols: [4],
      dataStartRow: 3,
    },
  ])
}

const exportGeneralLedgerExcel = () => {
  if (!generalLedger.value.account) {
    return
  }

  downloadWorkbook(`general-ledger-${generalLedger.value.account.code}.xlsx`, [
    {
      name: 'General Ledger',
      rows: [
        ['GENERAL LEDGER'],
        [`Account: ${generalLedger.value.account.code} - ${generalLedger.value.account.name}`],
        [`Period: ${generalLedger.value.period.from || '-'} to ${generalLedger.value.period.to || '-'}`],
        [],
        ['Opening Balance', '', '', '', '', Number(generalLedger.value.opening_balance || 0)],
        ['No', 'Date', 'Journal No.', 'Description', 'Debit', 'Credit', 'Running Balance'],
        ...generalLedger.value.entries.map((item, index) => [
          index + 1,
          item.date,
          item.journalNo,
          item.description,
          Number(item.debit || 0),
          Number(item.credit || 0),
          Number(item.balance || 0),
        ]),
        ['', '', '', 'Total', Number(generalLedger.value.total_debit || 0), Number(generalLedger.value.total_credit || 0), Number(generalLedger.value.closing_balance || 0)],
      ],
      merges: [
        { s: { r: 0, c: 0 }, e: { r: 0, c: 6 } },
        { s: { r: 1, c: 0 }, e: { r: 1, c: 6 } },
        { s: { r: 2, c: 0 }, e: { r: 2, c: 6 } },
      ],
      cols: [
        { wch: 8 },
        { wch: 14 },
        { wch: 18 },
        { wch: 42 },
        { wch: 16 },
        { wch: 16 },
        { wch: 18 },
      ],
      amountCols: [4, 5, 6],
      dataStartRow: 4,
    },
  ])
}

const exportRoomStatusExcel = () => {
  downloadWorkbook('room-status-report.xlsx', [
    {
      name: 'Room Status',
      rows: [
        ['ROOM STATUS REPORT'],
        [`Generated at: ${getExportTimestamp()}`],
        [],
        ['No', 'Room No.', 'Room Name', 'Room Type', 'Floor', 'Current Status', 'Housekeeping', 'HK Team', 'Remarks'],
        ...filteredRoomStatusRows.value.map((item, index) => [
          index + 1,
          item.code,
          item.name,
          item.type,
          item.floor,
          item.status,
          item.housekeepingStatus,
          item.housekeepingTeam,
          item.note,
        ]),
      ],
      merges: [
        { s: { r: 0, c: 0 }, e: { r: 0, c: 8 } },
        { s: { r: 1, c: 0 }, e: { r: 1, c: 8 } },
      ],
      cols: [
        { wch: 8 },
        { wch: 12 },
        { wch: 24 },
        { wch: 22 },
        { wch: 10 },
        { wch: 18 },
        { wch: 24 },
        { wch: 14 },
        { wch: 28 },
      ],
      dataStartRow: 3,
    },
  ])
}

const printRoomStatusReport = () => {
  if (typeof window === 'undefined') {
    return
  }

  const rows = filteredRoomStatusRows.value
    .map(
      (item) => `
        <tr>
          <td>${item.code}</td>
          <td>${item.name}</td>
          <td>${item.type}</td>
          <td>${item.floor}</td>
          <td>${item.status}</td>
          <td>${item.housekeepingStatus}</td>
          <td>${item.housekeepingTeam}</td>
          <td>${item.note}</td>
        </tr>
      `,
    )
    .join('')

  const printWindow = window.open('', '_blank', 'width=1280,height=900')
  if (!printWindow) {
    return
  }

  printWindow.document.write(`
    <html>
      <head>
        <title>Room Status Report</title>
        <style>
          body { font-family: Arial, sans-serif; padding: 24px; color: #1f2937; }
          h1 { margin: 0 0 4px; }
          p { margin: 0 0 16px; color: #6b7280; }
          table { width: 100%; border-collapse: collapse; }
          th, td { border: 1px solid #d1d5db; padding: 8px; text-align: left; vertical-align: top; font-size: 12px; }
          th { background: #f3f4f6; }
        </style>
      </head>
      <body>
        <h1>Room Status Report</h1>
        <p>Generated at: ${getExportTimestamp()}</p>
        <table>
          <thead>
            <tr>
              <th>Room No.</th>
              <th>Room Name</th>
              <th>Room Type</th>
              <th>Floor</th>
              <th>Current Status</th>
              <th>Housekeeping</th>
              <th>HK Team</th>
              <th>Remarks</th>
            </tr>
          </thead>
          <tbody>${rows}</tbody>
        </table>
      </body>
    </html>
  `)
  printWindow.document.close()
  printWindow.focus()
  printWindow.print()
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
      <LoadingState v-if="loading" label="Preparing accounting reports..." overlay />

      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Accounting reports based on COA</p>
          <h3>General ledger reports</h3>
        </div>
        <div class="kpi-inline">
          <button class="action-button primary" @click="loadAllReports">Refresh</button>
        </div>
      </div>

      <div class="table-toolbar">
        <div class="toolbar-tabs">
          <button
            v-for="item in reportTabs"
            :key="item.id"
            class="toolbar-tab"
            :class="{ active: activeTab === item.id }"
            @click="setActiveTab(item.id)"
          >
            {{ item.label }}
          </button>
        </div>
      </div>

      <div v-if="reportResult.text" class="booking-feedback" :class="reportResult.tone">
        {{ reportResult.text }}
      </div>

      <!-- PROFIT & LOSS -->
      <div v-if="activeTab === 'labarugi'">
        <div class="modal-actions" style="margin-top: 1rem;">
          <button class="action-button" @click="exportProfitLossExcel">Export Excel</button>
        </div>

        <div class="booking-inline-summary" style="margin-top: 1rem;">
          <div class="note-cell">
            <strong>Net profit</strong>
            <p class="subtle" style="font-size: 1.2rem; color: var(--primary); font-weight: bold;">{{ toCurrency(profitLoss.net_profit) }}</p>
          </div>
          <div class="note-cell">
            <strong>Total revenue</strong>
            <p class="subtle">{{ toCurrency(profitLoss.total_revenue) }}</p>
          </div>
          <div class="note-cell">
            <strong>Total expenses</strong>
            <p class="subtle" style="color: darkred;">{{ toCurrency(profitLoss.total_expense) }}</p>
          </div>
        </div>

        <h4 style="margin: 1.5rem 0 0.5rem 0; color: var(--text-main);">Revenue</h4>
        <table v-smart-table class="data-table">
          <thead>
            <tr>
              <th>COA code</th>
              <th>Account name</th>
              <th>Normal balance</th>
              <th style="text-align: right;">Report value</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!loading && !profitLoss.revenues.length">
              <td colspan="4" class="table-empty-cell">No revenue journal data available yet.</td>
            </tr>
            <tr v-for="item in profitLoss.revenues" :key="item.code">
              <td>{{ item.code }}</td>
              <td><strong>{{ item.name }}</strong></td>
              <td>{{ item.normal_balance }}</td>
              <td style="text-align: right;">{{ toCurrency(item.balance) }}</td>
            </tr>
          </tbody>
        </table>

        <h4 style="margin: 1.5rem 0 0.5rem 0; color: var(--text-main);">Expense</h4>
        <table v-smart-table class="data-table">
          <thead>
            <tr>
              <th>COA code</th>
              <th>Account name</th>
              <th>Normal balance</th>
              <th style="text-align: right;">Report value</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!loading && !profitLoss.expenses.length">
              <td colspan="4" class="table-empty-cell">No expense journal data available yet.</td>
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

      <!-- BALANCE SHEET -->
      <div v-if="activeTab === 'neraca'">
        <div class="modal-actions" style="margin-top: 1rem;">
          <button class="action-button" @click="exportBalanceSheetExcel">Export Excel</button>
        </div>

        <div class="booking-inline-summary" style="margin-top: 1rem;">
          <div class="note-cell">
            <strong>Total assets</strong>
            <p class="subtle" style="font-size: 1.2rem; color: var(--primary); font-weight: bold;">{{ toCurrency(balanceSheet.total_asset) }}</p>
          </div>
          <div class="note-cell">
            <strong>Total liabilities & equity</strong>
            <p class="subtle" style="font-size: 1.2rem; font-weight: bold;">{{ toCurrency(balanceSheet.total_liability_and_equity) }}</p>
          </div>
        </div>

        <h4 style="margin: 1.5rem 0 0.5rem 0; color: var(--text-main);">Assets</h4>
        <table v-smart-table class="data-table">
          <thead>
            <tr>
              <th>COA code</th>
              <th>Account name</th>
              <th>Normal balance</th>
              <th style="text-align: right;">Report value</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!loading && !balanceSheet.assets.length">
              <td colspan="4" class="table-empty-cell">No asset journal data available yet.</td>
            </tr>
            <tr v-for="item in balanceSheet.assets" :key="item.code">
              <td>{{ item.code }}</td>
              <td><strong>{{ item.name }}</strong></td>
              <td>{{ item.normal_balance }}</td>
              <td style="text-align: right;">{{ toCurrency(item.balance) }}</td>
            </tr>
          </tbody>
        </table>

          <div class="report-balance-split" style="margin-top: 1rem;">
            <div class="report-balance-card">
              <h4 style="margin: 0 0 0.5rem 0; color: var(--text-main);">Liabilities</h4>
              <div class="table-scroll">
                <table v-smart-table class="data-table">
                  <thead>
                    <tr>
                      <th>Account</th>
                      <th style="text-align: right;">Value</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-if="!loading && !balanceSheet.liabilities.length">
                      <td colspan="2" class="table-empty-cell">No payable journals available yet.</td>
                    </tr>
                    <tr v-for="item in balanceSheet.liabilities" :key="item.code">
                      <td><strong>{{ item.name }}</strong> ({{ item.code }})</td>
                      <td style="text-align: right;">{{ toCurrency(item.balance) }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
            
            <div class="report-balance-card">
              <h4 style="margin: 0 0 0.5rem 0; color: var(--text-main);">Equity</h4>
              <div class="table-scroll">
                <table v-smart-table class="data-table">
                  <thead>
                    <tr>
                      <th>Account</th>
                      <th style="text-align: right;">Value</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-if="!loading && !balanceSheet.equities.length">
                      <td colspan="2" class="table-empty-cell">No equity journals available yet.</td>
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
      </div>

      <!-- CASH FLOW -->
      <div v-if="activeTab === 'aruskas'">
        <div class="modal-actions" style="margin-top: 1rem;">
          <button class="action-button" @click="exportCashFlowExcel">Export Excel</button>
        </div>

        <div class="booking-inline-summary" style="margin-top: 1rem;">
          <div class="note-cell">
            <strong>Net running cash inflow</strong>
            <p class="subtle" style="font-size: 1.2rem; color: var(--primary); font-weight: bold;">{{ toCurrency(cashFlow.net_cash_flow) }}</p>
          </div>
          <div class="note-cell">
            <strong>Total inflow (cash debit)</strong>
            <p class="subtle">{{ toCurrency(cashFlow.total_inflow) }}</p>
          </div>
          <div class="note-cell">
            <strong>Total outflow (cash credit)</strong>
            <p class="subtle" style="color: darkred;">{{ toCurrency(cashFlow.total_outflow) }}</p>
          </div>
        </div>

        <h4 style="margin: 1.5rem 0 0.5rem 0; color: var(--text-main);">Cash inflow history</h4>
        <table v-smart-table class="data-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>COA (Asset/Kas)</th>
              <th>Reference description</th>
              <th style="text-align: right;">Amount</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!loading && !cashFlow.inflows.length">
              <td colspan="4" class="table-empty-cell">No cash inflow journal history available yet.</td>
            </tr>
            <tr v-for="(item, index) in cashFlow.inflows" :key="index">
              <td>{{ item.date }}</td>
              <td><strong>{{ item.coa }}</strong></td>
              <td>{{ item.description }}</td>
              <td style="text-align: right;">{{ toCurrency(item.amount) }}</td>
            </tr>
          </tbody>
        </table>

        <h4 style="margin: 1.5rem 0 0.5rem 0; color: var(--text-main);">Cash outflow history</h4>
        <table v-smart-table class="data-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>COA (Asset/Kas)</th>
              <th>Reference description</th>
              <th style="text-align: right;">Amount</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!loading && !cashFlow.outflows.length">
              <td colspan="4" class="table-empty-cell">No cash outflow journal history available yet.</td>
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

      <div v-if="activeTab === 'bukubesar'">
        <LoadingState v-if="loadingLedger" label="Loading general ledger..." overlay />

        <div class="table-toolbar" style="margin-top: 1rem;">
          <div class="utility-group">
            <select v-model="ledgerFilters.coaCode" class="form-control" style="min-width: 220px;">
              <option disabled value="">Select COA account</option>
              <option v-for="item in coaAccounts" :key="item.code" :value="item.code">
                {{ item.code }} - {{ item.name }}
              </option>
            </select>
            <input v-model="ledgerFilters.fromDate" class="form-control" type="date" />
            <input v-model="ledgerFilters.toDate" class="form-control" type="date" />
          </div>
          <div class="utility-group">
            <button class="action-button primary" @click="loadGeneralLedger">Load ledger</button>
            <button class="action-button" :disabled="!generalLedger.account" @click="exportGeneralLedgerExcel">Export Excel</button>
          </div>
        </div>

        <div v-if="generalLedger.account" class="booking-inline-summary" style="margin-top: 1rem;">
          <div class="note-cell">
            <strong>Account</strong>
            <p class="subtle">{{ generalLedger.account.code }} - {{ generalLedger.account.name }}</p>
          </div>
          <div class="note-cell">
            <strong>Opening balance</strong>
            <p class="subtle">{{ toCurrency(generalLedger.opening_balance) }}</p>
          </div>
          <div class="note-cell">
            <strong>Closing balance</strong>
            <p class="subtle" style="font-size: 1.1rem; color: var(--primary); font-weight: bold;">{{ toCurrency(generalLedger.closing_balance) }}</p>
          </div>
        </div>

        <div v-if="generalLedger.account" class="booking-inline-summary" style="margin-top: 1rem;">
          <div class="note-cell">
            <strong>Period</strong>
            <p class="subtle">{{ generalLedger.period.from || '-' }} to {{ generalLedger.period.to || '-' }}</p>
          </div>
          <div class="note-cell">
            <strong>Total debit</strong>
            <p class="subtle">{{ toCurrency(generalLedger.total_debit) }}</p>
          </div>
          <div class="note-cell">
            <strong>Total credit</strong>
            <p class="subtle">{{ toCurrency(generalLedger.total_credit) }}</p>
          </div>
        </div>

        <table v-smart-table class="data-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Journal No.</th>
              <th>Description</th>
              <th>Debit</th>
              <th>Credit</th>
              <th>Running Balance</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="generalLedger.account">
              <td>{{ generalLedger.period.from || '-' }}</td>
              <td>-</td>
              <td><strong>Opening Balance</strong></td>
              <td>{{ toCurrency(0) }}</td>
              <td>{{ toCurrency(0) }}</td>
              <td><strong>{{ toCurrency(generalLedger.opening_balance) }}</strong></td>
            </tr>
            <tr v-if="generalLedger.account && !generalLedger.entries.length">
              <td colspan="6" class="table-empty-cell">No ledger transactions found for the selected account and period.</td>
            </tr>
            <tr v-for="item in generalLedger.entries" :key="item.id">
              <td>{{ item.date }}</td>
              <td><strong>{{ item.journalNo }}</strong></td>
              <td>{{ item.description }}</td>
              <td>{{ toCurrency(item.debit) }}</td>
              <td>{{ toCurrency(item.credit) }}</td>
              <td><strong>{{ toCurrency(item.balance) }}</strong></td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="activeTab === 'roomstatus'">
        <div class="booking-inline-summary" style="margin-top: 1rem;">
          <div class="note-cell">
            <strong>Total rooms</strong>
            <p class="subtle" style="font-size: 1.2rem; color: var(--primary); font-weight: bold;">{{ roomStatusRows.length }}</p>
          </div>
          <div class="note-cell">
            <strong>Rooms in housekeeping</strong>
            <p class="subtle">{{ roomStatusRows.filter((item) => item.housekeepingStatus !== 'No active task').length }}</p>
          </div>
          <div class="note-cell">
            <strong>Visible rows</strong>
            <p class="subtle">{{ filteredRoomStatusRows.length }}</p>
          </div>
        </div>

        <div class="table-toolbar" style="margin-top: 1rem;">
          <div class="utility-group">
            <input
              v-model="roomReportSearch"
              class="toolbar-search"
              placeholder="Search room / type / status / floor"
            />
          </div>
          <div class="utility-group">
            <button class="action-button primary" @click="loadAllReports">Refresh</button>
            <button class="action-button" @click="exportRoomStatusExcel">Export Excel</button>
            <button class="action-button" @click="printRoomStatusReport">Export PDF</button>
          </div>
        </div>

        <table v-smart-table class="data-table">
          <thead>
            <tr>
              <th>Room No.</th>
              <th>Room Name</th>
              <th>Room Type</th>
              <th>Floor</th>
              <th>Current Status</th>
              <th>Housekeeping</th>
              <th>HK Team</th>
              <th>Remarks</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!loading && !filteredRoomStatusRows.length">
              <td colspan="8" class="table-empty-cell">No room status rows are available for the current report.</td>
            </tr>
            <tr v-for="item in filteredRoomStatusRows" :key="item.code">
              <td><strong>{{ item.code }}</strong></td>
              <td>{{ item.name }}</td>
              <td>{{ item.type }}</td>
              <td>{{ item.floor }}</td>
              <td>{{ item.status }}</td>
              <td>{{ item.housekeepingStatus }}</td>
              <td>{{ item.housekeepingTeam }}</td>
              <td>{{ item.note }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="activeTab === 'rekonsiliasi'">
        <div class="booking-inline-summary" style="margin-top: 1rem;">
          <div class="note-cell">
            <strong>Bookings with issues</strong>
            <p class="subtle" style="font-size: 1.2rem; color: var(--primary); font-weight: bold;">{{ reconciliation.summary.booking_issue_count }}</p>
          </div>
          <div class="note-cell">
            <strong>Payments with issues</strong>
            <p class="subtle" style="font-size: 1.2rem; color: darkred; font-weight: bold;">{{ reconciliation.summary.payment_issue_count }}</p>
          </div>
          <div class="note-cell">
            <strong>Records checked</strong>
            <p class="subtle">{{ reconciliation.summary.bookings_checked }} booking(s) | {{ reconciliation.summary.payments_checked }} payment(s)</p>
          </div>
        </div>

        <div class="modal-actions" style="margin: 1rem 0;">
          <button class="action-button primary" @click="runAccountingSync">Sync historical accounting</button>
          <button class="action-button" @click="loadAllReports">Refresh audit</button>
          <button class="action-button" @click="exportReconciliationCsv">Export Excel (CSV)</button>
          <button class="action-button" @click="printReconciliationReport">Print audit</button>
        </div>

        <h4 style="margin: 1.5rem 0 0.5rem 0; color: var(--text-main);">Booking Reconciliation</h4>
        <table v-smart-table class="data-table">
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
              <td colspan="7" class="table-empty-cell">No booking audit data available yet.</td>
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
        <table v-smart-table class="data-table">
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
              <td colspan="7" class="table-empty-cell">No payment audit data available yet.</td>
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
            <strong>Current page</strong>
            <p class="subtle">{{ auditTrail.meta.current_page }} / {{ auditTrail.meta.last_page }}</p>
          </div>
          <div class="note-cell">
            <strong>Active filter</strong>
            <p class="subtle">{{ auditModule || 'All modules' }} | {{ auditSearch || 'No keyword' }}</p>
          </div>
        </div>

        <div class="table-toolbar" style="margin-top: 1rem;">
          <div class="utility-group">
            <input v-model="auditSearch" class="toolbar-search" placeholder="Search user, entity, or description..." />
            <select v-model="auditModule" class="form-control" style="min-width: 180px;">
              <option v-for="item in auditModules" :key="item.value" :value="item.value">{{ item.label }}</option>
            </select>
          </div>
          <div class="utility-group">
            <button class="action-button primary" @click="loadAuditTrail">Filter audit</button>
            <button class="action-button" @click="exportAuditTrailCsv">Export audit</button>
          </div>
        </div>

        <table v-smart-table class="data-table">
          <thead>
            <tr>
              <th>Time</th>
              <th>User</th>
              <th>Module</th>
              <th>Action</th>
              <th>Entity</th>
              <th>Description</th>
              <th>IP</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!loading && !auditTrail.rows.length">
              <td colspan="7" class="table-empty-cell">No audit trail data available yet.</td>
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

