<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import LoadingState from '../components/LoadingState.vue'
import Select2Field from '../components/Select2Field.vue'
import api from '../services/api'
import { useHotelStore } from '../stores/hotel'

const hotel = useHotelStore()

const inventoryResult = ref({ tone: '', text: '' })
const loading = ref(false)
const showItemModal = ref(false)
const showIssueModal = ref(false)
const editingItemId = ref(null)
const inventoryItems = ref([])
const inventoryPurchases = ref([])
const inventoryIssues = ref([])
const inventoryJournalEntries = ref([])
const roomRows = ref([])
const unitMasterRows = ref([])

const itemForm = reactive({
  name: '',
  category: 'Amenity',
  unit: 'pcs',
  trackingType: 'Consumable',
  inventoryCoa: '',
  expenseCoa: '',
  reorderLevel: 0,
})

const issueForm = reactive({
  issueDate: new Date().toISOString().slice(0, 10),
  roomNo: '',
  itemId: '',
  quantity: 1,
  note: '',
})

const inventoryAssetOptions = computed(() =>
  hotel.coaList
    .filter((item) => item.category === 'Asset')
    .map((item) => ({ value: `${item.code} - ${item.name}`, label: `${item.code} - ${item.name}` })),
)

const inventoryExpenseOptions = computed(() =>
  hotel.coaList
    .filter((item) => item.category === 'Expense')
    .map((item) => ({ value: `${item.code} - ${item.name}`, label: `${item.code} - ${item.name}` })),
)

const inventoryItemOptions = computed(() =>
  inventoryItems.value.map((item) => ({
    value: item.id,
    label: `${item.name} | ${item.category} | Stock ${item.onHandQty} ${item.unit}`,
  })),
)

const unitMasterOptions = computed(() =>
  unitMasterRows.value.map((item) => ({
    value: item.name,
    label: item.name,
  })),
)

const roomOptions = computed(() => {
  if (roomRows.value.length) {
    return roomRows.value.map((room) => ({
      value: room.code,
      label: `${room.code} | ${room.name}`,
    }))
  }

  return hotel.roomMasterList.map((room) => ({
    value: room.code,
    label: `${room.code} | ${room.name}`,
  }))
})

const lowStockCount = computed(() =>
  inventoryItems.value.filter((item) => item.onHandQty <= item.reorderLevel).length,
)
const consumableIssueCount = computed(() =>
  inventoryIssues.value.filter((item) => item.trackingType === 'Consumable').length,
)
const linenIssueCount = computed(() =>
  inventoryIssues.value.filter((item) => item.trackingType === 'Linen').length,
)
const purchaseTransactionCount = computed(() => inventoryPurchases.value.length)

const resetItemForm = () => {
  itemForm.name = ''
  itemForm.category = 'Amenity'
  itemForm.unit = unitMasterOptions.value[0]?.value ?? 'pcs'
  itemForm.trackingType = 'Consumable'
  itemForm.inventoryCoa = inventoryAssetOptions.value[0]?.value ?? ''
  itemForm.expenseCoa = inventoryExpenseOptions.value[0]?.value ?? ''
  itemForm.reorderLevel = 0
}

const itemModalTitle = computed(() => (editingItemId.value ? 'Edit item master' : 'Add item master'))
const itemSubmitLabel = computed(() => (editingItemId.value ? 'Update item' : 'Save item'))

const resetIssueForm = () => {
  issueForm.issueDate = new Date().toISOString().slice(0, 10)
  issueForm.roomNo = roomOptions.value[0]?.value ?? ''
  issueForm.itemId = inventoryItemOptions.value[0]?.value ?? ''
  issueForm.quantity = 1
  issueForm.note = ''
}

const openItemModal = () => {
  inventoryResult.value = { tone: '', text: '' }
  editingItemId.value = null
  resetItemForm()
  showItemModal.value = true
}

