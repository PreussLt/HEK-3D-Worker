# Datenbank-Schema

PostgreSQL 16. Alle IDs sind UUIDs (`gen_random_uuid()`).

## Tabellen

### `jobs` — Print-Jobs

| Spalte | Typ | Standard | Beschreibung |
|--------|-----|----------|--------------|
| `id` | UUID PK | `gen_random_uuid()` | |
| `status` | VARCHAR(20) | `pending` | `pending` / `processing` / `done` / `error` |
| `logo_key` | TEXT NOT NULL | | Storage-Key des Logos (bucket: logos) |
| `model_key` | TEXT NOT NULL | | Storage-Key des Modells (bucket: models) |
| `output_key` | TEXT | NULL | Storage-Key der fertigen 3MF (bucket: output) |
| `error` | TEXT | NULL | Fehlermeldung + Stacktrace bei status=error |
| `placement` | JSONB | NULL | Logo-Position (siehe unten) |
| `model_color` | VARCHAR(7) | `#4a9eff` | Hex-Farbe für das Modell |
| `logo_color` | VARCHAR(7) | `#ffffff` | Hex-Farbe für das Logo (Legacy) |
| `plate_index` | INTEGER | NULL | BambuStudio-Platte (0-basiert) |
| `filter_objects` | TEXT | NULL | JSON-Array mit Objekt-IDs für 3MF-Filterung |
| `layers` | JSONB | NULL | Multi-Layer-Konfiguration (Array) |
| `print_settings` | JSONB | NULL | Druckparameter (siehe unten) |
| `created_at` | TIMESTAMPTZ | `NOW()` | |
| `updated_at` | TIMESTAMPTZ | `NOW()` | |

Index: `idx_jobs_status` auf `(status)`

**`placement` JSONB-Struktur:**
```json
{
  "position":         [x, y, z],
  "normal":           [nx, ny, nz],
  "size":             [w, h],
  "rotation":         deg,
  "viewer_transform": { "center": [x,y,z], "scale": s }
}
```

**`layers` JSONB-Struktur:**
```json
[
  {
    "key":       "logos/...",
    "color":     "#ffffff",
    "placement": { ...wie placement... }
  }
]
```

**`print_settings` JSONB-Struktur:**
```json
{
  "support_mode":  "none" | "auto" | "everywhere",
  "support_angle": 20-70,
  "brim_width":    0.0-20.0,
  "layer_height":  0.10 | 0.15 | 0.20 | 0.25 | 0.30
}
```

---

### `models` — 3D-Modell-Bibliothek

| Spalte | Typ | Standard | Beschreibung |
|--------|-----|----------|--------------|
| `id` | UUID PK | `gen_random_uuid()` | |
| `filename` | TEXT NOT NULL | | Originaler Dateiname beim Upload |
| `name` | TEXT | NULL | Benutzerdefinierter Name (= Download-Dateiname) |
| `format` | VARCHAR(10) NOT NULL | | `stl` / `obj` / `ply` / `glb` / `gltf` / `3mf` |
| `storage_key` | TEXT NOT NULL UNIQUE | | Storage-Key (bucket: models) |
| `plate_index` | INTEGER | NULL | Vorausgewählte BambuStudio-Platte |
| `filter_objects` | TEXT | NULL | JSON-Array vorausgewählter Objekt-IDs |
| `uploaded_at` | TIMESTAMPTZ | `NOW()` | |

Hinweis: `name` wird bevorzugt angezeigt; fehlt er, fällt die UI auf `filename` zurück. Beim Download eines Job-Ergebnisses wird `name` (ohne Extension) + `.3mf` als Dateiname verwendet.

---

### `logos` — Logo-Bibliothek

| Spalte | Typ | Standard | Beschreibung |
|--------|-----|----------|--------------|
| `id` | UUID PK | `gen_random_uuid()` | |
| `filename` | TEXT NOT NULL | | Originaler Dateiname |
| `storage_key` | TEXT NOT NULL UNIQUE | | Storage-Key (bucket: logos) |
| `uploaded_at` | TIMESTAMPTZ | `NOW()` | |

