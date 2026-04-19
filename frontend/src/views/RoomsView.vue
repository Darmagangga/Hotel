<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import LoadingState from '../components/LoadingState.vue'
import Select2Field from '../components/Select2Field.vue'
import api from '../services/api'
import { useHotelStore } from '../stores/hotel'

const hotel = useHotelStore()

const roomSearch = ref('')
const roomResult = ref({ tone: '', text: '' })
const editingCode = ref('')
const showRoomModal = ref(false)
const loading = ref(false)
const saving = ref(false)
const roomMasters = ref([])
const housekeepingQueue = ref([])
const pagination = reactive({
  page: 1,
  perPage: 12,
  total: 0,
  lastPage: 1,
})

const roomForm = reactive({
  code: '',
  name: '',
  coaReceivableCode: '',
  coaRevenueCode: '',
})

const filteredRoomMasters = computed(() => roomMasters.value)
const receivableCoaOptions = computed(() => {
  const options = hotel.coaList
    .filter((account) => account.category === 'Asset')
    .map((account) => ({
      value: account.code,
      label: `${account.code} - ${account.name}`,
    }))

  if (roomForm.coaReceivableCode && !options.some((item) => item.value === roomForm.coaReceivableCode)) {
    return [{ value: roomForm.coaReceivableCode, label: `${roomForm.coaReceivableCode} (Current)` }, ...options]
  }

  return options
})

const revenueCoaOptions = computed(() => {
  const options = hotel.coaList
    .filter((account) => account.category === 'Revenue')
    .map((account) => ({
      value: account.code,
      label: `${account.code} - ${account.name}`,
    }))

  if (roomForm.coaRevenueCode && !options.some((item) => item.value === roomForm.coaRevenueCode)) {
    return [{ value: roomForm.coaRevenueCode, label: `${roomForm.coaRevenueCode} (Current)` }, ...options]
  }

  return options
})

const resetRoomForm = (clearResult = true) => {
  editingCode.value = ''
  roomForm.code = ''
  roomForm.name = ''
  roomForm.coaReceivableCode = ''
  roomForm.coaRevenueCode = ''

  if (clearResult) {
    roomResult.value = { tone: '', text: '' }
  }
}

const closeRoomModal = (clearResult = true) => {
  showRoomModal.value = false
  resetRoomForm(clearResult)
}

const editRoom = (room) => {
  editingCode.value = room.code
  roomForm.code = room.code
  roomForm.name = room.name
  roomForm.coaReceivableCode = room.coaReceivableCode ?? ''
  roomForm.coaRevenueCode = room.coaRevenueCode ?? ''
  roomResult.value = { tone: '', text: '' }
  showRoomModal.value = true
}

const loadRoomDependencies = async () => {
  const coaResponse = await api.get('/coa-accounts', { params: { per_page: 100 } })

  hotel.setCoaAccounts(Array.isArray(coaResponse.data?.data) ? coaResponse.data.data : [])
}

const loadRooms = async () => {
  loading.value = true

  try {
    const response = await api.get('/rooms', {
      params: {
        page: pagination.page,
        per_page: pagination.perPage,
        search: roomSearch.value.trim() || undefined,
      },
    })

    roomMasters.value = Array.isArray(response.data?.data) ? response.data.data : []
    pagination.total = Number(response.data?.meta?.total ?? roomMasters.value.length)
    pagination.lastPage = Number(response.data?.meta?.last_page ?? 1)
    pagination.page = Number(response.data?.meta?.current_page ?? 1)
    roomResult.value = { tone: '', text: '' }
  } catch (error) {
    roomResult.value = {
      tone: 'error',
      text: error?.response?.data?.message ?? 'Failed to load room master data from the database.',
    }
  } finally {
    loading.value = false
  }
}

const loadHousekeepingQueue = async () => {
  try {
    const response = await api.get('/housekeeping/queue')
    housekeepingQueue.value = Array.isArray(response.data?.data) ? response.data.data : []
  } catch (error) {
    roomResult.value = {
      tone: 'error',
      text: error?.response?.data?.message ?? 'Failed to load the housekeeping turnaround queue.',
    }
    housekeepingQueue.value = []
  }
}

const updateHousekeepingTask = async (item, status) => {
  roomResult.value = { tone: '', text: '' }

  try {
    const response = await api.patch(`/housekeeping/tasks/${item.id}`, { status })
    roomResult.value = {
      tone: 'success',
      text: response.data?.message ?? `Housekeeping task for room ${item.room} was updated successfully.`,
    }
    await Promise.all([loadRooms(), loadHousekeepingQueue()])
  } catch (error) {
    roomResult.value = {
      tone: 'error',
      text: error?.response?.data?.message ?? (error instanceof Error ? error.message : 'Failed to update the housekeeping task.'),
    }
  }
}

