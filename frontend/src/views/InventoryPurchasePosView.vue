<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import Swal from 'sweetalert2'
import LoadingState from '../components/LoadingState.vue'
import Select2Field from '../components/Select2Field.vue'
import api from '../services/api'
import { useHotelStore } from '../stores/hotel'

const hotel = useHotelStore()

const loading = ref(false)
const purchaseResult = ref({ tone: '', text: '' })
const inventoryItems = ref([])
const inventoryPurchases = ref([])
const activeTab = ref('detail')
const showDetailModal = ref(false)
const detailModalMode = ref('create')
const purchaseErrors = reactive({
  supplier: '',
  purchaseDate: '',
  paymentAccount: '',
  cartItems: '',
})
const detailErrors = reactive({
  itemId: '',
  quantity: '',
  unitCostValue: '',
})

const currencyFormatter = new Intl.NumberFormat('id-ID', {
  style: 'currency',
  currency: 'IDR',
  maximumFractionDigits: 0,
})

const toCurrency = (amount) => currencyFormatter.format(Number(amount ?? 0) || 0)
const toNumberInput = (value) => {
  const digitsOnly = String(value ?? '').replace(/[^0-9]/g, '')
  return digitsOnly ? Number(digitsOnly) : ''
}
const formatNumberInput = (value) => {
  if (value === '' || value == null) return ''
  return new Intl.NumberFormat('id-ID', {
    maximumFractionDigits: 0,
  }).format(Number(value) || 0)
}

const purchaseForm = reactive({
  purchaseDate: new Date().toISOString().slice(0, 10),
  supplier: '',
  paymentAccount: '',
  note: '',
  extraCostPercent: 0,
  transactionNo: 'Auto',
  cartItems: [],
})

const detailForm = reactive({
  rowId: '',
  itemId: '',
  quantity: 1,
  unit: '',
  unitCostValue: '',
  note: '',
})
const isDetailPriceFocused = ref(false)

const inventoryAssetOptions = computed(() =>
  hotel.coaList
    .filter((item) => item.category === 'Asset')
    .map((item) => ({ value: `${item.code} - ${item.name}`, label: `${item.code} - ${item.name}` })),
)

const inventoryItemOptions = computed(() =>
  inventoryItems.value.map((item) => ({
    value: item.id,
    label: `${item.code || 'ITEM'} | ${item.name} | Stock ${item.onHandQty} ${item.unit}`,
  })),
)

const selectedDetailItem = computed(() =>
  inventoryItems.value.find((item) => Number(item.id) === Number(detailForm.itemId)) ?? null,
)

const detailSubtotalValue = computed(() => {
  const gross = Number(detailForm.quantity || 0) * Number(detailForm.unitCostValue || 0)
  return Math.max(gross, 0)
})
const detailUnitCostDisplay = computed({
  get: () => {
    if (isDetailPriceFocused.value) {
      return detailForm.unitCostValue
    }
    return formatNumberInput(detailForm.unitCostValue)
  },
  set: (value) => {
    detailForm.unitCostValue = toNumberInput(value)
  },
})

const grossTotalValue = computed(() =>
  purchaseForm.cartItems.reduce((total, item) => total + Number(item.grossSubtotalValue || 0), 0),
)
const totalDiscountValue = computed(() =>
  purchaseForm.cartItems.reduce((total, item) => total + Number(item.discountValue || 0), 0),
)
const subtotalValue = computed(() =>
  purchaseForm.cartItems.reduce((total, item) => total + Number(item.lineTotalValue || 0), 0),
)
const totalQuantity = computed(() =>
  purchaseForm.cartItems.reduce((total, item) => total + Number(item.quantity || 0), 0),
)
const extraCostValue = computed(() =>
  Math.max((subtotalValue.value * Number(purchaseForm.extraCostPercent || 0)) / 100, 0),
)
const grandTotalValue = computed(() => subtotalValue.value + extraCostValue.value)
const canSubmitPurchase = computed(() => purchaseForm.cartItems.length > 0)

const clearPurchaseErrors = () => {
  purchaseErrors.supplier = ''
  purchaseErrors.purchaseDate = ''
  purchaseErrors.paymentAccount = ''
  purchaseErrors.cartItems = ''
}

