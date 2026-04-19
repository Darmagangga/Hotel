<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '../services/api'
import BaseEChart from '../components/BaseEChart.vue'
import { useHotelStore } from '../stores/hotel'

const hotel = useHotelStore()
const loading = ref(false)
const errorMessage = ref('')
const activePeriod = ref('today')
const customStart = ref('')
const customEnd = ref('')
const appliedRangeLabel = ref('')
const appliedPeriodLabel = ref('Today')
const generatedAt = ref('')
const roomRows = ref([])
const housekeepingQueue = ref([])
const bookingRows = ref([])

const periodOptions = [
  { value: 'today', label: 'Today' },
  { value: 'week', label: 'This week' },
  { value: 'month', label: 'This month' },
  { value: 'custom', label: 'Custom' },
]

const dashboard = ref({
  closingSummary: {},
  balanceSheetAssets: [],
  profitLossRevenues: [],
  ownerFinancials: [],
  overview: [],
  dailyControl: [],
  revenueMix: [],
  vendorPayables: [],
  arrivalWatch: [],
  cashierQueue: [],
  channelPerformance: [],
  roomTypePerformance: [],
  liveMovement: [],
  departmentNotes: [],
  annualRevenueSeries: [],
})

const buildQueryParams = () => {
  const params = { period: activePeriod.value }

  if (activePeriod.value === 'custom') {
    if (!customStart.value || !customEnd.value) {
      throw new Error('Select a start date and end date for the custom range.')
    }

    params.start_date = customStart.value
    params.end_date = customEnd.value
  }

  return params
}

const findMetric = (items, pattern) =>
  (Array.isArray(items) ? items : []).find((item) => String(item?.label ?? '').toLowerCase().includes(pattern))

const parseDisplayNumber = (value) => {
  const raw = String(value ?? '')
  const negative = raw.includes('-')
  const digits = raw.replace(/[^0-9]/g, '')

  if (!digits) {
    return 0
  }

  const parsed = Number(digits)
  return negative ? parsed * -1 : parsed
}

const toCompactNumber = (value) =>
  new Intl.NumberFormat('en-US', {
    notation: 'compact',
    compactDisplay: 'short',
    maximumFractionDigits: 1,
  }).format(Number(value ?? 0))

const formatCurrency = (value) =>
  new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
  }).format(Number(value ?? 0))

const toPercent = (value, total) => {
  if (!total) {
    return 0
  }

  return Math.round((value / total) * 100)
}

const normalizeRoomStatus = (value) => String(value ?? '').trim().toLowerCase()

const roomStatusMeta = {
  available: { label: 'Available', color: '#7fa64a' },
  occupied: { label: 'Occupied', color: '#2d4d22' },
  dirty: { label: 'Dirty', color: '#c7942f' },
  cleaning: { label: 'Cleaning', color: '#5e9a96' },
  blocked: { label: 'Blocked', color: '#8a6f5a' },
  maintenance: { label: 'Maintenance', color: '#8c5a5a' },
  inactive: { label: 'Inactive', color: '#8b949e' },
}

const reservationStatusMeta = {
  Tentative: { color: '#d7c56f' },
  Confirmed: { color: '#7fa64a' },
  'Checked-in': { color: '#2d4d22' },
  'Checked-out': { color: '#5e9a96' },
  Cancelled: { color: '#c46b6b' },
  'No-Show': { color: '#8b949e' },
}

const buildLinePath = (values, width = 320, height = 96) => {
  if (!values.length) {
    return ''
  }

  const min = Math.min(...values)
  const max = Math.max(...values)
  const spread = Math.max(max - min, 1)

  return values
    .map((value, index) => {
      const x = values.length === 1 ? width / 2 : (index / (values.length - 1)) * width
      const y = height - ((value - min) / spread) * (height - 12) - 6
      return `${index === 0 ? 'M' : 'L'} ${x.toFixed(2)} ${y.toFixed(2)}`
    })
    .join(' ')
}

const buildAreaPath = (linePath, values, width = 320, height = 96) => {
  if (!values.length || !linePath) {
    return ''
  }

  const firstX = 0
  const lastX = values.length === 1 ? width / 2 : width
  return `${linePath} L ${lastX} ${height} L ${firstX} ${height} Z`
}

const loadDashboardData = async () => {
  loading.value = true
  errorMessage.value = ''

  try {
    const params = buildQueryParams()
    const [dashboardResponse, vendorPayablesResponse, balanceSheetResponse, profitLossResponse, roomsResponse, housekeepingResponse, bookingsResponse] = await Promise.allSettled([
      api.get('/dashboard/owner', { params }),
      api.get('/reports/vendor-payables'),
      api.get('/reports/balance-sheet'),
      api.get('/reports/profit-loss'),
      api.get('/rooms', { params: { per_page: 500 } }),
      api.get('/housekeeping/queue'),
      api.get('/bookings', { params: { per_page: 100 } }),
    ])

    if (dashboardResponse.status !== 'fulfilled') {
      throw dashboardResponse.reason
    }

    const payload = dashboardResponse.value.data?.data ?? {}

    dashboard.value = {
      closingSummary: payload.closingSummary ?? {},
      balanceSheetAssets: balanceSheetResponse.status === 'fulfilled' && Array.isArray(balanceSheetResponse.value.data?.data?.assets)
        ? balanceSheetResponse.value.data.data.assets
        : [],
      profitLossRevenues: profitLossResponse.status === 'fulfilled' && Array.isArray(profitLossResponse.value.data?.data?.revenues)
        ? profitLossResponse.value.data.data.revenues
        : [],
      ownerFinancials: Array.isArray(payload.ownerFinancials) ? payload.ownerFinancials : [],
      overview: Array.isArray(payload.overview) ? payload.overview : [],
      dailyControl: Array.isArray(payload.dailyControl) ? payload.dailyControl : [],
      revenueMix: Array.isArray(payload.revenueMix) ? payload.revenueMix : [],
      vendorPayables: vendorPayablesResponse.status === 'fulfilled' && Array.isArray(vendorPayablesResponse.value.data?.data?.vendors)
        ? vendorPayablesResponse.value.data.data.vendors
        : [],
      arrivalWatch: Array.isArray(payload.arrivalWatch) ? payload.arrivalWatch : [],
      cashierQueue: Array.isArray(payload.cashierQueue) ? payload.cashierQueue : [],
      channelPerformance: Array.isArray(payload.channelPerformance) ? payload.channelPerformance : [],
      roomTypePerformance: Array.isArray(payload.roomTypePerformance) ? payload.roomTypePerformance : [],
      liveMovement: Array.isArray(payload.liveMovement) ? payload.liveMovement : [],
      departmentNotes: Array.isArray(payload.departmentNotes) ? payload.departmentNotes : [],
      annualRevenueSeries: Array.isArray(payload.annualRevenueSeries) ? payload.annualRevenueSeries : [],
    }

    roomRows.value = roomsResponse.status === 'fulfilled' && Array.isArray(roomsResponse.value.data?.data)
      ? roomsResponse.value.data.data
      : []

    housekeepingQueue.value = housekeepingResponse.status === 'fulfilled' && Array.isArray(housekeepingResponse.value.data?.data)
      ? housekeepingResponse.value.data.data
      : []

    bookingRows.value = bookingsResponse.status === 'fulfilled' && Array.isArray(bookingsResponse.value.data?.data)
      ? bookingsResponse.value.data.data
      : []

    appliedRangeLabel.value = payload.rangeLabel ?? ''
    appliedPeriodLabel.value = payload.periodLabel ?? 'Today'
    generatedAt.value = payload.generatedAt ?? ''
    hotel.setOverview(dashboard.value.overview)
    hotel.setBusinessDate(payload.businessDate)
    hotel.setCurrentDateLabel(payload.currentDateLabel)
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : 'Failed to load the owner dashboard.'
    console.error('Failed to load owner dashboard:', error)
  } finally {
    loading.value = false
  }
}

