const toBase64Url = (value) =>
  btoa(unescape(encodeURIComponent(value)))
    .replace(/\+/g, '-')
    .replace(/\//g, '_')
    .replace(/=+$/g, '')

export const encodeInvoicePrintOverrides = (value) => {
  if (!value || typeof value !== 'object') {
    return ''
  }

  try {
    return toBase64Url(JSON.stringify(value))
  } catch (error) {
    console.error('Failed to encode invoice print overrides:', error)
    return ''
  }
}
