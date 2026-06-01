/**
 * QSM Bulk Importer — Public JS
 *
 * Minimal, dependency-free utilities for frontend components:
 *  - Progress animation helper
 *  - Optional status polling (if an element provides data-qsm-status-url)
 *
 * Safe to include on public pages. Does not perform any cross-origin requests.
 * Uses `fetch` with credentials: 'same-origin' if polling is enabled.
 *
 * The script gracefully no-ops when required attributes are not present.
 */

(function () {
  'use strict';

  /**
   * Animate a progress bar element to a target percentage.
   * @param {HTMLElement} barElem Element with class .qsm-progress-bar
   * @param {number} targetPercent Number 0..100
   */
  function animateProgressBar(barElem, targetPercent) {
    if (!barElem || typeof targetPercent !== 'number') return;
    var pct = Math.max(0, Math.min(100, targetPercent));
    // Set width via style; CSS transition handles animation
    barElem.style.width = pct + '%';
    // Update ARIA
    if (barElem.parentElement && barElem.parentElement.getAttribute) {
      barElem.parentElement.setAttribute('aria-valuenow', String(pct));
    }
  }

  /**
   * Read status JSON from a given URL and update a container.
   * Expected JSON shape (example):
   *   { "status": "importing", "percent": 42, "message": "Importing 42%" }
   *
   * The container should have:
   *   - data-qsm-status-url (URL to poll)
   *   - inside it an element .qsm-progress-bar
   *   - inside it elements .qsm-status-title and .qsm-summary (optional)
   *
   * @param {HTMLElement} container
   * @returns {Promise<void>}
   */
  function fetchAndUpdateStatus(container) {
    if (!container) return Promise.resolve();
    var url = container.getAttribute('data-qsm-status-url');
    if (!url) return Promise.resolve();

    // Use fetch with same-origin credentials to support logged-in endpoints
    return fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (resp) {
        if (!resp.ok) {
          throw new Error('Status fetch failed: ' + resp.status);
        }
        return resp.json();
      })
      .then(function (json) {
        // Apply information if present
        var percent = (typeof json.percent === 'number') ? json.percent : null;
        var statusText = (typeof json.status === 'string') ? json.status : null;
        var message = (typeof json.message === 'string') ? json.message : null;

        var bar = container.querySelector('.qsm-progress-bar');
        if (bar && percent !== null) {
          animateProgressBar(bar, percent);
        }

        var titleEl = container.querySelector('.qsm-status-title');
        if (titleEl && statusText) {
          titleEl.textContent = statusText;
        }

        var summaryEl = container.querySelector('.qsm-summary');
        if (summaryEl && message) {
          summaryEl.textContent = message;
        }
      })
      .catch(function (err) {
        // Fail quietly; optionally expose debug info if data-qsm-debug is truthy
        if (container.getAttribute('data-qsm-debug') === '1') {
          console.warn('QSM public status error:', err);
        }
      });
  }

  /**
   * Initialize all public status containers on the page.
   * They can opt into polling by setting data-qsm-poll-interval (seconds).
   */
  function initPublicStatusContainers() {
    var containers = document.querySelectorAll('.qsm-public[data-qsm-status-url]');
    if (!containers || containers.length === 0) return;

    containers.forEach(function (container) {
      // One-time initial fetch/update
      fetchAndUpdateStatus(container);

      // If poll interval is present, set up polling
      var intervalSec = parseInt(container.getAttribute('data-qsm-poll-interval'), 10);
      if (!isNaN(intervalSec) && intervalSec > 0) {
        // Use setInterval; store handle on element to allow potential cleanup
        var handle = setInterval(function () {
          fetchAndUpdateStatus(container);
        }, intervalSec * 1000);
        container._qsm_poll_handle = handle;
      }

      // Provide a cleanup hook if container is removed from DOM
      // (not required, but a polite precaution)
      var observer = new MutationObserver(function (mutations) {
        mutations.forEach(function (m) {
          m.removedNodes && m.removedNodes.forEach(function (n) {
            if (n === container && container._qsm_poll_handle) {
              clearInterval(container._qsm_poll_handle);
            }
          });
        });
      });
      observer.observe(document.body, { childList: true, subtree: true });
    });
  }

  // DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initPublicStatusContainers);
  } else {
    initPublicStatusContainers();
  }

  // Expose helper for external scripts if needed
  window.QSMBulkPublic = {
    animateProgressBar: animateProgressBar,
    fetchAndUpdateStatus: fetchAndUpdateStatus
  };

})();