const applyPreset = async (period) => {
  activePeriod.value = period

  if (period !== 'custom') {
    await loadDashboardData()
  }
}

const applyCustomRange = async () => {
  activePeriod.value = 'custom'
  await loadDashboardData()
}

const printDashboard = () => {
  window.print()
}

const arrivalsCount = computed(() => String(findMetric(dashboard.value.dailyControl, 'arrival')?.value ?? 0))
const departuresCount = computed(() => String(findMetric(dashboard.value.dailyControl, 'departure')?.value ?? 0))
const inHouseCount = computed(() => String(findMetric(dashboard.value.dailyControl, 'in house')?.value ?? 0))
const availableRoomsCount = computed(() => String(findMetric(dashboard.value.dailyControl, 'sellable')?.value ?? 0))
const occupiedRoomsCount = computed(() => {
  const occupancyNote = String(findMetric(dashboard.value.overview, 'occupancy')?.note ?? '')
  const matched = occupancyNote.match(/(\d+)\s+room occupied/i)
  return matched?.[1] ?? inHouseCount.value
})

const topMetrics = computed(() => [
  { label: 'Check In', value: arrivalsCount.value, tone: 'olive' },
  { label: 'Check Out', value: departuresCount.value, tone: 'amber' },
  { label: 'In House', value: inHouseCount.value, tone: 'teal' },
  { label: 'Available Room', value: availableRoomsCount.value, tone: 'green' },
  { label: 'Occupied Room', value: occupiedRoomsCount.value, tone: 'dark' },
])

const reservationMonthlySummary = computed(() => {
  const businessDate = String(hotel.currentBusinessDate ?? '').slice(0, 10)
  const [targetYear, targetMonth] = businessDate ? businessDate.split('-').map(Number) : [new Date().getFullYear(), new Date().getMonth() + 1]
  const monthlyRows = bookingRows.value.filter((item) => {
    const checkIn = String(item.checkIn ?? '').slice(0, 10)
    if (!checkIn) {
      return false
    }

    const [year, month] = checkIn.split('-').map(Number)
    return year === targetYear && month === targetMonth
  })

  const counts = new Map()
  monthlyRows.forEach((item) => {
    const status = String(item.status ?? '').trim() || 'Unknown'
    counts.set(status, (counts.get(status) ?? 0) + 1)
  })

  const orderedStatuses = ['Tentative', 'Confirmed', 'Checked-in', 'Checked-out', 'Cancelled', 'No-Show']
  return orderedStatuses
    .map((status) => ({
      label: status,
      value: counts.get(status) ?? 0,
      color: reservationStatusMeta[status]?.color ?? '#94a3b8',
    }))
    .filter((item) => item.value > 0)
})

const reservationMonthlyChartStyle = computed(() => {
  const rows = reservationMonthlySummary.value
  const total = rows.reduce((sum, item) => sum + Number(item.value ?? 0), 0)

  if (!total) {
    return { background: 'conic-gradient(#dce8cf 0deg 360deg)' }
  }

  let cursor = 0
  const slices = rows.map((item) => {
    const share = Number(item.value ?? 0) / total
    const start = cursor
    cursor += share * 360
    return `${item.color} ${start}deg ${cursor}deg`
  })

  return { background: `conic-gradient(${slices.join(', ')})` }
})

const reservationMonthlyChartOption = computed(() => ({
  color: reservationMonthlySummary.value.map((item) => item.color),
  tooltip: {
    trigger: 'item',
    appendToBody: true,
    confine: false,
    extraCssText: 'z-index: 99999;',
    formatter: ({ name, value, percent }) => `${name}<br/>${value} booking (${percent}%)`,
  },
  title: {
    text: String(reservationMonthlySummary.value.reduce((sum, item) => sum + Number(item.value ?? 0), 0)),
    left: 'center',
    top: 'center',
    textStyle: { fontSize: 18, fontWeight: 700, color: '#24351f' },
  },
  series: [
    {
      type: 'pie',
      radius: ['58%', '78%'],
      center: ['50%', '50%'],
      avoidLabelOverlap: false,
      label: { show: false },
      labelLine: { show: false },
      data: reservationMonthlySummary.value.length
        ? reservationMonthlySummary.value.map((item) => ({ name: item.label, value: item.value }))
        : [{ name: 'No data', value: 1, itemStyle: { color: '#dce8cf' } }],
    },
  ],
}))

