<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import LoadingState from '../components/LoadingState.vue'
import api from '../services/api'

const loading = ref(false)
const pageResult = ref({ tone: '', text: '' })
const vendorModalResult = ref({ tone: '', text: '' })
const vendors = ref([])
const showVendorModal = ref(false)
const editingVendorId = ref(null)

const vendorForm = reactive({
  vendorName: '',
  phone: '',
  email: '',
  address: '',
  contactPerson: '',
  paymentTermsDays: 0,
  openingBalanceValue: 0,
  notes: '',
  isActive: true,
})

const filteredVendors = computed(() => vendors.value)

const vendorStats = computed(() => {
  const totals = {
    total: vendors.value.length,
    active: vendors.value.filter((item) => item.isActive).length,
    withPhone: vendors.value.filter((item) => String(item.phone || '').trim()).length,
    withEmail: vendors.value.filter((item) => String(item.email || '').trim()).length,
  }

  return totals
})

const resetVendorForm = () => {
  editingVendorId.value = null
  vendorModalResult.value = { tone: '', text: '' }
  vendorForm.vendorName = ''
  vendorForm.phone = ''
  vendorForm.email = ''
  vendorForm.address = ''
  vendorForm.contactPerson = ''
  vendorForm.paymentTermsDays = 0
  vendorForm.openingBalanceValue = 0
  vendorForm.notes = ''
  vendorForm.isActive = true
}

const loadVendors = async () => {
  loading.value = true
  pageResult.value = { tone: '', text: '' }

  try {
    const response = await api.get('/vendors')
    vendors.value = Array.isArray(response.data?.data) ? response.data.data : []
  } catch (error) {
    pageResult.value = {
      tone: 'error',
      text: error?.response?.data?.message || 'Failed to load activity vendors.',
    }
  } finally {
    loading.value = false
  }
}

const openVendorModal = (vendor = null) => {
  resetVendorForm()

  if (vendor) {
    editingVendorId.value = vendor.id
    vendorForm.vendorName = vendor.vendorName
    vendorForm.phone = vendor.phone
    vendorForm.email = vendor.email
    vendorForm.address = vendor.address
    vendorForm.contactPerson = vendor.contactPerson
    vendorForm.paymentTermsDays = Number(vendor.paymentTermsDays || 0)
    vendorForm.openingBalanceValue = Number(vendor.openingBalanceValue || 0)
    vendorForm.notes = vendor.notes
    vendorForm.isActive = Boolean(vendor.isActive)
  }

  showVendorModal.value = true
}

const submitVendor = async () => {
  if (!String(vendorForm.vendorName || '').trim()) {
    vendorModalResult.value = { tone: 'error', text: 'Vendor name wajib diisi.' }
    return
  }

  try {
    const payload = {
      ...vendorForm,
      paymentTermsDays: Number(vendorForm.paymentTermsDays || 0),
      openingBalanceValue: Number(vendorForm.openingBalanceValue || 0),
    }

    const response = editingVendorId.value
      ? await api.put(`/vendors/${editingVendorId.value}`, payload)
      : await api.post('/vendors', payload)

    pageResult.value = { tone: 'success', text: response.data?.message || 'Activity vendor saved successfully.' }
    vendorModalResult.value = { tone: '', text: '' }
    showVendorModal.value = false
    await loadVendors()
  } catch (error) {
    vendorModalResult.value = {
      tone: 'error',
      text: error?.response?.data?.message || 'Failed to save activity vendor.',
    }
  }
}

onMounted(() => {
  loadVendors()
})
</script>

