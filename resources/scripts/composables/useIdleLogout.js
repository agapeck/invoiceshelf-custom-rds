import { ref, onMounted, onUnmounted } from 'vue'
import { useNotificationStore } from '@/scripts/stores/notification'

/**
 * Idle logout composable - detects user inactivity and triggers logout
 * @param {Object} options - Configuration options
 * @param {number} options.timeoutMinutes - Minutes of inactivity before logout (default: 30)
 * @param {Function} options.logoutFn - Function to call for logout (required)
 * @param {boolean} options.useWindowStore - Whether to use window.pinia for notification store (for customer portal)
 * @param {string} options.warningMessage - Custom warning message (optional)
 * @param {string} options.logoutMessage - Custom logout message (optional)
 */
export function useIdleLogout(options = {}) {
  const {
    timeoutMinutes = 30,
    logoutFn,
    useWindowStore = false,
    warningMessage = 'You will be logged out in 1 minute due to inactivity.',
    logoutMessage = 'You have been logged out due to inactivity.',
  } = options

  if (!logoutFn || typeof logoutFn !== 'function') {
    console.error('useIdleLogout: logoutFn is required and must be a function')
    return { showingWarning: ref(false), resetTimer: () => {}, stopIdleDetection: () => {} }
  }

  const IDLE_TIMEOUT = timeoutMinutes * 60 * 1000 // Convert to milliseconds
  const WARNING_BEFORE = 60 * 1000 // Show warning 1 minute before logout
  
  let idleTimer = null
  let warningTimer = null
  const showingWarning = ref(false)
  
  const events = ['mousedown', 'mousemove', 'keydown', 'scroll', 'touchstart', 'click']
  
  const resetTimer = () => {
    // Clear existing timers
    if (idleTimer) clearTimeout(idleTimer)
    if (warningTimer) clearTimeout(warningTimer)
    showingWarning.value = false
    
    // Set warning timer (fires 1 minute before logout)
    warningTimer = setTimeout(() => {
      showingWarning.value = true
      const notificationStore = useNotificationStore(useWindowStore)
      notificationStore.showNotification({
        type: 'warning',
        message: warningMessage,
      })
    }, IDLE_TIMEOUT - WARNING_BEFORE)
    
    // Set logout timer
    idleTimer = setTimeout(() => {
      performLogout()
    }, IDLE_TIMEOUT)
  }
  
  const performLogout = async () => {
    const notificationStore = useNotificationStore(useWindowStore)
    
    notificationStore.showNotification({
      type: 'info',
      message: logoutMessage,
    })
    
    // Small delay to show notification
    setTimeout(() => {
      logoutFn()
    }, 500)
  }
  
  const startIdleDetection = () => {
    events.forEach(event => {
      document.addEventListener(event, resetTimer, { passive: true })
    })
    resetTimer()
  }
  
  const stopIdleDetection = () => {
    events.forEach(event => {
      document.removeEventListener(event, resetTimer)
    })
    if (idleTimer) clearTimeout(idleTimer)
    if (warningTimer) clearTimeout(warningTimer)
  }
  
  onMounted(() => {
    startIdleDetection()
  })
  
  onUnmounted(() => {
    stopIdleDetection()
  })
  
  return {
    showingWarning,
    resetTimer,
    stopIdleDetection
  }
}
