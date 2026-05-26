# HTTP-Routen

Der Router liegt in `frontend/public/index.php`. Alle Routen liefern HTML (keine REST-JSON-API), außer `/api/file`.

## Print-Jobs

### `GET /`
Listet alle Jobs (Tabelle mit ID, Status, Logo, Modell, Datum).

### `GET /jobs/new`
Zeigt das Job-Erstellungs-Formular (3D-Editor).

Query-Parameter:

| Parameter | Typ | Beschreibung |
|-----------|-----|--------------|
| `model_id` | UUID | Modell vorauswählen |
| `logo_key` | string | Logo-Key vorausfüllen |
| `template_id` | UUID | Template laden (Farbe, Layer, Print-Settings) |

### `POST /jobs/new`
Erstellt einen neuen Job.

Form-Felder:

| Feld | Typ | Beschreibung |
|------|-----|--------------|
| `model_id` | UUID | Pflichtfeld — gewähltes Modell |
| `model_color` | `#rrggbb` | Modell-Farbe (Standard: `#4a9eff`) |
| `layers_config` | JSON-String | Multi-Layer-Array (optional) |
| `layer_file_{n}` | file | Logo-Datei für Layer n |
| `logo` | file | Legacy: einzelnes Logo (wenn kein layers_config) |
| `existing_logo_key` | string | Bereits hochgeladenes Logo verwenden |
| `placement` | JSON-String | Logo-Platzierung (Legacy) |
| `support_mode` | `none`/`auto`/`everywhere` | Standard: `auto` |
| `support_angle` | int 20–70 | Standard: 45 |
| `brim_width` | float 0–20 | Standard: 5.0 |
| `layer_height` | `0.10`–`0.30` | Standard: `0.20` |

Redirect nach `/jobs/{id}` bei Erfolg.

### `GET /jobs/{id}`
Job-Detail: Status, 3D-Vorschau (wenn done), Download-Button.

---

## Modell-Bibliothek

### `GET /models`
Grid-Ansicht aller hochgeladenen Modelle mit 3D-Vorschau.

### `GET /models/new`
Upload-Formular.

### `POST /models/new`
Lädt ein Modell hoch.

Form-Felder:

| Feld | Typ | Beschreibung |
|------|-----|--------------|
| `model` | file | Pflichtfeld — STL, OBJ, PLY, GLB, GLTF oder 3MF |
| `name` | string | Benutzerdefinierter Name (= späterer Download-Dateiname) |
| `filter_objects` | JSON-String | Array von Objekt-IDs (aus 3MF-Struktur) |

Redirect nach `/models` bei Erfolg.

### `POST /models/{id}/delete`
Löscht Modell-Datensatz aus der DB.

---

## Magnet-Jobs

### `GET /magnet`
Listet alle Magnet-Jobs.

### `GET /magnet/new`
Formular zur Erstellung eines Magnet-Jobs (3D-Face-Selector).

### `POST /magnet/new`
Erstellt einen Magnet-Job.

Form-Felder:

| Feld | Typ | Beschreibung |
|------|-----|--------------|
| `model_id` | UUID | Pflichtfeld |
| `magnet_diameter` | float | Standard: 6.0 mm |
| `magnet_length` | float | Standard: 2.0 mm |
| `n_magnets` | int | Standard: 4, max: 8 |
| `face_selection` | JSON-String | Manuell gewählte Fläche (optional) |

### `GET /magnet/{id}`
Detail-Ansicht: Status, Download für modifiziertes Modell und Bohrschablone.

---

## Bild-Konvertierung

### `GET /convert`
Listet alle Konvertierungs-Jobs.

### `POST /convert`
Startet eine Konvertierung.

Form-Felder:

| Feld | Typ | Beschreibung |
|------|-----|--------------|
| `image` | file | PNG, JPG, WebP, BMP, GIF oder TIFF |

### `GET /convert/{id}`
Detail: Status, SVG-Download, RGBA-PNG-Download.

---

## 3D-Editor

### `GET /editor`
Freier 3D-Editor (editor3d.js).

### `POST /editor/save`
Speichert ein bearbeitetes Modell.

---

## Templates

### `GET /templates`
Listet alle Job-Templates.

### `POST /templates/new`
Erstellt ein Template.

Form-Felder:

| Feld | Typ | Beschreibung |
|------|-----|--------------|
| `name` | string | Pflichtfeld |
| `model_id` | UUID | Verknüpftes Modell |
| `model_color` | `#rrggbb` | |
| `layers` | JSON-String | Layer-Konfiguration |
| `print_settings` | JSON-String | Druckparameter |

### `POST /templates/{id}/delete`
Löscht Template.

---

## Datei-Streaming

### `GET /api/file?bucket={bucket}&key={key}`

Streamt eine Datei direkt aus RustFS an den Browser.

| Parameter | Erlaubte Werte |
|-----------|---------------|
| `bucket` | `models`, `logos`, `output` |
| `key` | Storage-Key der Datei |

Wird intern für 3D-Vorschauen und Downloads verwendet. Gibt HTTP 400 bei unbekanntem Bucket zurück.
