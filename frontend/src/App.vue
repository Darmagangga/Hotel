<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import { useHotelStore } from './stores/hotel'
import NightAuditModal from './components/NightAuditModal.vue'
import api from './services/api'

const route = useRoute()
const router = useRouter()
const hotel = useHotelStore()

const navigation = [
  {
    key: 'operations-group',
    label: 'Operations',
    icon: 'OP',
    children: [
      { name: 'dashboard', label: 'Dashboard', icon: 'DB' },
      { name: 'bookings', label: 'Reservations', icon: 'RS' },
      { name: 'rooms', label: 'Rooms', icon: 'RM' },
    ],
  },
  {
    key: 'finance-group',
    label: 'Finance',
    icon: 'FN',
    children: [
      { name: 'finance', label: 'Invoice', icon: 'IN' },
      { name: 'journals', label: 'Journal', icon: 'JU' },
      { name: 'coa', label: 'COA', icon: 'GL' },
      { name: 'vendor-payables', label: 'Activity Payables', icon: 'AP', permission: 'activities' },
    ],
  },
  {
    key: 'inventory-group',
    label: 'Inventory',
    icon: 'IV',
    children: [
      { name: 'inventory', label: 'Inventory', icon: 'IV' },
      { name: 'inventory-purchases', label: 'Purchase POS', icon: 'PO', permission: 'inventory' },
    ],
  },
  {
    key: 'master-group',
    label: 'Master',
    icon: 'MS',
    children: [
      { name: 'master-units', label: 'Satuan', icon: 'ST', permission: 'inventory' },
    ],
  },
  {
    key: 'activities-group',
    label: 'Activities',
    icon: 'AC',
    children: [
      { name: 'activities', label: 'Catalog', icon: 'AC' },
      { name: 'transport', label: 'Transport', icon: 'TR' },
      { name: 'activity-vendors', label: 'Vendors', icon: 'VD', permission: 'activities' },
    ],
  },
  {
    key: 'reports-group',
    label: 'Reports',
    icon: 'RP',
    children: [
      { key: 'report-profit-loss', name: 'reports', label: 'Profit & Loss', icon: 'PL', query: { tab: 'labarugi' } },
      { key: 'report-balance-sheet', name: 'reports', label: 'Balance Sheet', icon: 'BS', query: { tab: 'neraca' } },
      { key: 'report-cash-flow', name: 'reports', label: 'Cash Flow', icon: 'CF', query: { tab: 'aruskas' } },
      { key: 'report-room-status', name: 'reports', label: 'Room Status', icon: 'RS', query: { tab: 'roomstatus' } },
      { key: 'report-ledger', name: 'reports', label: 'General Ledger', icon: 'GL', query: { tab: 'bukubesar' } },
      { key: 'report-recon', name: 'reports', label: 'Reconciliation', icon: 'RC', query: { tab: 'rekonsiliasi' } },
      { key: 'report-vendor-payables', name: 'reports', label: 'Vendor Payables', icon: 'VP', query: { tab: 'hutangvendor' }, permission: 'activities' },
      { key: 'report-vendor-ap', name: 'vendor-payables', label: 'Activity Payables', icon: 'AP', permission: 'activities' },
      { key: 'report-audit', name: 'reports', label: 'Audit Trail', icon: 'AT', query: { tab: 'audittrail' } },
    ],
  },
  {
    key: 'admin-group',
    label: 'Administration',
    icon: 'AD',
    children: [
      { name: 'settings', label: 'Settings', icon: 'ST' },
      { name: 'users', label: 'Users', icon: 'US' },
      { name: 'roles', label: 'Access Roles', icon: 'LK' },
    ],
  },
]

const userRole = computed(() => hotel.user?.role || 'admin')
const userPermissions = computed(() => hotel.user?.permissions || [])
const canAccess = (permissionName) =>
  userRole.value === 'admin' || userPermissions.value.includes(permissionName)
const canCreateBooking = computed(() => canAccess('bookings'))

const flattenNavigation = (items) =>
  items.flatMap((item) => (item.children ? item.children : [item]))

const isNavigationMatch = (item) => {
  if (item.name !== route.name) {
    return false
  }

  const expectedTab = item.query?.tab
  if (!expectedTab) {
    return true
  }

  return String(route.query.tab ?? '') === String(expectedTab)
}

const filteredNavigation = computed(() => {
  if (userRole.value === 'admin') return navigation

  return navigation
    .map((item) => {
      if (!item.children) {
        const permissionName = item.permission ?? item.name
        return userPermissions.value.includes(permissionName) || item.name === 'dashboard' ? item : null
      }

      const children = item.children.filter((child) => {
        const permissionName = child.permission ?? child.name
        return userPermissions.value.includes(permissionName)
      })

      return children.length ? { ...item, children } : null
    })
    .filter(Boolean)
})

