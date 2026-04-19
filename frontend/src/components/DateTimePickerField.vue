<script setup>
import flatpickr from 'flatpickr'
import 'flatpickr/dist/flatpickr.css'
import { English } from 'flatpickr/dist/l10n/default.js'
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'

const props = defineProps({
  modelValue: {
    type: String,
    default: '',
  },
  placeholder: {
    type: String,
    default: 'Select date and time',
  },
  minDate: {
    type: String,
    default: '',
  },
  maxDate: {
    type: String,
    default: '',
  },
  enableTime: {
    type: Boolean,
    default: true,
  },
})

const emit = defineEmits(['update:modelValue'])

const inputRef = ref(null)
let picker = null

const buildConfig = () => ({
  enableTime: props.enableTime,
  dateFormat: props.enableTime ? 'Y-m-d H:i' : 'Y-m-d',
  altInput: true,
  altInputClass: 'form-control',
  altFormat: props.enableTime ? 'd M Y H:i' : 'd M Y',
  time_24hr: true,
  locale: English,
  minDate: props.minDate || null,
  maxDate: props.maxDate || null,
  defaultDate: props.modelValue || null,
  onChange: (selectedDates, dateStr) => {
    emit('update:modelValue', dateStr)
  },
})

const initPicker = () => {
  if (!inputRef.value) {
    return
  }

  if (picker) {
    picker.destroy()
  }

  picker = flatpickr(inputRef.value, buildConfig())
}

watch(
  () => props.modelValue,
  (value) => {
    if (picker && value !== picker.input.value) {
      picker.setDate(value || null, false, props.enableTime ? 'Y-m-d H:i' : 'Y-m-d')
    }
  },
)

watch(
  () => [props.minDate, props.maxDate],
  ([minDate, maxDate]) => {
    if (!picker) {
      return
    }

    picker.set('minDate', minDate || null)
    picker.set('maxDate', maxDate || null)
  },
)

onMounted(() => {
  initPicker()
})

onBeforeUnmount(() => {
  if (picker) {
    picker.destroy()
  }
})
</script>

<template>
  <input
    ref="inputRef"
    class="form-control"
    :placeholder="placeholder"
    type="text"
  />
</template>