const openEditItemModal = (item) => {
  inventoryResult.value = { tone: '', text: '' }
  editingItemId.value = item.id
  itemForm.name = item.name ?? ''
  itemForm.category = item.category ?? 'Amenity'
  itemForm.unit = item.unit ?? 'pcs'
  itemForm.trackingType = item.trackingType ?? (String(item.category ?? '').toLowerCase() === 'linen' ? 'Linen' : 'Consumable')
  itemForm.inventoryCoa = item.inventoryCoa ?? inventoryAssetOptions.value[0]?.value ?? ''
  itemForm.expenseCoa = item.expenseCoa ?? inventoryExpenseOptions.value[0]?.value ?? ''
  itemForm.reorderLevel = Number(item.reorderLevel ?? 0)
  showItemModal.value = true
}

const openIssueModal = () => {
  inventoryResult.value = { tone: '', text: '' }
  resetIssueForm()
  showIssueModal.value = true
}

const loadDependencies = async () => {
  try {
    const [coaResponse, roomResponse, unitResponse] = await Promise.all([
      api.get('/coa-accounts', { params: { per_page: 500 } }),
      api.get('/rooms', { params: { per_page: 200 } }),
      api.get('/master-units'),
    ])

    const coaRows = Array.isArray(coaResponse.data?.data) ? coaResponse.data.data : []
    if (coaRows.length) {
      hotel.setCoaAccounts(coaRows)
    }

    roomRows.value = Array.isArray(roomResponse.data?.data) ? roomResponse.data.data : []
    unitMasterRows.value = Array.isArray(unitResponse.data?.data) ? unitResponse.data.data : []
  } catch (error) {
    roomRows.value = []
    unitMasterRows.value = []
  }
}

const loadInventory = async () => {
  loading.value = true
  inventoryResult.value = { tone: '', text: '' }

  try {
    const response = await api.get('/inventory')
    const data = response.data?.data ?? {}

    inventoryItems.value = Array.isArray(data.items) ? data.items : []
    inventoryPurchases.value = Array.isArray(data.purchases) ? data.purchases : []
    inventoryIssues.value = Array.isArray(data.issues) ? data.issues : []
    inventoryJournalEntries.value = Array.isArray(data.journalEntries) ? data.journalEntries : []
    hotel.setInventorySnapshot({
      items: inventoryItems.value,
      purchases: inventoryPurchases.value,
      issues: inventoryIssues.value,
    })
  } catch (error) {
    inventoryResult.value = {
      tone: 'error',
      text: error?.response?.data?.message || error?.message || 'Failed to load inventory data from the database.',
    }
    inventoryItems.value = []
    inventoryPurchases.value = []
    inventoryIssues.value = []
    inventoryJournalEntries.value = []
    hotel.setInventorySnapshot()
  } finally {
    loading.value = false
  }
}

const submitItem = async () => {
  inventoryResult.value = { tone: '', text: '' }

  try {
    const isEditing = Boolean(editingItemId.value)
    const response = isEditing
      ? await api.put(`/inventory/items/${editingItemId.value}`, { ...itemForm })
      : await api.post('/inventory/items', { ...itemForm })
    inventoryResult.value = {
      tone: 'success',
      text: response.data?.message || (isEditing
        ? `Item ${itemForm.name} was updated successfully.`
        : `Item ${itemForm.name} was added to the inventory master successfully.`),
    }
    editingItemId.value = null
    showItemModal.value = false
    await loadInventory()
  } catch (error) {
    inventoryResult.value = {
      tone: 'error',
      text: error?.response?.data?.message || (error instanceof Error ? error.message : 'Failed to add the inventory item.'),
    }
  }
}

const submitIssue = async () => {
  inventoryResult.value = { tone: '', text: '' }

  try {
    const response = await api.post('/inventory/issues', {
      ...issueForm,
      quantity: Number(issueForm.quantity),
    })
    inventoryResult.value = {
      tone: 'success',
      text: response.data?.message || `Item issue to room ${issueForm.roomNo} was posted successfully.`,
    }
    showIssueModal.value = false
    await loadInventory()
  } catch (error) {
    inventoryResult.value = {
      tone: 'error',
      text: error?.response?.data?.message || (error instanceof Error ? error.message : 'Failed to post the room issue.'),
    }
  }
}

onMounted(async () => {
  await Promise.all([loadDependencies(), loadInventory()])
})
</script>

