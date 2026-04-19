<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import DateTimePickerField from '../components/DateTimePickerField.vue'
import LoadingState from '../components/LoadingState.vue'
import Select2Field from '../components/Select2Field.vue'
import StayViewCalendar from '../components/StayViewCalendar.vue'
import api from '../services/api'
import { useHotelStore } from '../stores/hotel'
import { encodeInvoicePrintOverrides } from '../utils/invoicePrintOverrides'
import { loadPrintTemplateSettings, PRINT_TEMPLATE_UPDATED_EVENT } from '../utils/printTemplate'

const router = useRouter()
const hotel = useHotelStore()
const MS_PER_DAY = 24 * 60 * 60 * 1000

const toUtcDate = (value) => {
  const [year, month, day] = String(value ?? '').split('-').map(Number)
  return new Date(Date.UTC(year, (month || 1) - 1, day || 1))
}

const toIsoDate = (date) => {
  const year = date.getUTCFullYear()
  const month = String(date.getUTCMonth() + 1).padStart(2, '0')
  const day = String(date.getUTCDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

const todayDateKey = () => toIsoDate(new Date())

const addDays = (value, days) => {
  const date = toUtcDate(value)
  date.setUTCDate(date.getUTCDate() + days)
  return toIsoDate(date)
}

const dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']
const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
const buildCalendarDates = (startDate, length, businessDate) =>
  Array.from({ length }, (_, index) => {
    const key = addDays(startDate, index)
    const date = toUtcDate(key)
    return {
      key,
      day: dayNames[date.getUTCDay()],
      date: String(date.getUTCDate()),
      label: `${String(date.getUTCDate()).padStart(2, '0')} ${monthNames[date.getUTCMonth()]}`,
      today: key === businessDate,
      weekend: [0, 6].includes(date.getUTCDay()),
    }
  })

const buildCalendarRangeLabel = (dates) => {
  if (!dates.length) {
    return ''
  }

  const first = dates[0]
  const last = dates[dates.length - 1]
  return `${first.label} ${first.key.slice(0, 4)} - ${last.label} ${last.key.slice(0, 4)}`
}

const formatShortStayDate = (value) => {
  const dateKey = String(value ?? '').slice(0, 10)
  if (!dateKey) {
    return '-'
  }

  const date = toUtcDate(dateKey)
  return `${String(date.getUTCDate()).padStart(2, '0')} ${monthNames[date.getUTCMonth()]}`
}

const firstDateKey = hotel.calendarDates[0].key
const secondDateKey = hotel.calendarDates[1].key
const visibleCalendarStart = ref(todayDateKey())

const bookingSearch = ref('')
const bookingStatus = ref('All')
const bookingDateStart = ref('')
const bookingDateEnd = ref('')
const loadingBookings = ref(false)
const loadingRooms = ref(false)
const bookingsResult = ref({ tone: '', text: '' })
const addonResult = ref({ tone: '', text: '' })
const cancellationPolicy = ref({ percent: 0, label: '0', enabled: false })
const bookings = ref([])
const roomRows = ref([])
const selectedBookingCode = ref(hotel.selectedBookingCode ?? '')
const showInsightModal = ref(false)
const showAddonModal = ref(false)
const showAddonListModal = ref(false)
const showInvoiceModal = ref(false)
const invoiceDocumentMode = ref('invoice')
const printTemplate = ref(loadPrintTemplateSettings())
const invoicePrintDraft = ref(null)
const invoiceHtmlPreviewUrl = ref('')
const showPaymentModal = ref(false)
const showStatusConfirmModal = ref(false)
const loadingCheckoutInventory = ref(false)
const checkoutInventoryRows = ref([])
const statusConfirmState = ref({
  bookingCode: '',
  guest: '',
  nextStatus: '',
  title: '',
  description: '',
  penaltyText: '',
  warningText: '',
  confirmLabel: '',
})

const paymentMethods = ['Cash', 'Bank Transfer', 'Credit Card', 'Debit Card', 'QRIS']
const paymentResult = ref({ tone: '', text: '' })
const paymentForm = reactive({
  paymentDate: new Date().toISOString().slice(0, 10),
  method: paymentMethods[0],
  amountValue: '',
  referenceNo: '',
  note: '',
})

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

const addonTypeOptions = [
  { value: 'transport', label: 'Transport' },
  { value: 'scooter', label: 'Scooter' },
  { value: 'island_tour', label: 'Island Tour' },
  { value: 'boat_ticket', label: 'Boat Ticket' },
]

let addonEntrySeed = 1
const createAddonEntry = (serviceDate = firstDateKey, endDate = secondDateKey) => ({
  id: `addon-entry-${addonEntrySeed++}`,
  addonType: addonTypeOptions[0].value,
  itemValue: '',
  serviceDate,
  startDate: serviceDate,
  endDate: endDate < serviceDate ? serviceDate : endDate,
  status: 'Planned',
})

const addonEntries = ref([createAddonEntry()])

const selectedBooking = computed(() =>
  bookings.value.find((item) => item.code === selectedBookingCode.value) ?? null,
)
const selectedBookingBalanceLabel = computed(() => {
  const balanceValue = Number(
    selectedBooking.value?.balanceValue
    ?? selectedBooking.value?.grandTotalValue
    ?? selectedBooking.value?.amountValue
    ?? 0,
  )

  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
  }).format(balanceValue)
})

const invoicePreview = computed(() => {
  if (!selectedBooking.value) {
    return null
  }

  const booking = selectedBooking.value
  const startKey = String(booking.checkIn ?? '').slice(0, 10)
  const endKey = String(booking.checkOut ?? '').slice(0, 10)
  const nights = Math.max(1, Math.round((toUtcDate(endKey) - toUtcDate(startKey)) / MS_PER_DAY))
  const addons = Array.isArray(booking.addons) ? booking.addons : []
  const payments = (hotel.paymentTransactions ?? [])
    .filter((item) => item.bookingCode === booking.code)
    .sort((left, right) => right.paymentDate.localeCompare(left.paymentDate))
  const addonTotalValue = addons.reduce((total, item) => total + Number(item.totalPriceValue ?? 0), 0)
  const roomOnlySubtotalValue = Math.max(Number(booking.amountValue ?? 0), 0)
  const roomDetails = Array.isArray(booking.roomDetails) ? booking.roomDetails : []
  const roomCount = roomDetails.length || Math.max(1, Number(booking.roomCount ?? 1))
  const perRoomDefault = roomCount > 0 ? Math.round(roomOnlySubtotalValue / roomCount) : roomOnlySubtotalValue

  const roomLines = roomDetails.length
    ? roomDetails.map((room, index) => {
        const rateValue = Number(room.rate ?? room.rateValue ?? 0)
        const totalValue = rateValue > 0 ? rateValue * nights : perRoomDefault
        return {
          id: `${booking.code}-room-${room.room}-${index}`,
          room: room.room,
          roomType: room.roomType,
          pax: `${Number(room.adults ?? 0)} adult(s), ${Number(room.children ?? 0)} child(ren)`,
          nights,
          totalValue,
          rateLabel: new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(rateValue > 0 ? rateValue : Math.round(totalValue / nights)),
          totalLabel: new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(totalValue),
        }
      })
    : [{
        id: `${booking.code}-room-main`,
        room: booking.room || '-',
        roomType: booking.roomType || '-',
        pax: `${Number(booking.adults ?? 0)} adult(s), ${Number(booking.children ?? 0)} child(ren)`,
        nights,
        totalValue: roomOnlySubtotalValue,
        rateLabel: new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(Math.round(roomOnlySubtotalValue / nights)),
        totalLabel: new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(roomOnlySubtotalValue),
      }]

  const roomTotalValue = roomLines.reduce((total, room) => total + Number(room.totalValue ?? 0), 0)

  return {
    bookingCode: booking.code,
    invoiceNo: booking.invoiceNo,
    guest: booking.guest,
    channel: booking.channel,
    issueDate: booking.issueDate,
    dueDate: booking.dueDate,
    stayLabel: `${formatShortStayDate(startKey)} - ${formatShortStayDate(endKey)}`,
    nightsLabel: `${nights} night(s)`,
    roomLabel: booking.room,
    roomCount: booking.roomCount,
    roomLines,
    roomTotal: new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(roomOnlySubtotalValue || roomTotalValue),
    addonLines: addons.map((item) => ({
      id: item.id,
      label: item.addonLabel,
      description: item.serviceName,
      serviceDate: item.serviceDateLabel ?? item.serviceDate ?? '-',
      status: item.status ?? '-',
      qty: Number(item.quantity ?? 1),
      amountLabel: item.totalPrice ?? new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(Number(item.totalPriceValue ?? 0)),
    })),
    addonTotal: new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(addonTotalValue),
    subtotal: booking.grandTotal,
    paid: booking.paidAmount ?? new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(payments.reduce((total, item) => total + Number(item.signedAmountValue ?? item.amountValue ?? 0), 0)),
    balance: selectedBookingBalanceLabel.value,
    paymentStatus: booking.invoiceStatus ?? (Number(booking.balanceValue ?? 0) <= 0 ? 'Paid' : 'Unpaid'),
    payments: payments.map((item) => ({
      id: item.id,
      date: item.paymentDate,
      method: item.method,
      type: item.transactionLabel,
      reference: item.referenceNo || '-',
      amountLabel: `${Number(item.signedAmountValue ?? item.amountValue ?? 0) < 0 ? '- ' : ''}${new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(Math.abs(Number(item.signedAmountValue ?? item.amountValue ?? 0)))}`,
    })),
    note: booking.note || '',
  }
})

