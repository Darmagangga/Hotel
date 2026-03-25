<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import { useHotelStore } from './stores/hotel'
import NightAuditModal from './components/NightAuditModal.vue'

const route = useRoute()
const router = useRouter()
const hotel = useHotelStore()

const navigation = [
  { name: 'dashboard', label: 'Dashboard', icon: 'DB' },
  { name: 'bookings', label: 'Reservasi', icon: 'RS' },
  { name: 'rooms', label: 'Kamar', icon: 'RM' },
  { name: 'finance', label: 'Keuangan', icon: 'FN' },
  { name: 'journals', label: 'Jurnal', icon: 'JU' },
  { name: 'coa', label: 'COA', icon: 'GL' },
  { name: 'inventory', label: 'Persediaan', icon: 'IV' },
  { name: 'transport', label: 'Transportasi', icon: 'TR' },
  { name: 'activities', label: 'Aktivitas', icon: 'AC' },
  { name: 'reports', label: 'Laporan', icon: 'RP' },
  { name: 'users', label: 'Pengguna', icon: 'US' },
  { name: 'roles', label: 'Hak Akses', icon: 'LK' },
]

const userRole = computed(() => hotel.user?.role || 'admin')
const userPermissions = computed(() => hotel.user?.permissions || [])
const canAccess = (permissionName) =>
  userRole.value === 'admin' || userPermissions.value.includes(permissionName)
const canCreateBooking = computed(() => canAccess('bookings'))

const filteredNavigation = computed(() => {
  if (userRole.value === 'admin') return navigation // Admin sees all basically, or fallback
  
  // Strict matching based on dynamic permission array from DB
  return navigation.filter(n => userPermissions.value.includes(n.name) || n.name === 'dashboard')
})

const currentPage = computed(
  () => (filteredNavigation.value.find((item) => item.name === route.name) ?? filteredNavigation.value[0]) || navigation[0],
)

const quickViews = ['Front Office']
const accessNotice = ref('')
let accessNoticeTimer = null

const showAccessNotice = (message) => {
  accessNotice.value = message
  window.clearTimeout(accessNoticeTimer)
  accessNoticeTimer = window.setTimeout(() => {
    accessNotice.value = ''
  }, 5000)
}

const openBookingPage = async () => {
  if (!canCreateBooking.value) {
    return
  }

  if (route.name !== 'booking-create') {
    await router.push({ name: 'booking-create' })
  }
}

const showNightAuditModal = ref(false)

const handleNightAudit = () => {
  showNightAuditModal.value = true
}

const handleLogout = () => {
  hotel.logout()
  router.push({ name: 'login' })
}

const handleUnauthorized = async (event) => {
  hotel.logout()
  showAccessNotice(event?.detail?.message || 'Sesi Anda berakhir. Silakan login kembali.')

  if (route.name !== 'login') {
    await router.push({ name: 'login' })
  }
}

const handleForbidden = async (event) => {
  showAccessNotice(event?.detail?.message || 'Anda tidak memiliki akses ke halaman atau aksi ini.')

  if (route.name !== 'login') {
    await router.push({ name: 'dashboard' })
  }
}

onMounted(() => {
  window.addEventListener('pms:unauthorized', handleUnauthorized)
  window.addEventListener('pms:forbidden', handleForbidden)
})

onBeforeUnmount(() => {
  window.removeEventListener('pms:unauthorized', handleUnauthorized)
  window.removeEventListener('pms:forbidden', handleForbidden)
  window.clearTimeout(accessNoticeTimer)
})
</script>

<template>
  <div class="app-shell" :class="{'no-shell': route.name === 'login'}">
    <aside v-if="route.name !== 'login'" class="sidebar-panel">
      <div class="brand-lockup">
        <div>
          <p class="eyebrow">PMS</p>
          <h1>{{ hotel.hotelName }}</h1>
        </div>
      </div>

      <div class="sidebar-summary">
        <div class="summary-head">
          <span class="status-badge success">Sistem online</span>
          <span class="summary-code">PMS-FO</span>
        </div>
        <p>{{ hotel.tagline }}</p>
      </div>

      <nav class="main-nav">
        <RouterLink
          v-for="item in filteredNavigation"
          :key="item.name"
          :to="{ name: item.name }"
          class="nav-link"
        >
          <span class="nav-icon">{{ item.icon }}</span>
          <span>{{ item.label }}</span>
        </RouterLink>
      </nav>

      <div class="sidebar-footer">
        <div class="summary-head">
          <p class="eyebrow">Ringkasan shift</p>
          <span class="summary-code">Live</span>
        </div>
        <div class="mini-stats">
          <div>
            <strong>{{ hotel.overview[0].value }}</strong>
            <span>Okupansi</span>
          </div>
          <div>
            <strong>{{ hotel.overview[3].value }}</strong>
            <span>Pendapatan hari ini</span>
          </div>
        </div>
      </div>
      
      <div v-if="hotel.user" style="padding: 1.5rem;">
        <button class="action-button" style="width: 100%; border: 1px solid var(--border-color);" @click="handleLogout">Log Out ({{ hotel.user?.name }})</button>
      </div>
    </aside>

    <div class="workspace">
      <header v-if="route.name !== 'login'" class="topbar">
        <div class="topbar-title">
          <div>
            <p class="eyebrow">Front office dan back office</p>
            <h2>{{ currentPage.label }}</h2>
          </div>

          <div class="topbar-switches">
            <span
              v-for="view in quickViews"
              :key="view"
              class="switch-chip"
              :class="{ active: view === currentPage.label || (view === 'Front Office' && currentPage.name === 'dashboard') }"
            >
              {{ view }}
            </span>
          </div>
        </div>

        <div class="topbar-actions">
          <div class="date-chip">{{ hotel.currentDateLabel }}</div>

          <button class="action-button" @click="handleNightAudit">Night audit</button>
          <button class="action-button primary" :disabled="!canCreateBooking" @click="openBookingPage">Booking baru</button>
        </div>
      </header>

      <section v-if="route.name !== 'login'" class="utility-bar">
        <div class="utility-group">
          <span class="utility-label">Filter cepat</span>
          <button class="utility-button active">Hari ini</button>
          <button class="utility-button">Kedatangan</button>
          <button class="utility-button">Keberangkatan</button>
          <button class="utility-button">Sedang menginap</button>
        </div>

        <div class="utility-group">
          <span class="utility-label">Petugas shift</span>
          <button class="utility-button">Manajer FO</button>
          <button class="utility-button">Kasir</button>
          <button class="utility-button">Meja HK</button>
        </div>
      </section>

      <section v-if="accessNotice" class="page-frame" style="padding-bottom: 0;">
        <div class="booking-feedback error" style="margin-bottom: 0;">
          {{ accessNotice }}
        </div>
      </section>

      <main class="page-frame">
        <RouterView />
      </main>

      <NightAuditModal 
        v-if="showNightAuditModal && route.name !== 'login'" 
        @close="showNightAuditModal = false" 
      />
    </div>
  </div>
</template>

<style>
.no-shell {
  display: block;
}
.no-shell .workspace {
  margin-left: 0;
  padding: 0;
  height: 100vh;
}
.no-shell .page-frame {
  height: 100vh;
  margin: 0;
  padding: 0;
  border-radius: 0;
}
</style>
