import axios from 'axios'

const resolveApiBaseUrl = () => {
  if (typeof window === 'undefined') {
    return 'http://localhost/HOTEL-BOOK/api-hotel.php/'
  }

  const explicitBase = import.meta.env.VITE_API_BASE_URL
  if (explicitBase) {
    return explicitBase.endsWith('/') ? explicitBase : `${explicitBase}/`
  }

  const { origin, hostname, port, pathname } = window.location

  const isLocalhost = hostname === 'localhost' || hostname === '127.0.0.1'
  const isFluxPath = pathname === '/flux' || pathname.startsWith('/flux/')

  if (port === '5173') {
    return 'http://localhost/HOTEL-BOOK/api-hotel.php/'
  }

  if (isLocalhost) {
    return `${origin}/HOTEL-BOOK/api-hotel.php/`
  }

  if (isFluxPath) {
    return `${origin}/api/api-hotel.php/`
  }

  return `${origin}/api/api-hotel.php/`
}

const api = axios.create({
  baseURL: resolveApiBaseUrl(),
  headers: {
    Accept: 'application/json',
  },
})

api.interceptors.request.use((config) => {
  if (typeof config.url === 'string') {
    config.url = config.url.replace(/^\/+/, '')
  }

  const token = localStorage.getItem('pms_token')

  if (token) {
    config.headers.Authorization = `Bearer ${token}`
    config.headers['X-PMS-Token'] = token
  } else if (config.headers?.Authorization) {
    delete config.headers.Authorization
    delete config.headers['X-PMS-Token']
  }

  return config
})

api.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error?.response?.status
    const message = error?.response?.data?.message || error?.message || 'Terjadi kesalahan saat memproses permintaan.'

    if (typeof window !== 'undefined') {
      if (status === 401) {
        localStorage.removeItem('pms_token')
        localStorage.removeItem('pms_user')
        window.dispatchEvent(new CustomEvent('pms:unauthorized', { detail: { message } }))
      } else if (status === 403) {
        window.dispatchEvent(new CustomEvent('pms:forbidden', { detail: { message } }))
      }
    }

    return Promise.reject(error)
  },
)

export default api