const submitRoom = async () => {
  roomResult.value = { tone: '', text: '' }
  saving.value = true

  try {
    if (editingCode.value) {
      const response = await api.put(`/rooms/${editingCode.value}`, {
        name: roomForm.name,
        coaReceivableCode: roomForm.coaReceivableCode || null,
        coaRevenueCode: roomForm.coaRevenueCode || null,
      })

      roomResult.value = {
        tone: 'success',
        text: response.data?.message ?? `Room master ${editingCode.value} was updated successfully.`,
      }
    } else {
      roomResult.value = {
        tone: 'error',
        text: 'Setup ini memakai 8 kamar tetap. Tambah kamar baru dinonaktifkan agar operasional tetap sesuai properti.',
      }
      return
    }

    await loadRooms()
    closeRoomModal(false)
  } catch (error) {
    roomResult.value = {
      tone: 'error',
      text: error?.response?.data?.message ?? (error instanceof Error ? error.message : 'Failed to save the room master.'),
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
  await loadRooms()
}

let searchTimer = null

watch(roomSearch, () => {
  pagination.page = 1
  window.clearTimeout(searchTimer)
  searchTimer = window.setTimeout(() => {
    loadRooms()
  }, 300)
})

onMounted(async () => {
  await loadRoomDependencies()
  await Promise.all([loadRooms(), loadHousekeepingQueue()])
})
</script>

<template>
  <section class="panel-card panel-dense">
    <LoadingState v-if="loading" label="Loading room data from the database..." overlay />
    <div class="panel-head panel-head-tight">
      <div>
        <p class="eyebrow-dark">8-room setup</p>
        <h3>Rooms</h3>
      </div>
      <div class="topbar-actions">
        <input
          v-model="roomSearch"
          class="toolbar-search"
          placeholder="Search code / room / COA"
        />
      </div>
    </div>

    <div v-if="roomResult.text" class="booking-feedback" :class="roomResult.tone">
      {{ roomResult.text }}
    </div>

    <div class="table-scroll">
      <table v-smart-table class="data-table room-master-table">
        <thead>
          <tr>
            <th>Code</th>
            <th>Room name</th>
            <th>Receivable COA</th>
            <th>Revenue COA</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="room in filteredRoomMasters" :key="room.code">
            <td><strong>{{ room.code }}</strong></td>
            <td>{{ room.name }}</td>
            <td>{{ room.coaReceivable }}</td>
            <td>{{ room.coaRevenue }}</td>
            <td>{{ room.status }}</td>
            <td>
              <button class="action-button" @click="editRoom(room)">Edit</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

  </section>

  <section class="panel-card panel-dense">
    <div class="panel-head panel-head-tight">
      <div>
        <p class="eyebrow-dark">Housekeeping</p>
        <h3>Turnaround queue</h3>
      </div>
      <div class="kpi-inline">
        <span>{{ housekeepingQueue.length }} task</span>
      </div>
    </div>

    <div class="table-scroll">
      <table v-smart-table class="data-table room-master-table">
        <thead>
          <tr>
            <th>Room</th>
            <th>Task</th>
            <th>Status</th>
            <th>Priority</th>
            <th>Team</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!housekeepingQueue.length">
            <td colspan="6">Tidak ada task housekeeping aktif.</td>
          </tr>
          <tr v-for="item in housekeepingQueue" :key="item.id">
            <td><strong>{{ item.room }}</strong></td>
            <td>{{ item.task }}</td>
            <td>{{ item.status }}</td>
            <td>{{ item.priority }}</td>
            <td>{{ item.owner }}</td>
            <td>
              <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <button
                  v-if="item.canStart"
                  class="action-button"
                  @click="updateHousekeepingTask(item, 'in_progress')"
                >
                  Start cleaning
                </button>
                <button
                  v-if="item.canComplete"
                  class="action-button primary"
                  @click="updateHousekeepingTask(item, 'done')"
                >
                  Mark available
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>

  <div v-if="showRoomModal" class="modal-backdrop" @click.self="closeRoomModal()">
    <section class="modal-card">
      <LoadingState v-if="saving" label="Saving room master..." overlay />
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Room master</p>
          <h3>{{ editingCode ? `Edit room ${editingCode}` : 'Fixed room setup' }}</h3>
        </div>
        <button class="action-button" @click="closeRoomModal()">Close</button>
      </div>

      <div class="booking-form-grid">
        <label class="field-stack">
          <span>Room code</span>
          <input
            v-model="roomForm.code"
            class="form-control"
            :disabled="Boolean(editingCode)"
            placeholder="Example: 1"
          />
        </label>

        <label class="field-stack">
          <span>Room name</span>
          <input
            v-model="roomForm.name"
            class="form-control"
            placeholder="Example: Kamar 1"
          />
        </label>

        <label class="field-stack field-span-2">
          <span>Receivable COA</span>
          <Select2Field
            v-model="roomForm.coaReceivableCode"
            :options="receivableCoaOptions"
            :multiple="false"
            placeholder="Select receivable COA"
          />
        </label>

        <label class="field-stack field-span-2">
          <span>Revenue COA</span>
          <Select2Field
            v-model="roomForm.coaRevenueCode"
            :options="revenueCoaOptions"
            :multiple="false"
            placeholder="Select revenue COA"
          />
        </label>
      </div>

      <div class="booking-inline-summary">
        <div class="note-cell">
          <strong>Setup properti</strong>
          <p class="subtle">
            <template v-if="editingCode">
              Anda sedang mengubah kamar {{ editingCode }} dalam setup 8 kamar tetap. Kode kamar dikunci agar relasi booking tetap aman.
            </template>
            <template v-else>
              Properti ini memakai model tanpa kelas kamar, jadi fokusnya hanya pada 8 kamar operasional yang sudah disiapkan.
            </template>
          </p>
        </div>
        <div class="note-cell">
          <strong>Finance integration</strong>
          <p class="subtle">The receivable COA is used for room billing, while the revenue COA is used for room revenue recognition.</p>
        </div>
      </div>

      <div v-if="roomResult.text" class="booking-feedback" :class="roomResult.tone">
        {{ roomResult.text }}
      </div>

      <div class="modal-actions">
        <button class="action-button" :disabled="saving" @click="closeRoomModal()">Cancel</button>
        <button class="action-button primary" :disabled="saving" @click="submitRoom">
          {{ saving ? 'Saving...' : (editingCode ? 'Update room master' : 'Add room master') }}
        </button>
      </div>
    </section>
  </div>
</template>