---

### `job_templates` — Wiederverwendbare Druckkonfigurationen

| Spalte | Typ | Standard | Beschreibung |
|--------|-----|----------|--------------|
| `id` | UUID PK | `gen_random_uuid()` | |
| `name` | TEXT NOT NULL | | Anzeigename |
| `model_id` | UUID FK | NULL | Verknüpftes Modell (→ models.id) |
| `model_color` | VARCHAR(7) | `#4a9eff` | |
| `layers` | JSONB | NULL | Vorkonfigurierter Layer-Stack |
| `print_settings` | JSONB | NULL | Vorkonfigurierte Druckparameter |
| `created_at` | TIMESTAMPTZ | `NOW()` | |

---

### `magnet_jobs` — Magnet-Bohrjobs

| Spalte | Typ | Standard | Beschreibung |
|--------|-----|----------|--------------|
| `id` | UUID PK | `gen_random_uuid()` | |
| `status` | VARCHAR(20) | `pending` | `pending` / `processing` / `done` / `error` |
| `model_key` | TEXT NOT NULL | | Storage-Key des Quell-Modells |
| `magnet_diameter` | FLOAT | `6.0` | Durchmesser der Magnete in mm |
| `magnet_length` | FLOAT | `2.0` | Tiefe der Bohrlöcher in mm |
| `n_magnets` | INTEGER | `4` | Anzahl Löcher (1–8) |
| `face_selection` | JSONB | NULL | Manuell gewählte Fläche (siehe unten) |
| `output_key` | TEXT | NULL | Modell mit Bohrlöchern (bucket: output) |
| `template_key` | TEXT | NULL | Bohrschablone STL (bucket: output) |
| `error` | TEXT | NULL | |
| `created_at` | TIMESTAMPTZ | `NOW()` | |
| `updated_at` | TIMESTAMPTZ | `NOW()` | |

**`face_selection` JSONB-Struktur:**
```json
{
  "normal":           [nx, ny, nz],
  "centroid":         [x, y, z],
  "tangent":          [tx, ty, tz],
  "bitangent":        [bx, by, bz],
  "positions_2d":     [[u1,v1], [u2,v2], ...],
  "viewer_transform": { "center": [x,y,z], "scale": s }
}
```

---

### `convert_jobs` — Bild-zu-SVG-Konvertierung

| Spalte | Typ | Standard | Beschreibung |
|--------|-----|----------|--------------|
| `id` | UUID PK | `gen_random_uuid()` | |
| `status` | VARCHAR(20) | `pending` | `pending` / `processing` / `done` / `error` |
| `input_key` | TEXT NOT NULL | | Eingabe-Bild (bucket: logos) |
| `filename` | TEXT NOT NULL | | Originaler Dateiname |
| `output_key` | TEXT | NULL | SVG-Ergebnis (bucket: output) |
| `logo_key` | TEXT | NULL | RGBA-PNG für den Druck (bucket: logos) |
| `error` | TEXT | NULL | |
| `created_at` | TIMESTAMPTZ | `NOW()` | |
| `updated_at` | TIMESTAMPTZ | `NOW()` | |

---

## Migrationen

Migrationen liegen in `database/migrations/` und werden manuell ausgeführt:

```bash
docker compose exec -T postgres psql -U hek3d_user -d hek3d \
  -f - < database/migrations/005_model_name.sql
```

| Datei | Inhalt |
|-------|--------|
| `001_initial_schema.sql` | Basistabellen (jobs, logos, models, magnet_jobs) |
| `002_placement.sql` | `jobs.placement` JSONB-Spalte |
| `003_model_color.sql` | `jobs.model_color`, Erweiterung der jobs-Tabelle |
| `004_logo_color.sql` | `jobs.logo_color` |
| `005_model_name.sql` | `models.name` — benutzerdefinierter Modellname |
