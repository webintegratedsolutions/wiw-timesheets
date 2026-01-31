(function () {
  const config = window.wiwtsClientSync;
  if (!config) {
    return;
  }

  let path = window.location.pathname || '/';
  if (!path.endsWith('/')) {
    path += '/';
  }
  const allowedPaths = Array.isArray(config.paths) ? config.paths : [];

  if (allowedPaths.length > 0 && !allowedPaths.includes(path)) {
    return;
  }

  let modal = document.getElementById('wiwts-sync-modal');
  if (!modal) {
    modal = document.createElement('div');
    modal.id = 'wiwts-sync-modal';
    modal.className = 'wiwts-sync-modal';
    modal.setAttribute('aria-hidden', 'true');
    modal.setAttribute('role', 'status');
    modal.setAttribute('aria-live', 'polite');
    modal.innerHTML = '<div class="wiwts-sync-modal__content">Syncing timesheet records...</div>';
    document.body.appendChild(modal);
  }

  const payload = new URLSearchParams({
    action: 'wiwts_frontend_sync',
    _ajax_nonce: config.nonce,
  });

  modal.classList.add('is-active');
  modal.setAttribute('aria-hidden', 'false');

  fetch(config.ajaxUrl, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
    },
    body: payload.toString(),
  })
    .then((response) => response.json())
    .then((data) => {
      const status = data && data.data ? data.data.status : null;
      if (data && data.success && status === 'success') {
        window.location.reload();
        return;
      }

      modal.classList.remove('is-active');
      modal.setAttribute('aria-hidden', 'true');
    })
    .catch(() => {
      modal.classList.remove('is-active');
      modal.setAttribute('aria-hidden', 'true');
    });
})();