const clearDetailErrors = () => {
  detailErrors.itemId = ''
  detailErrors.quantity = ''
  detailErrors.unitCostValue = ''
}

const validatePurchaseForm = () => {
  clearPurchaseErrors()
  let hasError = false

  if (!String(purchaseForm.supplier).trim()) {
    purchaseErrors.supplier = 'Supplier is required.'
    hasError = true
  }

  if (!String(purchaseForm.purchaseDate).trim()) {
    purchaseErrors.purchaseDate = 'Purchase date is required.'
    hasError = true
  }

  if (!String(purchaseForm.paymentAccount).trim()) {
    purchaseErrors.paymentAccount = 'A payment account must be selected.'
    hasError = true
  }

  if (!purchaseForm.cartItems.length) {
    purchaseErrors.cartItems = 'Add at least one item line.'
    hasError = true
  }

  return !hasError
}

const validateDetailForm = () => {
  clearDetailErrors()
  let hasError = false

  if (!selectedDetailItem.value) {
    detailErrors.itemId = 'An item code must be selected.'
    hasError = true
  }

  const quantity = Number(detailForm.quantity)
  if (!quantity || quantity < 1) {
    detailErrors.quantity = 'Minimum quantity is 1.'
    hasError = true
  }

  const unitCostValue = Number(detailForm.unitCostValue)
  if (!unitCostValue || unitCostValue <= 0) {
    detailErrors.unitCostValue = 'Unit price must be greater than 0.'
    hasError = true
  }

  return !hasError
}

const resetPurchaseForm = () => {
  purchaseForm.purchaseDate = new Date().toISOString().slice(0, 10)
  purchaseForm.supplier = ''
  purchaseForm.paymentAccount = inventoryAssetOptions.value[0]?.value ?? ''
  purchaseForm.note = ''
  purchaseForm.extraCostPercent = 0
  purchaseForm.transactionNo = 'Auto'
  purchaseForm.cartItems = []
  clearPurchaseErrors()
}

const resetDetailForm = () => {
  detailForm.rowId = ''
  detailForm.itemId = inventoryItemOptions.value[0]?.value ?? ''
  detailForm.quantity = 1
  detailForm.unit = selectedDetailItem.value?.unit ?? ''
  detailForm.unitCostValue = selectedDetailItem.value?.latestCostValue ? Number(selectedDetailItem.value.latestCostValue) : ''
  detailForm.note = ''
  isDetailPriceFocused.value = false
  clearDetailErrors()
}

const openCreateDetailModal = () => {
  purchaseResult.value = { tone: '', text: '' }
  detailModalMode.value = 'create'
  resetDetailForm()
  showDetailModal.value = true
}

const openEditDetailModal = (entry) => {
  purchaseResult.value = { tone: '', text: '' }
  detailModalMode.value = 'edit'
  clearDetailErrors()
  detailForm.rowId = entry.rowId
  detailForm.itemId = entry.itemId
  detailForm.quantity = entry.quantity
  detailForm.unit = entry.unit
  detailForm.unitCostValue = entry.unitCostValue
  detailForm.note = entry.note || ''
  isDetailPriceFocused.value = false
  showDetailModal.value = true
}

const removeDetailLine = (rowId) => {
  purchaseForm.cartItems = purchaseForm.cartItems.filter((item) => item.rowId !== rowId)
}

const saveDetailLine = () => {
  purchaseResult.value = { tone: '', text: '' }
  if (!validateDetailForm()) return

  const quantity = Number(detailForm.quantity)
  const unitCostValue = Number(detailForm.unitCostValue)

  const grossSubtotalValue = quantity * unitCostValue
  const discountValue = 0
  const lineTotalValue = detailSubtotalValue.value
  const entry = {
    rowId: detailForm.rowId || `${selectedDetailItem.value.id}-${Date.now()}-${purchaseForm.cartItems.length}`,
    itemId: selectedDetailItem.value.id,
    itemCode: selectedDetailItem.value.code || `ITEM-${selectedDetailItem.value.id}`,
    itemName: selectedDetailItem.value.name,
    quantity,
    unit: selectedDetailItem.value.unit,
    unitCostValue,
    discountPercent: 0,
    discountValue,
    grossSubtotalValue,
    lineTotalValue,
    note: detailForm.note,
  }

  if (detailModalMode.value === 'edit') {
    purchaseForm.cartItems = purchaseForm.cartItems.map((item) => (item.rowId === entry.rowId ? entry : item))
  } else {
    purchaseForm.cartItems.push(entry)
  }

  showDetailModal.value = false
}

