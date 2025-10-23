;(function ($) {
  'use strict'
  const DEBOUNCE_DELAY = 50
  const debouncedScrollToSliceFromHash = debounce(
    () => scrollToSliceFromHash(),
    DEBOUNCE_DELAY
  )

  let debounceTimer = null

  function debounce(func, delay) {
    return function (...args) {
      const context = this
      clearTimeout(debounceTimer)
      debounceTimer = setTimeout(() => func.apply(context, args), delay)
    }
  }

  function iframePreviews() {
    const $iframes = $('iframe[data-iframe-preview]')
    const resizeIframe = (event) => {
      // Only accept messages from your iframe’s origin
      if (event.data?.type === 'resize' && event.data?.id) {
        const iframe = $iframes.filter(`[data-slice-id="${event.data.id}"]`)[0]
        if (!iframe) {
          return
        }
        const wrapper = iframe.parentElement
        const zoomFactor = parseFloat(wrapper.dataset.zoomFactor) || 0.5
        wrapper.style.height = event.data.height * zoomFactor + 'px'
      }
    }

    window.removeEventListener('message', resizeIframe)
    if (!$iframes.length) {
      return
    }
    window.addEventListener('message', resizeIframe)
  }

  function scrollToSliceFromHash() {
    const pageHash = window.location.hash.substring(1)
    if (pageHash) {
      // Scroll to the hash after a short delay to ensure the iframe has resized
      const targetElement = document.getElementById(pageHash)
      if (targetElement) {
        clearTimeout(debounceTimer)
        targetElement.scrollIntoView({ behavior: 'auto' })
      }
    }
  }

  function asyncEdit() {
    if (rex.page !== 'content/edit') {
      return
    }

    let setHistory = true
    let existingSliceEdit = $('.rex-slice-edit')

    const editButtons = $('a.btn-edit[href*="slice_id"]')
    editButtons
      .off('click.asyncEdit')
      .on('click.asyncEdit', async function (e) {
        e.preventDefault()
        const $this = $(this)
        const slice = $(this).closest('.rex-slice')
        const sliceId = slice.attr('id')
        if (!sliceId) {
          return
        }
        if (existingSliceEdit.length) {
          if (sliceId === existingSliceEdit.attr('id')) {
            return
          }
          await restoreExistingSlice(existingSliceEdit)
          existingSliceEdit = null
        }

        restore()
        rex_loader.show()
        try {
          const result = await fetch(this.href, {
            method: 'GET',
            headers: {
              'Content-Type': 'text/html',
              'X-Requested-With': 'XMLHttpRequest'
            }
          })
          if (!result.ok) throw new Error('Network response was not ok')
          const html = await result.text()
          const resultSlice = $(html).find(`#${sliceId}`)

          if (resultSlice.length) {
            $('.panel-body .alert').remove()
            setSliceEdit(sliceId, slice)
            slice.replaceWith(resultSlice)
            $(document).trigger('rex:ready', [resultSlice])
            debouncedScrollToSlice(resultSlice[0])
            if (setHistory) {
              history.pushState(null, '', $this.attr('href')) // change url without reloading page
            }
            setHistory = true
          }
          rex_loader.hide()
        } catch (error) {
          console.error('Error editing slice:', error)
        }
      })
    const scrollToSlice = (slice) => {
      slice.scrollIntoView({ behavior: 'auto' })
    }
    const setSliceEdit = (id, element) => {
      rex.isSliceEditing = true
      rex.sliceEditCurrent = element.clone(true, true)
      rex.sliceEditCurrentId = id
    }
    const restore = () => {
      if (rex.isSliceEditing && rex.sliceEditCurrent) {
        const currentSlice = $(`#${rex.sliceEditCurrentId}`)
        if (currentSlice.length && currentSlice.hasClass('rex-slice-edit')) {
          currentSlice.replaceWith(rex.sliceEditCurrent)
        }
        rex.isSliceEditing = false
        rex.sliceEditCurrent = null
      }
    }
    const debouncedScrollToSlice = debounce(
      (slice) => scrollToSlice(slice),
      DEBOUNCE_DELAY
    )
    const restoreExistingSlice = async (slice) => {
      const sliceId = slice.attr('id')
      const $contentNav = $('#rex-js-structure-content-nav')
      const editUrl = $contentNav.find('a[href*="edit"]:first').attr('href')
      if (editUrl) {
        try {
          const result = await fetch(editUrl, {
            method: 'GET',
            headers: {
              'Content-Type': 'text/html',
              'X-Requested-With': 'XMLHttpRequest'
            }
          })
          if (!result.ok) throw new Error('Network response was not ok')
          const html = await result.text()
          const resultSlice = $(html).find(`#${sliceId}`)
          if (resultSlice.length) {
            $('.panel-body .alert').remove()
            setSliceEdit(sliceId, slice)
            slice.replaceWith(resultSlice)
          }
        } catch (error) {
          console.error('Error restoring slice:', error)
        }
      }
      return
    }
    // handle back/forward navigation
    $(window)
      .off('popstate.asyncEdit')
      .on('popstate.asyncEdit', function (e) {
        // check current url for slice_id and open edit if found
        const urlParams = new URLSearchParams(window.location.search)
        const sliceId = urlParams.get('slice_id')
        if (sliceId) {
          const $slice = $(`#slice${sliceId}`)
          if ($slice.length) {
            setHistory = false
            $slice.find('a.btn-edit').trigger('click.asyncEdit')
          }
        } else {
          // no slice_id in url, just scroll to top of page
          window.scrollTo({ top: 0, behavior: 'auto' })
        }
      })
  }

  $(document).on('rex:ready rex:selectMedia rex:YForm_selectData', function () {
    iframePreviews()
    debouncedScrollToSliceFromHash()
    asyncEdit()
  })
})(jQuery)
