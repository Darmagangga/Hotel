<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import $ from 'jquery'
import select2Installer from 'select2/dist/js/select2.full'
import 'select2/dist/css/select2.css'

const props = defineProps({
  modelValue: {
    type: [Array, String],
    default: '',
  },
  options: {
    type: Array,
    default: () => [],
  },
  placeholder: {
    type: String,
    default: 'Select option',
  },
  multiple: {
    type: Boolean,
    default: true,
  },
})

const emit = defineEmits(['update:modelValue'])

const selectRef = ref(null)
let select2Ready = null
const instanceId = `select2-${Math.random().toString(36).slice(2, 10)}`

const normalizedOptions = computed(() => (
  Array.isArray(props.options)
    ? props.options.map((option) => ({
        value: String(option?.value ?? ''),
        label: option?.label ?? option?.text ?? '',
      }))
    : []
))

const ensureSelect2 = async () => {
  if (!select2Ready) {
    window.$ = $
    window.jQuery = $
    select2Ready = Promise.resolve().then(() => {
      const install = select2Installer?.default ?? select2Installer

      if (typeof install === 'function') {
        install(window, $)
      }

      if (typeof $.fn?.select2 !== 'function') {
        throw new Error('Select2 failed to initialize.')
      }
    })
  }

  await select2Ready
}

const resolveDropdownParent = () => {
  if (!selectRef.value) {
    return $(document.body)
  }

  const host = $(selectRef.value)
  const modalParent = host.closest('.modal-card')

  if (modalParent.length) {
    return modalParent
  }

  const panelParent = host.closest('.panel-card')

  if (panelParent.length) {
    return panelParent
  }

  return $(document.body)
}

const focusSearchField = (attempt = 0) => {
  const dropdownParent = resolveDropdownParent().get(0)
  const scopedSearchField = dropdownParent?.querySelector('.select2-container--open .select2-search__field')
  const globalSearchField = document.querySelector('.select2-container--open .select2-search__field')
  const searchField = scopedSearchField ?? globalSearchField

  if (searchField) {
    searchField.focus()
    searchField.click()
    return
  }

  if (attempt < 6) {
    window.setTimeout(() => {
      focusSearchField(attempt + 1)
    }, 40)
  }
}

const syncValue = () => {
  if (!selectRef.value) {
    return
  }

  const nextValue = props.multiple
    ? (Array.isArray(props.modelValue) ? props.modelValue : [])
    : (props.modelValue ? String(props.modelValue) : '')
  const currentValue = $(selectRef.value).val() ?? (props.multiple ? [] : '')

  if (JSON.stringify(currentValue) !== JSON.stringify(nextValue)) {
    $(selectRef.value).val(nextValue).trigger('change.select2')
  }
}

const destroySelect2 = () => {
  if (!selectRef.value || !$(selectRef.value).hasClass('select2-hidden-accessible')) {
    return
  }

  $(selectRef.value).off('.select2field')
  $(selectRef.value).select2('destroy')
}

const initSelect2 = async () => {
  await ensureSelect2()
  await nextTick()

  if (!selectRef.value) {
    return
  }

  destroySelect2()

  $(selectRef.value)
    .select2({
      width: '100%',
      dropdownParent: resolveDropdownParent(),
      placeholder: props.placeholder,
      closeOnSelect: !props.multiple,
      allowClear: !props.multiple,
      minimumResultsForSearch: 0,
      selectionCssClass: instanceId,
      dropdownCssClass: instanceId,
    })
    .on('select2:open', () => {
      focusSearchField()
    })
    .on('change.select2field', () => {
      const value = $(selectRef.value).val()

      if (props.multiple) {
        emit('update:modelValue', (value ?? []).map(String))
        return
      }

      emit('update:modelValue', value ? String(value) : '')
    })

  syncValue()
}

watch(
  () => normalizedOptions.value,
  async () => {
    await initSelect2()
  },
  { deep: true },
)

watch(
  () => props.modelValue,
  () => {
    syncValue()
  },
  { deep: true },
)

watch(
  () => [props.placeholder, props.multiple],
  async () => {
    await initSelect2()
  },
)

onMounted(async () => {
  await initSelect2()
})

onBeforeUnmount(() => {
  destroySelect2()
})
</script>

<template>
  <select ref="selectRef" class="form-control select2-host" :multiple="multiple">
    <option v-if="!multiple" value=""></option>
    <option
      v-for="option in normalizedOptions"
      :key="option.value"
      :value="option.value"
    >
      {{ option.label }}
    </option>
  </select>
</template>
