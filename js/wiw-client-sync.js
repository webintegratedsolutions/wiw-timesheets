(function () {
  const config = window.wiwtsClientSync;
  if (!config) return;

  let path = window.location.pathname || "/";
  if (!path.endsWith("/")) path += "/";
  const allowedPaths = Array.isArray(config.paths) ? config.paths : [];
  if (allowedPaths.length > 0 && !allowedPaths.includes(path)) return;

  const rateLimitSeconds =
    typeof config.rateLimitSeconds === "number" && config.rateLimitSeconds > 0
      ? config.rateLimitSeconds
      : 900;

  // Client-side gating (reduces calls + prevents "syncing" modal on every refresh)
  const storageKey = "wiwts_last_sync_ts_" + path;
  const nowMs = Date.now();
  const lastMsRaw = window.localStorage ? window.localStorage.getItem(storageKey) : null;
  const lastMs = lastMsRaw ? parseInt(lastMsRaw, 10) : 0;

  if (lastMs && !isNaN(lastMs)) {
    const elapsedSec = (nowMs - lastMs) / 1000;
    if (elapsedSec < rateLimitSeconds) {
      return;
    }
  }

  let modal = document.getElementById("wiwts-sync-modal");
  if (!modal) {
    modal = document.createElement("div");
    modal.id = "wiwts-sync-modal";
    modal.className = "wiwts-sync-modal";
    modal.setAttribute("aria-hidden", "true");
    modal.setAttribute("role", "status");
    modal.setAttribute("aria-live", "polite");
    modal.innerHTML =
      '<div class="wiwts-sync-modal__content">Syncing timesheet records...</div>';
    document.body.appendChild(modal);
  }

  const payload = new URLSearchParams({
    action: "wiwts_frontend_sync",
    _ajax_nonce: config.nonce,
  });

  modal.classList.add("is-active");
  modal.setAttribute("aria-hidden", "false");

  fetch(config.ajaxUrl, {
    method: "POST",
    credentials: "same-origin",
    headers: {
      "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
    },
    body: payload.toString(),
  })
    .then((response) => response.json())
    .then((data) => {
      const status = data && data.data ? data.data.status : null;

      // Record that we attempted sync, regardless of success vs rate_limit, to prevent modal spam.
      try {
        if (window.localStorage) {
          window.localStorage.setItem(storageKey, String(Date.now()));
        }
      } catch (e) {}

      if (data && data.success && status === "success") {
        window.location.reload();
        return;
      }

      modal.classList.remove("is-active");
      modal.setAttribute("aria-hidden", "true");
    })
    .catch(() => {
      // Even on errors, avoid hammering on refresh loops; set cooldown so user isn't stuck seeing modal repeatedly
      try {
        if (window.localStorage) {
          window.localStorage.setItem(storageKey, String(Date.now()));
        }
      } catch (e) {}

      modal.classList.remove("is-active");
      modal.setAttribute("aria-hidden", "true");
    });
})();