const reservationSegments = computed(() => {
  const sourceRows = dashboard.value.channelPerformance.length ? dashboard.value.channelPerformance : dashboard.value.roomTypePerformance
  const rows = sourceRows.map((item, index) => ({
    label: item.channel ?? item.roomType ?? `Segment ${index + 1}`,
    count: Number(item.bookings ?? 0),
    color: ['#4b7f2c', '#7cab56', '#a8c982', '#d8e7bf', '#56793c'][index % 5],
  }))

  const total = rows.reduce((sum, item) => sum + item.count, 0)

  return {
    total,
    rows: rows.map((item) => ({
      ...item,
      share: toPercent(item.count, total),
    })),
  }
})

const reservationChartStyle = computed(() => {
  const rows = reservationSegments.value.rows.filter((item) => item.share > 0)
  if (!rows.length) {
    return { background: 'conic-gradient(#dce8cf 0deg 360deg)' }
  }

  let cursor = 0
  const slices = rows.map((item) => {
    const start = cursor
    cursor += (item.share / 100) * 360
    return `${item.color} ${start}deg ${cursor}deg`
  })

  return { background: `conic-gradient(${slices.join(', ')})` }
})

const departuresWatch = computed(() =>
  dashboard.value.liveMovement
    .filter((item) => String(item.eta ?? '').toLowerCase().includes('departure'))
    .slice(0, 4),
)

const guestsInHouse = computed(() => dashboard.value.liveMovement.slice(0, 4))

const roomStatusSummary = computed(() => {
  const counts = new Map()

  roomRows.value.forEach((item) => {
    const status = normalizeRoomStatus(item.status)
    counts.set(status, (counts.get(status) ?? 0) + 1)
  })

  const orderedStatuses = ['available', 'occupied', 'dirty', 'cleaning', 'blocked', 'maintenance', 'inactive']

  return orderedStatuses
    .filter((status) => (counts.get(status) ?? 0) > 0)
    .map((status) => ({
      key: status,
      label: roomStatusMeta[status]?.label ?? status,
      value: counts.get(status) ?? 0,
      color: roomStatusMeta[status]?.color ?? '#94a3b8',
    }))
})

const roomStatusChartStyle = computed(() => {
  const rows = roomStatusSummary.value
  const total = rows.reduce((sum, item) => sum + Number(item.value ?? 0), 0)

  if (!total) {
    return { background: 'conic-gradient(#dce8cf 0deg 360deg)' }
  }

  let cursor = 0
  const slices = rows.map((item) => {
    const share = Number(item.value ?? 0) / total
    const start = cursor
    cursor += share * 360
    return `${item.color} ${start}deg ${cursor}deg`
  })

  return { background: `conic-gradient(${slices.join(', ')})` }
})

const roomStatusChartOption = computed(() => ({
  color: roomStatusSummary.value.map((item) => item.color),
  tooltip: {
    trigger: 'item',
    appendToBody: true,
    confine: false,
    extraCssText: 'z-index: 99999;',
    formatter: ({ name, value, percent }) => `${name}<br/>${value} room (${percent}%)`,
  },
  title: {
    text: String(roomStatusSummary.value.reduce((sum, item) => sum + Number(item.value ?? 0), 0)),
    left: 'center',
    top: 'center',
    textStyle: { fontSize: 18, fontWeight: 700, color: '#24351f' },
  },
  series: [
    {
      type: 'pie',
      radius: ['58%', '78%'],
      center: ['50%', '50%'],
      avoidLabelOverlap: false,
      label: { show: false },
      labelLine: { show: false },
      data: roomStatusSummary.value.length
        ? roomStatusSummary.value.map((item) => ({ name: item.label, value: item.value }))
        : [{ name: 'No data', value: 1, itemStyle: { color: '#dce8cf' } }],
    },
  ],
}))

const housekeepingSummary = computed(() => {
  const pending = housekeepingQueue.value.filter((item) => String(item.status ?? '').toLowerCase() === 'pending').length
  const inProgress = housekeepingQueue.value.filter((item) => String(item.status ?? '').toLowerCase() === 'in progress').length
  const departures = Number(departuresCount.value ?? 0)
  const cleanRooms = roomRows.value.filter((item) => normalizeRoomStatus(item.status) === 'available').length

  return [
    { label: 'Dirty', value: pending },
    { label: 'Clean', value: cleanRooms },
    { label: 'Departure', value: departures },
    { label: 'In progress', value: inProgress },
  ]
})

const reservationTypeSummary = computed(() =>
  reservationSegments.value.rows.slice(0, 4).map((item) => ({
    label: item.label,
    value: item.count,
  })),
)

const progressCollection = computed(() => {
  const revenueRows = Array.isArray(dashboard.value.revenueMix) ? dashboard.value.revenueMix.filter((item) => parseDisplayNumber(item?.value) > 0) : []
  const profitLossRows = Array.isArray(dashboard.value.profitLossRevenues)
    ? dashboard.value.profitLossRevenues
        .filter((item) => Number(item?.balance ?? 0) > 0)
        .map((item) => ({
          label: item.name,
          value: formatCurrency(item.balance),
        }))
    : []
  const rows = revenueRows.length ? revenueRows : (profitLossRows.length ? profitLossRows : dashboard.value.ownerFinancials)
  return rows.slice(0, 5).map((item) => ({
    label: item.label,
    value: item.value,
    progress: Math.max(10, Math.min(Number(item.progress ?? 0) || toPercent(parseDisplayNumber(item.value), parseDisplayNumber(rows[0]?.value) || 1), 100)),
  }))
})

const vendorDebtRows = computed(() =>
  (Array.isArray(dashboard.value.vendorPayables) ? dashboard.value.vendorPayables : [])
    .filter((item) => Number(item.outstandingValue ?? 0) > 0)
    .sort((left, right) => Number(right.outstandingValue ?? 0) - Number(left.outstandingValue ?? 0))
    .slice(0, 6),
)

const vendorDebtChartRows = computed(() =>
  vendorDebtRows.value.map((item, index) => ({
    label: item.vendorName,
    value: Number(item.outstandingValue ?? 0),
    color: ['#6f9b42', '#3e6f8e', '#c7942f', '#8c5a5a', '#5e9a96', '#8a6f5a'][index % 6],
    subtitle: `${item.openBillCount ?? 0} open bill(s)`,
  })),
)

