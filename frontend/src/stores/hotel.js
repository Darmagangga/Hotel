import { defineStore } from 'pinia'
import api from '../services/api'

const currencyFormatter = new Intl.NumberFormat('id-ID', {
  style: 'currency',
  currency: 'IDR',
  maximumFractionDigits: 0,
})

const toCurrency = (amount) => currencyFormatter.format(amount)
const toDateKey = (value) => String(value ?? '').slice(0, 10)

const overlap = (startA, endA, startB, endB) => startA < endB && startB < endA

const paxLabel = (adults, children) => {
  if (children > 0) {
    return `${adults} adults, ${children} child`
  }

  return `${adults} adults`
}

const totalPax = (roomDetails, key) =>
  roomDetails.reduce((total, item) => total + Number(item?.[key] ?? 0), 0)

const addonStatusOptions = ['Planned', 'Confirmed', 'Posted']

const roomRevenueCoaMap = {
  'Deluxe Garden': '4-1101 Room Revenue - Deluxe Garden',
  'Ocean Suite': '4-1102 Room Revenue - Ocean Suite',
  'Villa Pool': '4-1103 Room Revenue - Villa Pool',
  'Suite Family': '4-1104 Room Revenue - Suite Family',
  'Deluxe Sea': '4-1105 Room Revenue - Deluxe Sea',
  Unassigned: '4-1199 Room Revenue - Unassigned',
}

const roomReceivableCoaMap = {
  'Deluxe Garden': '1-1101 Room Receivable - Deluxe Garden',
  'Ocean Suite': '1-1102 Room Receivable - Ocean Suite',
  'Villa Pool': '1-1103 Room Receivable - Villa Pool',
  'Suite Family': '1-1104 Room Receivable - Suite Family',
  'Deluxe Sea': '1-1105 Room Receivable - Deluxe Sea',
  Unassigned: '1-1199 Room Receivable - Unassigned',
}

const buildRoomName = (room) => room.name ?? `Room ${room.no}`
const buildRoomRevenueCoa = (room) =>
  room.coaRevenue ?? room.coa ?? roomRevenueCoaMap[room.type] ?? roomRevenueCoaMap.Unassigned
const buildRoomReceivableCoa = (room) =>
  room.coaReceivable ?? roomReceivableCoaMap[room.type] ?? roomReceivableCoaMap.Unassigned
const buildInvoiceNumber = (bookingCode) => `INV-${String(bookingCode ?? '').replace(/^BK-/, '')}`
const toNumber = (value) => {
  const amount = Number(value ?? 0)
  return Number.isFinite(amount) ? amount : 0
}
const calendarDayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']
const calendarMonthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
const MS_PER_DAY = 24 * 60 * 60 * 1000

const toUtcDate = (value) => {
  const [year, month, day] = String(value ?? '')
    .split('-')
    .map(Number)
  return new Date(Date.UTC(year, (month || 1) - 1, day || 1))
}

