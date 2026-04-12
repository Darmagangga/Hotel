<script setup>
import { computed, reactive, ref, watch } from 'vue'
import DateTimePickerField from './DateTimePickerField.vue'
import Select2Field from './Select2Field.vue'
import { useHotelStore } from '../stores/hotel'

const emit = defineEmits(['close', 'created'])

const hotel = useHotelStore()
const toDateKey = (value) => String(value ?? '').slice(0, 10)
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
const firstDateKey = toDateKey(hotel.currentBusinessDate) || toIsoDate(new Date())
const secondDateKey = addDateKeyDays(firstDateKey, 1)

const bookingResult = ref({ tone: '', text: '' })
const createRoomSelection = () => ({
  room: '',
  adults: 2,
  children: 0,
})

const bookingForm = reactive({
  guest: '',
  checkIn: `${firstDateKey} 14:00`,
  checkOut: `${secondDateKey} 12:00`,
  roomSelections: [createRoomSelection()],
  channel: hotel.bookingChannels[0],
  status: hotel.bookingStatuses[0],
  note: '',
})

const availableRooms = computed(() =>
  hotel.availableRooms({
    checkIn: bookingForm.checkIn,
    checkOut: bookingForm.checkOut,
  }),
)

const bookingMinDate = computed(() => `${firstDateKey} 00:00`)
const bookingMinCheckOut = computed(() => {
  const currentDateKey = toDateKey(bookingForm.checkIn)
  const nextDateKey = addDateKeyDays(currentDateKey || firstDateKey, 1)
  return `${nextDateKey} 12:00`
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
      label: `Room ${item.room} | ${item.roomType} | ${item.flag} | ${item.hk}`,
    }))
}

const roomSelectionInfo = (index) =>
  availableRooms.value.find((item) => item.room === bookingForm.roomSelections[index]?.room) ?? null

const stayLength = computed(() => {
  const startDateKey = toDateKey(bookingForm.checkIn)
  const endDateKey = toDateKey(bookingForm.checkOut)

  if (!startDateKey || !endDateKey || endDateKey <= startDateKey) {
    return 0
  }

  return Math.max(0, diffDateKeys(startDateKey, endDateKey))
})

const amountPreview = computed(() => {
  const selectedRoomItems = availableRooms.value.filter((item) => selectedRooms.value.includes(item.room))

  if (!selectedRoomItems.length || stayLength.value <= 0) {
    return null
  }

  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
  }).format(selectedRoomItems.reduce((total, room) => total + room.rate * stayLength.value, 0))
})

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
      adults: Number(detail.adults || 1),
      children: Number(detail.children || 0),
    }))

    if (!bookingForm.roomSelections.length) {
      bookingForm.roomSelections = [createRoomSelection()]
      return
    }

    if (!bookingForm.roomSelections.some((detail) => !detail.room)) {
      bookingForm.roomSelections = [...bookingForm.roomSelections, createRoomSelection()]
    }
  },
  { immediate: true },
)

const normalizedRoomDetails = computed(() =>
  bookingForm.roomSelections
    .filter((detail) => detail.room)
    .map((detail) => ({
      room: detail.room,
      adults: Math.max(1, Number(detail.adults || 1)),
      children: Math.max(0, Number(detail.children || 0)),
    })),
)

const addRoomSelection = () => {
  bookingForm.roomSelections = [...bookingForm.roomSelections, createRoomSelection()]
}

const removeRoomSelection = (index) => {
  bookingForm.roomSelections = bookingForm.roomSelections.filter((_, currentIndex) => currentIndex !== index)

  if (!bookingForm.roomSelections.length) {
    bookingForm.roomSelections = [createRoomSelection()]
    return
  }

  if (!bookingForm.roomSelections.some((detail) => !detail.room)) {
    bookingForm.roomSelections = [...bookingForm.roomSelections, createRoomSelection()]
  }
}

const totalAdults = computed(() =>
  normalizedRoomDetails.value.reduce((total, item) => total + item.adults, 0),
)

const totalChildren = computed(() =>
  normalizedRoomDetails.value.reduce((total, item) => total + item.children, 0),
)

const submitBooking = () => {
  bookingResult.value = { tone: '', text: '' }

  if (!bookingForm.guest.trim()) {
    bookingResult.value = { tone: 'error', text: 'Guest name is required.' }
    return
  }

  if (!normalizedRoomDetails.value.length) {
    bookingResult.value = { tone: 'error', text: 'Select at least one room.' }
    return
  }

  try {
    const booking = hotel.createBooking({
      guest: bookingForm.guest.trim(),
      checkIn: bookingForm.checkIn,
      checkOut: bookingForm.checkOut,
      roomDetails: normalizedRoomDetails.value,
      channel: bookingForm.channel,
      status: bookingForm.status,
      note: bookingForm.note.trim(),
    })

    emit('created', booking)
  } catch (error) {
    bookingResult.value = {
      tone: 'error',
      text: error instanceof Error ? error.message : 'Failed to create booking.',
    }
  }
}
</script>

