<script setup>
import { ref, onMounted } from 'vue'
import api from '../services/api'
import { useHotelStore } from '../stores/hotel'
import LoadingState from './LoadingState.vue'

const emit = defineEmits(['close', 'success'])
const hotel = useHotelStore()

const loading = ref(true)
const executing = ref(false)
const statusData = ref(null)
const loadError = ref(null)
const execError = ref(null)

const loadStatus = async () => {
  loading.value = true
  loadError.value = null
  try {
    const res = await api.get('/night-audit/status')
    statusData.value = res.data
  } catch (err) {
    loadError.value = 'Gagal memuat status sistem: ' + (err.response?.data?.message || err.message)
  } finally {
    loading.value = false
  }
}

const runAudit = async () => {
  if (statusData.value?.pending_checkouts > 0) {
    alert("Proses diblokir! Masih ada tamu overstay. Silakan lakukan Check-Out atau Extend mereka terlebih dahulu di menu reservasi.")
    return
  }
  
  executing.value = true
  execError.value = null
  try {
    const res = await hotel.runNightAudit()
    alert(res.message + '\n\nTotal ' + (res.data?.folios_processed || 0) + ' folio tamu sukses ditagihkan untuk bermalam.')
    window.location.reload()
  } catch (err) {
    const apiErrors = err?.response?.data?.errors
    if (Array.isArray(apiErrors) && apiErrors.length > 0) {
      execError.value = apiErrors.join('\n\n')
    } else {
      execError.value = 'Gagal menjalankan Night Audit: ' + (err?.response?.data?.message || err.message)
    }
  } finally {
    executing.value = false
  }
}

onMounted(() => {
  loadStatus()
})
</script>

<template>
  <div class="modal-backdrop" @click.self="emit('close')">
    <div class="modal-card" style="max-width: 600px;">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">SOP Akuntansi</p>
          <h3 style="display: flex; align-items: center; gap: 0.5rem;">
            🌙 Tutup Buku (Night Audit)
          </h3>
        </div>
        <button class="action-button" @click="emit('close')">Close</button>
      </div>

      <div class="booking-form-grid" style="padding: 1.5rem; background: var(--surface-2);">
        <LoadingState v-if="loading" label="Melakukan Pengecekan Sistem (System Check)..." />

        <div v-else-if="loadError" class="booking-feedback error">
          {{ loadError }}
          <button class="action-button" style="margin-top: 1rem;" @click="loadStatus">Coba Lagi</button>
        </div>

        <div v-else-if="statusData">
          <div class="booking-timeline" style="margin-bottom: 2rem;">
            
            <!-- STEP 1: PENDING CHECKOUTS -->
            <div class="timeline-step">
              <div class="step-indicator" :class="{ 'step-error': statusData.pending_checkouts > 0, 'step-success': statusData.pending_checkouts === 0 }">
                 {{ statusData.pending_checkouts > 0 ? '❌' : '✅' }}
              </div>
              <div class="step-content">
                <strong>Pengecekan Keberangkatan (Departures)</strong>
                <p v-if="statusData.pending_checkouts > 0" style="color: darkred; font-size: 0.9rem;">
                  Terdapat <strong>{{ statusData.pending_checkouts }} tamu Overstay</strong>. Anda Batal menjalankan Night Audit. Tamu ini wajib di Check-Out atau tagihannya di-Extend agar proses ini bisa berjalan!
                </p>
                <p v-else style="color: darkgreen; font-size: 0.9rem;">Semua tamu yang check-out hari ini sudah tuntas diselesaikan.</p>
              </div>
            </div>

            <!-- STEP 2: UNRESOLVED ARRIVALS -->
            <div class="timeline-step">
              <div class="step-indicator" :class="{ 'step-warning': statusData.unresolved_arrivals > 0, 'step-success': statusData.unresolved_arrivals === 0 }">
                {{ statusData.unresolved_arrivals > 0 ? '⚠️' : '✅' }}
              </div>
              <div class="step-content">
                <strong>Anomali Kedatangan (No-Shows)</strong>
                <p v-if="statusData.unresolved_arrivals > 0" style="color: #c97e00; font-size: 0.9rem;">
                  Terdapat <strong>{{ statusData.unresolved_arrivals }} tamu</strong> yang tidak datang hari ini. Sistem akan menghanguskan (No-Show/Cancel) mereka secara paksa dan membuka kembali kamarnya.
                </p>
                <p v-else style="color: darkgreen; font-size: 0.9rem;">Semua reservasi tamu yang tiba hari ini sudah ditangani dengan baik.</p>
              </div>
            </div>

            <!-- STEP 3: EXECUTION PREVIEW -->
            <div class="timeline-step">
              <div class="step-indicator step-primary">⚙️</div>
              <div class="step-content">
                <strong>Konfirmasi Pemrosesan Akhir Hari</strong>
                <ul style="margin: 0.5rem 0 0 1rem; font-size: 0.9rem; color: var(--text-muted); line-height: 1.5;">
                  <li><strong>{{ statusData.active_in_house }} Folio Kamar</strong> akan diposting tagihan harga kamar malam ini (Termasuk Pajak).</li>
                  <li><strong>{{ statusData.active_in_house }} Status Kamar</strong> akan diubah otomatis menjadi Kotor (Dirty) untuk Housekeeping.</li>
                  <li>Tanggal bisnis sistem akan dimajukan melewati {{ statusData.audit_date }}.</li>
                </ul>
              </div>
            </div>

          </div>

          <!-- EXECUTION ERROR FEEDBACK -->
          <div v-if="execError" class="booking-feedback error" style="margin-top: 1rem; white-space: pre-line;">
            {{ execError }}
          </div>
        </div>
      </div>

      <div class="modal-actions" style="margin-top: 1rem;">
        <button class="action-button" @click="emit('close')" :disabled="executing">Kembali</button>
        
        <button 
          v-if="!loading && !loadError"
          class="action-button primary" 
          :disabled="statusData?.pending_checkouts > 0 || executing"
          @click="runAudit"
        >
          <span v-if="executing">Memproses (Jangan ditutup)...</span>
          <span v-else>Jalankan Tutup Buku (Process Audit)</span>
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.step-indicator {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: bold;
  font-size: 1.2rem;
  background: var(--surface-1);
}
.step-error { background: #fee2e2; }
.step-warning { background: #fef08a; }
.step-success { background: #dcfce7; }
.step-primary { background: var(--primary); color: white; }
</style>
