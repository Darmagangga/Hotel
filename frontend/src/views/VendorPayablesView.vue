<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import LoadingState from '../components/LoadingState.vue'
import api from '../services/api'

const loading = ref(false)
const pageResult = ref({ tone: '', text: '' })
const vendorModalResult = ref({ tone: '', text: '' })
const vendors = ref([])
const bills = ref([])
const payments = ref([])
const report = ref({
  summary: {
    totalOutstanding: 'IDR 0',
    totalOutstandingValue: 0,
    totalOverdue: 'IDR 0',
    totalOverdueValue: 0,
    dueThisWeek: 'IDR 0',
    dueThisWeekValue: 0,
    current: 'IDR 0',
    aging30: 'IDR 0',
    aging60: 'IDR 0',
    aging90: 'IDR 0',
    agingAbove90: 'IDR 0',
    vendorCount: 0,
    openBillCount: 0,
  },
  vendors: [],
  bills: [],
  payments: [],
})

const selectedVendorId = ref('')
const showVendorModal = ref(false)
const showBillModal = ref(false)
const showPaymentModal = ref(false)
const editingVendorId = ref(null)

const sourceModuleOptions = [
  { value: 'activity', label: 'General Activity' },
  { value: 'scooter', label: 'Scooter' },
  { value: 'operator', label: 'Operator' },
  { value: 'island_tour', label: 'Island Tour' },
  { value: 'boat_ticket', label: 'Boat Ticket' },
]

const paymentMethods = ['Bank Transfer', 'Cash', 'Debit Card', 'Credit Card', 'Other']

const vendorForm = reactive({
  vendorName: '',
  phone: '',
  email: '',
  address: '',
  contactPerson: '',
  paymentTermsDays: 0,
  openingBalanceValue: 0,
  notes: '',
  isActive: true,
})

const billForm = reactive({
  vendorId: '',
  billDate: new Date().toISOString().slice(0, 10),
  dueDate: '',
  description: '',
  grandTotalValue: '',
  sourceModule: 'activity',
  sourceReference: '',
  notes: '',
})

const paymentForm = reactive({
  vendorId: '',
  paymentDate: new Date().toISOString().slice(0, 10),
  paymentMethod: paymentMethods[0],
  amountValue: '',
  referenceNumber: '',
  notes: '',
  allocations: [],
})

const toCurrency = (amount) =>
  new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
  }).format(Number(amount || 0))

const selectedVendor = computed(() =>
  vendors.value.find((item) => String(item.id) === String(selectedVendorId.value)) ?? null,
)

const visibleBills = computed(() => {
  if (!selectedVendorId.value) {
    return bills.value
  }
  return bills.value.filter((item) => String(item.vendorId) === String(selectedVendorId.value))
})

const visiblePayments = computed(() => {
  if (!selectedVendorId.value) {
    return payments.value
  }
  return payments.value.filter((item) => String(item.vendorId) === String(selectedVendorId.value))
})

const allocatableBills = computed(() =>
  bills.value.filter((item) =>
    String(item.vendorId) === String(paymentForm.vendorId) && ['unpaid', 'partial'].includes(String(item.status)),
  ),
)

const paymentPreviewTotal = computed(() =>
  paymentForm.allocations.reduce((total, item) => total + Number(item.allocatedAmountValue || 0), 0),
)

const positivePaymentAllocations = computed(() =>
  paymentForm.allocations
    .filter((item) => Number(item.allocatedAmountValue || 0) > 0)
    .map((item) => ({
      billId: Number(item.billId),
      allocatedAmountValue: Number(item.allocatedAmountValue || 0),
    })),
)

const syncPaymentAllocations = () => {
  const existingMap = new Map(
    paymentForm.allocations.map((item) => [String(item.billId), Number(item.allocatedAmountValue || 0)]),
  )

  paymentForm.allocations = allocatableBills.value.map((bill) => ({
    billId: bill.id,
    billNumber: bill.billNumber,
    balanceDueValue: Number(bill.balanceDueValue || 0),
    allocatedAmountValue: existingMap.get(String(bill.id)) ?? 0,
  }))
}

