<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import LoadingState from '../components/LoadingState.vue'
import api from '../services/api'
import { useHotelStore } from '../stores/hotel'

const hotel = useHotelStore()

const search = ref('')
const filterCategory = ref('All')
const modalMode = ref('create')
const editingCode = ref('')
const showModal = ref(false)
const formResult = ref({ tone: '', text: '' })
const loading = ref(false)
const saving = ref(false)
const sourceLabel = ref('Database')
const pagination = reactive({
  page: 1,
  perPage: 15,
  total: 0,
  lastPage: 1,
})
const accounts = ref([])

const categoryOptions = ['All', 'Asset', 'Liability', 'Equity', 'Revenue', 'Expense']
const normalBalanceOptions = ['Debit', 'Credit']
const categoryLabels = {
  All: 'All',
  Asset: 'Asset',
  Liability: 'Liability',
  Equity: 'Equity',
  Revenue: 'Revenue',
  Expense: 'Expense',
}
const normalBalanceLabels = {
  Debit: 'Debit',
  Credit: 'Credit',
}

const coaForm = reactive({
  code: '',
  name: '',
  category: 'Asset',
  normalBalance: 'Debit',
  note: '',
  active: true,
})

const filteredAccounts = computed(() => {
  return accounts.value
})

const categorySummary = computed(() =>
  categoryOptions
    .filter((item) => item !== 'All')
    .map((item) => ({
      label: item,
      count: hotel.coaList.filter((account) => account.category === item).length,
    })),
)

const loadCoaAccounts = async () => {
  loading.value = true

  try {
    const response = await api.get('/coa-accounts', {
      params: {
        page: pagination.page,
        per_page: pagination.perPage,
        search: search.value.trim() || undefined,
        category: filterCategory.value === 'All' ? undefined : filterCategory.value,
      },
    })
    const rows = Array.isArray(response.data?.data) ? response.data.data : []
    const meta = response.data?.meta ?? {}

    hotel.setCoaAccounts(rows)
    pagination.total = Number(meta.total ?? rows.length)
    pagination.lastPage = Number(meta.last_page ?? 1)
    pagination.page = Number(meta.current_page ?? 1)
    accounts.value = rows

    sourceLabel.value = 'Database'
    formResult.value = { tone: '', text: '' }
  } catch (error) {
    sourceLabel.value = 'Local store'
    accounts.value = hotel.coaList
    formResult.value = {
      tone: 'error',
      text: 'Failed to load COA from the database. Showing temporary local data.',
    }
  } finally {
    loading.value = false
  }
}

const resetForm = () => {
  coaForm.code = ''
  coaForm.name = ''
  coaForm.category = 'Asset'
  coaForm.normalBalance = 'Debit'
  coaForm.note = ''
  coaForm.active = true
}

const openCreateModal = () => {
  modalMode.value = 'create'
  editingCode.value = ''
  formResult.value = { tone: '', text: '' }
  resetForm()
  showModal.value = true
}

const openEditModal = (account) => {
  modalMode.value = 'edit'
  editingCode.value = account.code
  formResult.value = { tone: '', text: '' }
  coaForm.code = account.code
  coaForm.name = account.name
  coaForm.category = account.category
  coaForm.normalBalance = account.normalBalance
  coaForm.note = account.note
  coaForm.active = account.active
  showModal.value = true
}

const closeModal = () => {
  showModal.value = false
}

const submitForm = async () => {
  formResult.value = { tone: '', text: '' }
  saving.value = true

  try {
    if (modalMode.value === 'create') {
      const response = await api.post('/coa-accounts', { ...coaForm })
      formResult.value = { tone: 'success', text: response.data?.message ?? `COA ${coaForm.code} was added successfully.` }
    } else {
      const response = await api.put(`/coa-accounts/${editingCode.value}`, { ...coaForm })
      formResult.value = { tone: 'success', text: response.data?.message ?? `COA ${editingCode.value} was updated successfully.` }
    }

    showModal.value = false
    await loadCoaAccounts()
  } catch (error) {
    formResult.value = {
      tone: 'error',
      text: error?.response?.data?.message ?? (error instanceof Error ? error.message : 'Failed to save COA.'),
    }
  } finally {
    saving.value = false
  }
}

const goToPage = async (page) => {
  if (page < 1 || page > pagination.lastPage || page === pagination.page) {
    return
  }

  pagination.page = page
  await loadCoaAccounts()
}

let searchTimer = null

watch(filterCategory, async () => {
  pagination.page = 1
  await loadCoaAccounts()
})

watch(search, () => {
  pagination.page = 1
  window.clearTimeout(searchTimer)
  searchTimer = window.setTimeout(() => {
    loadCoaAccounts()
  }, 300)
})

onMounted(async () => {
  await loadCoaAccounts()
})
</script>

