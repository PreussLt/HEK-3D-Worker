# Worker — Verarbeitungs-Pipelines

Der Worker (`worker/src/main.py`) startet beim Container-Start drei Processor-Threads, die unabhängig die Datenbank pollen.

## Gemeinsames Polling-Muster

```python
# Alle 5 Sekunden:
SELECT * FROM <tabelle>
WHERE status = 'pending'
ORDER BY created_at
LIMIT 1
FOR UPDATE SKIP LOCKED
```

`SKIP LOCKED` erlaubt mehrere Worker-Instanzen ohne doppelte Verarbeitung. Nach dem Fetch wird `status = 'processing'` gesetzt, dann die eigentliche Arbeit getan, danach `status = 'done'` + `output_key`. Bei jeder Exception: `status = 'error'` + Stacktrace in `error`.

---

## 1. JobProcessor — Logo-Einbettung

**Quelldatei:** `worker/src/processors/job_processor.py`

Verarbeitet Einträge aus der `jobs`-Tabelle.

### Pipeline

```
Modell laden
    ↓
Logo-PNG(s) laden
    ↓
für jeden Layer:
    Koordinaten transformieren
    → Raycast: Logo auf Oberfläche projizieren
    → Dual-Layer-Mesh erzeugen (Kappe + Einlage)
    → Taubin-Glättung (15 Iterationen)
    ↓
Supports generieren (optional)
    ↓
Brim generieren (optional)
    ↓
3MF-Datei zusammenbauen
    ↓
RustFS hochladen
```

### Modell laden (`_load_mesh`)

- **3MF**: ZIP öffnen, `.model`-XML parsen (mit und ohne Namespace), Vertices + Triangles lesen
  - **Platten-Filter** (`plate_index`): liest `Metadata/model_settings.config` aus dem BambuStudio-3MF, extrahiert Objekt-IDs der gewählten Platte
  - **Objekt-Filter** (`filter_objects`): BFS-Expansion über Component-Referenzen, nur gewählte Objekte mergen
- **Andere Formate**: `trimesh.load()`, Scene → Trimesh-Konvertierung bei Bedarf

### Koordinaten-Transformation

Der Browser sendet Positionen in Viewer-Koordinaten (normalisiert + zentriert). Der Worker transformiert zurück:

```
mesh_point = (viewer_point / scale) + center
```

`center` und `scale` kommen aus `placement.viewer_transform`. Fehlt das Feld, wird eine Schätzung aus der Bounding-Box berechnet.

### Logo-Projektion (`_raycast_logo_onto_surface`)

1. PNG-Bild laden, in binäre Maske umwandeln (Gauss-Blur für Anti-Aliasing)
2. Bildpixel in 3D-Positionen umrechnen (tangential zur Oberfläche)
3. Batch-Raycast senkrecht zur Oberfläche: `trimesh.ray.intersects_location()`
4. Für jeden Treffer:
   - **Kappe** (top cap): dünnes Dreieck auf der Oberfläche
   - **Einlage** (inlay): Extrusion nach innen bis zur konfigurierten Tiefe
   - Wanddicken-Schutz: Tiefe wird auf 80 % der lokalen Wanddicke begrenzt
5. Taubin-Glättung des Logo-Meshs (15 Iterationen, λ=0.5, μ=−0.53)

### Support-Generierung (`_generate_supports`)

- Überhang-Flächen: Winkel > `support_angle` zur Z-Achse
- Grid-Sampling mit 3.0 mm Abstand, Deduplizierung
- Zylindrische Stützen (6-seitig, r=0.5 mm)
- Prüft, ob bereits Material darunter liegt
- Manifold3d Boolean-Differenz vom Modell-Mesh

### Brim-Generierung (`_generate_brim`)

- Schnittkontur bei Z_min + 0.05 mm
- Shapely-Buffer um `brim_width` mm
- Extrusion um eine Schichtdicke (`layer_height`)

### 3MF-Export (`_write_3mf`)

Die fertige 3MF ist eine ZIP-Datei mit:

```
[Content_Types].xml
_rels/.rels
3D/3dmodel.model
```

Objekte in der 3MF:

| Objekt | Name in BambuStudio | Filament |
|--------|---------------------|---------|
| Grundkörper | `Grundkoerper - Filament 1` | 1 |
| Logo-Einlage n | `Logo-Einlage n - Filament 2` | 2 |
| Stützen | `Stuetzen - Filament 1` | 1 |
| Brim | `Brim - Filament 1` | 1 |

---

## 2. MagnetJobProcessor — Bohrlocherzeugung

