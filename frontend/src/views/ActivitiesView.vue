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

const scooterResult = ref({ tone: '', text: '' })
const operatorResult = ref({ tone: '', text: '' })
const islandTourResult = ref({ tone: '', text: '' })
const boatTicketResult = ref({ tone: '', text: '' })

const showScooterModal = ref(false)
const showOperatorModal = ref(false)
const showIslandTourModal = ref(false)
const showBoatTicketModal = ref(false)

const editingScooterId = ref('')
const editingOperatorId = ref('')
const editingIslandTourId = ref('')
const editingBoatTicketId = ref('')

const scooterRows = ref([])
const operatorRows = ref([])
const islandTourRows = ref([])
const boatTicketRows = ref([])
const transportRates = ref([])

const scooterForm = reactive({
  startDate: '',
  endDate: '',
  scooterType: '',
  vendor: '',
  priceValue: 0,
})

const operatorForm = reactive({
  operator: '',
  priceValue: 0,
  note: '',
})

const islandTourForm = reactive({
  destination: '',
  driver: '',
  costValue: 0,
  note: '',
})

const boatTicketForm = reactive({
  company: '',
  destination: '',
  priceValue: 0,
})

const activitySummary = computed(() => ({
  scooter: scooterRows.value.length,
  operators: operatorRows.value.length,
  tours: islandTourRows.value.length,
  tickets: boatTicketRows.value.length,
}))

const transportDriverOptions = computed(() => {
  const drivers = [...new Set(transportRates.value.map((item) => item.driver).filter(Boolean))]

  if (islandTourForm.driver && !drivers.includes(islandTourForm.driver)) {
    drivers.unshift(islandTourForm.driver)
  }

  return drivers.map((driver) => ({
    value: driver,
    label: driver,
  }))
})

const resetScooterForm = (clearResult = true) => {
  editingScooterId.value = ''
  scooterForm.startDate = ''
  scooterForm.endDate = ''
  scooterForm.scooterType = ''
  scooterForm.vendor = ''
  scooterForm.priceValue = 0

  if (clearResult) {
    scooterResult.value = { tone: '', text: '' }
  }
}

const resetOperatorForm = (clearResult = true) => {
  editingOperatorId.value = ''
  operatorForm.operator = ''
  operatorForm.priceValue = 0
  operatorForm.note = ''

  if (clearResult) {
    operatorResult.value = { tone: '', text: '' }
  }
}

const resetIslandTourForm = (clearResult = true) => {
  editingIslandTourId.value = ''
  islandTourForm.destination = ''
  islandTourForm.driver = ''
  islandTourForm.costValue = 0
  islandTourForm.note = ''

  if (clearResult) {
    islandTourResult.value = { tone: '', text: '' }
  }
}

const resetBoatTicketForm = (clearResult = true) => {
  editingBoatTicketId.value = ''
  boatTicketForm.company = ''
  boatTicketForm.destination = ''
  boatTicketForm.priceValue = 0

  if (clearResult) {
    boatTicketResult.value = { tone: '', text: '' }
  }
}

const closeScooterModal = (clearResult = true) => {
  showScooterModal.value = false
  resetScooterForm(clearResult)
}

const closeOperatorModal = (clearResult = true) => {
  showOperatorModal.value = false
  resetOperatorForm(clearResult)
}

const closeIslandTourModal = (clearResult = true) => {
  showIslandTourModal.value = false
  resetIslandTourForm(clearResult)
}

const closeBoatTicketModal = (clearResult = true) => {
  showBoatTicketModal.value = false
  resetBoatTicketForm(clearResult)
}

const editScooter = (item) => {
  editingScooterId.value = String(item.dbId)
  scooterForm.startDate = item.startDate
  scooterForm.endDate = item.endDate
  scooterForm.scooterType = item.scooterType
  scooterForm.vendor = item.vendor
  scooterForm.priceValue = item.priceValue
  scooterResult.value = { tone: '', text: '' }
  showScooterModal.value = true
}

const editOperator = (item) => {
  editingOperatorId.value = String(item.dbId)
  operatorForm.operator = item.operator
  operatorForm.priceValue = item.priceValue
  operatorForm.note = item.note
  operatorResult.value = { tone: '', text: '' }
  showOperatorModal.value = true
}

const editIslandTour = (item) => {
  editingIslandTourId.value = String(item.dbId)
  islandTourForm.destination = item.destination
  islandTourForm.driver = item.driver
  islandTourForm.costValue = item.costValue
  islandTourForm.note = item.note
  islandTourResult.value = { tone: '', text: '' }
  showIslandTourModal.value = true
}

