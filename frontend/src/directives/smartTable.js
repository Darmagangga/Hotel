const PAGE_SIZE_OPTIONS = [5, 10, 15, 20]
const DEFAULT_PAGE_SIZE = 5

const normalize = (value) => String(value ?? '').trim().toLowerCase()

const isUtilityRow = (row) =>
  row?.dataset?.datatableGenerated === 'true' ||
  Boolean(row?.querySelector?.('.table-empty-cell, .table-loading-cell'))

const createNoMatchRow = (table) => {
  const row = document.createElement('tr')
  row.dataset.datatableGenerated = 'true'

  const cell = document.createElement('td')
  cell.className = 'table-empty-cell'
  cell.colSpan = Math.max(table.tHead?.rows?.[0]?.cells?.length || 1, 1)
  cell.textContent = 'No matching records found.'

  row.appendChild(cell)
  return row
}

const createButton = (label) => {
  const button = document.createElement('button')
  button.type = 'button'
  button.className = 'action-button datatable-button'
  button.textContent = label
  return button
}

const createToolbar = () => {
  const toolbar = document.createElement('div')
  toolbar.className = 'datatable-toolbar'

  const left = document.createElement('div')
  left.className = 'datatable-toolbar-group'

  const search = document.createElement('input')
  search.type = 'search'
  search.className = 'toolbar-search datatable-search'
  search.placeholder = 'Search table...'

  left.appendChild(search)

  const right = document.createElement('div')
  right.className = 'datatable-toolbar-group'

  const label = document.createElement('label')
  label.className = 'datatable-page-size'
  label.textContent = 'Show'

  const select = document.createElement('select')
  select.className = 'form-control datatable-select'

  PAGE_SIZE_OPTIONS.forEach((size) => {
    const option = document.createElement('option')
    option.value = String(size)
    option.textContent = String(size)
    if (size === DEFAULT_PAGE_SIZE) {
      option.selected = true
    }
    select.appendChild(option)
  })

  const suffix = document.createElement('span')
  suffix.textContent = 'rows'

  label.appendChild(select)
  label.appendChild(suffix)
  right.appendChild(label)

  toolbar.appendChild(left)
  toolbar.appendChild(right)

  return { toolbar, search, select }
}

const createFooter = () => {
  const footer = document.createElement('div')
  footer.className = 'datatable-footer'

  const info = document.createElement('div')
  info.className = 'datatable-info subtle'

  const actions = document.createElement('div')
  actions.className = 'datatable-pagination'

  const prev = createButton('Previous')
  const next = createButton('Next')

  const page = document.createElement('span')
  page.className = 'datatable-page subtle'

  actions.appendChild(prev)
  actions.appendChild(page)
  actions.appendChild(next)

  footer.appendChild(info)
  footer.appendChild(actions)

  return { footer, info, actions, prev, next, page }
}

const attachControls = (table) => {
  const mountTarget = table.parentElement?.classList.contains('table-scroll')
    ? table.parentElement
    : table

  const toolbarParts = createToolbar()
  const footerParts = createFooter()

  mountTarget.parentNode?.insertBefore(toolbarParts.toolbar, mountTarget)
  mountTarget.parentNode?.insertBefore(footerParts.footer, mountTarget.nextSibling)

  return {
    mountTarget,
    ...toolbarParts,
    ...footerParts,
  }
}

const createController = (table) => {
  const tbody = table.tBodies?.[0]
  if (!tbody) {
    return null
  }

  const controls = attachControls(table)
  const state = {
    query: '',
    pageSize: DEFAULT_PAGE_SIZE,
    page: 1,
    noMatchRow: createNoMatchRow(table),
  }

  const getAllRows = () => Array.from(tbody.rows)
  const getDataRows = () => getAllRows().filter((row) => !isUtilityRow(row))
  const getPlaceholderRows = () =>
    getAllRows().filter((row) => !row.dataset.datatableGenerated && row.querySelector('.table-empty-cell, .table-loading-cell'))

  const ensureNoMatchRow = () => {
    if (!tbody.contains(state.noMatchRow)) {
      tbody.appendChild(state.noMatchRow)
    }
  }

  const updateInfo = (total, start, end) => {
    if (!total) {
      controls.info.textContent = 'Showing 0 to 0 of 0 rows'
      return
    }

    controls.info.textContent = `Showing ${start} to ${end} of ${total} rows`
  }

  const render = () => {
    const rows = getDataRows()
    const placeholderRows = getPlaceholderRows()
    const query = normalize(state.query)
    const filteredRows = rows.filter((row) => normalize(row.textContent).includes(query))
    const total = filteredRows.length
    const pageCount = Math.max(1, Math.ceil(total / state.pageSize))

    state.page = Math.min(state.page, pageCount)
    if (state.page < 1) {
      state.page = 1
    }

    const startIndex = (state.page - 1) * state.pageSize
    const visibleRows = filteredRows.slice(startIndex, startIndex + state.pageSize)

    rows.forEach((row) => {
      row.style.display = visibleRows.includes(row) ? '' : 'none'
    })

    const hasSourcePlaceholder = placeholderRows.length > 0
    placeholderRows.forEach((row) => {
      row.style.display = rows.length === 0 ? '' : 'none'
    })

    ensureNoMatchRow()
    state.noMatchRow.style.display = rows.length > 0 && total === 0 ? '' : 'none'

    const start = total ? startIndex + 1 : 0
    const end = total ? Math.min(startIndex + visibleRows.length, total) : 0
    updateInfo(total, start, end)

    controls.prev.disabled = state.page <= 1 || total === 0
    controls.next.disabled = state.page >= pageCount || total === 0
    controls.page.textContent = total
      ? `Page ${state.page} of ${pageCount}`
      : (hasSourcePlaceholder && rows.length === 0 ? 'No rows available' : 'No matching rows')

    const hidePager = rows.length === 0 && hasSourcePlaceholder
    controls.actions.style.visibility = hidePager ? 'hidden' : 'visible'
  }

  const refresh = () => {
    render()
  }

  const handleSearch = (event) => {
    state.query = event.target.value
    state.page = 1
    render()
  }

  const handleSizeChange = (event) => {
    state.pageSize = Number(event.target.value) || DEFAULT_PAGE_SIZE
    state.page = 1
    render()
  }

  const handlePrev = () => {
    if (state.page > 1) {
      state.page -= 1
      render()
    }
  }

  const handleNext = () => {
    state.page += 1
    render()
  }

  controls.search.addEventListener('input', handleSearch)
  controls.select.addEventListener('change', handleSizeChange)
  controls.prev.addEventListener('click', handlePrev)
  controls.next.addEventListener('click', handleNext)

  const observer = new MutationObserver(() => {
    render()
  })

  observer.observe(tbody, {
    childList: true,
    subtree: true,
    characterData: true,
  })

  render()

  return {
    refresh,
    destroy() {
      observer.disconnect()
      controls.search.removeEventListener('input', handleSearch)
      controls.select.removeEventListener('change', handleSizeChange)
      controls.prev.removeEventListener('click', handlePrev)
      controls.next.removeEventListener('click', handleNext)
      controls.toolbar.remove()
      controls.footer.remove()
    },
  }
}

export default {
  mounted(el) {
    el.__smartTable = createController(el)
  },
  updated(el) {
    el.__smartTable?.refresh()
  },
  unmounted(el) {
    el.__smartTable?.destroy()
    delete el.__smartTable
  },
}