<template>
  <section class="page-grid two">
    <article class="panel-card panel-dense">
      <LoadingState v-if="loading" label="Loading COA data from the database..." overlay />
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Account summary</p>
          <h3>Chart of accounts summary</h3>
        </div>
        <span class="status-badge info">{{ pagination.total || hotel.coaList.length }} COA | {{ sourceLabel }}</span>
      </div>

      <div class="booking-inline-summary">
        <div v-for="item in categorySummary" :key="item.label" class="note-cell">
          <strong>{{ categoryLabels[item.label] }}</strong>
          <p class="subtle">{{ item.count }} accounts</p>
        </div>
      </div>
    </article>

    <article class="panel-card panel-dense">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">COA controls</p>
          <h3>Account master actions</h3>
        </div>
        <button class="action-button primary" @click="openCreateModal">Add COA</button>
      </div>

      <div class="compact-list">
        <div class="list-row list-row-tight">
          <strong>Mapping piutang</strong>
          <p class="subtle">Use asset COA for room receivables, deposits, and guest settlement.</p>
        </div>
        <div class="list-row list-row-tight">
          <strong>Mapping pendapatan</strong>
          <p class="subtle">Hubungkan room revenue, transport revenue, dan activity revenue ke master COA.</p>
        </div>
        <div class="list-row list-row-tight">
          <strong>Kas dan bank</strong>
          <p class="subtle">Use cash and bank accounts for posting payments from invoices.</p>
        </div>
      </div>
    </article>
  </section>

  <section class="page-grid">
    <article class="panel-card panel-dense">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">COA master list</p>
          <h3>Chart of accounts</h3>
        </div>
        <div class="kpi-inline">
          <span>{{ filteredAccounts.length }} visible accounts</span>
          <span>{{ pagination.total || hotel.coaList.length }} total accounts</span>
        </div>
      </div>

      <div class="table-toolbar">
        <div class="toolbar-tabs">
          <button
            v-for="item in categoryOptions"
            :key="item"
            class="toolbar-tab"
            :class="{ active: filterCategory === item }"
            @click="filterCategory = item"
          >
            {{ categoryLabels[item] }}
          </button>
        </div>
        <input
          v-model="search"
          class="toolbar-search"
          placeholder="Search code / account / category"
        />
      </div>

      <div v-if="formResult.text" class="booking-feedback" :class="formResult.tone">
        {{ formResult.text }}
      </div>

      <table v-smart-table class="data-table">
        <thead>
          <tr>
            <th>Code</th>
            <th>Account name</th>
            <th>Category</th>
            <th>Normal balance</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="loading">
            <td colspan="6" class="table-loading-cell">
              <LoadingState label="Loading COA data from the database..." />
            </td>
          </tr>
          <tr v-else-if="!filteredAccounts.length">
            <td colspan="6" class="table-empty-cell">
              No matching COA data found.
            </td>
          </tr>
          <tr v-for="item in filteredAccounts" :key="item.code">
            <td><strong>{{ item.code }}</strong></td>
            <td>{{ item.name }}</td>
            <td>{{ categoryLabels[item.category] }}</td>
            <td>{{ normalBalanceLabels[item.normalBalance] }}</td>
            <td>{{ item.active ? 'Active' : 'Inactive' }}</td>
            <td>
              <button class="action-button" @click="openEditModal(item)">Edit</button>
            </td>
          </tr>
        </tbody>
      </table>

    </article>
  </section>

  <div v-if="showModal" class="modal-backdrop" @click.self="closeModal()">
    <section class="modal-card">
      <LoadingState v-if="saving" label="Saving COA..." overlay />
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">COA master</p>
          <h3>{{ modalMode === 'create' ? 'Add COA' : `Edit COA ${editingCode}` }}</h3>
        </div>
        <button class="action-button" @click="closeModal()">Close</button>
      </div>

      <div class="booking-form-grid">
        <label class="field-stack">
          <span>Kode</span>
          <input v-model="coaForm.code" class="form-control" :disabled="modalMode === 'edit'" placeholder="Example: 4-1101" />
        </label>

        <label class="field-stack">
          <span>Account name</span>
          <input v-model="coaForm.name" class="form-control" placeholder="Example: Room Revenue - Deluxe Garden" />
        </label>

        <label class="field-stack">
          <span>Category</span>
          <select v-model="coaForm.category" class="form-control">
            <option v-for="item in categoryOptions.filter((option) => option !== 'All')" :key="item" :value="item">{{ categoryLabels[item] }}</option>
          </select>
        </label>

        <label class="field-stack">
          <span>Normal balance</span>
          <select v-model="coaForm.normalBalance" class="form-control">
            <option v-for="item in normalBalanceOptions" :key="item" :value="item">{{ normalBalanceLabels[item] }}</option>
          </select>
        </label>

        <label class="field-stack field-span-2">
          <span>Note</span>
          <textarea
            v-model="coaForm.note"
            class="form-control form-textarea"
            placeholder="Account description for hotel operational mapping"
          ></textarea>
        </label>

        <label class="field-stack">
          <span>Status</span>
          <select v-model="coaForm.active" class="form-control">
            <option :value="true">Active</option>
            <option :value="false">Inactive</option>
          </select>
        </label>
      </div>

      <div class="modal-actions">
        <button class="action-button" :disabled="saving" @click="closeModal()">Cancel</button>
        <button class="action-button primary" :disabled="saving" @click="submitForm">
          {{ saving ? 'Saving...' : 'Save COA' }}
        </button>
      </div>
    </section>
  </div>
</template>
