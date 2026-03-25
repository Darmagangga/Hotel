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
  All: 'Semua',
  Asset: 'Aset',
  Liability: 'Liabilitas',
  Equity: 'Ekuitas',
  Revenue: 'Pendapatan',
  Expense: 'Biaya',
}
const normalBalanceLabels = {
  Debit: 'Debit',
  Credit: 'Kredit',
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
    sourceLabel.value = 'Store lokal'
    accounts.value = hotel.coaList
    formResult.value = {
      tone: 'error',
      text: 'Gagal mengambil COA dari database. Menampilkan data lokal sementara.',
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
      formResult.value = { tone: 'success', text: response.data?.message ?? `COA ${coaForm.code} berhasil ditambahkan.` }
    } else {
      const response = await api.put(`/coa-accounts/${editingCode.value}`, { ...coaForm })
      formResult.value = { tone: 'success', text: response.data?.message ?? `COA ${editingCode.value} berhasil diperbarui.` }
    }

    showModal.value = false
    await loadCoaAccounts()
  } catch (error) {
    formResult.value = {
      tone: 'error',
      text: error?.response?.data?.message ?? (error instanceof Error ? error.message : 'Gagal menyimpan COA.'),
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
      <LoadingState v-if="loading" label="Memuat data COA dari database..." overlay />
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Ringkasan akun</p>
          <h3>Ringkasan chart of accounts</h3>
        </div>
        <span class="status-badge info">{{ pagination.total || hotel.coaList.length }} COA | {{ sourceLabel }}</span>
      </div>

      <div class="booking-inline-summary">
        <div v-for="item in categorySummary" :key="item.label" class="note-cell">
          <strong>{{ categoryLabels[item.label] }}</strong>
          <p class="subtle">{{ item.count }} akun</p>
        </div>
      </div>
    </article>

    <article class="panel-card panel-dense">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Kontrol COA</p>
          <h3>Aksi master akun</h3>
        </div>
        <button class="action-button primary" @click="openCreateModal">Tambah COA</button>
      </div>

      <div class="compact-list">
        <div class="list-row list-row-tight">
          <strong>Mapping piutang</strong>
          <p class="subtle">Pakai COA aset untuk piutang kamar, deposit, dan settlement tamu.</p>
        </div>
        <div class="list-row list-row-tight">
          <strong>Mapping pendapatan</strong>
          <p class="subtle">Hubungkan room revenue, transport revenue, dan activity revenue ke master COA.</p>
        </div>
        <div class="list-row list-row-tight">
          <strong>Kas dan bank</strong>
          <p class="subtle">Gunakan akun kas/bank untuk posting pembayaran dari invoice.</p>
        </div>
      </div>
    </article>
  </section>

  <section class="page-grid">
    <article class="panel-card panel-dense">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Daftar master COA</p>
          <h3>Chart of accounts</h3>
        </div>
        <div class="kpi-inline">
          <span>{{ filteredAccounts.length }} akun terlihat</span>
          <span>{{ pagination.total || hotel.coaList.length }} total akun</span>
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
          placeholder="Cari kode / akun / kategori"
        />
      </div>

      <div v-if="formResult.text" class="booking-feedback" :class="formResult.tone">
        {{ formResult.text }}
      </div>

      <table class="data-table">
        <thead>
          <tr>
            <th>Kode</th>
            <th>Nama akun</th>
            <th>Kategori</th>
            <th>Saldo normal</th>
            <th>Status</th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="loading">
            <td colspan="6" class="table-loading-cell">
              <LoadingState label="Sedang mengambil data COA dari database..." />
            </td>
          </tr>
          <tr v-else-if="!filteredAccounts.length">
            <td colspan="6" class="table-empty-cell">
              Tidak ada data COA yang cocok.
            </td>
          </tr>
          <tr v-for="item in filteredAccounts" :key="item.code">
            <td><strong>{{ item.code }}</strong></td>
            <td>{{ item.name }}</td>
            <td>{{ categoryLabels[item.category] }}</td>
            <td>{{ normalBalanceLabels[item.normalBalance] }}</td>
            <td>{{ item.active ? 'Aktif' : 'Nonaktif' }}</td>
            <td>
              <button class="action-button" @click="openEditModal(item)">Edit</button>
            </td>
          </tr>
        </tbody>
      </table>

      <div class="modal-actions" style="margin-top: 16px;">
        <button class="action-button" :disabled="pagination.page <= 1" @click="goToPage(pagination.page - 1)">Prev</button>
        <span class="subtle">Halaman {{ pagination.page }} / {{ pagination.lastPage }}</span>
        <button class="action-button" :disabled="pagination.page >= pagination.lastPage" @click="goToPage(pagination.page + 1)">Next</button>
      </div>
    </article>
  </section>

  <div v-if="showModal" class="modal-backdrop" @click.self="closeModal()">
    <section class="modal-card">
      <LoadingState v-if="saving" label="Menyimpan COA..." overlay />
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">COA master</p>
          <h3>{{ modalMode === 'create' ? 'Tambah COA' : `Edit COA ${editingCode}` }}</h3>
        </div>
        <button class="action-button" @click="closeModal()">Tutup</button>
      </div>

      <div class="booking-form-grid">
        <label class="field-stack">
          <span>Kode</span>
          <input v-model="coaForm.code" class="form-control" :disabled="modalMode === 'edit'" placeholder="Contoh: 4-1101" />
        </label>

        <label class="field-stack">
          <span>Nama akun</span>
          <input v-model="coaForm.name" class="form-control" placeholder="Contoh: Room Revenue - Deluxe Garden" />
        </label>

        <label class="field-stack">
          <span>Kategori</span>
          <select v-model="coaForm.category" class="form-control">
            <option v-for="item in categoryOptions.filter((option) => option !== 'All')" :key="item" :value="item">{{ categoryLabels[item] }}</option>
          </select>
        </label>

        <label class="field-stack">
          <span>Saldo normal</span>
          <select v-model="coaForm.normalBalance" class="form-control">
            <option v-for="item in normalBalanceOptions" :key="item" :value="item">{{ normalBalanceLabels[item] }}</option>
          </select>
        </label>

        <label class="field-stack field-span-2">
          <span>Note</span>
          <textarea
            v-model="coaForm.note"
            class="form-control form-textarea"
            placeholder="Keterangan akun untuk mapping operasional hotel"
          ></textarea>
        </label>

        <label class="field-stack">
          <span>Status</span>
          <select v-model="coaForm.active" class="form-control">
            <option :value="true">Aktif</option>
            <option :value="false">Nonaktif</option>
          </select>
        </label>
      </div>

      <div class="modal-actions">
        <button class="action-button" :disabled="saving" @click="closeModal()">Batal</button>
        <button class="action-button primary" :disabled="saving" @click="submitForm">
          {{ saving ? 'Menyimpan...' : 'Simpan COA' }}
        </button>
      </div>
    </section>
  </div>
</template>