<template>
  <section class="page-grid">
    <LoadingState v-if="loading" label="Loading activity vendors..." overlay />

    <article class="panel-card panel-dense">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Vendors</p>
          <h3>Master vendor for activity operations</h3>
        </div>
        <div class="modal-actions">
          <button class="action-button primary" @click="openVendorModal()">Add vendor</button>
        </div>
      </div>

      <div v-if="pageResult.text" class="booking-feedback" :class="pageResult.tone">
        {{ pageResult.text }}
      </div>

      <div class="summary-strip">
        <article class="summary-card">
          <p class="summary-label">Total Vendors</p>
          <strong>{{ vendorStats.total }}</strong>
          <span>{{ vendorStats.active }} active vendor(s)</span>
        </article>
        <article class="summary-card">
          <p class="summary-label">With Phone</p>
          <strong>{{ vendorStats.withPhone }}</strong>
          <span>Vendor with phone contact</span>
        </article>
        <article class="summary-card">
          <p class="summary-label">With Email</p>
          <strong>{{ vendorStats.withEmail }}</strong>
          <span>Vendor with email contact</span>
        </article>
        <article class="summary-card">
          <p class="summary-label">With Contact</p>
          <strong>{{ vendors.filter((item) => String(item.contactPerson || item.phone || item.email || '').trim()).length }}</strong>
          <span>Vendor with reachable contact</span>
        </article>
      </div>
    </article>

    <article class="panel-card panel-dense">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Vendor List</p>
          <h3>Vendor master table</h3>
        </div>
      </div>

      <div class="table-scroll">
        <table v-smart-table class="data-table finance-table">
          <thead>
            <tr>
              <th>Vendor</th>
              <th>Contact</th>
              <th>Terms</th>
              <th>Outstanding</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in filteredVendors" :key="item.id">
              <td>
                <strong>{{ item.vendorName }}</strong>
                <div class="subtle">{{ item.vendorCode }}</div>
              </td>
              <td>
                <div>{{ item.contactPerson || '-' }}</div>
                <div class="subtle">{{ item.phone || item.email || '-' }}</div>
              </td>
              <td>{{ item.paymentTermsDays }} day(s)</td>
              <td>{{ item.outstanding }}</td>
              <td>{{ item.isActive ? 'Active' : 'Inactive' }}</td>
              <td>
                <div class="modal-actions booking-table-actions">
                  <button class="action-button" @click="openVendorModal(item)">Edit</button>
                </div>
              </td>
            </tr>
            <tr v-if="!filteredVendors.length">
              <td colspan="6" class="table-empty-cell">No vendors found.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </article>
  </section>

  <div v-if="showVendorModal" class="modal-backdrop" @click.self="showVendorModal = false">
    <section class="modal-card">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Vendor</p>
          <h3>{{ editingVendorId ? 'Edit vendor' : 'Add vendor' }}</h3>
        </div>
        <button class="action-button" @click="showVendorModal = false">Close</button>
      </div>

      <div class="booking-form-grid">
        <label class="field-stack"><span>Vendor name</span><input v-model="vendorForm.vendorName" class="form-control" /></label>
        <label class="field-stack"><span>Phone</span><input v-model="vendorForm.phone" class="form-control" /></label>
        <label class="field-stack"><span>Email</span><input v-model="vendorForm.email" class="form-control" /></label>
        <label class="field-stack"><span>Contact person</span><input v-model="vendorForm.contactPerson" class="form-control" /></label>
        <label class="field-stack"><span>Terms (days)</span><input v-model="vendorForm.paymentTermsDays" class="form-control" type="number" min="0" /></label>
        <label class="field-stack"><span>Opening balance</span><input v-model="vendorForm.openingBalanceValue" class="form-control" type="number" min="0" /></label>
        <label class="field-stack">
          <span>Status</span>
          <select v-model="vendorForm.isActive" class="form-control">
            <option :value="true">Active</option>
            <option :value="false">Inactive</option>
          </select>
        </label>
        <label class="field-stack field-span-2"><span>Address</span><textarea v-model="vendorForm.address" class="form-control form-textarea"></textarea></label>
        <label class="field-stack field-span-2"><span>Notes</span><textarea v-model="vendorForm.notes" class="form-control form-textarea"></textarea></label>
      </div>

      <div v-if="vendorModalResult.text" class="booking-feedback" :class="vendorModalResult.tone">
        {{ vendorModalResult.text }}
      </div>

      <div class="modal-actions">
        <button class="action-button" @click="showVendorModal = false">Cancel</button>
        <button class="action-button primary" @click="submitVendor">Save vendor</button>
      </div>
    </section>
  </div>
</template>