const vendorDebtChartOption = computed(() => ({
  color: vendorDebtChartRows.value.map((item) => item.color),
  tooltip: {
    trigger: 'item',
    appendToBody: true,
    confine: false,
    extraCssText: 'z-index: 99999;',
    formatter: ({ name, value, percent }) => `${name}<br/>${formatCurrency(value)} (${percent}%)`,
  },
  title: {
    text: formatCurrency(vendorDebtChartRows.value.reduce((sum, item) => sum + Number(item.value ?? 0), 0)),
    left: 'center',
    top: 'center',
    textStyle: { fontSize: 14, fontWeight: 700, color: '#24351f' },
  },
  series: [
    {
      type: 'pie',
      radius: ['58%', '78%'],
      center: ['50%', '50%'],
      avoidLabelOverlap: false,
      label: { show: false },
      labelLine: { show: false },
      data: vendorDebtChartRows.value.length
        ? vendorDebtChartRows.value.map((item) => ({ name: item.label, value: item.value }))
        : [{ name: 'No data', value: 1, itemStyle: { color: '#dce8cf' } }],
    },
  ],
}))

const cashBankSummary = computed(() => {
  const assets = Array.isArray(dashboard.value.balanceSheetAssets) ? dashboard.value.balanceSheetAssets : []
  let cash = 0
  let bank = 0

  assets.forEach((item) => {
    const code = String(item.code ?? '').trim()
    const name = String(item.name ?? '').toLowerCase()
    const balance = Number(item.balance ?? 0)

    if (balance <= 0) {
      return
    }

    if (!code.startsWith('111')) {
      return
    }

    if (code === '111001' || name.includes('cash') || name.includes('kas')) {
      cash += balance
      return
    }

    bank += balance
  })

  return [
    { label: 'Cash', value: cash, color: '#6f9b42' },
    { label: 'Bank', value: bank, color: '#3e6f8e' },
  ].filter((item) => item.value > 0)
})

const cashBankChartOption = computed(() => ({
  color: cashBankSummary.value.map((item) => item.color),
  tooltip: {
    trigger: 'item',
    appendToBody: true,
    confine: false,
    extraCssText: 'z-index: 99999;',
    formatter: ({ name, value, percent }) => `${name}<br/>${formatCurrency(value)} (${percent}%)`,
  },
  title: {
    text: formatCurrency(cashBankSummary.value.reduce((sum, item) => sum + Number(item.value ?? 0), 0)),
    left: 'center',
    top: 'center',
    textStyle: { fontSize: 14, fontWeight: 700, color: '#24351f' },
  },
  series: [
    {
      type: 'pie',
      radius: ['58%', '78%'],
      center: ['50%', '50%'],
      avoidLabelOverlap: false,
      label: { show: false },
      labelLine: { show: false },
      data: cashBankSummary.value.length
        ? cashBankSummary.value.map((item) => ({ name: item.label, value: item.value }))
        : [{ name: 'No data', value: 1, itemStyle: { color: '#dce8cf' } }],
    },
  ],
}))

const revenueMixChartOption = computed(() => ({
  grid: { left: 10, right: 88, top: 10, bottom: 8, containLabel: true },
  tooltip: {
    trigger: 'axis',
    axisPointer: { type: 'shadow' },
    appendToBody: true,
    confine: false,
    extraCssText: 'z-index: 99999;',
    formatter: (params) => {
      const item = Array.isArray(params) ? params[0] : params
      return `${item.name}<br/>${formatCurrency(item.value)}`
    },
  },
  xAxis: {
    type: 'value',
    show: false,
    axisLabel: {
      color: '#6d7b67',
      formatter: (value) => toCompactNumber(value),
    },
    splitLine: { lineStyle: { color: '#edf2e6' } },
  },
  yAxis: {
    type: 'category',
    data: progressCollection.value.map((item) => item.label),
    axisLabel: { show: false },
    axisTick: { show: false },
    axisLine: { show: false },
  },
  series: [
    {
      type: 'bar',
      barMaxWidth: 22,
      data: progressCollection.value.map((item) => ({
        value: parseDisplayNumber(item.value),
        itemStyle: { color: '#6f9b42', borderRadius: [0, 8, 8, 0] },
      })),
      label: {
        show: true,
        position: 'right',
        color: '#566451',
        formatter: ({ dataIndex }) => progressCollection.value[dataIndex]?.value ?? '',
      },
    },
  ],
}))

const hotelRevenueChartRows = computed(() => {
  const rows = dashboard.value.annualRevenueSeries.map((item) => ({
    label: item.label,
    displayValue: item.totalRevenue,
    value: Number(item.totalRevenueValue ?? 0),
    roomRevenue: item.roomRevenue,
    roomRevenueValue: Number(item.roomRevenueValue ?? 0),
    addonRevenue: item.addonRevenue,
    addonRevenueValue: Number(item.addonRevenueValue ?? 0),
    color: '#5f8b32',
  }))

  const max = Math.max(...rows.map((item) => item.value), 1)

  return rows.map((item) => ({
    ...item,
    height: `${Math.max(10, (item.value / max) * 100)}%`,
    roomShare: item.value > 0 ? (item.roomRevenueValue / item.value) * 100 : 0,
    addonShare: item.value > 0 ? (item.addonRevenueValue / item.value) * 100 : 0,
  }))
})

const annualRevenueValues = computed(() => hotelRevenueChartRows.value.map((item) => Math.max(1, item.value)))
const annualRevenueLinePath = computed(() => buildLinePath(annualRevenueValues.value, 420, 150))
const annualRevenueAreaPath = computed(() => buildAreaPath(annualRevenueLinePath.value, annualRevenueValues.value, 420, 150))