const createInvoicePrintDraft = () => {
  const booking = selectedBooking.value
  const preview = invoicePreview.value
  if (!booking || !preview) {
    return null
  }

  return {
    invoice: {
      invoice_number: preview.invoiceNo,
      issued_at: preview.issueDate,
      due_at: preview.dueDate,
      status: preview.paymentStatus,
    },
    booking: {
      code: booking.code,
      guest: booking.guest,
      checkIn: booking.checkIn,
      checkOut: booking.checkOut,
      note: booking.note || '',
      invoiceStatus: preview.paymentStatus,
      roomDetails: preview.roomLines.map((room, index) => ({
        room: room.room,
        roomType: room.roomType,
        adults: Number(booking.roomDetails?.[index]?.adults ?? booking.adults ?? 0),
        children: Number(booking.roomDetails?.[index]?.children ?? booking.children ?? 0),
        rateValue: Math.round(Number(room.totalValue ?? 0) / Math.max(1, Number(room.nights ?? 1))),
        lineTotalValue: Number(room.totalValue ?? 0),
      })),
      addons: (Array.isArray(booking.addons) ? booking.addons : []).map((item) => ({
        addonLabel: item.addonLabel,
        serviceName: item.serviceName,
        serviceDateLabel: item.serviceDateLabel ?? item.serviceDate ?? '-',
        quantity: Number(item.quantity ?? 1),
        unitPriceValue: Number(item.unitPriceValue ?? 0),
        totalPriceValue: Number(item.totalPriceValue ?? 0),
      })),
      payments: preview.payments.map((payment) => ({
        paymentDate: payment.date,
        transactionLabel: payment.type,
        method: payment.method,
        referenceNo: payment.reference,
        signedAmountValue: Number(String(payment.amountLabel).replace(/[^0-9-]/g, '')) || 0,
      })),
    },
    document: {
      invoiceTitle: 'INVOICE',
      addonFooterNote: 'Please review this invoice carefully. Payments are considered settled after confirmed receipt.',
    },
  }
}

watch(selectedBookingCode, (bookingCode) => {
  hotel.setSelectedBooking(bookingCode || null)
})

watch(
  () => hotel.selectedBookingCode,
  (bookingCode) => {
    const normalizedCode = bookingCode ?? ''
    if (normalizedCode !== selectedBookingCode.value) {
      selectedBookingCode.value = normalizedCode
    }
  },
)

watch(
  () => hotel.currentBusinessDate,
  () => {
    const todayKey = todayDateKey()
    if (todayKey) {
      visibleCalendarStart.value = todayKey
    }
  },
)

watch(selectedBooking, (booking) => {
  if (booking) {
    return
  }

  showInsightModal.value = false
  showAddonModal.value = false
  showAddonListModal.value = false
  showInvoiceModal.value = false
  showPaymentModal.value = false
  showStatusConfirmModal.value = false
})

watch(
  () => [selectedBookingCode.value, showInvoiceModal.value, invoiceDocumentMode.value],
  () => {
    if (!showInvoiceModal.value || invoiceDocumentMode.value !== 'invoice') {
      return
    }

    invoicePrintDraft.value = createInvoicePrintDraft()
    refreshInvoiceHtmlPreview()
  },
)

const filteredBookings = computed(() => {
  const query = bookingSearch.value.trim().toLowerCase()

  return bookings.value.filter((item) => {
    const matchesStatus = bookingStatus.value === 'All' || item.status === bookingStatus.value
    const itemCheckIn = String(item.checkIn ?? '').slice(0, 10)
    const matchesStart = !bookingDateStart.value || itemCheckIn >= bookingDateStart.value
    const matchesEnd = !bookingDateEnd.value || itemCheckIn <= bookingDateEnd.value
    const haystack = [item.code, item.guest, item.room, item.roomType, item.channel]
      .join(' ')
      .toLowerCase()

    return matchesStatus && matchesStart && matchesEnd && (!query || haystack.includes(query))
  })
})

const calendarDates = computed(() => buildCalendarDates(visibleCalendarStart.value, 7, hotel.currentBusinessDate))
const calendarRangeLabel = computed(() => buildCalendarRangeLabel(calendarDates.value))

const buildBookingStatusTone = (booking) => {
  const startKey = String(booking.checkIn ?? '').slice(0, 10)
  const endKey = String(booking.checkOut ?? '').slice(0, 10)
  const businessDate = hotel.currentBusinessDate

  if (startKey === businessDate) {
    return 'arriving'
  }

  if (endKey === businessDate) {
    return 'due-out'
  }

  if (booking.status === 'Checked-in') {
    return 'checked-in'
  }

  if (booking.status === 'Cancelled' || booking.status === 'No-Show') {
    return 'blocked'
  }

  return 'confirmed'
}

const stayCalendarGroups = computed(() => {
  const roomGroupKey = 'Kamar'
  const roomTypeMap = new Map()
  const dateKeys = calendarDates.value.map((day) => day.key)

  roomRows.value.forEach((room) => {
    if (!roomTypeMap.has(roomGroupKey)) {
      roomTypeMap.set(roomGroupKey, {
        roomType: roomGroupKey,
        plan: '',
        availability: '',
        unassigned: 0,
        rate: 0,
        rooms: [],
      })
    }

    roomTypeMap.get(roomGroupKey).rooms.push({
      no: room.code,
      hk: room.hk || room.status || 'Available',
      flag: String(room.status ?? '').slice(0, 2).toUpperCase() || 'AV',
      bookings: [],
    })
  })

  bookings.value.forEach((booking) => {
    const startKey = String(booking.checkIn ?? '').slice(0, 10)
    const endKey = String(booking.checkOut ?? '').slice(0, 10)
    const startIndex = dateKeys.findIndex((key) => key === startKey)
    const endIndex = dateKeys.findIndex((key) => key === endKey)

    booking.roomDetails?.forEach((detail) => {
      const group = roomTypeMap.get(roomGroupKey)
      const room = group?.rooms.find((item) => item.no === detail.room)

      if (!group || !room) {
        return
      }

      let start = startIndex
      let end = endIndex

      if (start === -1) {
        if (startKey < dateKeys[0]) {
          start = 0
        } else {
          return
        }
      }

      if (end === -1) {
        if (endKey > dateKeys[dateKeys.length - 1]) {
          end = dateKeys.length
        } else {
          return
        }
      }

      const span = Math.max(1, end - start)
      const nights = Math.max(1, Math.round((toUtcDate(endKey) - toUtcDate(startKey)) / MS_PER_DAY))
      const stayLabel = `${nights} night(s)`
      const stayTooltip = `Check-in ${formatShortStayDate(startKey)} | Check-out ${formatShortStayDate(endKey)} | ${stayLabel}`

      room.bookings.push({
        id: `${booking.code}-${detail.room}`,
        start,
        span,
        status: buildBookingStatusTone(booking),
        guest: booking.guest,
        code: booking.code,
        source: booking.channel,
        pax: `${detail.adults} adult(s), ${detail.children} child(ren)`,
        balance: booking.note || booking.amount,
        stayLabel,
        stayTooltip,
      })
    })
  })

  return Array.from(roomTypeMap.values()).map((group) => {
    const vacantClean = group.rooms.filter((room) => room.bookings.length === 0).length

    return {
      ...group,
      plan: `${group.rooms.length} rooms | ${group.rooms.reduce((total, room) => total + room.bookings.length, 0)} booking(s)`,
      availability: `${vacantClean} vacant clean`,
      rooms: group.rooms.sort((left, right) => String(left.no).localeCompare(String(right.no))),
    }
  })
})

