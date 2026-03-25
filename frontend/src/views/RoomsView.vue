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
const roomTypeItems = ref([])
const pagination = reactive({
  page: 1,
  perPage: 12,
  total: 0,
  lastPage: 1,
})

const roomForm = reactive({
  code: '',
  name: '',
  roomTypeId: '',
  coaReceivableCode: '',
  coaRevenueCode: '',
})

const filteredRoomMasters = computed(() => roomMasters.value)
const roomTypeOptions = computed(() => roomTypeItems.value)
const groupedRackRows = computed(() => {
  const groups = roomMasters.value.reduce((map, room) => {
    const floorKey = room.floor ? `Floor ${room.floor}` : 'No Floor'
    const cells = map.get(floorKey) ?? []
    cells.push({
      no: room.code,
      status: String(room.status ?? '').toLowerCase(),
      guest: room.note || room.name || 'No note',
      flag: String(room.status ?? '').slice(0, 2).toUpperCase() || 'NA',
    })
    map.set(floorKey, cells)
    return map
  }, new Map())

  return [...groups.entries()].map(([floor, cells]) => ({ floor, cells }))
})
const housekeepingQueue = computed(() =>
  roomMasters.value
    .filter((room) => ['Cleaning', 'Dirty', 'Blocked', 'Maintenance'].includes(String(room.status ?? '')))
    .map((room) => ({
      room: room.code,
      task: room.note || `Follow up status ${room.status}`,
      eta: room.status === 'Cleaning' ? 'Housekeeping queue' : 'Need inspection',
      owner: room.status === 'Maintenance' ? 'Engineering' : 'Housekeeping',
    })),
)

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
  roomForm.roomTypeId = ''
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

const openCreateRoomModal = () => {
  resetRoomForm()
  showRoomModal.value = true
}

const editRoom = (room) => {
  editingCode.value = room.code
  roomForm.code = room.code
  roomForm.name = room.name
  roomForm.roomTypeId = String(room.roomTypeId ?? '')
  roomForm.coaReceivableCode = room.coaReceivableCode ?? ''
  roomForm.coaRevenueCode = room.coaRevenueCode ?? ''
  roomResult.value = { tone: '', text: '' }
  showRoomModal.value = true
}

const loadRoomDependencies = async () => {
  const [coaResponse, roomTypeResponse] = await Promise.all([
    api.get('/coa-accounts', { params: { per_page: 100 } }),
    api.get('/room-types'),
  ])

  hotel.setCoaAccounts(Array.isArray(coaResponse.data?.data) ? coaResponse.data.data : [])
  roomTypeItems.value = (Array.isArray(roomTypeResponse.data?.data) ? roomTypeResponse.data.data : []).map((item) => ({
    value: String(item.id),
    label: item.name,
  }))
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
      text: error?.response?.data?.message ?? 'Gagal mengambil data master kamar dari database.',
    }
  } finally {
    loading.value = false
  }
}