const filteredLeafNavigation = computed(() => flattenNavigation(filteredNavigation.value))

const currentPage = computed(
  () => (filteredLeafNavigation.value.find((item) => isNavigationMatch(item)) ?? filteredLeafNavigation.value.find((item) => item.name === route.name) ?? filteredLeafNavigation.value[0]) || flattenNavigation(navigation)[0],
)
const sidebarOccupancyValue = computed(() => {
  const overviewItems = Array.isArray(hotel.overview) ? hotel.overview : []
  return overviewItems.find((item) => String(item?.label ?? '').toLowerCase().includes('occupancy'))?.value ?? '0%'
})
const sidebarRevenueValue = computed(() => {
  const revenueItems = Array.isArray(hotel.revenueMix) ? hotel.revenueMix : []
  return revenueItems.find((item) => String(item?.label ?? '').toLowerCase().includes('total revenue'))?.value
    ?? revenueItems.find((item) => String(item?.label ?? '').toLowerCase().includes('room revenue'))?.value
    ?? 'IDR 0'
})

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
const mobileNavOpen = ref(false)
const sidebarCollapsed = ref(false)
const expandedGroups = ref([])

const navigationItemKey = (item) => item.key ?? item.name ?? item.label
const groupHasActiveChild = (item) => Array.isArray(item.children) && item.children.some((child) => isNavigationMatch(child) || child.name === route.name)

const ensureActiveGroupExpanded = () => {
  const activeGroupKeys = filteredNavigation.value
    .filter((item) => item.children && groupHasActiveChild(item))
    .map((item) => navigationItemKey(item))

  expandedGroups.value = Array.from(new Set([...expandedGroups.value, ...activeGroupKeys]))
}

const isGroupExpanded = (item) => expandedGroups.value.includes(navigationItemKey(item))

const toggleGroup = (item) => {
  const key = navigationItemKey(item)

  expandedGroups.value = expandedGroups.value.includes(key)
    ? expandedGroups.value.filter((entry) => entry !== key)
    : [...expandedGroups.value, key]
}

const closeMobileNav = () => {
  mobileNavOpen.value = false
}

const toggleMobileNav = () => {
  mobileNavOpen.value = !mobileNavOpen.value
}

const toggleSidebarCollapsed = () => {
  sidebarCollapsed.value = !sidebarCollapsed.value
}

const syncResponsiveShell = () => {
  if (typeof window === 'undefined') {
    return
  }

  if (window.innerWidth > 960) {
    mobileNavOpen.value = false
  }
}

const handleNightAudit = () => {
  showNightAuditModal.value = true
}

const handleLogout = () => {
  hotel.logout()
  router.push({ name: 'login' })
}

const handleUnauthorized = async (event) => {
  hotel.logout()
  showAccessNotice(event?.detail?.message || 'Your session has ended. Please sign in again.')

  if (route.name !== 'login') {
    await router.push({ name: 'login' })
  }
}

const handleForbidden = async (event) => {
  showAccessNotice(event?.detail?.message || 'You do not have access to this page or action.')

  if (route.name !== 'login') {
    await router.push({ name: 'dashboard' })
  }
}

onMounted(() => {
  ensureActiveGroupExpanded()
  syncResponsiveShell()
  window.addEventListener('resize', syncResponsiveShell)
  window.addEventListener('pms:unauthorized', handleUnauthorized)
  window.addEventListener('pms:forbidden', handleForbidden)

  hotel.loadUserFromStorage()

  if (typeof window === 'undefined') {
    return
  }

  const token = localStorage.getItem('pms_token')
  if (!token) {
    return
  }

  api
    .get('/dashboard/owner')
    .then((response) => {
      const payload = response.data?.data ?? {}
      if (Array.isArray(payload.overview)) {
        hotel.setOverview(payload.overview)
      }
      if (Array.isArray(payload.revenueMix)) {
        hotel.setRevenueMix(payload.revenueMix)
      }
      if (payload.businessDate) {
        hotel.setBusinessDate(payload.businessDate)
      }
      if (payload.currentDateLabel) {
        hotel.setCurrentDateLabel(payload.currentDateLabel)
      }
    })
    .catch((error) => {
      console.error('Failed to sync business date on app start:', error)
    })
})

