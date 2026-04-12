<script setup>
import { onMounted, ref } from 'vue'
import api from '../services/api'

const loading = ref(false)
const saving = ref(false)
const settingsResult = ref({ tone: '', text: '' })
const cancellationPenaltyPercent = ref(0)

const loadSettings = async () => {
  loading.value = true
  settingsResult.value = { tone: '', text: '' }

  try {
    const response = await api.get('/settings/policies')
    const policy = response.data?.data?.cancellationPolicy ?? {}
    cancellationPenaltyPercent.value = Number(policy.percent ?? 0)
  } catch (error) {
    settingsResult.value = {
      tone: 'error',
      text: error?.response?.data?.message || error?.message || 'Failed to load booking policy settings.',
    }
  } finally {
    loading.value = false
  }
}

const saveSettings = async () => {
  settingsResult.value = { tone: '', text: '' }
  saving.value = true

  try {
    const response = await api.put('/settings/policies', {
      cancellationPenaltyPercent: cancellationPenaltyPercent.value,
    })

    const policy = response.data?.data?.cancellationPolicy ?? {}
    cancellationPenaltyPercent.value = Number(policy.percent ?? cancellationPenaltyPercent.value)
    settingsResult.value = {
      tone: 'success',
      text: response.data?.message || 'Booking policy settings saved successfully.',
    }
  } catch (error) {
    settingsResult.value = {
      tone: 'error',
      text: error?.response?.data?.message || error?.message || 'Failed to save booking policy settings.',
    }
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  await loadSettings()
})
</script>

<template>
  <section class="page-grid two">
    <article class="panel-card panel-dense">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Settings</p>
          <h3>Booking policy</h3>
          <p class="panel-note">Manage business rules that should not appear on the operational dashboard.</p>
        </div>
        <button class="action-button" :disabled="loading" @click="loadSettings">Refresh</button>
      </div>

      <div class="booking-form-grid">
        <label class="field-stack">
          <span>Cancellation penalty (%)</span>
          <input
            v-model.number="cancellationPenaltyPercent"
            class="form-control"
            type="number"
            min="0"
            max="100"
            step="0.01"
          />
        </label>

        <div class="field-stack">
          <span>Policy preview</span>
          <div class="dashboard-meta-pill">
            <strong>{{ cancellationPenaltyPercent }}%</strong>
            of active booking charges
          </div>
        </div>
      </div>

      <div v-if="settingsResult.text" class="booking-feedback" :class="settingsResult.tone">
        {{ settingsResult.text }}
      </div>

      <div class="modal-actions">
        <button class="action-button primary" :disabled="saving || loading" @click="saveSettings">
          {{ saving ? 'Saving...' : 'Save booking policy' }}
        </button>
      </div>
    </article>

    <article class="panel-card panel-dense">
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Policy note</p>
          <h3>How this setting is used</h3>
        </div>
      </div>

      <div class="compact-list">
        <div class="list-row list-row-tight">
          <strong>Reservation cancellation</strong>
          <p class="subtle">The percentage is used when a booking is canceled and a penalty charge must be calculated.</p>
        </div>
        <div class="list-row list-row-tight">
          <strong>Booking workflow</strong>
          <p class="subtle">Front office will still see the penalty result during cancellation, but the configuration stays in Settings.</p>
        </div>
        <div class="list-row list-row-tight">
          <strong>Recommended access</strong>
          <p class="subtle">Keep this page limited to admin or owner roles to avoid accidental policy changes.</p>
        </div>
      </div>
    </article>
  </section>
</template>
