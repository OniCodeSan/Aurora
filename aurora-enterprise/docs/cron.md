# Aurora Enterprise – Cron Setup

La pipeline funziona solo se i worker WP-CLI vengono eseguiti da cron di sistema (WP-Cron deve restare disattivato). Di seguito i passaggi per configurare i job usando il container `wordpress-worker-1` creato nel `docker-compose`.

## 1. Creare la cartella log
```bash
sudo mkdir -p /var/log/aurora
sudo chown $(whoami) /var/log/aurora
```

## 2. Caricare il file di esempio
Nel repository trovi `cron/aurora-crontab.example` con i comandi suggeriti. Copia il contenuto nel tuo `crontab` utente:
```bash
crontab cron/aurora-crontab.example
```
Oppure incolla le linee manualmente con `crontab -e`.

## 3. Comandi spiegati
| Job | Frequenza | Comando |
|-----|-----------|---------|
| Price worker | ogni 5 min | `docker exec wordpress-worker-1 wp aurora worker --indexer=price --batch=750 --max-loops=2 --allow-root` |
| Stock worker | ogni 5 min (offset 2 min) | `docker exec wordpress-worker-1 wp aurora worker --indexer=stock --batch=750 --max-loops=2 --allow-root` |
| Visibility worker | ogni 15 min | `docker exec wordpress-worker-1 wp aurora worker --indexer=visibility --batch=500 --max-loops=1 --allow-root` |
| Rebuild completo | ore 03:00 | `docker exec wordpress-worker-1 wp aurora rebuild --indexer=all --allow-root` |
| Feed receiver (opz.) | ogni ora | `docker exec wordpress-worker-1 wp option update aurora_feed_receiver_url "https://www.contentmug.it/wp-json/wc/store/products" --allow-root` |

## 4. Verificare
- Controlla i log sotto `/var/log/aurora/*.log`.
- In WordPress, la dashboard Aurora Indexer → sezione Cron passerà da “paused” a “processed” non appena i job girano almeno una volta.
- Nel container worker puoi sempre lanciare manualmente: `docker exec -it wordpress-worker-1 wp aurora worker --indexer=all --batch=250 --max-loops=1 --allow-root`.

Con questa configurazione i worker saranno orchestrati dal sistema host e non dipenderanno più da WP-Cron.