const annualRevenueChartOption = computed(() => ({
  color: ['#4f6f2a', '#a7c57f', '#d1a64a'],
  tooltip: {
    trigger: 'axis',
    axisPointer: { type: 'cross' },
    appendToBody: true,
    confine: false,
    extraCssText: 'z-index: 99999;',
    formatter: (params) => {
      const rows = Array.isArray(params) ? params : [params]
      const title = rows[0]?.axisValueLabel ?? ''
      const lines = rows.map((item) => `${item.marker}${item.seriesName}: ${formatCurrency(item.value)}`)
      return [title, ...lines].join('<br/>')
    },
  },
  legend: {
    bottom: 0,
    textStyle: { color: '#5f6d59' },
  },
  grid: { left: 18, right: 18, top: 28, bottom: 48, containLabel: true },
  xAxis: {
    type: 'category',
    data: hotelRevenueChartRows.value.map((item) => item.label),
    axisLabel: { color: '#6d7b67' },
    axisLine: { lineStyle: { color: '#dfe7d4' } },
  },
  yAxis: {
    type: 'value',
    axisLabel: {
      color: '#6d7b67',
      formatter: (value) => toCompactNumber(value),
    },
    splitLine: { lineStyle: { color: '#edf2e6' } },
  },
  series: [
    {
      name: 'Room Revenue',
      type: 'bar',
      stack: 'revenue',
      emphasis: { focus: 'series' },
      data: hotelRevenueChartRows.value.map((item) => item.roomRevenueValue),
      itemStyle: { borderRadius: [6, 6, 0, 0] },
    },
    {
      name: 'Add-on Revenue',
      type: 'bar',
      stack: 'revenue',
      emphasis: { focus: 'series' },
      data: hotelRevenueChartRows.value.map((item) => item.addonRevenueValue),
      itemStyle: { borderRadius: [6, 6, 0, 0] },
    },
    {
      name: 'Total Revenue',
      type: 'line',
      smooth: true,
      symbolSize: 7,
      data: hotelRevenueChartRows.value.map((item) => item.value),
      lineStyle: { width: 3 },
    },
  ],
}))

const reservationTableRows = computed(() => {
  if (dashboard.value.arrivalWatch.length) {
    return dashboard.value.arrivalWatch.map((item, index) => ({
      id: `arr-${index}`,
      reservationNo: `AR-${String(index + 1).padStart(3, '0')}`,
      guest: item.guest,
      email: item.note,
      roomType: item.room,
      status: item.time,
      tone: 'success',
    }))
  }

  return dashboard.value.liveMovement.map((item, index) => ({
    id: `stay-${index}`,
    reservationNo: `IH-${String(index + 1).padStart(3, '0')}`,
    guest: item.guest,
    email: item.eta,
    roomType: item.room,
    status: item.status,
    tone: 'info',
  }))
})

const notificationRows = computed(() =>
  dashboard.value.departmentNotes.slice(0, 4).map((item, index) => ({
    id: `${item.department}-${index}`,
    title: item.department,
    text: item.note,
    tone: ['success', 'info', 'warning', 'danger'][index % 4],
  })),
)

const roomTableRows = computed(() =>
  roomRows.value.length
    ? roomRows.value.slice(0, 6).map((item) => ({
        id: item.code,
        roomNo: item.code,
        type: item.type,
        guest: dashboard.value.liveMovement.find((guest) => String(guest.room).includes(String(item.code)))?.guest ?? '-',
        ac: item.note?.includes('AC') ? 'AC' : 'AC',
        floor: item.floor ?? '-',
        bed: item.bed ?? '-',
        status: item.status,
      }))
    : dashboard.value.liveMovement.slice(0, 6).map((item, index) => ({
        id: `live-${index}`,
        roomNo: item.room,
        type: item.stay,
        guest: item.guest,
        ac: 'AC',
        floor: '-',
        bed: '-',
        status: item.status,
      })),
)

const surveyValues = computed(() => {
  const rows = dashboard.value.ownerFinancials.length ? dashboard.value.ownerFinancials : dashboard.value.revenueMix
  return rows.map((item) => Math.max(1, parseDisplayNumber(item.value))).slice(0, 6)
})

const surveyLinePath = computed(() => buildLinePath(surveyValues.value))
const surveyAreaPath = computed(() => buildAreaPath(surveyLinePath.value, surveyValues.value))

const occupancyBars = computed(() => {
  const items = [
    { label: 'Arrival', value: Number(arrivalsCount.value) },
    { label: 'Departure', value: Number(departuresCount.value) },
    { label: 'In House', value: Number(inHouseCount.value) },
    { label: 'Available', value: Number(availableRoomsCount.value) },
    { label: 'Occupied', value: Number(occupiedRoomsCount.value) },
    ...dashboard.value.roomTypePerformance.slice(0, 5).map((item) => ({
      label: item.roomType,
      value: Number(item.bookings ?? 0),
    })),
  ]

  const max = Math.max(...items.map((item) => item.value), 1)
  return items.map((item) => ({
    ...item,
    height: `${Math.max(14, (item.value / max) * 100)}%`,
  }))
})

const hasDashboardData = computed(() => {
  return [
    dashboard.value.overview,
    dashboard.value.dailyControl,
    dashboard.value.revenueMix,
    dashboard.value.arrivalWatch,
    dashboard.value.liveMovement,
    roomRows.value,
  ].some((collection) => Array.isArray(collection) && collection.length > 0)
})

onMounted(async () => {
  await loadDashboardData()
})
</script>