const resetVendorForm = () => {
  editingVendorId.value = null
  vendorModalResult.value = { tone: '', text: '' }
  vendorForm.vendorName = ''
  vendorForm.phone = ''
  vendorForm.email = ''
  vendorForm.address = ''
  vendorForm.contactPerson = ''
  vendorForm.paymentTermsDays = 0
  vendorForm.openingBalanceValue = 0
  vendorForm.notes = ''
  vendorForm.isActive = true
}

const resetBillForm = () => {
  billForm.vendorId = selectedVendorId.value || ''
  billForm.billDate = new Date().toISOString().slice(0, 10)
  billForm.dueDate = ''
  billForm.description = ''
  billForm.grandTotalValue = ''
  billForm.sourceModule = 'activity'
  billForm.sourceReference = ''
  billForm.notes = ''
}

const resetPaymentForm = () => {
  paymentForm.vendorId = selectedVendorId.value || ''
  paymentForm.paymentDate = new Date().toISOString().slice(0, 10)
  paymentForm.paymentMethod = paymentMethods[0]
  paymentForm.amountValue = ''
  paymentForm.referenceNumber = ''
  paymentForm.notes = ''
  paymentForm.allocations = []
  syncPaymentAllocations()
}

const loadData = async () => {
  loading.value = true
  pageResult.value = { tone: '', text: '' }

  try {
    const requestParams = selectedVendorId.value ? { vendor_id: Number(selectedVendorId.value) } : undefined
    const [vendorsRes, billsRes, paymentsRes, reportRes] = await Promise.all([
      api.get('/vendors'),
      api.get('/vendor-bills', { params: requestParams }),
      api.get('/vendor-payments', { params: requestParams }),
      api.get('/reports/vendor-payables', { params: requestParams }),
    ])

    vendors.value = Array.isArray(vendorsRes.data?.data) ? vendorsRes.data.data : []
    bills.value = Array.isArray(billsRes.data?.data) ? billsRes.data.data : []
    payments.value = Array.isArray(paymentsRes.data?.data) ? paymentsRes.data.data : []
    report.value = reportRes.data?.data ?? report.value

    if (selectedVendorId.value && !vendors.value.some((item) => String(item.id) === String(selectedVendorId.value))) {
      selectedVendorId.value = ''
    }
  } catch (error) {
    pageResult.value = {
      tone: 'error',
      text: error?.response?.data?.message || 'Failed to load vendor payable data.',
    }
  } finally {
    loading.value = false
  }
}

const openVendorModal = (vendor = null) => {
  resetVendorForm()
  if (vendor) {
    editingVendorId.value = vendor.id
    vendorForm.vendorName = vendor.vendorName
    vendorForm.phone = vendor.phone
    vendorForm.email = vendor.email
    vendorForm.address = vendor.address
    vendorForm.contactPerson = vendor.contactPerson
    vendorForm.paymentTermsDays = Number(vendor.paymentTermsDays || 0)
    vendorForm.openingBalanceValue = Number(vendor.openingBalanceValue || 0)
    vendorForm.notes = vendor.notes
    vendorForm.isActive = Boolean(vendor.isActive)
  }
  showVendorModal.value = true
}

const openBillModal = () => {
  resetBillForm()
  showBillModal.value = true
}

const openPaymentModal = () => {
  resetPaymentForm()
  showPaymentModal.value = true
}

const submitVendor = async () => {
  if (!String(vendorForm.vendorName || '').trim()) {
    vendorModalResult.value = { tone: 'error', text: 'Vendor name wajib diisi.' }
    return
  }

  try {
    const payload = {
      ...vendorForm,
      paymentTermsDays: Number(vendorForm.paymentTermsDays || 0),
      openingBalanceValue: Number(vendorForm.openingBalanceValue || 0),
    }

    const response = editingVendorId.value
      ? await api.put(`/vendors/${editingVendorId.value}`, payload)
      : await api.post('/vendors', payload)

    pageResult.value = { tone: 'success', text: response.data?.message || 'Activity vendor saved successfully.' }
    vendorModalResult.value = { tone: '', text: '' }
    showVendorModal.value = false
    await loadData()
  } catch (error) {
    vendorModalResult.value = {
      tone: 'error',
      text: error?.response?.data?.message || 'Failed to save activity vendor.',
    }
  }
}