<template>
  <div class="modal-backdrop" @click.self="emit('close')">
    <section class="modal-card">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Quick booking</p>
          <h3>New reservation</h3>
        </div>
        <button class="action-button" @click="emit('close')">Close</button>
      </div>

      <div class="booking-form-grid">
        <label class="field-stack">
          <span>Guest name</span>
          <input v-model="bookingForm.guest" class="form-control" placeholder="Example: Dimas Pratama" />
        </label>

        <label class="field-stack">
          <span>Check-in</span>
          <DateTimePickerField
            v-model="bookingForm.checkIn"
            :min-date="bookingMinDate"
            placeholder="Select check-in date and time"
          />
        </label>

        <label class="field-stack">
          <span>Check-out</span>
          <DateTimePickerField
            v-model="bookingForm.checkOut"
            :min-date="bookingMinCheckOut"
            placeholder="Select check-out date and time"
          />
        </label>

        <label class="field-stack">
          <span>Booking source</span>
          <select v-model="bookingForm.channel" class="form-control">
            <option v-for="item in hotel.bookingChannels" :key="item" :value="item">{{ item }}</option>
          </select>
        </label>

        <label class="field-stack">
          <span>Status</span>
          <select v-model="bookingForm.status" class="form-control">
            <option v-for="item in hotel.bookingStatuses" :key="item" :value="item">{{ item }}</option>
          </select>
        </label>

        <div class="field-stack field-span-2">
          <span>Available rooms</span>
          <div class="room-select-stack">
            <div
              v-for="(selection, index) in bookingForm.roomSelections"
              :key="`quick-room-select-${index}`"
              class="room-select-card"
            >
              <div class="room-select-row">
                <Select2Field
                  v-model="bookingForm.roomSelections[index].room"
                  :options="roomOptionsForIndex(index)"
                  :multiple="false"
                  placeholder="Select room"
                />
                <button
                  v-if="bookingForm.roomSelections.length > 1"
                  type="button"
                  class="action-button room-select-remove"
                  @click="removeRoomSelection(index)"
                >
                  Remove
                </button>
              </div>

              <div class="room-detail-grid">
                <div class="note-cell room-detail-note">
                  <strong>Room detail</strong>
                  <p class="subtle">
                    <template v-if="roomSelectionInfo(index)">
                      {{ roomSelectionInfo(index).roomType }} | {{ roomSelectionInfo(index).flag }} | {{ roomSelectionInfo(index).hk }}
                    </template>
                    <template v-else>
                      Select a room to view the room type and housekeeping info.
                    </template>
                  </p>
                </div>

                <label class="field-stack">
                  <span>Adults</span>
                  <input v-model="selection.adults" class="form-control" type="number" min="1" max="8" />
                </label>

                <label class="field-stack">
                  <span>Children</span>
                  <input v-model="selection.children" class="form-control" type="number" min="0" max="6" />
                </label>
              </div>
            </div>
            <button type="button" class="action-button" @click="addRoomSelection">Add room</button>
          </div>
        </div>

        <label class="field-stack field-span-2">
          <span>Special note</span>
          <textarea
            v-model="bookingForm.note"
            class="form-control form-textarea"
            placeholder="Pickup, decor, payment note, or guest request"
          ></textarea>
        </label>
      </div>

      <div class="booking-inline-summary">
        <div class="note-cell">
          <strong>Availability</strong>
          <p class="subtle">
            {{ availableRooms.length }} room(s) available | {{ selectedRooms.length }} selected | {{ stayLength }} night(s)
          </p>
        </div>
        <div v-if="amountPreview" class="note-cell">
          <strong>Estimated amount</strong>
          <p class="subtle">{{ amountPreview }} | {{ totalAdults }} adult(s), {{ totalChildren }} child(ren)</p>
        </div>
      </div>

      <div v-if="bookingResult.text" class="booking-feedback" :class="bookingResult.tone">
        {{ bookingResult.text }}
      </div>

      <div class="modal-actions">
        <button class="action-button" @click="emit('close')">Cancel</button>
        <button class="action-button primary" @click="submitBooking">Create booking</button>
      </div>
    </section>
  </div>
</template>
