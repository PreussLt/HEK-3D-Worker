import time
import logging
import psycopg2
import os
from dotenv import load_dotenv

from processors.job_processor import JobProcessor
from processors.magnet_job_processor import MagnetJobProcessor
from processors.svg_converter import SvgConverterProcessor

load_dotenv()
logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
log = logging.getLogger(__name__)

MIGRATION_SQL = """
CREATE TABLE IF NOT EXISTS convert_jobs (
    id         UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    status     VARCHAR(20)  NOT NULL DEFAULT 'pending',
    input_key  TEXT         NOT NULL,
    filename   TEXT         NOT NULL DEFAULT '',
    output_key TEXT,
    logo_key   TEXT,
    error      TEXT,
    created_at TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);
DO $$ BEGIN
    ALTER TABLE convert_jobs ADD COLUMN IF NOT EXISTS logo_key TEXT;
EXCEPTION WHEN others THEN NULL;
END $$;
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
-- Add columns to existing tables (idempotent via DO block)
DO $$ BEGIN
    ALTER TABLE magnet_jobs ADD COLUMN IF NOT EXISTS n_magnets       INTEGER NOT NULL DEFAULT 4;
    ALTER TABLE magnet_jobs ADD COLUMN IF NOT EXISTS face_selection   JSONB;
    ALTER TABLE models      ADD COLUMN IF NOT EXISTS plate_index     INTEGER;
    ALTER TABLE jobs        ADD COLUMN IF NOT EXISTS plate_index     INTEGER;
    ALTER TABLE models      ADD COLUMN IF NOT EXISTS filter_objects  TEXT;
    ALTER TABLE jobs        ADD COLUMN IF NOT EXISTS filter_objects  TEXT;
    ALTER TABLE jobs        ADD COLUMN IF NOT EXISTS layers          JSONB;
    ALTER TABLE jobs        ADD COLUMN IF NOT EXISTS print_settings  JSONB;
EXCEPTION WHEN others THEN NULL;
END $$;
CREATE TABLE IF NOT EXISTS job_templates (
    id             UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
    name           TEXT        NOT NULL,
    model_id       UUID,
    model_color    VARCHAR(7)  NOT NULL DEFAULT '#4a9eff',
    layers         JSONB,
    print_settings JSONB,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
"""


def _run_migration() -> None:
    """Ensure magnet_jobs table exists (idempotent migration for existing DBs)."""
    for attempt in range(10):
        try:
            conn = psycopg2.connect(
                host=os.getenv("DB_HOST", "postgres"),
                port=int(os.getenv("DB_PORT", 5432)),
                dbname=os.getenv("DB_NAME", "hek3d"),
                user=os.getenv("DB_USER", "hek3d_user"),
                password=os.getenv("DB_PASSWORD", ""),
                connect_timeout=10,
            )
            with conn.cursor() as cur:
                cur.execute(MIGRATION_SQL)
            conn.commit()
            conn.close()
            log.info("DB migration complete.")
            return
        except psycopg2.OperationalError as exc:
            log.warning(f"Migration: DB not ready ({attempt + 1}/10): {exc}")
            time.sleep(3)
    raise RuntimeError("Could not run DB migration after 10 attempts")


def main() -> None:
    _run_migration()

    logo_processor    = JobProcessor()
    magnet_processor  = MagnetJobProcessor()
    convert_processor = SvgConverterProcessor()

    log.info("Worker started, polling for jobs...")

    while True:
        handled = False

        job = logo_processor.fetch_pending_job()
        if job:
            log.info(f"Logo job {job['id']}")
            logo_processor.process(job)
            handled = True

        mjob = magnet_processor.fetch_pending_job()
        if mjob:
            log.info(f"Magnet job {mjob['id']}")
            magnet_processor.process(mjob)
            handled = True

        cjob = convert_processor.fetch_pending_job()
        if cjob:
            log.info(f"Convert job {cjob['id']}")
            convert_processor.process(cjob)
            handled = True

        if not handled:
            time.sleep(5)


if __name__ == "__main__":
    main()
