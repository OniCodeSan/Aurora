(function () {
  const root = document.getElementById("aurora-repricer-root");
  const noticesRoot = document.getElementById("aurora-repricer-notices");
  const healthBadge = document.getElementById("aurora-repricer-health-badge");
  const lastUpdate = document.getElementById("aurora-repricer-last-update");
  const refreshBtn = document.getElementById("aurora-repricer-refresh-now");
  const overlay = document.getElementById("aurora-repricer-session-overlay");
  const overlayReload = document.getElementById("aurora-repricer-overlay-reload");

  if (!root || !window.auroraRepricerUi) {
    return;
  }

  const cfg = window.auroraRepricerUi;
  const restConfig = (() => {
    const raw = String(cfg.restUrl || "");
    try {
      const parsed = new URL(raw, window.location.href);
      const restRoute = parsed.searchParams.get("rest_route");
      if (restRoute !== null) {
        return {
          mode: "query",
          endpointPath: parsed.pathname || "/",
          baseRoute: String(restRoute || "/aurora/v1/").replace(/\/?$/, "/"),
        };
      }
      return {
        mode: "path",
        basePath: String(parsed.pathname || "/wp-json/").replace(/\/?$/, "/"),
      };
    } catch (e) {
      const hasQueryRoute = raw.includes("rest_route=");
      if (hasQueryRoute) {
        const parts = raw.replace(/^https?:\/\/[^/]+/i, "").split("?");
        const endpointPath = parts[0] || "/";
        const params = new URLSearchParams(parts[1] || "");
        return {
          mode: "query",
          endpointPath,
          baseRoute: String(params.get("rest_route") || "/aurora/v1/").replace(/\/?$/, "/"),
        };
      }
      return {
        mode: "path",
        basePath: raw.replace(/^https?:\/\/[^/]+/i, "").replace(/\/?$/, "/"),
      };
    }
  })();

  const buildRestUrl = (path) => {
    const cleanPath = String(path || "").replace(/^\/+/, "");
    if (restConfig.mode === "query") {
      const q = new URLSearchParams();
      q.set("rest_route", `${restConfig.baseRoute}${cleanPath}`);
      return `${restConfig.endpointPath}?${q.toString()}`;
    }
    return `${restConfig.basePath}${cleanPath}`;
  };
  const nonce = String(cfg.nonce || "");
  const routes = cfg.routes || {};
  const basePollMs = Number(cfg.pollMs || 5000);
  const maxPollMs = Number(cfg.maxPollMs || 15000);
  const defaultRuleDraft = () => ({
    rule_meta: { name: "", priority: 100, enabled: true, exclusive: false },
    scope: {
      product_ids: [],
      brand_ids: [],
      brand_terms: [],
      category_ids: [],
      supplier_ids: [],
      product_type: [],
      line: [],
      erp_stock_condition: "any",
      urgent_only: false,
    },
    conditions: {
      cost_min: null,
      cost_max: null,
      competitor_position_min: null,
      competitor_position_max: null,
      min_reviews: null,
      rotation_index: null,
      sold_last_30_days: null,
      top_search_only: false,
    },
    pricing_strategy: {
      type: "manual",
      markup_percent: null,
      markup_abs: null,
      margin_target_percent: null,
      manual_mode: "keep",
      manual_price: null,
      competitor_mode: "match",
      competitor_delta: null,
    },
    guardrails: {
      min_price: null,
      max_price: null,
      min_margin_percent: null,
      min_margin_abs: null,
      max_raise_percent: null,
      max_drop_percent: null,
      rounding: "none",
      step_value: null,
      margin_mode: "clamp",
    },
    inventory_rules: {
      max_qty_per_order: null,
      apply_if_stock_gt: null,
    },
    validity: {
      start_at: null,
      end_at: null,
    },
  });
  const defaultRuleOptions = () => ({
    categories: [],
    brands: [],
    product_types: [],
    suppliers: [],
    lines: [],
    products: [],
    brand_taxonomy: null,
  });

  const state = {
    data: null,
    uiLocked: false,
    authFailures: 0,
    pollDelay: basePollMs,
    pollTimer: null,
    selectedAssignmentId: 0,
    previews: {},
    filters: {
      productId: "",
      runId: "",
      rule: "",
      applied: "all",
    },
    form: {
      mode: "dry_run",
      max_products: 50,
      chunk_size: 25,
      timebox_seconds: 10,
      min_margin_percent: 0,
      min_margin_abs: 0,
      hard_max_raise_pct: 0,
      hard_max_drop_pct: 0,
    },
    schedulerOnlyAssignmentId: 0,
    rollbackRunIdInput: "",
    rollbackDryRun: false,
    detailsOpen: {
      warnings: true,
      runError: false,
      advanced: false,
      runRaw: false,
      schedulerRaw: false,
      rollbackDanger: false,
    },
    interactionUntil: 0,
    rules: {
      items: [],
      selectedId: 0,
      draft: defaultRuleDraft(),
      preview: null,
      loaded: false,
      options: defaultRuleOptions(),
      optionsLoaded: false,
    },
    lastRenderFingerprint: "",
  };

  const esc = (value) =>
    String(value ?? "").replace(/[&<>\"]/g, (char) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[char]));

  const route = (key) => String(routes[key] || "").replace(/^\/+/, "");
  const routeWithId = (key, id) => route(key).replace("%id%", encodeURIComponent(String(id || 0)));
  const computeFingerprint = (data) =>
    JSON.stringify({
      health: data?.health?.status || "WARN",
      wpCron: !!data?.config?.wp_cron_enabled,
      repricer: data?.repricer || {},
    });

  const showNotice = (type, message) => {
    if (!noticesRoot) return;
    noticesRoot.innerHTML = `<div class="notice notice-${type} is-dismissible aurora-repricer-banner"><p>${esc(message)}</p></div>`;
  };

  const clearNotice = () => {
    if (noticesRoot) noticesRoot.innerHTML = "";
  };

  const setHealthBadge = (status) => {
    if (!healthBadge) return;
    const normalized = String(status || "WARN");
    healthBadge.textContent = normalized;
    healthBadge.className = "aurora-repricer-badge";
    if (normalized === "HEALTHY" || normalized === "OK") {
      healthBadge.classList.add("aurora-repricer-badge--ok");
    } else if (normalized === "ERROR" || normalized === "FAIL") {
      healthBadge.classList.add("aurora-repricer-badge--error");
    } else {
      healthBadge.classList.add("aurora-repricer-badge--warn");
    }
  };

  const markInteraction = (ms) => {
    const extra = Number(ms || 8000);
    state.interactionUntil = Date.now() + Math.max(1000, extra);
  };

  const isInteracting = () => Date.now() < state.interactionUntil;
  const hasFocusedControl = () => {
    const active = document.activeElement;
    return !!(active && root.contains(active));
  };

  const humanizeError = (message) => {
    const text = String(message || "");
    if (!text) {
      return { title: "", help: "", raw: "" };
    }
    if (text.includes("No products selected")) {
      return {
        title: "Nessun prodotto eleggibile per il repricing",
        help: "Verifica scope assignment e usa Preview payload prima di rilanciare.",
        raw: text,
      };
    }
    return {
      title: text.slice(0, 180),
      help: "Apri i dettagli tecnici per informazioni complete.",
      raw: text,
    };
  };

  const normalizeError = (status, payload) => {
    const code = payload?.code || "request_failed";
    const retryAfter = Number(payload?.data?.retry_after || 0);
    let message = payload?.message || `Errore HTTP ${status}`;

    if (status === 401) message = "Sessione non valida: effettua nuovamente il login.";
    if (status === 403) message = "Permessi insufficienti o nonce non valido.";
    if (status === 429) message = "Rate limit attivo: attendi prima di rilanciare.";

    return { status, code, retryAfter, message };
  };

  const lockUi = (reason) => {
    if (state.uiLocked) return;
    state.uiLocked = true;
    window.clearTimeout(state.pollTimer);
    root.classList.add("aurora-repricer-ui-locked");
    if (overlay) {
      overlay.hidden = false;
    }
    showNotice("error", reason || "Sessione scaduta. Ricarica la pagina.");
  };

  const apiFetch = async (path, method, body, options) => {
    if (state.uiLocked) {
      throw { status: 403, message: "Sessione bloccata", retryAfter: 0 };
    }

    const config = options || {};
    const lockOnAuth = !!config.lockOnAuth;

    const doFetch = async (includeNonce) => {
      const headers = { Accept: "application/json" };
      if (includeNonce && nonce) {
        headers["X-WP-Nonce"] = nonce;
      }
      if (body !== undefined) {
        headers["Content-Type"] = "application/json";
      }

      const response = await fetch(buildRestUrl(path), {
        method: method || "GET",
        credentials: "same-origin",
        headers,
        body: body !== undefined ? JSON.stringify(body) : undefined,
      });

      let payload = null;
      try {
        payload = await response.json();
      } catch (e) {
        payload = null;
      }

      return { response, payload };
    };

    let { response, payload } = await doFetch(true);
    if (!response.ok) {
      let err = normalizeError(response.status, payload || {});
      const nonceError = err.status === 403 && (err.code === "rest_cookie_invalid_nonce" || err.code === "aurora_rest_invalid_nonce");

      // If nonce is stale, retry once with cookie auth only before locking the UI.
      if (nonceError) {
        const retry = await doFetch(false);
        if (retry.response.ok) {
          showNotice("warning", "Nonce scaduto: sessione ripristinata in sola cookie-auth.");
          return retry.payload;
        }
        err = normalizeError(retry.response.status, retry.payload || {});
      }

      if (lockOnAuth && (err.status === 401 || err.status === 403)) {
        lockUi(err.message);
      }
      throw err;
    }

    return payload;
  };

  const statusPill = (status) => {
    const clean = String(status || "unknown");
    return `<span class="aurora-repricer-status-pill status-${esc(clean)}">${esc(clean)}</span>`;
  };

  const parseUtc = (value) => {
    if (!value) return null;
    const maybeIso = String(value).replace(" ", "T") + "Z";
    const date = new Date(maybeIso);
    if (Number.isNaN(date.getTime())) return null;
    return date;
  };

  const isPastDue = (scheduler) => {
    const lastTick = parseUtc(scheduler?.last_at);
    if (!lastTick) return false;
    const delta = Date.now() - lastTick.getTime();
    return delta > 15 * 60 * 1000;
  };

  const setLoading = (button, loading) => {
    if (!button) return;
    const original = button.dataset.originalLabel || button.textContent;
    button.dataset.originalLabel = original;
    button.disabled = loading;
    button.textContent = loading ? "Operazione in corso..." : original;

    let spinner = button.nextElementSibling;
    if (!spinner || !spinner.classList.contains("spinner")) {
      spinner = document.createElement("span");
      spinner.className = "spinner";
      button.insertAdjacentElement("afterend", spinner);
    }
    spinner.classList.toggle("is-active", loading);
  };

  const cooldownButton = (button, seconds) => {
    if (!button || seconds <= 0) return;
    const original = button.dataset.originalLabel || button.textContent;
    button.disabled = true;
    let remain = seconds;
    button.textContent = `${original} (${remain}s)`;
    const timer = window.setInterval(() => {
      remain -= 1;
      if (remain <= 0) {
        window.clearInterval(timer);
        button.disabled = false;
        button.textContent = original;
        return;
      }
      button.textContent = `${original} (${remain}s)`;
    }, 1000);
  };

  const runAction = async (button, path, body, onSuccess, method) => {
    try {
      setLoading(button, true);
      const result = await apiFetch(path, method || "POST", body, { lockOnAuth: false });
      if (onSuccess) {
        onSuccess(result);
      }
      clearNotice();
      await refreshStatus();
      return result;
    } catch (err) {
      showNotice(err.status === 401 || err.status === 403 ? "error" : "warning", err.message || "Errore inatteso");
      if (err.status === 429) {
        cooldownButton(button, err.retryAfter || 5);
      }
      if (err.status >= 500) {
        console.error("[Aurora Repricer UI]", { path, body, err });
      }
      throw err;
    } finally {
      setLoading(button, false);
    }
  };

  const buildOverview = (repricer, healthStatus) => {
    const run = repricer.last_run || null;
    const progress = repricer.progress || null;
    const decisions = repricer.decisions || {};
    const scheduler = repricer.scheduler || {};
    const rollback = repricer.last_rollback_run || null;
    const runErr = humanizeError(run?.error || "");
    const errorClass = healthStatus === "ERROR" || healthStatus === "FAIL" ? " is-error" : "";

    return `
      <section class="aurora-repricer-overview${errorClass}" id="aurora-overview">
        <h2>System Overview</h2>
        <div class="aurora-repricer-overview-grid">
          <article class="aurora-repricer-card">
            <h3>Ultimo Run</h3>
            ${run ? `
              <dl class="aurora-repricer-kv">
                <dt>ID</dt><dd>#${esc(run.id)}</dd>
                <dt>Mode</dt><dd>${esc(run.mode || "-")}</dd>
                <dt>Status</dt><dd>${statusPill(run.status)}</dd>
                <dt>Started</dt><dd>${esc(run.started_at || "-")}</dd>
                <dt>Finished</dt><dd>${esc(run.finished_at || "-")}</dd>
              </dl>
              ${runErr.title ? `<p class="aurora-repricer-help"><strong>${esc(runErr.title)}</strong> ${esc(runErr.help)}</p><details id="aurora-run-error-details" ${state.detailsOpen.runError ? "open" : ""}><summary>Dettagli</summary><pre class="aurora-repricer-monospace">${esc(runErr.raw)}</pre></details>` : ""}
            ` : `<p class="aurora-repricer-empty">Nessun run disponibile.</p>`}
          </article>
          <article class="aurora-repricer-card">
            <h3>Scheduler</h3>
            <dl class="aurora-repricer-kv">
              <dt>Mode</dt><dd>${esc(scheduler.mode || "-")}</dd>
              <dt>In-window</dt><dd>${scheduler.in_window === null ? "-" : (scheduler.in_window ? "Sì" : "No")}</dd>
              <dt>Last tick</dt><dd>${esc(scheduler.last_at || "-")}</dd>
              <dt>Enqueued</dt><dd>${esc(scheduler.enqueued_last || 0)}</dd>
              <dt>Past-due</dt><dd>${isPastDue(scheduler) ? "Sì" : "No"}</dd>
            </dl>
          </article>
          <article class="aurora-repricer-card">
            <h3>Decisioni</h3>
            <dl class="aurora-repricer-kv">
              <dt>Total</dt><dd>${esc(decisions.decisions_count || 0)}</dd>
              <dt>Applied</dt><dd>${esc(decisions.applied_count_last_run || 0)}</dd>
              <dt>Top rule</dt><dd>${esc((decisions.breakdown && decisions.breakdown[0] && decisions.breakdown[0].rule_applied) || "-")}</dd>
              <dt>Processed</dt><dd>${esc(progress?.processed_count || 0)}</dd>
            </dl>
          </article>
          <article class="aurora-repricer-card">
            <h3>Rollback</h3>
            <dl class="aurora-repricer-kv">
              <dt>Pending</dt><dd>${esc(repricer.rollback_pending_count_last_apply_run || 0)}</dd>
              <dt>Queued</dt><dd>${esc(repricer.rollback_queue_count || 0)}</dd>
              <dt>Last</dt><dd>${rollback ? `#${esc(rollback.id)} ${statusPill(rollback.status)}` : "-"}</dd>
            </dl>
            <p><a href="#aurora-rollback-zone">Vai a rollback</a></p>
          </article>
        </div>
      </section>
    `;
  };

  const buildGuidedRun = (repricer) => {
    const assignments = Array.isArray(repricer.assignments) ? repricer.assignments : [];
    if (!state.selectedAssignmentId && assignments.length > 0) {
      state.selectedAssignmentId = Number(assignments[0].id);
    }

    const selected = assignments.find((a) => Number(a.id) === Number(state.selectedAssignmentId)) || null;
    const preview = state.previews[String(state.selectedAssignmentId)] || null;
    const previewZero = preview && Number(preview.selected_count || 0) === 0;
    const rulePreviewZero = state.rules.preview && Number(state.rules.preview.resolved_count || 0) === 0;

    const run = repricer.last_run || null;
    const progress = repricer.progress || null;
    const progressStatus = run?.status || "idle";
    const progressValue = progressStatus === "success" ? 100 : (progressStatus === "running" ? 55 : (progressStatus === "requested" ? 15 : (progressStatus === "partial" ? 80 : (progressStatus === "error" ? 100 : 0))));
    const recentPreviewRows = collectDecisionRows(repricer).slice(0, 5);
    const recentPreviewTable = recentPreviewRows.length
      ? `<table class="widefat striped aurora-repricer-preview-table">
          <thead><tr><th>Prodotto</th><th class="num">Prima</th><th class="num">Dopo</th><th>Regola</th></tr></thead>
          <tbody>
            ${recentPreviewRows
              .map(
                (row) => `<tr>
                  <td>#${esc(row.product_id)}</td>
                  <td class="num">${esc(row.old_price ?? "-")}</td>
                  <td class="num">${esc(row.new_price ?? "-")}</td>
                  <td>${esc(row.rule_applied || "-")}</td>
                </tr>`
              )
              .join("")}
          </tbody>
        </table>`
      : '<p class="aurora-repricer-empty">Nessuna decisione recente disponibile per preview prima→dopo.</p>';

    return `
      <section class="aurora-repricer-section" id="aurora-guided-run">
        <h2>Esegui repricing</h2>
        <p class="aurora-repricer-help">Flusso guidato: seleziona assignment, scegli modalità, poi avvia run asincrono.</p>

        <div class="aurora-repricer-step">
          <h3>Step 1 - Seleziona assignment</h3>
          <div class="aurora-repricer-grid-2">
            <div class="aurora-repricer-field">
              <label for="aurora-assignment-select">Assignment</label>
              <select id="aurora-assignment-select">
                ${assignments.map((a) => `<option value="${esc(a.id)}" ${Number(a.id) === Number(state.selectedAssignmentId) ? "selected" : ""}>${esc(a.name || "(senza nome)")} (#${esc(a.id)})</option>`).join("")}
              </select>
            </div>
            <div class="aurora-repricer-actions">
              <button type="button" class="button" id="aurora-preview-payload">Preview payload</button>
            </div>
          </div>
          <div id="aurora-preview-box" class="aurora-repricer-footer">
            ${selected ? `<p class="aurora-repricer-help">Scope type: <strong>${esc(selected.scope_type || "-")}</strong></p>` : ""}
            ${preview ? `<p class="aurora-repricer-help">Resolved: <strong>${esc(preview.selected_count || 0)}</strong> | Product IDs: ${esc((preview.product_ids || []).join(", ") || "-")}</p>` : '<p class="aurora-repricer-empty">Nessuna preview eseguita.</p>'}
            ${previewZero ? '<div class="notice notice-warning inline"><p>Nessun prodotto eleggibile: run disabilitato.</p></div>' : ""}
            ${rulePreviewZero ? '<div class="notice notice-warning inline"><p>Preview scope regola = 0: run disabilitato finché non correggi la regola.</p></div>' : ""}
          </div>
        </div>

        <div class="aurora-repricer-step">
          <h3>Step 2 - Modalità e parametri</h3>
          <div class="aurora-repricer-radio-group">
            <label><input type="radio" name="repricer_mode" value="dry_run" ${state.form.mode === "dry_run" ? "checked" : ""}> Dry-run</label>
            <label><input type="radio" name="repricer_mode" value="apply" ${state.form.mode === "apply" ? "checked" : ""}> Apply</label>
            <span class="aurora-repricer-help">Apply scrive i prezzi WooCommerce.</span>
          </div>
          <details id="aurora-advanced-details" ${state.detailsOpen.advanced ? "open" : ""}>
            <summary>Parametri avanzati</summary>
            <div class="aurora-repricer-grid">
              <div class="aurora-repricer-field"><label for="aurora-max-products">Max products</label><input id="aurora-max-products" type="number" min="1" max="200000" value="${esc(state.form.max_products)}"></div>
              <div class="aurora-repricer-field"><label for="aurora-chunk-size">Chunk size</label><input id="aurora-chunk-size" type="number" min="1" max="5000" value="${esc(state.form.chunk_size)}"></div>
              <div class="aurora-repricer-field"><label for="aurora-timebox">Timebox (s)</label><input id="aurora-timebox" type="number" min="5" max="3600" value="${esc(state.form.timebox_seconds)}"></div>
              <div class="aurora-repricer-field"><label for="aurora-margin-pct">Min margin %</label><input id="aurora-margin-pct" type="number" min="0" max="1000" step="0.1" value="${esc(state.form.min_margin_percent)}"></div>
              <div class="aurora-repricer-field"><label for="aurora-margin-abs">Min margin abs</label><input id="aurora-margin-abs" type="number" min="0" max="1000000" step="0.01" value="${esc(state.form.min_margin_abs)}"></div>
              <div class="aurora-repricer-field"><label for="aurora-hard-max-raise">Hard max increase %</label><input id="aurora-hard-max-raise" type="number" min="0" max="1000" step="0.1" value="${esc(state.form.hard_max_raise_pct)}"></div>
              <div class="aurora-repricer-field"><label for="aurora-hard-max-drop">Hard max decrease %</label><input id="aurora-hard-max-drop" type="number" min="0" max="1000" step="0.1" value="${esc(state.form.hard_max_drop_pct)}"></div>
            </div>
            <p class="aurora-repricer-help">Se impostati, i limiti hard bloccano APPLY quando una variazione supera la soglia.</p>
          </details>
        </div>

        <div class="aurora-repricer-step">
          <h3>Step 3 - Azione</h3>
          <div class="aurora-repricer-actions">
            <button type="button" class="button button-primary" id="aurora-run-repricer" ${previewZero || rulePreviewZero ? "disabled" : ""}>Run Repricer ${state.form.mode === "apply" ? "(apply)" : "(dry-run)"}</button>
          </div>
          <div class="aurora-repricer-card" style="margin-top:12px;">
            <h3>Stato run</h3>
            <dl class="aurora-repricer-kv">
              <dt>Run ID</dt><dd>${esc(run?.id || "-")}</dd>
              <dt>Stato</dt><dd>${statusPill(progressStatus)}</dd>
              <dt>Processed</dt><dd>${esc(progress?.processed_count || 0)}</dd>
              <dt>Updated</dt><dd>${esc(progress?.updated_count || 0)}</dd>
              <dt>Last product</dt><dd>${esc(progress?.last_product_id || "-")}</dd>
            </dl>
            <progress max="100" value="${esc(progressValue)}" style="width:100%; height:10px;"></progress>
            <p class="aurora-repricer-help">Link rapido: <a href="#aurora-decisioni">Vedi decisioni</a></p>
            <h4 style="margin:12px 0 8px;">Preview prima→dopo (ultime decisioni)</h4>
            ${recentPreviewTable}
          </div>
        </div>
      </section>
    `;
  };

  const buildRunDetails = (repricer) => {
    const run = repricer.last_run || null;
    if (!run) {
      return `
        <section class="aurora-repricer-section" id="aurora-run-details"><h2>Run details</h2><p class="aurora-repricer-empty">Nessun run disponibile.</p></section>
      `;
    }
    const progress = repricer.progress || null;
    const mapped = humanizeError(run.error || run.message || "");
    return `
      <section class="aurora-repricer-section" id="aurora-run-details">
        <h2>Run details</h2>
        <div class="aurora-repricer-grid-2">
          <div class="aurora-repricer-card">
            <dl class="aurora-repricer-kv">
              <dt>Status</dt><dd>${statusPill(run.status)}</dd>
              <dt>Mode</dt><dd>${esc(run.mode || "-")}</dd>
              <dt>Requested</dt><dd>${esc(run.requested_at || "-")}</dd>
              <dt>Started</dt><dd>${esc(run.started_at || "-")}</dd>
              <dt>Finished</dt><dd>${esc(run.finished_at || "-")}</dd>
              <dt>Processed</dt><dd>${esc(progress?.processed_count || 0)}</dd>
              <dt>Updated</dt><dd>${esc(progress?.updated_count || 0)}</dd>
            </dl>
            ${mapped.title ? `<p class="aurora-repricer-help"><strong>${esc(mapped.title)}</strong> ${esc(mapped.help)}</p>` : ""}
          </div>
          <div class="aurora-repricer-card">
            <h3>Dettagli tecnici</h3>
            <details id="aurora-run-raw-details" ${state.detailsOpen.runRaw ? "open" : ""}>
              <summary>Mostra messaggio raw</summary>
              <pre class="aurora-repricer-monospace">${esc(run.error || run.message || "n/a")}</pre>
            </details>
            <p class="aurora-repricer-help"><a href="#aurora-decisioni">Vedi decisioni</a></p>
          </div>
        </div>
      </section>
    `;
  };

  const collectDecisionRows = (repricer) => {
    const runId = Number(repricer.last_run?.id || 0);
    const rows = Array.isArray(repricer.recent_decisions) ? repricer.recent_decisions : [];
    return rows.map((row) => {
      return {
        run_id: Number(row.run_id || runId),
        product_id: row.product_id,
        old_price: row.old_price,
        candidate_price: row.candidate_price,
        clamped_price: row.clamped_price,
        rounded_price: row.rounded_price,
        new_price: row.new_price,
        delta_pct: row.delta_pct,
        margin_before: row.margin_before,
        margin_after: row.margin_after,
        rule_applied: row.rule_applied || "",
        strategy_key: row.strategy_key || "",
        reason_code: row.reason_code || "",
        created_at: row.created_at || "",
        applied: Number(row.applied || 0) === 1 ? "si" : "no",
      };
    });
  };

  const buildDecisionSection = (repricer) => {
    const rows = collectDecisionRows(repricer).filter((row) => {
      if (state.filters.productId && String(row.product_id) !== state.filters.productId) return false;
      if (state.filters.runId && String(row.run_id) !== state.filters.runId) return false;
      if (state.filters.rule && String(row.rule_applied) !== state.filters.rule) return false;
      if (state.filters.applied !== "all" && row.applied !== state.filters.applied) return false;
      return true;
    });

    const rules = [...new Set(collectDecisionRows(repricer).map((r) => r.rule_applied).filter(Boolean))];

    const tableRows = rows.map((row) => {
      const productUrl = `post.php?post=${encodeURIComponent(row.product_id)}&action=edit`;
      return `<tr>
        <td><a href="${productUrl}">#${esc(row.product_id)}</a></td>
        <td class="num">${esc(row.old_price ?? "-")}</td>
        <td class="num">${esc(row.candidate_price ?? "-")}</td>
        <td class="num">${esc(row.clamped_price ?? "-")}</td>
        <td class="num">${esc(row.rounded_price ?? "-")}</td>
        <td class="num">${esc(row.new_price ?? "-")}</td>
        <td class="num">${esc(row.delta_pct ?? "-")}</td>
        <td class="num">${esc(row.margin_before ?? "-")}</td>
        <td class="num">${esc(row.margin_after ?? "-")}</td>
        <td>${esc(row.rule_applied || "-")}</td>
        <td>${esc(row.reason_code || "-")}</td>
        <td>${esc(row.created_at || "-")}</td>
        <td>${row.applied === "si" ? "Sì" : "No"}</td>
      </tr>`;
    }).join("");

    return `
      <section class="aurora-repricer-section" id="aurora-decisioni">
        <h2>Decisioni</h2>
        <div class="aurora-repricer-grid">
          <div class="aurora-repricer-field"><label for="filter-product-id">Product ID</label><input id="filter-product-id" type="text" value="${esc(state.filters.productId)}"></div>
          <div class="aurora-repricer-field"><label for="filter-run-id">Run ID</label><input id="filter-run-id" type="text" value="${esc(state.filters.runId)}"></div>
          <div class="aurora-repricer-field"><label for="filter-rule">Regola</label><select id="filter-rule"><option value="">Tutte</option>${rules.map((r) => `<option value="${esc(r)}" ${state.filters.rule === r ? "selected" : ""}>${esc(r)}</option>`).join("")}</select></div>
          <div class="aurora-repricer-field"><label for="filter-applied">Applicato</label><select id="filter-applied"><option value="all" ${state.filters.applied === "all" ? "selected" : ""}>Tutti</option><option value="si" ${state.filters.applied === "si" ? "selected" : ""}>Sì</option><option value="no" ${state.filters.applied === "no" ? "selected" : ""}>No</option></select></div>
        </div>
        <table class="widefat striped aurora-repricer-table">
          <thead><tr><th>Prodotto</th><th class="num">Old</th><th class="num">Candidate</th><th class="num">Clamped</th><th class="num">Rounded</th><th class="num">New</th><th class="num">Delta %</th><th class="num">Margin B</th><th class="num">Margin A</th><th>Regola</th><th>Reason</th><th>Creato</th><th>Applicato</th></tr></thead>
          <tbody>${tableRows || '<tr><td colspan="13" class="aurora-repricer-empty">Nessuna decisione trovata con i filtri correnti.</td></tr>'}</tbody>
        </table>
      </section>
    `;
  };

  const buildSchedulerSection = (repricer) => {
    const scheduler = repricer.scheduler || {};
    const mapped = humanizeError(scheduler.last_error || "");
    return `
      <section class="aurora-repricer-section" id="aurora-scheduler">
        <h2>Scheduler</h2>
        <div class="aurora-repricer-grid-2">
          <div class="aurora-repricer-card">
            <dl class="aurora-repricer-kv">
              <dt>Mode</dt><dd>${esc(scheduler.mode || "-")}</dd>
              <dt>In-window</dt><dd>${scheduler.in_window === null ? "-" : (scheduler.in_window ? "Sì" : "No")}</dd>
              <dt>Cursor</dt><dd>${esc(scheduler.cursor ?? 0)}</dd>
              <dt>Last at</dt><dd>${esc(scheduler.last_at || "-")}</dd>
              <dt>Enqueued</dt><dd>${esc(scheduler.enqueued_last || 0)}</dd>
              <dt>Skipped</dt><dd>${esc(scheduler.skipped_last || 0)}</dd>
            </dl>
            ${mapped.title ? `<p class="aurora-repricer-help"><strong>${esc(mapped.title)}</strong></p><details id="aurora-scheduler-raw-details" ${state.detailsOpen.schedulerRaw ? "open" : ""}><summary>Dettagli tecnici</summary><pre class="aurora-repricer-monospace">${esc(mapped.raw)}</pre></details>` : ""}
          </div>
          <div class="aurora-repricer-card">
            <div class="aurora-repricer-field">
              <label for="scheduler-assignment-id">Assignment ID (opzionale)</label>
              <input id="scheduler-assignment-id" type="number" min="0" value="${esc(state.schedulerOnlyAssignmentId)}">
            </div>
            <p class="aurora-repricer-help">Esegue tick asincrono secondo finestre configurate.</p>
            <div class="aurora-repricer-actions">
              <button type="button" class="button button-secondary" id="aurora-run-tick">Esegui tick scheduler</button>
            </div>
          </div>
        </div>
      </section>
    `;
  };

  const buildAssignmentsSection = (repricer) => {
    const assignments = Array.isArray(repricer.assignments) ? repricer.assignments : [];
    const rows = assignments.map((a) => {
      const lr = a.last_run || {};
      return `<tr>
        <td>${esc(a.id)}</td>
        <td>${esc(a.name || "-")}</td>
        <td class="num">${esc(a.priority ?? "-")}</td>
        <td>${esc(a.scope_type || "-")}</td>
        <td>${lr.id ? `#${esc(lr.id)}` : "-"}</td>
        <td>${lr.status ? statusPill(lr.status) : "-"}</td>
        <td><div class="aurora-repricer-table-actions">
          <button type="button" class="button button-small" data-assignment-action="preview" data-assignment-id="${esc(a.id)}">Preview</button>
          <button type="button" class="button button-small" data-assignment-action="dry" data-assignment-id="${esc(a.id)}">Dry-run</button>
          <button type="button" class="button button-small" data-assignment-action="apply" data-assignment-id="${esc(a.id)}">Apply</button>
        </div></td>
      </tr>`;
    }).join("");

    return `
      <section class="aurora-repricer-section" id="aurora-assignments">
        <h2>Assignment</h2>
        <div class="aurora-repricer-actions" style="margin-bottom:8px;">
          <span class="aurora-repricer-help">Enabled: ${esc(repricer.enabled_assignments_count || 0)} | Queued runs: ${esc(repricer.queued_runs_count || 0)} | Rollback queued: ${esc(repricer.rollback_queue_count || 0)}</span>
          <button type="button" class="button" id="aurora-runall-dry">Run all (dry-run)</button>
          <button type="button" class="button button-primary" id="aurora-runall-apply">Run all (apply)</button>
        </div>
        <table class="widefat striped aurora-repricer-table">
          <thead><tr><th>ID</th><th>Nome</th><th class="num">Priorità</th><th>Scope</th><th>Last run</th><th>Stato</th><th>Azioni</th></tr></thead>
          <tbody>${rows || '<tr><td colspan="7" class="aurora-repricer-empty">Nessun assignment disponibile.</td></tr>'}</tbody>
        </table>
        <div id="aurora-assignment-feedback" class="aurora-repricer-help"></div>
      </section>
    `;
  };

  const buildRollbackSection = (repricer) => {
    const lastRollback = repricer.last_rollback_run || null;
    const lastApply = repricer.last_apply_run || null;
    return `
      <section class="aurora-repricer-section" id="aurora-rollback-zone">
        <h2>Rollback</h2>
        <details id="aurora-rollback-danger-details" class="aurora-repricer-danger" ${state.detailsOpen.rollbackDanger ? "open" : ""}>
          <summary>Danger zone - Operazione distruttiva</summary>
          <div class="aurora-repricer-grid-2" style="margin-top:12px;">
            <div class="aurora-repricer-field">
              <label for="rollback-run-id">Apply run_id</label>
              <input id="rollback-run-id" type="number" min="1" value="${esc(state.rollbackRunIdInput)}">
            </div>
            <div class="aurora-repricer-field">
              <label for="rollback-dry-run"><input id="rollback-dry-run" type="checkbox" ${state.rollbackDryRun ? "checked" : ""}> Dry-run rollback</label>
            </div>
          </div>
          <div class="aurora-repricer-actions">
            <button type="button" class="button" id="aurora-rollback-runid">Rollback run_id</button>
            <button type="button" class="button button-secondary" id="aurora-rollback-last" ${lastApply ? "" : "disabled title=\"Nessun apply run trovato\""}>Rollback ultimo apply</button>
          </div>
          <p class="aurora-repricer-help">Ultimo rollback: ${lastRollback ? `#${esc(lastRollback.id)} ${statusPill(lastRollback.status)} ${esc(lastRollback.finished_at || lastRollback.requested_at || "-")}` : "n/a"}</p>
        </details>
      </section>
    `;
  };

  const csvToIntArray = (value) =>
    String(value || "")
      .split(",")
      .map((item) => Number(item.trim()))
      .filter((num) => Number.isFinite(num) && num > 0)
      .map((num) => Math.trunc(num));

  const csvToTextArray = (value) =>
    String(value || "")
      .split(",")
      .map((item) => item.trim())
      .filter((item) => item.length > 0);

  const parseProductIdsCsv = (value) => csvToIntArray(value);

  const addProductIdToCsv = (value, productId) => {
    const ids = parseProductIdsCsv(value);
    const id = Number(productId || 0);
    if (id > 0 && !ids.includes(id)) {
      ids.push(id);
    }
    return ids.join(",");
  };

  const collectMultiValues = (selectNode) => {
    if (!selectNode || !selectNode.options) {
      return [];
    }
    const values = [];
    Array.from(selectNode.options).forEach((opt) => {
      if (opt.selected) {
        values.push(String(opt.value));
      }
    });
    return values;
  };

  const datetimeInputValue = (value) => {
    if (!value) return "";
    const text = String(value).replace(" ", "T");
    return text.length >= 16 ? text.slice(0, 16) : text;
  };

  const datetimeApiValue = (value) => {
    const clean = String(value || "").trim();
    if (!clean) return null;
    return clean.replace("T", " ");
  };

  const getRuleField = (path) => {
    if (!path) return undefined;
    return path.split(".").reduce((acc, key) => (acc && acc[key] !== undefined ? acc[key] : undefined), state.rules.draft);
  };

  const setRuleField = (path, value) => {
    if (!path) return;
    const segments = path.split(".");
    let cursor = state.rules.draft;
    for (let i = 0; i < segments.length - 1; i += 1) {
      const key = segments[i];
      if (!cursor[key] || typeof cursor[key] !== "object") {
        cursor[key] = {};
      }
      cursor = cursor[key];
    }
    cursor[segments[segments.length - 1]] = value;
  };

  const normalizeRuleFromApi = (row) => {
    const json = row && row.rule_json && typeof row.rule_json === "object" ? row.rule_json : null;
    if (!json) return defaultRuleDraft();
    const merged = defaultRuleDraft();
    const merge = (target, source) => {
      Object.keys(source || {}).forEach((key) => {
        if (source[key] && typeof source[key] === "object" && !Array.isArray(source[key]) && target[key] && typeof target[key] === "object") {
          merge(target[key], source[key]);
        } else {
          target[key] = source[key];
        }
      });
    };
    merge(merged, json);
    return merged;
  };

  const buildRuleEditorSection = () => {
    const rules = Array.isArray(state.rules.items) ? state.rules.items : [];
    const draft = state.rules.draft || defaultRuleDraft();
    const scope = draft.scope || {};
    const ruleOptions = state.rules.options || defaultRuleOptions();
    const selectedId = Number(state.rules.selectedId || 0);
    const preview = state.rules.preview;
    const previewText =
      preview && typeof preview === "object"
        ? `Resolved: ${preview.resolved_count || 0} | Sample IDs: ${(preview.sample_ids || []).join(", ") || "-"}`
        : "Nessuna preview scope.";

    const num = (path) => {
      const value = getRuleField(path);
      return value === null || value === undefined ? "" : String(value);
    };

    const selectedIntSet = (items) => new Set((Array.isArray(items) ? items : []).map((v) => String(Number(v || 0))).filter((v) => v !== "0"));
    const selectedTextSet = (items) => new Set((Array.isArray(items) ? items : []).map((v) => String(v)));
    const selectedCategories = selectedIntSet(scope.category_ids);
    const selectedBrands = selectedIntSet(scope.brand_ids);
    const selectedSuppliers = selectedTextSet(scope.supplier_ids);
    const selectedProductTypes = selectedTextSet(scope.product_type);
    const selectedLines = selectedTextSet(scope.line);
    const productIdsCsv = (Array.isArray(scope.product_ids) ? scope.product_ids : []).join(",");

    const renderIdOptions = (items, selectedSet) =>
      (Array.isArray(items) ? items : [])
        .map((item) => {
          const id = Number(item?.id || 0);
          if (id <= 0) return "";
          const name = String(item?.name || item?.label || `#${id}`);
          const selected = selectedSet.has(String(id)) ? "selected" : "";
          return `<option value="${esc(id)}" ${selected}>${esc(name)} (#${esc(id)})</option>`;
        })
        .join("");

    const renderTextOptions = (items, selectedSet) =>
      (Array.isArray(items) ? items : [])
        .map((item) => {
          const value = String(item?.value || "");
          if (!value) return "";
          const label = String(item?.label || value);
          const selected = selectedSet.has(value) ? "selected" : "";
          return `<option value="${esc(value)}" ${selected}>${esc(label)}</option>`;
        })
        .join("");

    return `
      <section class="aurora-repricer-section" id="aurora-rule-editor">
        <h2>Rule editor</h2>
        <p class="aurora-repricer-help">Gestione regole prezzo deterministiche: scope, condizioni, strategia e guardrail.</p>
        <div class="aurora-repricer-grid-2">
          <div class="aurora-repricer-field">
            <label for="aurora-rule-select">Regola salvata</label>
            <select id="aurora-rule-select">
              <option value="0">Nuova regola</option>
              ${rules
                .map(
                  (rule) =>
                    `<option value="${esc(rule.id)}" ${Number(rule.id) === selectedId ? "selected" : ""}>#${esc(rule.id)} ${esc(
                      rule.name || "(senza nome)"
                    )}</option>`
                )
                .join("")}
            </select>
          </div>
          <div class="aurora-repricer-actions">
            <button type="button" class="button" id="aurora-rule-new">Nuova</button>
            <button type="button" class="button button-primary" id="aurora-rule-save">Salva regola</button>
            <button type="button" class="button" id="aurora-rule-preview" ${selectedId > 0 ? "" : "disabled"}>Preview scope</button>
          </div>
        </div>

        <h3>Meta</h3>
        <div class="aurora-repricer-grid">
          <div class="aurora-repricer-field"><label>Nome</label><input type="text" data-rule-field="rule_meta.name" value="${esc(
            getRuleField("rule_meta.name") || ""
          )}"></div>
          <div class="aurora-repricer-field"><label>Priority</label><input type="number" min="0" max="1000000" data-rule-field="rule_meta.priority" data-rule-type="int" value="${esc(
            num("rule_meta.priority")
          )}"></div>
          <div class="aurora-repricer-field"><label><input type="checkbox" data-rule-field="rule_meta.enabled" data-rule-type="bool" ${
            getRuleField("rule_meta.enabled") ? "checked" : ""
          }> Enabled</label></div>
          <div class="aurora-repricer-field"><label><input type="checkbox" data-rule-field="rule_meta.exclusive" data-rule-type="bool" ${
            getRuleField("rule_meta.exclusive") ? "checked" : ""
          }> Escludi altre regole</label></div>
        </div>

        <h3>Scope</h3>
        <div class="aurora-repricer-grid">
          <div class="aurora-repricer-field">
            <label>Product IDs (separati da virgola)</label>
            <input id="aurora-rule-product-ids" type="text" data-rule-field="scope.product_ids" data-rule-type="csv-int" value="${esc(productIdsCsv)}" placeholder="es. 107221,207222">
            <p class="aurora-repricer-help">Puoi aggiungere piu ID manualmente oppure dal menu prodotto.</p>
          </div>
          <div class="aurora-repricer-field">
            <label for="aurora-rule-product-pick">Aggiungi prodotto dal sistema</label>
            <div class="aurora-repricer-actions">
              <select id="aurora-rule-product-pick">
                <option value="">Seleziona prodotto...</option>
                ${renderTextOptions(
                  (ruleOptions.products || []).map((row) => ({ value: String(row.id || ""), label: row.label || "" })),
                  new Set()
                )}
              </select>
              <button type="button" class="button button-small" id="aurora-rule-product-add">Aggiungi ID</button>
            </div>
          </div>
          <div class="aurora-repricer-field">
            <label>Categorie prodotto</label>
            <select multiple size="6" data-rule-field="scope.category_ids" data-rule-type="multi-int">
              ${renderIdOptions(ruleOptions.categories, selectedCategories)}
            </select>
            <p class="aurora-repricer-help">Usa Cmd/Ctrl per selezione multipla.</p>
          </div>
          <div class="aurora-repricer-field">
            <label>Brand</label>
            <select multiple size="6" data-rule-field="scope.brand_ids" data-rule-type="multi-int">
              ${renderIdOptions(ruleOptions.brands, selectedBrands)}
            </select>
          </div>
          <div class="aurora-repricer-field">
            <label>Supplier IDs</label>
            <select multiple size="6" data-rule-field="scope.supplier_ids" data-rule-type="multi-text">
              ${renderTextOptions(ruleOptions.suppliers, selectedSuppliers)}
            </select>
          </div>
          <div class="aurora-repricer-field">
            <label>Product type</label>
            <select multiple size="6" data-rule-field="scope.product_type" data-rule-type="multi-text">
              ${renderTextOptions(ruleOptions.product_types, selectedProductTypes)}
            </select>
          </div>
          <div class="aurora-repricer-field">
            <label>Linea (meta ERP _aurora_line)</label>
            <select multiple size="6" data-rule-field="scope.line" data-rule-type="multi-text">
              ${renderTextOptions(ruleOptions.lines, selectedLines)}
            </select>
            <p class="aurora-repricer-help">Linea identifica il segmento ERP del prodotto.</p>
          </div>
          <div class="aurora-repricer-field"><label>ERP stock</label><select data-rule-field="scope.erp_stock_condition"><option value="any" ${
            getRuleField("scope.erp_stock_condition") === "any" ? "selected" : ""
          }>any</option><option value="eq_0" ${
      getRuleField("scope.erp_stock_condition") === "eq_0" ? "selected" : ""
    }>eq_0</option><option value="gt_0" ${
      getRuleField("scope.erp_stock_condition") === "gt_0" ? "selected" : ""
    }>gt_0</option></select></div>
          <div class="aurora-repricer-field"><label><input type="checkbox" data-rule-field="scope.urgent_only" data-rule-type="bool" ${
            getRuleField("scope.urgent_only") ? "checked" : ""
          }> Urgent only</label></div>
        </div>

        <h3>Conditions</h3>
        <div class="aurora-repricer-grid">
          <div class="aurora-repricer-field"><label>Cost min</label><input type="number" step="0.01" data-rule-field="conditions.cost_min" data-rule-type="float-null" value="${esc(
            num("conditions.cost_min")
          )}"></div>
          <div class="aurora-repricer-field"><label>Cost max</label><input type="number" step="0.01" data-rule-field="conditions.cost_max" data-rule-type="float-null" value="${esc(
            num("conditions.cost_max")
          )}"></div>
          <div class="aurora-repricer-field"><label>Competitor pos min</label><input type="number" data-rule-field="conditions.competitor_position_min" data-rule-type="int-null" value="${esc(
            num("conditions.competitor_position_min")
          )}"></div>
          <div class="aurora-repricer-field"><label>Competitor pos max</label><input type="number" data-rule-field="conditions.competitor_position_max" data-rule-type="int-null" value="${esc(
            num("conditions.competitor_position_max")
          )}"></div>
          <div class="aurora-repricer-field"><label>Min reviews</label><input type="number" data-rule-field="conditions.min_reviews" data-rule-type="int-null" value="${esc(
            num("conditions.min_reviews")
          )}"></div>
          <div class="aurora-repricer-field"><label>Rotation op</label><select data-rule-field="conditions.rotation_index.operator"><option value="">-</option><option value=">" ${
            getRuleField("conditions.rotation_index.operator") === ">" ? "selected" : ""
          }>&gt;</option><option value=">=" ${
      getRuleField("conditions.rotation_index.operator") === ">=" ? "selected" : ""
    }>&gt;=</option><option value="<" ${
      getRuleField("conditions.rotation_index.operator") === "<" ? "selected" : ""
    }>&lt;</option><option value="<=" ${
      getRuleField("conditions.rotation_index.operator") === "<=" ? "selected" : ""
    }>&lt;=</option><option value="=" ${
      getRuleField("conditions.rotation_index.operator") === "=" ? "selected" : ""
    }>=</option><option value="!=" ${
      getRuleField("conditions.rotation_index.operator") === "!=" ? "selected" : ""
    }>!=</option></select></div>
          <div class="aurora-repricer-field"><label>Rotation value</label><input type="number" step="0.01" data-rule-field="conditions.rotation_index.value" data-rule-type="float-null" value="${esc(
            num("conditions.rotation_index.value")
          )}"></div>
          <div class="aurora-repricer-field"><label>Sold 30d op</label><select data-rule-field="conditions.sold_last_30_days.operator"><option value="">-</option><option value=">" ${
            getRuleField("conditions.sold_last_30_days.operator") === ">" ? "selected" : ""
          }>&gt;</option><option value=">=" ${
      getRuleField("conditions.sold_last_30_days.operator") === ">=" ? "selected" : ""
    }>&gt;=</option><option value="<" ${
      getRuleField("conditions.sold_last_30_days.operator") === "<" ? "selected" : ""
    }>&lt;</option><option value="<=" ${
      getRuleField("conditions.sold_last_30_days.operator") === "<=" ? "selected" : ""
    }>&lt;=</option><option value="=" ${
      getRuleField("conditions.sold_last_30_days.operator") === "=" ? "selected" : ""
    }>=</option><option value="!=" ${
      getRuleField("conditions.sold_last_30_days.operator") === "!=" ? "selected" : ""
    }>!=</option></select></div>
          <div class="aurora-repricer-field"><label>Sold 30d value</label><input type="number" step="0.01" data-rule-field="conditions.sold_last_30_days.value" data-rule-type="float-null" value="${esc(
            num("conditions.sold_last_30_days.value")
          )}"></div>
          <div class="aurora-repricer-field"><label><input type="checkbox" data-rule-field="conditions.top_search_only" data-rule-type="bool" ${
            getRuleField("conditions.top_search_only") ? "checked" : ""
          }> Top search only</label></div>
        </div>

        <h3>Pricing strategy</h3>
        <div class="aurora-repricer-grid">
          <div class="aurora-repricer-field"><label>Type</label><select data-rule-field="pricing_strategy.type"><option value="markup" ${
            getRuleField("pricing_strategy.type") === "markup" ? "selected" : ""
          }>markup</option><option value="margin" ${
      getRuleField("pricing_strategy.type") === "margin" ? "selected" : ""
    }>margin</option><option value="manual" ${
      getRuleField("pricing_strategy.type") === "manual" ? "selected" : ""
    }>manual</option><option value="competitor" ${
      getRuleField("pricing_strategy.type") === "competitor" ? "selected" : ""
    }>competitor</option></select></div>
          <div class="aurora-repricer-field"><label>Markup %</label><input type="number" step="0.01" data-rule-field="pricing_strategy.markup_percent" data-rule-type="float-null" value="${esc(
            num("pricing_strategy.markup_percent")
          )}"></div>
          <div class="aurora-repricer-field"><label>Markup abs</label><input type="number" step="0.01" data-rule-field="pricing_strategy.markup_abs" data-rule-type="float-null" value="${esc(
            num("pricing_strategy.markup_abs")
          )}"></div>
          <div class="aurora-repricer-field"><label>Target margin %</label><input type="number" step="0.01" data-rule-field="pricing_strategy.margin_target_percent" data-rule-type="float-null" value="${esc(
            num("pricing_strategy.margin_target_percent")
          )}"></div>
          <div class="aurora-repricer-field"><label>Manual mode</label><select data-rule-field="pricing_strategy.manual_mode"><option value="keep" ${
            getRuleField("pricing_strategy.manual_mode") === "keep" ? "selected" : ""
          }>keep</option><option value="override" ${
      getRuleField("pricing_strategy.manual_mode") === "override" ? "selected" : ""
    }>override</option></select></div>
          <div class="aurora-repricer-field"><label>Manual price</label><input type="number" step="0.01" data-rule-field="pricing_strategy.manual_price" data-rule-type="float-null" value="${esc(
            num("pricing_strategy.manual_price")
          )}"></div>
          <div class="aurora-repricer-field"><label>Competitor mode</label><select data-rule-field="pricing_strategy.competitor_mode"><option value="match" ${
            getRuleField("pricing_strategy.competitor_mode") === "match" ? "selected" : ""
          }>match</option><option value="beat" ${
      getRuleField("pricing_strategy.competitor_mode") === "beat" ? "selected" : ""
    }>beat</option></select></div>
          <div class="aurora-repricer-field"><label>Competitor delta</label><input type="number" step="0.01" data-rule-field="pricing_strategy.competitor_delta" data-rule-type="float-null" value="${esc(
            num("pricing_strategy.competitor_delta")
          )}"></div>
        </div>

        <h3>Guardrails</h3>
        <div class="aurora-repricer-grid">
          <div class="aurora-repricer-field"><label>Min price</label><input type="number" step="0.01" data-rule-field="guardrails.min_price" data-rule-type="float-null" value="${esc(
            num("guardrails.min_price")
          )}"></div>
          <div class="aurora-repricer-field"><label>Max price</label><input type="number" step="0.01" data-rule-field="guardrails.max_price" data-rule-type="float-null" value="${esc(
            num("guardrails.max_price")
          )}"></div>
          <div class="aurora-repricer-field"><label>Min margin %</label><input type="number" step="0.01" data-rule-field="guardrails.min_margin_percent" data-rule-type="float-null" value="${esc(
            num("guardrails.min_margin_percent")
          )}"></div>
          <div class="aurora-repricer-field"><label>Min margin abs</label><input type="number" step="0.01" data-rule-field="guardrails.min_margin_abs" data-rule-type="float-null" value="${esc(
            num("guardrails.min_margin_abs")
          )}"></div>
          <div class="aurora-repricer-field"><label>Max raise %</label><input type="number" step="0.01" data-rule-field="guardrails.max_raise_percent" data-rule-type="float-null" value="${esc(
            num("guardrails.max_raise_percent")
          )}"></div>
          <div class="aurora-repricer-field"><label>Max drop %</label><input type="number" step="0.01" data-rule-field="guardrails.max_drop_percent" data-rule-type="float-null" value="${esc(
            num("guardrails.max_drop_percent")
          )}"></div>
          <div class="aurora-repricer-field"><label>Rounding</label><select data-rule-field="guardrails.rounding"><option value="none" ${
            getRuleField("guardrails.rounding") === "none" ? "selected" : ""
          }>none</option><option value="x.99" ${
      getRuleField("guardrails.rounding") === "x.99" ? "selected" : ""
    }>x.99</option><option value="x.49" ${
      getRuleField("guardrails.rounding") === "x.49" ? "selected" : ""
    }>x.49</option><option value="step" ${
      getRuleField("guardrails.rounding") === "step" ? "selected" : ""
    }>step</option></select></div>
          <div class="aurora-repricer-field"><label>Step value</label><input type="number" step="0.01" data-rule-field="guardrails.step_value" data-rule-type="float-null" value="${esc(
            num("guardrails.step_value")
          )}"></div>
          <div class="aurora-repricer-field"><label>Margin mode</label><select data-rule-field="guardrails.margin_mode"><option value="clamp" ${
            getRuleField("guardrails.margin_mode") === "clamp" ? "selected" : ""
          }>clamp</option><option value="block" ${
      getRuleField("guardrails.margin_mode") === "block" ? "selected" : ""
    }>block</option></select></div>
        </div>

        <h3>Validity + Inventory</h3>
        <div class="aurora-repricer-grid">
          <div class="aurora-repricer-field"><label>Start at</label><input type="datetime-local" data-rule-field="validity.start_at" data-rule-type="datetime" value="${esc(
            datetimeInputValue(getRuleField("validity.start_at"))
          )}"></div>
          <div class="aurora-repricer-field"><label>End at</label><input type="datetime-local" data-rule-field="validity.end_at" data-rule-type="datetime" value="${esc(
            datetimeInputValue(getRuleField("validity.end_at"))
          )}"></div>
          <div class="aurora-repricer-field"><label>Max qty/order</label><input type="number" data-rule-field="inventory_rules.max_qty_per_order" data-rule-type="int-null" value="${esc(
            num("inventory_rules.max_qty_per_order")
          )}"></div>
          <div class="aurora-repricer-field"><label>Apply if stock &gt;</label><input type="number" data-rule-field="inventory_rules.apply_if_stock_gt" data-rule-type="int-null" value="${esc(
            num("inventory_rules.apply_if_stock_gt")
          )}"></div>
        </div>

        <p class="aurora-repricer-help" id="aurora-rule-preview-output">${esc(previewText)}</p>
      </section>
    `;
  };

  const buildRulesSavedSection = () => {
    const rules = Array.isArray(state.rules.items) ? state.rules.items : [];
    const rows = rules
      .map((rule) => {
        const id = Number(rule?.id || 0);
        const enabled = !!rule?.enabled;
        const exclusive = !!rule?.exclusive;
        const status = enabled ? "attiva" : "disattiva";
        const statusClass = enabled ? "status-success" : "status-error";
        return `<tr>
          <td class="num">${esc(id)}</td>
          <td>${esc(rule?.name || "-")}</td>
          <td class="num">${esc(rule?.priority ?? "-")}</td>
          <td><span class="aurora-repricer-status-pill ${statusClass}">${esc(status)}</span></td>
          <td>${exclusive ? "Sì" : "No"}</td>
          <td>${esc(rule?.updated_at || "-")}</td>
          <td><button type="button" class="button button-small" data-rule-open-id="${esc(id)}">Apri nell'editor</button></td>
        </tr>`;
      })
      .join("");

    return `
      <section class="aurora-repricer-section" id="aurora-rules-saved">
        <h2>Regole salvate</h2>
        <p class="aurora-repricer-help">Totale regole: <strong>${esc(rules.length)}</strong>. Usa "Apri nell'editor" per modificare velocemente.</p>
        <table class="widefat striped aurora-repricer-table">
          <thead>
            <tr>
              <th class="num">ID</th>
              <th>Nome</th>
              <th class="num">Priorità</th>
              <th>Stato</th>
              <th>Esclusiva</th>
              <th>Aggiornata</th>
              <th>Azione</th>
            </tr>
          </thead>
          <tbody>${rows || '<tr><td colspan="7" class="aurora-repricer-empty">Nessuna regola salvata.</td></tr>'}</tbody>
        </table>
      </section>
    `;
  };

  const buildSectionNav = () => `
    <nav class="aurora-repricer-nav" aria-label="Navigazione scheda repricer">
      <a href="#aurora-overview">Overview</a>
      <a href="#aurora-guided-run">Esegui</a>
      <a href="#aurora-rule-editor">Regole</a>
      <a href="#aurora-rules-saved">Regole salvate</a>
      <a href="#aurora-run-details">Run</a>
      <a href="#aurora-decisioni">Decisioni</a>
      <a href="#aurora-scheduler">Scheduler</a>
      <a href="#aurora-assignments">Assignment</a>
      <a href="#aurora-rollback-zone">Rollback</a>
    </nav>
  `;

  const loadRules = async (force) => {
    if (state.rules.loaded && !force) return;
    try {
      const response = await apiFetch(route("rulesList"), "GET", undefined, { lockOnAuth: true });
      state.rules.items = Array.isArray(response?.items) ? response.items : [];
      state.rules.loaded = true;
      if (!state.rules.selectedId && state.rules.items.length > 0) {
        state.rules.selectedId = Number(state.rules.items[0].id || 0);
      }
      if (state.rules.selectedId > 0) {
        const rule = await apiFetch(routeWithId("ruleGet", state.rules.selectedId), "GET", undefined, { lockOnAuth: true });
        state.rules.draft = normalizeRuleFromApi(rule);
      }
    } catch (err) {
      showNotice("warning", err.message || "Impossibile caricare le regole repricer.");
    }
  };

  const loadRuleOptions = async (force) => {
    if (state.rules.optionsLoaded && !force) return;
    try {
      const response = await apiFetch(route("ruleOptions"), "GET", undefined, { lockOnAuth: true });
      const raw = response?.options || {};
      state.rules.options = {
        categories: Array.isArray(raw.categories) ? raw.categories : [],
        brands: Array.isArray(raw.brands) ? raw.brands : [],
        product_types: Array.isArray(raw.product_types) ? raw.product_types : [],
        suppliers: Array.isArray(raw.suppliers) ? raw.suppliers : [],
        lines: Array.isArray(raw.lines) ? raw.lines : [],
        products: Array.isArray(raw.products) ? raw.products : [],
        brand_taxonomy: raw.brand_taxonomy || null,
      };
      state.rules.optionsLoaded = true;
    } catch (err) {
      showNotice("warning", err.message || "Impossibile caricare le opzioni di scope.");
    }
  };

  const renderWarnings = (data) => {
    const warnings = [];
    const scheduler = data?.repricer?.scheduler || {};
    if (data?.config?.wp_cron_enabled) {
      warnings.push("WP-Cron attivo: può causare run in ritardo in ambienti ad alto carico.");
    }
    if (isPastDue(scheduler)) {
      warnings.push("Scheduler tick past-due: l'ultimo tick è troppo vecchio.");
    }
    if (!warnings.length) return "";
    return `<details id="aurora-warnings-details" class="notice notice-warning is-dismissible" ${state.detailsOpen.warnings ? "open" : ""}><summary><strong>Avvisi operativi</strong></summary><ul>${warnings.map((w) => `<li>${esc(w)}</li>`).join("")}</ul></details>`;
  };

  const render = () => {
    const data = state.data;
    if (!data) {
      root.innerHTML = '<div class="aurora-repricer-section"><p class="aurora-repricer-empty">Caricamento stato...</p></div>';
      return;
    }

    const repricer = data.repricer || {};
    setHealthBadge(data.health?.status || "WARN");
    if (lastUpdate) {
      lastUpdate.textContent = data.generated_at_utc || "-";
    }

    root.innerHTML = `
      ${renderWarnings(data)}
      ${buildSectionNav()}
      ${buildOverview(repricer, data.health?.status || "WARN")}
      ${buildGuidedRun(repricer)}
      ${buildRuleEditorSection()}
      ${buildRulesSavedSection()}
      ${buildRunDetails(repricer)}
      ${buildDecisionSection(repricer)}
      ${buildSchedulerSection(repricer)}
      ${buildAssignmentsSection(repricer)}
      ${buildRollbackSection(repricer)}
    `;
    state.lastRenderFingerprint = computeFingerprint(data);

    bindInteractions(repricer);
    bindDetailsState();
  };

  const getMode = () => {
    const checked = root.querySelector('input[name="repricer_mode"]:checked');
    return checked ? checked.value : "dry_run";
  };

  const bindInteractions = (repricer) => {
    const assignmentSelect = document.getElementById("aurora-assignment-select");
    if (assignmentSelect) {
      assignmentSelect.addEventListener("change", () => {
        state.selectedAssignmentId = Number(assignmentSelect.value || 0);
        render();
      });
    }

    root.querySelectorAll(".aurora-repricer-nav a").forEach((link) => {
      link.addEventListener("click", (event) => {
        event.preventDefault();
        const target = link.getAttribute("href");
        if (!target) return;
        const node = document.querySelector(target);
        if (!node) return;
        node.scrollIntoView({ behavior: "smooth", block: "start" });
      });
    });

    root.querySelectorAll('input[name="repricer_mode"]').forEach((radio) => {
      radio.addEventListener("change", () => {
        state.form.mode = getMode();
        render();
      });
    });

    const bindNumeric = (id, key, min, max) => {
      const el = document.getElementById(id);
      if (!el) return;
      const handler = () => {
        const raw = Number(el.value);
        const clamped = Math.max(min, Math.min(max, Number.isFinite(raw) ? raw : min));
        state.form[key] = clamped;
        if (clamped !== raw) el.value = String(clamped);
      };
      el.addEventListener("change", handler);
      el.addEventListener("input", handler);
    };

    bindNumeric("aurora-max-products", "max_products", 1, 200000);
    bindNumeric("aurora-chunk-size", "chunk_size", 1, 5000);
    bindNumeric("aurora-timebox", "timebox_seconds", 5, 3600);
    bindNumeric("aurora-margin-pct", "min_margin_percent", 0, 1000);
    bindNumeric("aurora-margin-abs", "min_margin_abs", 0, 1000000);
    bindNumeric("aurora-hard-max-raise", "hard_max_raise_pct", 0, 1000);
    bindNumeric("aurora-hard-max-drop", "hard_max_drop_pct", 0, 1000);

    const previewBtn = document.getElementById("aurora-preview-payload");
    if (previewBtn) {
      previewBtn.addEventListener("click", async () => {
        const assignmentId = Number(state.selectedAssignmentId || 0);
        if (!assignmentId) {
          showNotice("warning", "Seleziona un assignment prima della preview.");
          return;
        }
        await runAction(previewBtn, route("preview"), { assignment_id: assignmentId, limit: 50 }, (res) => {
          state.previews[String(assignmentId)] = res;
          showNotice("success", `Preview completata: ${res.selected_count || 0} prodotti risolti.`);
        });
        render();
      });
    }

    const runBtn = document.getElementById("aurora-run-repricer");
    if (runBtn) {
      runBtn.addEventListener("click", async () => {
        const assignmentId = Number(state.selectedAssignmentId || 0);
        if (!assignmentId) {
          showNotice("warning", "Seleziona un assignment.");
          return;
        }

        state.form.mode = getMode();
        const isApply = state.form.mode === "apply";
        if (isApply) {
          const ok = window.confirm("Conferma APPLY: verranno aggiornati i prezzi reali. Continuare?");
          if (!ok) return;
        }

        const payload = {
          assignment_id: assignmentId,
          max_products: Number(state.form.max_products),
          chunk_size: Number(state.form.chunk_size),
          timebox_seconds: Number(state.form.timebox_seconds),
          min_margin_percent: Number(state.form.min_margin_percent),
          min_margin_abs: Number(state.form.min_margin_abs),
          hard_max_raise_pct: Number(state.form.hard_max_raise_pct),
          hard_max_drop_pct: Number(state.form.hard_max_drop_pct),
          dry_run: !isApply,
        };

        await runAction(runBtn, route("run"), payload, (res) => {
          showNotice("success", `Run schedulato: run_id=${res.run_id || "n/a"}`);
        });
      });
    }

    const filterProduct = document.getElementById("filter-product-id");
    const filterRun = document.getElementById("filter-run-id");
    const filterRule = document.getElementById("filter-rule");
    const filterApplied = document.getElementById("filter-applied");

    const onFilterChange = () => {
      state.filters.productId = filterProduct ? filterProduct.value.trim() : "";
      state.filters.runId = filterRun ? filterRun.value.trim() : "";
      state.filters.rule = filterRule ? filterRule.value : "";
      state.filters.applied = filterApplied ? filterApplied.value : "all";
      render();
    };

    [filterProduct, filterRun, filterRule, filterApplied].forEach((el) => {
      if (el) el.addEventListener("change", onFilterChange);
    });
    [filterProduct, filterRun].forEach((el) => {
      if (el) el.addEventListener("input", onFilterChange);
    });

    const tickBtn = document.getElementById("aurora-run-tick");
    if (tickBtn) {
      tickBtn.addEventListener("click", async () => {
        const input = document.getElementById("scheduler-assignment-id");
        const only = input ? Math.max(0, Number(input.value || 0)) : 0;
        state.schedulerOnlyAssignmentId = only;
        await runAction(tickBtn, route("tick"), { only_assignment_id: only }, (res) => {
          showNotice("success", `Tick eseguito: enqueued=${res.enqueued || 0}, skipped=${res.skipped || 0}`);
        });
      });
    }

    const runAllDry = document.getElementById("aurora-runall-dry");
    if (runAllDry) {
      runAllDry.addEventListener("click", async () => {
        await runAction(runAllDry, route("runAll"), { mode: "dry_run" }, (res) => {
          showNotice("success", `Run all dry-run: enqueued=${res.enqueued || 0}`);
        });
      });
    }

    const runAllApply = document.getElementById("aurora-runall-apply");
    if (runAllApply) {
      runAllApply.addEventListener("click", async () => {
        const ok = window.confirm("Run all APPLY aggiorna prezzi su tutti gli assignment abilitati. Confermi?");
        if (!ok) return;
        await runAction(runAllApply, route("runAll"), { mode: "apply" }, (res) => {
          showNotice("success", `Run all apply: enqueued=${res.enqueued || 0}`);
        });
      });
    }

    root.querySelectorAll("[data-assignment-action]").forEach((button) => {
      button.addEventListener("click", async () => {
        const action = button.getAttribute("data-assignment-action");
        const assignmentId = Number(button.getAttribute("data-assignment-id") || 0);
        const feedback = document.getElementById("aurora-assignment-feedback");

        if (action === "preview") {
          await runAction(button, route("preview"), { assignment_id: assignmentId, limit: 30 }, (res) => {
            if (feedback) {
              feedback.textContent = `Assignment ${assignmentId}: ${res.selected_count || 0} prodotti.`;
            }
          });
          return;
        }

        if (action === "dry") {
          await runAction(button, route("run"), { assignment_id: assignmentId, dry_run: true }, (res) => {
            if (feedback) {
              feedback.textContent = `Dry-run assignment ${assignmentId} schedulato (run_id=${res.run_id || "n/a"}).`;
            }
          });
          return;
        }

        if (action === "apply") {
          const ok = window.confirm(`Confermi apply per assignment ${assignmentId}?`);
          if (!ok) return;
          await runAction(button, route("apply"), { assignment_id: assignmentId }, (res) => {
            if (feedback) {
              feedback.textContent = `Apply assignment ${assignmentId} schedulato (run_id=${res.run_id || "n/a"}).`;
            }
          });
        }
      });
    });

    const ruleSelect = document.getElementById("aurora-rule-select");
    if (ruleSelect) {
      ruleSelect.addEventListener("change", async () => {
        const id = Number(ruleSelect.value || 0);
        state.rules.selectedId = id;
        state.rules.preview = null;
        if (id <= 0) {
          state.rules.draft = defaultRuleDraft();
          render();
          return;
        }
        try {
          const row = await apiFetch(routeWithId("ruleGet", id), "GET", undefined, { lockOnAuth: true });
          state.rules.draft = normalizeRuleFromApi(row);
          render();
        } catch (err) {
          showNotice("warning", err.message || "Impossibile caricare la regola selezionata.");
        }
      });
    }

    root.querySelectorAll("[data-rule-open-id]").forEach((button) => {
      button.addEventListener("click", async () => {
        const id = Number(button.getAttribute("data-rule-open-id") || 0);
        if (id <= 0) return;
        try {
          const row = await apiFetch(routeWithId("ruleGet", id), "GET", undefined, { lockOnAuth: true });
          state.rules.selectedId = id;
          state.rules.preview = null;
          state.rules.draft = normalizeRuleFromApi(row);
          render();
          const editor = document.getElementById("aurora-rule-editor");
          if (editor) {
            editor.scrollIntoView({ behavior: "smooth", block: "start" });
          }
        } catch (err) {
          showNotice("warning", err.message || "Impossibile aprire la regola selezionata.");
        }
      });
    });

    const ruleNewBtn = document.getElementById("aurora-rule-new");
    if (ruleNewBtn) {
      ruleNewBtn.addEventListener("click", () => {
        state.rules.selectedId = 0;
        state.rules.preview = null;
        state.rules.draft = defaultRuleDraft();
        render();
      });
    }

    const productAddBtn = document.getElementById("aurora-rule-product-add");
    if (productAddBtn) {
      productAddBtn.addEventListener("click", () => {
        const picker = document.getElementById("aurora-rule-product-pick");
        const productIdsInput = document.getElementById("aurora-rule-product-ids");
        const selectedValue = picker ? Number(picker.value || 0) : 0;
        if (!productIdsInput || selectedValue <= 0) {
          return;
        }
        const nextCsv = addProductIdToCsv(productIdsInput.value, selectedValue);
        productIdsInput.value = nextCsv;
        setRuleField("scope.product_ids", parseProductIdsCsv(nextCsv));
        if (picker) {
          picker.value = "";
        }
      });
    }

    root.querySelectorAll("[data-rule-field]").forEach((input) => {
      const eventName = input.type === "checkbox" || input.tagName === "SELECT" ? "change" : "input";
      input.addEventListener(eventName, () => {
        const path = input.getAttribute("data-rule-field");
        const type = input.getAttribute("data-rule-type") || "text";
        let value = input.value;
        if (type === "bool") {
          value = !!input.checked;
        } else if (type === "int") {
          value = Math.max(0, parseInt(input.value || "0", 10) || 0);
        } else if (type === "int-null") {
          value = String(input.value || "").trim() === "" ? null : Math.max(0, parseInt(input.value || "0", 10) || 0);
        } else if (type === "float-null") {
          value = String(input.value || "").trim() === "" ? null : Math.max(0, parseFloat(input.value || "0") || 0);
        } else if (type === "csv-int") {
          value = csvToIntArray(input.value);
        } else if (type === "csv-text") {
          value = csvToTextArray(input.value);
        } else if (type === "multi-int") {
          value = collectMultiValues(input).map((item) => Number(item || 0)).filter((item) => item > 0);
        } else if (type === "multi-text") {
          value = collectMultiValues(input).map((item) => String(item || "").trim()).filter((item) => item.length > 0);
        } else if (type === "datetime") {
          value = datetimeApiValue(input.value);
        }
        setRuleField(path, value);
      });
    });

    const ruleSaveBtn = document.getElementById("aurora-rule-save");
    if (ruleSaveBtn) {
      ruleSaveBtn.addEventListener("click", async () => {
        const payload = { rule: state.rules.draft };
        const selectedId = Number(state.rules.selectedId || 0);
        if (!state.rules.draft?.rule_meta?.name) {
          showNotice("warning", "Il nome regola è obbligatorio.");
          return;
        }
        if (selectedId > 0) {
          await runAction(ruleSaveBtn, routeWithId("ruleUpdate", selectedId), payload, () => {
            showNotice("success", `Regola #${selectedId} aggiornata.`);
          }, "PUT");
        } else {
          const res = await runAction(ruleSaveBtn, route("ruleCreate"), payload, (result) => {
            showNotice("success", `Regola creata (#${result.rule_id || "n/a"}).`);
          });
          state.rules.selectedId = Number(res?.rule_id || 0);
        }
        await loadRules(true);
        render();
      });
    }

    const rulePreviewBtn = document.getElementById("aurora-rule-preview");
    if (rulePreviewBtn) {
      rulePreviewBtn.addEventListener("click", async () => {
        const selectedId = Number(state.rules.selectedId || 0);
        if (selectedId <= 0) {
          showNotice("warning", "Salva prima la regola per eseguire preview scope.");
          return;
        }
        const result = await runAction(rulePreviewBtn, routeWithId("rulePreview", selectedId), { limit: 200 }, undefined);
        state.rules.preview = result;
        render();
      });
    }

    const rollbackRunIdBtn = document.getElementById("aurora-rollback-runid");
    if (rollbackRunIdBtn) {
      rollbackRunIdBtn.addEventListener("click", async () => {
        const input = document.getElementById("rollback-run-id");
        const dry = !!document.getElementById("rollback-dry-run")?.checked;
        const runId = input ? Number(input.value || 0) : 0;
        state.rollbackRunIdInput = input ? input.value : "";
        state.rollbackDryRun = dry;
        if (runId <= 0) {
          showNotice("warning", "Inserisci un run_id valido per il rollback.");
          return;
        }
        const ok = window.confirm(`Confermi rollback del run_id=${runId}?`);
        if (!ok) return;
        await runAction(rollbackRunIdBtn, route("rollback"), { run_id: runId, dry_run: dry }, (res) => {
          showNotice("success", `Rollback schedulato (run_id=${res.run_id || "n/a"}).`);
        });
      });
    }

    const rollbackLastBtn = document.getElementById("aurora-rollback-last");
    if (rollbackLastBtn) {
      rollbackLastBtn.addEventListener("click", async () => {
        if (!repricer.last_apply_run || Number(repricer.last_apply_run.run_id || 0) <= 0) {
          return;
        }
        const target = Number(repricer.last_apply_run.run_id);
        const c1 = window.confirm(`Rollback ultimo apply (run_id=${target})?`);
        if (!c1) return;
        const c2 = window.confirm("Conferma finale: procedere con rollback?");
        if (!c2) return;
        await runAction(rollbackLastBtn, route("rollback"), { run_id: target, dry_run: false }, (res) => {
          showNotice("success", `Rollback ultimo apply schedulato (run_id=${res.run_id || "n/a"}).`);
        });
      });
    }

    const schedulerOnlyInput = document.getElementById("scheduler-assignment-id");
    if (schedulerOnlyInput) {
      schedulerOnlyInput.addEventListener("input", () => {
        state.schedulerOnlyAssignmentId = Math.max(0, Number(schedulerOnlyInput.value || 0));
      });
    }
    const rollbackRunInput = document.getElementById("rollback-run-id");
    if (rollbackRunInput) {
      rollbackRunInput.addEventListener("input", () => {
        state.rollbackRunIdInput = rollbackRunInput.value;
      });
    }
    const rollbackDryInput = document.getElementById("rollback-dry-run");
    if (rollbackDryInput) {
      rollbackDryInput.addEventListener("change", () => {
        state.rollbackDryRun = !!rollbackDryInput.checked;
      });
    }
  };

  const bindDetailsState = () => {
    const bindings = [
      ["aurora-warnings-details", "warnings"],
      ["aurora-run-error-details", "runError"],
      ["aurora-advanced-details", "advanced"],
      ["aurora-run-raw-details", "runRaw"],
      ["aurora-scheduler-raw-details", "schedulerRaw"],
      ["aurora-rollback-danger-details", "rollbackDanger"],
    ];
    bindings.forEach(([id, key]) => {
      const node = document.getElementById(id);
      if (!node) return;
      node.addEventListener("toggle", () => {
        state.detailsOpen[key] = !!node.open;
      });
    });
  };

  const refreshStatus = async () => {
    try {
      const data = await apiFetch(route("status"), "GET", undefined, { lockOnAuth: false });
      state.data = data;
      state.authFailures = 0;
      state.pollDelay = basePollMs;
      const nextFingerprint = computeFingerprint(data);
      if (isInteracting() || hasFocusedControl()) {
        setHealthBadge(data.health?.status || "WARN");
        if (lastUpdate) {
          lastUpdate.textContent = data.generated_at_utc || "-";
        }
      } else if (nextFingerprint === state.lastRenderFingerprint) {
        setHealthBadge(data.health?.status || "WARN");
        if (lastUpdate) {
          lastUpdate.textContent = data.generated_at_utc || "-";
        }
      } else {
        render();
      }
      clearNotice();
    } catch (err) {
      if (err.status === 401 || err.status === 403) {
        state.authFailures += 1;
        if (state.authFailures >= 2) {
          lockUi(err.message || "Sessione scaduta. Ricarica la pagina.");
          return;
        }
      }
      if (!state.uiLocked) {
        const msg = err.message || "Errore caricamento stato";
        showNotice(err.status === 401 || err.status === 403 ? "error" : "warning", msg);
      }

      if (err.status === 429 && err.retryAfter > 0) {
        state.pollDelay = Math.min(maxPollMs, err.retryAfter * 1000);
      } else {
        state.pollDelay = Math.min(maxPollMs, state.pollDelay + 2000);
      }
    } finally {
      schedulePoll();
    }
  };

  const schedulePoll = () => {
    window.clearTimeout(state.pollTimer);
    if (state.uiLocked) return;
    if (document.hidden) {
      state.pollTimer = window.setTimeout(schedulePoll, basePollMs);
      return;
    }
    state.pollTimer = window.setTimeout(() => {
      refreshStatus();
    }, state.pollDelay);
  };

  if (refreshBtn) {
    refreshBtn.addEventListener("click", (event) => {
      event.preventDefault();
      state.pollDelay = basePollMs;
      refreshStatus();
    });
  }

  if (overlayReload) {
    overlayReload.addEventListener("click", () => {
      window.location.reload();
    });
  }

  document.addEventListener("visibilitychange", () => {
    if (!document.hidden && !state.uiLocked) {
      state.pollDelay = basePollMs;
      refreshStatus();
    }
  });

  ["pointerdown", "keydown", "focusin", "input", "change"].forEach((eventName) => {
    root.addEventListener(
      eventName,
      () => {
        markInteraction(9000);
      },
      true
    );
  });

  Promise.all([loadRules(true), loadRuleOptions(true)]).finally(() => {
    refreshStatus();
  });
})();