const staySummary = computed(() => {
  const businessDate = hotel.currentBusinessDate
  const arrivals = bookings.value.filter((booking) => String(booking.checkIn ?? '').slice(0, 10) === businessDate).length
  const inHouse = bookings.value.filter((booking) => {
    const start = String(booking.checkIn ?? '').slice(0, 10)
    const end = String(booking.checkOut ?? '').slice(0, 10)
    return start <= businessDate && end > businessDate
  }).length
  const unassigned = bookings.value.filter((booking) => !booking.roomDetails?.length).length
  const balanceDue = bookings.value.reduce(
    (total, booking) => total + Number(booking.balanceValue ?? booking.grandTotalValue ?? booking.amountValue ?? 0),
    0,
  )

  return [
    { label: 'Arrivals', value: String(arrivals), note: 'Reservations arriving today' },
    { label: 'In house', value: String(inHouse), note: 'Active reservations in the property' },
    { label: 'Unassigned', value: String(unassigned), note: 'Bookings without room assignment' },
    { label: 'Balance due', value: new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(balanceDue), note: 'Reservation value in this range' },
  ]
})

const addonStatusOptions = ['Planned', 'Confirmed', 'Posted']
const addonStatusSelectOptions = addonStatusOptions.map((item) => ({ value: item, label: item }))
const formatAddonOptionPrice = (value) => formatCurrency(value ?? 0)

const getAddonItemOptions = (addonType) => {
  if (addonType === 'transport') {
    return (hotel.transportRates ?? []).flatMap((item) => ([
      {
        value: `${item.dbId ?? item.id}:pickup`,
        label: `${item.driver} | Pickup | Harga Customer ${formatAddonOptionPrice(item.pickupPriceValue)}`,
        serviceName: `${item.driver} | Pickup`,
        unitPriceValue: item.pickupPriceValue,
        vendorUnitPriceValue: item.vendorPickupPriceValue ?? 0,
        addonLabel: 'Airport pickup',
        itemRef: item.dbId ?? item.id,
      },
      {
        value: `${item.dbId ?? item.id}:dropoff`,
        label: `${item.driver} | Drop off | Harga Customer ${formatAddonOptionPrice(item.dropOffPriceValue)}`,
        serviceName: `${item.driver} | Drop off`,
        unitPriceValue: item.dropOffPriceValue,
        vendorUnitPriceValue: item.vendorDropOffPriceValue ?? 0,
        addonLabel: 'Airport drop off',
        itemRef: item.dbId ?? item.id,
      },
    ]))
  }

  if (addonType === 'scooter') {
    return (hotel.scooterBookings ?? []).filter((item) => item.isActive !== false).map((item) => ({
      value: item.dbId ?? item.id,
      label: `${item.scooterType} | ${item.vendor} | Harga Customer ${formatAddonOptionPrice(item.customerPriceValue ?? item.priceValue)}`,
      serviceName: `${item.scooterType} | ${item.vendor}`,
      unitPriceValue: item.customerPriceValue ?? item.priceValue,
      vendorUnitPriceValue: item.vendorPriceValue ?? 0,
      addonLabel: 'Scooter rental',
      itemRef: item.dbId ?? item.id,
    }))
  }

  if (addonType === 'island_tour') {
    return (hotel.islandTours ?? []).filter((item) => item.isActive !== false).map((item) => ({
      value: item.dbId ?? item.id,
      label: `${item.destination} | ${item.driver} | Harga Customer ${formatAddonOptionPrice(item.customerPriceValue ?? item.costValue)}`,
      serviceName: `${item.destination} | ${item.driver}`,
      unitPriceValue: item.customerPriceValue ?? item.costValue,
      vendorUnitPriceValue: item.vendorPriceValue ?? 0,
      addonLabel: 'Island tour',
      itemRef: item.dbId ?? item.id,
    }))
  }

  if (addonType === 'boat_ticket') {
    return (hotel.boatTickets ?? []).filter((item) => item.isActive !== false).map((item) => ({
      value: item.dbId ?? item.id,
      label: `${item.company} | ${item.destination} | Harga Customer ${formatAddonOptionPrice(item.customerPriceValue ?? item.priceValue)}`,
      serviceName: `${item.company} | ${item.destination}`,
      unitPriceValue: item.customerPriceValue ?? item.priceValue,
      vendorUnitPriceValue: item.vendorPriceValue ?? 0,
      addonLabel: 'Boat ticket',
      itemRef: item.dbId ?? item.id,
    }))
  }

  return []
}

const getDefaultAddonItemValue = (addonType) =>
  getAddonItemOptions(addonType).find((item) => item?.value)?.value ?? ''

const getAddonItemCountLabel = (entry) =>
  `${getAddonItemOptions(entry.addonType).length} service item(s) available`

const isScooterAddon = (entry) => entry.addonType === 'scooter'

const getAddonQuantity = (entry) => {
  if (!isScooterAddon(entry)) {
    return 1
  }

  const startDate = String(entry.serviceDate ?? entry.startDate ?? '').trim()
  const endDate = String(entry.endDate ?? '').trim()

  if (!startDate || !endDate) {
    return 1
  }

  const duration = Math.round((toUtcDate(endDate) - toUtcDate(startDate)) / MS_PER_DAY) + 1
  return Math.max(1, duration)
}

const addonPreviewTotal = computed(() => {
  const total = addonEntries.value.reduce((sum, entry) => {
    const selectedItem = getAddonItemOptions(entry.addonType).find((item) => item.value === entry.itemValue)
    return sum + (Number(selectedItem?.unitPriceValue ?? 0) * getAddonQuantity(entry))
  }, 0)

  if (!total) {
    return null
  }

  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
  }).format(total)
})

const replaceBookingRow = (booking) => {
  const index = bookings.value.findIndex((item) => item.code === booking.code)

  if (index === -1) {
    bookings.value.unshift(booking)
    return
  }

  bookings.value.splice(index, 1, booking)
}

const syncBookingCollections = (rows) => {
  hotel.setBookings([...rows])
  hotel.setBookingAddons(
    rows.flatMap((booking) =>
      (Array.isArray(booking.addons) ? booking.addons : []).map((addon) => ({
        ...addon,
        bookingCode: booking.code,
      })),
    ),
  )
}

const loadPayments = async () => {
  try {
    const response = await api.get('/payments')
    hotel.setPaymentTransactions(Array.isArray(response.data?.data) ? response.data.data : [])
  } catch (error) {
    console.error('Failed to load payments for booking sync:', error)
    hotel.setPaymentTransactions([])
  }
}

const loadBookings = async () => {
  loadingBookings.value = true
  bookingsResult.value = { tone: '', text: '' }

  try {
    const response = await api.get('/bookings', {
      params: { per_page: 200 },
    })

    bookings.value = Array.isArray(response.data?.data) ? response.data.data : []
    syncBookingCollections(bookings.value)

    if (selectedBookingCode.value) {
      const stillExists = bookings.value.some((item) => item.code === selectedBookingCode.value)
      if (!stillExists) {
        selectedBookingCode.value = ''
      }
    }
  } catch (error) {
    bookingsResult.value = {
      tone: 'error',
      text: error?.response?.data?.message || error?.message || 'Failed to load bookings from the database.',
    }
    bookings.value = []
    syncBookingCollections([])
  } finally {
    loadingBookings.value = false
  }
}

const loadRooms = async () => {
  loadingRooms.value = true

  try {
    const response = await api.get('/rooms', {
      params: { per_page: 200 },
    })

    roomRows.value = Array.isArray(response.data?.data) ? response.data.data : []
  } catch (error) {
    bookingsResult.value = {
      tone: 'error',
      text: error?.response?.data?.message || error?.message || 'Failed to load room master data for the calendar.',
    }
    roomRows.value = []
  } finally {
    loadingRooms.value = false
  }
}

const loadAddonCatalog = async () => {
  try {
    const [catalogResponse, transportResponse] = await Promise.all([
      api.get('/activity-catalog'),
      api.get('/transport-rates'),
    ])

    const catalog = catalogResponse.data?.data ?? {}
    hotel.setActivityCatalog({
      scooters: Array.isArray(catalog.scooters) ? catalog.scooters : [],
      operators: Array.isArray(catalog.operators) ? catalog.operators : [],
      islandTours: Array.isArray(catalog.islandTours) ? catalog.islandTours : [],
      boatTickets: Array.isArray(catalog.boatTickets) ? catalog.boatTickets : [],
    })
    hotel.setTransportRates(Array.isArray(transportResponse.data?.data) ? transportResponse.data.data : [])
  } catch (error) {
    hotel.setActivityCatalog({
      scooters: [],
      operators: [],
      islandTours: [],
      boatTickets: [],
    })
    hotel.setTransportRates([])
    bookingsResult.value = {
      tone: 'error',
      text: error?.response?.data?.message || error?.message || 'Failed to load add-on catalog data.',
    }
  }
}

