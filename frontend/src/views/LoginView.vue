<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useHotelStore } from '../stores/hotel'

const router = useRouter()
const hotel = useHotelStore()

const email = ref('')
const password = ref('')
const errorMsg = ref(null)
const loading = ref(false)

const handleLogin = async () => {
  errorMsg.value = null
  loading.value = true
  
  try {
    await hotel.login(email.value, password.value)
    router.push({ name: 'dashboard' })
  } catch (err) {
    errorMsg.value = err.message || 'Login gagal. Periksa kembali email dan password Anda.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="login-wrapper">
    <div class="login-split">
      
      <!-- Kiri: Brand & Graphic -->
      <div class="login-hero">
        <div class="hero-content">
          <h1>Sagara Bay Suites</h1>
          <p>Enterprise Property Management System (PMS)<br/>Powered by Advanced Automations.</p>
        </div>
      </div>

      <!-- Kanan: Form -->
      <div class="login-form-container">
        <div class="login-card">
          <div style="margin-bottom: 2rem;">
            <p class="eyebrow-dark">Selamat Datang</p>
            <h2 style="font-size: 2rem; color: var(--text-main);">Masuk ke Portal</h2>
          </div>

          <form @submit.prevent="handleLogin" class="booking-form-grid" style="gap: 1.5rem; max-width: 100%;">
            <label class="field-stack" style="grid-column: span 1 / span 2;">
              <span>Alamat Email</span>
              <input 
                v-model="email" 
                type="email" 
                required 
                class="form-control" 
                placeholder="cth: admin@sagarabay.com" 
              />
            </label>

            <label class="field-stack" style="grid-column: span 1 / span 2;">
              <span>Kata Sandi</span>
              <input 
                v-model="password" 
                type="password" 
                required 
                class="form-control" 
                placeholder="Masukkan kata sandi..." 
              />
            </label>

            <div v-if="errorMsg" class="booking-feedback error" style="grid-column: span 1 / span 2; margin:0;">
              {{ errorMsg }}
            </div>

            <button type="submit" class="action-button primary" style="grid-column: span 1 / span 2; justify-content: center; padding: 1rem;" :disabled="loading">
              {{ loading ? 'Otentikasi...' : 'Masuk Sekarang' }}
            </button>
          </form>

        </div>
      </div>

    </div>
  </div>
</template>

<style scoped>
.login-wrapper {
  width: 100vw;
  height: 100vh;
  display: flex;
  background: var(--surface-1);
}

.login-split {
  display: flex;
  width: 100%;
  height: 100%;
}

.login-hero {
  flex: 1;
  background: linear-gradient(135deg, var(--primary) 0%, #1e3a8a 100%);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 4rem;
  color: white;
}

.hero-content {
  max-width: 500px;
}

.hero-content h1 {
  font-size: 3.5rem;
  font-family: var(--font-display);
  margin-bottom: 1rem;
  line-height: 1.1;
}

.hero-content p {
  font-size: 1.2rem;
  opacity: 0.9;
  line-height: 1.6;
}

.login-form-container {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 2rem;
  background: var(--surface-1);
}

.login-card {
  width: 100%;
  max-width: 450px;
  background: var(--surface-1);
  padding: 3rem;
  border-radius: 16px;
  box-shadow: 0 10px 25px rgba(0,0,0,0.05);
  border: 1px solid var(--border-color);
}
</style>