const loadDependencies = async () => {
  try {
    const coaResponse = await api.get('/coa-accounts', { params: { per_page: 500 } })
    const coaRows = Array.isArray(coaResponse.data?.data) ? coaResponse.data.data : []
    if (coaRows.length) {
      hotel.setCoaAccounts(coaRows)
    }
  } catch (error) {
    // keep page usable when coa fetch is temporarily unavailable
  }
}

const loadInventory = async () => {
  loading.value = true
  purchaseResult.value = { tone: '', text: '' }

  try {
    const response = await api.get('/inventory')
    const data = response.data?.data ?? {}
    inventoryItems.value = Array.isArray(data.items) ? data.items : []
    inventoryPurchases.value = Array.isArray(data.purchases) ? data.purchases : []
    hotel.setInventorySnapshot({
      items: inventoryItems.value,
      purchases: inventoryPurchases.value,
      issues: Array.isArray(data.issues) ? data.issues : [],
    })
  } catch (error) {
    purchaseResult.value = {
      tone: 'error',
      text: error?.response?.data?.message || error?.message || 'Failed to load inventory purchase POS data.',
    }
    inventoryItems.value = []
    inventoryPurchases.value = []
  } finally {
    loading.value = false
  }
}

const submitPurchase = async () => {
  purchaseResult.value = { tone: '', text: '' }
  if (!validatePurchaseForm()) {
    purchaseResult.value = { tone: 'error', text: 'Some data is still incomplete. Check the fields highlighted in red.' }
    return
  }

  try {
    const response = await api.post('/inventory/purchases', {
      purchaseDate: purchaseForm.purchaseDate,
      supplier: purchaseForm.supplier,
      paymentAccount: purchaseForm.paymentAccount,
      note: purchaseForm.note,
      extraCostPercent: Number(purchaseForm.extraCostPercent || 0),
      extraCostValue: extraCostValue.value,
      items: purchaseForm.cartItems.map((item) => ({
        itemId: Number(item.itemId),
        quantity: Number(item.quantity),
        unitCostValue: Number(item.unitCostValue),
        deliveryDate: purchaseForm.purchaseDate,
        discountPercent: 0,
        note: item.note,
      })),
    })

    purchaseResult.value = {
      tone: 'success',
      text: response.data?.message || 'Inventory purchase order was posted successfully.',
    }
    await Swal.fire({
      title: 'Success',
      text: response.data?.message || 'Inventory purchase order was posted successfully.',
      icon: 'success',
      confirmButtonText: 'OK',
      confirmButtonColor: '#2563eb',
    })
    await loadInventory()
    resetPurchaseForm()
  } catch (error) {
    const responseErrors = error?.response?.data?.errors
    if (responseErrors && typeof responseErrors === 'object') {
      purchaseErrors.supplier = Array.isArray(responseErrors.supplier) ? responseErrors.supplier[0] : ''
      purchaseErrors.purchaseDate = Array.isArray(responseErrors.purchaseDate) ? responseErrors.purchaseDate[0] : ''
      purchaseErrors.paymentAccount = Array.isArray(responseErrors.paymentAccount) ? responseErrors.paymentAccount[0] : ''
      purchaseErrors.cartItems = Array.isArray(responseErrors.items) ? responseErrors.items[0] : ''
    }
    purchaseResult.value = {
      tone: 'error',
      text: error?.response?.data?.message || (error instanceof Error ? error.message : 'Failed to post the purchase order.'),
    }
  }
}

watch(
  () => detailForm.itemId,
  () => {
    detailErrors.itemId = ''
    detailForm.unit = selectedDetailItem.value?.unit ?? ''
    if (detailModalMode.value === 'create') {
      detailForm.unitCostValue = selectedDetailItem.value?.latestCostValue ? Number(selectedDetailItem.value.latestCostValue) : ''
    }
  },
)