const selectBooking = (bookingCode) => {
  selectedBookingCode.value = bookingCode
}

const openBookingPage = async () => {
  await router.push({ name: 'booking-create' })
}

const openBookingFromCalendarSlot = async ({ room, date }) => {
  await router.push({
    name: 'booking-create',
    query: {
      room,
      checkIn: `${date} 14:00`,
      checkOut: `${addDays(date, 1)} 12:00`,
    },
  })
}

const openEditPage = async (bookingCode) => {
  await router.push({ name: 'booking-edit', params: { bookingCode } })
}

const openInvoicePreview = async (bookingCode = '') => {
  const targetCode = bookingCode || selectedBooking.value?.code
  if (!targetCode) {
    bookingsResult.value = { tone: 'error', text: 'Select a booking first to view the invoice.' }
    return
  }

  if (targetCode !== selectedBookingCode.value) {
    selectedBookingCode.value = targetCode
  }

  invoiceDocumentMode.value = 'invoice'
  showInvoiceModal.value = true
  invoicePrintDraft.value = createInvoicePrintDraft()
  refreshInvoiceHtmlPreview()
}

const closeInvoiceModal = () => {
  showInvoiceModal.value = false
  invoiceHtmlPreviewUrl.value = ''
}

const buildInvoicePreviewPdfUrl = (bookingCode, inline = false) => {
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
  return `${baseUrl}/bookings/${bookingCode}/invoice-pdf${query ? `?${query}` : ''}`
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
  return `${baseUrl}/bookings/${bookingCode}/invoice-print${query ? `?${query}` : ''}`
}

const refreshInvoiceHtmlPreview = () => {
  if (!selectedBooking.value || invoiceDocumentMode.value !== 'invoice') {
    invoiceHtmlPreviewUrl.value = ''
    return
  }

  invoiceHtmlPreviewUrl.value = buildInvoiceHtmlPreviewUrl(selectedBooking.value.code)
}

const resetInvoicePrintDraft = () => {
  invoicePrintDraft.value = createInvoicePrintDraft()
  refreshInvoiceHtmlPreview()
}

const downloadInvoicePreviewPdf = async () => {
  if (!selectedBooking.value) {
    return
  }

  const params = { size: 'A5', document: invoiceDocumentMode.value }
  if (invoiceDocumentMode.value === 'invoice' && invoicePrintDraft.value) {
    const encodedOverrides = encodeInvoicePrintOverrides(invoicePrintDraft.value)
    if (encodedOverrides) {
      params.overrides = encodedOverrides
    }
  }

  const response = await api.get(`/bookings/${selectedBooking.value.code}/invoice-pdf`, {
    params,
    responseType: 'blob',
  })
  const blob = new Blob([response.data], { type: 'application/pdf' })
  const url = window.URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = `${selectedBooking.value.code}-${invoiceDocumentMode.value}.pdf`
  document.body.appendChild(link)
  link.click()
  link.remove()
  window.URL.revokeObjectURL(url)
}

const openInvoicePreviewPrintPage = () => {
  if (!selectedBooking.value || typeof window === 'undefined') {
    return
  }

  window.open(buildInvoicePreviewPdfUrl(selectedBooking.value.code, true), '_blank', 'noopener')
}

const openInsightModal = (bookingCode = '') => {
  if (bookingCode) {
    selectBooking(bookingCode)
  }

  if (!selectedBooking.value) {
    bookingsResult.value = { tone: 'error', text: 'Select a booking first to view booking insight.' }
    return
  }

  showInsightModal.value = true
}

const closeInsightModal = () => {
  showInsightModal.value = false
}

const canCheckIn = computed(() =>
  ['Tentative', 'Confirmed'].includes(selectedBooking.value?.status ?? ''),
)

const canCheckOut = computed(() => selectedBooking.value?.status === 'Checked-in')
const cancellableStatuses = ['Tentative', 'Confirmed']
const canCancelBooking = (booking) => cancellableStatuses.includes(booking?.status ?? '')
const canNoShowBooking = (booking) => cancellableStatuses.includes(booking?.status ?? '')

const currencyFormatter = new Intl.NumberFormat('id-ID', {
  style: 'currency',
  currency: 'IDR',
  maximumFractionDigits: 0,
})

const formatCurrency = (value) => currencyFormatter.format(Number(value ?? 0))

const estimateCancellationPenalty = (booking) => {
  const percent = Number(cancellationPolicy.value.percent ?? 0)
  const base = Number(booking?.grandTotalValue ?? booking?.amountValue ?? 0)
  if (percent <= 0 || base <= 0) {
    return 0
  }

  return Math.round(base * (percent / 100))
}

const openStatusConfirmModal = (state) => {
  statusConfirmState.value = {
    bookingCode: state.bookingCode ?? '',
    guest: state.guest ?? '',
    nextStatus: state.nextStatus ?? '',
    title: state.title ?? '',
    description: state.description ?? '',
    penaltyText: state.penaltyText ?? '',
    warningText: state.warningText ?? '',
    confirmLabel: state.confirmLabel ?? 'Confirm',
  }
  showStatusConfirmModal.value = true
}

const closeStatusConfirmModal = () => {
  showStatusConfirmModal.value = false
  loadingCheckoutInventory.value = false
  checkoutInventoryRows.value = []
  statusConfirmState.value = {
    bookingCode: '',
    guest: '',
    nextStatus: '',
    title: '',
    description: '',
    penaltyText: '',
    warningText: '',
    confirmLabel: '',
  }
}

const checkoutInventoryTotalUsed = computed(() =>
  checkoutInventoryRows.value.reduce((total, row) => total + Number(row.usedQty || 0), 0),
)

const checkoutInventoryTotalReturned = computed(() =>
  checkoutInventoryRows.value.reduce((total, row) => total + Math.max(0, Number(row.outstandingQty || 0) - Number(row.usedQty || 0)), 0),
)

const checkoutInventoryTotalUsedCost = computed(() =>
  checkoutInventoryRows.value.reduce((total, row) => total + (Number(row.usedQty || 0) * Number(row.latestUnitCost || 0)), 0),
)

const loadCancellationPolicy = async () => {
  try {
    const response = await api.get('/settings/policies')
    cancellationPolicy.value = response.data?.data?.cancellationPolicy ?? { percent: 0, label: '0', enabled: false }
  } catch (error) {
    console.error('Failed to load cancellation penalty policy:', error)
    cancellationPolicy.value = { percent: 0, label: '0', enabled: false }
  }
}

const updateBookingStatus = async (bookingCode, status, extraPayload = {}) => {
  bookingsResult.value = { tone: '', text: '' }

  try {
    const response = await api.patch(`/bookings/${bookingCode}/status`, { status, ...extraPayload })
    const updatedBooking = response.data?.data
    const message = response.data?.message || 'Booking status was updated successfully.'

    if (updatedBooking) {
      replaceBookingRow(updatedBooking)
      syncBookingCollections(bookings.value)
      selectedBookingCode.value = updatedBooking.code
    }

    await loadRooms()

    bookingsResult.value = {
      tone: 'success',
      text: message,
    }
    return true
  } catch (error) {
    const errorData = error?.response?.data
    const errorMessage = errorData?.errors?.status?.[0] || errorData?.message || error?.message || 'Failed to update booking status.'
    
    bookingsResult.value = {
      tone: 'error',
      text: errorMessage,
    }
    return false
  }
}

const submitStatusConfirmation = async () => {
  const { bookingCode, nextStatus } = statusConfirmState.value

  if (!bookingCode || !nextStatus) {
    closeStatusConfirmModal()
    return
  }

  const extraPayload = nextStatus === 'Checked-out'
    ? {
        roomInventoryUsage: checkoutInventoryRows.value.map((row) => ({
          roomNo: row.roomNo,
          itemId: row.itemId,
          usedQty: Number(row.usedQty || 0),
        })),
      }
    : {}

  const success = await updateBookingStatus(bookingCode, nextStatus, extraPayload)
  if (success) {
    closeStatusConfirmModal()
  }
}

const checkInBooking = async (bookingCode) => {
  await updateBookingStatus(bookingCode, 'Checked-in')
}

