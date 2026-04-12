<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import LoadingState from '../components/LoadingState.vue'
import Select2Field from '../components/Select2Field.vue'
import api from '../services/api'
import { useHotelStore } from '../stores/hotel'

const hotel = useHotelStore()
const loadingCoa = ref(false)
const loadingJournals = ref(false)
const savingJournal = ref(false)
const showJournalForm = ref(false)
const coaLoadResult = ref({ tone: '', text: '' })
const journals = ref([])
const editingJournalId = ref(null)

let journalLineSeed = 1

const createJournalLine = () => ({
  id: `jl-${journalLineSeed++}`,
  account: '',
  debitValue: '',
  creditValue: '',
  debitFocused: false,
  creditFocused: false,
  memo: '',
})

const buildAccountValue = (coaCode) => {
  const match = hotel.coaList.find((item) => item.code === coaCode)
  return match ? `${match.code} - ${match.name}` : coaCode
}

const parseJournalAmount = (value) => {
  const raw = String(value ?? '').trim()

  if (!raw) {
    return 0
  }

  let normalized = raw.replace(/\s/g, '')

  if (normalized.includes(',') && normalized.includes('.')) {
    normalized = normalized.replace(/\./g, '').replace(',', '.')
  } else if (normalized.includes(',')) {
    normalized = normalized.replace(/\./g, '').replace(',', '.')
  } else if ((normalized.match(/\./g) ?? []).length > 1) {
    normalized = normalized.replace(/\./g, '')
  } else if (/^\d{1,3}\.\d{3}$/.test(normalized)) {
    normalized = normalized.replace('.', '')
  }

  normalized = normalized.replace(/[^\d.]/g, '')
  const parsed = Number(normalized)

  return Number.isFinite(parsed) ? parsed : 0
}

const normalizeJournalAmountInput = (value) => {
  const raw = String(value ?? '').replace(/\s/g, '').replace(/,/g, '.')
  let result = ''
  let hasDecimal = false

  for (const char of raw) {
    if (/\d/.test(char)) {
      result += char
      continue
    }

    if (char === '.' && !hasDecimal) {
      result += '.'
      hasDecimal = true
    }
  }

  const [integerPart = '', decimalPart = ''] = result.split('.')
  const trimmedInteger = integerPart.replace(/^0+(?=\d)/, '') || (integerPart ? '0' : '')
  const trimmedDecimal = decimalPart.slice(0, 2)

  return hasDecimal ? `${trimmedInteger || '0'}.${trimmedDecimal}` : trimmedInteger
}

const formatJournalAmount = (value) => {
  const amount = parseJournalAmount(value)

  if (!amount) {
    return ''
  }

  return new Intl.NumberFormat('id-ID', {
    minimumFractionDigits: Number.isInteger(amount) ? 0 : 2,
    maximumFractionDigits: 2,
  }).format(amount)
}

const sanitizeJournalAmount = (value) => {
  const normalized = normalizeJournalAmountInput(value)

  if (!normalized) {
    return ''
  }

  const amount = parseJournalAmount(normalized)

  return Number.isInteger(amount) ? String(amount) : amount.toFixed(2)
}

const displayJournalAmount = (line, key) => {
  const value = key === 'debit' ? line.debitValue : line.creditValue
  const focused = key === 'debit' ? line.debitFocused : line.creditFocused

  if (!value) {
    return ''
  }

  return focused ? value : formatJournalAmount(value)
}

const handleAmountFocus = (line, key) => {
  if (key === 'debit') {
    line.debitFocused = true
    return
  }

  line.creditFocused = true
}

const handleAmountInput = (line, key, event) => {
  const rawValue = normalizeJournalAmountInput(event?.target?.value)

  if (key === 'debit') {
    line.debitValue = rawValue
    return
  }

  line.creditValue = rawValue
}

const handleAmountBlur = (line, key) => {
  if (key === 'debit') {
    line.debitFocused = false
    return
  }

  line.creditFocused = false
}

const journalResult = ref({ tone: '', text: '' })
const journalForm = reactive({
  journalDate: new Date().toISOString().slice(0, 10),
  referenceNo: '',
  description: '',
  lines: [createJournalLine(), createJournalLine()],
})

const coaOptions = computed(() =>
  hotel.coaList.map((item) => ({
    value: `${item.code} - ${item.name}`,
    label: `${item.code} - ${item.name}`,
  })),
)

const recentJournals = computed(() => journals.value)
const journalDebitTotal = computed(() =>
  journalForm.lines.reduce((total, line) => total + parseJournalAmount(line.debitValue), 0),
)
const journalCreditTotal = computed(() =>
  journalForm.lines.reduce((total, line) => total + parseJournalAmount(line.creditValue), 0),
)
const isJournalBalanced = computed(() =>
  journalDebitTotal.value > 0 && Math.abs(journalDebitTotal.value - journalCreditTotal.value) < 0.000001,
)

