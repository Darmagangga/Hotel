<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '../services/api'
import { useHotelStore } from '../stores/hotel'
import LoadingState from './LoadingState.vue'

const emit = defineEmits(['close', 'success'])
const hotel = useHotelStore()

const loading = ref(true)
const executing = ref(false)
const statusData = ref(null)
const historyRows = ref([])
const loadError = ref(null)
const execError = ref(null)
const successData = ref(null)
const nextDatePreview = computed(() => successData.value?.nextBusinessDate || statusData.value?.next_business_date || '-')

const loadStatus = async () => {
  loading.value = true
  loadError.value = null

  try {
    const [statusRes, historyRes] = await Promise.all([
      api.get('/night-audit/status'),
      api.get('/night-audit/history'),
    ])
    statusData.value = statusRes.data
    historyRows.value = Array.isArray(historyRes.data?.data) ? historyRes.data.data : []
  } catch (err) {
    loadError.value = 'Failed to load system status: ' + (err.response?.data?.message || err.message)
  } finally {
    loading.value = false
  }
}

const runAudit = async () => {
  if (statusData.value?.pending_checkouts > 0) {
    alert('Process blocked. There are still overstaying guests. Please check them out or extend their stay first from the reservations menu.')
    return
  }

  executing.value = true
  execError.value = null
  successData.value = null

  try {
    const res = await hotel.runNightAudit()
    successData.value = res.data ?? null
    if (res.data?.nextBusinessDate) {
      hotel.setBusinessDate(res.data.nextBusinessDate)
    }
    if (res.data?.currentDateLabel) {
      hotel.setCurrentDateLabel(res.data.currentDateLabel)
    }
    await loadStatus()
    emit('success', res)
  } catch (err) {
    const apiErrors = err?.response?.data?.errors
    if (Array.isArray(apiErrors) && apiErrors.length > 0) {
      execError.value = apiErrors.join('\n\n')
    } else {
      execError.value = 'Failed to run Night Audit: ' + (err?.response?.data?.message || err.message)
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
    <div class="modal-card night-audit-modal">
      <div class="panel-head panel-head-tight night-audit-head">
        <div class="night-audit-head-copy">
          <p class="eyebrow-dark">Daily Closing Control</p>
          <h3>Night Audit</h3>
          <p class="night-audit-subtitle">Review operational exceptions, close the current business date, and move the property to the next day with a cleaner control screen.</p>
        </div>
        <button class="action-button" @click="emit('close')">Close</button>
      </div>

      <div class="night-audit-body">
        <LoadingState v-if="loading" label="Running system check..." />

        <div v-else-if="loadError" class="booking-feedback error">
          {{ loadError }}
          <button class="action-button" style="margin-top: 1rem;" @click="loadStatus">Try again</button>
        </div>

        <div v-else-if="statusData">
          <div class="night-audit-banner">
            <div class="night-audit-banner-card night-audit-banner-card-primary">
              <p class="night-audit-banner-label">Current Business Date</p>
              <strong>{{ statusData.business_date_label }}</strong>
              <span>Active operating day</span>
            </div>
            <div class="night-audit-banner-card">
              <p class="night-audit-banner-label">Next Business Date</p>
              <strong>{{ nextDatePreview }}</strong>
              <span>Will be activated after closing</span>
            </div>
          </div>

          <div class="night-audit-stats">
            <div class="night-audit-stat">
              <span class="night-audit-stat-label">Pending Check-outs</span>
              <strong>{{ statusData.pending_checkouts }}</strong>
              <p>Guests that must be resolved before closing.</p>
            </div>
            <div class="night-audit-stat">
              <span class="night-audit-stat-label">Unresolved Arrivals</span>
              <strong>{{ statusData.unresolved_arrivals }}</strong>
              <p>Reservations that may become cancel or no-show.</p>
            </div>
            <div class="night-audit-stat">
              <span class="night-audit-stat-label">In-House Snapshot</span>
              <strong>{{ statusData.active_in_house }}</strong>
              <p>Occupied rooms affected by the daily closing flow.</p>
            </div>
          </div>

          <div class="night-audit-money">
            <div class="night-audit-money-head">
              <div>
                <p class="eyebrow-dark">Owner Closing</p>
                <h4>Daily cash summary</h4>
              </div>
              <span class="status-badge success">{{ statusData.closing_summary?.netCollectionsLabel || 'IDR 0' }}</span>
            </div>

            <div class="night-audit-money-grid">
              <div class="night-audit-money-card night-audit-money-card-primary">
                <span>Gross collection</span>
                <strong>{{ statusData.closing_summary?.grossCollectionsLabel || 'IDR 0' }}</strong>
                <p>Total payment posted on this business date.</p>
              </div>
              <div class="night-audit-money-card">
                <span>Refund / void</span>
                <strong>{{ statusData.closing_summary?.refundsVoidsLabel || 'IDR 0' }}</strong>
                <p>Reversal amount affecting the daily close.</p>
              </div>
              <div class="night-audit-money-card">
                <span>Cash on hand</span>
                <strong>{{ statusData.closing_summary?.cashLabel || 'IDR 0' }}</strong>
                <p>Cash payment collected for the day.</p>
              </div>
              <div class="night-audit-money-card">
                <span>Bank transfer</span>
                <strong>{{ statusData.closing_summary?.bankTransferLabel || 'IDR 0' }}</strong>
                <p>Transfer collection posted today.</p>
              </div>
              <div class="night-audit-money-card">
                <span>Card</span>
                <strong>{{ statusData.closing_summary?.cardLabel || 'IDR 0' }}</strong>
                <p>Credit and debit card combined.</p>
              </div>
              <div class="night-audit-money-card">
                <span>QRIS</span>
                <strong>{{ statusData.closing_summary?.qrisLabel || 'IDR 0' }}</strong>
                <p>Digital wallet and QR collection.</p>
              </div>
            </div>
          </div>

          <div class="booking-timeline night-audit-timeline">
            <div class="timeline-step night-audit-step">
              <div class="step-indicator" :class="{ 'step-error': statusData.pending_checkouts > 0, 'step-success': statusData.pending_checkouts === 0 }">
                {{ statusData.pending_checkouts > 0 ? 'X' : 'OK' }}
              </div>
              <div class="step-content">
                <strong>Departure check</strong>
                <p v-if="statusData.pending_checkouts > 0" class="night-audit-note night-audit-note-danger">
                  There are <strong>{{ statusData.pending_checkouts }} overstaying guests</strong>. Night Audit cannot continue until they are checked out or extended.
                </p>
                <p v-else class="night-audit-note night-audit-note-success">All guests scheduled to depart today have been resolved.</p>
              </div>
            </div>

            <div class="timeline-step night-audit-step">
              <div class="step-indicator" :class="{ 'step-warning': statusData.unresolved_arrivals > 0, 'step-success': statusData.unresolved_arrivals === 0 }">
                {{ statusData.unresolved_arrivals > 0 ? '!' : 'OK' }}
              </div>
              <div class="step-content">
                <strong>Arrival anomaly check</strong>
                <p v-if="statusData.unresolved_arrivals > 0" class="night-audit-note night-audit-note-warning">
                  There are <strong>{{ statusData.unresolved_arrivals }} guests</strong> who did not arrive today. The system will force them to `No-Show` or `Cancel` and release the rooms again.
                </p>
                <p v-else class="night-audit-note night-audit-note-success">All arrivals scheduled for today have been handled.</p>
              </div>
            </div>

            <div class="timeline-step night-audit-step">
              <div class="step-indicator step-primary">GO</div>
              <div class="step-content">
                <strong>End-of-day processing confirmation</strong>
                <ul class="night-audit-checklist">
                  <li><strong>{{ statusData.active_in_house }} room folios</strong> will be posted with tonight's room charge, including tax.</li>
                  <li><strong>{{ statusData.active_in_house }} room statuses</strong> will be automatically changed to `Dirty` for housekeeping follow-up.</li>
                  <li>The system business date will advance from {{ statusData.business_date }} to {{ statusData.next_business_date }}.</li>
                </ul>
              </div>
            </div>
          </div>

          <div v-if="successData" class="booking-feedback success night-audit-feedback">
            <strong>Daily closing completed.</strong>
            <p style="margin: 0.35rem 0 0;">{{ successData.summary?.foliosProcessed || 0 }} unresolved folio(s) were processed and the business date is now {{ successData.nextBusinessDate }}.</p>
          </div>

          <div v-if="execError" class="booking-feedback error night-audit-feedback" style="white-space: pre-line;">
            {{ execError }}
          </div>

          <div class="night-audit-history">
            <div class="night-audit-history-head">
              <strong>Recent Daily Closing</strong>
              <span>{{ historyRows.length }} record(s)</span>
            </div>
            <div v-if="historyRows.length === 0" class="night-audit-history-empty">No closing history yet.</div>
            <div v-else class="night-audit-history-list">
              <div v-for="row in historyRows" :key="row.id" class="night-audit-history-row">
                <div>
                  <strong>{{ row.businessDate }}</strong>
                  <p>Next: {{ row.nextBusinessDate }}</p>
                </div>
                <div>
                  <strong>{{ row.closingAmount }}</strong>
                  <p>Net collection</p>
                </div>
                <div>
                  <strong>{{ row.cashAmount }}</strong>
                  <p>Cash on hand</p>
                </div>
                <div>
                  <strong>{{ row.closedBy }}</strong>
                  <p>{{ row.closedAt }}</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="modal-actions night-audit-actions">
        <button class="action-button" @click="emit('close')" :disabled="executing">Back</button>

        <button
          v-if="!loading && !loadError"
          class="action-button primary"
          :disabled="statusData?.pending_checkouts > 0 || executing"
          @click="runAudit"
        >
          <span v-if="executing">Processing, please do not close...</span>
          <span v-else>Run Night Audit</span>
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.night-audit-modal {
  max-width: 880px;
  max-height: calc(100vh - 48px);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
.night-audit-head {
  align-items: flex-start;
  padding-bottom: 1.15rem;
  border-bottom: 1px solid rgba(148, 163, 184, 0.18);
  background:
    radial-gradient(circle at top left, rgba(37, 99, 235, 0.22), transparent 32%),
    linear-gradient(135deg, rgba(15, 23, 42, 0.98), rgba(30, 41, 59, 0.95));
  color: #f8fafc;
}
.night-audit-head-copy h3 {
  margin: 0;
  font-size: 1.6rem;
}
.night-audit-subtitle {
  margin: 0.55rem 0 0;
  max-width: 56ch;
  color: rgba(226, 232, 240, 0.82);
  line-height: 1.55;
}
.night-audit-body {
  flex: 1 1 auto;
  min-height: 0;
  overflow-y: auto;
  padding: 1.5rem;
  background:
    linear-gradient(180deg, rgba(248, 250, 252, 0.96), rgba(241, 245, 249, 0.98)),
    var(--surface-2);
}
.step-indicator {
  width: 42px;
  height: 42px;
  border-radius: 14px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: bold;
  font-size: 0.88rem;
  letter-spacing: 0.06em;
  box-shadow: inset 0 0 0 1px rgba(148, 163, 184, 0.18);
}
.night-audit-banner {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1rem;
  margin-bottom: 1rem;
}
.night-audit-banner-card,
.night-audit-history {
  border: 1px solid var(--line);
  border-radius: 18px;
  background: var(--surface-1);
  padding: 1rem 1.1rem;
  box-shadow: 0 16px 38px rgba(15, 23, 42, 0.06);
}
.night-audit-banner-card {
  display: grid;
  gap: 0.25rem;
}
.night-audit-banner-card strong {
  font-size: 1.08rem;
}
.night-audit-banner-card span {
  color: var(--text-muted);
  font-size: 0.88rem;
}
.night-audit-banner-card-primary {
  background: linear-gradient(135deg, rgba(37, 99, 235, 0.12), rgba(15, 23, 42, 0.03));
  border-color: rgba(37, 99, 235, 0.18);
}
.night-audit-banner-label {
  margin: 0 0 0.25rem;
  font-size: 0.78rem;
  color: var(--text-muted);
  text-transform: uppercase;
  letter-spacing: 0.08em;
}
.night-audit-stats {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 1rem;
  margin-bottom: 1rem;
}
.night-audit-stat {
  border: 1px solid rgba(148, 163, 184, 0.18);
  border-radius: 18px;
  background: rgba(255, 255, 255, 0.92);
  padding: 1rem 1.1rem;
}
.night-audit-stat strong {
  display: block;
  margin-top: 0.18rem;
  font-size: 2rem;
  line-height: 1;
}
.night-audit-stat p {
  margin: 0.4rem 0 0;
  color: var(--text-muted);
  font-size: 0.88rem;
  line-height: 1.5;
}
.night-audit-stat-label {
  color: var(--text-muted);
  font-size: 0.78rem;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}
.night-audit-timeline {
  margin-bottom: 1.35rem;
  border: 1px solid rgba(148, 163, 184, 0.18);
  border-radius: 22px;
  background: rgba(255, 255, 255, 0.9);
  padding: 1.2rem;
  box-shadow: 0 18px 40px rgba(15, 23, 42, 0.05);
}
.night-audit-money {
  margin-bottom: 1rem;
  border: 1px solid rgba(148, 163, 184, 0.18);
  border-radius: 22px;
  background: rgba(255, 255, 255, 0.9);
  padding: 1.15rem;
  box-shadow: 0 18px 40px rgba(15, 23, 42, 0.05);
}
.night-audit-money-head {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  align-items: flex-start;
  margin-bottom: 1rem;
}
.night-audit-money-head h4 {
  margin: 0.15rem 0 0;
  font-size: 1.05rem;
}
.night-audit-money-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.9rem;
}
.night-audit-money-card {
  border: 1px solid rgba(148, 163, 184, 0.16);
  border-radius: 16px;
  background: rgba(248, 250, 252, 0.9);
  padding: 0.95rem 1rem;
}
.night-audit-money-card span {
  color: var(--text-muted);
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
}
.night-audit-money-card strong {
  display: block;
  margin-top: 0.28rem;
  font-size: 1.2rem;
}
.night-audit-money-card p {
  margin: 0.38rem 0 0;
  color: var(--text-muted);
  font-size: 0.86rem;
  line-height: 1.5;
}
.night-audit-money-card-primary {
  background: linear-gradient(135deg, rgba(15, 23, 42, 0.96), rgba(37, 99, 235, 0.9));
  border-color: rgba(37, 99, 235, 0.26);
  color: #eff6ff;
}
.night-audit-money-card-primary span,
.night-audit-money-card-primary p {
  color: rgba(239, 246, 255, 0.8);
}
.night-audit-step {
  padding: 0.35rem 0;
}
.night-audit-note {
  margin: 0.3rem 0 0;
  font-size: 0.92rem;
  line-height: 1.55;
}
.night-audit-note-danger {
  color: #b91c1c;
}
.night-audit-note-warning {
  color: #b45309;
}
.night-audit-note-success {
  color: #15803d;
}
.night-audit-checklist {
  margin: 0.55rem 0 0 1rem;
  color: var(--text-muted);
  font-size: 0.92rem;
  line-height: 1.65;
}
.night-audit-feedback {
  margin-top: 1rem;
}
.night-audit-history {
  margin-top: 1.25rem;
}
.night-audit-history-head,
.night-audit-history-row {
  display: grid;
  grid-template-columns: 1.2fr 1fr 1fr 1fr;
  gap: 0.8rem;
  align-items: start;
}
.night-audit-history-head {
  margin-bottom: 0.75rem;
  font-size: 0.9rem;
}
.night-audit-history-list {
  display: grid;
  gap: 0.75rem;
}
.night-audit-history-row {
  border: 1px solid rgba(148, 163, 184, 0.16);
  border-radius: 16px;
  background: rgba(248, 250, 252, 0.9);
  padding: 0.85rem 0.95rem;
}
.night-audit-history-row p,
.night-audit-history-empty {
  margin: 0.2rem 0 0;
  font-size: 0.86rem;
  color: var(--text-muted);
}
.night-audit-actions {
  flex: 0 0 auto;
  margin-top: 0;
  padding: 1rem 1.5rem 1.35rem;
  border-top: 1px solid rgba(148, 163, 184, 0.18);
  background: rgba(248, 250, 252, 0.82);
}
@media (max-width: 720px) {
  .night-audit-banner,
  .night-audit-stats,
  .night-audit-money-grid,
  .night-audit-history-head,
  .night-audit-history-row {
    grid-template-columns: 1fr;
  }
  .night-audit-head {
    gap: 1rem;
  }
  .night-audit-head-copy h3 {
    font-size: 1.35rem;
  }
  .night-audit-body,
  .night-audit-actions {
    padding-left: 1rem;
    padding-right: 1rem;
  }
}
.step-error { background: #fee2e2; color: #991b1b; }
.step-warning { background: #fef3c7; color: #b45309; }
.step-success { background: #dcfce7; color: #166534; }
.step-primary { background: var(--primary); color: white; }
</style>
