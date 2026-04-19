import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import smartTable from './directives/smartTable'
import './style.css'
import { applyPrintTemplateSettings, loadPrintTemplateSettings } from './utils/printTemplate'

const app = createApp(App)

applyPrintTemplateSettings(loadPrintTemplateSettings())

app.use(createPinia())
app.use(router)
app.directive('smart-table', smartTable)
app.mount('#app')
