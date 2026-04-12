<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import api from '../services/api'
import { useHotelStore } from '../stores/hotel'

const hotel = useHotelStore()

const transportSearch = ref('')
const transportResult = ref({ tone: '', text: '' })
const editingTransportId = ref('')
const showTransportModal = ref(false)
const transportRates = ref([])
const transportManifest = ref([])

const transportForm = reactive({
  driver: '',
  pickupPriceValue: 250000,
  dropOffPriceValue: 250000,
  vehicle: '',
  note: '',
})

const filteredTransportRates = computed(() => {
  const query = transportSearch.value.trim().toLowerCase()

  return transportRates.value.filter((item) => {
    const haystack = [item.id, item.driver, item.vehicle, item.pickupPrice, item.dropOffPrice, item.note]
      .join(' ')
      .toLowerCase()

    return !query || haystack.includes(query)
  })
})

const transportSummary = computed(() => ({
  drivers: transportRates.value.length,
  avgPickup: transportRates.value.length
    ? new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 })
      .format(transportRates.value.reduce((total, item) => total + item.pickupPriceValue, 0) / transportRates.value.length)
    : '-',
  avgDropOff: transportRates.value.length
    ? new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 })
      .format(transportRates.value.reduce((total, item) => total + item.dropOffPriceValue, 0) / transportRates.value.length)
    : '-',
}))

const resetTransportForm = (clearResult = true) => {
  editingTransportId.value = ''
  transportForm.driver = ''
  transportForm.pickupPriceValue = 250000
  transportForm.dropOffPriceValue = 250000
  transportForm.vehicle = ''
  transportForm.note = ''

  if (clearResult) {
    transportResult.value = { tone: '', text: '' }
  }
}

const closeTransportModal = (clearResult = true) => {
  showTransportModal.value = false
  resetTransportForm(clearResult)
}

const openCreateTransportModal = () => {
  resetTransportForm()
  showTransportModal.value = true
}

const editTransport = (transport) => {
  editingTransportId.value = String(transport.dbId)
  transportForm.driver = transport.driver
  transportForm.pickupPriceValue = transport.pickupPriceValue
  transportForm.dropOffPriceValue = transport.dropOffPriceValue
  transportForm.vehicle = transport.vehicle
  transportForm.note = transport.note
  transportResult.value = { tone: '', text: '' }
  showTransportModal.value = true
}

const loadTransportRates = async () => {
  try {
    const [response, bookingResponse] = await Promise.all([
      api.get('/transport-rates'),
      api.get('/bookings', { params: { per_page: 500 } }),
    ])
    transportRates.value = Array.isArray(response.data?.data) ? response.data.data : []
    hotel.setTransportRates(transportRates.value)
    const bookingRows = Array.isArray(bookingResponse.data?.data) ? bookingResponse.data.data : []
    transportManifest.value = bookingRows
      .flatMap((booking) =>
        (Array.isArray(booking.addons) ? booking.addons : [])
          .filter((addon) => addon.addonType === 'transport')
          .map((addon) => ({
            ref: `${booking.code}-${addon.id}`,
            schedule: addon.serviceDateLabel ?? addon.serviceDate ?? '',
            service: addon.addonLabel,
            pax: `Qty ${addon.quantity}`,
            status: addon.status,
          })),
      )
      .slice(0, 8)
  } catch (error) {
    transportResult.value = {
      tone: 'error',
      text: error?.response?.data?.message || error?.message || 'Failed to load transport data.',
    }
    transportRates.value = []
    transportManifest.value = []
    hotel.setTransportRates([])
  }
}

const submitTransport = async () => {
  transportResult.value = { tone: '', text: '' }

  try {
    const payload = {
      driver: transportForm.driver,
      pickupPriceValue: Number(transportForm.pickupPriceValue),
      dropOffPriceValue: Number(transportForm.dropOffPriceValue),
      vehicle: transportForm.vehicle,
      note: transportForm.note,
    }
    const response = editingTransportId.value
      ? await api.put(`/transport-rates/${editingTransportId.value}`, payload)
      : await api.post('/transport-rates', payload)

    transportResult.value = {
      tone: 'success',
      text: response.data?.message || 'Transport data saved successfully.',
    }

    await loadTransportRates()
    closeTransportModal(false)
  } catch (error) {
    transportResult.value = {
      tone: 'error',
      text: error?.response?.data?.message || (error instanceof Error ? error.message : 'Failed to save transport data.'),
    }
  }
}

onMounted(async () => {
  await loadTransportRates()
})
</script>

