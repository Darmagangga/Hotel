<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import LoadingState from '../components/LoadingState.vue'
import api from '../services/api'

const loading = ref(false)
const saving = ref(false)
const loadError = ref('')
const result = ref({ tone: '', text: '' })
const unitRows = ref([])
const showModal = ref(false)
const editingUnitId = ref(null)

const unitForm = reactive({
  name: '',
})

const totalUnitCount = computed(() => unitRows.value.length)
const modalTitle = computed(() => (editingUnitId.value ? 'Edit satuan' : 'Tambah satuan'))
const submitLabel = computed(() => (editingUnitId.value ? 'Update satuan' : 'Simpan satuan'))

const resetForm = () => {
  unitForm.name = ''
}

const loadUnits = async () => {
  loading.value = true
  loadError.value = ''

  try {
    const response = await api.get('/master-units')
    unitRows.value = Array.isArray(response.data?.data) ? response.data.data : []
  } catch (error) {
    loadError.value = error?.response?.data?.message || error?.message || 'Failed to load unit master.'
    unitRows.value = []
  } finally {
    loading.value = false
  }
}

const openCreateModal = () => {
  result.value = { tone: '', text: '' }
  editingUnitId.value = null
  resetForm()
  showModal.value = true
}

const openEditModal = (row) => {
  result.value = { tone: '', text: '' }
  editingUnitId.value = row.id
  unitForm.name = row.name ?? ''
  showModal.value = true
}

const closeModal = () => {
  showModal.value = false
  editingUnitId.value = null
  resetForm()
}

const submitUnit = async () => {
  saving.value = true
  result.value = { tone: '', text: '' }

  try {
    const isEditing = Boolean(editingUnitId.value)
    const response = isEditing
      ? await api.put(`/master-units/${editingUnitId.value}`, { name: unitForm.name })
      : await api.post('/master-units', { name: unitForm.name })

    result.value = {
      tone: 'success',
      text: response.data?.message || (isEditing ? 'Satuan berhasil diperbarui.' : 'Satuan berhasil ditambahkan.'),
    }
    closeModal()
    await loadUnits()
  } catch (error) {
    result.value = {
      tone: 'error',
      text: error?.response?.data?.message || error?.message || 'Failed to save unit master.',
    }
  } finally {
    saving.value = false
  }
}

const deleteUnit = async (row) => {
  if (typeof window !== 'undefined' && !window.confirm(`Hapus satuan ${row.name}?`)) {
    return
  }

  result.value = { tone: '', text: '' }

  try {
    const response = await api.delete(`/master-units/${row.id}`)
    result.value = {
      tone: 'success',
      text: response.data?.message || 'Satuan berhasil dihapus.',
    }
    await loadUnits()
  } catch (error) {
    result.value = {
      tone: 'error',
      text: error?.response?.data?.message || error?.message || 'Failed to delete unit master.',
    }
  }
}

onMounted(async () => {
  await loadUnits()
})
</script>

<template>
  <section class="page-grid">
    <article class="panel-card panel-dense">
      <LoadingState v-if="loading" label="Loading unit master..." overlay />

      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Master data</p>
          <h3>Satuan</h3>
          <p class="subtle">Daftar satuan yang dipakai oleh barang inventory.</p>
        </div>
        <div style="display: flex; gap: 0.75rem; align-items: center;">
          <span class="status-badge info">{{ totalUnitCount }} satuan</span>
          <button class="action-button primary" @click="openCreateModal">Tambah satuan</button>
        </div>
      </div>

      <div v-if="loadError" class="booking-feedback error">
        {{ loadError }}
      </div>

      <div v-if="result.text" class="booking-feedback" :class="result.tone">
        {{ result.text }}
      </div>

      <div class="table-scroll">
        <table v-smart-table class="data-table">
          <thead>
            <tr>
              <th>Satuan</th>
              <th>Dipakai di barang</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!loading && !unitRows.length">
              <td colspan="3" class="table-empty-cell">Belum ada satuan yang terdaftar.</td>
            </tr>
            <tr v-for="row in unitRows" :key="row.id">
              <td><strong>{{ row.name }}</strong></td>
              <td>{{ row.itemCount }} barang</td>
              <td>
                <div class="modal-actions booking-table-actions">
                  <button class="action-button" @click="openEditModal(row)">Edit</button>
                  <button class="action-button" :disabled="row.itemCount > 0" @click="deleteUnit(row)">Hapus</button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </article>
  </section>

  <div v-if="showModal" class="modal-backdrop" @click.self="closeModal">
    <section class="modal-card">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Master satuan</p>
          <h3>{{ modalTitle }}</h3>
        </div>
        <button class="action-button" @click="closeModal">Close</button>
      </div>

      <label class="field-stack">
        <span>Nama satuan</span>
        <input v-model="unitForm.name" class="form-control" placeholder="Contoh: pcs, box, liter" />
      </label>

      <div class="modal-actions">
        <button class="action-button" @click="closeModal">Cancel</button>
        <button class="action-button primary" :disabled="saving" @click="submitUnit">{{ submitLabel }}</button>
      </div>
    </section>
  </div>
</template>
