-- Migration 002: Add placement JSONB to jobs

ALTER TABLE jobs ADD COLUMN IF NOT EXISTS placement JSONB;