<template>
  <section class="page-grid">
    <article class="panel-card panel-dense">
      <LoadingState v-if="loading" label="Loading inventory from the database..." overlay />
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Stock card</p>
          <h3>Inventory item master</h3>
        </div>
        <div style="display: flex; gap: 0.75rem; align-items: center;">
          <span class="status-badge warning">{{ lowStockCount }} alert</span>
          <button class="action-button primary" @click="openItemModal">Add item</button>
        </div>
      </div>

      <div v-if="inventoryResult.text" class="booking-feedback" :class="inventoryResult.tone">
        {{ inventoryResult.text }}
      </div>

      <div class="table-scroll">
        <table v-smart-table class="data-table">
          <thead>
            <tr>
              <th>Item</th>
              <th>Category</th>
              <th>Tracking type</th>
              <th>Purchased</th>
              <th>Issued</th>
              <th>Available stock</th>
              <th>Last cost</th>
              <th>Inventory COA</th>
              <th>Expense COA</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in inventoryItems" :key="item.id">
              <td><strong>{{ item.name }}</strong></td>
              <td>{{ item.category }}</td>
              <td>{{ item.trackingType }}</td>
              <td>{{ item.purchasedQty }} {{ item.unit }}</td>
              <td>{{ item.issuedQty }} {{ item.unit }}</td>
              <td>{{ item.onHandQty }} {{ item.unit }}</td>
              <td>{{ item.latestCost }}</td>
              <td>{{ item.inventoryCoa }}</td>
              <td>{{ item.expenseCoa }}</td>
              <td>
                <button class="action-button" @click="openEditItemModal(item)">Edit</button>
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
          <p class="eyebrow-dark">Room issue</p>
          <h3>Distribute items to rooms</h3>
        </div>
        <button class="action-button primary" @click="openIssueModal">Issue item</button>
      </div>

      <div class="table-scroll">
        <table v-smart-table class="data-table inventory-log-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Room</th>
              <th>Item</th>
              <th>Qty</th>
              <th>Tracking</th>
              <th>Accounting</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!inventoryIssues.length">
              <td colspan="6" class="table-empty-cell">No room item issues have been posted yet.</td>
            </tr>
            <tr v-for="entry in inventoryIssues" :key="entry.id">
              <td>{{ entry.issueDate }}</td>
              <td><strong>{{ entry.roomNo }}</strong></td>
              <td>{{ entry.itemName }}</td>
              <td>{{ entry.quantity }} {{ entry.unit }}</td>
              <td>{{ entry.trackingType }}</td>
              <td>{{ entry.trackingType === 'Consumable' ? entry.totalValueLabel : 'Internal movement' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </article>

    <article class="panel-card panel-dense">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Latest purchase transactions</p>
          <h3>Summary from the POS page</h3>
        </div>
        <RouterLink class="action-button primary" :to="{ name: 'inventory-purchases' }">Manage transactions</RouterLink>
      </div>

      <div class="table-scroll">
        <table v-smart-table class="data-table inventory-log-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Transaction no.</th>
              <th>Supplier</th>
              <th>Item summary</th>
              <th>Total</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!inventoryPurchases.length">
              <td colspan="5" class="table-empty-cell">No inventory purchase transactions have been posted yet.</td>
            </tr>
            <tr v-for="entry in inventoryPurchases.slice(0, 8)" :key="entry.id">
              <td>{{ entry.purchaseDate }}</td>
              <td><strong>{{ entry.transactionNo }}</strong></td>
              <td>{{ entry.supplier }}</td>
              <td>{{ entry.itemSummary }}</td>
              <td>{{ entry.totalCost }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </article>
  </section>

  <section class="page-grid">
    <article class="panel-card panel-dense">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Journal preview</p>
          <h3>Accounting impact of inventory</h3>
        </div>
        <span class="status-badge info">{{ inventoryJournalEntries.length }} journal lines</span>
      </div>

      <div class="table-scroll">
        <table v-smart-table class="data-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Source</th>
              <th>Type</th>
              <th>Account</th>
              <th>Position</th>
              <th>Amount</th>
              <th>Memo</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="entry in inventoryJournalEntries" :key="entry.id">
              <td>{{ entry.entryDate }}</td>
              <td><strong>{{ entry.source }}</strong></td>
              <td>{{ entry.transactionType }}</td>
              <td>{{ entry.account }}</td>
              <td>{{ entry.position }}</td>
              <td>{{ entry.amount }}</td>
              <td>{{ entry.memo }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </article>
  </section>

  <div v-if="showItemModal" class="modal-backdrop" @click.self="showItemModal = false">
    <section class="modal-card">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Inventory item</p>
          <h3>{{ itemModalTitle }}</h3>
        </div>
        <button class="action-button" @click="showItemModal = false; editingItemId = null">Close</button>
      </div>

      <div class="booking-form-grid">
        <label class="field-stack">
          <span>Item name</span>
          <input v-model="itemForm.name" class="form-control" placeholder="Example: Soap 30ml" />
        </label>

        <label class="field-stack">
          <span>Category</span>
          <select v-model="itemForm.category" class="form-control">
            <option value="Amenity">Amenity</option>
            <option value="Linen">Linen</option>
            <option value="Cleaning supply">Cleaning supply</option>
          </select>
        </label>

        <label class="field-stack">
          <span>Unit</span>
          <Select2Field
            v-model="itemForm.unit"
            :options="unitMasterOptions"
            :multiple="false"
            placeholder="Select unit"
          />
        </label>

        <label class="field-stack">
          <span>Tracking type</span>
          <select v-model="itemForm.trackingType" class="form-control">
            <option value="Consumable">Consumable</option>
            <option value="Linen">Linen</option>
          </select>
        </label>

        <label class="field-stack field-span-2">
          <span>Inventory COA</span>
          <Select2Field
            v-model="itemForm.inventoryCoa"
            :options="inventoryAssetOptions"
            :multiple="false"
            placeholder="Select inventory account"
          />
        </label>

        <label class="field-stack field-span-2">
          <span>Expense COA</span>
          <Select2Field
            v-model="itemForm.expenseCoa"
            :options="inventoryExpenseOptions"
            :multiple="false"
            placeholder="Select expense account"
          />
        </label>

        <label class="field-stack">
          <span>Reorder level</span>
          <input v-model="itemForm.reorderLevel" class="form-control" min="0" type="number" />
        </label>
      </div>

      <div class="modal-actions">
        <button class="action-button" @click="showItemModal = false; editingItemId = null">Cancel</button>
        <button class="action-button primary" @click="submitItem">{{ itemSubmitLabel }}</button>
      </div>
    </section>
  </div>

  <div v-if="showIssueModal" class="modal-backdrop" @click.self="showIssueModal = false">
    <section class="modal-card">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Room issue</p>
          <h3>Issue items to room</h3>
        </div>
        <button class="action-button" @click="showIssueModal = false">Close</button>
      </div>

      <div class="booking-form-grid">
        <label class="field-stack">
          <span>Issue date</span>
          <input v-model="issueForm.issueDate" class="form-control" type="date" />
        </label>

        <label class="field-stack">
          <span>Room</span>
          <Select2Field
            v-model="issueForm.roomNo"
            :options="roomOptions"
            :multiple="false"
            placeholder="Select room"
          />
        </label>

        <label class="field-stack field-span-2">
          <span>Item</span>
          <Select2Field
            v-model="issueForm.itemId"
            :options="inventoryItemOptions"
            :multiple="false"
            placeholder="Select inventory item"
          />
        </label>

        <label class="field-stack">
          <span>Quantity</span>
          <input v-model="issueForm.quantity" class="form-control" min="1" type="number" />
        </label>

        <label class="field-stack field-span-2">
          <span>Notes</span>
          <textarea
            v-model="issueForm.note"
            class="form-control form-textarea"
            placeholder="Example: soap for arrival setup or replacement towels"
          ></textarea>
        </label>
      </div>

      <div class="modal-actions">
        <button class="action-button" @click="showIssueModal = false">Cancel</button>
        <button class="action-button primary" @click="submitIssue">Post issue to room</button>
      </div>
    </section>
  </div>
</template>