watch(() => detailForm.quantity, () => {
  detailErrors.quantity = ''
})

watch(() => detailForm.unitCostValue, () => {
  detailErrors.unitCostValue = ''
})

watch(() => purchaseForm.supplier, () => {
  purchaseErrors.supplier = ''
})

watch(() => purchaseForm.purchaseDate, () => {
  purchaseErrors.purchaseDate = ''
})

watch(() => purchaseForm.paymentAccount, () => {
  purchaseErrors.paymentAccount = ''
})

onMounted(async () => {
  await Promise.all([loadDependencies(), loadInventory()])
  resetPurchaseForm()
  resetDetailForm()
})
</script>

<template>
  <section class="po-workspace panel-card panel-dense">
    <LoadingState v-if="loading" label="Loading inventory purchase POS..." overlay />

    <div class="po-hero">
      <div class="po-title-row">
        <div class="po-title-group">
          <RouterLink class="action-button" :to="{ name: 'inventory' }">Back</RouterLink>
          <div>
            <p class="eyebrow-dark">Inventory purchase desk</p>
            <h2>Purchase Order (PO)</h2>
          </div>
        </div>

        <div class="po-action-row">
          <button class="action-button primary" :disabled="!canSubmitPurchase" @click="submitPurchase">Post purchase</button>
          <button class="action-button" @click="resetPurchaseForm">Reset draft</button>
        </div>
      </div>

    </div>

    <div v-if="purchaseResult.text" class="booking-feedback" :class="purchaseResult.tone">
      {{ purchaseResult.text }}
    </div>

    <div class="po-form-shell">
      <div class="po-form-columns">
        <div class="po-column">
          <div class="po-form-row po-form-row-wide">
            <label>Supplier</label>
            <div>
              <input
                v-model="purchaseForm.supplier"
                class="form-control"
                :class="{ 'po-field-error': purchaseErrors.supplier }"
                placeholder="Select supplier"
              />
              <p v-if="purchaseErrors.supplier" class="po-error-text">{{ purchaseErrors.supplier }}</p>
            </div>
          </div>
          <div class="po-form-row po-form-row-wide">
            <label>Payment Account</label>
            <div :class="{ 'po-select-error': purchaseErrors.paymentAccount }">
              <Select2Field
                v-model="purchaseForm.paymentAccount"
                :options="inventoryAssetOptions"
                :multiple="false"
                placeholder="Select cash / bank account"
              />
              <p v-if="purchaseErrors.paymentAccount" class="po-error-text">{{ purchaseErrors.paymentAccount }}</p>
            </div>
          </div>
        </div>

        <div class="po-column">
          <div class="po-form-grid-2">
            <div class="po-form-row">
              <label>Date</label>
              <div>
                <input
                  v-model="purchaseForm.purchaseDate"
                  class="form-control"
                  :class="{ 'po-field-error': purchaseErrors.purchaseDate }"
                  type="date"
                />
                <p v-if="purchaseErrors.purchaseDate" class="po-error-text">{{ purchaseErrors.purchaseDate }}</p>
              </div>
            </div>
            <div class="po-form-row">
              <label>Transaction No.</label>
              <input :value="purchaseForm.transactionNo" class="form-control" readonly />
            </div>
          </div>
          <div class="po-form-row po-form-row-wide po-note-row">
            <label>Notes</label>
            <textarea
              v-model="purchaseForm.note"
              class="form-control form-textarea"
              placeholder="Supplier notes or receiving instructions"
            ></textarea>
          </div>
        </div>
      </div>
    </div>

    <div class="po-tab-strip">
      <button class="po-tab" :class="{ active: activeTab === 'detail' }" @click="activeTab = 'detail'">Detail</button>
      <button class="po-tab" :class="{ active: activeTab === 'info' }" @click="activeTab = 'info'">Info</button>
    </div>

    <template v-if="activeTab === 'detail'">
      <div class="po-detail-toolbar">
        <button class="action-button primary" @click="openCreateDetailModal">Add item line</button>
      </div>
      <p v-if="purchaseErrors.cartItems" class="po-error-text" style="margin-bottom: 10px;">{{ purchaseErrors.cartItems }}</p>

      <div class="po-grid-box">
        <div class="table-scroll po-table-scroll">
          <table class="data-table po-detail-table">
            <thead>
              <tr>
                <th>Item Code</th>
                <th>Item Name</th>
                <th>Quantity</th>
                <th>Unit</th>
                <th>Price</th>
                <th>Subtotal</th>
                <th>Notes</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!purchaseForm.cartItems.length">
                <td colspan="8" class="table-empty-cell">There are no item lines in this purchase order yet.</td>
              </tr>
              <tr
                v-for="item in purchaseForm.cartItems"
                :key="item.rowId"
                class="po-clickable-row"
                @click="openEditDetailModal(item)"
              >
                <td><strong>{{ item.itemCode }}</strong></td>
                <td>{{ item.itemName }}</td>
                <td>{{ item.quantity }}</td>
                <td>{{ item.unit }}</td>
                <td>{{ toCurrency(item.unitCostValue) }}</td>
                <td>{{ toCurrency(item.lineTotalValue) }}</td>
                <td>{{ item.note || '-' }}</td>
                <td>
                  <div class="po-row-actions">
                    <button class="action-button" @click.stop="openEditDetailModal(item)">Edit</button>
                    <button class="action-button" @click.stop="removeDetailLine(item.rowId)">Delete</button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="po-footer-grid">
        <div class="po-footer-column">
          <div class="po-total-row">
            <span>Total</span>
            <strong>{{ toCurrency(grossTotalValue) }}</strong>
          </div>
          <div class="po-total-row">
            <span>Extra Cost (%)</span>
            <input v-model="purchaseForm.extraCostPercent" class="form-control po-mini-input" min="0" step="0.01" type="number" />
          </div>
          <div class="po-total-row po-total-row-grand">
            <span>Transaction Total</span>
            <strong>{{ toCurrency(grandTotalValue) }}</strong>
          </div>
        </div>

        <div class="po-footer-column">
          <div class="po-total-row">
            <span>Total Extra Cost</span>
            <strong>{{ toCurrency(extraCostValue) }}</strong>
          </div>
          <div class="po-total-row">
            <span>Total Discount</span>
            <strong>{{ toCurrency(totalDiscountValue) }}</strong>
          </div>
        </div>
      </div>
    </template>

    <template v-else>
      <div class="po-info-grid">
        <div class="po-info-card">
          <h4>Transaction Information</h4>
          <div class="po-info-line"><span>Supplier</span><strong>{{ purchaseForm.supplier || '-' }}</strong></div>
          <div class="po-info-line"><span>Total Items</span><strong>{{ purchaseForm.cartItems.length }} line / {{ totalQuantity }} unit</strong></div>
          <div class="po-info-line"><span>Total</span><strong>{{ toCurrency(grandTotalValue) }}</strong></div>
          <div class="po-info-line"><span>Payment Account</span><strong>{{ purchaseForm.paymentAccount || '-' }}</strong></div>
          <div class="po-info-line"><span>Extra Cost</span><strong>{{ toCurrency(extraCostValue) }}</strong></div>
        </div>

        <div class="po-info-card">
          <h4>PO History</h4>
          <div class="table-scroll">
            <table v-smart-table class="data-table po-history-table">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Transaction no.</th>
                  <th>Supplier</th>
                  <th>Total</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="!inventoryPurchases.length">
                  <td colspan="4" class="table-empty-cell">No inventory purchase transactions have been posted yet.</td>
                </tr>
                <tr v-for="entry in inventoryPurchases.slice(0, 8)" :key="entry.id">
                  <td>{{ entry.purchaseDate }}</td>
                  <td><strong>{{ entry.transactionNo }}</strong></td>
                  <td>{{ entry.supplier }}</td>
                  <td>{{ entry.totalCost }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </template>
  </section>

  <div v-if="showDetailModal" class="modal-backdrop" @click.self="showDetailModal = false">
    <section class="modal-card po-detail-modal">
      <div class="po-modal-head">
        <h3>Transaction Detail</h3>
        <button class="po-modal-close" @click="showDetailModal = false">&times;</button>
      </div>

      <div class="po-modal-body">
        <div class="modal-actions" style="justify-content: flex-start; margin-bottom: 1rem;">
          <button class="action-button primary" @click="saveDetailLine">Save</button>
        </div>

        <div class="po-modal-grid">
          <div class="po-form-row po-form-row-wide">
            <label>Item Code</label>
            <div :class="{ 'po-select-error': detailErrors.itemId }">
              <Select2Field
                v-model="detailForm.itemId"
                :options="inventoryItemOptions"
                :multiple="false"
                placeholder="Select inventory item"
              />
              <p v-if="detailErrors.itemId" class="po-error-text">{{ detailErrors.itemId }}</p>
            </div>
          </div>
          <div class="po-paired-grid po-form-row-full">
            <div class="po-form-row">
              <label>Quantity</label>
              <div>
                <input
                  v-model="detailForm.quantity"
                  class="form-control"
                  :class="{ 'po-field-error': detailErrors.quantity }"
                  min="1"
                  type="number"
                />
                <p v-if="detailErrors.quantity" class="po-error-text">{{ detailErrors.quantity }}</p>
              </div>
            </div>
            <div class="po-form-row">
              <label>Price</label>
              <div>
                <input
                  v-model="detailUnitCostDisplay"
                  class="form-control po-input-align-right"
                  :class="{ 'po-field-error': detailErrors.unitCostValue }"
                  inputmode="numeric"
                  placeholder="0"
                  @focus="isDetailPriceFocused = true"
                  @blur="isDetailPriceFocused = false"
                />
                <p v-if="detailErrors.unitCostValue" class="po-error-text">{{ detailErrors.unitCostValue }}</p>
              </div>
            </div>
            <div class="po-form-row">
              <label>Unit</label>
              <input :value="detailForm.unit || '-'" class="form-control" readonly />
            </div>
            <div class="po-form-row">
              <label>Subtotal</label>
              <input :value="toCurrency(detailSubtotalValue)" class="form-control po-subtotal-input" readonly />
            </div>
          </div>

          <div class="po-form-row po-form-row-full">
            <label>Notes</label>
            <textarea v-model="detailForm.note" class="form-control form-textarea po-note-textarea" placeholder="Item notes"></textarea>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>

<style scoped>
.po-workspace {
  padding: 16px;
}

.po-hero {
  display: grid;
  gap: 14px;
  margin-bottom: 8px;
}

.po-title-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
  flex-wrap: wrap;
}

