export const PRINT_TEMPLATE_STORAGE_KEY = 'pms_print_template_settings'
export const PRINT_TEMPLATE_UPDATED_EVENT = 'pms:print-template-updated'

export const printTemplateDefaults = {
  documentLabel: 'Guest invoice / folio',
  documentTitle: 'HOTEL BOOK',
  tagline: 'Template cetak untuk invoice, folio tamu, dan dokumen operasional hotel.',
  accentColor: '#1f4b99',
  paperSize: 'A5',
  orientation: 'portrait',
  fontFamily: 'serif',
  baseFontSize: 9.5,
  compactMode: true,
  showHeaderBand: true,
  showSummaryTint: true,
  footerNote: 'Terima kasih telah menginap bersama kami.',
  preparedByLabel: 'Prepared by',
  approvalLabel: 'Guest signature',
}

const clampBaseFontSize = (value) => {
  const numeric = Number(value)

  if (!Number.isFinite(numeric)) {
    return printTemplateDefaults.baseFontSize
  }

  return Math.min(12, Math.max(8, numeric))
}

const normalizeFontFamily = (value) => {
  const allowed = new Set(['serif', 'sans', 'mono'])
  return allowed.has(value) ? value : printTemplateDefaults.fontFamily
}

const normalizePaperSize = (value) => {
  const allowed = new Set(['A5', 'A4', 'Letter'])
  return allowed.has(value) ? value : printTemplateDefaults.paperSize
}

const normalizeOrientation = (value) => {
  return value === 'landscape' ? 'landscape' : 'portrait'
}

const normalizeHexColor = (value) => {
  const color = String(value ?? '').trim()
  return /^#([0-9a-fA-F]{6})$/.test(color) ? color : printTemplateDefaults.accentColor
}

export const normalizePrintTemplateSettings = (value = {}) => ({
  documentLabel: String(value.documentLabel ?? printTemplateDefaults.documentLabel).trim() || printTemplateDefaults.documentLabel,
  documentTitle: String(value.documentTitle ?? printTemplateDefaults.documentTitle).trim() || printTemplateDefaults.documentTitle,
  tagline: String(value.tagline ?? printTemplateDefaults.tagline).trim() || printTemplateDefaults.tagline,
  accentColor: normalizeHexColor(value.accentColor),
  paperSize: normalizePaperSize(value.paperSize),
  orientation: normalizeOrientation(value.orientation),
  fontFamily: normalizeFontFamily(value.fontFamily),
  baseFontSize: clampBaseFontSize(value.baseFontSize),
  compactMode: value.compactMode !== false,
  showHeaderBand: value.showHeaderBand !== false,
  showSummaryTint: value.showSummaryTint !== false,
  footerNote: String(value.footerNote ?? printTemplateDefaults.footerNote).trim(),
  preparedByLabel: String(value.preparedByLabel ?? printTemplateDefaults.preparedByLabel).trim() || printTemplateDefaults.preparedByLabel,
  approvalLabel: String(value.approvalLabel ?? printTemplateDefaults.approvalLabel).trim() || printTemplateDefaults.approvalLabel,
})

const getStorage = () => {
  if (typeof window === 'undefined') {
    return null
  }

  return window.localStorage
}

const fontFamilyMap = {
  serif: '"Times New Roman", Georgia, serif',
  sans: '"Segoe UI", Arial, Helvetica, sans-serif',
  mono: '"Courier New", "Liberation Mono", monospace',
}

const paperStyleMap = {
  A5: {
    portrait: { pageSize: 'A5 portrait', width: '5.8in' },
    landscape: { pageSize: 'A5 landscape', width: '8.3in' },
  },
  A4: {
    portrait: { pageSize: 'A4 portrait', width: '8.27in' },
    landscape: { pageSize: 'A4 landscape', width: '11.69in' },
  },
  Letter: {
    portrait: { pageSize: 'Letter portrait', width: '8.5in' },
    landscape: { pageSize: 'Letter landscape', width: '11in' },
  },
}

const hexToRgb = (color) => {
  const normalized = normalizeHexColor(color).slice(1)
  return {
    r: Number.parseInt(normalized.slice(0, 2), 16),
    g: Number.parseInt(normalized.slice(2, 4), 16),
    b: Number.parseInt(normalized.slice(4, 6), 16),
  }
}

const ensurePageStyleTag = () => {
  if (typeof document === 'undefined') {
    return null
  }

  let styleTag = document.getElementById('pms-print-template-page-style')
  if (!styleTag) {
    styleTag = document.createElement('style')
    styleTag.id = 'pms-print-template-page-style'
    document.head.appendChild(styleTag)
  }
  return styleTag
}

export const loadPrintTemplateSettings = () => {
  const storage = getStorage()
  if (!storage) {
    return { ...printTemplateDefaults }
  }

  try {
    const raw = storage.getItem(PRINT_TEMPLATE_STORAGE_KEY)
    if (!raw) {
      return { ...printTemplateDefaults }
    }

    return normalizePrintTemplateSettings(JSON.parse(raw))
  } catch (error) {
    console.error('Failed to load print template settings:', error)
    return { ...printTemplateDefaults }
  }
}

export const applyPrintTemplateSettings = (value) => {
  if (typeof document === 'undefined') {
    return normalizePrintTemplateSettings(value)
  }

  const settings = normalizePrintTemplateSettings(value)
  const root = document.documentElement
  const body = document.body
  const accent = hexToRgb(settings.accentColor)
  const pageStyle = paperStyleMap[settings.paperSize]?.[settings.orientation] ?? paperStyleMap.A5.portrait

  root.style.setProperty('--print-accent', settings.accentColor)
  root.style.setProperty('--print-accent-soft', `rgba(${accent.r}, ${accent.g}, ${accent.b}, 0.08)`)
  root.style.setProperty('--print-font-family', fontFamilyMap[settings.fontFamily] ?? fontFamilyMap.serif)
  root.style.setProperty('--print-font-size', `${settings.baseFontSize}pt`)
  root.style.setProperty('--print-sheet-width', pageStyle.width)
  root.style.setProperty('--print-title-spacing', settings.compactMode ? '0.12em' : '0.22em')
  root.style.setProperty('--print-section-gap', settings.compactMode ? '5px' : '8px')
  root.style.setProperty('--print-sheet-padding', settings.compactMode ? '0.1in' : '0.16in')

  if (body) {
    body.dataset.printCompact = String(settings.compactMode)
    body.dataset.printHeaderBand = String(settings.showHeaderBand)
    body.dataset.printSummaryTint = String(settings.showSummaryTint)
  }

  const styleTag = ensurePageStyleTag()
  if (styleTag) {
    styleTag.textContent = `@media print { @page { size: ${pageStyle.pageSize}; margin: 0.3in; } html, body { width: ${pageStyle.width}; min-width: ${pageStyle.width}; } }`
  }

  return settings
}

export const savePrintTemplateSettings = (value) => {
  const storage = getStorage()
  const settings = normalizePrintTemplateSettings(value)

  if (storage) {
    storage.setItem(PRINT_TEMPLATE_STORAGE_KEY, JSON.stringify(settings))
  }

  applyPrintTemplateSettings(settings)

  if (typeof window !== 'undefined') {
    window.dispatchEvent(new CustomEvent(PRINT_TEMPLATE_UPDATED_EVENT, { detail: settings }))
  }

  return settings
}

export const resetPrintTemplateSettings = () => savePrintTemplateSettings(printTemplateDefaults)
