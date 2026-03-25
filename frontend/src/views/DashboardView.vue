<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '../services/api'
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
const showAdvancedSections = false

const dashboard = ref({
  overview: [],
  dailyControl: [],
  revenueMix: [],
  arrivalWatch: [],
  cashierQueue: [],
  channelPerformance: [],
  roomTypePerformance: [],
  liveMovement: [],
  departmentNotes: [],
})

const hasDashboardData = computed(() => {
  return [
    dashboard.value.overview,
    dashboard.value.dailyControl,
    dashboard.value.revenueMix,
    dashboard.value.arrivalWatch,
    dashboard.value.cashierQueue,
  ].some((collection) => Array.isArray(collection) && collection.length > 0)
})

const buildQueryParams = () => {
  const params = { period: activePeriod.value }

  if (activePeriod.value === 'custom') {
    if (!customStart.value || !customEnd.value) {
      throw new Error('Pilih tanggal mulai dan akhir untuk custom range.')
    }

    params.start_date = customStart.value
    params.end_date = customEnd.value
  }

  return params
}

const loadDashboardData = async () => {
  loading.value = true
  errorMessage.value = ''

  try {
    const response = await api.get('/dashboard/owner', {
      params: buildQueryParams(),
    })
    const payload = response.data?.data ?? {}

    dashboard.value = {
      overview: Array.isArray(payload.overview) ? payload.overview : [],
      dailyControl: Array.isArray(payload.dailyControl) ? payload.dailyControl : [],
      revenueMix: Array.isArray(payload.revenueMix) ? payload.revenueMix : [],
      arrivalWatch: Array.isArray(payload.arrivalWatch) ? payload.arrivalWatch : [],
      cashierQueue: Array.isArray(payload.cashierQueue) ? payload.cashierQueue : [],
      channelPerformance: Array.isArray(payload.channelPerformance) ? payload.channelPerformance : [],
      roomTypePerformance: Array.isArray(payload.roomTypePerformance) ? payload.roomTypePerformance : [],
      liveMovement: Array.isArray(payload.liveMovement) ? payload.liveMovement : [],
      departmentNotes: Array.isArray(payload.departmentNotes) ? payload.departmentNotes : [],
    }

    appliedRangeLabel.value = payload.rangeLabel ?? ''
    appliedPeriodLabel.value = payload.periodLabel ?? 'Today'
    generatedAt.value = payload.generatedAt ?? ''

    hotel.setOverview(dashboard.value.overview)
    hotel.setBusinessDate(payload.businessDate)
    hotel.setCurrentDateLabel(payload.currentDateLabel)
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : 'Gagal memuat dashboard owner.'
    console.error('Gagal memuat dashboard owner:', error)
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

onMounted(async () => {
  await loadDashboardData()
})
</script>

<template>
  <section class="dashboard-owner-hero">
    <div class="dashboard-owner-copy">
      <p class="eyebrow-dark">Owner dashboard</p>
      <h2>Business pulse that is ready for review</h2>
      <p class="dashboard-owner-text">
        Pantau occupancy, arrival pressure, outstanding folio, dan performa channel dari satu layar yang
        terhubung langsung ke database.
      </p>
      <div class="dashboard-owner-meta">
        <span class="dashboard-meta-pill">{{ appliedPeriodLabel }}</span>
        <span class="dashboard-meta-pill">{{ appliedRangeLabel || hotel.currentDateLabel }}</span>
        <span class="dashboard-meta-pill" v-if="generatedAt">Updated {{ generatedAt }}</span>
      </div>
    </div>

    <div class="dashboard-owner-actions">
      <div class="dashboard-period-switch">
        <button
          type="button"
          class="switch-chip"
          :class="{ active: activePeriod === 'today' }"
          @click="applyPreset('today')"
        >
          Today
        </button>
        <button
          type="button"
          class="switch-chip"
          :class="{ active: activePeriod === 'week' }"
          @click="applyPreset('week')"
        >
          This Week
        </button>
        <button
          type="button"
          class="switch-chip"
          :class="{ active: activePeriod === 'month' }"
          @click="applyPreset('month')"
        >
          This Month
        </button>
        <button
          type="button"
          class="switch-chip"
          :class="{ active: activePeriod === 'custom' }"
          @click="applyPreset('custom')"
        >
          Custom
        </button>
      </div>

      <div class="dashboard-custom-range" v-if="activePeriod === 'custom'">
        <label class="field-stack">
          <span>Start</span>
          <input v-model="customStart" type="date" class="form-control" />
        </label>
        <label class="field-stack">
          <span>End</span>
          <input v-model="customEnd" type="date" class="form-control" />
        </label>
        <button type="button" class="action-button primary" @click="applyCustomRange">Apply range</button>
      </div>

      <div class="dashboard-action-row">
        <button type="button" class="utility-button" @click="loadDashboardData">Refresh</button>
        <button type="button" class="utility-button" @click="printDashboard">Print summary</button>
      </div>
    </div>
  </section>

  <div v-if="errorMessage" class="booking-feedback error">
    {{ errorMessage }}
  </div>

  <section v-if="loading" class="panel-card dashboard-state-card">
    <div class="loading-state">
      <span class="loading-spinner"></span>
      <div>
        <strong>Refreshing owner dashboard</strong>
        <p class="subtle">Menarik data terbaru dari database untuk periode yang dipilih.</p>
      </div>
    </div>
  </section>

  <section v-else-if="!hasDashboardData" class="panel-card dashboard-empty-card">
    <p class="eyebrow-dark">No dashboard data</p>
    <h3>Belum ada aktivitas untuk periode ini</h3>
    <p class="subtle">
      Coba ganti periodenya atau mulai dari booking dan payment supaya owner dashboard menampilkan sinyal bisnis.
    </p>
  </section>

  <template v-else>
    <section class="summary-strip">
      <article
        v-for="(item, index) in dashboard.overview"
        :key="item.label"
        class="summary-box"
        :class="{ accent: index === 2 }"
      >
        <p class="summary-label">{{ item.label }}</p>
        <strong>{{ item.value }}</strong>
        <span>{{ item.note }}</span>
      </article>
    </section>

    <section class="dashboard-grid">
      <article class="panel-card panel-dense">
        <div class="panel-head panel-head-tight">
          <div>
            <p class="eyebrow-dark">Owner pulse</p>
            <h3>Daily control board</h3>
            <p class="panel-note">Snapshot untuk {{ appliedRangeLabel }}</p>
          </div>
          <span class="status-badge warning">{{ dashboard.cashierQueue.length }} need follow-up</span>
        </div>

        <div class="desk-alert-grid">
          <div v-for="item in dashboard.dailyControl" :key="item.label" class="desk-alert">
            <strong>{{ item.value }}</strong>
            <span>{{ item.label }}</span>
          </div>
        </div>
      </article>

      <article class="panel-card panel-dense">
        <div class="panel-head panel-head-tight">
          <div>
            <p class="eyebrow-dark">Revenue mix</p>
            <h3>Money snapshot</h3>
            <p class="panel-note">Komposisi pendapatan pada periode aktif</p>
          </div>
        </div>

        <div class="progress-stack">
          <div v-for="item in dashboard.revenueMix" :key="item.label">
            <div class="progress-label">
              <span>{{ item.label }}</span>
              <strong>{{ item.value }}</strong>
            </div>
            <div class="progress-bar">
              <span :style="{ width: `${Math.min(Number(item.progress ?? 0), 100)}%` }"></span>
            </div>
          </div>
        </div>
      </article>
    </section>

    <section v-if="showAdvancedSections" class="page-grid two">
      <article class="panel-card panel-dense">
        <div class="panel-head panel-head-tight">
          <div>
            <p class="eyebrow-dark">Arrivals watch</p>
            <h3>Guests to prepare</h3>
            <p class="panel-note">Kedatangan yang masuk dalam periode aktif</p>
          </div>
          <span class="status-badge info">{{ dashboard.arrivalWatch.length }} visible</span>
        </div>

        <div class="compact-list">
          <div v-for="item in dashboard.arrivalWatch" :key="`${item.guest}-${item.room}`" class="list-row list-row-tight">
            <div class="split-row">
              <strong>{{ item.guest }}</strong>
              <span>{{ item.time }}</span>
            </div>
            <p class="subtle">{{ item.room }} | {{ item.note }}</p>
          </div>
          <p v-if="!dashboard.arrivalWatch.length" class="subtle booking-addon-empty">Tidak ada arrival pada periode ini.</p>
        </div>
      </article>

      <article class="panel-card panel-dense">
        <div class="panel-head panel-head-tight">
          <div>
            <p class="eyebrow-dark">Cashier queue</p>
            <h3>Outstanding folios</h3>
            <p class="panel-note">Settlement yang masih perlu di-follow-up</p>
          </div>
          <span class="status-badge warning">Need settlement</span>
        </div>

        <div class="compact-list">
          <div v-for="item in dashboard.cashierQueue" :key="`${item.guest}-${item.due}`" class="list-row list-row-tight">
            <div class="split-row">
              <strong>{{ item.guest }}</strong>
              <span>{{ item.balance }}</span>
            </div>
            <p class="subtle">{{ item.item }} | Due {{ item.due }}</p>
          </div>
          <p v-if="!dashboard.cashierQueue.length" class="subtle booking-addon-empty">Semua folio pada periode ini sudah settle.</p>
        </div>
      </article>
    </section>

    <section v-if="showAdvancedSections" class="page-grid two">
      <article class="panel-card panel-dense">
        <div class="panel-head panel-head-tight">
          <div>
            <p class="eyebrow-dark">Channel mix</p>
            <h3>Top booking sources</h3>
            <p class="panel-note">Sumber revenue teratas pada periode aktif</p>
          </div>
          <span class="status-badge info">{{ dashboard.channelPerformance.length }} source tracked</span>
        </div>

        <table class="data-table">
          <thead>
            <tr>
              <th>Channel</th>
              <th>Bookings</th>
              <th>Revenue</th>
              <th>Outstanding</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!dashboard.channelPerformance.length">
              <td colspan="4" class="table-empty-cell">Belum ada data channel pada periode ini.</td>
            </tr>
            <tr v-for="item in dashboard.channelPerformance" :key="item.channel">
              <td><strong>{{ item.channel }}</strong></td>
              <td>{{ item.bookings }}</td>
              <td>{{ item.revenue }}</td>
              <td>{{ item.outstanding }}</td>
            </tr>
          </tbody>
        </table>
      </article>

      <article class="panel-card panel-dense">
        <div class="panel-head panel-head-tight">
          <div>
            <p class="eyebrow-dark">Room type mix</p>
            <h3>Product performance</h3>
            <p class="panel-note">Kinerja produk kamar dan add-on</p>
          </div>
          <span class="status-badge success">{{ dashboard.roomTypePerformance.length }} type tracked</span>
        </div>

        <table class="data-table">
          <thead>
            <tr>
              <th>Room type</th>
              <th>Bookings</th>
              <th>Room revenue</th>
              <th>Add-on</th>
              <th>Total</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!dashboard.roomTypePerformance.length">
              <td colspan="5" class="table-empty-cell">Belum ada data room type pada periode ini.</td>
            </tr>
            <tr v-for="item in dashboard.roomTypePerformance" :key="item.roomType">
              <td><strong>{{ item.roomType }}</strong></td>
              <td>{{ item.bookings }}</td>
              <td>{{ item.roomRevenue }}</td>
              <td>{{ item.addonRevenue }}</td>
              <td>{{ item.totalRevenue }}</td>
            </tr>
          </tbody>
        </table>
      </article>
    </section>

    <section class="page-grid two">
      <article class="panel-card panel-dense">
        <div class="panel-head panel-head-tight">
          <div>
            <p class="eyebrow-dark">Live movement</p>
            <h3>Guest activity board</h3>
            <p class="panel-note">Snapshot in-house berdasarkan business date aktif</p>
          </div>
          <span class="status-badge info">Database live</span>
        </div>

        <table class="data-table">
          <thead>
            <tr>
              <th>Room</th>
              <th>Guest</th>
              <th>Stay</th>
              <th>Status</th>
              <th>Next action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!dashboard.liveMovement.length">
              <td colspan="5" class="table-empty-cell">Belum ada tamu in-house yang aktif.</td>
            </tr>
            <tr v-for="item in dashboard.liveMovement" :key="`${item.room}-${item.guest}`">
              <td><strong>{{ item.room }}</strong></td>
              <td>{{ item.guest }}</td>
              <td>{{ item.stay }}</td>
              <td>{{ item.status }}</td>
              <td class="subtle">{{ item.eta }}</td>
            </tr>
          </tbody>
        </table>
      </article>

      <article class="panel-card panel-dense">
        <div class="panel-head panel-head-tight">
          <div>
            <p class="eyebrow-dark">Department board</p>
            <h3>Execution notes</h3>
            <p class="panel-note">Catatan singkat untuk tindak lanjut tiap departemen</p>
          </div>
          <span class="status-badge success">Operational pulse</span>
        </div>

        <div class="note-board">
          <div v-for="item in dashboard.departmentNotes" :key="item.department" class="note-cell">
            <strong>{{ item.department }}</strong>
            <p class="subtle">{{ item.note }}</p>
          </div>
        </div>
      </article>
    </section>
  </template>
</template>
