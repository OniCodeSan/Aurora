# Release Checklist — Aurora Enterprise RC

## 1) Repository

- [ ] `git status -sb` clean
- [ ] branch/tag allineati
- [ ] commit changelog/release docs finali

## 2) Tag

- [ ] tag tecnico closure presente (`engine_closed_2026-03-05`)
- [ ] tag RC creato (`v1.0.0-rc.1` o successivo)
- [ ] push tag su remote

## 3) Build ZIP

- [ ] eseguito `tools/release/build_zip.sh`
- [ ] artefatto in `dist/aurora-enterprise-1.0.0-rc.1.zip`
- [ ] checksum SHA256 generato
- [ ] dimensione file verificata

## 4) Install test

- [ ] upload ZIP su WordPress pulito
- [ ] activation senza fatal
- [ ] deactivation senza side effects distruttivi

## 5) UI check

- [ ] Dashboard carica e mostra KPI
- [ ] System Status carica e aggiorna
- [ ] Ops trigger funzionanti
- [ ] Repricer pagina e azioni live
- [ ] Feed Hub e connessioni feed visibili

## 6) REST/Security check

- [ ] capability check attivo (401/403 su non autorizzati)
- [ ] nonce gestito correttamente lato admin
- [ ] rate limit trigger (429 + retry_after)

## 7) Uninstall behavior

- [ ] default: non drop tabelle custom
- [ ] option/transient `aurora_*` rimossi
- [ ] drop tabelle solo con `aurora_delete_data_on_uninstall=true`
