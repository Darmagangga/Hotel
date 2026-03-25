<script setup>
defineProps({
  label: {
    type: String,
    default: 'Memuat data...',
  },
  overlay: {
    type: Boolean,
    default: false,
  },
})
</script>

<template>
  <div class="loading-state" :class="{ overlay }">
    <div class="loading-spinner" aria-hidden="true"></div>
    <p>{{ label }}</p>
  </div>
</template>