.po-title-group {
  display: flex;
  align-items: flex-start;
  gap: 14px;
}

.po-title-group h2 {
  margin: 2px 0 0;
  color: var(--blue-deep);
  font-size: 1.7rem;
  letter-spacing: -0.02em;
}

.po-hero-note {
  margin: 6px 0 0;
  color: var(--muted);
  font-size: 0.95rem;
  max-width: 680px;
}

.po-action-row {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.po-form-shell {
  border: 1px solid var(--line);
  border-radius: 14px;
  background: linear-gradient(180deg, #fbfdff 0%, #f3f7fb 100%);
  padding: 18px;
  box-shadow: 0 10px 26px rgba(22, 34, 44, 0.05);
}

.po-form-columns {
  display: grid;
  grid-template-columns: 1.2fr 0.8fr;
  gap: 18px;
}

.po-column {
  display: grid;
  gap: 10px;
}

.po-form-grid-2 {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px 14px;
}

.po-form-row {
  display: grid;
  grid-template-columns: 110px minmax(0, 1fr);
  gap: 10px;
  align-items: center;
}

.po-form-row label {
  color: var(--muted);
  font-size: 0.9rem;
  font-weight: 700;
}

.po-form-row-wide {
  grid-template-columns: 110px minmax(0, 1fr);
}

.po-note-row {
  align-items: start;
}

.po-tab-strip {
  display: flex;
  gap: 6px;
  align-items: end;
  margin-top: 14px;
}

.po-tab {
  border: 1px solid var(--line);
  background: var(--panel-soft);
  color: var(--muted);
  padding: 9px 16px;
  border-radius: 999px;
  cursor: pointer;
  font-weight: 700;
}

.po-tab.active {
  background: var(--sidebar);
  color: #ffffff;
  border-color: var(--sidebar-deep);
}

.po-detail-toolbar {
  padding: 12px 0 8px;
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
  flex-wrap: wrap;
}

.po-grid-box {
  border: 1px solid var(--line);
  border-radius: 12px;
  background: #ffffff;
  box-shadow: 0 8px 22px rgba(22, 34, 44, 0.05);
}

.po-table-scroll {
  min-height: 270px;
}

.po-detail-table th {
  background: var(--panel-soft);
  color: var(--blue-deep);
  font-size: 0.84rem;
  white-space: nowrap;
}

.po-detail-table td {
  font-size: 0.92rem;
}

.po-clickable-row {
  cursor: pointer;
}

.po-clickable-row:hover td {
  background: var(--panel-soft);
}

.po-row-actions {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}

.po-footer-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 24px;
  margin-top: 14px;
}

.po-footer-column {
  display: grid;
  gap: 8px;
  padding: 16px;
  border: 1px solid var(--line);
  border-radius: 12px;
  background: #fbfdff;
}

.po-total-row {
  display: grid;
  grid-template-columns: 130px minmax(0, 1fr);
  gap: 10px;
  align-items: center;
}

.po-total-row span {
  color: var(--muted);
  font-size: 0.9rem;
  font-weight: 700;
}

.po-total-row strong {
  display: block;
  text-align: right;
  padding: 10px 12px;
  border: 1px solid var(--line-strong);
  background: #ffffff;
  border-radius: 4px;
  color: var(--blue-deep);
}

.po-total-row-grand strong {
  color: var(--primary);
  font-size: 1rem;
}

.po-mini-input {
  text-align: right;
}

.po-info-grid {
  display: grid;
  grid-template-columns: 0.9fr 1.1fr;
  gap: 16px;
  margin-top: 14px;
}

.po-info-card {
  border: 1px solid var(--line);
  border-radius: 14px;
  background: linear-gradient(180deg, #fbfdff 0%, #f3f7fb 100%);
  padding: 16px;
  box-shadow: 0 10px 24px rgba(22, 34, 44, 0.05);
}

.po-info-card h4 {
  margin: 0 0 12px;
  color: var(--blue-deep);
}

.po-info-line {
  display: flex;
  justify-content: space-between;
  gap: 10px;
  padding: 8px 0;
  border-bottom: 1px solid var(--line);
}

.po-info-line:last-child {
  border-bottom: none;
}

.po-info-line span {
  color: var(--muted);
}

.po-history-table th {
  background: var(--panel-soft);
  color: var(--blue-deep);
}

.po-detail-modal {
  max-width: 920px;
  padding: 0;
  overflow: hidden;
}

.po-modal-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 16px;
  background: var(--sidebar);
  color: #ffffff;
}

.po-modal-head h3 {
  margin: 0;
  font-size: 1.1rem;
}

.po-modal-close {
  border: none;
  background: transparent;
  color: #ffffff;
  font-size: 1.7rem;
  line-height: 1;
  cursor: pointer;
}

.po-modal-body {
  padding: 14px 16px 18px;
  background: var(--panel-soft);
}

.po-modal-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 10px 16px;
}

.po-form-row-full {
  grid-column: 1 / -1;
}

.po-paired-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px 16px;
}

.po-input-align-right {
  text-align: right;
}

.po-field-error {
  border-color: #dc2626 !important;
  box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.12);
}

.po-select-error :deep(.select2-selection),
.po-select-error :deep(.selection .select2-selection) {
  border-color: #dc2626 !important;
  box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.12);
}

.po-error-text {
  margin: 6px 0 0;
  color: #dc2626;
  font-size: 0.8rem;
  font-weight: 600;
}

.po-subtotal-input {
  background: #ffffff;
  color: var(--primary);
  font-weight: 700;
}

.po-note-textarea {
  min-height: 92px;
}

@media (max-width: 980px) {
  .po-form-columns,
  .po-footer-grid,
  .po-info-grid,
  .po-modal-grid {
    grid-template-columns: 1fr;
  }

  .po-form-grid-2 {
    grid-template-columns: 1fr;
  }

  .po-paired-grid {
    grid-template-columns: 1fr;
  }

  .po-total-row,
  .po-form-row,
  .po-form-row-wide {
    grid-template-columns: 1fr;
  }
}
</style>

