-- Migration 001: Initial schema

CREATE TABLE IF NOT EXISTS logos (
    id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    filename    TEXT         NOT NULL,
    storage_key TEXT         NOT NULL UNIQUE,
    uploaded_at TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS models (
    id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    filename    TEXT         NOT NULL,
    format      VARCHAR(10)  NOT NULL CHECK (format IN ('stl', 'obj', 'ply', 'glb', 'gltf')),
    storage_key TEXT         NOT NULL UNIQUE,
    uploaded_at TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS jobs (
    id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    status      VARCHAR(20)  NOT NULL DEFAULT 'pending'
                             CHECK (status IN ('pending', 'processing', 'done', 'error')),
    logo_key    TEXT         NOT NULL,
    model_key   TEXT         NOT NULL,
    output_key  TEXT,
    error       TEXT,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_jobs_status ON jobs (status);
