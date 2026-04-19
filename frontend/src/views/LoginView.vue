<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useHotelStore } from '../stores/hotel'

const router = useRouter()
const hotel = useHotelStore()

const username = ref('')
const password = ref('')
const errorMsg = ref(null)
const loading = ref(false)

const handleLogin = async () => {
  errorMsg.value = null
  loading.value = true
  
  try {
    await hotel.login(username.value, password.value)
    router.push({ name: 'dashboard' })
  } catch (err) {
    errorMsg.value = err?.response?.data?.message || err.message || 'Login failed. Please check your username and password.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="login-wrapper">
    <div class="login-split">
      
      <!-- Brand & Graphic Side (Left) -->
      <div class="login-hero">
        <div class="hero-overlay"></div>
        <div class="hero-content">
          <div class="brand-badge">Udara Premium</div>
          <h1>Elevate Your Hospitality Management.</h1>
          <p>The definitive property management system bridging sophisticated automation with an elegant, intuitive interface.</p>
        </div>
      </div>

      <!-- Form Side (Right) -->
      <div class="login-form-container">
        <div class="login-card">
          <div class="login-header">
            <h2>Welcome Back</h2>
            <p>Please enter your credentials to access the portal.</p>
          </div>

          <form @submit.prevent="handleLogin" class="auth-form">
            <div class="input-group">
              <label for="username">Username</label>
              <div class="input-wrapper">
                <span class="input-icon">
                  <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                  </svg>
                </span>
                <input 
                  id="username"
                  v-model="username" 
                  type="text" 
                  required 
                  placeholder="e.g. admin / fo / hk / email" 
                />
              </div>
            </div>

            <div class="input-group">
              <label for="password">Password</label>
              <div class="input-wrapper">
                <span class="input-icon">
                  <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                  </svg>
                </span>
                <input 
                  id="password"
                  v-model="password" 
                  type="password" 
                  required 
                  placeholder="Enter your password..." 
                />
              </div>
            </div>

            <div v-if="errorMsg" class="error-message">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
              </svg>
              <span>{{ errorMsg }}</span>
            </div>

            <button type="submit" class="submit-button" :class="{ 'loading': loading }" :disabled="loading">
              <span v-if="!loading">Sign In to Dashboard</span>
              <span v-else class="loader"></span>
            </button>
          </form>
          
          <div class="login-footer">
            <p>Need assistance? <a href="#">Contact Support</a></p>
          </div>
        </div>
      </div>

    </div>
  </div>
</template>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600&display=swap');

.login-wrapper {
  width: 100vw;
  height: 100vh;
  margin: 0;
  padding: 0;
  display: flex;
  background-color: #f8fafc;
  font-family: 'Plus Jakarta Sans', sans-serif;
  overflow: hidden;
}

.login-split {
  display: flex;
  width: 100%;
  height: 100%;
}

/* LEFT SIDE - HERO */
.login-hero {
  flex: 1;
  position: relative;
  display: none;
  background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
  overflow: hidden;
}

@media (min-width: 900px) {
  .login-hero {
    display: flex;
    align-items: center;
    justify-content: center;
  }
}

.hero-overlay {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background-image: radial-gradient(circle at 20% 150%, rgba(56, 189, 248, 0.15) 0%, transparent 50%),
                    radial-gradient(circle at 80% -20%, rgba(129, 140, 248, 0.15) 0%, transparent 50%);
  z-index: 1;
}

/* Subtle animated background elements */
.login-hero::before, .login-hero::after {
  content: '';
  position: absolute;
  border-radius: 50%;
  filter: blur(80px);
  z-index: 0;
  animation: float 10s infinite ease-in-out alternate;
}

.login-hero::before {
  width: 400px;
  height: 400px;
  background: rgba(56, 189, 248, 0.1);
  top: -100px;
  left: -100px;
}

.login-hero::after {
  width: 500px;
  height: 500px;
  background: rgba(99, 102, 241, 0.1);
  bottom: -150px;
  right: -100px;
  animation-delay: -5s;
}

@keyframes float {
  0% { transform: translate(0, 0); }
  100% { transform: translate(30px, 50px); }
}

.hero-content {
  position: relative;
  z-index: 2;
  max-width: 520px;
  padding: 4rem;
  color: #ffffff;
  animation: fadeUp 1s ease-out forwards;
}

.brand-badge {
  display: inline-block;
  padding: 0.5rem 1rem;
  background: rgba(255, 255, 255, 0.1);
  border: 1px solid rgba(255, 255, 255, 0.15);
  border-radius: 100px;
  font-size: 0.85rem;
  font-weight: 500;
  letter-spacing: 0.5px;
  text-transform: uppercase;
  margin-bottom: 2rem;
  backdrop-filter: blur(10px);
}

.hero-content h1 {
  font-family: 'Outfit', sans-serif;
  font-size: 3.5rem;
  font-weight: 600;
  line-height: 1.1;
  margin-bottom: 1.5rem;
  background: linear-gradient(to right, #ffffff, #94a3b8);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
}

.hero-content p {
  font-size: 1.15rem;
  line-height: 1.7;
  color: #cbd5e1;
  font-weight: 400;
}

/* RIGHT SIDE - FORM */
.login-form-container {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #ffffff;
  position: relative;
}

.login-card {
  width: 100%;
  max-width: 440px;
  padding: 2.5rem;
  animation: slideIn 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}

.login-header {
  margin-bottom: 2.5rem;
  text-align: left;
}

.login-header h2 {
  font-family: 'Outfit', sans-serif;
  font-size: 2.25rem;
  font-weight: 600;
  color: #0f172a;
  margin-bottom: 0.5rem;
  letter-spacing: -0.5px;
}

.login-header p {
  color: #64748b;
  font-size: 1rem;
}

.auth-form {
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}

.input-group {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.input-group label {
  font-size: 0.9rem;
  font-weight: 600;
  color: #334155;
}

.input-wrapper {
  position: relative;
  display: flex;
  align-items: center;
}

.input-icon {
  position: absolute;
  left: 1rem;
  color: #94a3b8;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: color 0.3s ease;
}

.input-wrapper input {
  width: 100%;
  padding: 0.875rem 1rem 0.875rem 2.75rem;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  font-size: 1rem;
  color: #0f172a;
  background: #f8fafc;
  transition: all 0.3s ease;
  font-family: inherit;
}

.input-wrapper input::placeholder {
  color: #94a3b8;
}

.input-wrapper input:hover {
  border-color: #cbd5e1;
}

.input-wrapper input:focus {
  outline: none;
  border-color: #3b82f6;
  background: #ffffff;
  box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
}

.input-wrapper input:focus + .input-icon,
.input-wrapper input:focus ~ .input-icon {
  color: #3b82f6;
}

.error-message {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.75rem 1rem;
  background: #fef2f2;
  border: 1px solid #fecaca;
  border-radius: 8px;
  color: #dc2626;
  font-size: 0.875rem;
  font-weight: 500;
  animation: shake 0.5s cubic-bezier(0.36, 0.07, 0.19, 0.97) both;
}

.error-message svg {
  width: 1.25rem;
  height: 1.25rem;
  flex-shrink: 0;
}

.submit-button {
  margin-top: 0.5rem;
  width: 100%;
  padding: 1rem;
  background: linear-gradient(to right, #2563eb, #3b82f6);
  color: white;
  border: none;
  border-radius: 12px;
  font-size: 1rem;
  font-weight: 600;
  cursor: pointer;
  display: flex;
  justify-content: center;
  align-items: center;
  transition: all 0.3s ease;
  box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
}

.submit-button:hover:not(:disabled) {
  transform: translateY(-2px);
  box-shadow: 0 6px 16px rgba(37, 99, 235, 0.3);
  background: linear-gradient(to right, #1d4ed8, #2563eb);
}

.submit-button:active:not(:disabled) {
  transform: translateY(0);
}

.submit-button:disabled {
  opacity: 0.7;
  cursor: not-allowed;
}

.loader {
  width: 20px;
  height: 20px;
  border: 2px solid rgba(255,255,255,0.3);
  border-radius: 50%;
  border-top-color: white;
  animation: spin 0.8s linear infinite;
}

.login-footer {
  margin-top: 2rem;
  text-align: center;
  font-size: 0.9rem;
  color: #64748b;
}

.login-footer a {
  color: #3b82f6;
  text-decoration: none;
  font-weight: 500;
  transition: color 0.2s;
}

.login-footer a:hover {
  color: #1d4ed8;
  text-decoration: underline;
}

/* Animations */
@keyframes slideIn {
  from { opacity: 0; transform: translateY(20px); }
  to { opacity: 1; transform: translateY(0); }
}

@keyframes fadeUp {
  from { opacity: 0; transform: translateY(30px); }
  to { opacity: 1; transform: translateY(0); }
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

@keyframes shake {
  10%, 90% { transform: translate3d(-1px, 0, 0); }
  20%, 80% { transform: translate3d(2px, 0, 0); }
  30%, 50%, 70% { transform: translate3d(-3px, 0, 0); }
  40%, 60% { transform: translate3d(3px, 0, 0); }
}
</style>
