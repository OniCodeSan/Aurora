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
    `;
    root.innerHTML = html;
    bindForm();
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