const checkOutBooking = async (bookingCode) => {
  const booking = bookings.value.find((item) => item.code === bookingCode) ?? selectedBooking.value
  loadingCheckoutInventory.value = true
  bookingsResult.value = { tone: '', text: '' }

  try {
    const response = await api.get(`/bookings/${bookingCode}/checkout-inventory`)
    const rows = Array.isArray(response.data?.data) ? response.data.data : []
    checkoutInventoryRows.value = rows.map((row) => ({
      ...row,
      usedQty: 0,
    }))

    openStatusConfirmModal({
      bookingCode,
      guest: booking?.guest ?? '',
      nextStatus: 'Checked-out',
      title: 'Check-out guest',
      description: rows.length
        ? 'Masukkan jumlah item kamar yang dipakai tamu. Sisa item akan otomatis dikembalikan ke stok.'
        : 'Tidak ada item kamar outstanding. Check-out bisa langsung diproses.',
      penaltyText: '',
      warningText: rows.length
        ? 'Qty dipakai akan tetap dianggap keluar dari stok. Qty sisa otomatis kembali ke inventory.'
        : '',
      confirmLabel: 'Yes, process check-out',
    })
  } catch (error) {
    const errorData = error?.response?.data
    bookingsResult.value = {
      tone: 'error',
      text: errorData?.message || error?.message || 'Failed to load room inventory usage for check-out.',
    }
  } finally {
    loadingCheckoutInventory.value = false
  }
}

const cancelBooking = async (booking) => {
  if (!booking) {
    return
  }

  const penaltyValue = estimateCancellationPenalty(booking)
  openStatusConfirmModal({
    bookingCode: booking.code,
    guest: booking.guest,
    nextStatus: 'Cancelled',
    title: 'Cancel reservation',
    description: `Booking ${booking.code} under ${booking.guest} will be canceled.`,
    penaltyText: cancellationPolicy.value.enabled
      ? `A cancellation penalty of ${cancellationPolicy.value.label}% will be charged for ${formatCurrency(penaltyValue)}.`
      : 'There is no active cancellation penalty at the moment.',
    warningText: 'If payments already received exceed the penalty amount, the system will reject the action until a refund or void is processed.',
    confirmLabel: 'Yes, cancel booking',
  })
}

const noShowBooking = async (booking) => {
  if (!booking) {
    return
  }

  openStatusConfirmModal({
    bookingCode: booking.code,
    guest: booking.guest,
    nextStatus: 'No-Show',
    title: 'Mark as no-show',
    description: `Booking ${booking.code} under ${booking.guest} will be marked as no-show.`,
    penaltyText: '',
    warningText: 'Use this status only if the guest truly did not arrive and the reservation will not continue.',
    confirmLabel: 'Yes, mark as no-show',
  })
}

const resetAddonEntries = () => {
  const startDate = selectedBooking.value?.checkIn?.slice(0, 10) ?? firstDateKey
  const endDate = selectedBooking.value?.checkOut?.slice(0, 10) ?? secondDateKey
  const entry = createAddonEntry(startDate, endDate)
  entry.itemValue = getDefaultAddonItemValue(entry.addonType)
  addonEntries.value = [entry]
}

const addAddonEntry = () => {
  const startDate = selectedBooking.value?.checkIn?.slice(0, 10) ?? firstDateKey
  const endDate = selectedBooking.value?.checkOut?.slice(0, 10) ?? secondDateKey
  const entry = createAddonEntry(startDate, endDate)
  entry.itemValue = getDefaultAddonItemValue(entry.addonType)
  addonEntries.value = [...addonEntries.value, entry]
}

const removeAddonEntry = (entryId) => {
  addonEntries.value = addonEntries.value.filter((entry) => entry.id !== entryId)

  if (!addonEntries.value.length) {
    resetAddonEntries()
  }
}

const handleAddonTypeChange = (entry) => {
  entry.itemValue = getDefaultAddonItemValue(entry.addonType)
  entry.startDate = entry.serviceDate
  entry.endDate = entry.endDate < entry.startDate ? entry.startDate : entry.endDate
}

const handleAddonServiceDateChange = (entry) => {
  entry.startDate = entry.serviceDate

  if (entry.endDate < entry.serviceDate) {
    entry.endDate = entry.serviceDate
  }
}

const openAddonModal = (bookingCode = '') => {
  if (bookingCode) {
    selectBooking(bookingCode)
  }

  if (!selectedBooking.value) {
    addonResult.value = { tone: 'error', text: 'Select a booking first before adding an add-on.' }
    return
  }

  addonResult.value = { tone: '', text: '' }
  resetAddonEntries()
  showAddonModal.value = true
}

const closeAddonModal = () => {
  showAddonModal.value = false
}

const openAddonListModal = (bookingCode = '') => {
  if (bookingCode) {
    selectBooking(bookingCode)
  }

  if (!selectedBooking.value) {
    addonResult.value = { tone: 'error', text: 'Select a booking first to view add-ons.' }
    return
  }

  showAddonListModal.value = true
}

const closeAddonListModal = () => {
  showAddonListModal.value = false
}

const submitAddon = async () => {
  addonResult.value = { tone: '', text: '' }

  if (!selectedBooking.value) {
    addonResult.value = { tone: 'error', text: 'Booking belum dipilih.' }
    return
  }

  try {
    let updatedBooking = null

    for (const entry of addonEntries.value) {
      const selectedItem = getAddonItemOptions(entry.addonType).find((item) => item.value === entry.itemValue)

      if (!selectedItem) {
        throw new Error('Select an add-on service item first.')
      }

      const response = await api.post(`/bookings/${selectedBooking.value.code}/addons`, {
        addonType: entry.addonType,
        referenceId: selectedItem.itemRef,
        serviceName: selectedItem.serviceName,
        addonLabel: selectedItem.addonLabel,
        serviceDate: entry.serviceDate,
        startDate: entry.serviceDate,
        endDate: entry.endDate,
        quantity: getAddonQuantity(entry),
        unitPriceValue: selectedItem.unitPriceValue,
        vendorUnitPriceValue: selectedItem.vendorUnitPriceValue ?? 0,
        status: entry.status,
        notes: '',
      })

      updatedBooking = response.data?.data ?? updatedBooking
    }

    if (updatedBooking) {
      replaceBookingRow(updatedBooking)
      syncBookingCollections(bookings.value)
      selectedBookingCode.value = updatedBooking.code
    }

    addonResult.value = {
      tone: 'success',
      text: `${addonEntries.value.length} add-on(s) were added successfully to booking ${selectedBooking.value.code}.`,
    }
    showAddonModal.value = false
  } catch (error) {
    addonResult.value = {
      tone: 'error',
      text: error?.response?.data?.message || error?.message || 'Failed to add the add-on to the booking.',
    }
  }
}

const cancelAddon = async (addon) => {
  if (!selectedBooking.value) {
    return
  }

  addonResult.value = { tone: '', text: '' }

  try {
    const response = await api.patch(`/bookings/${selectedBooking.value.code}/addons/${addon.id}`, {
      status: 'Cancelled',
    })
    const updatedBooking = response.data?.data

    if (updatedBooking) {
      replaceBookingRow(updatedBooking)
      syncBookingCollections(bookings.value)
      selectedBookingCode.value = updatedBooking.code
    }

    addonResult.value = {
      tone: 'success',
      text: `Add-on ${addon.addonLabel} was cancelled successfully.`,
    }
  } catch (error) {
    addonResult.value = {
      tone: 'error',
      text: error?.response?.data?.message || error?.message || 'Failed to cancel the add-on.',
    }
  }
}

const deleteAddon = async (addon) => {
  if (!selectedBooking.value) {
    return
  }

  addonResult.value = { tone: '', text: '' }

  try {
    const response = await api.delete(`/bookings/${selectedBooking.value.code}/addons/${addon.id}`)
    const updatedBooking = response.data?.data

    if (updatedBooking) {
      replaceBookingRow(updatedBooking)
      syncBookingCollections(bookings.value)
      selectedBookingCode.value = updatedBooking.code
    }

    addonResult.value = {
      tone: 'success',
      text: `Add-on ${addon.addonLabel} was removed successfully.`,
    }
  } catch (error) {
    addonResult.value = {
      tone: 'error',
      text: error?.response?.data?.message || error?.message || 'Failed to delete the add-on.',
    }
  }
}

const handleCalendarSelect = (bookingCode) => {
  selectBooking(bookingCode)
  openInsightModal(bookingCode)
}

const openPaymentModal = (bookingCode = '') => {
  if (bookingCode) {
    selectBooking(bookingCode)
  }

  if (!selectedBooking.value) {
    bookingsResult.value = { tone: 'error', text: 'Select a booking first before recording a payment.' }
    return
  }

  paymentResult.value = { tone: '', text: '' }
  paymentForm.paymentDate = new Date().toISOString().slice(0, 10)
  paymentForm.method = paymentMethods[0]
  paymentForm.amountValue = selectedBooking.value?.balanceValue > 0 ? String(selectedBooking.value.balanceValue) : ''
  paymentForm.referenceNo = ''
  paymentForm.note = ''

  if (Number(selectedBooking.value?.balanceValue ?? 0) <= 0) {
    paymentResult.value = {
      tone: 'error',
      text: 'Booking ini sudah lunas. Tidak ada outstanding yang perlu diposting.',
    }
    return
  }
  
  if (showInsightModal.value) {
    closeInsightModal()
  }
  
  showPaymentModal.value = true
}

