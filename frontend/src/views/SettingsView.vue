<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '../services/api'
import {
  loadPrintTemplateSettings,
  normalizePrintTemplateSettings,
  printTemplateDefaults,
  resetPrintTemplateSettings,
  savePrintTemplateSettings,
} from '../utils/printTemplate'

const activeTab = ref('booking')
const loading = ref(false)
const saving = ref(false)
const settingsResult = ref({ tone: '', text: '' })
const cancellationPenaltyPercent = ref(0)
const resetConfirmation = ref('')
const resetRunning = ref(false)
const resetResult = ref({ tone: '', text: '' })

const printSaving = ref(false)
const printResult = ref({ tone: '', text: '' })
const printTemplate = ref(loadPrintTemplateSettings())

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

const savePrintTemplate = async () => {
  printSaving.value = true
  printResult.value = { tone: '', text: '' }

  try {
    printTemplate.value = savePrintTemplateSettings(printTemplate.value)
    printResult.value = {
      tone: 'success',
      text: 'Print template saved and applied to invoice preview.',
    }
  } catch (error) {
    printResult.value = {
      tone: 'error',
      text: error instanceof Error ? error.message : 'Failed to save print template.',
    }
  } finally {
    printSaving.value = false
  }
}

const restorePrintDefaults = () => {
  printTemplate.value = { ...printTemplateDefaults }
  printResult.value = {
    tone: '',
    text: 'Default values restored in the form. Save to apply them.',
  }
}

const resetAppliedPrintTemplate = () => {
  printTemplate.value = resetPrintTemplateSettings()
  printResult.value = {
    tone: 'success',
    text: 'Default print template has been restored and applied.',
  }
}

const resetTransactions = async () => {
  resetResult.value = { tone: '', text: '' }

  if (resetConfirmation.value !== 'RESET') {
    resetResult.value = {
      tone: 'error',
      text: 'Ketik RESET terlebih dahulu untuk menghapus semua transaksi.',
    }
    return
  }

  resetRunning.value = true

  try {
    const response = await api.post('/settings/reset-transactions', {
      confirmation: resetConfirmation.value,
    })

    resetConfirmation.value = ''
    resetResult.value = {
      tone: 'success',
      text: response.data?.message || 'Semua transaksi berhasil direset.',
    }
  } catch (error) {
    resetResult.value = {
      tone: 'error',
      text: error?.response?.data?.message || error?.message || 'Gagal mereset semua transaksi.',
    }
  } finally {
    resetRunning.value = false
  }
}

const previewStyle = computed(() => {
  const fontMap = {
    serif: '"Times New Roman", Georgia, serif',
    sans: '"Segoe UI", Arial, sans-serif',
    mono: '"Courier New", monospace',
  }
  const safeTemplate = normalizePrintTemplateSettings(printTemplate.value)

  return {
    '--preview-accent': safeTemplate.accentColor,
    '--preview-accent-soft': `${safeTemplate.accentColor}14`,
    '--preview-font': fontMap[safeTemplate.fontFamily] ?? fontMap.serif,
    '--preview-size': `${safeTemplate.baseFontSize}pt`,
  }
})

onMounted(async () => {
  await loadSettings()
})
</script>

