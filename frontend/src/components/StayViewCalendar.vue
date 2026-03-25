<script setup>
import { computed } from 'vue'
import { useHotelStore } from '../stores/hotel'

const hotel = useHotelStore()
const props = defineProps({
  calendarDates: {
    type: Array,
    default: null,
  },
  stayCalendar: {
    type: Array,
    default: null,
  },
  staySummary: {
    type: Array,
    default: null,
  },
  calendarRangeLabel: {
    type: String,
    default: '',
  },
  selectedCode: {
    type: String,
    default: '',
  },
  interactive: {
    type: Boolean,
    default: false,
  },
})
const emit = defineEmits(['select-booking', 'select-slot', 'navigate-prev', 'navigate-next', 'navigate-today'])

const calendarDates = computed(() => props.calendarDates ?? hotel.calendarDates)
const selectedCode = computed(() => props.selectedCode ?? hotel.selectedBookingCode)
const stayCalendar = computed(() => props.stayCalendar ?? hotel.stayCalendar)
const staySummary = computed(() => props.staySummary ?? hotel.staySummary)
const calendarRangeLabel = computed(() => props.calendarRangeLabel ?? hotel.calendarRangeLabel)

const timelineWidth = computed(() => `${calendarDates.value.length * 144}px`)

const sheetStyle = computed(() => ({
  '--stay-grid-width': timelineWidth.value,
}))

const gridStyle = computed(() => ({
  gridTemplateColumns: `repeat(${calendarDates.value.length}, minmax(144px, 1fr))`,
}))

const bookingStyle = (booking) => {
  const totalDays = calendarDates.value.length || 1
  const leftPercent = (booking.start / totalDays) * 100
  const widthPercent = (booking.span / totalDays) * 100

  return {
    left: `calc(${leftPercent}% + 6px)`,
    width: `calc(${widthPercent}% - 12px)`,
  }
}

const selectBooking = (bookingCode) => {
  emit('select-booking', bookingCode)
  hotel.selectStayBooking(bookingCode)
}

const selectSlot = (roomNo, dayKey) => {
  if (!props.interactive) {
    return
  }

  emit('select-slot', { room: roomNo, date: dayKey })
}
</script>

<template>
  <section class="panel-card panel-dense stayview-card">
    <div class="panel-head">
      <div>
        <p class="eyebrow-dark">Stay view calendar</p>
        <h3>Room booking calendar</h3>
        <p class="panel-note">Grid tanggal, booking bar, housekeeping status, dan room hold ala eZee.</p>
      </div>

      <div class="stayview-head-actions">
        <span class="status-badge info">{{ calendarRangeLabel }}</span>
        <span class="status-badge warning">{{ staySummary[2]?.value ?? 0 }} unassigned</span>
      </div>
    </div>

    <section class="summary-strip stayview-summary">
      <article
        v-for="item in staySummary"
        :key="item.label"
        class="summary-box"
        :class="{ accent: item.label === 'Balance due' }"
      >
        <p class="summary-label">{{ item.label }}</p>
        <strong>{{ item.value }}</strong>
        <span>{{ item.note }}</span>
      </article>
    </section>

    <div class="stayview-toolbar">
      <div class="toolbar-tabs">
        <button class="toolbar-tab active">Stay View</button>
        <button class="toolbar-tab" @click="$emit('navigate-prev')">Prev</button>
        <button class="toolbar-tab" @click="$emit('navigate-today')">Today</button>
        <button class="toolbar-tab" @click="$emit('navigate-next')">Next</button>
      </div>

      <div class="stayview-legend">
        <span
          v-for="item in hotel.stayLegend"
          :key="item.label"
          class="stayview-legend-item"
        >
          <span class="stayview-legend-dot" :class="item.tone"></span>
          <span>{{ item.label }}</span>
        </span>
      </div>
    </div>

    <div class="stayview-board">
      <div class="stayview-sheet" :style="sheetStyle">
        <div class="stayview-header-row">
          <div class="stayview-sidehead">
            <span class="summary-label">Room / HK</span>
            <strong>Inventory-linked stay board</strong>
          </div>

          <div class="stayview-date-grid" :style="gridStyle">
            <div
              v-for="day in calendarDates"
              :key="day.key"
              class="stayview-date-cell"
              :class="{ today: day.today, weekend: day.weekend }"
            >
              <span>{{ day.day }}</span>
              <strong>{{ day.date }}</strong>
              <small>{{ day.label }}</small>
            </div>
          </div>
        </div>

        <div
          v-for="group in stayCalendar"
          :key="group.roomType"
          class="stayview-group"
        >
          <div class="stayview-group-row">
            <div class="stayview-group-meta">
              <div>
                <strong>{{ group.roomType }}</strong>
                <p class="subtle">{{ group.plan }}</p>
              </div>

              <div class="stayview-group-badges">
                <span class="summary-code">{{ group.availability }}</span>
                <span v-if="group.unassigned" class="status-badge warning">
                  {{ group.unassigned }} unassigned
                </span>
              </div>
            </div>

            <div class="stayview-group-track" :style="gridStyle">
              <div
                v-for="day in calendarDates"
                :key="`${group.roomType}-${day.key}`"
                class="stayview-group-cell"
                :class="{ today: day.today, weekend: day.weekend }"
              ></div>
            </div>
          </div>

          <div
            v-for="room in group.rooms"
            :key="`${group.roomType}-${room.no}`"
            class="stayview-room-row"
          >
            <div class="stayview-room-meta">
              <div class="stayview-room-title">
                <strong>Room {{ room.no }}</strong>
                <span class="summary-code">{{ room.flag }}</span>
              </div>
              <p class="subtle">{{ room.hk }}</p>
            </div>

            <div class="stayview-track" :style="gridStyle">
              <div
                v-for="day in calendarDates"
                :key="`${room.no}-${day.key}`"
                class="stayview-track-cell"
                :class="{ today: day.today, weekend: day.weekend }"
                @click="selectSlot(room.no, day.key)"
              ></div>

              <article
                v-for="booking in room.bookings"
                :key="booking.id"
                class="stayview-booking"
                :class="[booking.status, { selected: selectedCode === booking.code }]"
                :style="bookingStyle(booking)"
                tabindex="0"
                @click="selectBooking(booking.code)"
                @keyup.enter="selectBooking(booking.code)"
              >
                <div class="stayview-booking-head">
                  <strong>{{ booking.guest }}</strong>
                  <span>{{ booking.code }}</span>
                </div>
                <p>{{ booking.source }} | {{ booking.pax }}</p>
                <small>{{ booking.balance }}</small>
              </article>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>
