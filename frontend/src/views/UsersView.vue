<script setup>
import { ref, onMounted } from 'vue'
import api from '../services/api'
import LoadingState from '../components/LoadingState.vue'

const users = ref([])
const roles = ref([])
const loading = ref(true)
const creating = ref(false)
const showCreateForm = ref(false)
const editMode = ref(false)
const editingId = ref(null)

const userForm = ref({
  name: '',
  email: '',
  password: '',
  role_id: ''
})
const formError = ref(null)

const fetchData = async () => {
  loading.value = true
  try {
    const [uRes, rRes] = await Promise.all([
      api.get('/users'),
      api.get('/roles')
    ])
    users.value = uRes.data
    roles.value = rRes.data
    if (roles.value.length > 0 && !userForm.value.role_id) {
      userForm.value.role_id = roles.value[0].id
    }
  } catch (error) {
    alert('Gagal memuat data pengguna: ' + error.message)
  } finally {
    loading.value = false
  }
}

const resetForm = () => {
  userForm.value = { name: '', email: '', password: '', role_id: roles.value[0]?.id }
  editMode.value = false
  editingId.value = null
  showCreateForm.value = false
}

const openCreate = () => {
  resetForm()
  showCreateForm.value = true
}

const openEdit = (user) => {
  userForm.value = {
    name: user.name,
    email: user.email,
    password: '',
    role_id: user.role_id || roles.value[0]?.id
  }
  editMode.value = true
  editingId.value = user.id
  showCreateForm.value = true
}

const submitForm = async () => {
  creating.value = true
  formError.value = null
  try {
    if (editMode.value) {
      await api.put(`/users/${editingId.value}`, userForm.value)
    } else {
      await api.post('/users', userForm.value)
    }
    resetForm()
    fetchData()
  } catch (error) {
    if (error.response?.data?.errors) {
      formError.value = Object.values(error.response.data.errors).flat().join('\n')
    } else {
      formError.value = error.response?.data?.message || error.message
    }
  } finally {
    creating.value = false
  }
}

const toggleUser = async (id) => {
  try {
    await api.patch(`/users/${id}/toggle`)
    fetchData()
  } catch (error) {
    alert('Gagal merubah status: ' + error.message)
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
        <h3>Manajemen Pengguna & Hak Akses</h3>
        <p class="panel-note">Atur akun staf dan hak akses (Role-Based Access Control).</p>
      </div>
      <button v-if="!showCreateForm" class="action-button primary" @click="openCreate">
        + Buat Akun Staf Baru
      </button>
    </div>

    <!-- Pendaftaran & Edit Form -->
    <div v-if="showCreateForm" class="panel-card" style="margin-bottom: 1rem; border-left: 4px solid var(--primary);">
      <h3 style="margin-bottom: 1rem; color: var(--blue-deep);">{{ editMode ? 'Edit Profil Staf' : 'Formulir Pendaftaran' }}</h3>
      <form @submit.prevent="submitForm" class="booking-form-grid" style="gap: 1.2rem;">
        
        <label class="field-stack">
          <span>Nama Lengkap Staf</span>
          <input v-model="userForm.name" type="text" class="form-control" required placeholder="Cth: Sarah Wijaya" />
        </label>
        
        <label class="field-stack">
          <span>Email (Untuk Login)</span>
          <input v-model="userForm.email" type="email" class="form-control" required placeholder="Cth: sarah@sagarabay.com" />
        </label>
        
        <label class="field-stack">
          <span>Kata Sandi {{ editMode ? '(Kosongkan jika tak diubah)' : 'Baru' }}</span>
          <input v-model="userForm.password" type="password" class="form-control" :required="!editMode" minlength="6" placeholder="Minimal 6 karakter" />
        </label>
        
        <label class="field-stack">
          <span>Hak Akses (Role/Jabatan)</span>
          <select v-model="userForm.role_id" class="form-control" required>
            <option v-for="role in roles" :key="role.id" :value="role.id">
              {{ role.name.charAt(0).toUpperCase() + role.name.slice(1) }}
            </option>
          </select>
        </label>
        
        <div v-if="formError" class="booking-feedback error field-span-2" style="white-space: pre-line; margin:0;">
          {{ formError }}
        </div>
        
        <div class="field-span-2" style="display: flex; gap: 0.5rem; justify-content: flex-end; margin-top: 1rem;">
          <button type="button" class="action-button" @click="resetForm">Batal</button>
          <button type="submit" class="action-button primary" :disabled="creating">
            {{ creating ? 'Menyimpan...' : 'Simpan Akun' }}
          </button>
        </div>

      </form>
    </div>

    <!-- User Table -->
    <div class="panel-card panel-dense">
      <LoadingState v-if="loading" label="Memuat data staf..." />
      <div v-else>
        <table class="data-table">
          <thead>
            <tr>
              <th>Nama Staf</th>
              <th>Email Address</th>
              <th>Hak Akses (Role)</th>
              <th style="width: 100px;">Status</th>
              <th style="width: 180px; text-align:right;">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="u in users" :key="u.id">
              <td><strong>{{ u.name }}</strong></td>
              <td>{{ u.email }}</td>
              <td>
                <span class="status-badge" :class="{'info': u.role === 'admin', 'success': u.role === 'frontdesk', 'warning': u.role === 'housekeeping'}">
                  {{ u.role.toUpperCase() }}
                </span>
              </td>
              <td>
                <span v-if="u.is_active" style="color: var(--green); font-weight:600; padding:2px 8px; background:#dcfce7; border-radius:4px; font-size:0.8rem;">Active</span>
                <span v-else style="color: var(--red); font-weight:600; padding:2px 8px; background:#fee2e2; border-radius:4px; font-size:0.8rem;">Disabled</span>
              </td>
              <td style="text-align: right; display:flex; gap:0.5rem; justify-content:flex-end;">
                <button class="action-button" style="padding: 4px 10px; font-size:0.8rem;" @click="openEdit(u)">Edit</button>
                <button class="action-button" style="padding: 4px 10px; font-size:0.8rem;" @click="toggleUser(u.id)">
                  {{ u.is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