const submitBill = async () => {
  try {
    const response = await api.post('/vendor-bills', {
      ...billForm,
      vendorId: Number(billForm.vendorId),
      grandTotalValue: Number(billForm.grandTotalValue || 0),
    })

    pageResult.value = { tone: 'success', text: response.data?.message || 'Activity bill saved successfully.' }
    showBillModal.value = false
    await loadData()
  } catch (error) {
    pageResult.value = {
      tone: 'error',
      text: error?.response?.data?.message || 'Failed to save activity bill.',
    }
  }
}

const submitPayment = async () => {
  const allocatedAmount = positivePaymentAllocations.value.reduce(
    (total, item) => total + Number(item.allocatedAmountValue || 0),
    0,
  )
  const manualAmount = Number(paymentForm.amountValue || 0)
  const paymentAmount = allocatedAmount > 0 ? allocatedAmount : manualAmount

  if (paymentAmount <= 0) {
    pageResult.value = {
      tone: 'error',
      text: 'Nilai 0 berarti belum ada hutang yang dibayar. Isi nominal pada hutang yang benar-benar dibayar.',
    }
    return
  }

  try {
    const response = await api.post('/vendor-payments', {
      ...paymentForm,
      vendorId: Number(paymentForm.vendorId),
      amountValue: paymentAmount,
      allocations: positivePaymentAllocations.value,
    })

    pageResult.value = { tone: 'success', text: response.data?.message || 'Activity payment saved successfully.' }
    showPaymentModal.value = false
    await loadData()
  } catch (error) {
    pageResult.value = {
      tone: 'error',
      text: error?.response?.data?.message || 'Failed to save activity payment.',
    }
  }
}

watch(
  () => paymentForm.vendorId,
  () => {
    syncPaymentAllocations()
  },
)

watch(
  () => selectedVendorId.value,
  () => {
    loadData()
  },
)

onMounted(() => {
  loadData()
})
</script>

