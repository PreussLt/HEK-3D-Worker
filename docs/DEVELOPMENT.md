# Entwicklungs-Guide

## Voraussetzungen

- Docker + Docker Compose
- Kein lokales PHP/Python nötig — alles läuft im Container

## Erstmalige Einrichtung

```bash
cp .env.example .env
docker compose up -d

# Migrationen ausführen (einmalig)
for f in database/migrations/*.sql; do
  docker compose exec -T postgres psql -U hek3d_user -d hek3d -f - < "$f"
done
```

## Dienste starten / stoppen

```bash
docker compose up -d          # alle Dienste
docker compose down           # stoppen (Daten bleiben erhalten)
docker compose down -v        # stoppen + alle Volumes löschen
docker compose restart php worker
```

## Logs

```bash
docker compose logs -f worker   # Worker-Output (Verarbeitungsschritte)
docker compose logs -f php      # Apache-/PHP-Fehler
docker compose logs -f postgres
```

## Neue Migration anlegen

1. Datei `database/migrations/00N_beschreibung.sql` erstellen:

```sql
ALTER TABLE models ADD COLUMN IF NOT EXISTS neues_feld TEXT;
```

2. Gegen die laufende DB ausführen:

```bash
docker compose exec -T postgres psql -U hek3d_user -d hek3d \
  -f - < database/migrations/00N_beschreibung.sql
```

3. Ggf. `docker/postgres/init.sql` für Neuaufbauten aktualisieren (enthält das vollständige Schema).

## Frontend-Änderungen

Das `frontend/`-Verzeichnis wird live in den PHP-Container gemountet — PHP-Änderungen sind sofort aktiv, kein Rebuild nötig.

Bei Änderungen an `composer.json`:

```bash
docker compose exec php composer install --no-dev --optimize-autoloader
```

## Worker-Änderungen

Das `worker/`-Verzeichnis ist ebenfalls live gemountet. Nach Python-Änderungen:

```bash
docker compose restart worker
```

Bei Änderungen an `requirements.txt` (neue Pakete):

```bash
docker compose build worker
docker compose up -d worker
```

## Vollständiger Rebuild

```bash
docker compose build --no-cache
docker compose up -d
```

## Direkter DB-Zugriff

```bash
# psql-Shell
docker compose exec postgres psql -U hek3d_user -d hek3d

# Nützliche Queries
SELECT id, status, error, created_at FROM jobs ORDER BY created_at DESC LIMIT 10;
SELECT id, filename, name, format, uploaded_at FROM models ORDER BY uploaded_at DESC;
```

## RustFS (S3) Web-Console

Erreichbar unter **http://localhost:22203**

Login: `minioadmin` / `minioadmin` (aus `.env`)

Buckets: `logos`, `models`, `output`

## Umgebungsvariablen

Alle Variablen werden aus `.env` geladen. Die Defaults aus `.env.example` funktionieren out-of-the-box für lokale Entwicklung.

| Variable | Beschreibung |
|----------|--------------|
| `DB_HOST` | PostgreSQL-Host (im Docker-Netz: `postgres`) |
| `DB_PORT` | PostgreSQL-Port (Standard: `5432`) |
| `DB_NAME` | Datenbankname |
| `DB_USER` | Datenbankbenutzer |
| `DB_PASSWORD` | Datenbankpasswort |
| `RUSTFS_ENDPOINT` | S3-Endpunkt (im Docker-Netz: `http://rustfs:9000`) |
| `RUSTFS_ACCESS_KEY` | S3 Access Key |
| `RUSTFS_SECRET_KEY` | S3 Secret Key |
| `RUSTFS_BUCKET_LOGOS` | Bucket für Logos (Standard: `logos`) |
| `RUSTFS_BUCKET_MODELS` | Bucket für Modelle (Standard: `models`) |
| `RUSTFS_BUCKET_OUTPUT` | Bucket für Ergebnisse (Standard: `output`) |
| `APP_ENV` | `development` oder `production` |
| `APP_SECRET` | Session-Secret |

## Projekt-Ports

| Port | Dienst |
|------|--------|
| 22200 | PHP-Frontend (HTTP) |
| 22201 | PostgreSQL |
| 22202 | RustFS S3 API |
| 22203 | RustFS Web-Console |

## Typische Debugging-Szenarien

### Job steckt bei `processing` fest

```bash
# Worker neu starten
docker compose restart worker

# Job manuell auf pending zurücksetzen
docker compose exec postgres psql -U hek3d_user -d hek3d \
  -c "UPDATE jobs SET status='pending', error=NULL WHERE id='<uuid>';"
```

### Worker-Fehler verstehen

```bash
docker compose logs worker | tail -50
# Oder: Fehler steht auch in jobs.error in der DB
docker compose exec postgres psql -U hek3d_user -d hek3d \
  -c "SELECT id, error FROM jobs WHERE status='error' ORDER BY updated_at DESC LIMIT 5;"
```

### Datei nicht gefunden (404 bei /api/file)

Prüfen ob der Storage-Key in RustFS existiert:
- RustFS-Console öffnen: http://localhost:22203
- Bucket und Key überprüfen
- Ggf. Worker-Logs auf Upload-Fehler prüfen
