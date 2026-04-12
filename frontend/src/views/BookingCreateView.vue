<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import DateTimePickerField from '../components/DateTimePickerField.vue'
import LoadingState from '../components/LoadingState.vue'
import Select2Field from '../components/Select2Field.vue'
import api from '../services/api'
import { useHotelStore } from '../stores/hotel'

const router = useRouter()
const route = useRoute()
const hotel = useHotelStore()

const toDateKey = (value) => String(value ?? '').slice(0, 10)
const hasDateOverlap = (startA, endA, startB, endB) => startA < endB && startB < endA
const MS_PER_DAY = 24 * 60 * 60 * 1000
const toUtcDate = (value) => {
  const [year, month, day] = String(value ?? '')
    .slice(0, 10)
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
const addDateKeyDays = (value, days) => {
  const date = toUtcDate(value)
  date.setUTCDate(date.getUTCDate() + days)
  return toIsoDate(date)
}
const diffDateKeys = (start, end) => Math.round((toUtcDate(end) - toUtcDate(start)) / MS_PER_DAY)
const currencyFormatter = new Intl.NumberFormat('id-ID', {
  style: 'currency',
  currency: 'IDR',
  maximumFractionDigits: 0,
})
const toCurrency = (value) => currencyFormatter.format(Number(value ?? 0) || 0)
const parseBookingRate = (value) => {
  const raw = String(value ?? '').trim()

  if (!raw) {
    return 0
  }

  let normalized = raw.replace(/\s/g, '')

  if (normalized.includes(',') && normalized.includes('.')) {
    normalized = normalized.replace(/\./g, '').replace(',', '.')
  } else if (normalized.includes(',')) {
    normalized = normalized.replace(',', '.')
  }

  const parsed = Number(normalized)
  return Number.isFinite(parsed) ? Math.max(0, parsed) : 0
}
const normalizeBookingRateInput = (value) => {
  const raw = String(value ?? '').replace(/\s/g, '').replace(/,/g, '.')
  let result = ''
  let hasDecimal = false

  for (const char of raw) {
    if (/\d/.test(char)) {
      result += char
      continue
    }

    if (char === '.' && !hasDecimal) {
      result += '.'
      hasDecimal = true
    }
  }

  return result
}
const sanitizeBookingRate = (value) => {
  const normalized = normalizeBookingRateInput(value)

  if (!normalized) {
    return ''
  }

  const amount = parseBookingRate(normalized)
  return Number.isInteger(amount) ? String(amount) : amount.toFixed(2)
}
const formatBookingRate = (value) => {
  const amount = parseBookingRate(value)

  if (!amount) {
    return ''
  }

  return new Intl.NumberFormat('id-ID', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(amount)
}
const businessDateKey = computed(() => toDateKey(hotel.currentBusinessDate) || toIsoDate(new Date()))
const nextBusinessDateKey = computed(() => addDateKeyDays(businessDateKey.value, 1))

const bookingResult = ref({ tone: '', text: '' })
const saving = ref(false)
const loadingRooms = ref(false)
const loadingBooking = ref(false)
const roomLoadResult = ref({ tone: '', text: '' })
const roomMasterRows = ref([])
const bookingRows = ref([])

const createRoomSelection = () => ({
  room: '',
  rate: '',
  rateFocused: false,
  adults: 2,
  children: 0,
})

const bookingForm = reactive({
  guest: '',
  phone: '',
  email: '',
  checkIn: `${businessDateKey.value} 14:00`,
  checkOut: `${nextBusinessDateKey.value} 12:00`,
  roomSelections: [createRoomSelection()],
  channel: hotel.bookingChannels[0],
  status: hotel.bookingStatuses[0],
  note: '',
  arrivalTime: '14:00',
  departureTime: '12:00',
})

const editingBookingCode = computed(() => String(route.params.bookingCode ?? '').trim())
const isEditMode = computed(() => editingBookingCode.value.length > 0)
const pageTitle = computed(() => (isEditMode.value ? 'Edit reservation' : 'New booking'))
const pageSubtitle = computed(() =>
  isEditMode.value
    ? 'Update reservation details, stay dates, and room assignments without losing operational context.'
    : 'Create a new reservation, select available rooms, then save it with a clear rate summary.',
)

const submitLabel = computed(() => {
  if (saving.value) {
    return isEditMode.value ? 'Saving changes...' : 'Saving...'
  }

  return isEditMode.value ? 'Update reservation' : 'Save reservation'
})

const bookingMinDate = computed(() => `${businessDateKey.value} 00:00`)
const bookingMinCheckOut = computed(() => {
  const currentDateKey = toDateKey(bookingForm.checkIn) || businessDateKey.value
  const nextDateKey = addDateKeyDays(currentDateKey, 1)
  return `${nextDateKey} 12:00`
})

const availableRooms = computed(() => {
  if (roomMasterRows.value.length) {
    const requestedStart = toDateKey(bookingForm.checkIn)
    const requestedEnd = toDateKey(bookingForm.checkOut)
    const sellableStatuses = new Set(['available', 'ready', 'vacant clean', 'vacant'])
    const occupiedRoomCodes = new Set(
      bookingRows.value
        .filter((booking) => booking.code !== editingBookingCode.value)
        .filter((booking) =>
          hasDateOverlap(
            requestedStart,
            requestedEnd,
            toDateKey(booking.checkIn),
            toDateKey(booking.checkOut),
          ),
        )
        .flatMap((booking) =>
          Array.isArray(booking.roomDetails)
            ? booking.roomDetails.map((detail) => String(detail.room ?? ''))
            : [],
        )
        .filter(Boolean),
    )

    return roomMasterRows.value
      .filter((room) => sellableStatuses.has(String(room.status ?? '').toLowerCase()))
      .filter((room) => !occupiedRoomCodes.has(String(room.code)))
      .map((room) => ({
        room: room.code,
        roomType: room.type,
        flag: String(room.status ?? '').slice(0, 2).toUpperCase() || 'AV',
        hk: room.note || room.status || 'Available',
        rate: Number(room.rate ?? 0),
      }))
  }

  return []
})

const selectedRooms = computed(() =>
  bookingForm.roomSelections.map((item) => item.room).filter(Boolean),
)

const roomOptionsForIndex = (index) => {
  const selectedByOthers = selectedRooms.value.filter((roomNo) =>
    roomNo && roomNo !== bookingForm.roomSelections[index].room,
  )

  return availableRooms.value
    .filter((item) =>
      !selectedByOthers.includes(item.room) || item.room === bookingForm.roomSelections[index].room,
    )
    .map((item) => ({
      value: item.room,
      label: `${item.room} | ${item.roomType}`,
    }))
}

const roomSelectionInfo = (index) =>
  availableRooms.value.find((item) => item.room === bookingForm.roomSelections[index]?.room) ?? null

const normalizedRoomDetails = computed(() =>
  bookingForm.roomSelections
    .filter((detail) => detail.room)
    .map((detail) => ({
      room: detail.room,
      rate: parseBookingRate(detail.rate),
      adults: Math.max(1, Number(detail.adults || 1)),
      children: Math.max(0, Number(detail.children || 0)),
    })),
)

const stayLength = computed(() => {
  const startDateKey = toDateKey(bookingForm.checkIn)
  const endDateKey = toDateKey(bookingForm.checkOut)

  if (!startDateKey || !endDateKey || endDateKey <= startDateKey) {
    return 0
  }

  return Math.max(0, diffDateKeys(startDateKey, endDateKey))
})

const totalAdults = computed(() =>
  normalizedRoomDetails.value.reduce((total, item) => total + item.adults, 0),
)

const totalChildren = computed(() =>
  normalizedRoomDetails.value.reduce((total, item) => total + item.children, 0),
)

const estimatedSummary = computed(() => {
  const selectedRoomItems = normalizedRoomDetails.value
    .map((detail) => {
      const roomInfo = availableRooms.value.find((item) => item.room === detail.room)

      if (!roomInfo) {
        return null
      }

      return {
        ...roomInfo,
        appliedRate: parseBookingRate(detail.rate || roomInfo.rate),
      }
    })
    .filter(Boolean)

  if (!selectedRoomItems.length || stayLength.value <= 0) {
    return null
  }

  const totalValue = selectedRoomItems.reduce((total, room) => total + room.appliedRate * stayLength.value, 0)

  return {
    roomCount: selectedRoomItems.length,
    roomTypes: [...new Set(selectedRoomItems.map((item) => item.roomType))],
    totalValue,
    total: new Intl.NumberFormat('id-ID', {
      style: 'currency',
      currency: 'IDR',
      maximumFractionDigits: 0,
    }).format(totalValue),
  }
})

const stayRangeLabel = computed(() => {
  const start = toDateKey(bookingForm.checkIn)
  const end = toDateKey(bookingForm.checkOut)
  return `${start} - ${end}`
})

const resetBookingForm = () => {
  bookingForm.guest = ''
  bookingForm.phone = ''
  bookingForm.email = ''
  bookingForm.checkIn = `${businessDateKey.value} 14:00`
  bookingForm.checkOut = `${nextBusinessDateKey.value} 12:00`
  bookingForm.roomSelections = [createRoomSelection()]
  bookingForm.channel = hotel.bookingChannels[0]
  bookingForm.status = hotel.bookingStatuses[0]
  bookingForm.note = ''
}

const applyQueryPrefill = () => {
  const room = String(route.query.room ?? '').trim()
  const checkIn = String(route.query.checkIn ?? '').trim()
  const checkOut = String(route.query.checkOut ?? '').trim()

  if (checkIn) {
    bookingForm.checkIn = checkIn
  }

  if (checkOut) {
    bookingForm.checkOut = checkOut
  }

  if (room) {
    bookingForm.roomSelections = [{
      room,
      rate: '',
      rateFocused: false,
      adults: 2,
      children: 0,
    }]
  }
}

const loadBookingRows = async () => {
  try {
    const response = await api.get('/bookings', {
      params: {
        per_page: 500,
      },
    })

    bookingRows.value = Array.isArray(response.data?.data) ? response.data.data : []
  } catch (error) {
    bookingRows.value = []
  }
}

const loadRoomMaster = async () => {
  loadingRooms.value = true
  roomLoadResult.value = { tone: '', text: '' }

  try {
    const response = await api.get('/rooms', {
      params: {
        per_page: 200,
      },
    })

    roomMasterRows.value = Array.isArray(response.data?.data) ? response.data.data : []
  } catch (error) {
    roomLoadResult.value = {
      tone: 'error',
      text: 'Failed to load the room master from the database. Room options are disabled until the data is available.',
    }
    roomMasterRows.value = []
  } finally {
    loadingRooms.value = false
  }
}

const applyBookingToForm = (booking) => {
  bookingForm.guest = booking.guest ?? ''
  bookingForm.phone = booking.phone ?? ''
  bookingForm.email = booking.email ?? ''
  bookingForm.checkIn = booking.checkIn ?? `${businessDateKey.value} 14:00`
  bookingForm.checkOut = booking.checkOut ?? `${nextBusinessDateKey.value} 12:00`
  bookingForm.channel = booking.channel || hotel.bookingChannels[0]
  bookingForm.status = booking.status || hotel.bookingStatuses[0]
  bookingForm.note = booking.note ?? ''
  bookingForm.roomSelections = (booking.roomDetails?.length
    ? booking.roomDetails.map((detail) => ({
      room: detail.room ?? '',
      rate: sanitizeBookingRate(detail.rate),
      rateFocused: false,
      adults: Number(detail.adults ?? 1),
      children: Number(detail.children ?? 0),
    }))
    : [createRoomSelection()])
}

const loadBooking = async () => {
  if (!isEditMode.value) {
    resetBookingForm()
    applyQueryPrefill()
    return
  }

  loadingBooking.value = true
  bookingResult.value = { tone: '', text: '' }

  try {
    const response = await api.get(`/bookings/${editingBookingCode.value}`)
    const booking = response.data?.data

    if (!booking) {
      throw new Error('Reservation data was not found.')
    }

    applyBookingToForm(booking)
  } catch (error) {
    bookingResult.value = {
      tone: 'error',
      text: error?.response?.data?.message || error?.message || 'Failed to load reservation data from the database.',
    }
  } finally {
    loadingBooking.value = false
  }
}

watch(
  () => bookingForm.checkIn,
  (value) => {
    if (bookingForm.checkOut <= value || bookingForm.checkOut < bookingMinCheckOut.value) {
      bookingForm.checkOut = bookingMinCheckOut.value
    }
  },
  { immediate: true },
)

watch(
  availableRooms,
  (rooms) => {
    bookingForm.roomSelections = bookingForm.roomSelections.map((detail) => ({
      ...detail,
      room: rooms.some((item) => item.room === detail.room) ? detail.room : '',
      rate: rooms.some((item) => item.room === detail.room)
        ? (sanitizeBookingRate(detail.rate) || sanitizeBookingRate(rooms.find((item) => item.room === detail.room)?.rate))
        : '',
      adults: Number(detail.adults || 1),
      children: Number(detail.children || 0),
    }))

    bookingForm.roomSelections = bookingForm.roomSelections.filter((detail, index, list) =>
      detail.room ? list.findIndex((item) => item.room === detail.room) === index : true,
    )

    if (!bookingForm.roomSelections.length) {
      bookingForm.roomSelections = [createRoomSelection()]
      return
    }
  },
  { immediate: true },
)

watch(
  () => bookingForm.roomSelections.map((detail) => detail.room),
  (rooms, previousRooms = []) => {
    rooms.forEach((roomCode, index) => {
      const selection = bookingForm.roomSelections[index]

      if (!selection) {
        return
      }

      if (!roomCode) {
        selection.rate = ''
        return
      }

      if (roomCode !== previousRooms[index]) {
        const matchedRoom = availableRooms.value.find((item) => item.room === roomCode)
        selection.rate = sanitizeBookingRate(matchedRoom?.rate)
      }
    })
  },
)

const displayBookingRate = (selection) => {
  if (!selection.rate) {
    return ''
  }

  return selection.rateFocused ? selection.rate : formatBookingRate(selection.rate)
}

const handleRateFocus = (selection) => {
  selection.rateFocused = true
}

const handleRateInput = (selection, event) => {
  selection.rate = normalizeBookingRateInput(event?.target?.value)
}

const handleRateBlur = (selection) => {
  selection.rateFocused = false
  selection.rate = sanitizeBookingRate(selection.rate)
}

const addRoomSelection = () => {
  bookingForm.roomSelections = [...bookingForm.roomSelections, createRoomSelection()]
}

const removeRoomSelection = (index) => {
  bookingForm.roomSelections = bookingForm.roomSelections.filter((_, currentIndex) => currentIndex !== index)

  if (!bookingForm.roomSelections.length) {
    bookingForm.roomSelections = [createRoomSelection()]
    return
  }
}

const syncLocalBookingState = (booking) => {
  if (!booking?.code) {
    return
  }

  const existingIndex = hotel.bookings.findIndex((item) => item.code === booking.code)

  if (existingIndex === -1) {
    hotel.bookings.unshift(booking)
  } else {
    hotel.bookings.splice(existingIndex, 1, booking)
  }

  hotel.setSelectedBooking(booking.code)
  const bookingRowIndex = bookingRows.value.findIndex((item) => item.code === booking.code)

  if (bookingRowIndex === -1) {
    bookingRows.value.unshift(booking)
  } else {
    bookingRows.value.splice(bookingRowIndex, 1, booking)
  }
}

const submitBooking = async () => {
  bookingResult.value = { tone: '', text: '' }

  if (!bookingForm.guest.trim()) {
    bookingResult.value = { tone: 'error', text: 'Guest name is required before creating a reservation.' }
    return
  }

  if (!normalizedRoomDetails.value.length) {
    bookingResult.value = { tone: 'error', text: 'Select at least one room for this reservation.' }
    return
  }

  saving.value = true

  try {
    const payload = {
      guest: bookingForm.guest.trim(),
      phone: bookingForm.phone.trim(),
      email: bookingForm.email.trim(),
      checkIn: bookingForm.checkIn,
      checkOut: bookingForm.checkOut,
      roomDetails: normalizedRoomDetails.value,
      channel: bookingForm.channel,
      status: bookingForm.status,
      note: bookingForm.note.trim(),
    }
    const response = isEditMode.value
      ? await api.put(`/bookings/${editingBookingCode.value}`, payload)
      : await api.post('/bookings', payload)
    const booking = response.data?.data

    if (!booking) {
      throw new Error('The reservation response is invalid.')
    }

    syncLocalBookingState(booking)
    bookingResult.value = {
      tone: 'success',
      text: isEditMode.value
        ? `Reservation ${booking.code} was updated successfully in the database.`
        : `Reservation ${booking.code} was saved successfully to the database.`,
    }

    if (!isEditMode.value) {
      await router.replace({ name: 'booking-edit', params: { bookingCode: booking.code } })
    } else {
      applyBookingToForm(booking)
    }
  } catch (error) {
    bookingResult.value = {
      tone: 'error',
      text: error?.response?.data?.message || error?.message || 'Failed to save the reservation.',
    }
  } finally {
    saving.value = false
  }
}

watch(
  () => editingBookingCode.value,
  async () => {
    await loadBooking()
  },
)

watch(
  () => route.query,
  () => {
    if (!isEditMode.value) {
      resetBookingForm()
      applyQueryPrefill()
    }
  },
)

onMounted(async () => {
  await Promise.all([loadRoomMaster(), loadBookingRows()])
  await loadBooking()
})
</script>

<template>
  <section class="booking-create-shell">
    <article class="booking-create-main">
      <LoadingState v-if="loadingRooms" label="Loading room master from the database..." overlay />
      <LoadingState v-if="loadingBooking" label="Loading reservation from the database..." overlay />
      <div class="booking-create-head">
        <div>
          <p class="eyebrow-dark">Reservation workspace</p>
          <h3>{{ pageTitle }}</h3>
          <p class="subtle">{{ pageSubtitle }}</p>
          <p v-if="isEditMode" class="subtle">Reservation code: <strong>{{ editingBookingCode }}</strong></p>
        </div>

        <div class="booking-create-actions">
          <button class="action-button" @click="router.push({ name: 'bookings' })">Back to list</button>
          <button
            v-if="isEditMode"
            class="action-button"
            @click="router.push({ name: 'booking-create' })"
          >
            Create new reservation
          </button>
          <button class="action-button primary" :disabled="saving" @click="submitBooking">
            {{ submitLabel }}
          </button>
        </div>
      </div>

      <div v-if="bookingResult.text" class="booking-feedback" :class="bookingResult.tone">
        {{ bookingResult.text }}
      </div>

      <div v-if="roomLoadResult.text" class="booking-feedback" :class="roomLoadResult.tone">
        {{ roomLoadResult.text }}
      </div>

      <section class="booking-create-grid">
        <article class="panel-card panel-dense booking-workspace-card">
          <div class="panel-head panel-head-tight">
            <div>
              <p class="eyebrow-dark">Guest profile</p>
              <h3>Guest information</h3>
            </div>
            <span class="status-badge info">Walk-in / OTA / Direct</span>
          </div>

          <div class="booking-form-grid">
            <label class="field-stack field-span-2">
              <span>Guest name</span>
              <input v-model="bookingForm.guest" class="form-control"  />
            </label>

            <label class="field-stack">
              <span>Phone</span>
              <input v-model="bookingForm.phone" class="form-control" />
            </label>

            <label class="field-stack">
              <span>Email</span>
              <input v-model="bookingForm.email" class="form-control" />
            </label>
          </div>
        </article>

        <article class="panel-card panel-dense booking-workspace-card">
          <div class="panel-head panel-head-tight">
            <div>
              <p class="eyebrow-dark">Stay details</p>
              <h3>Stay details</h3>
            </div>
            <span class="status-badge success">{{ stayLength }} night(s)</span>
          </div>

          <div class="booking-form-grid">
            <label class="field-stack">
              <span>Check-in</span>
              <DateTimePickerField
                v-model="bookingForm.checkIn"
                :min-date="bookingMinDate"
                placeholder="Select check-in date"
              />
            </label>

            <label class="field-stack">
              <span>Check-out</span>
              <DateTimePickerField
                v-model="bookingForm.checkOut"
                :min-date="bookingMinCheckOut"
                placeholder="Select check-out date"
              />
            </label>

            <label class="field-stack">
              <span>Booking source</span>
              <Select2Field
                v-model="bookingForm.channel"
                :options="hotel.bookingChannels.map((item) => ({ value: item, label: item }))"
                :multiple="false"
                placeholder="Select source"
              />
            </label>

            <label class="field-stack">
              <span>Status</span>
              <Select2Field
                v-model="bookingForm.status"
                :options="hotel.bookingStatuses.map((item) => ({ value: item, label: item }))"
                :multiple="false"
                placeholder="Select status"
              />
            </label>
          </div>
        </article>
      </section>

      <article class="panel-card panel-dense booking-workspace-card">
        <div class="panel-head panel-head-tight">
          <div>
            <p class="eyebrow-dark">Room assignment</p>
          </div>
          <span class="status-badge warning">{{ availableRooms.length }} rooms from room table</span>
        </div>

        <div class="booking-room-table-head">
          <span>Room</span>
          <span>Adult</span>
          <span>Children</span>
          <span>Rate</span>
          <span></span>
        </div>

        <div class="room-select-stack booking-room-line-stack">
          <div
            v-for="(selection, index) in bookingForm.roomSelections"
            :key="`page-room-select-${index}`"
            class="room-select-card booking-room-line"
          >
            <div class="booking-room-line-grid">
              <div class="field-stack booking-room-field">
                <span class="booking-room-mobile-label">Room</span>
                <Select2Field
                  v-model="bookingForm.roomSelections[index].room"
                  :options="roomOptionsForIndex(index)"
                  :multiple="false"
                  placeholder="Select room"
                />
              </div>

              <label class="field-stack booking-room-field">
                <span class="booking-room-mobile-label">Adult</span>
                <input v-model="selection.adults" class="form-control" min="1" max="8" type="number" placeholder="0" />
              </label>

              <label class="field-stack booking-room-field">
                <span class="booking-room-mobile-label">Children</span>
                <input v-model="selection.children" class="form-control" min="0" max="6" type="number" placeholder="0" />
              </label>

              <label class="field-stack booking-room-field">
                <span class="booking-room-mobile-label">Rate</span>
                <input
                  :value="displayBookingRate(selection)"
                  class="form-control"
                  inputmode="decimal"
                  type="text"
                  placeholder="0"
                  @focus="handleRateFocus(selection)"
                  @input="handleRateInput(selection, $event)"
                  @blur="handleRateBlur(selection)"
                />
              </label>

              <div class="booking-room-line-action">
                <button
                  v-if="bookingForm.roomSelections.length > 1"
                  type="button"
                  class="action-button room-select-remove"
                  @click="removeRoomSelection(index)"
                >
                  Remove
                </button>
              </div>
            </div>
          </div>

          <button type="button" class="action-button" @click="addRoomSelection">Add room</button>
        </div>
      </article>

      <article class="panel-card panel-dense booking-workspace-card">
        <div class="panel-head panel-head-tight">
          <div>
            <p class="eyebrow-dark">Reservation note</p>
            <h3>Operational notes</h3>
          </div>
          <span class="status-badge info">FO note</span>
        </div>

        <label class="field-stack">
          <span>Special note</span>
          <textarea
            v-model="bookingForm.note"
            class="form-control form-textarea booking-create-note"
            placeholder="Example: early check-in, airport pickup, birthday setup, deposit note"
          ></textarea>
        </label>
      </article>
    </article>

    <aside class="booking-create-sidebar">
      <article class="panel-card panel-dense booking-create-summary">
        <div class="panel-head panel-head-tight">
          <div>
            <p class="eyebrow-dark">Reservation summary</p>
            <h3>Stay summary</h3>
          </div>
          <span class="status-badge success">Live</span>
        </div>

        <div class="compact-list">
          <div class="list-row list-row-tight">
            <strong>Guest</strong>
            <p class="subtle">{{ bookingForm.guest || 'Not filled in yet' }}</p>
          </div>
          <div class="list-row list-row-tight">
            <strong>Stay range</strong>
            <p class="subtle">{{ stayRangeLabel }}</p>
          </div>
          <div class="list-row list-row-tight">
            <strong>Room count</strong>
            <p class="subtle">{{ selectedRooms.length }} room(s)</p>
          </div>
          <div class="list-row list-row-tight">
            <strong>Total pax</strong>
            <p class="subtle">{{ totalAdults }} adult(s), {{ totalChildren }} child(ren)</p>
          </div>
          <div class="list-row list-row-tight">
            <strong>Booking source</strong>
            <p class="subtle">{{ bookingForm.channel }}</p>
          </div>
          <div class="list-row list-row-tight">
            <strong>Status</strong>
            <p class="subtle">{{ bookingForm.status }}</p>
          </div>
        </div>
      </article>

      <article class="panel-card panel-dense booking-create-summary">
        <div class="panel-head panel-head-tight">
          <div>
            <p class="eyebrow-dark">Rate preview</p>
            <h3>Estimated charge</h3>
          </div>
          <span class="summary-code">ABS</span>
        </div>

        <div v-if="estimatedSummary" class="compact-list">
          <div class="list-row list-row-tight">
            <strong>Total room charge</strong>
            <p class="subtle">{{ estimatedSummary.total }}</p>
          </div>
          <div class="list-row list-row-tight">
            <strong>Room type mix</strong>
            <p class="subtle">{{ estimatedSummary.roomTypes.join(', ') }}</p>
          </div>
          <div class="list-row list-row-tight">
            <strong>Nights</strong>
            <p class="subtle">{{ stayLength }} night(s)</p>
          </div>
        </div>
        <p v-else class="subtle">Select rooms and a date range to see the estimated rate.</p>
      </article>

    </aside>
  </section>
</template>
