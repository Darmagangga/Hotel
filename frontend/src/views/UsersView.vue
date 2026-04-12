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
  username: '',
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
    alert('Failed to load user data: ' + error.message)
  } finally {
    loading.value = false
  }
}

const resetForm = () => {
  userForm.value = { name: '', username: '', email: '', password: '', role_id: roles.value[0]?.id }
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
    username: user.username || '',
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
    alert('Failed to change status: ' + error.message)
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
        <h3>User Management & Access Roles</h3>
        <p class="panel-note">Manage staff accounts and permissions with role-based access control.</p>
      </div>
      <button v-if="!showCreateForm" class="action-button primary" @click="openCreate">
        + Create New Staff Account
      </button>
    </div>

    <!-- Pendaftaran & Edit Form -->
    <div v-if="showCreateForm" class="panel-card" style="margin-bottom: 1rem; border-left: 4px solid var(--primary);">
      <h3 style="margin-bottom: 1rem; color: var(--blue-deep);">{{ editMode ? 'Edit Staff Profile' : 'Registration Form' }}</h3>
      <form @submit.prevent="submitForm" class="booking-form-grid" style="gap: 1.2rem;">
        
        <label class="field-stack">
          <span>Full name</span>
          <input v-model="userForm.name" type="text" class="form-control" required placeholder="Example: Sarah Wijaya" />
        </label>
        
        <label class="field-stack">
          <span>Login username</span>
          <input v-model="userForm.username" type="text" class="form-control" required placeholder="Example: sarah, cashier1, fo-morning" />
        </label>

        <label class="field-stack">
          <span>Email (Optional)</span>
          <input v-model="userForm.email" type="email" class="form-control" placeholder="Example: sarah@udarahideaway.com" />
        </label>
        
        <label class="field-stack">
          <span>Password {{ editMode ? '(Leave blank to keep current password)' : 'New' }}</span>
          <input v-model="userForm.password" type="password" class="form-control" :required="!editMode" minlength="6" placeholder="Minimum 6 characters" />
        </label>
        
        <label class="field-stack">
          <span>Access role</span>
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
          <button type="button" class="action-button" @click="resetForm">Cancel</button>
          <button type="submit" class="action-button primary" :disabled="creating">
            {{ creating ? 'Saving...' : 'Save Account' }}
          </button>
        </div>

      </form>
    </div>

    <!-- User Table -->
    <div class="panel-card panel-dense">
      <LoadingState v-if="loading" label="Loading staff data..." />
      <div v-else>
        <table v-smart-table class="data-table">
          <thead>
            <tr>
              <th>Staff name</th>
              <th>Username</th>
              <th>Email</th>
              <th>Access role</th>
              <th style="width: 100px;">Status</th>
              <th style="width: 180px; text-align:right;">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="u in users" :key="u.id">
              <td><strong>{{ u.name }}</strong></td>
              <td>{{ u.username }}</td>
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
                  {{ u.is_active ? 'Disable' : 'Enable' }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