onBeforeUnmount(() => {
  window.removeEventListener('resize', syncResponsiveShell)
  window.removeEventListener('pms:unauthorized', handleUnauthorized)
  window.removeEventListener('pms:forbidden', handleForbidden)
  window.clearTimeout(accessNoticeTimer)
})

watch(
  () => route.fullPath,
  () => {
    ensureActiveGroupExpanded()
    closeMobileNav()
  },
)

watch(
  () => filteredNavigation.value,
  () => {
    ensureActiveGroupExpanded()
  },
  { deep: true, immediate: true },
)
</script>

<template>
  <div class="app-shell" :class="[{ 'no-shell': route.name === 'login' }, { 'mobile-nav-open': mobileNavOpen }, { 'sidebar-collapsed': sidebarCollapsed }]">
    <div
      v-if="route.name !== 'login' && mobileNavOpen"
      class="mobile-nav-backdrop"
      @click="closeMobileNav"
    ></div>

    <aside v-if="route.name !== 'login'" class="sidebar-panel" :class="{ 'is-open': mobileNavOpen, 'is-collapsed': sidebarCollapsed }">
      <div class="brand-lockup">
        <div>
          <p class="eyebrow">PMS</p>
          <h1>{{ hotel.hotelName }}</h1>
        </div>
      </div>

      <div class="sidebar-scroll">
        <nav class="main-nav">
          <template v-for="item in filteredNavigation" :key="item.key ?? item.name">
            <div
              v-if="item.children"
              class="nav-group"
              :class="{ active: groupHasActiveChild(item), expanded: isGroupExpanded(item) }"
            >
              <button
                type="button"
                class="nav-group-label nav-group-trigger"
                @click="toggleGroup(item)"
              >
                <span class="nav-group-main">
                  <span class="nav-icon">{{ item.icon }}</span>
                  <span>{{ item.label }}</span>
                </span>
                <span class="nav-group-caret">{{ isGroupExpanded(item) ? '−' : '+' }}</span>
              </button>
              <div v-if="isGroupExpanded(item)" class="nav-submenu">
                <RouterLink
                  v-for="child in item.children"
                  :key="child.key ?? child.name"
                  :to="{ name: child.name, query: child.query }"
                  custom
                  v-slot="{ href, navigate }"
                >
                  <a
                    :href="href"
                    class="nav-link nav-link-sub"
                    :class="{ 'router-link-active': isNavigationMatch(child) }"
                    @click="navigate"
                  >
                    <span class="nav-icon">{{ child.icon }}</span>
                    <span>{{ child.label }}</span>
                  </a>
                </RouterLink>
              </div>
            </div>

            <RouterLink
              v-else
              :to="{ name: item.name }"
              class="nav-link"
            >
              <span class="nav-icon">{{ item.icon }}</span>
              <span>{{ item.label }}</span>
            </RouterLink>
          </template>
        </nav>

        <div class="sidebar-footer">
          <div class="summary-head">
            <p class="eyebrow">Shift summary</p>
            <span class="summary-code">Live</span>
          </div>
          <div class="mini-stats">
            <div>
              <strong>{{ sidebarOccupancyValue }}</strong>
              <span>Occupancy</span>
            </div>
            <div>
              <strong>{{ sidebarRevenueValue }}</strong>
              <span>Revenue today</span>
            </div>
          </div>
        </div>

        <div v-if="hotel.user" class="sidebar-user-actions">
          <button class="action-button sidebar-logout-button" @click="handleLogout">Log Out ({{ hotel.user?.name }})</button>
        </div>
      </div>
    </aside>

    <div class="workspace">
      <header v-if="route.name !== 'login'" class="topbar">
        <div class="topbar-title">
          <button type="button" class="mobile-nav-toggle" @click="toggleMobileNav">
            <span></span>
            <span></span>
            <span></span>
          </button>
          <button
            v-if="route.name !== 'login'"
            type="button"
            class="sidebar-collapse-toggle"
            @click="toggleSidebarCollapsed"
          >
            {{ sidebarCollapsed ? '→' : '←' }}
          </button>
          <div>
            <h2>{{ currentPage.label }}</h2>
          </div>
        </div>

        <div class="topbar-actions">
          <div class="date-chip">{{ hotel.currentDateLabel }}</div>

          <button class="action-button" @click="handleNightAudit">Night audit</button>
          <button class="action-button primary" :disabled="!canCreateBooking" @click="openBookingPage">New booking</button>
        </div>
      </header>

      <section v-if="accessNotice" class="page-frame page-notice-frame" style="padding-bottom: 0;">
        <div class="booking-feedback error" style="margin-bottom: 0;">
          {{ accessNotice }}
        </div>
      </section>

      <main class="page-frame main-page-frame">
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