<template>
  <section class="page-grid">
    <LoadingState v-if="loading" label="Loading vendor payables..." overlay />

    <article class="panel-card panel-dense">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Activity Payables</p>
          <h3>Activity vendor debt control</h3>
        </div>
        <div class="modal-actions">
          <button class="action-button" @click="openVendorModal()">Add vendor</button>
          <button class="action-button" @click="openBillModal()">Add bill</button>
          <button class="action-button primary" @click="openPaymentModal()">Pay vendor</button>
        </div>
      </div>

      <div v-if="pageResult.text" class="booking-feedback" :class="pageResult.tone">
        {{ pageResult.text }}
      </div>

      <div class="summary-strip">
        <article class="summary-card">
          <p class="summary-label">Outstanding</p>
          <strong>{{ report.summary.totalOutstanding }}</strong>
          <span>{{ report.summary.openBillCount }} open bill(s)</span>
        </article>
        <article class="summary-card">
          <p class="summary-label">Overdue</p>
          <strong>{{ report.summary.totalOverdue }}</strong>
          <span>Past due vendor liabilities</span>
        </article>
        <article class="summary-card">
          <p class="summary-label">Due This Week</p>
          <strong>{{ report.summary.dueThisWeek }}</strong>
          <span>Upcoming payable pressure</span>
        </article>
        <article class="summary-card">
          <p class="summary-label">Vendors</p>
          <strong>{{ report.summary.vendorCount }}</strong>
          <span>Tracked activity vendors</span>
        </article>
      </div>
    </article>

    <section class="page-grid two">
      <article class="panel-card panel-dense">
        <div class="panel-head panel-head-tight">
          <div>
          <p class="eyebrow-dark">Vendors</p>
          <h3>Vendor master for activity payables</h3>
          </div>
        </div>

        <div class="table-scroll">
          <table v-smart-table class="data-table">
            <thead>
              <tr>
                <th>Vendor</th>
                <th>Contact</th>
                <th>Outstanding</th>
                <th>Overdue</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in vendors" :key="item.id">
                <td>
                  <strong>{{ item.vendorName }}</strong>
                  <div class="subtle">{{ item.vendorCode }}</div>
                </td>
                <td>
                  <div>{{ item.contactPerson || '-' }}</div>
                  <div class="subtle">{{ item.phone || item.email || '-' }}</div>
                </td>
                <td>{{ item.outstanding }}</td>
                <td>{{ item.overdue }}</td>
                <td>
                  <div class="modal-actions booking-table-actions">
                    <button class="action-button" @click="selectedVendorId = String(item.id)">View</button>
                    <button class="action-button" @click="openVendorModal(item)">Edit</button>
                  </div>
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
          <p class="eyebrow-dark">Activity Bills</p>
          <h3>Activity vendor bills</h3>
          </div>
          <span class="status-badge warning">{{ visibleBills.length }} bill(s)</span>
        </div>

        <div class="table-scroll">
          <table v-smart-table class="data-table finance-table">
            <thead>
              <tr>
                <th>Bill</th>
                <th>Vendor</th>
                <th>Due</th>
                <th>Total</th>
                <th>Balance</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in visibleBills" :key="item.id">
                <td>
                  <strong>{{ item.billNumber }}</strong>
                  <div class="subtle">{{ item.description }}</div>
                </td>
                <td>{{ item.vendorName }}</td>
                <td>{{ item.dueDate }}</td>
                <td>{{ item.grandTotal }}</td>
                <td>{{ item.balanceDue }}</td>
                <td>{{ item.statusLabel }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </article>

      <article class="panel-card panel-dense">
        <div class="panel-head panel-head-tight">
          <div>
          <p class="eyebrow-dark">Payments</p>
          <h3>Activity vendor payments</h3>
          </div>
          <span class="status-badge success">{{ visiblePayments.length }} payment(s)</span>
        </div>

        <div class="table-scroll">
          <table v-smart-table class="data-table finance-table">
            <thead>
              <tr>
                <th>Payment</th>
                <th>Vendor</th>
                <th>Date</th>
                <th>Method</th>
                <th>Amount</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in visiblePayments" :key="item.id">
                <td>
                  <strong>{{ item.paymentNumber }}</strong>
                  <div class="subtle">{{ item.referenceNumber || '-' }}</div>
                </td>
                <td>{{ item.vendorName }}</td>
                <td>{{ item.paymentDate }}</td>
                <td>{{ item.paymentMethodLabel }}</td>
                <td>{{ item.amount }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </article>
    </section>
  </section>

  <div v-if="showVendorModal" class="modal-backdrop" @click.self="showVendorModal = false">
    <section class="modal-card">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Vendor</p>
          <h3>{{ editingVendorId ? 'Edit vendor' : 'Add vendor' }}</h3>
        </div>
        <button class="action-button" @click="showVendorModal = false">Close</button>
      </div>

      <div class="booking-form-grid">
        <label class="field-stack"><span>Vendor name</span><input v-model="vendorForm.vendorName" class="form-control" /></label>
        <label class="field-stack">
          <span>Contact person</span>
          <input v-model="vendorForm.contactPerson" class="form-control" />
        </label>
        <label class="field-stack"><span>Phone</span><input v-model="vendorForm.phone" class="form-control" /></label>
        <label class="field-stack"><span>Email</span><input v-model="vendorForm.email" class="form-control" /></label>
        <label class="field-stack"><span>Terms (days)</span><input v-model="vendorForm.paymentTermsDays" class="form-control" type="number" min="0" /></label>
        <label class="field-stack"><span>Opening balance</span><input v-model="vendorForm.openingBalanceValue" class="form-control" type="number" min="0" /></label>
        <label class="field-stack">
          <span>Status</span>
          <select v-model="vendorForm.isActive" class="form-control">
            <option :value="true">Active</option>
            <option :value="false">Inactive</option>
          </select>
        </label>
        <label class="field-stack field-span-2"><span>Address</span><textarea v-model="vendorForm.address" class="form-control form-textarea"></textarea></label>
        <label class="field-stack field-span-2"><span>Notes</span><textarea v-model="vendorForm.notes" class="form-control form-textarea"></textarea></label>
      </div>

      <div v-if="vendorModalResult.text" class="booking-feedback" :class="vendorModalResult.tone">
        {{ vendorModalResult.text }}
      </div>

      <div class="modal-actions">
        <button class="action-button" @click="showVendorModal = false">Cancel</button>
        <button class="action-button primary" @click="submitVendor">Save vendor</button>
      </div>
    </section>
  </div>

  <div v-if="showBillModal" class="modal-backdrop" @click.self="showBillModal = false">
    <section class="modal-card">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Activity Bill</p>
          <h3>Create activity payable bill</h3>
        </div>
        <button class="action-button" @click="showBillModal = false">Close</button>
      </div>

      <div class="booking-form-grid">
        <label class="field-stack">
          <span>Activity vendor</span>
          <select v-model="billForm.vendorId" class="form-control">
            <option value="">Select vendor</option>
            <option v-for="item in vendors" :key="item.id" :value="item.id">{{ item.vendorName }}</option>
          </select>
        </label>
        <label class="field-stack"><span>Bill date</span><input v-model="billForm.billDate" class="form-control" type="date" /></label>
        <label class="field-stack"><span>Due date</span><input v-model="billForm.dueDate" class="form-control" type="date" /></label>
        <label class="field-stack"><span>Amount</span><input v-model="billForm.grandTotalValue" class="form-control" type="number" min="0" /></label>
        <label class="field-stack">
          <span>Activity source</span>
          <select v-model="billForm.sourceModule" class="form-control">
            <option v-for="item in sourceModuleOptions" :key="item.value" :value="item.value">{{ item.label }}</option>
          </select>
        </label>
        <label class="field-stack"><span>Reference</span><input v-model="billForm.sourceReference" class="form-control" /></label>
        <label class="field-stack field-span-2"><span>Description</span><textarea v-model="billForm.description" class="form-control form-textarea"></textarea></label>
        <label class="field-stack field-span-2"><span>Notes</span><textarea v-model="billForm.notes" class="form-control form-textarea"></textarea></label>
      </div>

      <div class="modal-actions">
        <button class="action-button" @click="showBillModal = false">Cancel</button>
        <button class="action-button primary" @click="submitBill">Save bill</button>
      </div>
    </section>
  </div>

  <div v-if="showPaymentModal" class="modal-backdrop" @click.self="showPaymentModal = false">
    <section class="modal-card invoice-modal-card">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Activity Payment</p>
          <h3>Allocate activity vendor payment</h3>
        </div>
        <button class="action-button" @click="showPaymentModal = false">Close</button>
      </div>

      <div class="booking-form-grid">
        <label class="field-stack">
          <span>Activity vendor</span>
          <select v-model="paymentForm.vendorId" class="form-control">
            <option value="">Select vendor</option>
            <option v-for="item in vendors" :key="item.id" :value="item.id">{{ item.vendorName }}</option>
          </select>
        </label>
        <label class="field-stack"><span>Payment date</span><input v-model="paymentForm.paymentDate" class="form-control" type="date" /></label>
        <label class="field-stack">
          <span>Method</span>
          <select v-model="paymentForm.paymentMethod" class="form-control">
            <option v-for="item in paymentMethods" :key="item" :value="item">{{ item }}</option>
          </select>
        </label>
        <label class="field-stack"><span>Amount</span><input v-model="paymentForm.amountValue" class="form-control" type="number" min="0" /></label>
        <label class="field-stack"><span>Reference</span><input v-model="paymentForm.referenceNumber" class="form-control" /></label>
        <label class="field-stack field-span-2"><span>Notes</span><textarea v-model="paymentForm.notes" class="form-control form-textarea"></textarea></label>
      </div>

      <article class="panel-card panel-dense">
        <div class="panel-head panel-head-tight">
          <div>
            <p class="eyebrow-dark">Allocation</p>
            <h3>Open bills for selected vendor</h3>
          </div>
          <span class="status-badge info">{{ toCurrency(paymentPreviewTotal) }}</span>
        </div>

        <div class="table-scroll">
          <table class="data-table finance-table">
            <thead>
              <tr>
                <th>Bill</th>
                <th>Due</th>
                <th>Balance</th>
                <th>Allocate</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in paymentForm.allocations" :key="item.billId">
                <td>{{ item.billNumber }}</td>
                <td>{{ bills.find((bill) => bill.id === item.billId)?.dueDate || '-' }}</td>
                <td>{{ toCurrency(item.balanceDueValue) }}</td>
                <td><input v-model="item.allocatedAmountValue" class="form-control" type="number" min="0" :max="item.balanceDueValue" /></td>
              </tr>
              <tr v-if="!paymentForm.allocations.length">
                <td colspan="4" class="table-empty-cell">No open bills for this vendor. Payment will remain unallocated.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </article>

      <div class="modal-actions">
        <button class="action-button" @click="showPaymentModal = false">Cancel</button>
        <button class="action-button primary" @click="submitPayment">Post payment</button>
      </div>
    </section>
  </div>
</template>