const submitRoom = async () => {
  roomResult.value = { tone: '', text: '' }
  saving.value = true

  try {
    if (editingCode.value) {
      const response = await api.put(`/rooms/${editingCode.value}`, {
        name: roomForm.name,
        roomTypeId: Number(roomForm.roomTypeId),
        coaReceivableCode: roomForm.coaReceivableCode || null,
        coaRevenueCode: roomForm.coaRevenueCode || null,
      })

      roomResult.value = {
        tone: 'success',
        text: response.data?.message ?? `Master kamar ${editingCode.value} berhasil diperbarui.`,
      }
    } else {
      const response = await api.post('/rooms', {
        code: roomForm.code,
        name: roomForm.name,
        roomTypeId: Number(roomForm.roomTypeId),
        coaReceivableCode: roomForm.coaReceivableCode || null,
        coaRevenueCode: roomForm.coaRevenueCode || null,
      })

      roomResult.value = {
        tone: 'success',
        text: response.data?.message ?? `Master kamar ${roomForm.code} berhasil ditambahkan.`,
      }
    }

    await loadRooms()
    closeRoomModal(false)
  } catch (error) {
    roomResult.value = {
      tone: 'error',
      text: error?.response?.data?.message ?? (error instanceof Error ? error.message : 'Gagal menyimpan master kamar.'),
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
  await loadRooms()
})
</script>

<template>
  <section class="panel-card panel-dense">
    <LoadingState v-if="loading" label="Memuat data kamar dari database..." overlay />
    <div class="panel-head panel-head-tight">
      <div>
        <p class="eyebrow-dark">Daftar master kamar</p>
        <h3>Daftar kamar</h3>
      </div>
      <div class="topbar-actions">
        <input
          v-model="roomSearch"
          class="toolbar-search"
          placeholder="Cari kode / nama / tipe / COA"
        />
        <button class="action-button primary" @click="openCreateRoomModal">
          Tambah room master
        </button>
      </div>
    </div>

    <div v-if="roomResult.text" class="booking-feedback" :class="roomResult.tone">
      {{ roomResult.text }}
    </div>

    <table class="data-table">
      <thead>
        <tr>
          <th>Kode</th>
          <th>Nama kamar</th>
          <th>Tipe</th>
          <th>COA piutang</th>
          <th>COA pendapatan</th>
          <th>Status</th>
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="room in filteredRoomMasters" :key="room.code">
          <td><strong>{{ room.code }}</strong></td>
          <td>{{ room.name }}</td>
          <td>{{ room.type }}</td>
          <td>{{ room.coaReceivable }}</td>
          <td>{{ room.coaRevenue }}</td>
          <td>{{ room.status }}</td>
          <td>
            <button class="action-button" @click="editRoom(room)">Edit</button>
          </td>
        </tr>
      </tbody>
    </table>

    <div class="modal-actions" style="margin-top: 16px;">
      <button class="action-button" :disabled="pagination.page <= 1" @click="goToPage(pagination.page - 1)">Prev</button>
      <span class="subtle">Halaman {{ pagination.page }} / {{ pagination.lastPage }} | {{ pagination.total }} kamar</span>
      <button class="action-button" :disabled="pagination.page >= pagination.lastPage" @click="goToPage(pagination.page + 1)">Next</button>
    </div>
  </section>

  <section class="page-grid two">
    <article class="panel-card panel-dense">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Tampilan kamar</p>
          <h3>Monitor room rack</h3>
        </div>
        <div class="kpi-inline">
          <span>VC kamar kosong siap jual</span>
          <span>OC sedang terisi</span>
          <span>OOO out of order</span>
        </div>
      </div>

      <div class="table-toolbar">
        <div class="toolbar-tabs">
          <button class="toolbar-tab active">Tampilan stay</button>
          <button class="toolbar-tab">Tampilan kamar</button>
          <button class="toolbar-tab">Tampilan cepat</button>
        </div>
        <div class="toolbar-search">Cari kamar / tamu / lantai</div>
      </div>

      <div class="rack-board">
        <div v-for="row in groupedRackRows" :key="row.floor" class="rack-row">
          <div class="rack-floor">{{ row.floor }}</div>
          <div class="rack-cells">
            <div
              v-for="cell in row.cells"
              :key="cell.no"
              class="rack-cell"
              :class="cell.status"
            >
              <div class="rack-cell-head">
                <strong>{{ cell.no }}</strong>
                <span>{{ cell.flag }}</span>
              </div>
              <p>{{ cell.guest }}</p>
            </div>
          </div>
        </div>
      </div>
    </article>

    <article class="panel-card panel-dense">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Housekeeping</p>
          <h3>Turnaround queue</h3>
        </div>
        <span class="status-badge warning">{{ housekeepingQueue.length }} rooms pending</span>
      </div>

      <table class="data-table">
        <thead>
          <tr>
            <th>Room</th>
            <th>Task</th>
            <th>ETA</th>
            <th>Owner</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in housekeepingQueue" :key="item.room">
            <td><strong>{{ item.room }}</strong></td>
            <td>{{ item.task }}</td>
            <td>{{ item.eta }}</td>
            <td>{{ item.owner }}</td>
          </tr>
          <tr v-if="!housekeepingQueue.length">
            <td colspan="4" class="table-empty-cell">Tidak ada kamar yang butuh follow-up housekeeping saat ini.</td>
          </tr>
        </tbody>
      </table>
    </article>
  </section>

  <section class="panel-card panel-dense">
    <div class="panel-head panel-head-tight">
      <div>
        <p class="eyebrow-dark">Catatan kamar</p>
        <h3>Status kamar dan housekeeping</h3>
      </div>
      <div class="kpi-inline">
        <span>{{ housekeepingQueue.length }} kamar dalam turnaround</span>
        <span>{{ filteredRoomMasters.filter((room) => ['Blocked', 'Maintenance'].includes(String(room.status ?? ''))).length }} kamar diblokir / maintenance</span>
      </div>
    </div>

    <div class="room-grid">
      <article v-for="room in filteredRoomMasters" :key="room.code" class="room-card">
        <div class="split-row">
          <div>
            <strong>Room {{ room.code }}</strong>
            <p class="subtle">{{ room.name }}</p>
          </div>
          <span class="status-dot" :class="room.status"></span>
        </div>
        <p class="subtle">{{ room.type }}</p>
        <p class="subtle">COA piutang: {{ room.coaReceivable }}</p>
        <p class="subtle">COA pendapatan: {{ room.coaRevenue }}</p>
        <div class="split-row" style="margin-top: 14px;">
          <span>{{ room.hk }}</span>
          <span>{{ room.note }}</span>
        </div>
      </article>
    </div>
  </section>

  <div v-if="showRoomModal" class="modal-backdrop" @click.self="closeRoomModal()">
    <section class="modal-card">
      <LoadingState v-if="saving" label="Menyimpan master kamar..." overlay />
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Room master</p>
          <h3>{{ editingCode ? `Edit kamar ${editingCode}` : 'Tambah master kamar' }}</h3>
        </div>
        <button class="action-button" @click="closeRoomModal()">Tutup</button>
      </div>

      <div class="booking-form-grid">
        <label class="field-stack">
          <span>Kode kamar</span>
          <input
            v-model="roomForm.code"
            class="form-control"
            :disabled="Boolean(editingCode)"
            placeholder="Contoh: 301"
          />
        </label>

        <label class="field-stack">
          <span>Nama kamar</span>
          <input
            v-model="roomForm.name"
            class="form-control"
            placeholder="Contoh: Deluxe Garden 301"
          />
        </label>

        <label class="field-stack">
          <span>Tipe kamar</span>
          <select v-model="roomForm.roomTypeId" class="form-control">
            <option value="">Pilih tipe kamar</option>
            <option v-for="item in roomTypeOptions" :key="item.value" :value="item.value">{{ item.label }}</option>
          </select>
        </label>

        <label class="field-stack field-span-2">
          <span>COA piutang</span>
          <Select2Field
            v-model="roomForm.coaReceivableCode"
            :options="receivableCoaOptions"
            :multiple="false"
            placeholder="Pilih COA piutang"
          />
        </label>

        <label class="field-stack field-span-2">
          <span>COA pendapatan</span>
          <Select2Field
            v-model="roomForm.coaRevenueCode"
            :options="revenueCoaOptions"
            :multiple="false"
            placeholder="Pilih COA pendapatan"
          />
        </label>
      </div>

      <div class="booking-inline-summary">
        <div class="note-cell">
          <strong>Mode edit</strong>
          <p class="subtle">
            <template v-if="editingCode">
              Sedang mengedit master kamar {{ editingCode }}. Kode kamar dikunci agar referensi booking tetap aman.
            </template>
            <template v-else>
              Buat kamar baru dengan kode unik, pilih tipe kamar, lalu hubungkan ke COA piutang dan pendapatan yang sesuai.
            </template>
          </p>
        </div>
        <div class="note-cell">
          <strong>Integrasi keuangan</strong>
          <p class="subtle">COA piutang dipakai untuk tagihan kamar, sedangkan COA pendapatan dipakai untuk pengakuan revenue kamar.</p>
        </div>
      </div>

      <div v-if="roomResult.text" class="booking-feedback" :class="roomResult.tone">
        {{ roomResult.text }}
      </div>

      <div class="modal-actions">
        <button class="action-button" :disabled="saving" @click="closeRoomModal()">Cancel</button>
        <button class="action-button primary" :disabled="saving" @click="submitRoom">
          {{ saving ? 'Menyimpan...' : (editingCode ? 'Perbarui master kamar' : 'Tambah master kamar') }}
        </button>
      </div>
    </section>
  </div>
</template>