<template>
  <section class="panel-card panel-dense">
    <div class="panel-head panel-head-tight">
      <div>
        <p class="eyebrow-dark">Transport desk</p>
        <h3>Pickup and drop-off drivers</h3>
      </div>
      <div class="kpi-inline">
        <span>{{ transportSummary.drivers }} driver</span>
        <span>Avg pickup {{ transportSummary.avgPickup }}</span>
        <span>Avg drop off {{ transportSummary.avgDropOff }}</span>
      </div>
    </div>

    <div class="table-toolbar">
      <input
        v-model="transportSearch"
        class="toolbar-search"
        placeholder="Search driver / vehicle / rate"
      />
      <button class="action-button primary" @click="openCreateTransportModal">
        Add transport driver
      </button>
    </div>

    <div v-if="transportResult.text" class="booking-feedback" :class="transportResult.tone">
      {{ transportResult.text }}
    </div>

    <table v-smart-table class="data-table">
      <thead>
        <tr>
          <th>ID</th>
          <th>Driver</th>
          <th>Vehicle</th>
          <th>Pickup</th>
          <th>Drop off</th>
          <th>Notes</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="item in filteredTransportRates" :key="item.id">
          <td><strong>{{ item.id }}</strong></td>
          <td>{{ item.driver }}</td>
          <td>{{ item.vehicle }}</td>
          <td>{{ item.pickupPrice }}</td>
          <td>{{ item.dropOffPrice }}</td>
          <td>{{ item.note }}</td>
          <td>
            <button class="action-button" @click="editTransport(item)">Edit</button>
          </td>
        </tr>
      </tbody>
    </table>
  </section>

  <section class="page-grid two">
    <article class="panel-card panel-dense">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Operational note</p>
          <h3>Transport pricing rule</h3>
        </div>
        <span class="status-badge info">Front office</span>
      </div>

      <div class="compact-list">
        <div class="list-row list-row-tight">
          <strong>Pickup</strong>
          <p class="subtle">Use the pickup rate for guest collection from the airport, harbour, or meeting point to the property.</p>
        </div>
        <div class="list-row list-row-tight">
          <strong>Drop off</strong>
          <p class="subtle">Use the drop-off rate for guest transfers from the property to the airport, harbour, or other departure points.</p>
        </div>
        <div class="list-row list-row-tight">
          <strong>Driver assignment</strong>
          <p class="subtle">Front office can use this list to choose active drivers for guest transfers and post charges to the folio.</p>
        </div>
      </div>
    </article>

    <article class="panel-card panel-dense">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Live manifest</p>
          <h3>Transport related services</h3>
        </div>
        <span class="status-badge success">Live queue</span>
      </div>

      <div class="compact-list">
        <div
          v-for="item in transportManifest"
          :key="item.ref"
          class="list-row list-row-tight"
        >
          <div class="split-row">
            <strong>{{ item.ref }}</strong>
            <span>{{ item.schedule }}</span>
          </div>
          <p class="subtle">{{ item.service }} | {{ item.pax }} | {{ item.status }}</p>
        </div>
        <p v-if="!transportManifest.length" class="subtle booking-addon-empty">
          No active transport add-ons found in the database.
        </p>
      </div>
    </article>
  </section>

  <div v-if="showTransportModal" class="modal-backdrop" @click.self="closeTransportModal()">
    <section class="modal-card">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Transport setup</p>
          <h3>{{ editingTransportId ? `Edit driver ${editingTransportId}` : 'Add transport driver' }}</h3>
        </div>
        <button class="action-button" @click="closeTransportModal()">Close</button>
      </div>

      <div class="booking-form-grid">
        <label class="field-stack">
          <span>Driver</span>
          <input v-model="transportForm.driver" class="form-control" placeholder="Example: Made Ariana" />
        </label>

        <label class="field-stack">
          <span>Vehicle</span>
          <input v-model="transportForm.vehicle" class="form-control" placeholder="Example: Toyota Avanza" />
        </label>

        <label class="field-stack">
          <span>Pickup rate</span>
          <input v-model="transportForm.pickupPriceValue" class="form-control" type="number" min="0" step="1000" />
        </label>

        <label class="field-stack">
          <span>Drop-off rate</span>
          <input v-model="transportForm.dropOffPriceValue" class="form-control" type="number" min="0" step="1000" />
        </label>

        <label class="field-stack field-span-2">
          <span>Notes</span>
          <textarea v-model="transportForm.note" class="form-control form-textarea" placeholder="Driver notes, service area, or operating hours"></textarea>
        </label>
      </div>

      <div class="booking-inline-summary">
        <div class="note-cell">
          <strong>Entry rule</strong>
          <p class="subtle">Enter the assigned driver, then set pickup and drop-off rates so front office can post transport charges quickly.</p>
        </div>
        <div class="note-cell">
          <strong>Pricing check</strong>
          <p class="subtle">
            Pickup: {{ new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(Number(transportForm.pickupPriceValue || 0)) }}
            |
            Drop off: {{ new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(Number(transportForm.dropOffPriceValue || 0)) }}
          </p>
        </div>
      </div>

      <div v-if="transportResult.text" class="booking-feedback" :class="transportResult.tone">
        {{ transportResult.text }}
      </div>

      <div class="modal-actions">
        <button class="action-button" @click="closeTransportModal()">Cancel</button>
        <button class="action-button primary" @click="submitTransport">
          {{ editingTransportId ? 'Update driver' : 'Add driver' }}
        </button>
      </div>
    </section>
  </div>
</template>
