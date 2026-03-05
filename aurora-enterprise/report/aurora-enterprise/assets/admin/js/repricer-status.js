(() => {
  const root = document.getElementById("aurora-repricer-root");
  if (!root || !window.auroraRepricer) return;

  const { restBase, nonce, pollMs } = window.auroraRepricer;
  let timer = null;

  const state = {
    lastRunStatus: null,
  };

  const badge = (status) => {
    const cls =
      status === "error"
        ? "aurora-badge error"
        : status === "warning"
        ? "aurora-badge warn"
        : "aurora-badge ok";
    return `<span class="${cls}">${status || "n/a"}</span>`;
  };

  const render = (data) => {
    const repr = data?.repricer || {};
    const lastRun = repr.last_run;
    const progress = repr.progress;
    const decisions = repr.decisions || {};
    const recent = repr.recent_decisions || [];
    const assignments = repr.assignments || [];
    const lastRollback = repr.last_rollback_run || null;
    const scheduler = repr.scheduler || {};

    state.lastRunStatus = lastRun?.status || null;

    const lastRunHtml = lastRun
      ? `<div><strong>ID:</strong> ${lastRun.id}</div>
         <div><strong>Status:</strong> ${badge(lastRun.status)}</div>
         <div><strong>Mode:</strong> ${lastRun.mode || '-'}</div>
         <div><strong>Message:</strong> ${lastRun.message || "-"}</div>
         <div><strong>Error:</strong> ${lastRun.error || "-"}</div>
         <div><strong>Requested:</strong> ${lastRun.requested_at || "-"}</div>
         <div><strong>Started:</strong> ${lastRun.started_at || "-"}</div>
         <div><strong>Finished:</strong> ${lastRun.finished_at || "-"}</div>`
      : "<div class=\"aurora-muted\">Nessun run trovato.</div>";

    const progressHtml = progress
      ? `<div><strong>Status:</strong> ${progress.status}</div>
         <div><strong>Processed:</strong> ${progress.processed_count}</div>
         <div><strong>Updated:</strong> ${progress.updated_count}</div>
         <div><strong>Last product:</strong> ${progress.last_product_id}</div>
         <div><strong>Updated at:</strong> ${progress.updated_at || "-"}</div>`
      : "<div class=\"aurora-muted\">Nessun progresso disponibile.</div>";

    const breakdown = (decisions.breakdown || [])
      .map((r) => `<tr><td>${r.rule_applied}</td><td>${r.c}</td></tr>`)
      .join("");

    const recentRows = recent
      .map(
        (r) =>
          `<tr><td>${r.product_id}</td><td>${r.old_price ?? "-"}</td><td>${r.new_price ?? "-"}</td><td>${r.rule_applied}</td><td>${r.created_at}</td></tr>`
      )
      .join("");

    const html = `
      <div class="aurora-repricer-grid">
        <div class="aurora-card">
          <h3>Ultimo run</h3>
          ${lastRunHtml}
        </div>
        <div class="aurora-card">
          <h3>Progress</h3>
          ${progressHtml}
        </div>
      <div class="aurora-card">
        <h3>Decisions</h3>
        <div><strong>Totale:</strong> ${decisions.decisions_count ?? 0}</div>
        <div><strong>Prodotti unici:</strong> ${decisions.distinct_products ?? 0}</div>
        <div><strong>Applied:</strong> ${decisions.applied_count_last_run ?? 0}</div>
        <div><strong>Rollback pending (last apply run):</strong> ${repr.rollback_pending_count_last_apply_run ?? 0}</div>
        <div><strong>Rollback queue:</strong> ${repr.rollback_queue_count ?? 0}</div>
        <table class="aurora-table" aria-label="Repricer breakdown">
          <thead><tr><th>Rule</th><th>Count</th></tr></thead>
          <tbody>${breakdown || '<tr><td colspan="2" class="aurora-muted">N/A</td></tr>'}</tbody>
        </table>
       </div>
      </div>
      <div class="aurora-card">
        <h3>Decisions recenti</h3>
        <table class="aurora-table">
          <thead><tr><th>Product</th><th>Old</th><th>New</th><th>Rule</th><th>Created</th></tr></thead>
          <tbody>${recentRows || '<tr><td colspan="5" class="aurora-muted">Nessuna decisione</td></tr>'}</tbody>
        </table>
      </div>
      <div class="aurora-card">
        <h3>Scheduler</h3>
        <div><strong>Mode:</strong> ${scheduler.mode || '-'}</div>
        <div><strong>In window:</strong> ${scheduler.in_window === null ? 'n/a' : (scheduler.in_window ? 'yes' : 'no')}</div>
        <div><strong>Cursor:</strong> ${scheduler.cursor ?? 0}</div>
        <div><strong>Last at:</strong> ${scheduler.last_at || '-'}</div>
        <div><strong>Enqueued last:</strong> ${scheduler.enqueued_last ?? 0}</div>
        <div><strong>Skipped last:</strong> ${scheduler.skipped_last ?? 0}</div>
        <div><strong>Skipped out-of-window last:</strong> ${scheduler.skipped_out_of_window_last ?? 0}</div>
        <div><strong>Last error:</strong> ${scheduler.last_error || '-'}</div>
        <button class="button" id="aurora-tick-now">Run scheduler tick now</button>
        <span id="aurora-tick-result" class="aurora-muted"></span>
      </div>
      <div class="aurora-card">
        <h3>Trigger repricer (dry-run)</h3>
        <form id="aurora-repricer-form" class="aurora-form">
          ${formField("max_products", "Max products", 50)}
          ${formField("chunk_size", "Chunk size", 25)}
          ${formField("timebox_seconds", "Timebox (s)", 10)}
          ${formField("min_margin_percent", "Min margin %", 0)}
          ${formField("min_margin_abs", "Min margin abs", 0)}
          <label><input type="checkbox" name="dry_run" checked /> Dry run</label>
          <button class="button button-primary" type="submit">Run repricer (dry-run)</button>
          <span id="aurora-repricer-result" class="aurora-muted"></span>
        </form>
      </div>
      <div class="aurora-card">
        <h3>Assignments</h3>
        <div class="aurora-meta">
          <span><strong>Enabled:</strong> ${repr.enabled_assignments_count ?? 0}</span>
          <span><strong>Queued runs:</strong> ${repr.queued_runs_count ?? 0}</span>
          <span><strong>Rollback queued:</strong> ${repr.rollback_queue_count ?? 0}</span>
          <button class="button run-all-dry">Run-all dry-run</button>
          <button class="button button-primary run-all-apply">Run-all apply</button>
          <span id="aurora-runall-result" class="aurora-muted"></span>
        </div>
        <table class="aurora-table" id="aurora-assignments-table">
          <thead>
            <tr>
              <th>ID</th><th>Name</th><th>Priority</th><th>Scope</th><th>Last run</th><th>Actions</th>
            </tr>
          </thead>
          <tbody>
            ${assignments.length === 0 ? '<tr><td colspan="6" class="aurora-muted">No assignments</td></tr>' : assignments.map(a => {
              const lr = a.last_run || {};
              return `<tr data-assignment="${a.id}">
                <td>${a.id}</td>
                <td>${a.name || "-"}</td>
                <td>${a.priority ?? "-"}</td>
                <td>${a.scope_type || "-"}</td>
                <td>${lr.id ? `#${lr.id} ${badge(lr.status)} (${lr.decisions_count ?? 0})` : '-'}</td>
                <td>
                  <button class="button preview-btn" data-id="${a.id}">Preview</button>
                  <button class="button dryrun-btn" data-id="${a.id}">Dry-run</button>
                </td>
              </tr>`;
            }).join("")}
          </tbody>
        </table>
        <div id="aurora-preview-panel" class="aurora-muted"></div>
      </div>
      <div class="aurora-card">
        <h3>Rollback</h3>
        <div class="aurora-meta">
          <div><strong>Last rollback:</strong> ${lastRollback ? `#${lastRollback.id} ${badge(lastRollback.status)} (${lastRollback.updated_at || lastRollback.finished_at || '-'})` : 'n/a'}</div>
        </div>
        <form id="aurora-rollback-form" class="aurora-form">
          ${formField("rollback_run_id", "Apply run_id", '')}
          <label><input type="checkbox" name="rollback_dry" /> Dry run</label>
          <button class="button" type="submit">Rollback run_id</button>
          <button class="button button-primary" type="button" id="aurora-rollback-last">Rollback last apply</button>
          <span id="aurora-rollback-result" class="aurora-muted"></span>
        </form>
      </div>
    `;
    root.innerHTML = html;
    bindForm();
    bindAssignments();
    bindRollback(repr);
    bindScheduler();
  };

  const formField = (name, label, value) =>
    `<label>${label}<br/><input type="number" name="${name}" value="${value}" /></label>`;

  const bindForm = () => {
    const form = document.getElementById("aurora-repricer-form");
    if (!form) return;
    form.addEventListener("submit", async (e) => {
      e.preventDefault();
      const data = Object.fromEntries(new FormData(form).entries());
      const payload = {
        max_products: Number(data.max_products || 0),
        chunk_size: Number(data.chunk_size || 0),
        timebox_seconds: Number(data.timebox_seconds || 0),
        min_margin_percent: Number(data.min_margin_percent || 0),
        min_margin_abs: Number(data.min_margin_abs || 0),
        dry_run: form.querySelector('input[name=\"dry_run\"]').checked,
      };
      const resEl = document.getElementById("aurora-repricer-result");
      resEl.textContent = "Running…";
      try {
        const res = await fetch(`${restBase}repricer/run`, {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "X-WP-Nonce": nonce,
          },
          body: JSON.stringify(payload),
        });
        const json = await res.json();
        if (!res.ok) {
          resEl.textContent = json?.message || `Error ${res.status}`;
        } else {
          resEl.textContent = `Scheduled run_id=${json.run_id || json.run_id === 0 ? json.run_id : "n/a"}`;
          state.lastRunStatus = "requested";
          schedulePoll(true);
          fetchStatus();
        }
      } catch (err) {
        resEl.textContent = err?.message || "Request failed";
      }
    });
  };

  const bindAssignments = () => {
    const previewPanel = document.getElementById("aurora-preview-panel");
    const runAllDry = document.querySelector(".run-all-dry");
    const runAllApply = document.querySelector(".run-all-apply");
    const resultEl = document.getElementById("aurora-runall-result");
    const triggerRunAll = async (mode) => {
      resultEl.textContent = "Scheduling…";
      try {
        const res = await fetch(`${restBase}repricer/run-all`, {
          method: "POST",
          headers: { "Content-Type": "application/json", "X-WP-Nonce": nonce },
          body: JSON.stringify({ mode }),
        });
        const json = await res.json();
        resultEl.textContent = res.ok ? `enqueued=${json.enqueued ?? 0} skipped=${json.skipped ?? 0}` : (json?.message || `Error ${res.status}`);
        schedulePoll(true);
      } catch (e) {
        resultEl.textContent = e.message || "Request failed";
      }
    };
    if (runAllDry) runAllDry.onclick = () => triggerRunAll("dry_run");
    if (runAllApply) runAllApply.onclick = () => {
      if (!confirm("Run all assignments in APPLY mode?")) return;
      triggerRunAll("apply");
    };

    document.querySelectorAll(".preview-btn").forEach((btn) => {
      btn.addEventListener("click", async (e) => {
        const id = e.currentTarget.getAttribute("data-id");
        previewPanel.textContent = "Preview loading…";
        try {
          const res = await fetch(`${restBase}repricer/preview`, {
            method: "POST",
            headers: { "Content-Type": "application/json", "X-WP-Nonce": nonce },
            body: JSON.stringify({ assignment_id: Number(id), limit: 20 }),
          });
          const json = await res.json();
          if (!res.ok) {
            previewPanel.textContent = json?.message || `Error ${res.status}`;
            return;
          }
          previewPanel.textContent = `Preview assignment ${id}: ${json.selected_count} ids -> ${json.product_ids?.join(", ") || "n/a"}`;
        } catch (err) {
          previewPanel.textContent = err?.message || "Preview failed";
        }
      });
    });

    document.querySelectorAll(".dryrun-btn").forEach((btn) => {
      btn.addEventListener("click", async (e) => {
        const id = e.currentTarget.getAttribute("data-id");
        previewPanel.textContent = `Scheduling dry-run for assignment ${id}…`;
        try {
          const res = await fetch(`${restBase}repricer/run`, {
            method: "POST",
            headers: { "Content-Type": "application/json", "X-WP-Nonce": nonce },
            body: JSON.stringify({ assignment_id: Number(id), dry_run: true }),
          });
          const json = await res.json();
          previewPanel.textContent = res.ok ? `Scheduled run_id=${json.run_id}` : (json?.message || `Error ${res.status}`);
          schedulePoll(true);
        } catch (err) {
          previewPanel.textContent = err?.message || "Run failed";
        }
      });
    });
  };

  const bindScheduler = () => {
    const btn = document.getElementById("aurora-tick-now");
    const resEl = document.getElementById("aurora-tick-result");
    if (!btn) return;
    btn.addEventListener("click", async () => {
      btn.disabled = true;
      resEl.textContent = "Running tick…";
      try {
        const res = await fetch(`${restBase}repricer/scheduler/tick`, {
          method: "POST",
          headers: { "Content-Type": "application/json", "X-WP-Nonce": nonce },
        });
        const json = await res.json();
        if (!res.ok) {
          resEl.textContent = json?.message || `Error ${res.status}`;
        } else {
          resEl.textContent = `enqueued=${json.enqueued ?? 0} skipped=${json.skipped ?? 0} oow=${json.skipped_out_of_window ?? 0}`;
          fetchStatus();
        }
      } catch (e) {
        resEl.textContent = e.message || "Tick failed";
      } finally {
        btn.disabled = false;
      }
    });
  };

  const bindRollback = (repr) => {
    const form = document.getElementById("aurora-rollback-form");
    const lastBtn = document.getElementById("aurora-rollback-last");
    const result = document.getElementById("aurora-rollback-result");
    const lastApply = repr?.last_apply_run?.run_id || null;

    const callRollback = async (runId, dry) => {
      result.textContent = "Scheduling rollback…";
      try {
        const res = await fetch(`${restBase}repricer/rollback`, {
          method: "POST",
          headers: { "Content-Type": "application/json", "X-WP-Nonce": nonce },
          body: JSON.stringify({ run_id: runId, dry_run: dry }),
        });
        const json = await res.json();
        if (!res.ok) {
          result.textContent = json?.message || `Error ${res.status}`;
        } else {
          result.textContent = `Scheduled rollback run_id=${json.run_id || "n/a"}`;
          schedulePoll(true);
        }
      } catch (e) {
        result.textContent = e.message || "Request failed";
      }
    };

    if (form) {
      form.addEventListener("submit", (e) => {
        e.preventDefault();
        const data = Object.fromEntries(new FormData(form).entries());
        const runId = Number(data.rollback_run_id || 0);
        if (!runId) {
          result.textContent = "run_id required";
          return;
        }
        const dry = form.querySelector('input[name="rollback_dry"]').checked;
        callRollback(runId, dry);
      });
    }
    if (lastBtn) {
      lastBtn.onclick = () => {
        if (!lastApply) {
          result.textContent = "No apply run available";
          return;
        }
        const dry = form?.querySelector('input[name="rollback_dry"]').checked || false;
        callRollback(lastApply, dry);
      };
    }
  };

  const fetchStatus = async () => {
    try {
      const res = await fetch(`${restBase}system-status`, {
        headers: { "X-WP-Nonce": nonce },
      });
      const json = await res.json();
      render(json);
    } catch (e) {
      root.innerHTML = `<div class="aurora-card"><h3>Errore</h3><div class="aurora-muted">${e.message}</div></div>`;
      clearInterval(timer);
    }
  };

  const schedulePoll = (immediate = false) => {
    if (timer) clearInterval(timer);
    timer = setInterval(() => {
      if (state.lastRunStatus && ["requested", "running", "partial"].includes(state.lastRunStatus)) {
        fetchStatus();
      } else {
        clearInterval(timer);
      }
    }, pollMs || 3000);
    if (immediate) {
      fetchStatus();
    }
  };

  fetchStatus();
  schedulePoll();
})();
