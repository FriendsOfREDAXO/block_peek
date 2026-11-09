(() => {
	"use strict";

	let panel = null;
	let toggle = null;

	function close(restoreFocus = false) {
		panel?.remove();
		toggle?.setAttribute("aria-expanded", "false");
		toggle?.removeAttribute("aria-controls");
		if (restoreFocus) toggle?.focus();
		panel = null;
		toggle = null;
	}

	function open(button, dropdown, menu) {
		for (const native of document.querySelectorAll(".dropdown.open, .btn-group.open")) {
			native.classList.remove("open");
			for (const nativeToggle of native.querySelectorAll('[data-toggle="dropdown"]')) {
				nativeToggle.setAttribute("aria-expanded", "false");
			}
		}
		toggle = button;
		panel = document.createElement("div");
		panel.className = "bp-picker";
		panel.id = "bp-picker-dialog";
		panel.setAttribute("role", "dialog");
		panel.setAttribute("aria-label", button.textContent.trim());

		const bar = document.createElement("label");
		bar.className = "bp-picker-bar";
		const label = document.createElement("span");
		label.className = "bp-picker-label";
		label.textContent = window.rex.block_peek_picker_i18n?.search_label ?? "Modul suchen";
		const search = document.createElement("input");
		search.type = "search";
		search.className = "bp-picker-search form-control";
		search.placeholder = window.rex.block_peek_picker_i18n?.search_placeholder ?? "Modul suchen …";
		bar.append(label, search);
		panel.append(bar);

		const body = document.createElement("div");
		body.className = "bp-picker-body";
		panel.append(body);
		let list = null;
		const rows = [];
		for (const item of menu.children) {
			const header = item.matches(".dropdown-header") ? item : item.querySelector(".dropdown-header");
			if (header) {
				const heading = document.createElement("h3");
				heading.className = "bp-picker-group";
				heading.textContent = header.textContent.trim();
				body.append(heading);
				list = null;
				continue;
			}
			const link = item.querySelector("a[href]");
			if (!link) continue;
			if (!list) {
				list = document.createElement("div");
				list.className = "bp-picker-list";
				body.append(list);
			}
			const href = link.getAttribute("href");
			// Read the raw href: async/test documents can have an about:blank base.
			const moduleId = href.match(/[?&]module_id=(\d+)/)?.[1];
			const preview = window.rex.block_peek_picker[moduleId];
			const row = document.createElement("a");
			row.className = "bp-picker-row";
			row.setAttribute("href", href);
			if (moduleId) {
				const thumb = document.createElement(preview?.svg ? "img" : "div");
				thumb.className = "bp-picker-thumb";
				if (preview?.svg) {
					// SVGs stay in an image context; never insert their markup into the backend DOM.
					thumb.src = "data:image/svg+xml;charset=utf-8," + encodeURIComponent(preview.svg);
					thumb.alt = "";
				} else {
					thumb.classList.add("bp-picker-thumb--empty");
					thumb.setAttribute("aria-hidden", "true");
				}
				row.append(thumb);
			}
			const meta = document.createElement("div");
			meta.className = "bp-picker-meta";
			const name = document.createElement("div");
			name.className = "bp-picker-name";
			name.textContent = link.textContent.trim().replace(/^[–-]\s*/, "");
			const description = document.createElement("div");
			description.className = "bp-picker-desc";
			description.textContent = preview?.description || "";
			meta.append(name, description);
			row.append(meta);
			list.append(row);
			rows.push(row);
		}

		search.addEventListener("input", () => {
			const query = search.value.trim().toLocaleLowerCase();
			for (const row of rows) row.hidden = !row.textContent.toLocaleLowerCase().includes(query);
			for (const heading of body.querySelectorAll(".bp-picker-group")) {
				heading.hidden = !heading.nextElementSibling?.querySelector(".bp-picker-row:not([hidden])");
			}
		});
		panel.addEventListener("keydown", (event) => {
			if (event.key === "Escape") {
				event.preventDefault();
				event.stopPropagation();
				close(true);
				return;
			}
			if (event.key === "Enter" && event.target === search) {
				event.preventDefault();
				rows.find((row) => !row.hidden)?.click();
				return;
			}
			if (["Home", "End"].includes(event.key) && !event.target.closest(".bp-picker-row")) return;
			if (!["ArrowDown", "ArrowUp", "Home", "End"].includes(event.key)) return;
			const visible = rows.filter((row) => !row.hidden);
			if (!visible.length) return;
			event.preventDefault();
			const index = visible.indexOf(document.activeElement);
			let next;
			if (event.key === "Home") next = 0;
			else if (event.key === "End") next = visible.length - 1;
			else if (event.key === "ArrowDown") next = (index + 1) % visible.length;
			else next = (index < 0 ? visible.length : index) - 1;
			visible[(next + visible.length) % visible.length].focus();
		});

		dropdown.after(panel);
		button.setAttribute("aria-expanded", "true");
		button.setAttribute("aria-controls", panel.id);
		search.focus();
	}

	function handleToggle(event) {
		if (window.rex?.page !== "content/edit" || !window.rex?.block_peek_picker) return;
		const button = event.target.closest?.('.rex-slice-select [data-toggle="dropdown"]');
		if (button) {
			if (button.matches(':disabled, [disabled], [aria-disabled="true"]')) return;
			if (event.type === "keydown") {
				if (event.key === "Escape" && button === toggle && panel?.isConnected) {
					event.preventDefault();
					event.stopPropagation();
					close(true);
					return;
				}
				if (![" ", "Enter", "ArrowDown", "ArrowUp"].includes(event.key)) return;
			}
			const dropdown = button.closest(".dropdown") || button.parentElement;
			const menu = dropdown.querySelector(".dropdown-menu");
			if (!menu) return;
			event.preventDefault();
			event.stopPropagation();
			const wasOpen = event.type === "click" && button === toggle && panel?.isConnected;
			close();
			if (!wasOpen) open(button, dropdown, menu);
		} else if (event.type === "click" && panel && !panel.contains(event.target)) {
			close();
		}
	}

	document.addEventListener("click", handleToggle, true);
	document.addEventListener("keydown", handleToggle, true);
})();
