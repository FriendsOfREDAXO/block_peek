(($) => {
	"use strict";

	const SETTLE_TIMEOUT_MS = 500;
	const LATE_SHIFT_WATCH_MS = 500;
	const SCROLL_INTENT_KEY = "block_peek:scroll_intent";
	// Stale-entry guard: a session-storage write is only honored if consumed
	// within this window. Protects against leftover entries when the user
	// navigated away mid-flow and returns to a different page much later.
	const SCROLL_INTENT_TTL_MS = 30_000;

	// Module-level state. Resets on every full page load. On bfcache restore
	// (pageshow persisted=true) we explicitly reset the iframe-reports cache.
	const reportedIframes = new Set();
	let pendingSettleNotifier = null;

	// ── iframeMessageRouter ────────────────────────────────────────
	// Single global "message" listener. Sizes wrapper height from
	// postMessage events sent by BlockPeekPoster.js inside each iframe.
	// Notifies scrollRestorer (when awaiting) that an iframe reported.
	function handleIframeMessage(event) {
		if (event.data?.type !== "resize" || !event.data?.id) return;
		const id = String(event.data.id);
		// Match by sender, not id alone: sprog's language compare panel repeats the
		// slice list of the current language, so one slice id can exist twice.
		const iframe = Array.from(document.querySelectorAll(`iframe[data-iframe-preview][data-slice-id="${id}"]`)).find(
			(el) => el.contentWindow === event.source,
		);
		if (!iframe) return;
		const wrapper = iframe.parentElement;
		const zoomFactor = parseFloat(wrapper.dataset.zoomFactor) || 0.5;
		wrapper.style.height = `${event.data.height * zoomFactor}px`;
		reportedIframes.add(id);
		if (pendingSettleNotifier) pendingSettleNotifier(id);
	}

	// ── scrollIntent ───────────────────────────────────────────────
	// Capture-phase delegate listeners on document. Write sessionStorage
	// then let the native action proceed. No preventDefault, no fetch.

	function isContentEditPage() {
		return typeof rex !== "undefined" && rex.page === "content/edit";
	}

	function writeIntent(sliceId, intent) {
		try {
			sessionStorage.setItem(SCROLL_INTENT_KEY, JSON.stringify({ sliceId, intent, t: Date.now() }));
		} catch (_e) {
			// sessionStorage may be disabled / quota; silently skip restoration.
		}
	}

	function handleEditClickCapture(e) {
		if (!isContentEditPage()) return;
		const link = e.target.closest?.("a.btn-edit[href*='slice_id']");
		if (!link) return;
		let url;
		try {
			url = new URL(link.href, window.location.origin);
		} catch (_e) {
			return;
		}
		const sliceId = url.searchParams.get("slice_id");
		if (sliceId) writeIntent(sliceId, "edit");
	}

	function handleFormSubmitCapture(e) {
		if (!isContentEditPage()) return;
		const form = e.target;
		if (!form?.matches?.("form")) return;
		const slice = form.closest?.(".rex-slice-edit");
		if (!slice || !slice.id) return;

		const sliceId = slice.id.replace(/^slice/, "");
		if (!sliceId) return;
		// REDAXO uses btn_save and btn_update (apply); Enter-key default-submitter ⇒ save.
		const intent = e.submitter?.name === "btn_update" ? "apply" : "save";
		writeIntent(sliceId, intent);
	}

	// ── scrollRestorer ─────────────────────────────────────────────
	// On rex:ready (content/edit only), reads sessionStorage / URL,
	// waits for iframes to settle, scrolls target into view (nearest),
	// watches for late layout shifts and re-scrolls within the window.

	function readSessionTarget() {
		try {
			const raw = sessionStorage.getItem(SCROLL_INTENT_KEY);
			if (!raw) return null;
			sessionStorage.removeItem(SCROLL_INTENT_KEY);
			const parsed = JSON.parse(raw);
			if (!parsed?.sliceId) return null;
			if (parsed.t && Date.now() - parsed.t > SCROLL_INTENT_TTL_MS) return null;
			return parsed;
		} catch (_e) {
			return null;
		}
	}

	function readUrlTarget() {
		const params = new URLSearchParams(window.location.search);
		const sliceId = params.get("slice_id");
		return sliceId ? { sliceId, intent: "edit" } : null;
	}

	function awaitIframesSettled() {
		return new Promise((resolve) => {
			const expected = new Set(
				Array.from(document.querySelectorAll("iframe[data-iframe-preview][data-slice-id]")).map(
					(el) => el.dataset.sliceId,
				),
			);
			const reported = new Set();
			for (const id of reportedIframes) {
				if (expected.has(id)) reported.add(id);
			}

			const isCovered = () => {
				for (const id of expected) {
					if (!reported.has(id)) return false;
				}
				return true;
			};

			if (expected.size === 0 || isCovered()) {
				resolve();
				return;
			}

			let done = false;
			const finish = () => {
				if (done) return;
				done = true;
				pendingSettleNotifier = null;
				clearTimeout(timer);
				resolve();
			};

			pendingSettleNotifier = (id) => {
				if (expected.has(id)) reported.add(id);
				if (isCovered()) finish();
			};
			const timer = setTimeout(finish, SETTLE_TIMEOUT_MS);
		});
	}

	function watchForLateShifts(sliceEl, durationMs) {
		const start = performance.now();
		let lastTop = sliceEl.getBoundingClientRect().top;
		const tick = () => {
			if (!sliceEl.isConnected) return;
			if (performance.now() - start > durationMs) return;
			const currentTop = sliceEl.getBoundingClientRect().top;
			if (currentTop !== lastTop) {
				sliceEl.scrollIntoView({ block: "nearest", behavior: "auto" });
				lastTop = sliceEl.getBoundingClientRect().top;
			}
			requestAnimationFrame(tick);
		};
		requestAnimationFrame(tick);
	}

	function scrollRestorer() {
		if (!isContentEditPage()) return;
		// A settle is already in flight; don't clobber its notifier.
		if (pendingSettleNotifier) return;

		if ("scrollRestoration" in history) {
			history.scrollRestoration = "manual";
		}

		const target = readSessionTarget() || readUrlTarget();
		if (!target) return;

		const sliceEl = document.getElementById(`slice${target.sliceId}`) || document.getElementById(target.sliceId);
		if (!sliceEl) return;

		awaitIframesSettled().then(() => {
			sliceEl.scrollIntoView({ block: "nearest", behavior: "auto" });
			watchForLateShifts(sliceEl, LATE_SHIFT_WATCH_MS);
		});
	}

	// ── Module bootstrap ───────────────────────────────────────────
	window.addEventListener("message", handleIframeMessage);
	document.addEventListener("click", handleEditClickCapture, true);
	document.addEventListener("submit", handleFormSubmitCapture, true);

	$(document).on("rex:ready rex:selectMedia rex:YForm_selectData", () => {
		scrollRestorer();
	});

	// bfcache / non-reload navigation: pageshow with persisted=true means JS
	// state from a previous load is still in memory. Reset the iframe-reports
	// cache (new page's iframes will report fresh) and re-run scroll restoration.
	window.addEventListener("pageshow", (e) => {
		if (e.persisted) {
			reportedIframes.clear();
			scrollRestorer();
		}
	});
})(jQuery);