<template>
  <section class="page-grid">
    <article class="panel-card panel-dense">
      <div class="panel-head">
        <div>
          <p class="eyebrow-dark">Settings workspace</p>
          <h3>Operational rules and print design</h3>
          <p class="panel-note">Gunakan tab ini untuk policy booking dan desain template cetak invoice / folio.</p>
        </div>
      </div>

      <div class="toolbar-tabs">
        <button class="toolbar-tab" :class="{ active: activeTab === 'booking' }" @click="activeTab = 'booking'">Booking Policy</button>
        <button class="toolbar-tab" :class="{ active: activeTab === 'print' }" @click="activeTab = 'print'">Print Design</button>
        <button class="toolbar-tab" :class="{ active: activeTab === 'danger' }" @click="activeTab = 'danger'">Danger Zone</button>
      </div>
    </article>

    <section v-if="activeTab === 'booking'" class="page-grid two">
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

    <section v-else-if="activeTab === 'print'" class="page-grid two settings-print-grid">
      <article class="panel-card panel-dense">
        <div class="panel-head panel-head-tight">
          <div>
            <p class="eyebrow-dark">Print template</p>
            <h3>Invoice / folio designer</h3>
            <p class="panel-note">Atur identitas dokumen, ukuran kertas, tipografi, dan elemen tanda tangan.</p>
          </div>
        </div>

        <div class="settings-designer-grid">
          <label class="field-stack">
            <span>Document label</span>
            <input v-model="printTemplate.documentLabel" class="form-control" type="text" />
          </label>

          <label class="field-stack">
            <span>Document title</span>
            <input v-model="printTemplate.documentTitle" class="form-control" type="text" />
          </label>

          <label class="field-stack field-span-2">
            <span>Header tagline</span>
            <textarea v-model="printTemplate.tagline" class="form-control form-textarea"></textarea>
          </label>

          <label class="field-stack">
            <span>Accent color</span>
            <div class="settings-color-field">
              <input v-model="printTemplate.accentColor" class="settings-color-input" type="color" />
              <input v-model="printTemplate.accentColor" class="form-control" type="text" />
            </div>
          </label>

          <label class="field-stack">
            <span>Base font size (pt)</span>
            <input v-model.number="printTemplate.baseFontSize" class="form-control" type="number" min="8" max="12" step="0.1" />
          </label>

          <label class="field-stack">
            <span>Paper size</span>
            <select v-model="printTemplate.paperSize" class="form-control">
              <option value="A5">A5</option>
              <option value="A4">A4</option>
              <option value="Letter">Letter</option>
            </select>
          </label>

          <label class="field-stack">
            <span>Orientation</span>
            <select v-model="printTemplate.orientation" class="form-control">
              <option value="portrait">Portrait</option>
              <option value="landscape">Landscape</option>
            </select>
          </label>

          <label class="field-stack">
            <span>Font family</span>
            <select v-model="printTemplate.fontFamily" class="form-control">
              <option value="serif">Serif</option>
              <option value="sans">Sans</option>
              <option value="mono">Monospace</option>
            </select>
          </label>

          <label class="field-stack">
            <span>Prepared by label</span>
            <input v-model="printTemplate.preparedByLabel" class="form-control" type="text" />
          </label>

          <label class="field-stack">
            <span>Approval label</span>
            <input v-model="printTemplate.approvalLabel" class="form-control" type="text" />
          </label>

          <label class="field-stack field-span-2">
            <span>Footer note</span>
            <textarea v-model="printTemplate.footerNote" class="form-control form-textarea"></textarea>
          </label>
        </div>

        <div class="settings-toggle-list">
          <label class="settings-toggle">
            <input v-model="printTemplate.showHeaderBand" type="checkbox" />
            <span>Tampilkan header band / strip judul</span>
          </label>
          <label class="settings-toggle">
            <input v-model="printTemplate.showSummaryTint" type="checkbox" />
            <span>Gunakan highlight warna pada ringkasan total</span>
          </label>
          <label class="settings-toggle">
            <input v-model="printTemplate.compactMode" type="checkbox" />
            <span>Compact mode untuk cetak yang lebih rapat</span>
          </label>
        </div>

        <div v-if="printResult.text" class="booking-feedback" :class="printResult.tone">
          {{ printResult.text }}
        </div>

        <div class="modal-actions">
          <button class="action-button" @click="restorePrintDefaults">Reset form</button>
          <button class="action-button" @click="resetAppliedPrintTemplate">Restore applied default</button>
          <button class="action-button primary" :disabled="printSaving" @click="savePrintTemplate">
            {{ printSaving ? 'Saving...' : 'Save print design' }}
          </button>
        </div>
      </article>

      <article class="panel-card panel-dense">
        <div class="panel-head panel-head-tight">
          <div>
            <p class="eyebrow-dark">Live preview</p>
            <h3>Template preview</h3>
            <p class="panel-note">Preview ini mengikuti input di form. Perubahan diterapkan ke invoice preview setelah disimpan.</p>
          </div>
        </div>

        <div class="print-designer-preview" :class="{ compact: printTemplate.compactMode, 'has-header-band': printTemplate.showHeaderBand, tinted: printTemplate.showSummaryTint }" :style="previewStyle">
          <header class="print-designer-header">
            <div class="print-designer-brand">
              <p class="eyebrow-dark">{{ printTemplate.documentLabel }}</p>
              <h2>{{ printTemplate.documentTitle }}</h2>
              <p class="subtle">{{ printTemplate.tagline }}</p>
            </div>
            <div class="print-designer-meta">
              <div>
                <span class="invoice-meta-label">Paper</span>
                <strong>{{ printTemplate.paperSize }} / {{ printTemplate.orientation }}</strong>
              </div>
              <div>
                <span class="invoice-meta-label">Font</span>
                <strong>{{ printTemplate.fontFamily }}</strong>
              </div>
              <div>
                <span class="invoice-meta-label">Base size</span>
                <strong>{{ printTemplate.baseFontSize }} pt</strong>
              </div>
            </div>
          </header>

          <section class="invoice-doc-grid">
            <div class="note-cell">
              <strong>Bill to</strong>
              <p class="subtle">Tamu reguler / corporate</p>
            </div>
            <div class="note-cell">
              <strong>Stay details</strong>
              <p class="subtle">2 malam, Deluxe Room, 2 pax</p>
            </div>
            <div class="note-cell">
              <strong>Document dates</strong>
              <p class="subtle">18 Apr 2026 / 19 Apr 2026</p>
            </div>
            <div class="note-cell">
              <strong>Status</strong>
              <p class="subtle">Partially paid</p>
            </div>
          </section>

          <section class="invoice-charge-summary">
            <div class="invoice-charge-row">
              <span>Room charges</span>
              <strong>IDR 1.500.000</strong>
            </div>
            <div class="invoice-charge-row">
              <span>Add-on services</span>
              <strong>IDR 250.000</strong>
            </div>
            <div class="invoice-charge-row balance">
              <span>Outstanding balance</span>
              <strong>IDR 500.000</strong>
            </div>
          </section>

          <p v-if="printTemplate.footerNote" class="subtle invoice-print-note invoice-print-note-footer">
            {{ printTemplate.footerNote }}
          </p>

          <footer class="invoice-print-footer">
            <div class="invoice-signature-box">
              <span>{{ printTemplate.preparedByLabel }}</span>
            </div>
            <div class="invoice-signature-box">
              <span>{{ printTemplate.approvalLabel }}</span>
            </div>
          </footer>
        </div>
      </article>
    </section>

    <section v-else class="page-grid two">
      <article class="panel-card panel-dense">
        <div class="panel-head panel-head-tight">
          <div>
            <p class="eyebrow-dark">Danger Zone</p>
            <h3>Reset semua transaksi</h3>
            <p class="panel-note">Aksi ini menghapus transaksi operasional seperti booking, invoice, payment, journal, vendor bill, vendor payment, inventory movement, dan audit trail. Master data tetap dipertahankan.</p>
          </div>
        </div>

        <div class="booking-form-grid">
          <label class="field-stack field-span-2">
            <span>Ketik RESET untuk konfirmasi</span>
            <input
              v-model="resetConfirmation"
              class="form-control"
              type="text"
              placeholder="RESET"
            />
          </label>
        </div>

        <div v-if="resetResult.text" class="booking-feedback" :class="resetResult.tone">
          {{ resetResult.text }}
        </div>

        <div class="modal-actions">
          <button class="action-button primary" :disabled="resetRunning" @click="resetTransactions">
            {{ resetRunning ? 'Resetting...' : 'Reset all transactions' }}
          </button>
        </div>
      </article>

      <article class="panel-card panel-dense">
        <div class="panel-head panel-head-tight">
          <div>
            <p class="eyebrow-dark">Yang tetap aman</p>
            <h3>Master data tidak dihapus</h3>
          </div>
        </div>

        <div class="compact-list">
          <div class="list-row list-row-tight">
            <strong>Dipertahankan</strong>
            <p class="subtle">User, roles, kamar, room type, vendor, COA, activity catalog, transport rate, dan setting aplikasi.</p>
          </div>
          <div class="list-row list-row-tight">
            <strong>Direset</strong>
            <p class="subtle">Booking, guest, invoice, payment, journal, vendor payable, inventory movement, housekeeping task, night audit run, dan audit trail.</p>
          </div>
          <div class="list-row list-row-tight">
            <strong>Gunakan dengan hati-hati</strong>
            <p class="subtle">Aksi ini cocok untuk membersihkan data demo atau memulai ulang transaksi operasional dari nol.</p>
          </div>
        </div>
      </article>
    </section>
  </section>
</template>
