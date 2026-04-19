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

const extractApiError = (error, fallback) => {
  const responseErrors = error?.response?.data?.errors

  if (responseErrors && typeof responseErrors === 'object') {
    const firstError = Object.values(responseErrors).flat().find(Boolean)
    if (firstError) {
      return firstError
    }
  }

  return error?.response?.data?.message || (error instanceof Error ? error.message : fallback)
}

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
const activityVendors = ref([])
const coaAccounts = ref([])

const scooterForm = reactive({
  scooterType: '',
  vendorId: '',
  vendorPriceValue: 0,
  customerPriceValue: 0,
  payableCoaCode: '',
  feeCoaCode: '',
})

const operatorForm = reactive({
  operator: '',
  vendorId: '',
  vendorPriceValue: 0,
  customerPriceValue: 0,
  payableCoaCode: '',
  feeCoaCode: '',
  note: '',
})

const islandTourForm = reactive({
  destination: '',
  vendorId: '',
  driver: '',
  vendorPriceValue: 0,
  customerPriceValue: 0,
  payableCoaCode: '',
  feeCoaCode: '',
  note: '',
})

const boatTicketForm = reactive({
  company: '',
  vendorId: '',
  destination: '',
  vendorPriceValue: 0,
  customerPriceValue: 0,
  payableCoaCode: '',
  feeCoaCode: '',
})

const activitySummary = computed(() => ({
  scooter: scooterRows.value.length,
  operators: operatorRows.value.length,
  tours: islandTourRows.value.length,
  tickets: boatTicketRows.value.length,
}))

const scooterVendorOptions = computed(() => {
  const rows = activityVendors.value.filter((item) => item.isActive)
  const options = rows.map((item) => ({
    value: String(item.id),
    label: `${item.vendorName}${item.contactPerson ? ` | ${item.contactPerson}` : ''}`,
  }))

  if (scooterForm.vendorId && !options.some((item) => item.value === String(scooterForm.vendorId))) {
    const currentVendor = activityVendors.value.find((item) => String(item.id) === String(scooterForm.vendorId))
    options.unshift({
      value: String(scooterForm.vendorId),
      label: currentVendor?.vendorName || 'Selected vendor',
    })
  }

  return options
})

const operatorVendorOptions = computed(() => {
  const rows = activityVendors.value.filter((item) => item.isActive)
  const options = rows.map((item) => ({
    value: String(item.id),
    label: `${item.vendorName}${item.contactPerson ? ` | ${item.contactPerson}` : ''}`,
  }))

  if (operatorForm.vendorId && !options.some((item) => item.value === String(operatorForm.vendorId))) {
    const currentVendor = activityVendors.value.find((item) => String(item.id) === String(operatorForm.vendorId))
    options.unshift({
      value: String(operatorForm.vendorId),
      label: currentVendor?.vendorName || 'Selected vendor',
    })
  }

  return options
})

const islandTourVendorOptions = computed(() => {
  const rows = activityVendors.value.filter((item) => item.isActive)
  const options = rows.map((item) => ({
    value: String(item.id),
    label: `${item.vendorName}${item.contactPerson ? ` | ${item.contactPerson}` : ''}`,
  }))

  if (islandTourForm.vendorId && !options.some((item) => item.value === String(islandTourForm.vendorId))) {
    const currentVendor = activityVendors.value.find((item) => String(item.id) === String(islandTourForm.vendorId))
    options.unshift({
      value: String(islandTourForm.vendorId),
      label: currentVendor?.vendorName || 'Selected vendor',
    })
  }

  return options
})

