(() => {
  const config = window.auroraOpsAdmin || {};
  const statusRoot = document.getElementById("aurora-system-status-root");
  const opsRoot = document.getElementById("aurora-ops-root");
  if (!statusRoot && !opsRoot) return;

  const routes = config.routes || {};
  const restBase = String(config.restBase || "").replace(/\/?$/, "/");
  const nonce = String(config.nonce || "");
  const basePollMs = Number(config.pollMs || 5000);
  const maxBackoffMs = Number(config.maxBackoffMs || 60000);

  const esc = (value) =>
    String(value ?? "").replace(/[&<>"]/g, (char) => ({
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
    }[char]));

  const endpoint = (key) => String(routes[key] || "").replace(/^\/+/, "");

  const normalizeError = (response, payload) => {
    const status = Number(payload?.data?.status || response.status || 0);
    const retryAfter = Number(payload?.data?.retry_after || response.headers.get("Retry-After") || 0);
    let message = payload?.message || `Request failed (${status || "network"})`;
    if (status === 401) message = "Authentication required. Please log in again.";
    if (status === 403) message = "Insufficient permissions for this operation.";
    if (status === 429) message = "Rate limited. Please retry shortly.";
    return {
      status,
      code: String(payload?.code || "request_failed"),
      retryAfter,
      message,
    };
  };

  const request = async (path, options = {}) => {
    const method = options.method || "GET";
    const headers = { Accept: "application/json" };
    const hasBody = Object.prototype.hasOwnProperty.call(options, "body");
    if (nonce) headers["X-WP-Nonce"] = nonce;
    if (hasBody) headers["Content-Type"] = "application/json";

    const response = await fetch(`${restBase}${path}`, {
      method,
      credentials: "same-origin",
      headers,
      body: hasBody ? JSON.stringify(options.body) : undefined,
    });

    let payload = null;
    try {
      payload = await response.json();
    } catch (err) {
      payload = null;
    }

    if (!response.ok) {
      throw normalizeError(response, payload);
    }
    return payload;
  };

  const formatDate = (value) => (value ? esc(value) : "<span class=\"aurora-muted\">-</span>");

  const createPoller = (task, onSuccess, onFailure) => {
    let timeoutId = 0;
    let currentDelay = basePollMs;
    let stopped = false;

    const schedule = (ms) => {
      if (stopped) return;
      timeoutId = window.setTimeout(run, ms);
    };

    const run = async () => {
      if (stopped) return;
      try {
        const data = await task();
        currentDelay = basePollMs;
        onSuccess(data, currentDelay);
      } catch (error) {
        const retryMs = error?.status === 429 && error.retryAfter > 0 ? error.retryAfter * 1000 : 0;
        currentDelay = retryMs > 0 ? Math.min(maxBackoffMs, retryMs) : Math.min(maxBackoffMs, Math.max(basePollMs, currentDelay * 2));
        onFailure(error, currentDelay);
      } finally {
        schedule(currentDelay);
      }
    };

    return {
      start() {
        run();
      },
      refreshNow() {
        window.clearTimeout(timeoutId);
        currentDelay = basePollMs;
        run();
      },
      stop() {
        stopped = true;
        window.clearTimeout(timeoutId);
      },
    };
  };

  const healthClass = (status) => {
    if (status === "FAIL") return "is-fail";
    if (status === "WARN") return "is-warn";
    return "is-ok";
  };

  const runStatusLabel = (status) => {
    if (status === "error") return "is-fail";
    if (status === "running" || status === "requested" || status === "partial") return "is-warn";
    return "is-ok";
  };

  const buildSystemStatus = (root) => {
    const state = {
      data: null,
      message: "",
      messageType: "warn",
    };

    const render = (nextPollMs) => {
      const data = state.data || {};
      const health = data.health || {};
      const errors = data.ops_errors || {};
      const queue = data.queue || {};
      const timestamps = data.last_run_timestamps || {};
      const reasons = Array.isArray(health.reasons) ? health.reasons : [];
      const messageHtml = state.message
        ? `<div class="notice notice-${state.messageType === "error" ? "error" : "warning"} inline"><p>${esc(state.message)}</p></div>`
        : "";

      root.innerHTML = `
        ${messageHtml}
        <div class="aurora-admin-toolbar">
          <button class="button" id="aurora-status-refresh">Refresh now</button>
          <span class="aurora-muted">Next poll in ${Math.max(1, Math.ceil(nextPollMs / 1000))}s</span>
        </div>
        <div class="aurora-admin-grid">
          <section class="aurora-card">
            <h2>Health</h2>
            <div class="aurora-pill ${healthClass(health.status)}">${esc(health.status || "WARN")}</div>
            <div class="aurora-muted">${reasons.length ? esc(reasons.join(" | ")) : "No active issues."}</div>
          </section>
          <section class="aurora-card">
            <h2>Queue</h2>
            <div class="aurora-metric">${Number(queue.dead || 0)}</div>
            <div class="aurora-muted">Dead queue items</div>
          </section>
          <section class="aurora-card">
            <h2>Ops Errors</h2>
            <div class="aurora-metric">${Number(errors.filtered || 0)} / ${Number(errors.total || 0)}</div>
            <div class="aurora-muted">Filtered (24h) / Total</div>
          </section>
          <section class="aurora-card">
            <h2>Last Error</h2>
            <div><strong>Op:</strong> ${esc(data.last_error?.op_key || "-")}</div>
            <div><strong>At:</strong> ${formatDate(data.last_error?.created_at)}</div>
            <div class="aurora-muted">${esc(data.last_error?.message || "No recent errors.")}</div>
          </section>
        </div>
        <section class="aurora-card">
          <h2>Last Run Timestamps</h2>
          <table class="widefat striped">
            <tbody>
              <tr><th>Repricer tick</th><td>${formatDate(timestamps.repricer_tick)}</td></tr>
              <tr><th>Repricer run</th><td>${formatDate(timestamps.repricer_run)}</td></tr>
              <tr><th>Feed enqueue</th><td>${formatDate(timestamps.feed_enqueue)}</td></tr>
              <tr><th>Feed run</th><td>${formatDate(timestamps.feed_run)}</td></tr>
              <tr><th>Rebuild</th><td>${formatDate(timestamps.rebuild)}</td></tr>
              <tr><th>Sweep leases</th><td>${formatDate(timestamps.sweep_leases)}</td></tr>
            </tbody>
          </table>
        </section>
      `;

      const refreshButton = root.querySelector("#aurora-status-refresh");
      if (refreshButton) {
        refreshButton.addEventListener("click", () => {
          poller.refreshNow();
        });
      }
    };

    const poller = createPoller(
      () => request(endpoint("status")),
      (data) => {
        state.data = data;
        state.message = "";
        state.messageType = "warn";
        render(basePollMs);
      },
      (error, nextPollMs) => {
        if (error.status === 429) {
          const wait = error.retryAfter > 0 ? error.retryAfter : Math.ceil(nextPollMs / 1000);
          state.message = `Rate limited, retry in ${wait}s.`;
          state.messageType = "warn";
        } else if (error.status === 401 || error.status === 403) {
          state.message = error.message;
          state.messageType = "error";
        } else {
          state.message = `${error.message}. Backoff ${Math.ceil(nextPollMs / 1000)}s.`;
          state.messageType = "warn";
        }
        render(nextPollMs);
      }
    );

    render(basePollMs);
    poller.start();
  };

  const buildOpsPage = (root) => {
    const state = {
      data: null,
      notice: "",
      noticeType: "success",
      cooldowns: new Map(),
      pending: new Set(),
    };

    const setNotice = (message, type = "success") => {
      state.notice = message;
      state.noticeType = type;
      render(basePollMs);
    };

    const lockButton = (buttonKey, isPending) => {
      if (isPending) state.pending.add(buttonKey);
      else state.pending.delete(buttonKey);
      render(basePollMs);
    };

    const hasFocusedInput = () => {
      const active = document.activeElement;
      if (!active) return false;
      if (!root.contains(active)) return false;
      return ["INPUT", "SELECT", "TEXTAREA"].includes(active.tagName);
    };

    const startCooldown = (buttonKey, seconds) => {
      if (!Number.isFinite(seconds) || seconds <= 0) return;
      state.cooldowns.set(buttonKey, seconds);
      const intervalId = window.setInterval(() => {
        const current = Number(state.cooldowns.get(buttonKey) || 0);
        if (current <= 1) {
          state.cooldowns.delete(buttonKey);
          window.clearInterval(intervalId);
        } else {
          state.cooldowns.set(buttonKey, current - 1);
        }
        render(basePollMs);
      }, 1000);
    };

    const recentRows = () => {
      const runs = Array.isArray(state.data?.recent_runs) ? state.data.recent_runs : [];
      if (!runs.length) {
        return "<tr><td colspan=\"7\" class=\"aurora-muted\">No recent runs.</td></tr>";
      }
      return runs.map((run) => `
        <tr>
          <td>${esc(run.id)}</td>
          <td>${esc(run.op_key)}</td>
          <td><span class="aurora-pill ${runStatusLabel(run.status)}">${esc(run.status)}</span></td>
          <td>${formatDate(run.created_at)}</td>
          <td>${formatDate(run.started_at)}</td>
          <td>${formatDate(run.finished_at)}</td>
          <td>${esc(run.error || run.message || "-")}</td>
        </tr>
      `).join("");
    };

    const label = (key, fallback) => {
      const cooldown = Number(state.cooldowns.get(key) || 0);
      if (cooldown > 0) return `Retry in ${cooldown}s`;
      if (state.pending.has(key)) return "Running...";
      return fallback;
    };

    const disabled = (key) => state.pending.has(key) || Number(state.cooldowns.get(key) || 0) > 0;

    const render = (nextPollMs) => {
      const noticeHtml = state.notice
        ? `<div class="notice notice-${state.noticeType} inline"><p>${esc(state.notice)}</p></div>`
        : "";
      const errors = state.data?.ops_errors || {};
      const health = state.data?.health || {};

      root.innerHTML = `
        ${noticeHtml}
        <div class="aurora-admin-toolbar">
          <button class="button" id="aurora-ops-refresh">Refresh now</button>
          <span class="aurora-muted">Next poll in ${Math.max(1, Math.ceil(nextPollMs / 1000))}s</span>
          <span class="aurora-pill ${healthClass(health.status)}">${esc(health.status || "WARN")}</span>
          <span class="aurora-muted">Errors (24h/total): ${Number(errors.filtered || 0)} / ${Number(errors.total || 0)}</span>
        </div>
        <div class="aurora-admin-grid">
          <section class="aurora-card">
            <h2>Feed</h2>
            <p class="description">Queue feed generation safely.</p>
            <label>Chunk size <input type="number" id="aurora-feed-chunk" min="1" max="10000" value="1000"></label>
            <div class="aurora-actions">
              <button class="button" id="aurora-feed-enqueue" ${disabled("feed_enqueue") ? "disabled" : ""}>${label("feed_enqueue", "Feed enqueue")}</button>
              <button class="button button-primary" id="aurora-feed-run" ${disabled("feed_run") ? "disabled" : ""}>${label("feed_run", "Feed run")}</button>
            </div>
          </section>
          <section class="aurora-card">
            <h2>Indicizzatore catalogo</h2>
            <label>Ambito indicizzazione
              <select id="aurora-rebuild-indexer">
                <option value="all">all</option>
                <option value="price">price</option>
                <option value="stock">stock</option>
                <option value="visibility">visibility</option>
              </select>
            </label>
            <div class="aurora-actions">
              <button class="button" id="aurora-rebuild" ${disabled("rebuild") ? "disabled" : ""}>${label("rebuild", "Rebuild")}</button>
            </div>
          </section>
          <section class="aurora-card">
            <h2>Sweep leases</h2>
            <label>Channel
              <select id="aurora-sweep-channel">
                <option value="all">all</option>
                <option value="price">price</option>
                <option value="stock">stock</option>
                <option value="visibility">visibility</option>
                <option value="feed">feed</option>
              </select>
            </label>
            <div class="aurora-actions">
              <button class="button" id="aurora-sweep" ${disabled("sweep_leases") ? "disabled" : ""}>${label("sweep_leases", "Sweep leases")}</button>
            </div>
          </section>
          <section class="aurora-card">
            <h2>Repricer tick</h2>
            <label>only_assignment_id
              <input type="number" id="aurora-only-assignment" min="0" value="0">
            </label>
            <div class="aurora-actions">
              <button class="button" id="aurora-repricer-tick" ${disabled("repricer_tick") ? "disabled" : ""}>${label("repricer_tick", "Repricer scheduler tick")}</button>
            </div>
          </section>
        </div>
        <section class="aurora-card">
          <h2>Recent activity</h2>
          <table class="widefat striped">
            <thead>
              <tr><th>ID</th><th>Op</th><th>Status</th><th>Created</th><th>Started</th><th>Finished</th><th>Message</th></tr>
            </thead>
            <tbody>${recentRows()}</tbody>
          </table>
        </section>
      `;

      const bindButton = (id, buttonKey, callback) => {
        const button = root.querySelector(`#${id}`);
        if (!button) return;
        button.addEventListener("click", async () => {
          if (disabled(buttonKey)) return;
          lockButton(buttonKey, true);
          try {
            const payload = await callback();
            const runId = payload?.run_id ?? payload?.response?.run_id ?? payload?.data?.run_id;
            const summary = runId ? `run_id=${runId}` : "request accepted";
            setNotice(`Success: ${summary}`, "success");
            poller.refreshNow();
          } catch (error) {
            if (error.status === 429) {
              const retry = error.retryAfter > 0 ? error.retryAfter : 5;
              startCooldown(buttonKey, retry);
              setNotice(`Rate limited: retry in ${retry}s`, "warning");
            } else {
              setNotice(error.message || "Request failed", "error");
            }
          } finally {
            lockButton(buttonKey, false);
          }
        });
      };

      bindButton("aurora-feed-enqueue", "feed_enqueue", async () => {
        const chunkInput = root.querySelector("#aurora-feed-chunk");
        return request(endpoint("feedEnqueue"), {
          method: "POST",
          body: { chunk_size: Number(chunkInput?.value || 1000) },
        });
      });

      bindButton("aurora-feed-run", "feed_run", async () =>
        request(endpoint("feedRun"), { method: "POST", body: { batch: 100, max_loops: 1 } })
      );

      bindButton("aurora-rebuild", "rebuild", async () => {
        const indexer = root.querySelector("#aurora-rebuild-indexer");
        return request(endpoint("rebuild"), { method: "POST", body: { indexer: String(indexer?.value || "all") } });
      });

      bindButton("aurora-sweep", "sweep_leases", async () => {
        const channel = root.querySelector("#aurora-sweep-channel");
        return request(endpoint("sweepLeases"), { method: "POST", body: { channel: String(channel?.value || "all") } });
      });

      bindButton("aurora-repricer-tick", "repricer_tick", async () => {
        const assignment = root.querySelector("#aurora-only-assignment");
        return request(endpoint("repricerTick"), {
          method: "POST",
          body: { only_assignment_id: Number(assignment?.value || 0) },
        });
      });

      const refreshButton = root.querySelector("#aurora-ops-refresh");
      if (refreshButton) {
        refreshButton.addEventListener("click", () => {
          poller.refreshNow();
        });
      }
    };

    const poller = createPoller(
      () => request(endpoint("status")),
      (data) => {
        state.data = data;
        if (state.noticeType !== "error") {
          state.notice = "";
        }
        if (!hasFocusedInput()) {
          render(basePollMs);
        }
      },
      (error, nextPollMs) => {
        state.notice = `${error.message}. Next retry in ${Math.ceil(nextPollMs / 1000)}s.`;
        state.noticeType = error.status === 401 || error.status === 403 ? "error" : "warning";
        render(nextPollMs);
      }
    );

    render(basePollMs);
    poller.start();
  };

  if (statusRoot) {
    buildSystemStatus(statusRoot);
  }
  if (opsRoot) {
    buildOpsPage(opsRoot);
  }
})();
