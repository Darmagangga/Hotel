<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import LoadingState from '../components/LoadingState.vue'
import Select2Field from '../components/Select2Field.vue'
import api from '../services/api'
import { useHotelStore } from '../stores/hotel'

const hotel = useHotelStore()

const inventoryResult = ref({ tone: '', text: '' })
const loading = ref(false)
const showItemModal = ref(false)
const showPurchaseModal = ref(false)
const showIssueModal = ref(false)
const inventoryItems = ref([])
const inventoryPurchases = ref([])
const inventoryIssues = ref([])
const inventoryJournalEntries = ref([])
const roomRows = ref([])

const itemForm = reactive({
  name: '',
  category: 'Amenity',
  unit: 'pcs',
  trackingType: 'Consumable',
  inventoryCoa: '',
  expenseCoa: '',
  reorderLevel: 0,
})

const purchaseForm = reactive({
  purchaseDate: new Date().toISOString().slice(0, 10),
  supplier: '',
  itemId: '',
  quantity: 1,
  unitCostValue: '',
  paymentAccount: '',
  note: '',
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

const resetItemForm = () => {
  itemForm.name = ''
  itemForm.category = 'Amenity'
  itemForm.unit = 'pcs'
  itemForm.trackingType = 'Consumable'
  itemForm.inventoryCoa = inventoryAssetOptions.value[0]?.value ?? ''
  itemForm.expenseCoa = inventoryExpenseOptions.value[0]?.value ?? ''
  itemForm.reorderLevel = 0
}

const resetPurchaseForm = () => {
  purchaseForm.purchaseDate = new Date().toISOString().slice(0, 10)
  purchaseForm.supplier = ''
  purchaseForm.itemId = inventoryItemOptions.value[0]?.value ?? ''
  purchaseForm.quantity = 1
  purchaseForm.unitCostValue = ''
  purchaseForm.paymentAccount = inventoryAssetOptions.value[0]?.value ?? ''
  purchaseForm.note = ''
}

const resetIssueForm = () => {
  issueForm.issueDate = new Date().toISOString().slice(0, 10)
  issueForm.roomNo = roomOptions.value[0]?.value ?? ''
  issueForm.itemId = inventoryItemOptions.value[0]?.value ?? ''
  issueForm.quantity = 1
  issueForm.note = ''
}

const openItemModal = () => {
  inventoryResult.value = { tone: '', text: '' }
  resetItemForm()
  showItemModal.value = true
}

const openPurchaseModal = () => {
  inventoryResult.value = { tone: '', text: '' }
  resetPurchaseForm()
  showPurchaseModal.value = true
}

const openIssueModal = () => {
  inventoryResult.value = { tone: '', text: '' }
  resetIssueForm()
  showIssueModal.value = true
}

const loadDependencies = async () => {
  try {
    const [coaResponse, roomResponse] = await Promise.all([
      api.get('/coa-accounts', { params: { per_page: 500 } }),
      api.get('/rooms', { params: { per_page: 200 } }),
    ])

    const coaRows = Array.isArray(coaResponse.data?.data) ? coaResponse.data.data : []
    if (coaRows.length) {
      hotel.setCoaAccounts(coaRows)
    }

    roomRows.value = Array.isArray(roomResponse.data?.data) ? roomResponse.data.data : []
  } catch (error) {
    roomRows.value = []
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
      text: error?.response?.data?.message || error?.message || 'Gagal memuat data inventory dari database.',
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
    const response = await api.post('/inventory/items', { ...itemForm })
    inventoryResult.value = {
      tone: 'success',
      text: response.data?.message || `Item ${itemForm.name} berhasil ditambahkan ke inventory master.`,
    }
    showItemModal.value = false
    await loadInventory()
  } catch (error) {
    inventoryResult.value = {
      tone: 'error',
      text: error?.response?.data?.message || (error instanceof Error ? error.message : 'Gagal menambah item inventory.'),
    }
  }
}

const submitPurchase = async () => {
  inventoryResult.value = { tone: '', text: '' }

  try {
    const response = await api.post('/inventory/purchases', {
      ...purchaseForm,
      quantity: Number(purchaseForm.quantity),
      unitCostValue: Number(purchaseForm.unitCostValue),
    })
    inventoryResult.value = {
      tone: 'success',
      text: response.data?.message || 'Pembelian inventory berhasil diposting.',
    }
    showPurchaseModal.value = false
    await loadInventory()
  } catch (error) {
    inventoryResult.value = {
      tone: 'error',
      text: error?.response?.data?.message || (error instanceof Error ? error.message : 'Gagal memposting pembelian.'),
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
      text: response.data?.message || `Issue item ke kamar ${issueForm.roomNo} berhasil diposting.`,
    }
    showIssueModal.value = false
    await loadInventory()
  } catch (error) {
    inventoryResult.value = {
      tone: 'error',
      text: error?.response?.data?.message || (error instanceof Error ? error.message : 'Gagal issue item ke kamar.'),
    }
  }
}

onMounted(async () => {
  await Promise.all([loadDependencies(), loadInventory()])
})
</script>

<template>
  <section class="page-grid two">
    <article class="panel-card panel-dense">
      <LoadingState v-if="loading" label="Memuat inventory dari database..." overlay />
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Kontrol persediaan</p>
          <h3>Pembelian, stok, dan issue ke kamar</h3>
        </div>
        <span class="status-badge info">{{ inventoryItems.length }} item aktif</span>
      </div>

      <div class="booking-inline-summary">
        <div class="note-cell">
          <strong>Stok menipis</strong>
          <p class="subtle">{{ lowStockCount }} item di bawah batas reorder</p>
        </div>
        <div class="note-cell">
          <strong>Issue consumable</strong>
          <p class="subtle">{{ consumableIssueCount }} posting ke biaya</p>
        </div>
        <div class="note-cell">
          <strong>Issue linen</strong>
          <p class="subtle">{{ linenIssueCount }} perpindahan internal</p>
        </div>
      </div>

      <div class="modal-actions">
        <button class="action-button" @click="openItemModal">Tambah item</button>
        <button class="action-button primary" @click="openPurchaseModal">Pembelian baru</button>
        <button class="action-button primary" @click="openIssueModal">Issue ke kamar</button>
      </div>
    </article>

    <article class="panel-card panel-dense">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Metode akuntansi</p>
          <h3>Cara posting dibuat</h3>
        </div>
        <span class="status-badge success">Terhubung ke persediaan</span>
      </div>

      <div class="compact-list">
        <div class="list-row list-row-tight">
          <strong>Purchase</strong>
          <p class="subtle">Dr Persediaan, Cr Kas / Bank / supplier payment account.</p>
        </div>
        <div class="list-row list-row-tight">
          <strong>Issue consumable</strong>
          <p class="subtle">Dr Biaya amenity / room supply, Cr Persediaan saat dipakai ke kamar.</p>
        </div>
        <div class="list-row list-row-tight">
          <strong>Issue linen</strong>
          <p class="subtle">Dipindahkan ke kamar sebagai internal assignment, belum langsung jadi expense.</p>
        </div>
      </div>
    </article>
  </section>

  <section class="page-grid">
    <article class="panel-card panel-dense">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Kartu stok</p>
          <h3>Master item persediaan</h3>
        </div>
        <span class="status-badge warning">{{ lowStockCount }} alert</span>
      </div>

      <div v-if="inventoryResult.text" class="booking-feedback" :class="inventoryResult.tone">
        {{ inventoryResult.text }}
      </div>

      <table class="data-table">
        <thead>
          <tr>
            <th>Item</th>
            <th>Kategori</th>
            <th>Tipe tracking</th>
            <th>Dibeli</th>
            <th>Di-issue</th>
            <th>Stok tersedia</th>
            <th>COA persediaan</th>
            <th>COA biaya</th>
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
            <td>{{ item.inventoryCoa }}</td>
            <td>{{ item.expenseCoa }}</td>
          </tr>
        </tbody>
      </table>
    </article>
  </section>

  <section class="page-grid two">
    <article class="panel-card panel-dense">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Pembelian</p>
          <h3>Log pembelian persediaan</h3>
        </div>
        <button class="action-button primary" @click="openPurchaseModal">Tambah pembelian</button>
      </div>

      <table class="data-table">
        <thead>
          <tr>
            <th>Tanggal</th>
            <th>Supplier</th>
            <th>Item</th>
            <th>Qty</th>
            <th>Total</th>
            <th>Akun pembayaran</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="entry in inventoryPurchases" :key="entry.id">
            <td>{{ entry.purchaseDate }}</td>
            <td><strong>{{ entry.supplier }}</strong></td>
            <td>{{ entry.itemName }}</td>
            <td>{{ entry.quantity }}</td>
            <td>{{ entry.totalCost }}</td>
            <td>{{ entry.paymentAccount }}</td>
          </tr>
        </tbody>
      </table>
    </article>

    <article class="panel-card panel-dense">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Issue kamar</p>
          <h3>Distribusi item ke kamar</h3>
        </div>
        <button class="action-button primary" @click="openIssueModal">Issue item</button>
      </div>

      <table class="data-table">
        <thead>
          <tr>
            <th>Tanggal</th>
            <th>Room</th>
            <th>Item</th>
            <th>Qty</th>
            <th>Tracking</th>
            <th>Akuntansi</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="entry in inventoryIssues" :key="entry.id">
            <td>{{ entry.issueDate }}</td>
            <td><strong>{{ entry.roomNo }}</strong></td>
            <td>{{ entry.itemName }}</td>
            <td>{{ entry.quantity }} {{ entry.unit }}</td>
            <td>{{ entry.trackingType }}</td>
            <td>{{ entry.trackingType === 'Consumable' ? entry.totalValueLabel : 'Mutasi internal' }}</td>
          </tr>
        </tbody>
      </table>
    </article>
  </section>

  <section class="page-grid">
    <article class="panel-card panel-dense">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Pratinjau jurnal</p>
          <h3>Dampak akuntansi dari persediaan</h3>
        </div>
        <span class="status-badge info">{{ inventoryJournalEntries.length }} baris jurnal</span>
      </div>

      <table class="data-table">
        <thead>
          <tr>
            <th>Tanggal</th>
            <th>Source</th>
            <th>Tipe</th>
            <th>Akun</th>
            <th>Posisi</th>
            <th>Nominal</th>
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
    </article>
  </section>

  <div v-if="showItemModal" class="modal-backdrop" @click.self="showItemModal = false">
    <section class="modal-card">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Item persediaan</p>
          <h3>Tambah master item</h3>
        </div>
        <button class="action-button" @click="showItemModal = false">Tutup</button>
      </div>

      <div class="booking-form-grid">
        <label class="field-stack">
          <span>Nama item</span>
          <input v-model="itemForm.name" class="form-control" placeholder="Contoh: Sabun 30ml" />
        </label>

        <label class="field-stack">
          <span>Kategori</span>
          <select v-model="itemForm.category" class="form-control">
            <option value="Amenity">Amenity</option>
            <option value="Linen">Linen</option>
            <option value="Cleaning supply">Perlengkapan kebersihan</option>
          </select>
        </label>

        <label class="field-stack">
          <span>Satuan</span>
          <input v-model="itemForm.unit" class="form-control" placeholder="pcs" />
        </label>

        <label class="field-stack">
          <span>Tipe tracking</span>
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
            placeholder="Pilih akun persediaan"
          />
        </label>

        <label class="field-stack field-span-2">
          <span>Expense COA</span>
          <Select2Field
            v-model="itemForm.expenseCoa"
            :options="inventoryExpenseOptions"
            :multiple="false"
            placeholder="Pilih akun biaya"
          />
        </label>

        <label class="field-stack">
          <span>Batas reorder</span>
          <input v-model="itemForm.reorderLevel" class="form-control" min="0" type="number" />
        </label>
      </div>

      <div class="modal-actions">
        <button class="action-button" @click="showItemModal = false">Batal</button>
        <button class="action-button primary" @click="submitItem">Simpan item</button>
      </div>
    </section>
  </div>

  <div v-if="showPurchaseModal" class="modal-backdrop" @click.self="showPurchaseModal = false">
    <section class="modal-card">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Pembelian persediaan</p>
          <h3>Tambah pembelian barang</h3>
        </div>
        <button class="action-button" @click="showPurchaseModal = false">Tutup</button>
      </div>

      <div class="booking-form-grid">
        <label class="field-stack">
          <span>Tanggal pembelian</span>
          <input v-model="purchaseForm.purchaseDate" class="form-control" type="date" />
        </label>

        <label class="field-stack">
          <span>Supplier</span>
          <input v-model="purchaseForm.supplier" class="form-control" placeholder="Nama supplier" />
        </label>

        <label class="field-stack field-span-2">
          <span>Item</span>
          <Select2Field
            v-model="purchaseForm.itemId"
            :options="inventoryItemOptions"
            :multiple="false"
            placeholder="Pilih item inventory"
          />
        </label>

        <label class="field-stack">
          <span>Kuantitas</span>
          <input v-model="purchaseForm.quantity" class="form-control" min="1" type="number" />
        </label>

        <label class="field-stack">
          <span>Harga satuan</span>
          <input v-model="purchaseForm.unitCostValue" class="form-control" min="0" type="number" />
        </label>

        <label class="field-stack field-span-2">
          <span>Akun pembayaran</span>
          <Select2Field
            v-model="purchaseForm.paymentAccount"
            :options="inventoryAssetOptions"
            :multiple="false"
            placeholder="Pilih akun kas / bank"
          />
        </label>

        <label class="field-stack field-span-2">
          <span>Catatan</span>
          <textarea
            v-model="purchaseForm.note"
            class="form-control form-textarea"
            placeholder="Contoh: restock sabun untuk 1 minggu operasional"
          ></textarea>
        </label>
      </div>

      <div class="modal-actions">
        <button class="action-button" @click="showPurchaseModal = false">Batal</button>
        <button class="action-button primary" @click="submitPurchase">Posting pembelian</button>
      </div>
    </section>
  </div>

  <div v-if="showIssueModal" class="modal-backdrop" @click.self="showIssueModal = false">
    <section class="modal-card">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Issue kamar</p>
          <h3>Issue barang ke kamar</h3>
        </div>
        <button class="action-button" @click="showIssueModal = false">Tutup</button>
      </div>

      <div class="booking-form-grid">
        <label class="field-stack">
          <span>Tanggal issue</span>
          <input v-model="issueForm.issueDate" class="form-control" type="date" />
        </label>

        <label class="field-stack">
          <span>Kamar</span>
          <Select2Field
            v-model="issueForm.roomNo"
            :options="roomOptions"
            :multiple="false"
            placeholder="Pilih kamar"
          />
        </label>

        <label class="field-stack field-span-2">
          <span>Item</span>
          <Select2Field
            v-model="issueForm.itemId"
            :options="inventoryItemOptions"
            :multiple="false"
            placeholder="Pilih item inventory"
          />
        </label>

        <label class="field-stack">
          <span>Kuantitas</span>
          <input v-model="issueForm.quantity" class="form-control" min="1" type="number" />
        </label>

        <label class="field-stack field-span-2">
          <span>Catatan</span>
          <textarea
            v-model="issueForm.note"
            class="form-control form-textarea"
            placeholder="Contoh: sabun untuk arrival setup atau handuk pengganti"
          ></textarea>
        </label>
      </div>

      <div class="modal-actions">
        <button class="action-button" @click="showIssueModal = false">Batal</button>
        <button class="action-button primary" @click="submitIssue">Issue ke kamar</button>
      </div>
    </section>
  </div>
</template>