const editBoatTicket = (item) => {
  editingBoatTicketId.value = String(item.dbId)
  boatTicketForm.company = item.company
  boatTicketForm.destination = item.destination
  boatTicketForm.priceValue = item.priceValue
  boatTicketResult.value = { tone: '', text: '' }
  showBoatTicketModal.value = true
}

const loadCatalog = async () => {
  const [catalogResponse, transportResponse] = await Promise.all([
    api.get('/activity-catalog'),
    api.get('/transport-rates'),
  ])

  const catalog = catalogResponse.data?.data ?? {}
  scooterRows.value = Array.isArray(catalog.scooters) ? catalog.scooters : []
  operatorRows.value = Array.isArray(catalog.operators) ? catalog.operators : []
  islandTourRows.value = Array.isArray(catalog.islandTours) ? catalog.islandTours : []
  boatTicketRows.value = Array.isArray(catalog.boatTickets) ? catalog.boatTickets : []
  transportRates.value = Array.isArray(transportResponse.data?.data) ? transportResponse.data.data : []
  hotel.setActivityCatalog({
    scooters: scooterRows.value,
    operators: operatorRows.value,
    islandTours: islandTourRows.value,
    boatTickets: boatTicketRows.value,
  })
  hotel.setTransportRates(transportRates.value)
}

const submitScooter = async () => {
  scooterResult.value = { tone: '', text: '' }

  try {
    const response = editingScooterId.value
      ? await api.put(`/activity-catalog/scooters/${editingScooterId.value}`, scooterForm)
      : await api.post('/activity-catalog/scooters', scooterForm)
    scooterResult.value = { tone: 'success', text: response.data?.message || 'Data scooter berhasil disimpan.' }
    await loadCatalog()
    closeScooterModal(false)
  } catch (error) {
    scooterResult.value = {
      tone: 'error',
      text: error?.response?.data?.message || (error instanceof Error ? error.message : 'Gagal menyimpan data scooter.'),
    }
  }
}

const submitOperator = async () => {
  operatorResult.value = { tone: '', text: '' }

  try {
    const response = editingOperatorId.value
      ? await api.put(`/activity-catalog/operators/${editingOperatorId.value}`, operatorForm)
      : await api.post('/activity-catalog/operators', operatorForm)
    operatorResult.value = { tone: 'success', text: response.data?.message || 'Data operator berhasil disimpan.' }
    await loadCatalog()
    closeOperatorModal(false)
  } catch (error) {
    operatorResult.value = {
      tone: 'error',
      text: error?.response?.data?.message || (error instanceof Error ? error.message : 'Gagal menyimpan data operator.'),
    }
  }
}

const submitIslandTour = async () => {
  islandTourResult.value = { tone: '', text: '' }

  try {
    const response = editingIslandTourId.value
      ? await api.put(`/activity-catalog/island-tours/${editingIslandTourId.value}`, islandTourForm)
      : await api.post('/activity-catalog/island-tours', islandTourForm)
    islandTourResult.value = { tone: 'success', text: response.data?.message || 'Data island tour berhasil disimpan.' }
    await loadCatalog()
    closeIslandTourModal(false)
  } catch (error) {
    islandTourResult.value = {
      tone: 'error',
      text: error?.response?.data?.message || (error instanceof Error ? error.message : 'Gagal menyimpan data island tour.'),
    }
  }
}

const submitBoatTicket = async () => {
  boatTicketResult.value = { tone: '', text: '' }

  try {
    const response = editingBoatTicketId.value
      ? await api.put(`/activity-catalog/boat-tickets/${editingBoatTicketId.value}`, boatTicketForm)
      : await api.post('/activity-catalog/boat-tickets', boatTicketForm)
    boatTicketResult.value = { tone: 'success', text: response.data?.message || 'Data boat ticket berhasil disimpan.' }
    await loadCatalog()
    closeBoatTicketModal(false)
  } catch (error) {
    boatTicketResult.value = {
      tone: 'error',
      text: error?.response?.data?.message || (error instanceof Error ? error.message : 'Gagal menyimpan data boat ticket.'),
    }
  }
}

onMounted(async () => {
  await loadCatalog()
})
</script>