const boatTicketVendorOptions = computed(() => {
  const rows = activityVendors.value.filter((item) => item.isActive)
  const options = rows.map((item) => ({
    value: String(item.id),
    label: `${item.vendorName}${item.contactPerson ? ` | ${item.contactPerson}` : ''}`,
  }))

  if (boatTicketForm.vendorId && !options.some((item) => item.value === String(boatTicketForm.vendorId))) {
    const currentVendor = activityVendors.value.find((item) => String(item.id) === String(boatTicketForm.vendorId))
    options.unshift({
      value: String(boatTicketForm.vendorId),
      label: currentVendor?.vendorName || 'Selected vendor',
    })
  }

  return options
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
  scooterForm.scooterType = ''
  scooterForm.vendorId = ''
  scooterForm.vendorPriceValue = 0
  scooterForm.customerPriceValue = 0
  scooterForm.payableCoaCode = ''
  scooterForm.feeCoaCode = ''

  if (clearResult) {
    scooterResult.value = { tone: '', text: '' }
  }
}

const resetOperatorForm = (clearResult = true) => {
  editingOperatorId.value = ''
  operatorForm.operator = ''
  operatorForm.vendorId = ''
  operatorForm.vendorPriceValue = 0
  operatorForm.customerPriceValue = 0
  operatorForm.payableCoaCode = ''
  operatorForm.feeCoaCode = ''
  operatorForm.note = ''

  if (clearResult) {
    operatorResult.value = { tone: '', text: '' }
  }
}

const resetIslandTourForm = (clearResult = true) => {
  editingIslandTourId.value = ''
  islandTourForm.destination = ''
  islandTourForm.vendorId = ''
  islandTourForm.driver = ''
  islandTourForm.vendorPriceValue = 0
  islandTourForm.customerPriceValue = 0
  islandTourForm.payableCoaCode = ''
  islandTourForm.feeCoaCode = ''
  islandTourForm.note = ''

  if (clearResult) {
    islandTourResult.value = { tone: '', text: '' }
  }
}

const resetBoatTicketForm = (clearResult = true) => {
  editingBoatTicketId.value = ''
  boatTicketForm.company = ''
  boatTicketForm.vendorId = ''
  boatTicketForm.destination = ''
  boatTicketForm.vendorPriceValue = 0
  boatTicketForm.customerPriceValue = 0
  boatTicketForm.payableCoaCode = ''
  boatTicketForm.feeCoaCode = ''

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
  scooterForm.scooterType = item.scooterType
  scooterForm.vendorId = item.vendorId ? String(item.vendorId) : ''
  scooterForm.vendorPriceValue = item.vendorPriceValue
  scooterForm.customerPriceValue = item.customerPriceValue
  scooterForm.payableCoaCode = item.payableCoaCode || ''
  scooterForm.feeCoaCode = item.feeCoaCode || ''
  scooterResult.value = { tone: '', text: '' }
  showScooterModal.value = true
}

const editOperator = (item) => {
  editingOperatorId.value = String(item.dbId)
  operatorForm.operator = item.operator
  operatorForm.vendorId = item.vendorId ? String(item.vendorId) : ''
  operatorForm.vendorPriceValue = item.vendorPriceValue
  operatorForm.customerPriceValue = item.customerPriceValue
  operatorForm.payableCoaCode = item.payableCoaCode || ''
  operatorForm.feeCoaCode = item.feeCoaCode || ''
  operatorForm.note = item.note
  operatorResult.value = { tone: '', text: '' }
  showOperatorModal.value = true
}

const editIslandTour = (item) => {
  editingIslandTourId.value = String(item.dbId)
  islandTourForm.destination = item.destination
  islandTourForm.vendorId = item.vendorId ? String(item.vendorId) : ''
  islandTourForm.driver = item.driver
  islandTourForm.vendorPriceValue = item.vendorPriceValue
  islandTourForm.customerPriceValue = item.customerPriceValue
  islandTourForm.payableCoaCode = item.payableCoaCode || ''
  islandTourForm.feeCoaCode = item.feeCoaCode || ''
  islandTourForm.note = item.note
  islandTourResult.value = { tone: '', text: '' }
  showIslandTourModal.value = true
}

const editBoatTicket = (item) => {
  editingBoatTicketId.value = String(item.dbId)
  boatTicketForm.company = item.company
  boatTicketForm.vendorId = item.vendorId ? String(item.vendorId) : ''
  boatTicketForm.destination = item.destination
  boatTicketForm.vendorPriceValue = item.vendorPriceValue
  boatTicketForm.customerPriceValue = item.customerPriceValue
  boatTicketForm.payableCoaCode = item.payableCoaCode || ''
  boatTicketForm.feeCoaCode = item.feeCoaCode || ''
  boatTicketResult.value = { tone: '', text: '' }
  showBoatTicketModal.value = true
}

const loadCatalog = async () => {
  const [catalogResponse, transportResponse, vendorResponse, coaResponse] = await Promise.all([
    api.get('/activity-catalog'),
    api.get('/transport-rates'),
    api.get('/vendors'),
    api.get('/coa-accounts', { params: { per_page: 500 } }),
  ])

  const catalog = catalogResponse.data?.data ?? {}
  scooterRows.value = Array.isArray(catalog.scooters) ? catalog.scooters : []
  operatorRows.value = Array.isArray(catalog.operators) ? catalog.operators : []
  islandTourRows.value = Array.isArray(catalog.islandTours) ? catalog.islandTours : []
  boatTicketRows.value = Array.isArray(catalog.boatTickets) ? catalog.boatTickets : []
  transportRates.value = Array.isArray(transportResponse.data?.data) ? transportResponse.data.data : []
  activityVendors.value = Array.isArray(vendorResponse.data?.data) ? vendorResponse.data.data : []
  coaAccounts.value = Array.isArray(coaResponse.data?.data) ? coaResponse.data.data : []
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

  if (!String(scooterForm.scooterType ?? '').trim()) {
    scooterResult.value = { tone: 'error', text: 'Scooter type wajib diisi.' }
    return
  }

  if (!String(scooterForm.vendorId ?? '').trim()) {
    scooterResult.value = { tone: 'error', text: 'Vendor wajib diisi.' }
    return
  }

  if (Number(scooterForm.vendorPriceValue ?? 0) < 0 || Number(scooterForm.customerPriceValue ?? 0) < 0) {
    scooterResult.value = { tone: 'error', text: 'Harga vendor dan customer tidak boleh negatif.' }
    return
  }
  if (Number(scooterForm.customerPriceValue ?? 0) < Number(scooterForm.vendorPriceValue ?? 0)) {
    scooterResult.value = { tone: 'error', text: 'Harga customer tidak boleh lebih kecil dari harga vendor.' }
    return
  }
  if (!String(scooterForm.payableCoaCode ?? '').trim() || !String(scooterForm.feeCoaCode ?? '').trim()) {
    scooterResult.value = { tone: 'error', text: 'Hutang COA dan Fee COA wajib diisi.' }
    return
  }

  try {
    const response = editingScooterId.value
      ? await api.put(`/activity-catalog/scooters/${editingScooterId.value}`, { ...scooterForm, vendorId: Number(scooterForm.vendorId) })
      : await api.post('/activity-catalog/scooters', { ...scooterForm, vendorId: Number(scooterForm.vendorId) })
    scooterResult.value = { tone: 'success', text: response.data?.message || 'Scooter data saved successfully.' }
    await loadCatalog()
    closeScooterModal(false)
  } catch (error) {
    scooterResult.value = {
      tone: 'error',
      text: extractApiError(error, 'Failed to save scooter data.'),
    }
  }
}

const submitOperator = async () => {
  operatorResult.value = { tone: '', text: '' }

  if (!String(operatorForm.vendorId ?? '').trim()) {
    operatorResult.value = { tone: 'error', text: 'Vendor wajib dipilih.' }
    return
  }
  if (Number(operatorForm.vendorPriceValue ?? 0) < 0 || Number(operatorForm.customerPriceValue ?? 0) < 0) {
    operatorResult.value = { tone: 'error', text: 'Harga vendor dan customer tidak boleh negatif.' }
    return
  }
  if (Number(operatorForm.customerPriceValue ?? 0) < Number(operatorForm.vendorPriceValue ?? 0)) {
    operatorResult.value = { tone: 'error', text: 'Harga customer tidak boleh lebih kecil dari harga vendor.' }
    return
  }
  if (!String(operatorForm.payableCoaCode ?? '').trim() || !String(operatorForm.feeCoaCode ?? '').trim()) {
    operatorResult.value = { tone: 'error', text: 'Hutang COA dan Fee COA wajib diisi.' }
    return
  }

  try {
    const response = editingOperatorId.value
      ? await api.put(`/activity-catalog/operators/${editingOperatorId.value}`, { ...operatorForm, vendorId: Number(operatorForm.vendorId) })
      : await api.post('/activity-catalog/operators', { ...operatorForm, vendorId: Number(operatorForm.vendorId) })
    operatorResult.value = { tone: 'success', text: response.data?.message || 'Operator data saved successfully.' }
    await loadCatalog()
    closeOperatorModal(false)
  } catch (error) {
    operatorResult.value = {
      tone: 'error',
      text: error?.response?.data?.message || (error instanceof Error ? error.message : 'Failed to save operator data.'),
    }
  }
}

const submitIslandTour = async () => {
  islandTourResult.value = { tone: '', text: '' }

  if (!String(islandTourForm.vendorId ?? '').trim()) {
    islandTourResult.value = { tone: 'error', text: 'Vendor wajib dipilih.' }
    return
  }
  if (Number(islandTourForm.vendorPriceValue ?? 0) < 0 || Number(islandTourForm.customerPriceValue ?? 0) < 0) {
    islandTourResult.value = { tone: 'error', text: 'Harga vendor dan customer tidak boleh negatif.' }
    return
  }
  if (Number(islandTourForm.customerPriceValue ?? 0) < Number(islandTourForm.vendorPriceValue ?? 0)) {
    islandTourResult.value = { tone: 'error', text: 'Harga customer tidak boleh lebih kecil dari harga vendor.' }
    return
  }
  if (!String(islandTourForm.payableCoaCode ?? '').trim() || !String(islandTourForm.feeCoaCode ?? '').trim()) {
    islandTourResult.value = { tone: 'error', text: 'Hutang COA dan Fee COA wajib diisi.' }
    return
  }

  try {
    const response = editingIslandTourId.value
      ? await api.put(`/activity-catalog/island-tours/${editingIslandTourId.value}`, { ...islandTourForm, vendorId: Number(islandTourForm.vendorId) })
      : await api.post('/activity-catalog/island-tours', { ...islandTourForm, vendorId: Number(islandTourForm.vendorId) })
    islandTourResult.value = { tone: 'success', text: response.data?.message || 'Island tour data saved successfully.' }
    await loadCatalog()
    closeIslandTourModal(false)
  } catch (error) {
    islandTourResult.value = {
      tone: 'error',
      text: error?.response?.data?.message || (error instanceof Error ? error.message : 'Failed to save island tour data.'),
    }
  }
}

const submitBoatTicket = async () => {
  boatTicketResult.value = { tone: '', text: '' }

  if (!String(boatTicketForm.vendorId ?? '').trim()) {
    boatTicketResult.value = { tone: 'error', text: 'Vendor wajib dipilih.' }
    return
  }
  if (Number(boatTicketForm.vendorPriceValue ?? 0) < 0 || Number(boatTicketForm.customerPriceValue ?? 0) < 0) {
    boatTicketResult.value = { tone: 'error', text: 'Harga vendor dan customer tidak boleh negatif.' }
    return
  }
  if (Number(boatTicketForm.customerPriceValue ?? 0) < Number(boatTicketForm.vendorPriceValue ?? 0)) {
    boatTicketResult.value = { tone: 'error', text: 'Harga customer tidak boleh lebih kecil dari harga vendor.' }
    return
  }
  if (!String(boatTicketForm.payableCoaCode ?? '').trim() || !String(boatTicketForm.feeCoaCode ?? '').trim()) {
    boatTicketResult.value = { tone: 'error', text: 'Hutang COA dan Fee COA wajib diisi.' }
    return
  }

  try {
    const response = editingBoatTicketId.value
      ? await api.put(`/activity-catalog/boat-tickets/${editingBoatTicketId.value}`, { ...boatTicketForm, vendorId: Number(boatTicketForm.vendorId) })
      : await api.post('/activity-catalog/boat-tickets', { ...boatTicketForm, vendorId: Number(boatTicketForm.vendorId) })
    boatTicketResult.value = { tone: 'success', text: response.data?.message || 'Boat ticket data saved successfully.' }
    await loadCatalog()
    closeBoatTicketModal(false)
  } catch (error) {
    boatTicketResult.value = {
      tone: 'error',
      text: error?.response?.data?.message || (error instanceof Error ? error.message : 'Failed to save boat ticket data.'),
    }
  }
}

const toggleActivity = async (type, item) => {
  const labels = {
    scooter: item.scooterType || item.id,
    operator: item.operator || item.id,
    island_tour: item.destination || item.id,
    boat_ticket: item.company || item.id,
  }
  const routes = {
    scooter: `/activity-catalog/scooters/${item.dbId}/toggle`,
    operator: `/activity-catalog/operators/${item.dbId}/toggle`,
    island_tour: `/activity-catalog/island-tours/${item.dbId}/toggle`,
    boat_ticket: `/activity-catalog/boat-tickets/${item.dbId}/toggle`,
  }
  const setters = {
    scooter: scooterResult,
    operator: operatorResult,
    island_tour: islandTourResult,
    boat_ticket: boatTicketResult,
  }

  const targetActive = !item.isActive
  const actionLabel = targetActive ? 'aktifkan' : 'nonaktifkan'

  if (!window.confirm(`${actionLabel.charAt(0).toUpperCase() + actionLabel.slice(1)} master activity "${labels[type] ?? item.id}"?`)) {
    return
  }

  try {
    await api.patch(routes[type], { isActive: targetActive })
    setters[type].value = { tone: 'success', text: `Master activity berhasil di${targetActive ? 'aktifkan' : 'nonaktifkan'}.` }
    await loadCatalog()
  } catch (error) {
    setters[type].value = {
      tone: 'error',
      text: extractApiError(error, 'Gagal memperbarui status master activity.'),
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
      <span>Master scooter catalog</span>
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
        <button class="action-button primary" @click="showScooterModal = true">Add scooter</button>
      </div>

      <div v-if="scooterResult.text" class="booking-feedback" :class="scooterResult.tone">
        {{ scooterResult.text }}
      </div>

      <div class="table-scroll">
        <table v-smart-table class="data-table activity-data-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Scooter type</th>
              <th>Vendor</th>
              <th>Harga Vendor</th>
              <th>Harga Customer</th>
              <th>Fee</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in scooterRows" :key="item.id">
              <td><strong>{{ item.id }}</strong></td>
              <td>{{ item.scooterType }}</td>
              <td>{{ item.vendor }}</td>
              <td>{{ item.vendorPrice }}</td>
              <td>{{ item.customerPrice }}</td>
              <td>{{ item.fee }}</td>
              <td>{{ item.isActive ? 'Aktif' : 'Nonaktif' }}</td>
              <td class="row-actions">
                <button class="action-button" @click="editScooter(item)">Edit</button>
                <button class="action-button danger" @click="toggleActivity('scooter', item)">{{ item.isActive ? 'Nonaktifkan' : 'Aktifkan' }}</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </article>

    <article class="panel-card panel-dense">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Operator</p>
          <h3>Vendor and rate</h3>
        </div>
        <button class="action-button primary" @click="showOperatorModal = true">Add operator</button>
      </div>

      <div v-if="operatorResult.text" class="booking-feedback" :class="operatorResult.tone">
        {{ operatorResult.text }}
      </div>

      <div class="table-scroll">
        <table v-smart-table class="data-table activity-data-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Operator</th>
              <th>Vendor</th>
              <th>Harga Vendor</th>
              <th>Harga Customer</th>
              <th>Fee</th>
              <th>Information</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in operatorRows" :key="item.id">
              <td><strong>{{ item.id }}</strong></td>
              <td>{{ item.operator }}</td>
              <td>{{ item.vendor || '-' }}</td>
              <td>{{ item.vendorPrice }}</td>
              <td>{{ item.customerPrice }}</td>
              <td>{{ item.fee }}</td>
              <td>{{ item.note }}</td>
              <td>{{ item.isActive ? 'Aktif' : 'Nonaktif' }}</td>
              <td class="row-actions">
                <button class="action-button" @click="editOperator(item)">Edit</button>
                <button class="action-button danger" @click="toggleActivity('operator', item)">{{ item.isActive ? 'Nonaktifkan' : 'Aktifkan' }}</button>
              </td>
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
          <p class="eyebrow-dark">Island Tour</p>
          <h3>Destination and driver</h3>
        </div>
        <button class="action-button primary" @click="showIslandTourModal = true">Add island tour</button>
      </div>

      <div v-if="islandTourResult.text" class="booking-feedback" :class="islandTourResult.tone">
        {{ islandTourResult.text }}
      </div>

      <div class="table-scroll">
        <table v-smart-table class="data-table activity-data-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Destination</th>
              <th>Vendor</th>
              <th>Driver</th>
              <th>Harga Vendor</th>
              <th>Harga Customer</th>
              <th>Fee</th>
              <th>Notes</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in islandTourRows" :key="item.id">
              <td><strong>{{ item.id }}</strong></td>
              <td>{{ item.destination }}</td>
              <td>{{ item.vendor || '-' }}</td>
              <td>{{ item.driver }}</td>
              <td>{{ item.vendorPrice }}</td>
              <td>{{ item.customerPrice }}</td>
              <td>{{ item.fee }}</td>
              <td>{{ item.note }}</td>
              <td>{{ item.isActive ? 'Aktif' : 'Nonaktif' }}</td>
              <td class="row-actions">
                <button class="action-button" @click="editIslandTour(item)">Edit</button>
                <button class="action-button danger" @click="toggleActivity('island_tour', item)">{{ item.isActive ? 'Nonaktifkan' : 'Aktifkan' }}</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </article>

    <article class="panel-card panel-dense">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Boat Ticket</p>
          <h3>Company, destination, and price</h3>
        </div>
        <button class="action-button primary" @click="showBoatTicketModal = true">Add boat ticket</button>
      </div>

      <div v-if="boatTicketResult.text" class="booking-feedback" :class="boatTicketResult.tone">
        {{ boatTicketResult.text }}
      </div>

      <div class="table-scroll">
        <table v-smart-table class="data-table activity-data-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Company</th>
              <th>Vendor</th>
              <th>Destination</th>
              <th>Harga Vendor</th>
              <th>Harga Customer</th>
              <th>Fee</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in boatTicketRows" :key="item.id">
              <td><strong>{{ item.id }}</strong></td>
              <td>{{ item.company }}</td>
              <td>{{ item.vendor || '-' }}</td>
              <td>{{ item.destination }}</td>
              <td>{{ item.vendorPrice }}</td>
              <td>{{ item.customerPrice }}</td>
              <td>{{ item.fee }}</td>
              <td>{{ item.isActive ? 'Aktif' : 'Nonaktif' }}</td>
              <td class="row-actions">
                <button class="action-button" @click="editBoatTicket(item)">Edit</button>
                <button class="action-button danger" @click="toggleActivity('boat_ticket', item)">{{ item.isActive ? 'Nonaktifkan' : 'Aktifkan' }}</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </article>
  </section>

  <div v-if="showScooterModal" class="modal-backdrop" @click.self="closeScooterModal()">
    <section class="modal-card">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Scooter</p>
          <h3>{{ editingScooterId ? `Edit ${editingScooterId}` : 'Add scooter' }}</h3>
        </div>
        <button class="action-button" @click="closeScooterModal()">Close</button>
      </div>

      <div class="booking-form-grid">
        <label class="field-stack">
          <span>Scooter type</span>
          <input v-model="scooterForm.scooterType" class="form-control" placeholder="Vario, NMAX, etc." />
        </label>
        <label class="field-stack">
          <span>Vendor</span>
          <Select2Field
            v-model="scooterForm.vendorId"
            :options="scooterVendorOptions"
            :multiple="false"
            placeholder="Select scooter vendor"
          />
        </label>
        <label class="field-stack field-span-2">
          <span>Harga Vendor</span>
          <input v-model="scooterForm.vendorPriceValue" class="form-control" type="number" min="0" step="1000" />
        </label>
        <label class="field-stack field-span-2">
          <span>Harga Customer</span>
          <input v-model="scooterForm.customerPriceValue" class="form-control" type="number" min="0" step="1000" />
        </label>
        <label class="field-stack field-span-2">
          <span>Hutang COA</span>
          <Select2Field
            v-model="scooterForm.payableCoaCode"
            :options="payableCoaOptions"
            :multiple="false"
            placeholder="Select hutang COA"
          />
        </label>
        <label class="field-stack field-span-2">
          <span>Fee COA</span>
          <Select2Field
            v-model="scooterForm.feeCoaCode"
            :options="revenueCoaOptions"
            :multiple="false"
            placeholder="Select fee COA"
          />
        </label>
      </div>

      <div class="booking-inline-summary">
        <div class="note-cell">
          <strong>Pricing check</strong>
          <p class="subtle">Fee {{ formatCurrency(Number(scooterForm.customerPriceValue || 0) - Number(scooterForm.vendorPriceValue || 0)) }}</p>
        </div>
      </div>

      <div v-if="scooterResult.text" class="booking-feedback" :class="scooterResult.tone">
        {{ scooterResult.text }}
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
          <h3>{{ editingOperatorId ? `Edit ${editingOperatorId}` : 'Add operator' }}</h3>
        </div>
        <button class="action-button" @click="closeOperatorModal()">Close</button>
      </div>

      <div class="booking-form-grid">
        <label class="field-stack">
          <span>Operator</span>
          <input v-model="operatorForm.operator" class="form-control" placeholder="Operator name" />
        </label>
        <label class="field-stack">
          <span>Vendor</span>
          <Select2Field
            v-model="operatorForm.vendorId"
            :options="operatorVendorOptions"
            :multiple="false"
            placeholder="Select activity operator vendor"
          />
        </label>
        <label class="field-stack">
          <span>Harga Vendor</span>
          <input v-model="operatorForm.vendorPriceValue" class="form-control" type="number" min="0" step="1000" />
        </label>
        <label class="field-stack">
          <span>Harga Customer</span>
          <input v-model="operatorForm.customerPriceValue" class="form-control" type="number" min="0" step="1000" />
        </label>
        <label class="field-stack">
          <span>Hutang COA</span>
          <Select2Field
            v-model="operatorForm.payableCoaCode"
            :options="payableCoaOptions"
            :multiple="false"
            placeholder="Select hutang COA"
          />
        </label>
        <label class="field-stack">
          <span>Fee COA</span>
          <Select2Field
            v-model="operatorForm.feeCoaCode"
            :options="revenueCoaOptions"
            :multiple="false"
            placeholder="Select fee COA"
          />
        </label>
        <label class="field-stack field-span-2">
          <span>Information</span>
          <textarea v-model="operatorForm.note" class="form-control form-textarea" placeholder="Operator information"></textarea>
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
          <h3>{{ editingIslandTourId ? `Edit ${editingIslandTourId}` : 'Add island tour' }}</h3>
        </div>
        <button class="action-button" @click="closeIslandTourModal()">Close</button>
      </div>

      <div class="booking-form-grid">
        <label class="field-stack">
          <span>Destination</span>
          <input v-model="islandTourForm.destination" class="form-control" placeholder="Destination name" />
        </label>
        <label class="field-stack">
          <span>Vendor</span>
          <Select2Field
            v-model="islandTourForm.vendorId"
            :options="islandTourVendorOptions"
            :multiple="false"
            placeholder="Select island tour vendor"
          />
        </label>
        <label class="field-stack">
          <span>Driver</span>
          <Select2Field
            v-model="islandTourForm.driver"
            :options="transportDriverOptions"
            :multiple="false"
            placeholder="Select a driver from transport"
          />
        </label>
        <label class="field-stack">
          <span>Harga Vendor</span>
          <input v-model="islandTourForm.vendorPriceValue" class="form-control" type="number" min="0" step="1000" />
        </label>
        <label class="field-stack">
          <span>Harga Customer</span>
          <input v-model="islandTourForm.customerPriceValue" class="form-control" type="number" min="0" step="1000" />
        </label>
        <label class="field-stack">
          <span>Hutang COA</span>
          <Select2Field
            v-model="islandTourForm.payableCoaCode"
            :options="payableCoaOptions"
            :multiple="false"
            placeholder="Select hutang COA"
          />
        </label>
        <label class="field-stack">
          <span>Fee COA</span>
          <Select2Field
            v-model="islandTourForm.feeCoaCode"
            :options="revenueCoaOptions"
            :multiple="false"
            placeholder="Select fee COA"
          />
        </label>
        <label class="field-stack field-span-2">
          <span>Notes</span>
          <textarea v-model="islandTourForm.note" class="form-control form-textarea" placeholder="Tour information"></textarea>
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
          <h3>{{ editingBoatTicketId ? `Edit ${editingBoatTicketId}` : 'Add boat ticket' }}</h3>
        </div>
        <button class="action-button" @click="closeBoatTicketModal()">Close</button>
      </div>

      <div class="booking-form-grid">
        <label class="field-stack">
          <span>Company</span>
          <input v-model="boatTicketForm.company" class="form-control" placeholder="Company name" />
        </label>
        <label class="field-stack">
          <span>Vendor</span>
          <Select2Field
            v-model="boatTicketForm.vendorId"
            :options="boatTicketVendorOptions"
            :multiple="false"
            placeholder="Select boat ticket vendor"
          />
        </label>
        <label class="field-stack">
          <span>Destination</span>
          <input v-model="boatTicketForm.destination" class="form-control" placeholder="Ticket destination" />
        </label>
        <label class="field-stack field-span-2">
          <span>Harga Vendor</span>
          <input v-model="boatTicketForm.vendorPriceValue" class="form-control" type="number" min="0" step="1000" />
        </label>
        <label class="field-stack field-span-2">
          <span>Harga Customer</span>
          <input v-model="boatTicketForm.customerPriceValue" class="form-control" type="number" min="0" step="1000" />
        </label>
        <label class="field-stack field-span-2">
          <span>Hutang COA</span>
          <Select2Field
            v-model="boatTicketForm.payableCoaCode"
            :options="payableCoaOptions"
            :multiple="false"
            placeholder="Select hutang COA"
          />
        </label>
        <label class="field-stack field-span-2">
          <span>Fee COA</span>
          <Select2Field
            v-model="boatTicketForm.feeCoaCode"
            :options="revenueCoaOptions"
            :multiple="false"
            placeholder="Select fee COA"
          />
        </label>
      </div>

      <div class="modal-actions">
        <button class="action-button" @click="closeBoatTicketModal()">Cancel</button>
        <button class="action-button primary" @click="submitBoatTicket">Save boat ticket</button>
      </div>
    </section>
  </div>
</template>

<style scoped>
.table-scroll {
  overflow-x: auto;
}

.activity-data-table {
  width: max-content;
  min-width: 100%;
}

.activity-data-table th,
.activity-data-table td {
  white-space: nowrap;
}

.row-actions {
  white-space: nowrap;
}
</style>