const toIsoDate = (date) => {
  const year = date.getUTCFullYear()
  const month = String(date.getUTCMonth() + 1).padStart(2, '0')
  const day = String(date.getUTCDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

const addDays = (value, days) => {
  const date = toUtcDate(value)
  date.setUTCDate(date.getUTCDate() + days)
  return toIsoDate(date)
}

const diffDays = (start, end) => Math.round((toUtcDate(end) - toUtcDate(start)) / MS_PER_DAY)
const todayDateKey = () => toIsoDate(new Date())
const buildCurrentDateLabel = (dateKey) => {
  const date = toUtcDate(dateKey)
  return `${date.toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' })} | Day Shift`
}

const generateCalendarDates = (startDate, length, businessDate) =>
  Array.from({ length }, (_, index) => {
    const key = addDays(startDate, index)
    const date = toUtcDate(key)
    return {
      key,
      day: calendarDayNames[date.getUTCDay()],
      date: String(date.getUTCDate()),
      label: `${String(date.getUTCDate()).padStart(2, '0')} ${calendarMonthNames[date.getUTCMonth()]}`,
      today: key === businessDate,
      weekend: [0, 6].includes(date.getUTCDay()),
    }
  })

const buildCalendarRangeLabel = (calendarDates) => {
  if (!calendarDates.length) {
    return ''
  }

  const first = calendarDates[0]
  const last = calendarDates[calendarDates.length - 1]
  return `${first.label} ${first.key.slice(0, 4)} - ${last.label} ${last.key.slice(0, 4)}`
}

const buildRoomFlag = (room) => {
  if (room.status === 'repair') {
    return 'OOO'
  }

  if (room.status === 'occupied') {
    return 'OC'
  }

  if (room.status === 'cleaning') {
    return 'VD'
  }

  return 'VC'
}

const buildBookingStatus = (booking, businessDate) => {
  if (booking.checkIn === businessDate) {
    return 'arriving'
  }

  if (booking.checkOut === businessDate) {
    return 'due-out'
  }

  if (booking.checkIn < businessDate && booking.checkOut > businessDate) {
    return 'checked-in'
  }

  return 'confirmed'
}

export const useHotelStore = defineStore('hotel', {
  state: () => {
    const initialBusinessDate = todayDateKey()
    const initialCalendarDates = generateCalendarDates(initialBusinessDate, 7, initialBusinessDate)
    return ({
    user: null,
    hotelName: 'Udara Hideaway Villa',
    tagline: 'Centralized control for booking, rooms, finance, stock, and guest add-ons.',
    currentDateLabel: buildCurrentDateLabel(initialBusinessDate),
    currentBusinessDate: initialBusinessDate,
    calendarBaseDate: initialBusinessDate,
    overview: [
      { label: 'Occupancy', value: '50%', note: '4 of 8 rooms occupied' },
      { label: 'Arrivals', value: '3', note: 'Priority check-ins before 14:00' },
      { label: 'Departures', value: '1', note: '1 late check-out requested' },
      { label: 'Revenue', value: 'IDR 5.2M', note: 'Room, add-ons, and walk-ins' },
    ],
    revenueMix: [],
    bookingChannels: ['Booking', 'Direct', 'Airbnb', 'Booking.com'],
    bookingStatuses: ['Tentative', 'Confirmed', 'Checked-in', 'Checked-out', 'Cancelled', 'No-Show'],
    selectedBookingCode: null,
    bookings: [],
    bookingAddons: [],
    paymentTransactions: [],
    generalJournals: [],
    coaAccounts: [
      {
        code: '111001',
        name: 'Kas Udara',
        category: 'Asset',
        normalBalance: 'Debit',
        note: 'Kas dan Setara Kas',
        active: true,
      },
      {
        code: '111002',
        name: 'Kas Tenty',
        category: 'Asset',
        normalBalance: 'Debit',
        note: 'Kas dan Setara Kas',
        active: true,
      },
      {
        code: '111003',
        name: 'Kas Besar',
        category: 'Asset',
        normalBalance: 'Debit',
        note: 'Kas dan Setara Kas',
        active: true,
      },
      {
        code: '111004',
        name: 'Merchand BCA',
        category: 'Asset',
        normalBalance: 'Debit',
        note: 'Kas dan Setara Kas',
        active: true,
      },
      {
        code: '111005',
        name: 'BCA 146.5050.600 Fuad',
        category: 'Asset',
        normalBalance: 'Debit',
        note: 'Kas dan Setara Kas',
        active: true,
      },
      {
        code: '111006',
        name: 'BCA EV 7705.345.678 Evelyn',
        category: 'Asset',
        normalBalance: 'Debit',
        note: 'Kas dan Setara Kas',
        active: true,
      },
      {
        code: '111007',
        name: 'BRI',
        category: 'Asset',
        normalBalance: 'Debit',
        note: 'Kas dan Setara Kas',
        active: true,
      },
      {
        code: '111008',
        name: 'BLU',
        category: 'Asset',
        normalBalance: 'Debit',
        note: 'Kas dan Setara Kas',
        active: true,
      },
      {
        code: '111009',
        name: 'BCA Proyek',
        category: 'Asset',
        normalBalance: 'Debit',
        note: 'Kas dan Setara Kas',
        active: true,
      },
      {
        code: '111010',
        name: 'Credit Card Alkasa',
        category: 'Asset',
        normalBalance: 'Debit',
        note: 'Kas dan Setara Kas',
        active: true,
      },
      {
        code: '111011',
        name: 'Prive Fuad',
        category: 'Asset',
        normalBalance: 'Debit',
        note: 'Kas dan Setara Kas',
        active: true,
      },
      {
        code: '111012',
        name: 'Bank Mandiri',
        category: 'Asset',
        normalBalance: 'Debit',
        note: 'Kas dan Setara Kas',
        active: true,
      },
      {
        code: '111013',
        name: 'BCA Sam',
        category: 'Asset',
        normalBalance: 'Debit',
        note: 'Kas dan Setara Kas',
        active: true,
      },
      {
        code: '112001',
        name: 'Piutang Dagang',
        category: 'Asset',
        normalBalance: 'Debit',
        note: 'Piutang',
        active: true,
      },
      {
        code: '113001',
        name: 'Piutang Karyawan',
        category: 'Asset',
        normalBalance: 'Debit',
        note: 'Piutang',
        active: true,
      },
      {
        code: '114001',
        name: 'Piutang Alkasa',
        category: 'Asset',
        normalBalance: 'Debit',
        note: 'Piutang',
        active: true,
      },
      {
        code: '114002',
        name: 'Piutang Kulkul',
        category: 'Asset',
        normalBalance: 'Debit',
        note: 'Piutang',
        active: true,
      },
      {
        code: '114003',
        name: 'Piutang Lain',
        category: 'Asset',
        normalBalance: 'Debit',
        note: 'Piutang',
        active: true,
      },
      {
        code: '114004',
        name: 'Piutang Kaca Ayu',
        category: 'Asset',
        normalBalance: 'Debit',
        note: 'Piutang',
        active: true,
      },
      {
        code: '115001',
        name: 'Persediaan Barang',
        category: 'Asset',
        normalBalance: 'Debit',
        note: 'Persediaan dan Uang Muka',
        active: true,
      },
      {
        code: '116001',
        name: 'Sewa Bayar Dimuka',
        category: 'Asset',
        normalBalance: 'Debit',
        note: 'Persediaan dan Uang Muka',
        active: true,
      },
      {
        code: '116002',
        name: 'Uang Muka Pembelian',
        category: 'Asset',
        normalBalance: 'Debit',
        note: 'Persediaan dan Uang Muka',
        active: true,
      },
      {
        code: '120000',
        name: 'Inventaris',
        category: 'Asset',
        normalBalance: 'Debit',
        note: 'Aktiva Tetap',
        active: true,
      },
      {
        code: '120001',
        name: 'Akum. Peny. Inventaris',
        category: 'Asset',
        normalBalance: 'Credit',
        note: 'Aktiva Tetap',
        active: true,
      },
      {
        code: '120002',
        name: 'Room 5 sd 8',
        category: 'Asset',
        normalBalance: 'Debit',
        note: 'Aktiva Tetap',
        active: true,
      },
      {
        code: '120003',
        name: 'Tanah Bias',
        category: 'Asset',
        normalBalance: 'Debit',
        note: 'Aktiva Tetap',
        active: true,
      },
      {
        code: '411017',
        name: 'Penjualan Bunga',
        category: 'Revenue',
        normalBalance: 'Credit',
        note: 'Pendapatan Usaha',
        active: true,
      },
      {
        code: '411018',
        name: 'Airbnb',
        category: 'Revenue',
        normalBalance: 'Credit',
        note: 'Pendapatan Usaha',
        active: true,
      },
      {
        code: '411019',
        name: 'Refund Kamar',
        category: 'Revenue',
        normalBalance: 'Credit',
        note: 'Pendapatan Usaha',
        active: true,
      },
      {
        code: '411020',
        name: 'Kompensasi',
        category: 'Revenue',
        normalBalance: 'Credit',
        note: 'Pendapatan Usaha',
        active: true,
      },
      {
        code: '411021',
        name: 'Pendapatan Lain',
        category: 'Revenue',
        normalBalance: 'Credit',
        note: 'Pendapatan Usaha',
        active: true,
      },
      {
        code: '411022',
        name: 'Penjualan Food / Drink',
        category: 'Revenue',
        normalBalance: 'Credit',
        note: 'Pendapatan Usaha',
        active: true,
      },
      {
        code: '411023',
        name: 'Booking.com',
        category: 'Revenue',
        normalBalance: 'Credit',
        note: 'Pendapatan Usaha',
        active: true,
      },
      {
        code: '510001',
        name: 'Pendapatan Pickup/ Drop',
        category: 'Revenue',
        normalBalance: 'Credit',
        note: 'Pendapatan Luar Usaha',
        active: true,
      },
      {
        code: '510002',
        name: 'Pendapatan Scooter',
        category: 'Revenue',
        normalBalance: 'Credit',
        note: 'Pendapatan Luar Usaha',
        active: true,
      },
      {
        code: '510003',
        name: 'Pendapatan Manta',
        category: 'Revenue',
        normalBalance: 'Credit',
        note: 'Pendapatan Luar Usaha',
        active: true,
      },
      {
        code: '510004',
        name: 'Pendapatan Ticket Boat',
        category: 'Revenue',
        normalBalance: 'Credit',
        note: 'Pendapatan Luar Usaha',
        active: true,
      },
      {
        code: '510005',
        name: 'Pendapatan Tour',
        category: 'Revenue',
        normalBalance: 'Credit',
        note: 'Pendapatan Luar Usaha',
        active: true,
      },
      {
        code: '610001',
        name: 'Biaya Kantor',
        category: 'Expense',
        normalBalance: 'Debit',
        note: 'Biaya Administrasi',
        active: true,
      },
      {
        code: '610002',
        name: 'Biaya Listrik',
        category: 'Expense',
        normalBalance: 'Debit',
        note: 'Biaya Administrasi',
        active: true,
      },
      {
        code: '610003',
        name: 'Biaya Air',
        category: 'Expense',
        normalBalance: 'Debit',
        note: 'Biaya Administrasi',
        active: true,
      },
      {
        code: '610004',
        name: 'Biaya Tunjangan Karyawan',
        category: 'Expense',
        normalBalance: 'Debit',
        note: 'Biaya Administrasi',
        active: true,
      },
      {
        code: '610005',
        name: 'Biaya Bank',
        category: 'Expense',
        normalBalance: 'Debit',
        note: 'Biaya Administrasi',
        active: true,
      },
      {
        code: '610006',
        name: 'Biaya Transport / Ongkir',
        category: 'Expense',
        normalBalance: 'Debit',
        note: 'Biaya Administrasi',
        active: true,
      },
      {
        code: '610007',
        name: 'Biaya Pemlh Kendaraan',
        category: 'Expense',
        normalBalance: 'Debit',
        note: 'Biaya Administrasi',
        active: true,
      },
      {
        code: '610008',
        name: 'Biaya Telpon',
        category: 'Expense',
        normalBalance: 'Debit',
        note: 'Biaya Administrasi',
        active: true,
      },
      {
        code: '610009',
        name: 'Biaya Gaji',
        category: 'Expense',
        normalBalance: 'Debit',
        note: 'Biaya Administrasi',
        active: true,
      },
      {
        code: '610010',
        name: 'Biaya Sewa',
        category: 'Expense',
        normalBalance: 'Debit',
        note: 'Biaya Administrasi',
        active: true,
      },
      {
        code: '610011',
        name: 'Biaya Sabun, Shampoo, Hand Soap',
        category: 'Expense',
        normalBalance: 'Debit',
        note: 'Biaya Administrasi',
        active: true,
      },
      {
        code: '610012',
        name: 'Biaya Internet',
        category: 'Expense',
        normalBalance: 'Debit',
        note: 'Biaya Administrasi',
        active: true,
      },
      {
        code: '610013',
        name: 'Biaya BF',
        category: 'Expense',
        normalBalance: 'Debit',
        note: 'Biaya Administrasi',
        active: true,
      },
      {
        code: '610014',
        name: 'Biaya Maintenance',
        category: 'Expense',
        normalBalance: 'Debit',
        note: 'Biaya Administrasi',
        active: true,
      },
      {
        code: '610015',
        name: 'Biaya Aqua Tanggung, Aqua Galon',
        category: 'Expense',
        normalBalance: 'Debit',
        note: 'Biaya Administrasi',
        active: true,
      },
      {
        code: '610016',
        name: 'Biaya Kopi, Teh, Susu, Gula dll',
        category: 'Expense',
        normalBalance: 'Debit',
        note: 'Biaya Administrasi',
        active: true,
      },
      {
        code: '610017',
        name: 'Biaya Pool',
        category: 'Expense',
        normalBalance: 'Debit',
        note: 'Biaya Administrasi',
        active: true,
      },
      {
        code: '610018',
        name: 'Biaya Card',
        category: 'Expense',
        normalBalance: 'Debit',
        note: 'Biaya Administrasi',
        active: true,
      },
      {
        code: '610019',
        name: 'Peralatan Kamar',
        category: 'Expense',
        normalBalance: 'Debit',
        note: 'Biaya Administrasi',
        active: true,
      },
    ],
    inventoryItems: [
      {
        id: 'ITM-001',
        name: 'Sabun 30ml',
        category: 'Amenity',
        unit: 'pcs',
        trackingType: 'Consumable',
        inventoryCoa: '115001 - Persediaan Barang',
        expenseCoa: '610011 - Biaya Sabun, Shampoo, Hand Soap',
        reorderLevel: 20,
      },
      {
        id: 'ITM-002',
        name: 'Handuk Mandi',
        category: 'Linen',
        unit: 'pcs',
        trackingType: 'Linen',
        inventoryCoa: '115001 - Persediaan Barang',
        expenseCoa: '610019 - Peralatan Kamar',
        reorderLevel: 8,
      },
      {
        id: 'ITM-003',
        name: 'Handuk Wajah',
        category: 'Linen',
        unit: 'pcs',
        trackingType: 'Linen',
        inventoryCoa: '115001 - Persediaan Barang',
        expenseCoa: '610019 - Peralatan Kamar',
        reorderLevel: 12,
      },
    ],
    inventoryPurchases: [
      {
        id: 'PUR-001',
        purchaseDate: '2026-03-16',
        supplier: 'Bali Amenities Supply',
        itemId: 'ITM-001',
        quantity: 100,
        unitCostValue: 5000,
        unitCost: toCurrency(5000),
        totalCostValue: 500000,
        totalCost: toCurrency(500000),
        paymentAccount: '111005 - BCA 146.5050.600 Fuad',
        note: 'Restock sabun untuk housekeeping main store',
      },
      {
        id: 'PUR-002',
        purchaseDate: '2026-03-15',
        supplier: 'Linen Kuta Abadi',
        itemId: 'ITM-002',
        quantity: 12,
        unitCostValue: 75000,
        unitCost: toCurrency(75000),
        totalCostValue: 900000,
        totalCost: toCurrency(900000),
        paymentAccount: '111012 - Bank Mandiri',
        note: 'Pembelian handuk mandi baru',
      },
      {
        id: 'PUR-003',
        purchaseDate: '2026-03-15',
        supplier: 'Linen Kuta Abadi',
        itemId: 'ITM-003',
        quantity: 18,
        unitCostValue: 35000,
        unitCost: toCurrency(35000),
        totalCostValue: 630000,
        totalCost: toCurrency(630000),
        paymentAccount: '111012 - Bank Mandiri',
        note: 'Pembelian handuk wajah baru',
      },
    ],
    roomInventoryIssues: [
      {
        id: 'ISS-001',
        issueDate: '2026-03-17',
        roomNo: '101',
        itemId: 'ITM-001',
        quantity: 2,
        note: 'Arrival amenity for guest check-in',
      },
      {
        id: 'ISS-002',
        issueDate: '2026-03-17',
        roomNo: '101',
        itemId: 'ITM-002',
        quantity: 2,
        note: 'Placed in room after laundry turnover',
      },
      {
        id: 'ISS-003',
        issueDate: '2026-03-18',
        roomNo: '104',
        itemId: 'ITM-001',
        quantity: 2,
        note: 'Rush clean room setup',
      },
    ],
    rooms: [
      { no: '101', type: 'Deluxe Garden', status: 'occupied', hk: 'Guest in house', note: 'Twin setup' },
      { no: '102', type: 'Deluxe Garden', status: 'available', hk: 'Ready', note: 'Connecting room' },
      { no: '103', type: 'Deluxe Garden', status: 'occupied', hk: 'Guest in house', note: 'Long stay guest' },
      { no: '104', type: 'Deluxe Garden', status: 'cleaning', hk: 'Rush clean', note: 'Expected arrival 14:00' },
      { no: '105', type: 'Deluxe Garden', status: 'repair', hk: 'Bathroom repair', note: 'Out of order' },
      { no: '106', type: 'Deluxe Garden', status: 'occupied', hk: 'Guest in house', note: 'Near pool access' },
      { no: '107', type: 'Deluxe Garden', status: 'occupied', hk: 'Do not disturb', note: 'Family stay' },
      { no: '108', type: 'Deluxe Garden', status: 'available', hk: 'Ready', note: 'Garden view corner room' },
    ],
    scooterBookings: [
      {
        id: 'SCT-001',
        startDate: '2026-03-18',
        endDate: '2026-03-20',
        scooterType: 'Vario 160',
        vendor: 'Bali Ride Partner',
        priceValue: 180000,
        price: toCurrency(180000),
      },
      {
        id: 'SCT-002',
        startDate: '2026-03-19',
        endDate: '2026-03-22',
        scooterType: 'NMAX',
        vendor: 'Sunset Mobility',
        priceValue: 250000,
        price: toCurrency(250000),
      },
    ],
    activityOperators: [
      {
        id: 'OPR-001',
        operator: 'Bali Ride Partner',
        priceValue: 180000,
        price: toCurrency(180000),
        note: 'Scooter harian area hotel',
      },
      {
        id: 'OPR-002',
        operator: 'Ocean Trip Nusantara',
        priceValue: 780000,
        price: toCurrency(780000),
        note: 'Island hopping operator utama',
      },
    ],
    islandTours: [
      {
        id: 'TOUR-001',
        destination: 'Nusa Penida West',
        driver: 'Made Ariana',
        costValue: 780000,
        cost: toCurrency(780000),
        note: 'Include harbour transfer',
      },
      {
        id: 'TOUR-002',
        destination: 'Ubud Waterfall Route',
        driver: 'Komang Raka',
        costValue: 650000,
        cost: toCurrency(650000),
        note: 'Private car 8 jam',
      },
    ],
    boatTickets: [
      {
        id: 'BOT-001',
        company: 'Gili Fast Express',
        destination: 'Gili Trawangan',
        priceValue: 450000,
        price: toCurrency(450000),
      },
      {
        id: 'BOT-002',
        company: 'Lombok Marine',
        destination: 'Bangsal Lombok',
        priceValue: 400000,
        price: toCurrency(400000),
      },
    ],
    transportRates: [
      {
        id: 'TRF-001',
        driver: 'Made Ariana',
        vendorPickupPriceValue: 200000,
        vendorPickupPrice: toCurrency(200000),
        vendorDropOffPriceValue: 180000,
        vendorDropOffPrice: toCurrency(180000),
        customerPickupPriceValue: 250000,
        customerPickupPrice: toCurrency(250000),
        customerDropOffPriceValue: 225000,
        customerDropOffPrice: toCurrency(225000),
        pickupPriceValue: 250000,
        pickupPrice: toCurrency(250000),
        dropOffPriceValue: 225000,
        dropOffPrice: toCurrency(225000),
        vehicle: 'Toyota Avanza',
        note: 'Driver utama airport transfer pagi',
      },
      {
        id: 'TRF-002',
        driver: 'Komang Raka',
        vendorPickupPriceValue: 225000,
        vendorPickupPrice: toCurrency(225000),
        vendorDropOffPriceValue: 200000,
        vendorDropOffPrice: toCurrency(200000),
        customerPickupPriceValue: 275000,
        customerPickupPrice: toCurrency(275000),
        customerDropOffPriceValue: 250000,
        customerDropOffPrice: toCurrency(250000),
        pickupPriceValue: 275000,
        pickupPrice: toCurrency(275000),
        dropOffPriceValue: 250000,
        dropOffPrice: toCurrency(250000),
        vehicle: 'Suzuki APV',
        note: 'Support late arrival dan charter malam',
      },
    ],
    calendarRangeLabel: buildCalendarRangeLabel(initialCalendarDates),
    calendarDates: initialCalendarDates,
    stayLegend: [
      { label: 'Confirmed', tone: 'confirmed' },
      { label: 'Arriving', tone: 'arriving' },
      { label: 'In house', tone: 'checked-in' },
      { label: 'Due out', tone: 'due-out' },
      { label: 'Hold / block', tone: 'blocked' },
    ],
    staySummary: [
      { label: 'Arrivals', value: '18', note: '5 rooms still in rush clean' },
      { label: 'In house', value: '44', note: '12 departures before noon' },
      { label: 'Unassigned', value: '2', note: 'Need room mapping from OTA queue' },
      { label: 'Balance due', value: 'IDR 4.02M', note: '3 folios before check-out' },
    ],
    stayCalendar: [
      {
        roomType: 'Deluxe Garden',
        plan: '8 rooms | 4 arrivals | 0 unassigned',
        availability: '2 vacant clean',
        unassigned: 1,
        rate: 1075000,
        rooms: [
          {
            no: '101',
            hk: 'Ready',
            flag: 'VC',
            bookings: [
              {
                id: 'stay-101-a',
                start: 0,
                span: 2,
                status: 'due-out',
                guest: 'Nadya Gita',
                code: 'BK-260317-013',
                source: 'Walk-in',
                pax: '2 adults',
                balance: 'Boat ticket package',
              },
            ],
          },
          {
            no: '102',
            hk: 'Ready',
            flag: 'VC',
            bookings: [],
          },
          {
            no: '103',
            hk: 'Occupied',
            flag: 'OC',
            bookings: [
              {
                id: 'stay-103-a',
                start: 0,
                span: 4,
                status: 'checked-in',
                guest: 'Rina Hartono',
                code: 'BK-260317-017',
                source: 'Direct',
                pax: '1 adult',
                balance: 'Late checkout requested',
              },
            ],
          },
          {
            no: '104',
            hk: 'Rush clean',
            flag: 'VD',
            bookings: [
              {
                id: 'stay-104-a',
                start: 3,
                span: 2,
                status: 'confirmed',
                guest: 'Daniel Koh',
                code: 'BK-260317-012',
                source: 'Agoda',
                pax: '2 adults',
                balance: 'Deposit pending before key release',
              },
            ],
          },
          {
            no: '105',
            hk: 'Blocked',
            flag: 'OOO',
            bookings: [
              {
                id: 'stay-105-a',
                start: 0,
                span: 7,
                status: 'blocked',
                guest: 'Room hold',
                code: 'MT-105',
                source: 'Engineering',
                pax: 'Bathroom repair',
                balance: 'Out of order until 23 Mar',
              },
            ],
          },
          {
            no: '106',
            hk: 'Rush clean 25 min',
            flag: 'VD',
            bookings: [
              {
                id: 'stay-106-a',
                start: 0,
                span: 4,
                status: 'confirmed',
                guest: 'Maya Siregar',
                code: 'BK-260317-011',
                source: 'Direct',
                pax: '2 adults',
                balance: 'Airport pickup 13:30',
              },
            ],
          },
          {
            no: '107',
            hk: 'Ready',
            flag: 'VC',
            bookings: [
              {
                id: 'stay-107-a',
                start: 0,
                span: 5,
                status: 'confirmed',
                guest: 'Arjun Patel',
                code: 'BK-260317-014',
                source: 'Booking.com',
                pax: '2 adults, 1 child',
                balance: 'Deposit posted',
              },
            ],
          },
          {
            no: '108',
            hk: 'Ready',
            flag: 'VC',
            bookings: [],
          },
        ],
      },
    ],
    nightAuditSteps: [
      'Verify unsettled departures and pending folio transfer.',
      'Review no-show, late checkout, and room move postings.',
      'Close cashier shift and print payment summary.',
      'Sync transport and add-on postings into guest folio.',
    ],
  })},
  getters: {
    coaList: (state) =>
      [...state.coaAccounts].sort((left, right) =>
        left.code.localeCompare(right.code, undefined, { numeric: true }),
      ),
    generalJournalList: (state) =>
      state.generalJournals
        .map((journal) => {
          const debitTotalValue = journal.lines.reduce((total, line) => total + Number(line.debitValue ?? 0), 0)
          const creditTotalValue = journal.lines.reduce((total, line) => total + Number(line.creditValue ?? 0), 0)

          return {
            ...journal,
            debitTotalValue,
            debitTotal: toCurrency(debitTotalValue),
            creditTotalValue,
            creditTotal: toCurrency(creditTotalValue),
            lineCount: journal.lines.length,
          }
        })
        .sort((left, right) => right.journalDate.localeCompare(left.journalDate) || right.journalNo.localeCompare(left.journalNo)),
    roomMasterList: (state) =>
      [...state.rooms]
        .map((room) => ({
          ...room,
          code: room.no,
          name: buildRoomName(room),
          coaReceivable: buildRoomReceivableCoa(room),
          coaRevenue: buildRoomRevenueCoa(room),
        }))
        .sort((left, right) => left.no.localeCompare(right.no, undefined, { numeric: true })),
    addonTypeOptions: () => [
      { value: 'transport', label: 'Transport' },
      { value: 'scooter', label: 'Scooter' },
      { value: 'island_tour', label: 'Island Tour' },
      { value: 'boat_ticket', label: 'Boat Ticket' },
    ],
    roomTypeOptions: (state) =>
      [
        ...new Set([
          ...state.stayCalendar.map((group) => group.roomType),
          ...state.rooms.map((room) => room.type).filter(Boolean),
          'Unassigned',
        ]),
      ],
    stayBookingEntries: (state) =>
      state.stayCalendar.flatMap((group) =>
        group.rooms.flatMap((room) =>
          room.bookings.map((booking) => ({
            ...booking,
            room: room.no,
            roomType: group.roomType,
            hk: room.hk,
            flag: room.flag,
            startKey: state.calendarDates[booking.start]?.key,
            endKey: state.calendarDates[booking.start + booking.span]?.key ?? null,
          })),
        ),
      ),
    selectedBookingDetail() {
      const booking = this.bookings.find((item) => item.code === this.selectedBookingCode) ?? null
      const stayEntries = this.stayBookingEntries.filter((item) => item.code === this.selectedBookingCode)
      const stayEntry = stayEntries[0] ?? null

      if (!booking && !stayEntries.length) {
        return null
      }

      const bookingRooms = booking?.rooms?.length ? booking.rooms : booking?.room ? [booking.room] : []
      const stayRooms = stayEntries.map((item) => item.room)
      const rooms = [...new Set([...bookingRooms, ...stayRooms])].filter(Boolean)
      const roomTypes = [
        ...new Set([
          ...(booking?.roomType ? [booking.roomType] : []),
          ...stayEntries.map((item) => item.roomType),
        ]),
      ]
      const roomDetails = rooms.map((roomNo) => {
        const bookingRoomDetail = booking?.roomDetails?.find((item) => item.room === roomNo)
        const stayRoomDetail = stayEntries.find((item) => item.room === roomNo)
        const roomInfo = this.rooms.find((item) => item.no === roomNo)

        return {
          room: roomNo,
          roomType: bookingRoomDetail?.roomType ?? stayRoomDetail?.roomType ?? roomInfo?.type ?? null,
          adults: bookingRoomDetail?.adults ?? booking?.adults ?? null,
          children: bookingRoomDetail?.children ?? booking?.children ?? 0,
          hk: stayRoomDetail?.hk ?? roomInfo?.hk ?? null,
          flag: stayRoomDetail?.flag ?? null,
        }
      })
      const bookingCode = booking?.code ?? stayEntry.code
      const addons = this.bookingAddons.filter((item) => item.bookingCode === bookingCode)
      const bookingScopedAddons = addons.length
        ? addons
        : (Array.isArray(booking?.addons) ? booking.addons.map((item) => ({ ...item, bookingCode })) : [])
      const addonsTotalValue = addons.reduce((total, item) => total + item.totalPriceValue, 0)
      const roomAmountValue = booking?.amountValue ?? 0

      return {
        code: bookingCode,
        guest: booking?.guest ?? stayEntry.guest,
        room: rooms.join(', '),
        rooms,
        roomDetails,
        roomCount: rooms.length,
        roomType: roomTypes.join(', '),
        channel: booking?.channel ?? stayEntry.source,
        amount: booking?.amount ?? null,
        status: booking?.status ?? stayEntry.status,
        checkIn: booking?.checkIn ?? stayEntry.startKey,
        checkOut: booking?.checkOut ?? stayEntry.endKey,
        adults: roomDetails.length ? totalPax(roomDetails, 'adults') : booking?.adults ?? null,
        children: roomDetails.length ? totalPax(roomDetails, 'children') : booking?.children ?? null,
        addons: bookingScopedAddons,
        addonsTotal: toCurrency(addonsTotalValue),
        addonsTotalValue,
        grandTotal: toCurrency(roomAmountValue + addonsTotalValue),
        grandTotalValue: roomAmountValue + addonsTotalValue,
        note: booking?.note ?? stayEntry.balance,
        hk: stayEntries.length === 1 ? stayEntry?.hk ?? null : null,
        flag: stayEntries.length === 1 ? stayEntry?.flag ?? null : null,
      }
    },
    invoiceList() {
      return this.bookings.map((booking) => {
        const addons = this.bookingAddons.filter((item) => item.bookingCode === booking.code)
        const bookingScopedAddons = addons.length
          ? addons
          : (Array.isArray(booking.addons) ? booking.addons.map((item) => ({ ...item, bookingCode: booking.code })) : [])
        const payments = this.paymentTransactions
          .filter((item) => item.bookingCode === booking.code)
          .sort((left, right) => right.paymentDate.localeCompare(left.paymentDate))
        const roomChargeValue = toNumber(booking.amountValue ?? booking.roomAmountValue)
        const addonsTotalValue = bookingScopedAddons.reduce((total, item) => total + toNumber(item.totalPriceValue), 0)
        const bookingGrandTotalValue = toNumber(booking.grandTotalValue)
        const subtotalValue = Math.max(roomChargeValue + addonsTotalValue, bookingGrandTotalValue, roomChargeValue)
        const paidValue = payments.reduce((total, item) => total + toNumber(item.signedAmountValue ?? item.amountValue), 0)
        const balanceValue = Math.max(subtotalValue - paidValue, 0)
        const paymentStatus = subtotalValue <= 0
          ? 'Draft'
          : balanceValue <= 0
            ? 'Paid'
            : paidValue > 0
              ? 'Partial'
              : 'Unpaid'
        const roomLine = {
          id: `${booking.code}-room`,
          type: 'room',
          label: 'Room charge',
          description: `${booking.room} | ${booking.checkIn} to ${booking.checkOut}`,
          amountValue: roomChargeValue,
          amount: toCurrency(roomChargeValue),
        }
        const addonLines = bookingScopedAddons.map((item) => ({
          id: item.id,
          type: item.addonType,
          label: item.addonLabel,
          description: `${item.serviceName} | ${item.serviceDateLabel ?? item.serviceDate}`,
          amountValue: item.totalPriceValue,
          amount: item.totalPrice,
          status: item.status,
        }))
        const items = [roomLine, ...addonLines]
        return {
          invoiceNo: booking.invoiceNo ?? buildInvoiceNumber(booking.code),
          bookingCode: booking.code,
          guest: booking.guest,
          room: booking.room,
          roomCount: toNumber(booking.roomCount ?? booking.roomDetails?.length ?? 0),
          roomDetails: Array.isArray(booking.roomDetails) ? booking.roomDetails : [],
          channel: booking.channel,
          issueDate: booking.issueDate ?? booking.checkIn,
          dueDate: booking.dueDate ?? booking.checkIn,
          checkIn: booking.checkIn,
          checkOut: booking.checkOut,
          addons: bookingScopedAddons,
          items,
          subtotalValue,
          subtotal: toCurrency(subtotalValue),
          paidValue,
          paid: toCurrency(paidValue),
          balanceValue,
          balance: toCurrency(balanceValue),
          paymentStatus,
          payments,
          paymentCount: payments.length,
          note: booking.note ?? '',
        }
      })
    },
    financePaymentSummary() {
      const invoices = this.invoiceList
      const totalInvoiceValue = invoices.reduce((total, item) => total + item.subtotalValue, 0)
      const totalPaidValue = invoices.reduce((total, item) => total + item.paidValue, 0)
      const totalOutstandingValue = invoices.reduce((total, item) => total + item.balanceValue, 0)

      return [
        { type: 'Invoice issued', count: `${invoices.length} invoices`, amount: toCurrency(totalInvoiceValue) },
        { type: 'Payments posted', count: `${this.paymentTransactions.length} payments`, amount: toCurrency(totalPaidValue) },
        { type: 'Outstanding balance', count: `${invoices.filter((item) => item.balanceValue > 0).length} folios`, amount: toCurrency(totalOutstandingValue) },
      ]
    },
    financeOpenFolios() {
      return this.invoiceList
        .filter((item) => item.balanceValue > 0)
        .map((item) => ({
          guest: item.guest,
          balance: item.balance,
          item: `${item.room} | ${item.paymentStatus}`,
          due: item.dueDate,
          bookingCode: item.bookingCode,
          invoiceNo: item.invoiceNo,
        }))
    },
    inventoryItemCatalog: (state) =>
      state.inventoryItems.map((item) => {
        const purchases = state.inventoryPurchases.filter((entry) => entry.itemId === item.id)
        const issues = state.roomInventoryIssues.filter((entry) => entry.itemId === item.id)
        const purchasedQty = purchases.reduce((total, entry) => total + entry.quantity, 0)
        const issuedQty = issues.reduce((total, entry) => total + entry.quantity, 0)
        const totalPurchaseValue = purchases.reduce((total, entry) => total + entry.totalCostValue, 0)
        const averageCostValue = purchasedQty > 0 ? totalPurchaseValue / purchasedQty : 0
        const onHandQty = purchasedQty - issuedQty
        const onHandValue = Math.max(onHandQty, 0) * averageCostValue

        return {
          ...item,
          purchasedQty,
          issuedQty,
          onHandQty,
          averageCostValue,
          averageCost: toCurrency(averageCostValue),
          onHandValue,
          onHandValueLabel: toCurrency(onHandValue),
        }
      }),
    inventoryStockAlerts() {
      return this.inventoryItemCatalog
        .filter((item) => item.onHandQty <= item.reorderLevel)
        .map((item) => ({
          item: item.name,
          location: 'Main Store',
          qty: `${item.onHandQty} ${item.unit}`,
          alert: `Reorder below ${item.reorderLevel} ${item.unit}`,
        }))
    },
    inventoryRoomIssueDetails() {
      return this.roomInventoryIssues.map((entry) => {
        const item = this.inventoryItems.find((record) => record.id === entry.itemId)
        const stockCard = this.inventoryItemCatalog.find((record) => record.id === entry.itemId)
        const averageCostValue = stockCard?.averageCostValue ?? 0
        const totalValue = averageCostValue * entry.quantity

        return {
          ...entry,
          itemName: item?.name ?? 'Unknown item',
          category: item?.category ?? '-',
          trackingType: item?.trackingType ?? '-',
          unit: item?.unit ?? 'pcs',
          inventoryCoa: item?.inventoryCoa ?? '',
          expenseCoa: item?.expenseCoa ?? '',
          totalValue,
          totalValueLabel: toCurrency(totalValue),
        }
      })
    },
    inventoryJournalEntries() {
      const purchaseEntries = this.inventoryPurchases.flatMap((entry) => {
        const item = this.inventoryItems.find((record) => record.id === entry.itemId)

        return [
          {
            id: `${entry.id}-dr`,
            entryDate: entry.purchaseDate,
            source: entry.id,
            transactionType: 'Purchase',
            account: item?.inventoryCoa ?? '115001 - Persediaan Barang',
            position: 'Debit',
            amountValue: entry.totalCostValue,
            amount: entry.totalCost,
            memo: `${item?.name ?? 'Item'} purchase from ${entry.supplier}`,
          },
          {
            id: `${entry.id}-cr`,
            entryDate: entry.purchaseDate,
            source: entry.id,
            transactionType: 'Purchase',
            account: entry.paymentAccount,
            position: 'Credit',
            amountValue: entry.totalCostValue,
            amount: entry.totalCost,
            memo: `Settlement for ${item?.name ?? 'item'} purchase`,
          },
        ]
      })

      const issueEntries = this.inventoryRoomIssueDetails.flatMap((entry) => {
        if (entry.trackingType !== 'Consumable') {
          return [{
            id: `${entry.id}-memo`,
            entryDate: entry.issueDate,
            source: entry.id,
            transactionType: 'Room issue',
            account: 'No GL posting',
            position: 'Memo',
            amountValue: 0,
            amount: '-',
            memo: `${entry.itemName} moved to room ${entry.roomNo} as internal linen/asset assignment`,
          }]
        }

        return [
          {
            id: `${entry.id}-dr`,
            entryDate: entry.issueDate,
            source: entry.id,
            transactionType: 'Room issue',
            account: entry.expenseCoa,
            position: 'Debit',
            amountValue: entry.totalValue,
            amount: entry.totalValueLabel,
            memo: `${entry.itemName} consumed in room ${entry.roomNo}`,
          },
          {
            id: `${entry.id}-cr`,
            entryDate: entry.issueDate,
            source: entry.id,
            transactionType: 'Room issue',
            account: entry.inventoryCoa,
            position: 'Credit',
            amountValue: entry.totalValue,
            amount: entry.totalValueLabel,
            memo: `Inventory reduction for ${entry.itemName} room issue`,
          },
        ]
      })

      return [...issueEntries, ...purchaseEntries].sort((left, right) =>
        right.entryDate.localeCompare(left.entryDate),
      )
    },
    addonCatalogOptions: (state) => (addonType) => {
      if (addonType === 'transport') {
        return state.transportRates.flatMap((item) => ([
          {
            value: `${item.id}:pickup`,
            label: `${item.driver} | Pickup | ${item.customerPickupPrice ?? item.pickupPrice}`,
            serviceName: `${item.driver} | Pickup`,
            unitPriceValue: item.customerPickupPriceValue ?? item.pickupPriceValue,
            addonLabel: 'Airport pickup',
            itemRef: item.id,
          },
          {
            value: `${item.id}:dropoff`,
            label: `${item.driver} | Drop off | ${item.customerDropOffPrice ?? item.dropOffPrice}`,
            serviceName: `${item.driver} | Drop off`,
            unitPriceValue: item.customerDropOffPriceValue ?? item.dropOffPriceValue,
            addonLabel: 'Airport drop off',
            itemRef: item.id,
          },
        ]))
      }

      if (addonType === 'scooter') {
        return state.scooterBookings.filter((item) => item.isActive !== false).map((item) => ({
          value: item.id,
          label: `${item.scooterType} | ${item.vendor} | ${item.customerPrice ?? item.price}`,
          serviceName: `${item.scooterType} | ${item.vendor}`,
          unitPriceValue: item.customerPriceValue ?? item.priceValue,
          vendorUnitPriceValue: item.vendorPriceValue ?? 0,
          addonLabel: 'Scooter rental',
          itemRef: item.id,
        }))
      }

      if (addonType === 'island_tour') {
        return state.islandTours.filter((item) => item.isActive !== false).map((item) => ({
          value: item.id,
          label: `${item.destination} | ${item.driver} | ${item.customerPrice ?? item.cost}`,
          serviceName: `${item.destination} | ${item.driver}`,
          unitPriceValue: item.customerPriceValue ?? item.costValue,
          vendorUnitPriceValue: item.vendorPriceValue ?? 0,
          addonLabel: 'Island tour',
          itemRef: item.id,
        }))
      }

      if (addonType === 'boat_ticket') {
        return state.boatTickets.filter((item) => item.isActive !== false).map((item) => ({
          value: item.id,
          label: `${item.company} | ${item.destination} | ${item.customerPrice ?? item.price}`,
          serviceName: `${item.company} | ${item.destination}`,
          unitPriceValue: item.customerPriceValue ?? item.priceValue,
          vendorUnitPriceValue: item.vendorPriceValue ?? 0,
          addonLabel: 'Boat ticket',
          itemRef: item.id,
        }))
      }

      return []
    },
    availableRooms: (state) => (criteria) => {
      const startDateKey = toDateKey(criteria.checkIn)
      const endDateKey = toDateKey(criteria.checkOut)

      if (!startDateKey || !endDateKey || endDateKey <= startDateKey) {
        return []
      }

      return state.stayCalendar
        .filter((group) => !criteria.roomType || group.roomType === criteria.roomType)
        .flatMap((group) =>
          group.rooms
            .filter((room) => {
              const hasOverlap = state.bookings.some((booking) =>
                Array.isArray(booking.roomDetails)
                && booking.roomDetails.some((detail) => String(detail?.room ?? '') === room.no)
                && overlap(
                  startDateKey,
                  endDateKey,
                  toDateKey(booking.checkIn),
                  toDateKey(booking.checkOut),
                ),
              )

              return !hasOverlap
            })
            .map((room) => ({
              room: room.no,
              roomType: group.roomType,
              hk: room.hk,
              flag: room.flag,
              rate: group.rate,
            })),
        )
    },
  },
  actions: {
    setSelectedBooking(code) {
      this.selectedBookingCode = code
    },
    setOverview(items) {
      this.overview = Array.isArray(items) ? items : []
    },
    setRevenueMix(items) {
      this.revenueMix = Array.isArray(items) ? items : []
    },
    setBusinessDate(value) {
      const nextBusinessDate = String(value ?? '').trim() || this.currentBusinessDate
      this.currentBusinessDate = nextBusinessDate
      this.calendarBaseDate = nextBusinessDate
      this.calendarDates = generateCalendarDates(nextBusinessDate, 7, nextBusinessDate)
      this.calendarRangeLabel = buildCalendarRangeLabel(this.calendarDates)
    },
    setCurrentDateLabel(value) {
      this.currentDateLabel = String(value ?? '').trim() || buildCurrentDateLabel(this.currentBusinessDate)
    },
    setBookings(rows) {
      this.bookings = Array.isArray(rows) ? rows : []
    },
    setBookingAddons(rows) {
      this.bookingAddons = Array.isArray(rows) ? rows : []
    },
    setPaymentTransactions(rows) {
      this.paymentTransactions = Array.isArray(rows) ? rows : []
    },
    setInventorySnapshot(payload = {}) {
      this.inventoryItems = Array.isArray(payload.items) ? payload.items : []
      this.inventoryPurchases = Array.isArray(payload.purchases) ? payload.purchases : []
      this.roomInventoryIssues = Array.isArray(payload.issues) ? payload.issues : []
    },
    setTransportRates(rows) {
      this.transportRates = Array.isArray(rows) ? rows : []
    },
    setActivityCatalog(payload = {}) {
      this.scooterBookings = Array.isArray(payload.scooters) ? payload.scooters : []
      this.activityOperators = Array.isArray(payload.operators) ? payload.operators : []
      this.islandTours = Array.isArray(payload.islandTours) ? payload.islandTours : []
      this.boatTickets = Array.isArray(payload.boatTickets) ? payload.boatTickets : []
    },
    buildPaymentId() {
      const next = String(this.paymentTransactions.length + 1).padStart(3, '0')
      return `PAY-${next}`
    },
    buildInventoryItemId() {
      const next = String(this.inventoryItems.length + 1).padStart(3, '0')
      return `ITM-${next}`
    },
    buildInventoryPurchaseId() {
      const next = String(this.inventoryPurchases.length + 1).padStart(3, '0')
      return `PUR-${next}`
    },
    buildInventoryIssueId() {
      const next = String(this.roomInventoryIssues.length + 1).padStart(3, '0')
      return `ISS-${next}`
    },
    buildGeneralJournalId() {
      const next = String(this.generalJournals.length + 1).padStart(3, '0')
      return `GJ-${next}`
    },
    buildGeneralJournalNumber(journalDate) {
      const stamp = String(journalDate ?? '').replaceAll('-', '')
      const next = String(this.generalJournals.length + 1).padStart(3, '0')
      return `JU-${stamp}-${next}`
    },
    selectStayBooking(code) {
      this.selectedBookingCode = code
    },
    buildBookingCode(checkIn) {
      const stamp = checkIn.slice(2).replaceAll('-', '')
      const next = String(this.bookings.length + 1).padStart(3, '0')
      return `BK-${stamp}-${next}`
    },
    setCoaAccounts(accounts) {
      this.coaAccounts = (Array.isArray(accounts) ? accounts : []).map((item) => ({
        code: String(item.code ?? '').trim(),
        name: String(item.name ?? '').trim(),
        category: String(item.category ?? '').trim() || 'Asset',
        normalBalance: String(item.normalBalance ?? '').trim() || 'Debit',
        note: String(item.note ?? '').trim(),
        active: item.active !== false,
      }))
    },
    createCoaAccount(payload) {
      const code = String(payload.code ?? '').trim()
      const name = String(payload.name ?? '').trim()
      const category = String(payload.category ?? '').trim()
      const normalBalance = String(payload.normalBalance ?? '').trim()
      const note = String(payload.note ?? '').trim()

      if (!code || !name || !category || !normalBalance) {
        throw new Error('Kode akun, nama akun, kategori, dan saldo normal wajib diisi.')
      }

      if (this.coaAccounts.some((item) => item.code === code)) {
        throw new Error('Kode akun sudah dipakai. Gunakan kode akun lain.')
      }

      const coaAccount = {
        code,
        name,
        category,
        normalBalance,
        note: note || 'COA master account',
        active: payload.active !== false,
      }

      this.coaAccounts.unshift(coaAccount)
      return coaAccount
    },
    createInventoryItem(payload) {
      const name = String(payload.name ?? '').trim()
      const category = String(payload.category ?? '').trim()
      const unit = String(payload.unit ?? '').trim()
      const trackingType = String(payload.trackingType ?? '').trim()
      const inventoryCoa = String(payload.inventoryCoa ?? '').trim()
      const expenseCoa = String(payload.expenseCoa ?? '').trim()
      const reorderLevel = Number(payload.reorderLevel ?? 0)

      if (!name || !category || !unit || !trackingType || !inventoryCoa || !expenseCoa) {
        throw new Error('Nama item, kategori, unit, tipe tracking, COA persediaan, dan COA biaya wajib diisi.')
      }

      const item = {
        id: this.buildInventoryItemId(),
        name,
        category,
        unit,
        trackingType,
        inventoryCoa,
        expenseCoa,
        reorderLevel: Math.max(reorderLevel, 0),
      }

      this.inventoryItems.unshift(item)
      return item
    },
    createInventoryPurchase(payload) {
      const purchaseDate = String(payload.purchaseDate ?? '').trim()
      const supplier = String(payload.supplier ?? '').trim()
      const itemId = String(payload.itemId ?? '').trim()
      const quantity = Number(payload.quantity ?? 0)
      const unitCostValue = Number(payload.unitCostValue ?? 0)
      const paymentAccount = String(payload.paymentAccount ?? '').trim()
      const note = String(payload.note ?? '').trim()
      const item = this.inventoryItems.find((record) => record.id === itemId)

      if (!purchaseDate || !supplier || !item || quantity <= 0 || unitCostValue <= 0 || !paymentAccount) {
        throw new Error('Tanggal, supplier, item, qty, harga satuan, dan akun pembayaran wajib diisi.')
      }

      const totalCostValue = quantity * unitCostValue
      const purchase = {
        id: this.buildInventoryPurchaseId(),
        purchaseDate,
        supplier,
        itemId,
        quantity,
        unitCostValue,
        unitCost: toCurrency(unitCostValue),
        totalCostValue,
        totalCost: toCurrency(totalCostValue),
        paymentAccount,
        note: note || `Purchase of ${item.name}`,
      }

      this.inventoryPurchases.unshift(purchase)
      return purchase
    },
    createRoomInventoryIssue(payload) {
      const issueDate = String(payload.issueDate ?? '').trim()
      const roomNo = String(payload.roomNo ?? '').trim()
      const itemId = String(payload.itemId ?? '').trim()
      const quantity = Number(payload.quantity ?? 0)
      const note = String(payload.note ?? '').trim()
      const item = this.inventoryItems.find((record) => record.id === itemId)
      const stockCard = this.inventoryItemCatalog.find((record) => record.id === itemId)

      if (!issueDate || !roomNo || !item || quantity <= 0) {
        throw new Error('Tanggal issue, kamar, item, dan qty wajib diisi.')
      }

      if ((stockCard?.onHandQty ?? 0) < quantity) {
        throw new Error('Stok tidak cukup untuk di-issue ke kamar.')
      }

      const issue = {
        id: this.buildInventoryIssueId(),
        issueDate,
        roomNo,
        itemId,
        quantity,
        note: note || `${item.name} issued to room ${roomNo}`,
      }

      this.roomInventoryIssues.unshift(issue)
      return issue
    },
    createGeneralJournal(payload) {
      const journalDate = String(payload.journalDate ?? '').trim()
      const referenceNo = String(payload.referenceNo ?? '').trim()
      const description = String(payload.description ?? '').trim()
      const rawLines = Array.isArray(payload.lines) ? payload.lines : []
      const lines = rawLines
        .map((line, index) => ({
          id: `line-${index + 1}`,
          account: String(line.account ?? '').trim(),
          debitValue: Number(line.debitValue ?? 0),
          creditValue: Number(line.creditValue ?? 0),
          memo: String(line.memo ?? '').trim(),
        }))
        .filter((line) => line.account && (line.debitValue > 0 || line.creditValue > 0))

      if (!journalDate || !description || lines.length < 2) {
        throw new Error('Tanggal jurnal, keterangan, dan minimal dua baris akun wajib diisi.')
      }

      const hasInvalidLine = lines.some((line) => line.debitValue > 0 && line.creditValue > 0)

      if (hasInvalidLine) {
        throw new Error('Satu baris jurnal hanya boleh berisi debit atau credit, tidak keduanya.')
      }

      const debitTotalValue = lines.reduce((total, line) => total + line.debitValue, 0)
      const creditTotalValue = lines.reduce((total, line) => total + line.creditValue, 0)

      if (debitTotalValue <= 0 || creditTotalValue <= 0 || debitTotalValue !== creditTotalValue) {
        throw new Error('Total debit dan credit harus sama agar jurnal bisa diposting.')
      }

      const journal = {
        id: this.buildGeneralJournalId(),
        journalNo: this.buildGeneralJournalNumber(journalDate),
        journalDate,
        referenceNo: referenceNo || `REF-${Date.now()}`,
        description,
        lines,
      }

      this.generalJournals.unshift(journal)
      return journal
    },
    updateCoaAccount(code, payload) {
      const coaAccount = this.coaAccounts.find((item) => item.code === code)

      if (!coaAccount) {
        throw new Error('COA tidak ditemukan untuk diedit.')
      }

      const name = String(payload.name ?? '').trim()
      const category = String(payload.category ?? '').trim()
      const normalBalance = String(payload.normalBalance ?? '').trim()
      const note = String(payload.note ?? '').trim()

      if (!name || !category || !normalBalance) {
        throw new Error('Nama akun, kategori, dan saldo normal wajib diisi.')
      }

      coaAccount.name = name
      coaAccount.category = category
      coaAccount.normalBalance = normalBalance
      coaAccount.note = note || 'COA master account'
      coaAccount.active = payload.active !== false

      return coaAccount
    },
    createRoomMaster(payload) {
      const code = String(payload.code ?? '').trim()
      const name = String(payload.name ?? '').trim()
      const type = String(payload.type ?? '').trim()
      const coaReceivable = String(payload.coaReceivable ?? '').trim()
      const coaRevenue = String(payload.coaRevenue ?? '').trim()

      if (!code || !name || !type || !coaReceivable || !coaRevenue) {
        throw new Error('Kode kamar, nama kamar, tipe, COA piutang, dan COA pendapatan wajib diisi.')
      }

      if (this.rooms.some((room) => room.no === code)) {
        throw new Error('Kode kamar sudah dipakai. Gunakan kode kamar lain.')
      }

      const room = {
        no: code,
        name,
        type,
        coaReceivable,
        coaRevenue,
        status: payload.status || 'available',
        hk: payload.hk || 'Ready',
        note: payload.note || 'Master room created',
      }

      this.rooms.push(room)
      return room
    },
    updateRoomMaster(code, payload) {
      const room = this.rooms.find((item) => item.no === code)

      if (!room) {
        throw new Error('Data kamar tidak ditemukan untuk diedit.')
      }

      const name = String(payload.name ?? '').trim()
      const type = String(payload.type ?? '').trim()
      const coaReceivable = String(payload.coaReceivable ?? '').trim()
      const coaRevenue = String(payload.coaRevenue ?? '').trim()

      if (!name || !type || !coaReceivable || !coaRevenue) {
        throw new Error('Nama kamar, tipe, COA piutang, dan COA pendapatan wajib diisi.')
      }

      room.name = name
      room.type = type
      room.coaReceivable = coaReceivable
      room.coaRevenue = coaRevenue

      return room
    },
    buildTransportId() {
      const next = String(this.transportRates.length + 1).padStart(3, '0')
      return `TRF-${next}`
    },
    buildScooterBookingId() {
      const next = String(this.scooterBookings.length + 1).padStart(3, '0')
      return `SCT-${next}`
    },
    buildActivityOperatorId() {
      const next = String(this.activityOperators.length + 1).padStart(3, '0')
      return `OPR-${next}`
    },
    buildIslandTourId() {
      const next = String(this.islandTours.length + 1).padStart(3, '0')
      return `TOUR-${next}`
    },
    buildBoatTicketId() {
      const next = String(this.boatTickets.length + 1).padStart(3, '0')
      return `BOT-${next}`
    },
    buildBookingAddonId() {
      const next = String(this.bookingAddons.length + 1).padStart(3, '0')
      return `BKA-${next}`
    },
    createScooterBooking(payload) {
      const scooterType = String(payload.scooterType ?? '').trim()
      const vendor = String(payload.vendor ?? '').trim()
      const startDate = String(payload.startDate ?? '').trim()
      const endDate = String(payload.endDate ?? '').trim()
      const priceValue = Number(payload.priceValue ?? 0)

      if (!scooterType || !vendor || priceValue <= 0) {
        throw new Error('Tipe scooter, vendor, dan harga wajib diisi.')
      }

      const scooterBooking = {
        id: this.buildScooterBookingId(),
        startDate,
        endDate,
        scooterType,
        vendor,
        priceValue,
        price: toCurrency(priceValue),
      }

      this.scooterBookings.unshift(scooterBooking)
      return scooterBooking
    },
    updateScooterBooking(id, payload) {
      const scooterBooking = this.scooterBookings.find((item) => item.id === id)

      if (!scooterBooking) {
        throw new Error('Data scooter tidak ditemukan untuk diedit.')
      }

      const scooterType = String(payload.scooterType ?? '').trim()
      const vendor = String(payload.vendor ?? '').trim()
      const startDate = String(payload.startDate ?? '').trim()
      const endDate = String(payload.endDate ?? '').trim()
      const priceValue = Number(payload.priceValue ?? 0)

      if (!scooterType || !vendor || priceValue <= 0) {
        throw new Error('Tipe scooter, vendor, dan harga wajib diisi.')
      }

      scooterBooking.startDate = startDate
      scooterBooking.endDate = endDate
      scooterBooking.scooterType = scooterType
      scooterBooking.vendor = vendor
      scooterBooking.priceValue = priceValue
      scooterBooking.price = toCurrency(priceValue)

      return scooterBooking
    },
    createActivityOperator(payload) {
      const operator = String(payload.operator ?? '').trim()
      const note = String(payload.note ?? '').trim()
      const priceValue = Number(payload.priceValue ?? 0)

      if (!operator || priceValue <= 0) {
        throw new Error('Nama operator dan harga wajib diisi.')
      }

      const activityOperator = {
        id: this.buildActivityOperatorId(),
        operator,
        priceValue,
        price: toCurrency(priceValue),
        note: note || 'Activity operator',
      }

      this.activityOperators.unshift(activityOperator)
      return activityOperator
    },
    updateActivityOperator(id, payload) {
      const activityOperator = this.activityOperators.find((item) => item.id === id)

      if (!activityOperator) {
        throw new Error('Data operator tidak ditemukan untuk diedit.')
      }

      const operator = String(payload.operator ?? '').trim()
      const note = String(payload.note ?? '').trim()
      const priceValue = Number(payload.priceValue ?? 0)

      if (!operator || priceValue <= 0) {
        throw new Error('Nama operator dan harga wajib diisi.')
      }

      activityOperator.operator = operator
      activityOperator.priceValue = priceValue
      activityOperator.price = toCurrency(priceValue)
      activityOperator.note = note || 'Activity operator'

      return activityOperator
    },
    createIslandTour(payload) {
      const destination = String(payload.destination ?? '').trim()
      const driver = String(payload.driver ?? '').trim()
      const note = String(payload.note ?? '').trim()
      const costValue = Number(payload.costValue ?? 0)

      if (!destination || !driver || costValue <= 0) {
        throw new Error('Destinasi, driver, dan biaya wajib diisi.')
      }

      const islandTour = {
        id: this.buildIslandTourId(),
        destination,
        driver,
        costValue,
        cost: toCurrency(costValue),
        note: note || 'Island tour rate',
      }

      this.islandTours.unshift(islandTour)
      return islandTour
    },
    updateIslandTour(id, payload) {
      const islandTour = this.islandTours.find((item) => item.id === id)

      if (!islandTour) {
        throw new Error('Data island tour tidak ditemukan untuk diedit.')
      }

      const destination = String(payload.destination ?? '').trim()
      const driver = String(payload.driver ?? '').trim()
      const note = String(payload.note ?? '').trim()
      const costValue = Number(payload.costValue ?? 0)

      if (!destination || !driver || costValue <= 0) {
        throw new Error('Destinasi, driver, dan biaya wajib diisi.')
      }

      islandTour.destination = destination
      islandTour.driver = driver
      islandTour.costValue = costValue
      islandTour.cost = toCurrency(costValue)
      islandTour.note = note || 'Island tour rate'

      return islandTour
    },
    createBoatTicket(payload) {
      const company = String(payload.company ?? '').trim()
      const destination = String(payload.destination ?? '').trim()
      const priceValue = Number(payload.priceValue ?? 0)

      if (!company || !destination || priceValue <= 0) {
        throw new Error('Company, destination, dan harga ticket wajib diisi.')
      }

      const boatTicket = {
        id: this.buildBoatTicketId(),
        company,
        destination,
        priceValue,
        price: toCurrency(priceValue),
      }

      this.boatTickets.unshift(boatTicket)
      return boatTicket
    },
    updateBoatTicket(id, payload) {
      const boatTicket = this.boatTickets.find((item) => item.id === id)

      if (!boatTicket) {
        throw new Error('Data boat ticket tidak ditemukan untuk diedit.')
      }

      const company = String(payload.company ?? '').trim()
      const destination = String(payload.destination ?? '').trim()
      const priceValue = Number(payload.priceValue ?? 0)

      if (!company || !destination || priceValue <= 0) {
        throw new Error('Company, destination, dan harga ticket wajib diisi.')
      }

      boatTicket.company = company
      boatTicket.destination = destination
      boatTicket.priceValue = priceValue
      boatTicket.price = toCurrency(priceValue)

      return boatTicket
    },
    createTransportRate(payload) {
      const driver = String(payload.driver ?? '').trim()
      const vendorPickupPriceValue = Number(payload.vendorPickupPriceValue ?? payload.pickupPriceValue ?? 0)
      const vendorDropOffPriceValue = Number(payload.vendorDropOffPriceValue ?? payload.dropOffPriceValue ?? 0)
      const customerPickupPriceValue = Number(payload.customerPickupPriceValue ?? payload.pickupPriceValue ?? 0)
      const customerDropOffPriceValue = Number(payload.customerDropOffPriceValue ?? payload.dropOffPriceValue ?? 0)
      const vehicle = String(payload.vehicle ?? '').trim()
      const note = String(payload.note ?? '').trim()

      if (!driver || vendorPickupPriceValue <= 0 || vendorDropOffPriceValue <= 0 || customerPickupPriceValue <= 0 || customerDropOffPriceValue <= 0) {
        throw new Error('Driver, harga pickup, dan harga drop off wajib diisi.')
      }

      const transportRate = {
        id: this.buildTransportId(),
        driver,
        vendorPickupPriceValue,
        vendorPickupPrice: toCurrency(vendorPickupPriceValue),
        vendorDropOffPriceValue,
        vendorDropOffPrice: toCurrency(vendorDropOffPriceValue),
        customerPickupPriceValue,
        customerPickupPrice: toCurrency(customerPickupPriceValue),
        customerDropOffPriceValue,
        customerDropOffPrice: toCurrency(customerDropOffPriceValue),
        pickupPriceValue: customerPickupPriceValue,
        pickupPrice: toCurrency(customerPickupPriceValue),
        dropOffPriceValue: customerDropOffPriceValue,
        dropOffPrice: toCurrency(customerDropOffPriceValue),
        vehicle: vehicle || 'Vehicle not set',
        note: note || 'Standard transport rate',
      }

      this.transportRates.unshift(transportRate)
      return transportRate
    },
    updateTransportRate(id, payload) {
      const transportRate = this.transportRates.find((item) => item.id === id)

      if (!transportRate) {
        throw new Error('Data transport tidak ditemukan untuk diedit.')
      }

      const driver = String(payload.driver ?? '').trim()
      const vendorPickupPriceValue = Number(payload.vendorPickupPriceValue ?? payload.pickupPriceValue ?? 0)
      const vendorDropOffPriceValue = Number(payload.vendorDropOffPriceValue ?? payload.dropOffPriceValue ?? 0)
      const customerPickupPriceValue = Number(payload.customerPickupPriceValue ?? payload.pickupPriceValue ?? 0)
      const customerDropOffPriceValue = Number(payload.customerDropOffPriceValue ?? payload.dropOffPriceValue ?? 0)
      const vehicle = String(payload.vehicle ?? '').trim()
      const note = String(payload.note ?? '').trim()

      if (!driver || vendorPickupPriceValue <= 0 || vendorDropOffPriceValue <= 0 || customerPickupPriceValue <= 0 || customerDropOffPriceValue <= 0) {
        throw new Error('Driver, harga pickup, dan harga drop off wajib diisi.')
      }

      transportRate.driver = driver
      transportRate.vendorPickupPriceValue = vendorPickupPriceValue
      transportRate.vendorPickupPrice = toCurrency(vendorPickupPriceValue)
      transportRate.vendorDropOffPriceValue = vendorDropOffPriceValue
      transportRate.vendorDropOffPrice = toCurrency(vendorDropOffPriceValue)
      transportRate.customerPickupPriceValue = customerPickupPriceValue
      transportRate.customerPickupPrice = toCurrency(customerPickupPriceValue)
      transportRate.customerDropOffPriceValue = customerDropOffPriceValue
      transportRate.customerDropOffPrice = toCurrency(customerDropOffPriceValue)
      transportRate.pickupPriceValue = customerPickupPriceValue
      transportRate.pickupPrice = toCurrency(customerPickupPriceValue)
      transportRate.dropOffPriceValue = customerDropOffPriceValue
      transportRate.dropOffPrice = toCurrency(customerDropOffPriceValue)
      transportRate.vehicle = vehicle || 'Vehicle not set'
      transportRate.note = note || 'Standard transport rate'

      return transportRate
    },
    addAddonToBooking(payload) {
      const booking = this.bookings.find((item) => item.code === payload.bookingCode)

      if (!booking) {
        throw new Error('Booking tidak ditemukan untuk ditambahkan add-on.')
      }

      const addonType = String(payload.addonType ?? '').trim()
      const catalogItem = this.addonCatalogOptions(addonType).find((item) => item.value === payload.itemValue)

      if (!addonType || !catalogItem) {
        throw new Error('Pilih jenis add-on dan item layanan terlebih dahulu.')
      }

      const startDate = String(payload.startDate ?? payload.serviceDate ?? '').trim() || booking.checkIn
      const endDate = String(payload.endDate ?? '').trim()

      if (addonType === 'scooter' && endDate && endDate < startDate) {
        throw new Error('Tanggal akhir scooter tidak boleh lebih awal dari tanggal mulai.')
      }

      const quantity = addonType === 'scooter' && endDate
        ? Math.max(1, diffDays(startDate, endDate) + 1)
        : Math.max(1, Number(payload.quantity ?? 1))
      const totalPriceValue = catalogItem.unitPriceValue * quantity

      const serviceDate = startDate
      const serviceDateLabel =
        addonType === 'scooter' && endDate
          ? `${startDate} to ${endDate}`
          : startDate
      const status = addonStatusOptions.includes(payload.status) ? payload.status : addonStatusOptions[0]

      const bookingAddon = {
        id: this.buildBookingAddonId(),
        bookingCode: payload.bookingCode,
        addonType,
        addonLabel: catalogItem.addonLabel,
        itemRef: catalogItem.itemRef,
        serviceName: catalogItem.serviceName,
        serviceDate,
        startDate,
        endDate: addonType === 'scooter' ? endDate : '',
        serviceDateLabel,
        quantity,
        unitPriceValue: catalogItem.unitPriceValue,
        unitPrice: toCurrency(catalogItem.unitPriceValue),
        totalPriceValue,
        totalPrice: toCurrency(totalPriceValue),
        status,
        notes: String(payload.notes ?? '').trim() || 'Attached from booking detail',
      }

      this.bookingAddons.unshift(bookingAddon)
      return bookingAddon
    },
    async recordPayment(payload) {
      const invoice = this.invoiceList.find((item) => item.bookingCode === payload.bookingCode)

      if (!invoice) {
        throw new Error('Invoice tidak ditemukan untuk diposting pembayarannya.')
      }

      const amountValue = Number(payload.amountValue ?? 0)
      const method = String(payload.method ?? '').trim()
      const paymentDate = String(payload.paymentDate ?? '').trim() || invoice.issueDate
      const referenceNo = String(payload.referenceNo ?? '').trim()
      const note = String(payload.note ?? '').trim()

      if (amountValue <= 0 || !method) {
        throw new Error('Tanggal pembayaran, metode bayar, dan nominal wajib diisi.')
      }

      const response = await api.post('/payments', {
        bookingCode: payload.bookingCode,
        amountValue,
        method,
        paymentDate,
        referenceNo,
        note,
      })
      const payment = response.data?.data

      this.paymentTransactions.unshift(payment)
      return payment
    },
    createBooking(payload) {
      const startDateKey = toDateKey(payload.checkIn)
      const endDateKey = toDateKey(payload.checkOut)

      if (!startDateKey || !endDateKey || endDateKey <= startDateKey) {
        throw new Error('Tanggal booking belum valid. Check-in harus lebih awal dari check-out.')
      }

      const requestedRoomDetails = payload.roomDetails?.length
        ? payload.roomDetails
        : (payload.rooms ?? []).map((room) => ({
          room,
          adults: payload.adults,
          children: payload.children,
        }))

      if (!requestedRoomDetails.length) {
        throw new Error('Pilih minimal satu kamar untuk booking ini.')
      }

      const availableOptions = this.availableRooms({
        checkIn: payload.checkIn,
        checkOut: payload.checkOut,
        roomType: payload.roomType || '',
      })

      const roomOptions = requestedRoomDetails
        .map((detail) => availableOptions.find((item) => item.room === detail.room))
        .filter(Boolean)

      if (roomOptions.length !== requestedRoomDetails.length) {
        throw new Error('Salah satu kamar sudah terpakai pada rentang tanggal tersebut.')
      }

      const nights = Math.max(1, diffDays(startDateKey, endDateKey))
      const code = this.buildBookingCode(payload.checkIn)
      const roomTypes = [...new Set(roomOptions.map((room) => room.roomType))]
      const roomDetails = requestedRoomDetails.map((detail) => {
        const roomOption = roomOptions.find((item) => item.room === detail.room)
        const rateValue = Math.max(0, Number(detail.rate ?? roomOption?.rate ?? 0) || 0)

        return {
          room: detail.room,
          roomType: roomOption?.roomType ?? null,
          rate: toCurrency(rateValue),
          rateValue,
          adults: Number(detail.adults ?? 1),
          children: Number(detail.children ?? 0),
        }
      })
      const amountValue = roomDetails.reduce((total, detail) => total + detail.rateValue * nights, 0)
      const booking = {
        code,
        guest: payload.guest,
        room: roomDetails.map((item) => item.room).join(', '),
        rooms: roomDetails.map((item) => item.room),
        roomDetails,
        roomType: roomTypes.join(', '),
        channel: payload.channel,
        amount: toCurrency(amountValue),
        amountValue,
        status: payload.status,
        checkIn: payload.checkIn,
        checkOut: payload.checkOut,
        adults: totalPax(roomDetails, 'adults'),
        children: totalPax(roomDetails, 'children'),
        note: payload.note || 'Deposit follow-up required',
      }

      this.bookings.unshift(booking)

      roomDetails.forEach((detail, index) => {
        const roomNo = detail.room
        const roomOption = roomOptions.find((item) => item.room === roomNo)
        const group = this.stayCalendar.find((item) => item.roomType === roomOption?.roomType)
        const room = group?.rooms.find((item) => item.no === roomNo)
        const startIndex = this.calendarDates.findIndex((day) => day.key === startDateKey)

        if (room && startIndex !== -1) {
          room.bookings.push({
            id: `stay-${roomNo}-${Date.now()}-${index}`,
            start: startIndex,
            span: nights,
            status: startDateKey === this.currentBusinessDate ? 'arriving' : 'confirmed',
            guest: payload.guest,
            code,
            source: payload.channel,
            pax: paxLabel(detail.adults, detail.children),
            balance: payload.note || `Multi-room reservation | ${roomDetails.length} rooms`,
          })

          room.bookings.sort((left, right) => left.start - right.start)
        }
      })

      this.selectedBookingCode = code

      return booking
    },
    updateBookingStatus(bookingCode, newStatus) {
      const booking = this.bookings.find((item) => item.code === bookingCode)
      if (!booking) {
        throw new Error('Booking tidak ditemukan.')
      }

      booking.status = newStatus

      this.stayCalendar.forEach((group) => {
        group.rooms.forEach((room) => {
          room.bookings.forEach((b) => {
            if (b.code === bookingCode) {
              if (newStatus === 'Checked-in') {
                b.status = 'checked-in'
                room.flag = 'OC'
                room.hk = 'Occupied'
              } else if (newStatus === 'Checked-out') {
                b.status = 'checked-out'
                room.flag = 'VD'
                room.hk = 'Dirty'
              } else if (newStatus === 'Cancelled' || newStatus === 'No-Show') {
                b.status = 'cancelled'
              } else if (newStatus === 'Tentative') {
                b.status = 'tentative'
              } else if (newStatus === 'Confirmed') {
                b.status = 'confirmed'
              }
            }
          })
        })
      })

      return booking
    },
    async runNightAudit() {
      const response = await api.post('/night-audit')
      return response.data
    },
    async login(username, password) {
      const response = await api.post('/login', { username, password })
      const payload = typeof response?.data === 'string'
        ? (() => {
            try {
              return JSON.parse(response.data)
            } catch {
              return { raw: response.data }
            }
          })()
        : (response?.data ?? {})

      const authPayload = payload?.user
        ? payload
        : payload?.data?.user
          ? payload.data
          : null

      if (!authPayload?.user || typeof authPayload.user !== 'object') {
        this.user = null
        localStorage.removeItem('pms_token')
        localStorage.removeItem('pms_user')
        const backendMessage =
          payload?.message ||
          payload?.error ||
          (typeof payload?.raw === 'string' ? payload.raw.slice(0, 200) : null)

        throw new Error(backendMessage || 'Respons login tidak valid.')
      }

      this.user = authPayload.user
      localStorage.setItem('pms_token', String(authPayload?.token ?? ''))
      localStorage.setItem('pms_user', JSON.stringify(this.user))
      return this.user
    },
    logout() {
      this.user = null
      localStorage.removeItem('pms_token')
      localStorage.removeItem('pms_user')
    },
    loadUserFromStorage() {
      const stored = localStorage.getItem('pms_user')
      if (!stored || stored === 'undefined' || stored === 'null') {
        this.user = null
        if (stored === 'undefined' || stored === 'null') {
          localStorage.removeItem('pms_user')
        }
        return
      }

      try {
        this.user = JSON.parse(stored)
      } catch (error) {
        this.user = null
        localStorage.removeItem('pms_user')
      }
    }
  },
})
