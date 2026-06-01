// assets/admin/js/qsm-dropzone-fix.js
// Makes the whole dropzone area clickable & accept dragged files.
// Works with existing markup: finds the file input (name="qsm_file" or id="qsm_file")
// and finds a suitable wrapper (common dropzone classes or nearest parent).
(function(){
  'use strict';

  function findFileInput() {
    var el = document.querySelector('input[type="file"][name="qsm_file"]');
    if (!el) el = document.getElementById('qsm_file');
    if (!el) el = document.querySelector('input[type="file"]'); // last resort
    return el;
  }

  function findDropzone(fileInput) {
    if (!fileInput) return null;

    // common explicit id/class candidates (in case your template already uses one)
    var selectors = [
      '#qsm-dropzone',
      '.qsm-dropzone',
      '.qsm-drop-area',
      '.qsm-upload-area',
      '.dropzone',
      '.wp-drag-drop' // generic WP-like
    ];

    for (var i = 0; i < selectors.length; i++) {
      var el = document.querySelector(selectors[i]);
      if (el) return el;
    }

    // fallback: walk up from file input looking for a visually dashed box (heuristic)
    var p = fileInput.parentElement;
    var depth = 0;
    while (p && depth < 6) {
      var style = window.getComputedStyle(p);
      if (style && (style.borderStyle === 'dashed' || (p.className && p.className.indexOf('drop') !== -1) || p.getAttribute('role') === 'button')) {
        return p;
      }
      p = p.parentElement;
      depth++;
    }

    // final fallback: use the immediate parent of the input
    return fileInput.parentElement;
  }

  function attachDropzoneBehavior(dropzone, fileInput) {
    if (!dropzone || !fileInput) return;

    // create a small file-name display if not present
    var fileNameEl = dropzone.querySelector('#qsm_file_name');
    if (!fileNameEl) {
      fileNameEl = document.createElement('div');
      fileNameEl.id = 'qsm_file_name';
      fileNameEl.style.marginTop = '10px';
      fileNameEl.style.fontSize = '13px';
      dropzone.appendChild(fileNameEl);
    }

    // make dropzone clickable
    dropzone.style.cursor = 'pointer';
    dropzone.addEventListener('click', function(e){
      // prevent triggering when user clicked a link or button inside dropzone
      var tag = (e.target && e.target.tagName) ? e.target.tagName.toLowerCase() : '';
      if (tag === 'a' || tag === 'button' || tag === 'input' || tag === 'label') return;
      fileInput.click();
    });

    // show filename when chosen via dialog
    fileInput.addEventListener('change', function(){
      var f = fileInput.files && fileInput.files.length ? fileInput.files[0] : null;
      fileNameEl.textContent = f ? f.name : '';
    });

    // drag visuals
    function onDragOver(e){ e.preventDefault(); dropzone.classList.add('qsm-dragover'); }
    function onDragLeave(e){ e.preventDefault(); dropzone.classList.remove('qsm-dragover'); }

    dropzone.addEventListener('dragover', onDragOver);
    dropzone.addEventListener('dragenter', onDragOver);
    dropzone.addEventListener('dragleave', onDragLeave);
    dropzone.addEventListener('dragend', onDragLeave);

    // handle drop
    dropzone.addEventListener('drop', function(e){
      e.preventDefault();
      dropzone.classList.remove('qsm-dragover');

      var dt = e.dataTransfer || (e.originalEvent && e.originalEvent.dataTransfer);
      if (!dt || !dt.files || dt.files.length === 0) return;

      var files = dt.files;

      // Try direct assignment (works in modern browsers)
      try {
        fileInput.files = files;
      } catch (err) {
        // Fallback: build DataTransfer and assign if browser allows
        try {
          var dataTransfer = new DataTransfer();
          for (var i = 0; i < files.length; i++) dataTransfer.items.add(files[i]);
          fileInput.files = dataTransfer.files;
        } catch (err2) {
          // If we can't assign, ask user to use choose button
          alert('Drop is supported but your browser prevented automatic file assignment. Please click Choose file and pick the file.');
          return;
        }
      }

      // show file name
      fileNameEl.textContent = files[0].name;

      // If you want to auto-submit the form after drop, uncomment the following:
      // var form = fileInput.closest('form'); if (form) form.submit();
    });
  }

  // initialize on DOM ready
  function init() {
    var fileInput = findFileInput();
    if (!fileInput) return;
    var dropzone = findDropzone(fileInput);
    attachDropzoneBehavior(dropzone, fileInput);

    // add minimal CSS for dragover highlighting
    var css = '.qsm-dragover{border-color:#0073aa!important;background:#f3fbff!important;}';
    var s = document.createElement('style'); s.appendChild(document.createTextNode(css));
    document.head.appendChild(s);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();
