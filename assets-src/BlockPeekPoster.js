'use strict'

const SLICE_ID = BLOCK_PEEK_PLACEHOLDER_SLICE_ID
const maxHeight = BLOCK_PEEK_PLACEHOLDER_MAX_HEIGHT || 10000
const DEBOUNCE_DELAY = 50
const PERIODIC_CHECK_INTERVAL = 5000
const SLICE_WRAPPER = document.body

let lastHeight = 0
let periodicTimer = null
let debounceTimer = null

function debounce(func, delay) {
  return function (...args) {
    const context = this
    clearTimeout(debounceTimer)
    debounceTimer = setTimeout(() => func.apply(context, args), delay)
  }
}

// Get current SLICE_WRAPPER height
function getCurrentHeight() {
  return Math.min(
    Math.max(
      SLICE_WRAPPER.scrollHeight,
      SLICE_WRAPPER.offsetHeight,
      SLICE_WRAPPER.clientHeight
    ),
    maxHeight
  )
}

// Send height to parent if it has changed
function sendHeight() {
  const currentHeight = getCurrentHeight()

  // Only send if height has actually changed
  if (currentHeight !== lastHeight) {
    lastHeight = currentHeight

    try {
      parent.postMessage(
        {
          type: 'resize',
          id: SLICE_ID,
          height: currentHeight,
          timestamp: Date.now()
        },
        '*'
      )
    } catch (error) {
      console.warn('Failed to send height to parent:', error)
    }
  }
}

// Debounced version for resize events
const debouncedSendHeight = debounce(sendHeight, DEBOUNCE_DELAY)

// Initialize the script
function init() {
  // Clean up any existing listeners/timers
  cleanup()

  // Add event listeners
  // window.addEventListener('load', sendHeight)
  // window.addEventListener('resize', debouncedSendHeight)

  // Optional: Listen for DOM mutations that might change height
  if ('MutationObserver' in window) {
    const observer = new MutationObserver(debouncedSendHeight)
    observer.observe(document.body, {
      childList: true,
      subtree: true,
      attributes: true,
      attributeFilter: ['style', 'class']
    })

    // Store observer for cleanup
    window.__heightObserver = observer
  }

  // Periodic check for height changes (fallback)
  // periodicTimer = setInterval(sendHeight, PERIODIC_CHECK_INTERVAL)

  // Send initial height
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', sendHeight)
  } else {
    sendHeight()
  }
}

// Cleanup function
function cleanup() {
  window.removeEventListener('load', sendHeight)
  window.removeEventListener('resize', debouncedSendHeight)
  document.removeEventListener('DOMContentLoaded', sendHeight)

  if (periodicTimer) {
    clearInterval(periodicTimer)
    periodicTimer = null
  }

  if (window.__heightObserver) {
    window.__heightObserver.disconnect()
    delete window.__heightObserver
  }
}

// Handle page unload
window.addEventListener('beforeunload', cleanup)

// Initialize
init()