**Quelldatei:** `worker/src/processors/magnet_job_processor.py`

Verarbeitet Einträge aus der `magnet_jobs`-Tabelle.

### Pipeline

```
Modell laden
    ↓
Magnet-Positionen bestimmen
    ↓
Bohrlöcher fräsen (Boolean-Differenz)
    ↓
Bohrschablone erzeugen
    ↓
Beide STLs hochladen
```

### Positions-Bestimmung (`_find_magnet_positions`)

**Manueller Modus** (`face_selection` vorhanden):
- Wenn `positions_2d` gesetzt: Browser-Positionen direkt übernehmen
- Sonst: passendes Facet per Scoring suchen (Normal-Ausrichtung, Abstand, Fläche)
- Tangenten-Frame aus Normal berechnen, 2D-Positionen → 3D

**Auto-Modus**:
- Alle Mesh-Facetten berechnen
- Filter: Mindestfläche ≥ 3π × (d/2)²
- Größte geeignete flache Fläche auswählen
- Konvexe Hülle berechnen
- `n_magnets` Punkte gleichmäßig am Rand verteilen, 1,5× d nach innen eingerückt
- Deduplizierung bei Abstand < 1,2× d

### Bohren (`_drill_holes`)

- Für jede Position: Zylinder mit `diameter` mm Durchmesser und `length` mm Tiefe erstellen
- Zylinder ragt 1,5 mm über die Oberfläche (für sauberen Boolean)
- `manifold3d` Boolean-Differenz: Modell − Zylinder
- Mesh-Reparatur vor Boolean: `fill_holes()`, `fix_normals()`

### Bohrschablone (`_build_template`)

- 3 mm dicke Platte in XY-Ebene
- Durchgangsbohrungen an den Magnet-Positionen
- Rand: 2,5× Magnet-Durchmesser
- Zum Ausdrucken und als Bohrführung verwenden

### Ausgabe

| Datei | Storage-Key | Beschreibung |
|-------|-------------|--------------|
| Modifiziertes Modell | `output/{id}_magnets.stl` | Modell mit Bohrlöchern |
| Bohrschablone | `output/{id}_template.stl` | 3D-druckbare Führungsschablone |

---

## 3. SvgConverterProcessor — Raster → SVG

**Quelldatei:** `worker/src/processors/svg_converter.py`

Verarbeitet Einträge aus der `convert_jobs`-Tabelle.

### Pipeline

```
Bild laden (Pillow)
    ↓
Ink-Maske berechnen
    ↓
S/W-PNG für vtracer erstellen
    ↓
RGBA-PNG erstellen (transparenter Hintergrund)
    ↓
vtracer: PNG → SVG
    ↓
Alle Dateien hochladen
```

### Ink-Maske (`_build_mask`)

- **RGBA-Bild**: Alpha-Kanal > 128 → Tinte
- **Grayscales**: Adaptiver Schwellwert (90 % des Mittelwerts, Klemme 50–220)
- Ausgabe: Binär-Array (255 = Tinte, 0 = Hintergrund)

### SVG-Vektorisierung (vtracer)

```python
vtracer.convert_image_to_svg_py(
    colormode        = "binary",
    hierarchical     = "stacked",
    mode             = "spline",
    filter_speckle   = 4,
    corner_threshold = 60,
    length_threshold = 4.0,
    splice_threshold = 45,
    path_precision   = 3,
)
```

### Ausgabe

| Datei | Beschreibung |
|-------|--------------|
| `output/{id}.svg` | Vektorgrafik |
| `logos/svg_{id}_logo.png` | RGBA-PNG — direkt als Logo für Print-Jobs nutzbar |

---

## Python-Abhängigkeiten

| Paket | Version | Verwendung |
|-------|---------|------------|
| `trimesh` | 4.3.1 | 3D-Mesh-Operationen |
| `manifold3d` | ≥2.3.0 | Robuste Boolean-Operationen |
| `Pillow` | 10.3.0 | Bildverarbeitung |
| `numpy` | 1.26.4 | Vektorisierte Mathematik |
| `scipy` | ≥1.10.0 | Wissenschaftliche Berechnungen |
| `shapely` | ≥2.0.0 | 2D-Geometrie (Brim) |
| `rtree` | ≥1.0.0 | Spatial-Index |
| `vtracer` | ≥0.6.0 | SVG-Vektorisierung |
| `psycopg2-binary` | 2.9.9 | PostgreSQL-Treiber |
| `boto3` | 1.34.0 | S3/RustFS-Client |
| `python-dotenv` | 1.0.1 | `.env`-Laden |
