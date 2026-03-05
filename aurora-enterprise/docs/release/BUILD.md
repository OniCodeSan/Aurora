# Build Instructions — Aurora Enterprise

## Prerequisiti

- `git`, `zip`, `shasum`
- working tree pulito per build reproducible

## Comando build

```bash
cd /Users/mariano/.openclaw/workspace/aurora-enterprise
VERSION=1.0.0-rc.1 ./tools/release/build_zip.sh
```

## Output

- ZIP: `dist/aurora-enterprise-<VERSION>.zip`
- SHA256: `dist/aurora-enterprise-<VERSION>.zip.sha256`
- Size report: `dist/aurora-enterprise-<VERSION>.zip.size.txt`

## Inclusioni/Esclusioni

Build usa `git archive` su `HEAD`, quindi include solo file tracciati.
Esclusi implicitamente:
- file non tracciati
- output locali (`tools/stress/out`, `dist` già generati)

## Verifiche

```bash
ls -lh dist/aurora-enterprise-1.0.0-rc.1.zip
cat dist/aurora-enterprise-1.0.0-rc.1.zip.sha256
```

Confrontare checksum e dimensione tra ambienti prima del rilascio pubblico.
