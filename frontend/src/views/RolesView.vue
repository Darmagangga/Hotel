<script setup>
import { ref, onMounted } from 'vue'
import api from '../services/api'
import LoadingState from '../components/LoadingState.vue'

const roles = ref([])
const loading = ref(true)
const saving = ref(false)

const availableMenus = [
  { id: 'dashboard', label: 'Dashboard Front Office' },
  { id: 'bookings', label: 'Reservasi & Booking' },
  { id: 'rooms', label: 'Status Kamar & Kalender' },
  { id: 'finance', label: 'Keuangan & Kasir' },
  { id: 'journals', label: 'Jurnal Akuntansi' },
  { id: 'coa', label: 'Bagan Akun (COA)' },
  { id: 'inventory', label: 'Persediaan Barang (Inventory)' },
  { id: 'transport', label: 'Transportasi & Penjemputan' },
  { id: 'activities', label: 'Aktivitas & Tour' },
  { id: 'reports', label: 'Laporan Hotel' },
  { id: 'users', label: 'Daftar Pendaftaran Staf' },
  { id: 'roles', label: 'Pengaturan Hak Akses (Role)' },
]

const fetchData = async () => {
  loading.value = true
  try {
    const response = await api.get('/roles')
    roles.value = response.data
  } catch (error) {
    alert('Gagal memuat peran: ' + error.message)
  } finally {
    loading.value = false
  }
}

const hasPermission = (permissions, menuId) => {
  return permissions && permissions.includes(menuId)
}

const togglePermission = (role, menuId) => {
  if (!role.permissions) {
    role.permissions = []
  }
  
  if (role.permissions.includes(menuId)) {
    role.permissions = role.permissions.filter(m => m !== menuId)
  } else {
    role.permissions.push(menuId)
  }
}

const saveRole = async (role) => {
  saving.value = true
  try {
    await api.put(`/roles/${role.id}/permissions`, { permissions: role.permissions })
    alert(`Hak akses untuk ${role.name.toUpperCase()} berhasil disimpan!`)
  } catch (error) {
    alert('Gagal menyimpan: ' + error.message)
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  fetchData()
})
</script>

<template>
  <div class="page-grid" style="grid-template-columns: 1fr;">
    <div class="panel-head">
      <div>
        <h3>Pengaturan Hak Akses Menu</h3>
        <p class="panel-note">Sentralisasi otorisasi halaman untuk setiap jenis departemen staf.</p>
      </div>
    </div>

    <LoadingState v-if="loading" label="Memuat kebijakan akses..." />
    <div v-else class="page-grid two">
      
      <!-- Role Cards -->
      <article v-for="role in roles" :key="role.id" class="panel-card panel-dense">
        <div class="panel-head panel-head-tight" style="border-bottom: 1px solid var(--line); padding-bottom:12px; margin-bottom: 12px;">
          <div>
            <p class="eyebrow-dark">Departemen / Role</p>
            <h3 style="font-size: 1.25rem;">{{ role.name.toUpperCase() }}</h3>
          </div>
          <button class="action-button primary" :disabled="saving" @click="saveRole(role)">
            Simpan Konfigurasi
          </button>
        </div>
        
        <p class="subtle" style="margin-bottom: 1rem;">Centang halaman dan menu apa saja yang boleh dibuka oleh departemen ini:</p>

        <div class="permissions-grid">
          <label v-for="menu in availableMenus" :key="menu.id" class="permission-checkbox">
            <input 
              type="checkbox" 
              :checked="hasPermission(role.permissions, menu.id)" 
              @change="togglePermission(role, menu.id)"
            />
            <span>{{ menu.label }}</span>
          </label>
        </div>
      </article>

    </div>
  </div>
</template>

<style scoped>
.permissions-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.permission-checkbox {
  display: flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
  padding: 8px;
  border-radius: var(--border-radius);
  background: var(--panel-soft);
  border: 1px solid var(--line);
  transition: all 0.2s ease;
}

.permission-checkbox:hover {
  background: #f1f5f9;
  border-color: #cbd5e1;
}

.permission-checkbox input[type="checkbox"] {
  width: 16px;
  height: 16px;
  accent-color: var(--primary);
  cursor: pointer;
}

.permission-checkbox span {
  font-weight: 500;
  color: var(--text);
  font-size: 0.9rem;
}
</style>
