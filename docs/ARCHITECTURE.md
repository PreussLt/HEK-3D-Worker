# Architektur

## Überblick

```
┌─────────────┐     HTTP      ┌──────────────────┐
│   Browser   │ ◄───────────► │  PHP / Apache    │  :22200
└─────────────┘               │  (Frontend)      │
                               └────────┬─────────┘
                                        │ PDO / SQL
                               ┌────────▼─────────┐
                               │   PostgreSQL 16   │  :22201
                               └────────┬─────────┘
                                        │ SKIP LOCKED polling
                               ┌────────▼─────────┐
                               │  Python Worker   │
                               │  (3 Processors)  │
                               └────────┬─────────┘
                                        │ S3 API
                               ┌────────▼─────────┐
                               │  RustFS (S3)     │  :22202 / :22203
                               └──────────────────┘
```

## Dienste

### PHP-Frontend (`docker/php/Dockerfile`)

- PHP 8.3 + Apache, Port **22200**
- Enthält einen einfachen URL-Router in `public/index.php`
- Liest/schreibt Jobs, Modelle und Templates in PostgreSQL
- Streams Dateien aus RustFS direkt an den Browser (`/api/file`)
- Upload-Limit: 256 MB

### Python-Worker (`docker/worker/Dockerfile`)

- Python 3.12, kein HTTP-Port (nur DB-Polling)
- Startet **drei parallele Processor-Schleifen** (je ein Thread):
  - `JobProcessor` — Logo-Einbettung & 3MF-Export
  - `MagnetJobProcessor` — Bohrlocherzeugung
  - `SvgConverterProcessor` — Raster → SVG
- Jeder Processor läuft im 5-Sekunden-Takt und verwendet `SELECT … FOR UPDATE SKIP LOCKED` für lückenlose Verarbeitung bei potenziell mehreren Worker-Instanzen

### PostgreSQL (`postgres:16-alpine`)

- Port **22201**, persistentes Volume `postgres_data`
- Initialisierung via `docker/postgres/init.sql`
- Migrationen liegen unter `database/migrations/`

### RustFS (S3-kompatibel)

- Port **22202** (API), **22203** (Web-Console)
- Drei Buckets: `logos`, `models`, `output`
- Credentials aus `.env` (`RUSTFS_ACCESS_KEY` / `RUSTFS_SECRET_KEY`)

## Datenfluss: Print-Job

```
1. Browser lädt Modell hoch
      → PHP speichert Datei in RustFS (bucket: models)
      → PHP legt models-Datensatz in DB an

2. Browser öffnet Job-Editor
      → PHP lädt Modell-Metadaten aus DB
      → Browser streamt Modell-Datei von /api/file
      → editor3d.js rendert Vorschau (Three.js)

3. Browser sendet Job (POST /jobs/new)
      → PHP lädt Logo in RustFS (bucket: logos)
      → PHP legt jobs-Datensatz an (status=pending)

4. Worker pollt DB (SELECT … WHERE status='pending' FOR UPDATE SKIP LOCKED)
      → Setzt status=processing
      → Lädt Modell + Logo aus RustFS
      → Führt Mesh-Bearbeitung durch
      → Schreibt 3MF in RustFS (bucket: output)
      → Setzt status=done + output_key

5. Browser (Polling alle 4 s)
      → Lädt job_detail.php neu
      → Zeigt Download-Button mit 3MF-Dateinamen
```

## Concurrency & Fehlerbehandlung

- `FOR UPDATE SKIP LOCKED` verhindert Doppelverarbeitung bei mehreren Worker-Replikas
- Jeder Processor fängt alle Exceptions, schreibt den Stacktrace in `jobs.error` und setzt `status=error`
- Worker reconnected bei DB-Verbindungsabbrüchen automatisch
- Restart-Policy: `on-failure` (Worker), `unless-stopped` (PHP)

## Speicher-Layout (RustFS)

```
logos/
  {uniqid}_{originalname}.png        ← Upload
  {uniqid}_layer{n}.png              ← Multi-Layer
  svg_{job_id}_logo.png              ← Konvertiertes RGBA-PNG

models/
  {uniqid}_{originalname}.{ext}      ← Hochgeladenes Modell

output/
  {job_id}.3mf                       ← Print-Job Ergebnis
  {job_id}_magnets.stl               ← Modell mit Bohrlöchern
  {job_id}_template.stl              ← Bohrschablone
  {job_id}.svg                       ← Vektorisiertes Logo
```
