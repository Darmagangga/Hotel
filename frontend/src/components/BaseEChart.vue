<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import * as echarts from 'echarts'

const props = defineProps({
  option: {
    type: Object,
    required: true,
  },
  autoresize: {
    type: Boolean,
    default: true,
  },
})

const chartEl = ref(null)
let chartInstance = null
let resizeObserver = null

const renderChart = () => {
  if (!chartEl.value) {
    return
  }

  if (!chartInstance) {
    chartInstance = echarts.init(chartEl.value)
  }

  chartInstance.setOption(props.option, true)
}

const resizeChart = () => {
  chartInstance?.resize()
}

onMounted(() => {
  renderChart()

  if (props.autoresize && typeof ResizeObserver !== 'undefined' && chartEl.value) {
    resizeObserver = new ResizeObserver(() => {
      resizeChart()
    })
    resizeObserver.observe(chartEl.value)
  }

  window.addEventListener('resize', resizeChart)
})

watch(
  () => props.option,
  () => {
    renderChart()
  },
  { deep: true },
)

onBeforeUnmount(() => {
  window.removeEventListener('resize', resizeChart)
  resizeObserver?.disconnect()
  chartInstance?.dispose()
  chartInstance = null
})
</script>

<template>
  <div ref="chartEl" class="base-echart"></div>
</template>

<style scoped>
.base-echart {
  width: 100%;
  height: 100%;
}
</style>
