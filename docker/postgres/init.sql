CREATE TABLE IF NOT EXISTS jobs (
    id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    status      VARCHAR(20)  NOT NULL DEFAULT 'pending',
    logo_key    TEXT         NOT NULL,
    model_key   TEXT         NOT NULL,
    output_key  TEXT,
    error       TEXT,
    placement   JSONB,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS logos (
    id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    filename    TEXT         NOT NULL,
    storage_key TEXT         NOT NULL,
    uploaded_at TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS models (
    id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    filename    TEXT         NOT NULL,
    format      VARCHAR(10)  NOT NULL CHECK (format IN ('stl','obj','ply','glb','gltf','3mf')),
    storage_key TEXT         NOT NULL,
    uploaded_at TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS magnet_jobs (
    id               UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    status           VARCHAR(20)  NOT NULL DEFAULT 'pending',
    model_key        TEXT         NOT NULL,
    magnet_diameter  FLOAT        NOT NULL DEFAULT 6.0,
    magnet_length    FLOAT        NOT NULL DEFAULT 2.0,
    n_magnets        INTEGER      NOT NULL DEFAULT 4,
    face_selection   JSONB,
    output_key       TEXT,
    template_key     TEXT,
    error            TEXT,
    created_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);
