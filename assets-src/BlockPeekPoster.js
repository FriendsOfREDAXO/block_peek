(() => {
	const SLICE_ID = BLOCK_PEEK_PLACEHOLDER_SLICE_ID;
	const maxHeight = BLOCK_PEEK_PLACEHOLDER_MAX_HEIGHT || 10000;
	const SLICE_WRAPPER = document.body;

	let lastHeight = 0;

	// Get current SLICE_WRAPPER height
	function getCurrentHeight() {
		return Math.min(
			Math.max(
				SLICE_WRAPPER.scrollHeight,
				SLICE_WRAPPER.offsetHeight,
				SLICE_WRAPPER.clientHeight,
			),
			maxHeight,
		);
	}

	// Send height to parent if it has changed
	function sendHeight() {
		const currentHeight = getCurrentHeight();

		// Only send if height has actually changed
		if (currentHeight !== lastHeight) {
			lastHeight = currentHeight;

			try {
				parent.postMessage(
					{
						type: "resize",
						id: SLICE_ID,
						height: currentHeight,
						timestamp: Date.now(),
					},
					"*",
				);
			} catch (error) {
				console.warn("Failed to send height to parent:", error);
			}
		}
	}

	// Initialize the script
	function init() {
		// Clean up any existing listeners/timers
		cleanup();

		const exec = () => {
			requestAnimationFrame(() => sendHeight());
		};

		const resizeObserver = new window.ResizeObserver(exec);
		exec();
		resizeObserver.observe(document.body);
		window.__resizeObserver = resizeObserver;
	}

	// Cleanup function
	function cleanup() {
		if (window?.__resizeObserver) {
			window?.__resizeObserver.disconnect();
			delete window?.__resizeObserver;
		}
	}

	// Handle page unload
	window.addEventListener("beforeunload", cleanup);

	// Initialize
	init();

	const originalReplaceState = history.replaceState;
	history.replaceState = function (...args) {
		try {
			return originalReplaceState.apply(this, args);
		} catch (_e) {
			// Silently fail in srcdoc context
		}
	};
})();
