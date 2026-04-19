<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import Select2Field from '../components/Select2Field.vue'
import api from '../services/api'
import { useHotelStore } from '../stores/hotel'

const hotel = useHotelStore()

const formatCurrency = (amount) =>
  new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
  }).format(Number(amount || 0))

const transportSearch = ref('')
const transportResult = ref({ tone: '', text: '' })
const editingTransportId = ref('')
const showTransportModal = ref(false)
const transportRates = ref([])
const transportManifest = ref([])
const activityVendors = ref([])
const coaAccounts = ref([])

const transportForm = reactive({
  driver: '',
  vendorPickupPriceValue: 200000,
  vendorDropOffPriceValue: 200000,
  customerPickupPriceValue: 250000,
  customerDropOffPriceValue: 250000,
  vehicle: '',
  vendorId: '',
  feeCoaCode: '',
  payableCoaCode: '',
  note: '',
})

const transportVendorOptions = computed(() => {
  const rows = activityVendors.value.filter((item) => item.isActive)
  return rows.map((item) => ({
    value: String(item.id),
    label: `${item.vendorName}${item.contactPerson ? ` | ${item.contactPerson}` : ''}`,
  }))
})

const payableCoaOptions = computed(() =>
  coaAccounts.value
    .filter((item) => String(item.category).toLowerCase() === 'liability' && item.active !== false)
    .map((item) => ({ value: item.code, label: `${item.code} - ${item.name}` })),
)
const revenueCoaOptions = computed(() =>
  coaAccounts.value
    .filter((item) => String(item.category).toLowerCase() === 'revenue' && item.active !== false)
    .map((item) => ({ value: item.code, label: `${item.code} - ${item.name}` })),
)

const filteredTransportRates = computed(() => {
  const query = transportSearch.value.trim().toLowerCase()

  return transportRates.value.filter((item) => {
    const haystack = [
      item.id,
      item.driver,
      item.vehicle,
      item.vendorPickupPrice,
      item.vendorDropOffPrice,
      item.customerPickupPrice,
      item.customerDropOffPrice,
      item.note,
    ]
      .join(' ')
      .toLowerCase()

    return !query || haystack.includes(query)
  })
})

const transportSummary = computed(() => ({
  drivers: transportRates.value.length,
  avgPickup: transportRates.value.length
    ? formatCurrency(transportRates.value.reduce((total, item) => total + Number(item.customerPickupPriceValue || 0), 0) / transportRates.value.length)
    : '-',
  avgDropOff: transportRates.value.length
    ? formatCurrency(transportRates.value.reduce((total, item) => total + Number(item.customerDropOffPriceValue || 0), 0) / transportRates.value.length)
    : '-',
}))

const resetTransportForm = (clearResult = true) => {
  editingTransportId.value = ''
  transportForm.driver = ''
  transportForm.vendorPickupPriceValue = 200000
  transportForm.vendorDropOffPriceValue = 200000
  transportForm.customerPickupPriceValue = 250000
  transportForm.customerDropOffPriceValue = 250000
  transportForm.vehicle = ''
  transportForm.vendorId = ''
  transportForm.feeCoaCode = ''
  transportForm.payableCoaCode = ''
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
  transportForm.vendorPickupPriceValue = Number(transport.vendorPickupPriceValue ?? transport.pickupPriceValue ?? 0)
  transportForm.vendorDropOffPriceValue = Number(transport.vendorDropOffPriceValue ?? transport.dropOffPriceValue ?? 0)
  transportForm.customerPickupPriceValue = Number(transport.customerPickupPriceValue ?? transport.pickupPriceValue ?? 0)
  transportForm.customerDropOffPriceValue = Number(transport.customerDropOffPriceValue ?? transport.dropOffPriceValue ?? 0)
  transportForm.vehicle = transport.vehicle
  transportForm.vendorId = transport.vendorId ? String(transport.vendorId) : ''
  transportForm.feeCoaCode = transport.feeCoaCode || transport.expenseCoaCode || ''
  transportForm.payableCoaCode = transport.payableCoaCode || ''
  transportForm.note = transport.note
  transportResult.value = { tone: '', text: '' }
  showTransportModal.value = true
}

