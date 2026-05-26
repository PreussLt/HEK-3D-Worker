# HEK-3D-Worker

Webbasiertes System zur Vorbereitung von 3D-Druckdateien mit Logo-Einbettung, Magnet-Bohrungen und Bild-Vektorisierung.

## Überblick

HEK-3D-Worker nimmt 3D-Modelle und Logos entgegen, bettet das Logo als Negativ in das Modell ein und exportiert eine druckfertige 3MF-Datei für den Mehrfarbendruck (z.B. Bambu Lab). Daneben gibt es zwei weitere Verarbeitungs-Pipelines: Magnet-Bohrungen und Bild-zu-SVG-Konvertierung.

```
Browser → PHP-Frontend → PostgreSQL → Python-Worker → RustFS (S3)
```

## Features

- **Logo-Einbettung** — PNG/SVG-Logo wird per Raycast auf die Modelloberfläche projiziert und als Negativ-Einlage in die 3MF exportiert
- **Multi-Layer** — mehrere Logo-Lagen auf verschiedenen Tiefen, je mit eigener Farbe
- **3MF-Struktur-Parsing** — Bambu-Studio-3MF mit Druckplatten und Objekt-Selektion
- **Magnet-Bohrungen** — automatische oder manuelle Platzierung, erzeugt modifiziertes Modell + Bohrschablone
- **Bild → SVG** — Raster-Bild zu Vektor-Grafik via vtracer, inkl. RGBA-PNG für den Druck
- **Job-Templates** — wiederverwendbare Druck-Konfigurationen
- **3D-Bibliothek** — Modelle hochladen, benennen und wiederverwenden

## Stack

| Schicht | Technologie |
|---------|-------------|
| Frontend | PHP 8.3, Apache |
| Datenbank | PostgreSQL 16 |
| Worker | Python 3.12 |
| Objekt-Speicher | RustFS (S3-kompatibel) |
| Orchestrierung | Docker Compose |

## Schnellstart

### Voraussetzungen

- Docker + Docker Compose

### Setup

```bash
# Repository klonen
git clone <repo-url>
cd HEK-3D-Worker

# Umgebungsvariablen einrichten
cp .env.example .env
# .env nach Bedarf anpassen

# Alles starten
docker compose up -d

# Datenbank-Migrationen ausführen
docker compose exec -T postgres psql -U hek3d_user -d hek3d \
  -f - < database/migrations/001_initial_schema.sql
# ... 002 bis 005 analog
```

Die Anwendung ist dann erreichbar unter:

| Dienst | URL |
|--------|-----|
| Web-UI | http://localhost:22200 |
| PostgreSQL | localhost:22201 |
| RustFS API | http://localhost:22202 |
| RustFS Console | http://localhost:22203 |

### Services neu starten

```bash
docker compose restart php worker
```

### Logs anschauen

```bash
docker compose logs -f worker
docker compose logs -f php
```

## Projektstruktur

```
HEK-3D-Worker/
├── docker-compose.yml
├── .env.example
├── docker/
│   ├── php/
│   │   ├── Dockerfile
│   │   └── vhost.conf
│   ├── worker/
│   │   └── Dockerfile
│   └── postgres/
│       └── init.sql
├── database/
│   └── migrations/
│       ├── 001_initial_schema.sql
│       ├── 002_placement.sql
│       ├── 003_model_color.sql
│       ├── 004_logo_color.sql
│       └── 005_model_name.sql
├── frontend/
│   ├── composer.json
│   ├── config/
│   ├── public/
│   │   ├── index.php          # Router
│   │   └── assets/js/
│   ├── src/
│   │   ├── Controllers/
│   │   └── Services/
│   └── templates/
│       ├── layouts/
│       └── pages/
└── worker/
    ├── requirements.txt
    └── src/
        ├── main.py
        └── processors/
            ├── job_processor.py
            ├── magnet_job_processor.py
            └── svg_converter.py
```

## Dokumentation

| Dokument | Inhalt |
|----------|--------|
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | System-Architektur und Datenfluss |
| [docs/DATABASE.md](docs/DATABASE.md) | Datenbank-Schema (alle Tabellen) |
| [docs/API.md](docs/API.md) | HTTP-Routen und Parameter |
| [docs/WORKER.md](docs/WORKER.md) | Worker-Pipelines im Detail |
| [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) | Entwicklungs-Guide & Migrationen |

## Lizenz

Intern — HEK