const loadCoaAccounts = async () => {
  loadingCoa.value = true
  coaLoadResult.value = { tone: '', text: '' }

  try {
    const response = await api.get('/coa-accounts', {
      params: {
        per_page: 500,
      },
    })
    const rows = Array.isArray(response.data?.data) ? response.data.data : []

    if (rows.length) {
      hotel.setCoaAccounts(rows)
    }
  } catch (error) {
    coaLoadResult.value = {
      tone: 'error',
      text: 'Failed to load the COA list from the database. Using temporary local data.',
    }
  } finally {
    loadingCoa.value = false
  }
}

const loadJournals = async () => {
  loadingJournals.value = true

  try {
    const response = await api.get('/journals', {
      params: {
        per_page: 15,
      },
    })

    journals.value = Array.isArray(response.data?.data) ? response.data.data : []
  } catch (error) {
    journalResult.value = {
      tone: 'error',
      text: 'Failed to load journal history from the database.',
    }
  } finally {
    loadingJournals.value = false
  }
}

const resetJournalForm = () => {
  editingJournalId.value = null
  journalForm.journalDate = new Date().toISOString().slice(0, 10)
  journalForm.referenceNo = ''
  journalForm.description = ''
  journalForm.lines = [createJournalLine(), createJournalLine()]
}

const openCreateJournal = () => {
  resetJournalForm()
  journalResult.value = { tone: '', text: '' }
  showJournalForm.value = true

  if (typeof window !== 'undefined') {
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }
}

const closeJournalForm = () => {
  showJournalForm.value = false
  resetJournalForm()
}

const addJournalLine = () => {
  journalForm.lines = [...journalForm.lines, createJournalLine()]
}

const removeJournalLine = (lineId) => {
  journalForm.lines = journalForm.lines.filter((line) => line.id !== lineId)

  if (journalForm.lines.length < 2) {
    journalForm.lines = [...journalForm.lines, createJournalLine()].slice(0, 2)
  }
}

const editJournal = (journal) => {
  editingJournalId.value = journal.id
  showJournalForm.value = true
  journalResult.value = { tone: '', text: '' }
  journalForm.journalDate = journal.journalDate
  journalForm.referenceNo = journal.referenceNo ?? ''
  journalForm.description = journal.description ?? ''
  journalForm.lines = journal.lines.map((line) => ({
    id: `jl-${journalLineSeed++}`,
    account: buildAccountValue(line.coaCode),
    debitValue: sanitizeJournalAmount(line.debitValue),
    creditValue: sanitizeJournalAmount(line.creditValue),
    debitFocused: false,
    creditFocused: false,
    memo: line.memo ?? '',
  }))

  if (journalForm.lines.length < 2) {
    journalForm.lines = [...journalForm.lines, createJournalLine()].slice(0, 2)
  }

  if (typeof window !== 'undefined') {
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }
}

const submitJournal = async () => {
  journalResult.value = { tone: '', text: '' }
  savingJournal.value = true

  try {
    const payload = {
      journalDate: journalForm.journalDate,
      referenceNo: journalForm.referenceNo,
      description: journalForm.description,
      lines: journalForm.lines.map((line) => ({
        account: line.account,
        debitValue: sanitizeJournalAmount(line.debitValue),
        creditValue: sanitizeJournalAmount(line.creditValue),
        memo: line.memo,
      })),
    }
    const response = editingJournalId.value
      ? await api.put(`/journals/${editingJournalId.value}`, payload)
      : await api.post('/journals', payload)

    journalResult.value = {
      tone: 'success',
      text: response.data?.message ?? (editingJournalId.value ? 'General journal updated successfully.' : 'General journal posted successfully.'),
    }
    await loadJournals()
    resetJournalForm()
    showJournalForm.value = false
  } catch (error) {
    journalResult.value = {
      tone: 'error',
      text: error?.response?.data?.message ?? error?.response?.data?.errors?.lines?.[0] ?? (error instanceof Error ? error.message : 'Failed to post the general journal.'),
    }
  } finally {
    savingJournal.value = false
  }
}

onMounted(async () => {
  await Promise.all([loadCoaAccounts(), loadJournals()])
})
</script>

