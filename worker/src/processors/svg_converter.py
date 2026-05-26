import io
import os
import time
import logging
import tempfile

import numpy as np
import psycopg2
import boto3
from PIL import Image
import vtracer
from dotenv import load_dotenv

load_dotenv()
log = logging.getLogger(__name__)

_SUPPORTED = {"png", "jpg", "jpeg", "webp", "bmp", "gif", "tiff"}


class SvgConverterProcessor:
    def __init__(self) -> None:
        self.conn = self._connect_with_retry()
        self.s3 = boto3.client(
            "s3",
            endpoint_url=os.getenv("RUSTFS_ENDPOINT", "http://rustfs:9000"),
            aws_access_key_id=os.getenv("RUSTFS_ACCESS_KEY"),
            aws_secret_access_key=os.getenv("RUSTFS_SECRET_KEY"),
        )
        self.bucket_logos  = os.getenv("RUSTFS_BUCKET_LOGOS",  "logos")
        self.bucket_output = os.getenv("RUSTFS_BUCKET_OUTPUT", "output")
        self._ensure_buckets()

    # ── Bucket / DB boilerplate ───────────────────────────────────────────────

    def _ensure_buckets(self) -> None:
        for bucket in (self.bucket_logos, self.bucket_output):
            try:
                self.s3.head_bucket(Bucket=bucket)
            except Exception:
                try:
                    self.s3.create_bucket(Bucket=bucket)
                except Exception as e:
                    log.warning(f"Could not create bucket '{bucket}': {e}")

    def _connect_with_retry(self, retries: int = 10, delay: float = 3.0):
        for attempt in range(1, retries + 1):
            try:
                conn = psycopg2.connect(
                    host=os.getenv("DB_HOST", "postgres"),
                    port=int(os.getenv("DB_PORT", 5432)),
                    dbname=os.getenv("DB_NAME", "hek3d"),
                    user=os.getenv("DB_USER", "hek3d_user"),
                    password=os.getenv("DB_PASSWORD", ""),
                    connect_timeout=10,
                    keepalives=1, keepalives_idle=30,
                    keepalives_interval=10, keepalives_count=5,
                )
                log.info("PostgreSQL connected (SvgConverter).")
                return conn
            except psycopg2.OperationalError as exc:
                log.warning(f"DB not ready ({attempt}/{retries}): {exc}")
                if attempt == retries:
                    raise
                time.sleep(delay)

    def _reconnect(self) -> None:
        try:
            self.conn.close()
        except Exception:
            pass
        self.conn = self._connect_with_retry()

    def _ensure_conn(self) -> None:
        if self.conn.closed:
            self._reconnect()
            return
        try:
            self.conn.cursor().execute("SELECT 1")
        except (psycopg2.OperationalError, psycopg2.InterfaceError):
            self._reconnect()

    # ── Job fetching ──────────────────────────────────────────────────────────

    def fetch_pending_job(self) -> dict | None:
        self._ensure_conn()
        try:
            return self._fetch()
        except (psycopg2.OperationalError, psycopg2.InterfaceError):
            self._reconnect()
            return self._fetch()

    def _fetch(self) -> dict | None:
        with self.conn.cursor() as cur:
            cur.execute(
                """
                UPDATE convert_jobs SET status = 'processing', updated_at = NOW()
                WHERE id = (
                    SELECT id FROM convert_jobs WHERE status = 'pending'
                    ORDER BY created_at LIMIT 1 FOR UPDATE SKIP LOCKED
                )
                RETURNING id, input_key, filename
                """
            )
            self.conn.commit()
            row = cur.fetchone()
            if row:
                return {"id": str(row[0]), "input_key": row[1], "filename": row[2]}
        return None

    # ── Main processing ───────────────────────────────────────────────────────

    def process(self, job: dict) -> None:
        try:
            ext = job["input_key"].rsplit(".", 1)[-1].lower()
            if ext not in _SUPPORTED:
                raise ValueError(f"Unsupported image format: {ext}")

            with tempfile.TemporaryDirectory() as tmp:
                input_path = os.path.join(tmp, f"input.{ext}")
                bw_path    = os.path.join(tmp, "bw.png")
                svg_path   = os.path.join(tmp, "output.svg")
                logo_path  = os.path.join(tmp, "logo.png")

                self.s3.download_file(self.bucket_logos, job["input_key"], input_path)

                # Build binary mask and save assets
                mask = self._build_mask(input_path)
                self._save_bw_png(mask, bw_path)
                self._save_logo_rgba(mask, logo_path)

                # vtracer writes SVG to file
                vtracer.convert_image_to_svg_py(
                    bw_path, svg_path,
                    colormode="binary",
                    hierarchical="stacked",
                    mode="spline",
                    filter_speckle=4,
                    corner_threshold=60,
                    length_threshold=4.0,
                    splice_threshold=45,
                    path_precision=3,
                )

                # Upload SVG to output bucket
                output_key = f"converted/{job['id']}.svg"
                self.s3.upload_file(svg_path, self.bucket_output, output_key)

                # Upload RGBA PNG to logos bucket so it can be used directly as a Print-On logo
                logo_key = f"svg_{job['id']}_logo.png"
                self.s3.upload_file(logo_path, self.bucket_logos, logo_key)

                self._update_job(job["id"], "done",
                                 output_key=output_key, logo_key=logo_key)
                log.info(f"SVG conversion done: {output_key}, logo: {logo_key}")

        except Exception as exc:
            log.error(f"Convert job {job['id']} failed: {exc}", exc_info=True)
            self._update_job(job["id"], "error", error=str(exc))

    # ── Image processing ──────────────────────────────────────────────────────

    @staticmethod
    def _build_mask(path: str) -> np.ndarray:
        """Return a boolean uint8 mask (255=logo ink, 0=background)."""
        img = Image.open(path)

        if img.mode == "RGBA":
            alpha = np.array(img)[:, :, 3]
            return (alpha > 128).astype(np.uint8) * 255
        else:
            gray = np.array(img.convert("L"))
            # Assume light background, dark ink; use adaptive threshold
            threshold = int(np.clip(gray.mean() * 0.9, 50, 220))
            return (gray < threshold).astype(np.uint8) * 255

    @staticmethod
    def _save_bw_png(mask: np.ndarray, path: str) -> None:
        """Black logo on white background — input for vtracer."""
        bw = Image.new("L", (mask.shape[1], mask.shape[0]), 255)
        bw.paste(Image.new("L", bw.size, 0), mask=Image.fromarray(mask))
        bw.save(path, format="PNG")

    @staticmethod
    def _save_logo_rgba(mask: np.ndarray, path: str) -> None:
        """Black logo on transparent background — ready for use as a Print-On logo."""
        h, w = mask.shape
        rgba = np.zeros((h, w, 4), dtype=np.uint8)
        rgba[:, :, 3] = mask          # alpha = mask
        # RGB stays 0 (black) — the job processor uses alpha only
        Image.fromarray(rgba, "RGBA").save(path, format="PNG")

    # ── DB update ─────────────────────────────────────────────────────────────

    def _update_job(self, job_id: str, status: str, *,
                    output_key: str = None, logo_key: str = None,
                    error: str = None) -> None:
        self._ensure_conn()
        with self.conn.cursor() as cur:
            cur.execute(
                "UPDATE convert_jobs SET status=%s, output_key=%s, logo_key=%s, "
                "error=%s, updated_at=NOW() WHERE id=%s",
                (status, output_key, logo_key, error, job_id),
            )
            self.conn.commit()