<template>
  <section class="dashboard-reference-shell">
    <div class="dashboard-topbar-card">
      <div>
        <p class="eyebrow-dark">Dashboard</p>
        <h2 class="dashboard-reference-title">Operations Snapshot</h2>
        <p class="subtle">Real-time business view using active dashboard, room, and housekeeping data.</p>
      </div>

      <div class="dashboard-reference-toolbar">
        <div class="dashboard-period-switch">
          <button
            v-for="option in periodOptions"
            :key="option.value"
            type="button"
            class="switch-chip"
            :class="{ active: activePeriod === option.value }"
            @click="applyPreset(option.value)"
          >
            {{ option.label }}
          </button>
        </div>
        <span class="dashboard-meta-pill">
          <strong>{{ appliedPeriodLabel }}</strong>
          {{ appliedRangeLabel || hotel.currentDateLabel }}
        </span>
        <button type="button" class="utility-button" @click="loadDashboardData">Refresh</button>
        <button type="button" class="utility-button" @click="printDashboard">Print</button>
      </div>
    </div>

    <div v-if="activePeriod === 'custom'" class="dashboard-custom-range dashboard-reference-range">
      <label class="field-stack">
        <span>Start date</span>
        <input v-model="customStart" type="date" class="form-control" />
      </label>
      <label class="field-stack">
        <span>End date</span>
        <input v-model="customEnd" type="date" class="form-control" />
      </label>
      <button type="button" class="action-button primary" @click="applyCustomRange">Apply</button>
    </div>

    <div v-if="generatedAt" class="dashboard-updated-label">Updated {{ generatedAt }}</div>

    <div v-if="errorMessage" class="booking-feedback error">
      {{ errorMessage }}
    </div>

    <section v-if="loading" class="panel-card dashboard-state-card">
      <div class="loading-state">
        <span class="loading-spinner"></span>
        <div>
          <strong>Refreshing dashboard</strong>
          <p class="subtle">Fetching bookings, rooms, and housekeeping signals.</p>
        </div>
      </div>
    </section>

    <section v-else-if="!hasDashboardData" class="panel-card dashboard-empty-card">
      <p class="eyebrow-dark">Empty dashboard</p>
      <h3>There is no activity for this period yet</h3>
      <p class="subtle">Try another period or post operational data so the dashboard can populate.</p>
    </section>

    <template v-else>
      <section class="dashboard-reference-kpis">
        <article v-for="metric in topMetrics" :key="metric.label" class="dashboard-reference-kpi" :class="`tone-${metric.tone}`">
          <span>{{ metric.label }}</span>
          <strong>{{ metric.value }}</strong>
          <i></i>
        </article>
      </section>

      <section class="dashboard-reference-grid three">
        <article class="panel-card dashboard-reference-card">
          <div class="dashboard-card-title-row">
            <h3>Room Status</h3>
            <span class="subtle">Live inventory</span>
          </div>

          <div class="dashboard-chart-panel dashboard-chart-panel-compact">
            <div class="dashboard-chart-surface dashboard-chart-compact">
              <BaseEChart :option="roomStatusChartOption" />
            </div>

            <div class="dashboard-stat-list">
              <div v-for="item in roomStatusSummary" :key="item.label" class="dashboard-stat-row">
                <span>
                  <i class="dashboard-inline-dot" :style="{ backgroundColor: item.color }"></i>
                  {{ item.label }}
                </span>
                <strong>{{ item.value }}</strong>
              </div>
            </div>
          </div>
        </article>

        <article class="panel-card dashboard-reference-card">
          <div class="dashboard-card-title-row">
            <h3>Reservation</h3>
            <span class="subtle">1 month status</span>
          </div>

          <div class="dashboard-chart-panel dashboard-chart-panel-compact">
            <div class="dashboard-chart-surface dashboard-chart-compact">
              <BaseEChart :option="reservationMonthlyChartOption" />
            </div>

            <div class="dashboard-stat-list">
              <div v-if="!reservationMonthlySummary.length" class="dashboard-stat-row">
                <span>No reservation data</span>
                <strong>0</strong>
              </div>
              <div v-for="item in reservationMonthlySummary" :key="item.label" class="dashboard-stat-row">
                <span>
                  <i class="dashboard-inline-dot" :style="{ backgroundColor: item.color }"></i>
                  {{ item.label }}
                </span>
                <strong>{{ item.value }}</strong>
              </div>
            </div>
          </div>
        </article>

        <article class="panel-card dashboard-reference-card">
          <div class="dashboard-card-title-row">
            <h3>Cash / Bank</h3>
            <span class="subtle">Closing composition</span>
          </div>

          <div class="dashboard-chart-panel dashboard-chart-panel-compact">
            <div class="dashboard-chart-surface dashboard-chart-compact">
              <BaseEChart :option="cashBankChartOption" />
            </div>

            <div class="dashboard-stat-list">
              <div v-if="!cashBankSummary.length" class="dashboard-stat-row">
                <span>No payment data</span>
                <strong>0</strong>
              </div>
              <div v-for="item in cashBankSummary" :key="item.label" class="dashboard-stat-row">
                <span>
                  <i class="dashboard-inline-dot" :style="{ backgroundColor: item.color }"></i>
                  {{ item.label }}
                </span>
                <strong>{{ formatCurrency(item.value) }}</strong>
              </div>
            </div>
          </div>
        </article>

        <article class="panel-card dashboard-reference-card dashboard-revenue-annual-card row-span-half">
          <div class="dashboard-card-title-row">
            <h3>Annual Revenue</h3>
            <span class="subtle">January - December</span>
          </div>

          <div v-if="!hotelRevenueChartRows.length" class="dashboard-stat-row">
            <span>No revenue data</span>
            <strong>0</strong>
          </div>

          <template v-else>
            <div class="dashboard-chart-surface dashboard-chart-tall">
              <BaseEChart :option="annualRevenueChartOption" />
            </div>
          </template>
        </article>

        <article class="panel-card dashboard-reference-card row-span-half">
          <div class="dashboard-card-title-row">
            <h3>Vendor Payables</h3>
            <span class="subtle">Outstanding payables</span>
          </div>

          <div class="dashboard-chart-panel dashboard-chart-panel-compact">
            <div class="dashboard-chart-surface dashboard-chart-compact">
              <BaseEChart :option="vendorDebtChartOption" />
            </div>

            <div class="dashboard-stat-list">
              <div v-if="!vendorDebtChartRows.length" class="dashboard-stat-row">
                <span>No vendor debt</span>
                <strong>0</strong>
              </div>
              <div v-for="item in vendorDebtChartRows" :key="item.label" class="dashboard-stat-row">
                <span>
                  <i class="dashboard-inline-dot" :style="{ backgroundColor: item.color }"></i>
                  {{ item.label }}
                  <small class="subtle" style="display:block;">{{ item.subtitle }}</small>
                </span>
                <strong>{{ formatCurrency(item.value) }}</strong>
              </div>
            </div>
          </div>
        </article>
      </section>

      <section class="dashboard-reference-grid main">
        <article class="panel-card dashboard-reference-card">
          <div class="dashboard-card-title-row">
            <h3>Revenue Mix</h3>
            <span class="subtle">Distribution by metric</span>
          </div>

          <div class="dashboard-chart-surface dashboard-chart-medium">
            <BaseEChart :option="revenueMixChartOption" />
          </div>

          <div class="dashboard-progress-list">
            <div v-for="item in progressCollection" :key="item.label" class="dashboard-progress-item">
              <div class="dashboard-progress-head">
                <strong>{{ item.label }}</strong>
                <span>{{ item.value }}</span>
              </div>
            </div>
          </div>
        </article>
      </section>
    </template>
  </section>