const closePaymentModal = () => {
  showPaymentModal.value = false
}

const submitPayment = async () => {
  paymentResult.value = { tone: '', text: '' }

  if (!selectedBooking.value) {
    paymentResult.value = { tone: 'error', text: 'Booking belum dipilih.' }
    return
  }

  if (Number(selectedBooking.value.balanceValue ?? 0) <= 0) {
    paymentResult.value = { tone: 'error', text: 'Booking ini sudah lunas.' }
    return
  }

  try {
    const payment = await hotel.recordPayment({
      bookingCode: selectedBooking.value.code,
      paymentDate: paymentForm.paymentDate,
      method: paymentForm.method,
      amountValue: paymentForm.amountValue,
      referenceNo: paymentForm.referenceNo,
      note: paymentForm.note,
    })

    await Promise.all([loadBookings(), loadPayments()])
    
    paymentResult.value = {
      tone: 'success',
      text: `Payment ${payment.amount} was posted successfully. The balance is now synced.`,
    }
    
    setTimeout(() => {
        closePaymentModal()
        if (selectedBooking.value) {
            openInsightModal(selectedBooking.value.code)
        }
    }, 1500)
  } catch (error) {
    paymentResult.value = {
      tone: 'error',
      text: error?.response?.data?.message || (error instanceof Error ? error.message : 'Failed to post the payment.'),
    }
  }
}

const shiftCalendar = (days) => {
  visibleCalendarStart.value = addDays(visibleCalendarStart.value, days)
}

const goCalendarToday = () => {
  visibleCalendarStart.value = todayDateKey()
}

const syncPrintTemplate = () => {
  printTemplate.value = loadPrintTemplateSettings()
}

onMounted(() => {
  if (typeof window === 'undefined') {
    return
  }

  window.addEventListener(PRINT_TEMPLATE_UPDATED_EVENT, syncPrintTemplate)
})

onBeforeUnmount(() => {
  if (typeof window === 'undefined') {
    return
  }

  window.removeEventListener(PRINT_TEMPLATE_UPDATED_EVENT, syncPrintTemplate)
})

loadRooms()
Promise.all([loadBookings(), loadPayments(), loadCancellationPolicy(), loadAddonCatalog()])
</script>