<template>
  <section class="page-grid">
    <article v-if="showJournalForm" class="panel-card panel-dense">
      <LoadingState v-if="loadingCoa" label="Loading COA list..." overlay />
      <LoadingState v-if="savingJournal" label="Saving journal to the database..." overlay />
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">General journal</p>
          <h3>{{ editingJournalId ? `Edit journal #${editingJournalId}` : 'Manual journal entry' }}</h3>
        </div>
        <span class="status-badge info">{{ hotel.coaList.length }} COA accounts available</span>
      </div>

      <div v-if="coaLoadResult.text" class="booking-feedback" :class="coaLoadResult.tone">
        {{ coaLoadResult.text }}
      </div>

      <div class="booking-form-grid">
        <label class="field-stack">
          <span>Journal date</span>
          <input v-model="journalForm.journalDate" class="form-control" type="date" />
        </label>

        <label class="field-stack">
          <span>Reference no.</span>
          <input v-model="journalForm.referenceNo" class="form-control" />
        </label>

        <label class="field-stack field-span-2">
          <span>Description</span>
          <textarea
            v-model="journalForm.description"
            class="form-control form-textarea"
            
          ></textarea>
        </label>
      </div>

      <div class="room-select-stack">
        <div v-for="(line, index) in journalForm.lines" :key="line.id" class="room-select-card">
          <div class="split-row">

            <button
              v-if="journalForm.lines.length > 2"
              type="button"
              class="action-button room-select-remove"
              @click="removeJournalLine(line.id)"
            >
              Delete
            </button>
          </div>

          <div class="journal-line-grid">
            <label class="field-stack">
              <span>Account</span>
              <Select2Field
                v-model="line.account"
                :options="coaOptions"
                :multiple="false"
                placeholder="Select COA account"
              />
            </label>

            <label class="field-stack">
              <span>Notes</span>
              <input v-model="line.memo" class="form-control" placeholder="Journal line note" />
            </label>

            <label class="field-stack">
              <span>Debit</span>
              <input
                :value="displayJournalAmount(line, 'debit')"
                class="form-control journal-amount-input"
                inputmode="numeric"
                placeholder="0"
                @focus="handleAmountFocus(line, 'debit')"
                @input="handleAmountInput(line, 'debit', $event)"
                @blur="handleAmountBlur(line, 'debit')"
              />
            </label>

            <label class="field-stack">
              <span>Credit</span>
              <input
                :value="displayJournalAmount(line, 'credit')"
                class="form-control journal-amount-input"
                inputmode="numeric"
                placeholder="0"
                @focus="handleAmountFocus(line, 'credit')"
                @input="handleAmountInput(line, 'credit', $event)"
                @blur="handleAmountBlur(line, 'credit')"
              />
            </label>
          </div>
        </div>

        <button type="button" class="action-button" @click="addJournalLine">Add journal line</button>
      </div>

      <div class="booking-inline-summary">
        <div class="note-cell">
          <strong>Total debit</strong>
          <p class="subtle">{{ new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: Number.isInteger(journalDebitTotal) ? 0 : 2, maximumFractionDigits: 2 }).format(journalDebitTotal) }}</p>
        </div>
        <div class="note-cell">
          <strong>Total credit</strong>
          <p class="subtle">{{ new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: Number.isInteger(journalCreditTotal) ? 0 : 2, maximumFractionDigits: 2 }).format(journalCreditTotal) }}</p>
        </div>
      </div>

      <div v-if="journalResult.text" class="booking-feedback" :class="journalResult.tone">
        {{ journalResult.text }}
      </div>

      <div class="modal-actions">
        <button class="action-button" :disabled="savingJournal" @click="closeJournalForm()">Close</button>
        <button class="action-button" :disabled="savingJournal" @click="resetJournalForm()">Reset</button>
        <button class="action-button primary" :disabled="savingJournal" @click="submitJournal">
          {{ savingJournal ? 'Saving...' : (editingJournalId ? 'Update journal' : 'Post journal') }}
        </button>
      </div>
    </article>

    <article v-if="!showJournalForm" class="panel-card panel-dense">
      <LoadingState v-if="loadingJournals" label="Loading journal history..." overlay />
      <div class="panel-head panel-head-tight">
        <div>
          <p class="eyebrow-dark">Journal list</p>
          <h3>Journals stored in the database</h3>
        </div>
        <div class="modal-actions" style="margin-top: 0;">
          <span class="status-badge success">{{ recentJournals.length }} journals</span>
          <button class="action-button primary" @click="openCreateJournal">Create journal</button>
        </div>
      </div>

      <table v-smart-table class="data-table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Journal no.</th>
            <th>Reference</th>
            <th>Description</th>
            <th>Debit</th>
            <th>Kredit</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!loadingJournals && !recentJournals.length">
            <td colspan="7" class="table-empty-cell">There are no journals in the database yet.</td>
          </tr>
          <tr v-for="item in recentJournals" :key="item.id">
            <td>{{ item.journalDate }}</td>
            <td><strong>{{ item.journalNo }}</strong></td>
            <td>{{ item.referenceNo || '-' }}</td>
            <td>{{ item.description }}</td>
            <td>{{ new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: Number.isInteger(item.debitTotalValue) ? 0 : 2, maximumFractionDigits: 2 }).format(item.debitTotalValue) }}</td>
            <td>{{ new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: Number.isInteger(item.creditTotalValue) ? 0 : 2, maximumFractionDigits: 2 }).format(item.creditTotalValue) }}</td>
            <td>
              <button class="action-button" @click="editJournal(item)">Edit</button>
            </td>
          </tr>
        </tbody>
      </table>
    </article>
  </section>
</template>