</template>

<style scoped>
.dashboard-reference-shell {
  display: grid;
  gap: 16px;
}

.dashboard-topbar-card {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  align-items: flex-start;
  padding: 20px 22px;
  border: 1px solid var(--line);
  border-radius: 18px;
  background: linear-gradient(180deg, #ffffff 0%, #f9fbf5 100%);
}

.dashboard-reference-title {
  font-size: 1.4rem;
  color: #21351d;
}

.dashboard-reference-toolbar {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  justify-content: flex-end;
}

.dashboard-reference-range {
  background: #ffffff;
  border: 1px solid var(--line);
  border-radius: 16px;
  padding: 14px;
}

.dashboard-updated-label {
  justify-self: end;
  color: var(--muted);
  font-size: 0.82rem;
}

.dashboard-reference-kpis {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 12px;
}

.dashboard-reference-kpi {
  position: relative;
  overflow: hidden;
  display: grid;
  gap: 8px;
  padding: 18px 18px 14px;
  border-radius: 18px;
  border: 1px solid #dfe7d4;
  background: #ffffff;
  min-height: 118px;
}

.dashboard-reference-kpi span {
  color: #64725e;
  font-size: 0.8rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.dashboard-reference-kpi strong {
  font-size: 2rem;
  color: #22341d;
  line-height: 1;
}

.dashboard-reference-kpi i {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  height: 4px;
  background: currentColor;
  opacity: 0.9;
}

.dashboard-reference-kpi.tone-olive { color: #7c9733; }
.dashboard-reference-kpi.tone-amber { color: #af8c2a; }
.dashboard-reference-kpi.tone-teal { color: #37998f; }
.dashboard-reference-kpi.tone-green { color: #5f8b32; }
.dashboard-reference-kpi.tone-dark { color: #2b3a28; }

.dashboard-reference-grid {
  display: grid;
  gap: 14px;
}

.dashboard-reference-grid.three {
  grid-template-columns: 1.08fr 1.28fr 1fr;
}

.dashboard-reference-grid.main {
  grid-template-columns: 0.92fr 1.15fr;
  align-items: start;
}

.dashboard-reference-card {
  border-radius: 18px;
  padding: 18px;
  background: linear-gradient(180deg, #ffffff 0%, #fbfcf8 100%);
  border: 1px solid #e3ebd8;
  box-shadow: 0 14px 30px rgba(44, 68, 31, 0.05);
}

.dashboard-reference-card.wide {
  grid-column: span 1;
}

.dashboard-reference-card.full-span {
  grid-column: 1 / -1;
}

.dashboard-reference-card.row-span-half {
  grid-column: span 1;
}

.dashboard-revenue-annual-card.row-span-half {
  grid-column: span 2;
}

.dashboard-card-title-row {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  align-items: center;
  margin-bottom: 14px;
}

.dashboard-card-title-row h3 {
  font-size: 1rem;
  color: #263522;
  letter-spacing: 0.01em;
}

.dashboard-chart-panel {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 14px;
  align-items: stretch;
}

.dashboard-chart-panel-compact {
  grid-template-columns: minmax(0, 1fr);
}

.dashboard-chart-surface {
  width: 100%;
  min-width: 0;
  border-radius: 16px;
  background: radial-gradient(circle at top, #ffffff 0%, #f6faef 52%, #eef4e5 100%);
  border: 1px solid #e3ebd8;
}

.dashboard-chart-compact {
  height: 180px;
}

.dashboard-chart-medium {
  height: 240px;
  margin-bottom: 14px;
}

.dashboard-chart-tall {
  height: 360px;
  margin-bottom: 14px;
}

.dashboard-donut-panel {
  display: grid;
  grid-template-columns: 162px minmax(0, 1fr);
  gap: 12px;
  align-items: center;
}

.dashboard-donut {
  width: 154px;
  height: 154px;
  border-radius: 50%;
  display: grid;
  place-items: center;
  background: conic-gradient(#7a9b3b 0deg 180deg, #dce8cf 180deg 360deg);
}

.dashboard-donut-hole {
  width: 96px;
  height: 96px;
  border-radius: 50%;
  background: #ffffff;
  display: grid;
  place-items: center;
  text-align: center;
}

.dashboard-donut-hole strong {
  font-size: 1.5rem;
  color: #2a3b27;
}

.dashboard-donut-hole span {
  font-size: 0.78rem;
  color: #70806b;
}

.dashboard-donut-panel-compact {
  grid-template-columns: 112px minmax(0, 1fr);
}

.dashboard-donut-small {
  width: 106px;
  height: 106px;
}

.dashboard-donut-hole-small {
  width: 64px;
  height: 64px;
}

.dashboard-donut-hole-small strong {
  font-size: 1.05rem;
}

.dashboard-inline-dot {
  display: inline-block;
  width: 9px;
  height: 9px;
  border-radius: 50%;
  margin-right: 8px;
  vertical-align: middle;
}

.dashboard-legend-list,
.dashboard-progress-list,
.dashboard-notice-list,
.dashboard-stat-list {
  display: grid;
  gap: 10px;
}

.dashboard-legend-item {
  display: flex;
  gap: 10px;
  align-items: center;
}

.dashboard-legend-dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  flex: 0 0 auto;
}

.dashboard-column-lists {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 14px;
}

.dashboard-column-lists h4 {
  margin: 0 0 10px;
  color: #385030;
  font-size: 0.84rem;
}

.dashboard-mini-item {
  display: grid;
  gap: 2px;
  padding: 8px 0;
  border-bottom: 1px solid #edf2e8;
}

.dashboard-mini-item strong {
  color: #253521;
  font-size: 0.86rem;
}

.dashboard-mini-item p,
.dashboard-mini-item span {
  color: #6d7a68;
  font-size: 0.76rem;
  margin: 0;
}

.stacked-triple {
  display: grid;
  gap: 12px;
}

.dashboard-triple-card {
  padding: 14px;
  border-radius: 14px;
  border: 1px solid #e6eee0;
  background: #fbfdf8;
}

.dashboard-stat-row {
  display: flex;
  justify-content: space-between;
  gap: 10px;
  padding: 6px 0;
  border-bottom: 1px solid #edf2e8;
}

.dashboard-stat-row:last-child {
  border-bottom: 0;
}

.dashboard-stat-row span {
  color: #6d7a68;
}

.dashboard-stat-row strong {
  color: #243520;
}

.dashboard-progress-item {
  display: grid;
  gap: 8px;
  padding: 10px 12px;
  border-radius: 14px;
  background: #f8fbf4;
  border: 1px solid #e8efe1;
}

.dashboard-progress-head {
  display: flex;
  justify-content: space-between;
  gap: 12px;
}

.dashboard-progress-head strong {
  color: #2c3e27;
  font-size: 0.86rem;
}

.dashboard-progress-head span {
  color: #667361;
  font-weight: 600;
}

.dashboard-progress-track {
  height: 8px;
  border-radius: 999px;
  background: #ecf2e6;
  overflow: hidden;
}

.dashboard-progress-track span {
  display: block;
  height: 100%;
  border-radius: inherit;
  background: linear-gradient(90deg, #9dc15f 0%, #5e8930 100%);
}

.dashboard-reference-table th {
  background: #f8fbf4;
  color: #5a6955;
  font-size: 0.74rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.dashboard-reference-table td {
  font-size: 0.84rem;
}

.dashboard-notice {
  display: grid;
  gap: 6px;
  padding: 12px;
  border-radius: 14px;
  border: 1px solid #e7eee1;
  background: #f8fbf6;
}

.dashboard-notice strong {
  color: #273723;
}

.dashboard-notice p {
  margin: 0;
  color: #697565;
  font-size: 0.82rem;
}

.dashboard-notice.success { border-left: 4px solid #7ba347; }
.dashboard-notice.info { border-left: 4px solid #6e8fa9; }
.dashboard-notice.warning { border-left: 4px solid #c59d45; }
.dashboard-notice.danger { border-left: 4px solid #cb6b6b; }

.dashboard-linechart-wrap {
  display: grid;
  gap: 10px;
}

.dashboard-linechart {
  width: 100%;
  height: 110px;
}

.dashboard-linechart-area {
  fill: rgba(125, 163, 69, 0.14);
}

.dashboard-linechart-line {
  fill: none;
  stroke: #76983d;
  stroke-width: 3;
  stroke-linecap: round;
  stroke-linejoin: round;
}

.dashboard-chart-labels {
  display: grid;
  grid-template-columns: repeat(6, minmax(0, 1fr));
  gap: 6px;
}

.dashboard-chart-labels span {
  color: #72806d;
  font-size: 0.72rem;
  text-align: center;
}

.dashboard-status-hero {
  display: grid;
  justify-items: start;
  gap: 2px;
  margin-bottom: 14px;
  padding: 14px 16px;
  border-radius: 16px;
  background: linear-gradient(180deg, #f7fbf2 0%, #eef5e7 100%);
}

.dashboard-status-hero strong {
  font-size: 2rem;
  line-height: 1;
  color: #25381f;
}

.dashboard-status-hero span {
  color: #6f7f69;
  font-size: 0.82rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  font-weight: 700;
}

.dashboard-bar-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(44px, 1fr));
  gap: 10px;
  align-items: end;
  min-height: 228px;
}

.dashboard-bar-item {
  display: grid;
  justify-items: center;
  gap: 6px;
}

.dashboard-bar-track {
  width: 100%;
  height: 150px;
  border-radius: 14px;
  background: linear-gradient(180deg, #f2f6ec 0%, #edf2e7 100%);
  display: flex;
  align-items: end;
  padding: 6px;
}

.dashboard-bar-track span {
  width: 100%;
  border-radius: 10px;
  background: linear-gradient(180deg, #a0c364 0%, #587f31 100%);
}

.dashboard-bar-item strong {
  color: #31422d;
  font-size: 0.82rem;
}

.dashboard-bar-item p {
  margin: 0;
  color: #73806d;
  font-size: 0.7rem;
  text-align: center;
}

.dashboard-revenue-annual-card {
  min-width: 0;
}

.dashboard-annual-chart-wrap {
  padding: 8px 0 4px;
}

.dashboard-annual-chart {
  width: 100%;
  height: 180px;
}

.dashboard-annual-chart-area {
  fill: rgba(95, 139, 50, 0.16);
}

.dashboard-annual-chart-line {
  fill: none;
  stroke: #5f8b32;
  stroke-width: 3;
  stroke-linecap: round;
  stroke-linejoin: round;
}

.dashboard-revenue-chart {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(82px, 1fr));
  gap: 10px;
  align-items: end;
  min-height: 220px;
}

.dashboard-revenue-bar {
  display: grid;
  justify-items: center;
  gap: 8px;
}

.dashboard-revenue-track {
  width: 100%;
  height: 140px;
  border-radius: 14px;
  background: linear-gradient(180deg, #f3f7ee 0%, #eaf1e2 100%);
  padding: 8px;
  display: flex;
  align-items: end;
}

.dashboard-revenue-track span {
  display: block;
  width: 100%;
  border-radius: 10px;
}

.dashboard-revenue-bar strong {
  color: #2f402a;
  font-size: 0.78rem;
  text-align: center;
}

.dashboard-revenue-bar p {
  margin: 0;
  color: #72806d;
  font-size: 0.72rem;
  text-align: center;
}

@media (max-width: 1260px) {
  .dashboard-reference-kpis,
  .dashboard-reference-grid.three,
  .dashboard-reference-grid.main {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 860px) {
  .dashboard-topbar-card,
  .dashboard-chart-panel,
  .dashboard-donut-panel,
  .dashboard-column-lists {
    grid-template-columns: 1fr;
    flex-direction: column;
  }

  .dashboard-chart-panel,
  .dashboard-donut-panel {
    display: grid;
  }

  .dashboard-reference-toolbar {
    justify-content: flex-start;
  }

  .dashboard-chart-panel-compact,
  .dashboard-donut-panel-compact {
    grid-template-columns: 1fr;
  }

  .dashboard-chart-medium,
  .dashboard-chart-tall {
    height: 280px;
  }
}
</style>
