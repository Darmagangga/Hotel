import { createRouter, createWebHistory } from 'vue-router'
import { useHotelStore } from '../stores/hotel'
import DashboardView from '../views/DashboardView.vue'
import BookingsView from '../views/BookingsView.vue'
import BookingCreateView from '../views/BookingCreateView.vue'
import RoomsView from '../views/RoomsView.vue'
import FinanceView from '../views/FinanceView.vue'
import JournalView from '../views/JournalView.vue'
import CoaView from '../views/CoaView.vue'
import InventoryView from '../views/InventoryView.vue'
import InventoryPurchasePosView from '../views/InventoryPurchasePosView.vue'
import TransportView from '../views/TransportView.vue'
import ActivitiesView from '../views/ActivitiesView.vue'
import ReportsView from '../views/ReportsView.vue'
import LoginView from '../views/LoginView.vue'
import UsersView from '../views/UsersView.vue'
import RolesView from '../views/RolesView.vue'
import SettingsView from '../views/SettingsView.vue'

const routerBase = import.meta.env.PROD ? '/flux/' : '/'

const canAccessRoute = (user, permission) => {
  if (!permission) {
    return true
  }

  if (user?.role === 'admin') {
    return true
  }

  const permissions = Array.isArray(user?.permissions) ? user.permissions : []
  return permissions.includes(permission)
}

const router = createRouter({
  history: createWebHistory(routerBase),
  routes: [
    { path: '/login', name: 'login', component: LoginView },
    { path: '/', name: 'dashboard', component: DashboardView, meta: { requiresAuth: true, permission: 'dashboard' } },
    { path: '/bookings', name: 'bookings', component: BookingsView, meta: { requiresAuth: true, permission: 'bookings' } },
    { path: '/bookings/new', name: 'booking-create', component: BookingCreateView, meta: { requiresAuth: true, permission: 'bookings' } },
    { path: '/bookings/:bookingCode/edit', name: 'booking-edit', component: BookingCreateView, meta: { requiresAuth: true, permission: 'bookings' } },
    { path: '/rooms', name: 'rooms', component: RoomsView, meta: { requiresAuth: true, permission: 'rooms' } },
    { path: '/finance', name: 'finance', component: FinanceView, meta: { requiresAuth: true, permission: 'finance' } },
    { path: '/journals', name: 'journals', component: JournalView, meta: { requiresAuth: true, permission: 'journals' } },
    { path: '/coa', name: 'coa', component: CoaView, meta: { requiresAuth: true, permission: 'coa' } },
    { path: '/inventory', name: 'inventory', component: InventoryView, meta: { requiresAuth: true, permission: 'inventory' } },
    { path: '/inventory/purchases', name: 'inventory-purchases', component: InventoryPurchasePosView, meta: { requiresAuth: true, permission: 'inventory' } },
    { path: '/transport', name: 'transport', component: TransportView, meta: { requiresAuth: true, permission: 'transport' } },
    { path: '/activities', name: 'activities', component: ActivitiesView, meta: { requiresAuth: true, permission: 'activities' } },
    { path: '/reports', name: 'reports', component: ReportsView, meta: { requiresAuth: true, permission: 'reports' } },
    { path: '/settings', name: 'settings', component: SettingsView, meta: { requiresAuth: true, permission: 'settings' } },
    { path: '/users', name: 'users', component: UsersView, meta: { requiresAuth: true, permission: 'users' } },
    { path: '/roles', name: 'roles', component: RolesView, meta: { requiresAuth: true, permission: 'roles' } },
    { path: '/services', redirect: '/activities' },
  ],
})

router.beforeEach((to, from, next) => {
  const hotelStore = useHotelStore()
  const isAuthenticated = !!hotelStore.user

  if (!isAuthenticated) {
    hotelStore.loadUserFromStorage()
  }

  if (to.meta.requiresAuth && !hotelStore.user) {
    next({ name: 'login' })
    return
  }

  if (to.name === 'login' && hotelStore.user) {
    next({ name: 'dashboard' })
    return
  }

  if (to.meta.requiresAuth && !canAccessRoute(hotelStore.user, to.meta.permission)) {
    const fallbackRoute = canAccessRoute(hotelStore.user, 'dashboard') ? { name: 'dashboard' } : { name: 'login' }
    next(fallbackRoute)
    return
  }

  next()
})

export default router