const loadTransportRates = async () => {
  try {
    const [response, bookingResponse, vendorResponse, coaResponse] = await Promise.all([
      api.get('/transport-rates'),
      api.get('/bookings', { params: { per_page: 500 } }),
      api.get('/vendors'),
      api.get('/coa-accounts', { params: { per_page: 500 } }),
    ])
    transportRates.value = Array.isArray(response.data?.data) ? response.data.data : []
    activityVendors.value = Array.isArray(vendorResponse.data?.data) ? vendorResponse.data.data : []
    coaAccounts.value = Array.isArray(coaResponse.data?.data) ? coaResponse.data.data : []
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
      vendorPickupPriceValue: Number(transportForm.vendorPickupPriceValue),
      vendorDropOffPriceValue: Number(transportForm.vendorDropOffPriceValue),
      customerPickupPriceValue: Number(transportForm.customerPickupPriceValue),
      customerDropOffPriceValue: Number(transportForm.customerDropOffPriceValue),
      vehicle: transportForm.vehicle,
      vendorId: transportForm.vendorId ? Number(transportForm.vendorId) : 0,
      feeCoaCode: transportForm.feeCoaCode,
      payableCoaCode: transportForm.payableCoaCode,
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
          <th>Harga Vendor</th>
          <th>Harga Customer</th>
          <th>Fee COA</th>
          <th>Hutang COA</th>
          <th>Notes</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="item in filteredTransportRates" :key="item.id">
          <td><strong>{{ item.id }}</strong></td>
          <td>{{ item.driver }}</td>
          <td>{{ item.vehicle }}</td>
          <td>
            <div>Pickup {{ item.vendorPickupPrice }}</div>
            <div class="subtle">Drop off {{ item.vendorDropOffPrice }}</div>
          </td>
          <td>
            <div>Pickup {{ item.customerPickupPrice }}</div>
            <div class="subtle">Drop off {{ item.customerDropOffPrice }}</div>
          </td>
          <td>{{ item.feeCoaCode || '-' }}</td>
          <td>{{ item.payableCoaCode || '-' }}</td>
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
          <strong>Harga Vendor</strong>
          <p class="subtle">Isi rate pickup dan drop off yang dibayar ke vendor atau driver transport.</p>
        </div>
        <div class="list-row list-row-tight">
          <strong>Harga Customer</strong>
          <p class="subtle">Isi rate pickup dan drop off yang ditagihkan ke tamu untuk add-on transport.</p>
        </div>
        <div class="list-row list-row-tight">
          <strong>COA Posting</strong>
          <p class="subtle">Fee COA dipakai untuk margin transport, sedangkan Hutang COA dipakai saat biaya vendor dibukukan.</p>
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
          <span>Vendor</span>
          <Select2Field
            v-model="transportForm.vendorId"
            :options="transportVendorOptions"
            :multiple="false"
            placeholder="Select transport vendor"
          />
        </label>

        <div class="field-span-2 transport-rate-section">
          <div class="transport-rate-card">
            <p class="eyebrow-dark">Harga Vendor</p>
            <div class="transport-rate-grid">
              <label class="field-stack">
                <span>Pickup rate</span>
                <input v-model="transportForm.vendorPickupPriceValue" class="form-control" type="number" min="0" step="1000" />
              </label>
              <label class="field-stack">
                <span>Drop off rate</span>
                <input v-model="transportForm.vendorDropOffPriceValue" class="form-control" type="number" min="0" step="1000" />
              </label>
            </div>
          </div>

          <div class="transport-rate-card">
            <p class="eyebrow-dark">Harga Customer</p>
            <div class="transport-rate-grid">
              <label class="field-stack">
                <span>Pickup rate</span>
                <input v-model="transportForm.customerPickupPriceValue" class="form-control" type="number" min="0" step="1000" />
              </label>
              <label class="field-stack">
                <span>Drop off rate</span>
                <input v-model="transportForm.customerDropOffPriceValue" class="form-control" type="number" min="0" step="1000" />
              </label>
            </div>
          </div>
        </div>

        <label class="field-stack">
          <span>Hutang COA</span>
          <Select2Field
            v-model="transportForm.payableCoaCode"
            :options="payableCoaOptions"
            :multiple="false"
            placeholder="Select hutang COA"
          />
        </label>

        <label class="field-stack">
          <span>Fee COA</span>
          <Select2Field
            v-model="transportForm.feeCoaCode"
            :options="revenueCoaOptions"
            :multiple="false"
            placeholder="Select fee COA"
          />
        </label>

        <label class="field-stack field-span-2">
          <span>Notes</span>
          <textarea v-model="transportForm.note" class="form-control form-textarea" placeholder="Driver notes, service area, or operating hours"></textarea>
        </label>
      </div>

      <div class="booking-inline-summary">
        <div class="note-cell">
          <strong>Entry rule</strong>
          <p class="subtle">Isi harga vendor dan harga customer untuk pickup serta drop off agar FO dan finance memakai rate yang sama.</p>
        </div>
        <div class="note-cell">
          <strong>Pricing check</strong>
          <p class="subtle">
            Fee pickup: {{ formatCurrency(Number(transportForm.customerPickupPriceValue || 0) - Number(transportForm.vendorPickupPriceValue || 0)) }}
            |
            Fee drop off: {{ formatCurrency(Number(transportForm.customerDropOffPriceValue || 0) - Number(transportForm.vendorDropOffPriceValue || 0)) }}
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

<style scoped>
.transport-rate-section {
  display: grid;
  gap: 1rem;
}

.transport-rate-card {
  border: 1px solid var(--line);
  border-radius: 14px;
  padding: 1rem;
  background: rgba(255, 255, 255, 0.72);
}

.transport-rate-grid {
  display: grid;
  gap: 1rem;
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

@media (max-width: 720px) {
  .transport-rate-grid {
    grid-template-columns: 1fr;
  }
}
</style>