<template>
  <StayViewCalendar
    :calendar-dates="calendarDates"
    :stay-calendar="stayCalendarGroups"
    :stay-summary="staySummary"
    :calendar-range-label="calendarRangeLabel"
    :selected-code="selectedBookingCode"
    interactive
    @select-booking="handleCalendarSelect"
    @select-slot="openBookingFromCalendarSlot"
    @navigate-prev="shiftCalendar(-7)"
    @navigate-next="shiftCalendar(7)"
    @navigate-today="goCalendarToday"
  />

  <section class="page-grid">
    <article class="panel-card panel-dense">
      <LoadingState v-if="loadingBookings" label="Loading bookings from the database..." overlay />

      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Reservations control</p>
          <h3>Booking list</h3>
        </div>
        <div class="kpi-inline">
          <span>{{ filteredBookings.length }} visible bookings</span>
          <span>{{ bookings.length }} total records</span>
          <button
            class="action-button"
            :disabled="!selectedBooking"
            @click="openAddonModal(selectedBooking?.code ?? '')"
          >
            Add add-on to selected booking
          </button>
          <button class="action-button primary" @click="openBookingPage">Create new reservation</button>
        </div>
      </div>

      <div class="table-toolbar">
        <div class="toolbar-tabs">
          <button
            v-for="item in ['All', 'Tentative', 'Confirmed', 'Checked-in', 'Checked-out', 'Cancelled', 'No-Show']"
            :key="item"
            class="toolbar-tab"
            :class="{ active: bookingStatus === item }"
            @click="bookingStatus = item"
          >
            {{ item }}
          </button>
        </div>
        <div class="booking-filter-row">
          <DateTimePickerField
            v-model="bookingDateStart"
            :enable-time="false"
            placeholder="Check-in dari"
          />
          <DateTimePickerField
            v-model="bookingDateEnd"
            :enable-time="false"
            placeholder="Check-in sampai"
          />
          <input
            v-model="bookingSearch"
            class="toolbar-search"
            placeholder="Search booking / guest / room"
          />
        </div>
      </div>

      <div v-if="bookingsResult.text" class="booking-feedback" :class="bookingsResult.tone">
        {{ bookingsResult.text }}
      </div>

      <div v-if="addonResult.text" class="booking-feedback" :class="addonResult.tone">
        {{ addonResult.text }}
      </div>

      <div class="table-scroll">
        <table v-smart-table class="data-table booking-data-table">
          <thead>
            <tr>
              <th>Code</th>
              <th>Guest</th>
              <th>Stay date</th>
              <th>Room</th>
              <th>Source</th>
              <th>Room amount</th>
              <th>Add-on</th>
              <th>Grand total</th>
              <th class="booking-status-col">Status</th>
              <th class="booking-action-col">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!loadingBookings && !filteredBookings.length">
              <td colspan="10" class="table-empty-cell">There are no reservations in the database yet.</td>
            </tr>
            <tr
              v-for="item in filteredBookings"
              :key="item.code"
              class="booking-table-row"
              :class="{ selected: selectedBookingCode === item.code }"
              @click="selectBooking(item.code)"
            >
              <td><strong>{{ item.code }}</strong></td>
              <td>{{ item.guest }}</td>
              <td>{{ String(item.checkIn ?? '').slice(0, 10) }} - {{ String(item.checkOut ?? '').slice(0, 10) }}</td>
              <td>{{ item.room }}</td>
              <td>{{ item.channel }}</td>
              <td>{{ item.amount }}</td>
              <td>{{ item.addonsTotal }}</td>
              <td><strong>{{ item.grandTotal }}</strong></td>
              <td class="booking-status-cell">{{ item.status }}</td>
              <td class="booking-action-cell">
                <div class="modal-actions booking-table-actions">
                  <button class="action-button" @click.stop="openInsightModal(item.code)">Insight</button>
                  <button class="action-button" @click.stop="openEditPage(item.code)">Edit</button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </article>
  </section>

  <div v-if="showAddonModal" class="modal-backdrop" @click.self="closeAddonModal()">
    <section class="modal-card modal-card-scroll-lock">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Booking add-on</p>
          <h3>Add add-ons to {{ selectedBooking?.code }}</h3>
        </div>
        <button class="action-button" @click="closeAddonModal()">Close</button>
      </div>

      <div class="modal-scroll-body">
        <div class="room-select-stack">
        <div
          v-for="(entry, index) in addonEntries"
          :key="entry.id"
          class="room-select-card"
        >
          <div class="split-row">
            <strong>Add-on {{ index + 1 }}</strong>
            <button
              v-if="addonEntries.length > 1"
              type="button"
              class="action-button room-select-remove"
              @click="removeAddonEntry(entry.id)"
            >
              Remove
            </button>
          </div>

          <div class="booking-addon-entry-grid">
            <div class="booking-addon-entry-row" :class="{ 'booking-addon-entry-row-scooter': isScooterAddon(entry) }">
              <label class="field-stack">
                <span>Add-on type</span>
                <Select2Field
                  v-model="entry.addonType"
                  :options="addonTypeOptions"
                  :multiple="false"
                  placeholder="Select add-on type"
                  @update:modelValue="handleAddonTypeChange(entry)"
                />
              </label>

              <template v-if="isScooterAddon(entry)">
                <label class="field-stack">
                  <span>Service date</span>
                  <input v-model="entry.serviceDate" class="form-control" type="date" @input="handleAddonServiceDateChange(entry)" />
                </label>

                <label class="field-stack">
                  <span>Service end</span>
                  <input v-model="entry.endDate" class="form-control" :min="entry.serviceDate" type="date" />
                </label>
              </template>

              <label v-else class="field-stack">
                <span>Service date</span>
                <input v-model="entry.serviceDate" class="form-control" type="date" />
              </label>

              <label class="field-stack">
                <span>Status</span>
                <Select2Field
                  v-model="entry.status"
                  :options="addonStatusSelectOptions"
                  :multiple="false"
                  placeholder="Status"
                />
              </label>
            </div>

            <label class="field-stack booking-addon-item-field">
              <span>Service item</span>
              <Select2Field
                v-model="entry.itemValue"
                :options="getAddonItemOptions(entry.addonType)"
                :multiple="false"
                placeholder="Select a service from the add-on master"
              />
              <p class="subtle">{{ getAddonItemCountLabel(entry) }}</p>
            </label>
          </div>
        </div>

          <button type="button" class="action-button" @click="addAddonEntry">Add add-on row</button>
        </div>

        <div class="booking-inline-summary">
          <div class="note-cell">
            <strong>Booking target</strong>
            <p class="subtle">{{ selectedBooking?.guest }} | {{ selectedBooking?.room }}</p>
          </div>
          <div class="note-cell">
            <strong>Preview total</strong>
            <p class="subtle">{{ addonPreviewTotal ?? 'Select a service item first' }}</p>
          </div>
        </div>

        <div v-if="addonResult.text" class="booking-feedback" :class="addonResult.tone">
          {{ addonResult.text }}
        </div>
      </div>

      <div class="modal-actions">
        <button class="action-button" @click="closeAddonModal()">Cancel</button>
        <button class="action-button primary" @click="submitAddon">Attach add-ons</button>
      </div>
    </section>
  </div>

  <div v-if="showInsightModal && selectedBooking" class="modal-backdrop" @click.self="closeInsightModal()">
    <section class="modal-card">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Booking insight</p>
          <h3>{{ selectedBooking.code }} | {{ selectedBooking.guest }}</h3>
        </div>
        <button class="action-button" @click="closeInsightModal()">Close</button>
      </div>

      <div class="booking-detail-grid">
        <div class="note-cell">
          <strong>{{ selectedBooking.guest }}</strong>
          <p class="subtle">{{ selectedBooking.roomCount }} room(s): {{ selectedBooking.room }}</p>
        </div>
        <div class="note-cell">
          <strong>{{ selectedBooking.status }}</strong>
          <p class="subtle">Source: {{ selectedBooking.channel }}</p>
        </div>
        <div class="note-cell">
          <strong>Stay dates</strong>
          <p class="subtle">{{ selectedBooking.checkIn }} to {{ selectedBooking.checkOut }}</p>
        </div>
        <div class="note-cell">
          <strong>Pax / ops</strong>
          <p class="subtle">{{ selectedBooking.adults }} adult(s), {{ selectedBooking.children }} child(ren)</p>
        </div>

        <div class="note-cell booking-detail-main">
          <strong>Room details</strong>
          <div class="booking-room-detail-list">
            <div v-for="room in selectedBooking.roomDetails" :key="room.room" class="booking-room-detail-item">
              <strong>Room {{ room.room }}</strong>
              <p class="subtle">{{ room.adults }} adult(s), {{ room.children }} child(ren)</p>
            </div>
          </div>
        </div>

        <div class="note-cell booking-detail-main">
          <strong>Booking amount</strong>
          <p class="subtle">Room: {{ selectedBooking.amount }}</p>
          <p class="subtle">Add-ons: {{ selectedBooking.addonsTotal }}</p>
          <p class="subtle"><strong>Total: {{ selectedBooking.grandTotal }}</strong></p>
        </div>

        <div class="note-cell booking-detail-main">
          <strong>Notes</strong>
          <p class="subtle">{{ selectedBooking.note || 'No additional notes' }}</p>
        </div>
      </div>

      <div class="modal-actions">
        <button
          v-if="canCheckIn"
          class="action-button"
          @click="checkInBooking(selectedBooking.code)"
        >
          Check-in
        </button>
        <button
          v-if="canCheckOut"
          class="action-button"
          @click="checkOutBooking(selectedBooking.code)"
        >
          Check-out
        </button>
        <button class="action-button" @click="closeInsightModal(); openInvoicePreview(selectedBooking.code)">Invoice / Print</button>
        <button class="action-button" @click="closeInsightModal(); openEditPage(selectedBooking.code)">Edit booking</button>
        <button class="action-button" @click="closeInsightModal(); openAddonListModal(selectedBooking.code)">View add-ons</button>
        <button class="action-button primary" @click="closeInsightModal(); openPaymentModal(selectedBooking.code)">Payment / Settlement</button>
      </div>
    </section>
  </div>

  <div v-if="showInvoiceModal && invoicePreview" class="modal-backdrop" @click.self="closeInvoiceModal()">
    <section class="modal-card invoice-modal-card">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Invoice preview</p>
          <h3>{{ invoicePreview.invoiceNo }} | {{ invoicePreview.guest }}</h3>
        </div>
        <button class="action-button" @click="closeInvoiceModal()">Close</button>
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
              <strong>{{ invoicePreview.invoiceNo }}</strong>
            </div>
            <div>
              <span class="invoice-meta-label">Booking Ref.</span>
              <strong>{{ invoicePreview.bookingCode }}</strong>
            </div>
            <div>
              <span class="invoice-meta-label">Status</span>
              <strong>{{ invoicePreview.paymentStatus }}</strong>
            </div>
          </div>
        </header>

        <section class="invoice-doc-grid">
          <div class="note-cell">
            <strong>Bill to</strong>
            <p class="subtle">{{ invoicePreview.guest }}</p>
            <p class="subtle">{{ invoicePreview.channel }} booking</p>
          </div>
          <div class="note-cell">
            <strong>Stay details</strong>
            <p class="subtle">{{ invoicePreview.stayLabel }}</p>
            <p class="subtle">{{ invoicePreview.nightsLabel }} | {{ invoicePreview.roomCount }} room(s)</p>
          </div>
          <div class="note-cell">
            <strong>Document dates</strong>
            <p class="subtle">{{ invoicePreview.issueDate }} / {{ invoicePreview.dueDate }}</p>
          </div>
          <div class="note-cell">
            <strong>Room</strong>
            <p class="subtle">{{ invoicePreview.roomCount }} room(s) | {{ invoicePreview.roomLabel }}</p>
          </div>
        </section>

        <section class="invoice-section">
          <div class="panel-head panel-head-tight">
            <div>
              <p class="eyebrow-dark">Room charges</p>
              <h3>Room details</h3>
            </div>
            <strong>{{ invoicePreview.roomTotal }}</strong>
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
                <tr v-for="room in invoicePreview.roomLines" :key="room.id">
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
              <h3>Additional purchases and services</h3>
            </div>
            <strong>{{ invoicePreview.addonTotal }}</strong>
          </div>

          <div v-if="invoicePreview.addonLines.length" class="table-scroll">
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
                <tr v-for="item in invoicePreview.addonLines" :key="item.id">
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
          <p v-else class="subtle booking-addon-empty">There are no add-ons in this invoice yet.</p>
        </section>

        <section class="invoice-charge-summary">
          <div class="invoice-charge-row">
            <span>Room charges</span>
            <strong>{{ invoicePreview.roomTotal }}</strong>
          </div>
          <div class="invoice-charge-row">
            <span>Add-on services</span>
            <strong>{{ invoicePreview.addonTotal }}</strong>
          </div>
          <div class="invoice-charge-row">
            <span>Total invoice</span>
            <strong>{{ invoicePreview.subtotal }}</strong>
          </div>
          <div class="invoice-charge-row">
            <span>Paid</span>
            <strong>{{ invoicePreview.paid }}</strong>
          </div>
          <div class="invoice-charge-row balance">
            <span>Outstanding balance</span>
            <strong>{{ invoicePreview.balance }}</strong>
          </div>
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
            <strong>Settlement status</strong>
            <p class="subtle">{{ invoicePreview.paymentStatus }}</p>
          </div>
        </section>

        <section class="invoice-section">
          <div class="panel-head panel-head-tight">
            <div>
              <p class="eyebrow-dark">Payment history</p>
              <h3>Payment history</h3>
            </div>
          </div>

          <div v-if="invoicePreview.payments.length" class="table-scroll">
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
                <tr v-for="payment in invoicePreview.payments" :key="payment.id">
                  <td>{{ payment.date }}</td>
                  <td><strong>{{ payment.type }}</strong></td>
                  <td>{{ payment.method }}</td>
                  <td>{{ payment.reference }}</td>
                  <td>{{ payment.amountLabel }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-else class="subtle booking-addon-empty">There are no posted payments for this invoice yet.</p>
        </section>

        <p v-if="invoicePreview.note" class="subtle invoice-print-note">
          Booking note: {{ invoicePreview.note }}
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

      <section v-if="false && invoicePrintDraft" class="invoice-editor-shell">
        <article class="panel-card panel-dense invoice-editor-panel">
          <div class="panel-head panel-head-tight">
            <div>
              <p class="eyebrow-dark">TCPDF editor</p>
              <h3>Manual invoice override</h3>
              <p class="panel-note">Field di bawah akan dipakai oleh preview backend dan PDF TCPDF.</p>
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
              <span class="subtle">Ubah kamar, tipe, rate, dan total secara manual.</span>
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
              <span class="subtle">Jumlah dan nominal add-on bisa dioverride.</span>
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

          <div class="modal-actions">
            <button class="action-button" @click="closeInvoiceModal()">Back</button>
            <button class="action-button" @click="openInvoicePreviewPrintPage">Open PDF Print</button>
            <button class="action-button primary" @click="downloadInvoicePreviewPdf">Download PDF</button>
          </div>
      </section>
    </div>

  <div v-if="showAddonListModal && selectedBooking" class="modal-backdrop" @click.self="closeAddonListModal()">
    <section class="modal-card">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Booking add-ons</p>
          <h3>{{ selectedBooking.code }} | {{ selectedBooking.guest }}</h3>
        </div>
        <button class="action-button" @click="closeAddonListModal()">Close</button>
      </div>

      <div class="booking-inline-summary">
        <div class="note-cell">
          <strong>Booking target</strong>
          <p class="subtle">{{ selectedBooking.room }} | {{ selectedBooking.channel }}</p>
        </div>
        <div class="note-cell">
          <strong>Add-ons total</strong>
          <p class="subtle">{{ selectedBooking.addonsTotal }}</p>
        </div>
      </div>

      <div v-if="addonResult.text" class="booking-feedback" :class="addonResult.tone">
        {{ addonResult.text }}
      </div>

      <div v-if="selectedBooking.addons?.length" class="booking-addon-list">
        <div v-for="addon in selectedBooking.addons" :key="addon.id" class="booking-addon-item">
          <div class="split-row">
            <strong>{{ addon.addonLabel }}</strong>
            <span>{{ addon.totalPrice }}</span>
          </div>
          <p class="subtle">{{ addon.serviceName }}</p>
          <p class="subtle">{{ addon.serviceDateLabel ?? addon.serviceDate }} | Qty {{ addon.quantity }} | {{ addon.status }}</p>
          <p class="subtle">{{ addon.notes || 'No additional note' }}</p>
          <div class="modal-actions">
            <button
              v-if="addon.status !== 'Cancelled'"
              class="action-button"
              @click="cancelAddon(addon)"
            >
              Cancel
            </button>
            <button
              class="action-button"
              @click="deleteAddon(addon)"
            >
              Delete
            </button>
          </div>
        </div>
      </div>
      <p v-else class="subtle booking-addon-empty">
        There are no add-ons attached to this booking yet.
      </p>

      <div class="modal-actions">
        <button class="action-button" @click="closeAddonListModal()">Done</button>
        <button
          class="action-button primary"
          @click="closeAddonListModal(); openAddonModal(selectedBooking.code)"
        >
          Add add-on
        </button>
      </div>
    </section>
  </div>

  <div v-if="showPaymentModal && selectedBooking" class="modal-backdrop" @click.self="closePaymentModal()">
    <section class="modal-card">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Payment entry (Front Office)</p>
          <h3>{{ selectedBooking.code }} | {{ selectedBooking.guest }}</h3>
        </div>
        <button class="action-button" @click="closePaymentModal()">Close</button>
      </div>

      <div class="booking-inline-summary">
        <div class="note-cell">
          <strong>Grand Total</strong>
          <p class="subtle">{{ selectedBooking.grandTotal }}</p>
        </div>
        <div class="note-cell">
          <strong>Outstanding (Unpaid)</strong>
          <p class="subtle" style="font-weight: bold; color: darkred;">{{ selectedBookingBalanceLabel }}</p>
        </div>
      </div>

      <div class="booking-form-grid">
        <label class="field-stack">
          <span>Payment date</span>
          <input v-model="paymentForm.paymentDate" class="form-control" type="date" />
        </label>

        <label class="field-stack">
          <span>Metode</span>
          <select v-model="paymentForm.method" class="form-control">
            <option v-for="item in paymentMethods" :key="item" :value="item">{{ item }}</option>
          </select>
        </label>

        <label class="field-stack">
          <span>Amount (IDR)</span>
          <input 
            v-model="displayAmount" 
            class="form-control" 
            type="text" 
            @focus="isAmountFocused = true"
            @blur="isAmountFocused = false"
          />
        </label>

        <label class="field-stack">
          <span>No referensi / Kartu</span>
          <input v-model="paymentForm.referenceNo" class="form-control" placeholder="Nomor kuitansi / referensi" />
        </label>

        <label class="field-stack field-span-2">
          <span>Notes / Description</span>
          <textarea
            v-model="paymentForm.note"
            class="form-control form-textarea"
            placeholder="Example: initial deposit, settlement of remaining balance, etc."
          ></textarea>
        </label>
      </div>

      <div v-if="paymentResult.text" class="booking-feedback" :class="paymentResult.tone">
        {{ paymentResult.text }}
      </div>

      <div class="modal-actions">
        <button class="action-button" @click="closePaymentModal()">Cancel</button>
        <button class="action-button primary" @click="submitPayment">Post settlement</button>
      </div>
    </section>
  </div>

  <div v-if="showStatusConfirmModal" class="modal-backdrop" @click.self="closeStatusConfirmModal()">
    <section class="modal-card">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Booking confirmation</p>
          <h3>{{ statusConfirmState.title }}</h3>
        </div>
        <button class="action-button" @click="closeStatusConfirmModal()">Close</button>
      </div>

      <div class="booking-inline-summary">
        <div class="note-cell">
          <strong>Booking</strong>
          <p class="subtle">{{ statusConfirmState.bookingCode }}</p>
        </div>
        <div class="note-cell">
          <strong>Guest</strong>
          <p class="subtle">{{ statusConfirmState.guest }}</p>
        </div>
      </div>

      <p class="subtle" style="margin-bottom: 12px;">{{ statusConfirmState.description }}</p>

      <div v-if="statusConfirmState.penaltyText" class="booking-feedback success">
        {{ statusConfirmState.penaltyText }}
      </div>

      <div v-if="statusConfirmState.warningText" class="booking-feedback error">
        {{ statusConfirmState.warningText }}
      </div>

      <div v-if="statusConfirmState.nextStatus === 'Checked-out'">
        <div v-if="loadingCheckoutInventory" class="booking-feedback">
          Loading room inventory usage...
        </div>

        <template v-else-if="checkoutInventoryRows.length">
          <div class="booking-inline-summary" style="margin-top: 12px;">
            <div class="note-cell">
              <strong>Dipakai tamu</strong>
              <p class="subtle">{{ checkoutInventoryTotalUsed }} item</p>
            </div>
            <div class="note-cell">
              <strong>Kembali ke stok</strong>
              <p class="subtle">{{ checkoutInventoryTotalReturned }} item</p>
            </div>
            <div class="note-cell">
              <strong>Estimasi biaya pakai</strong>
              <p class="subtle">{{ formatCurrency(checkoutInventoryTotalUsedCost) }}</p>
            </div>
          </div>

          <div class="table-scroll" style="margin-top: 12px;">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Room</th>
                  <th>Item</th>
                  <th>Assign terakhir</th>
                  <th>Total assign</th>
                  <th>Qty sekarang</th>
                  <th>Dipakai</th>
                  <th>Kembali</th>
                  <th>Biaya pakai</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in checkoutInventoryRows" :key="row.id">
                  <td><strong>{{ row.roomNo }}</strong></td>
                  <td>{{ row.itemName }}</td>
                  <td>
                    {{ row.lastIssueQty }} {{ row.unit }}
                    <div class="subtle">{{ row.lastIssueDate || '-' }}</div>
                  </td>
                  <td>{{ row.totalIssuedQty }} {{ row.unit }}</td>
                  <td>{{ row.outstandingQty }} {{ row.unit }}</td>
                  <td style="max-width: 120px;">
                    <input
                      v-model="row.usedQty"
                      class="form-control"
                      type="number"
                      min="0"
                      :max="row.outstandingQty"
                      step="1"
                      @input="row.usedQty = Math.max(0, Math.min(Number(row.usedQty || 0), Number(row.outstandingQty || 0)))"
                    />
                  </td>
                  <td>{{ Math.max(0, Number(row.outstandingQty || 0) - Number(row.usedQty || 0)) }} {{ row.unit }}</td>
                  <td>{{ formatCurrency(Number(row.usedQty || 0) * Number(row.latestUnitCost || 0)) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>

        <div v-else class="booking-feedback success" style="margin-top: 12px;">
          Tidak ada item kamar yang masih outstanding untuk booking ini.
        </div>
      </div>

      <div class="modal-actions">
        <button class="action-button" @click="closeStatusConfirmModal()">Back</button>
        <button class="action-button primary" @click="submitStatusConfirmation">
          {{ statusConfirmState.confirmLabel }}
        </button>
      </div>
    </section>
  </div>
</template>
