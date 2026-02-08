(($) => {
	const DEBOUNCE_DELAY = 50;

	let debounceTimer = null;

	function debounce(func, delay) {
		return function (...args) {
			clearTimeout(debounceTimer);
			debounceTimer = setTimeout(() => func.apply(this, args), delay);
		};
	}

	function iframePreviews() {
		const $iframes = $("iframe[data-iframe-preview]");
		const resizeIframe = (event) => {
			// Only accept messages from your iframe’s origin
			if (event.data?.type === "resize" && event.data?.id) {
				const iframe = $iframes.filter(`[data-slice-id="${event.data.id}"]`)[0];
				if (!iframe) {
					return;
				}
				const wrapper = iframe.parentElement;
				const zoomFactor = parseFloat(wrapper.dataset.zoomFactor) || 0.5;
				wrapper.style.height = `${event.data.height * zoomFactor}px`;
			}
		};

		window.removeEventListener("message", resizeIframe);
		if (!$iframes.length) {
			return;
		}
		window.addEventListener("message", resizeIframe);
	}

	function asyncEdit() {
		if (rex.page !== "content/edit") {
			return;
		}

		let setHistory = true;
		let existingSliceEdit = $(".rex-slice-edit");

		const handleEdit = () => {
			const editButtons = $('a.btn-edit[href*="slice_id"]');
			editButtons
				.off("click.asyncEdit")
				.on("click.asyncEdit", async function (e) {
					e.preventDefault();
					const $this = $(this);
					const slice = $(this).closest(".rex-slice");
					const sliceId = slice.attr("id");
					if (!sliceId) {
						return;
					}
					if (existingSliceEdit.length) {
						if (existingSliceEdit && sliceId === existingSliceEdit.attr("id")) {
							return;
						}
						await restoreExistingSlice(existingSliceEdit);
						existingSliceEdit = null;
					}

					restore();
					rex_loader.show();
					try {
						const result = await fetch(this.href, {
							method: "GET",
							headers: {
								"Content-Type": "text/html",
								"X-Requested-With": "XMLHttpRequest",
							},
						});
						if (!result.ok) throw new Error("Network response was not ok");
						const html = await result.text();
						const resultSlice = $(html).find(`#${sliceId}`);

						if (resultSlice.length) {
							$(".panel-body .alert").remove();
							setSliceEdit(sliceId, slice);
							slice.replaceWith(resultSlice);
							$(document).trigger("rex:ready", [resultSlice]);
							handleSave(resultSlice);
							debouncedScrollToSlice(resultSlice[0]);
							if (setHistory) {
								history.pushState(null, "", $this.attr("href")); // change url without reloading page
							}
							setHistory = true;
						}
						rex_loader.hide();
					} catch (error) {
						console.error("Error editing slice:", error);
					}
				});
		};

		const handleSave = (slice) => {
			const form = slice.find("form");
			const saveButton = slice.find(".btn-save");
			const applyButton = slice.find(".btn-apply");
			if (saveButton.length) {
				if (form.length) {
					form.off("submit.asyncSave").on("submit.asyncSave", (e) => {
						e.preventDefault();
						saveButton.trigger("click.asyncSave");
					});
				}
				saveButton.off("click.asyncSave").on("click.asyncSave", (e) => {
					e.preventDefault();
					const buttonAction = saveButton.attr("name");
					const buttonActionValue = saveButton.attr("value");
					const formData = new FormData(form[0]);
					formData.append(buttonAction, buttonActionValue);
					fetch(form.attr("action"), {
						method: "POST",
						body: formData,
					})
						.then((response) => {
							if (!response.ok) {
								throw new Error("Network response was not ok");
							}
							return response.text();
						})
						.then((html) => {
							const updatedSlice = $(html).find(`#${slice.attr("id")}`);
							if (updatedSlice.length) {
								slice.replaceWith(updatedSlice);
								debouncedScrollToSlice(updatedSlice[0]);
								existingSliceEdit = [];
								handleEdit();
								// update the URL to remove `&function=edit#slice2` after saving
								if (window.location.search.includes("function=edit")) {
									const url = new URL(window.location);
									url.searchParams.delete("function");
									window.history.replaceState(null, "", url.toString());
								}
							}
							$(document).trigger("rex:ready", [updatedSlice || slice]);
						})
						.catch((error) => {
							console.error("Error saving slice:", error);
						});
				});
			}
			if (applyButton.length) {
				applyButton.off("click.asyncApply").on("click.asyncApply", (e) => {
					e.preventDefault();
					const buttonAction = applyButton.attr("name");
					const buttonActionValue = applyButton.attr("value");
					const formData = new FormData(form[0]);
					formData.append(buttonAction, buttonActionValue);
					// store current scroll position to restore it after saving
					const scrollPosition = window.scrollY;
					fetch(form.attr("action"), {
						method: "POST",
						body: formData,
					})
						.then((response) => {
							if (!response.ok) {
								throw new Error("Network response was not ok");
							}
							return response.text();
						})
						.then((html) => {
							const updatedSlice = $(html).find(`#${slice.attr("id")}`);
							if (updatedSlice.length) {
								slice.replaceWith(updatedSlice);
								setSliceEdit(updatedSlice.attr("id"), updatedSlice);
								existingSliceEdit = updatedSlice;
								if (scrollPosition) {
									console.log("Restoring scroll position:", scrollPosition);
									setTimeout(() => {
										window.scrollTo({
											top: scrollPosition,
											behavior: "instant",
										});
									});
								}
							}
							$(document).trigger("rex:ready", [updatedSlice || slice]);
						})
						.catch((error) => {
							console.error("Error updating slice:", error);
						});
				});
			}
		};

		if (existingSliceEdit.length) {
			handleSave(existingSliceEdit);
		}

		const scrollToSlice = (slice) => {
			slice.scrollIntoView({ behavior: "auto" });
		};
		const setSliceEdit = (id, element) => {
			rex.isSliceEditing = true;
			rex.sliceEditCurrent = element.clone(true, true);
			rex.sliceEditCurrentId = id;
		};
		const restore = () => {
			if (rex.isSliceEditing && rex.sliceEditCurrent) {
				const currentSlice = $(`#${rex.sliceEditCurrentId}`);
				if (currentSlice.length && currentSlice.hasClass("rex-slice-edit")) {
					currentSlice.replaceWith(rex.sliceEditCurrent);
				}
				rex.isSliceEditing = false;
				rex.sliceEditCurrent = null;
			}
		};
		const debouncedScrollToSlice = debounce(
			(slice) => scrollToSlice(slice),
			DEBOUNCE_DELAY,
		);
		const restoreExistingSlice = async (slice) => {
			const sliceId = slice.attr("id");
			const $contentNav = $("#rex-js-structure-content-nav");
			const editUrl = $contentNav.find('a[href*="edit"]:first').attr("href");
			if (editUrl) {
				try {
					const result = await fetch(editUrl, {
						method: "GET",
						headers: {
							"Content-Type": "text/html",
							"X-Requested-With": "XMLHttpRequest",
						},
					});
					if (!result.ok) throw new Error("Network response was not ok");
					const html = await result.text();
					const resultSlice = $(html).find(`#${sliceId}`);
					if (resultSlice.length) {
						$(".panel-body .alert").remove();
						setSliceEdit(sliceId, slice);
						slice.replaceWith(resultSlice);
					}
				} catch (error) {
					console.error("Error restoring slice:", error);
				}
			}
			return;
		};
		// handle back/forward navigation
		$(window)
			.off("popstate.asyncEdit")
			.on("popstate.asyncEdit", () => {
				// check current url for slice_id and open edit if found
				const urlParams = new URLSearchParams(window.location.search);
				const sliceId = urlParams.get("slice_id");
				if (sliceId) {
					const $slice = $(`#slice${sliceId}`);
					if ($slice.length) {
						setHistory = false;
						$slice.find("a.btn-edit").trigger("click.asyncEdit");
					}
				} else {
					// no slice_id in url, just scroll to top of page
					window.scrollTo({ top: 0, behavior: "auto" });
				}
			});

		handleEdit();
	}

	$(document).on("rex:ready rex:selectMedia rex:YForm_selectData", () => {
		iframePreviews();
		asyncEdit();
	});
})(jQuery);
