<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '../services/api'

const loading = ref(false)
const bookingRows = ref([])
const transportRates = ref([])
const activityCatalog = ref({
  scooters: [],
  islandTours: [],
  boatTickets: [],
})

const toDateKey = (value) => String(value ?? '').slice(0, 10)

const loadServiceData = async () => {
  loading.value = true

  try {
    const [bookingRes, transportRes, catalogRes] = await Promise.all([
      api.get('/bookings', { params: { per_page: 500 } }),
      api.get('/transport-rates'),
      api.get('/activity-catalog'),
    ])

    bookingRows.value = Array.isArray(bookingRes.data?.data) ? bookingRes.data.data : []
    transportRates.value = Array.isArray(transportRes.data?.data) ? transportRes.data.data : []
    const catalog = catalogRes.data?.data ?? {}
    activityCatalog.value = {
      scooters: Array.isArray(catalog.scooters) ? catalog.scooters : [],
      islandTours: Array.isArray(catalog.islandTours) ? catalog.islandTours : [],
      boatTickets: Array.isArray(catalog.boatTickets) ? catalog.boatTickets : [],
    }
  } catch (error) {
    console.error('Failed to load service board:', error)
    bookingRows.value = []
    transportRates.value = []
    activityCatalog.value = { scooters: [], islandTours: [], boatTickets: [] }
  } finally {
    loading.value = false
  }
}

const serviceManifest = computed(() =>
  bookingRows.value
    .flatMap((booking) =>
      (Array.isArray(booking.addons) ? booking.addons : []).map((addon) => ({
        service: addon.addonLabel,
        ref: `${booking.code}-${addon.id}`,
        pax: `Qty ${addon.quantity}`,
        schedule: addon.serviceDateLabel ?? toDateKey(addon.serviceDate ?? addon.startDate),
        status: addon.status,
      })),
    )
    .sort((left, right) => String(left.schedule).localeCompare(String(right.schedule)))
    .slice(0, 12),
)

const addonCatalogCards = computed(() => [
  {
    name: 'Airport pickup / drop off',
    price: transportRates.value[0]?.pickupPrice ?? '-',
    schedule: `${transportRates.value.length} transport rate(s)`,
    note: 'Rate is pulled from the active transport master in the database.',
  },
  {
    name: 'Scooter rental',
    price: activityCatalog.value.scooters[0]?.customerPrice ?? '-',
    schedule: `${activityCatalog.value.scooters.length} scooter setup`,
    note: `Fee ${activityCatalog.value.scooters[0]?.fee ?? '-'}`,
  },
  {
    name: 'Island tour',
    price: activityCatalog.value.islandTours[0]?.customerPrice ?? '-',
    schedule: `${activityCatalog.value.islandTours.length} tour product`,
    note: `Fee ${activityCatalog.value.islandTours[0]?.fee ?? '-'}`,
  },
  {
    name: 'Boat ticket',
    price: activityCatalog.value.boatTickets[0]?.customerPrice ?? '-',
    schedule: `${activityCatalog.value.boatTickets.length} boat route`,
    note: `Fee ${activityCatalog.value.boatTickets[0]?.fee ?? '-'}`,
  },
])

onMounted(async () => {
  await loadServiceData()
})
</script>

<template>
  <section class="page-grid two">
    <article class="panel-card panel-dense">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Service board</p>
          <h3>Service manifest tied to reservation</h3>
        </div>
        <span class="status-badge success">High upsell potential</span>
      </div>

      <table v-smart-table class="data-table">
        <thead>
          <tr>
            <th>Service</th>
            <th>Reference</th>
            <th>Pax / unit</th>
            <th>Schedule</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in serviceManifest" :key="item.ref">
            <td><strong>{{ item.service }}</strong></td>
            <td>{{ item.ref }}</td>
            <td>{{ item.pax }}</td>
            <td>{{ item.schedule }}</td>
            <td>{{ item.status }}</td>
          </tr>
          <tr v-if="!serviceManifest.length && !loading">
            <td colspan="5" class="table-empty-cell">No active booking add-ons found in the database.</td>
          </tr>
        </tbody>
      </table>
    </article>

    <article class="panel-card panel-dense">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Add-on catalog</p>
          <h3>Operational detail to keep</h3>
        </div>
        <span class="status-badge info">Reservation linked</span>
      </div>

      <div class="addon-grid">
        <article v-for="item in addonCatalogCards" :key="item.name" class="addon-card">
          <p class="eyebrow-dark">{{ item.schedule }}</p>
          <strong>{{ item.name }}</strong>
          <p class="subtle">{{ item.price }}</p>
          <p class="subtle" style="margin-top: 8px;">{{ item.note }}</p>
        </article>
      </div>
    </article>
  </section>
</template>

