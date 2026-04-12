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
const periodOptions = [
  { value: 'today', label: 'Today' },
  { value: 'week', label: 'This week' },
  { value: 'month', label: 'This month' },
  { value: 'custom', label: 'Custom' },
]

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

const followUpCount = computed(() => dashboard.value.cashierQueue.length)
const activeSnapshotLabel = computed(() => appliedRangeLabel.value || hotel.currentDateLabel)
const dashboardHeadline = computed(() => {
  if (activePeriod.value === 'custom') {
    return 'Business summary for the selected date range'
  }

  return 'Monitor operations, revenue, and folios from one concise screen'
})

const summaryTone = (label, index) => {
  const value = String(label ?? '').toLowerCase()

  if (value.includes('outstanding')) {
    return 'alert'
  }

  if (value.includes('occupancy') || value.includes('adr')) {
    return 'calm'
  }

  if (value.includes('arrival')) {
    return 'accent'
  }

  return index === 0 ? 'calm' : 'default'
}

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

onMounted(async () => {
  await loadDashboardData()
})
</script>

<template>
  <section class="dashboard-command-deck">
    <div class="dashboard-command-head">
      <div class="dashboard-owner-copy">
        <p class="eyebrow-dark">Dashboard owner</p>
        <h2>{{ dashboardHeadline }}</h2>
        <p class="dashboard-owner-text">
          Focus on occupancy, arrivals, outstanding folios, and revenue mix without unnecessary visual distraction.
        </p>
      </div>

      <div class="dashboard-command-actions">
        <button type="button" class="utility-button" @click="loadDashboardData">Refresh data</button>
        <button type="button" class="utility-button" @click="printDashboard">Print summary</button>
      </div>
    </div>

    <div class="dashboard-command-toolbar">
      <div class="dashboard-owner-meta">
        <span class="dashboard-meta-pill">
          <strong>Period</strong>
          {{ appliedPeriodLabel }}
        </span>
        <span class="dashboard-meta-pill">
          <strong>Snapshot</strong>
          {{ activeSnapshotLabel }}
        </span>
        <span class="dashboard-meta-pill" v-if="generatedAt">
          <strong>Updated</strong>
          {{ generatedAt }}
        </span>
      </div>

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
    </div>

    <div class="dashboard-custom-range" v-if="activePeriod === 'custom'">
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

  </section>

  <div v-if="errorMessage" class="booking-feedback error">
    {{ errorMessage }}
  </div>

  <section v-if="loading" class="panel-card dashboard-state-card">
    <div class="loading-state">
      <span class="loading-spinner"></span>
      <div>
        <strong>Refreshing owner dashboard</strong>
        <p class="subtle">Fetching the latest data from the database for the selected period.</p>
      </div>
    </div>
  </section>

  <section v-else-if="!hasDashboardData" class="panel-card dashboard-empty-card">
    <p class="eyebrow-dark">Empty dashboard</p>
    <h3>There is no activity for this period yet</h3>
    <p class="subtle">
      Try changing the period or start with bookings and payments so the dashboard can show business signals.
    </p>
  </section>

  <template v-else>
    <section class="summary-strip">
      <article
        v-for="(item, index) in dashboard.overview"
        :key="item.label"
        class="summary-box"
        :class="`summary-box--${summaryTone(item.label, index)}`"
      >
        <div class="summary-box-head">
          <p class="summary-label">{{ item.label }}</p>
          <span class="summary-kicker">
            {{ index === 0 ? 'Operasional' : index === 1 ? 'Front office' : index === 2 ? 'Perlu fokus' : 'Kinerja' }}
          </span>
        </div>
        <strong>{{ item.value }}</strong>
        <span>{{ item.note }}</span>
      </article>
    </section>

    <section class="dashboard-grid">
      <article class="panel-card panel-dense">
        <div class="panel-head panel-head-tight">
          <div>
            <p class="eyebrow-dark">Kontrol harian</p>
            <h3>Operations panel</h3>
            <p class="panel-note">Main snapshot for {{ activeSnapshotLabel }}</p>
          </div>
          <span class="status-badge warning">{{ followUpCount }} folios need follow-up</span>
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
            <p class="eyebrow-dark">Komposisi pendapatan</p>
            <h3>Revenue snapshot</h3>
            <p class="panel-note">Monitor revenue contribution and the number of folios still open</p>
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
            <p class="panel-note">Arrivals included in the active period</p>
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
          <p v-if="!dashboard.arrivalWatch.length" class="subtle booking-addon-empty">There are no arrivals in this period.</p>
        </div>
      </article>

      <article class="panel-card panel-dense">
        <div class="panel-head panel-head-tight">
          <div>
            <p class="eyebrow-dark">Cashier queue</p>
            <h3>Outstanding folios</h3>
            <p class="panel-note">Settlements that still need follow-up</p>
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
          <p v-if="!dashboard.cashierQueue.length" class="subtle booking-addon-empty">All folios in this period are already settled.</p>
        </div>
      </article>
    </section>

    <section v-if="showAdvancedSections" class="page-grid two">
      <article class="panel-card panel-dense">
        <div class="panel-head panel-head-tight">
          <div>
            <p class="eyebrow-dark">Channel mix</p>
            <h3>Top booking sources</h3>
            <p class="panel-note">Top revenue sources in the active period</p>
          </div>
          <span class="status-badge info">{{ dashboard.channelPerformance.length }} source tracked</span>
        </div>

        <div class="table-scroll">
          <table v-smart-table class="data-table dashboard-table">
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
                <td colspan="4" class="table-empty-cell">There is no channel data for this period yet.</td>
              </tr>
              <tr v-for="item in dashboard.channelPerformance" :key="item.channel">
                <td><strong>{{ item.channel }}</strong></td>
                <td>{{ item.bookings }}</td>
                <td>{{ item.revenue }}</td>
                <td>{{ item.outstanding }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </article>

      <article class="panel-card panel-dense">
        <div class="panel-head panel-head-tight">
          <div>
            <p class="eyebrow-dark">Room type mix</p>
            <h3>Product performance</h3>
            <p class="panel-note">Room product and add-on performance</p>
          </div>
          <span class="status-badge success">{{ dashboard.roomTypePerformance.length }} type tracked</span>
        </div>

        <div class="table-scroll">
          <table v-smart-table class="data-table dashboard-table">
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
                <td colspan="5" class="table-empty-cell">There is no room type data for this period yet.</td>
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
        </div>
      </article>
    </section>

    <section class="page-grid two">
      <article class="panel-card panel-dense">
        <div class="panel-head panel-head-tight">
          <div>
            <p class="eyebrow-dark">Live movement</p>
            <h3>Guest activity board</h3>
            <p class="panel-note">In-house snapshot based on the active business date</p>
          </div>
          <span class="status-badge info">Database live</span>
        </div>

        <div class="table-scroll">
          <table v-smart-table class="data-table dashboard-table">
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
                <td colspan="5" class="table-empty-cell">There are no active in-house guests.</td>
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
        </div>
      </article>

      <article class="panel-card panel-dense">
        <div class="panel-head panel-head-tight">
          <div>
            <p class="eyebrow-dark">Department board</p>
            <h3>Execution notes</h3>
            <p class="panel-note">Short notes for follow-up by each department</p>
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