<template>
  <section class="summary-strip">
    <article class="summary-box accent">
      <p class="summary-label">Scooter</p>
      <strong>{{ activitySummary.scooter }}</strong>
      <span>Rental periods active</span>
    </article>
    <article class="summary-box">
      <p class="summary-label">Operators</p>
      <strong>{{ activitySummary.operators }}</strong>
      <span>Vendor and operator list</span>
    </article>
    <article class="summary-box">
      <p class="summary-label">Island Tour</p>
      <strong>{{ activitySummary.tours }}</strong>
      <span>Destination setup</span>
    </article>
    <article class="summary-box">
      <p class="summary-label">Boat Ticket</p>
      <strong>{{ activitySummary.tickets }}</strong>
      <span>Company and route pricing</span>
    </article>
  </section>

  <section class="page-grid two">
    <article class="panel-card panel-dense">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Scooter</p>
          <h3>Rental setup</h3>
        </div>
        <button class="action-button primary" @click="showScooterModal = true">Tambah scooter</button>
      </div>

      <div v-if="scooterResult.text" class="booking-feedback" :class="scooterResult.tone">
        {{ scooterResult.text }}
      </div>

      <table class="data-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Tanggal awal</th>
            <th>Tanggal akhir</th>
            <th>Tipe scooter</th>
            <th>Vendor</th>
            <th>Harga</th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in scooterRows" :key="item.id">
            <td><strong>{{ item.id }}</strong></td>
            <td>{{ item.startDate }}</td>
            <td>{{ item.endDate }}</td>
            <td>{{ item.scooterType }}</td>
            <td>{{ item.vendor }}</td>
            <td>{{ item.price }}</td>
            <td><button class="action-button" @click="editScooter(item)">Edit</button></td>
          </tr>
        </tbody>
      </table>
    </article>

    <article class="panel-card panel-dense">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Operator</p>
          <h3>Vendor and rate</h3>
        </div>
        <button class="action-button primary" @click="showOperatorModal = true">Tambah operator</button>
      </div>

      <div v-if="operatorResult.text" class="booking-feedback" :class="operatorResult.tone">
        {{ operatorResult.text }}
      </div>

      <table class="data-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Operator</th>
            <th>Harga</th>
            <th>Informasi</th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in operatorRows" :key="item.id">
            <td><strong>{{ item.id }}</strong></td>
            <td>{{ item.operator }}</td>
            <td>{{ item.price }}</td>
            <td>{{ item.note }}</td>
            <td><button class="action-button" @click="editOperator(item)">Edit</button></td>
          </tr>
        </tbody>
      </table>
    </article>
  </section>

  <section class="page-grid two">
    <article class="panel-card panel-dense">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Island Tour</p>
          <h3>Destination and driver</h3>
        </div>
        <button class="action-button primary" @click="showIslandTourModal = true">Tambah island tour</button>
      </div>

      <div v-if="islandTourResult.text" class="booking-feedback" :class="islandTourResult.tone">
        {{ islandTourResult.text }}
      </div>

      <table class="data-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Destinasi</th>
            <th>Driver</th>
            <th>Biaya</th>
            <th>Catatan</th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in islandTourRows" :key="item.id">
            <td><strong>{{ item.id }}</strong></td>
            <td>{{ item.destination }}</td>
            <td>{{ item.driver }}</td>
            <td>{{ item.cost }}</td>
            <td>{{ item.note }}</td>
            <td><button class="action-button" @click="editIslandTour(item)">Edit</button></td>
          </tr>
        </tbody>
      </table>
    </article>

    <article class="panel-card panel-dense">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Boat Ticket</p>
          <h3>Company, destination, and price</h3>
        </div>
        <button class="action-button primary" @click="showBoatTicketModal = true">Tambah boat ticket</button>
      </div>

      <div v-if="boatTicketResult.text" class="booking-feedback" :class="boatTicketResult.tone">
        {{ boatTicketResult.text }}
      </div>

      <table class="data-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Company</th>
            <th>Destination</th>
            <th>Harga ticket</th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in boatTicketRows" :key="item.id">
            <td><strong>{{ item.id }}</strong></td>
            <td>{{ item.company }}</td>
            <td>{{ item.destination }}</td>
            <td>{{ item.price }}</td>
            <td><button class="action-button" @click="editBoatTicket(item)">Edit</button></td>
          </tr>
        </tbody>
      </table>
    </article>
  </section>

  <div v-if="showScooterModal" class="modal-backdrop" @click.self="closeScooterModal()">
    <section class="modal-card">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Scooter</p>
          <h3>{{ editingScooterId ? `Edit ${editingScooterId}` : 'Tambah scooter' }}</h3>
        </div>
        <button class="action-button" @click="closeScooterModal()">Close</button>
      </div>

      <div class="booking-form-grid">
        <label class="field-stack">
          <span>Tanggal awal</span>
          <input v-model="scooterForm.startDate" class="form-control" type="date" />
        </label>
        <label class="field-stack">
          <span>Tanggal akhir</span>
          <input v-model="scooterForm.endDate" class="form-control" type="date" />
        </label>
        <label class="field-stack">
          <span>Tipe scooter</span>
          <input v-model="scooterForm.scooterType" class="form-control" placeholder="Vario, NMAX, dll" />
        </label>
        <label class="field-stack">
          <span>Operator / vendor</span>
          <input v-model="scooterForm.vendor" class="form-control" placeholder="Nama vendor scooter" />
        </label>
        <label class="field-stack field-span-2">
          <span>Harga</span>
          <input v-model="scooterForm.priceValue" class="form-control" type="number" min="0" step="1000" />
        </label>
      </div>

      <div class="booking-inline-summary">
        <div class="note-cell">
          <strong>Pricing check</strong>
          <p class="subtle">{{ formatCurrency(scooterForm.priceValue) }}</p>
        </div>
      </div>

      <div class="modal-actions">
        <button class="action-button" @click="closeScooterModal()">Cancel</button>
        <button class="action-button primary" @click="submitScooter">Save scooter</button>
      </div>
    </section>
  </div>

  <div v-if="showOperatorModal" class="modal-backdrop" @click.self="closeOperatorModal()">
    <section class="modal-card">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Operator</p>
          <h3>{{ editingOperatorId ? `Edit ${editingOperatorId}` : 'Tambah operator' }}</h3>
        </div>
        <button class="action-button" @click="closeOperatorModal()">Close</button>
      </div>

      <div class="booking-form-grid">
        <label class="field-stack">
          <span>Operator</span>
          <input v-model="operatorForm.operator" class="form-control" placeholder="Nama operator" />
        </label>
        <label class="field-stack">
          <span>Harga</span>
          <input v-model="operatorForm.priceValue" class="form-control" type="number" min="0" step="1000" />
        </label>
        <label class="field-stack field-span-2">
          <span>Informasi</span>
          <textarea v-model="operatorForm.note" class="form-control form-textarea" placeholder="Info operator"></textarea>
        </label>
      </div>

      <div class="modal-actions">
        <button class="action-button" @click="closeOperatorModal()">Cancel</button>
        <button class="action-button primary" @click="submitOperator">Save operator</button>
      </div>
    </section>
  </div>

  <div v-if="showIslandTourModal" class="modal-backdrop" @click.self="closeIslandTourModal()">
    <section class="modal-card">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Island tour</p>
          <h3>{{ editingIslandTourId ? `Edit ${editingIslandTourId}` : 'Tambah island tour' }}</h3>
        </div>
        <button class="action-button" @click="closeIslandTourModal()">Close</button>
      </div>

      <div class="booking-form-grid">
        <label class="field-stack">
          <span>Destinasi</span>
          <input v-model="islandTourForm.destination" class="form-control" placeholder="Nama destinasi" />
        </label>
        <label class="field-stack">
          <span>Driver</span>
          <Select2Field
            v-model="islandTourForm.driver"
            :options="transportDriverOptions"
            :multiple="false"
            placeholder="Pilih driver dari transport"
          />
        </label>
        <label class="field-stack">
          <span>Biaya</span>
          <input v-model="islandTourForm.costValue" class="form-control" type="number" min="0" step="1000" />
        </label>
        <label class="field-stack field-span-2">
          <span>Catatan</span>
          <textarea v-model="islandTourForm.note" class="form-control form-textarea" placeholder="Info tour"></textarea>
        </label>
      </div>

      <div class="modal-actions">
        <button class="action-button" @click="closeIslandTourModal()">Cancel</button>
        <button class="action-button primary" @click="submitIslandTour">Save island tour</button>
      </div>
    </section>
  </div>

  <div v-if="showBoatTicketModal" class="modal-backdrop" @click.self="closeBoatTicketModal()">
    <section class="modal-card">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Boat ticket</p>
          <h3>{{ editingBoatTicketId ? `Edit ${editingBoatTicketId}` : 'Tambah boat ticket' }}</h3>
        </div>
        <button class="action-button" @click="closeBoatTicketModal()">Close</button>
      </div>

      <div class="booking-form-grid">
        <label class="field-stack">
          <span>Company</span>
          <input v-model="boatTicketForm.company" class="form-control" placeholder="Nama company" />
        </label>
        <label class="field-stack">
          <span>Destination</span>
          <input v-model="boatTicketForm.destination" class="form-control" placeholder="Tujuan ticket" />
        </label>
        <label class="field-stack field-span-2">
          <span>Harga ticket</span>
          <input v-model="boatTicketForm.priceValue" class="form-control" type="number" min="0" step="1000" />
        </label>
      </div>

      <div class="modal-actions">
        <button class="action-button" @click="closeBoatTicketModal()">Cancel</button>
        <button class="action-button primary" @click="submitBoatTicket">Save boat ticket</button>
      </div>
    </section>
  </div>
</template>
