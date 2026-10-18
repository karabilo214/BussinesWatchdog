# ADR 0063: Image upload in the page editor

Status: implemented (Step 169), made configurable with compression and optional PDF in Step 170 by the owner's decision of 2026-10-18.

## Context

The page editor (ADR 0045) took images only as an https address. The owner wants to upload a file from the panel: storage, size and type limits, metadata removed.

## Decision

1. **Formats:** JPEG, PNG and WebP only, recognised by the file content (`getimagesize`), not by the name or the browser's type. SVG is refused (it can carry scripts), GIF and animated images too (they would have to be re-encoded frame by frame).
2. **Limits:** at most `WATCHDOG_SITE_MEDIA_MAX_BYTES` (default 5 MiB), 6000 px per side and 16 megapixels — keeps decoding within the PHP memory limit and stops decompression bombs.
3. **Metadata:** every image is decoded and encoded again with GD (JPEG and WebP quality 85, PNG lossless with transparency). EXIF/XMP/IPTC (camera, GPS, author), comments and anything appended to the file are dropped. A JPEG's EXIF orientation is applied before, so phone photos are not sideways.
4. **Storage:** the private artifacts disk (S3; `WATCHDOG_SITE_MEDIA_DISK` to choose another), key `site-media/{uuid}.{ext}`, row in `site_media` (type, size, width, height, sha256 of the stored file, original name, who uploaded it).
5. **Address:** the public site serves `/media/{uuid}.{ext}` (`Cache-Control: public, max-age=31536000, immutable`, `nosniff`, `Content-Security-Policy: default-src 'none'; sandbox`). Pages keep this relative address, so a domain change does not break them; the panel runs on the same origin (`APP_URL/owner`), the editor and the preview show it directly. The address of a file never changes: an edited image is a new upload.
6. **Library and deletion:** the image panel of the editor uploads, lists the last 200 images to pick from, and deletes. Deleting is refused (409 `media_in_use`) while any page version — published, draft or archived — or an active block template contains the address; the file is then removed. Upload and deletion are in the platform audit with the content hash. Capability `content.manage`.
7. **Infrastructure:** the backend image gets the `gd` (JPEG, PNG, WebP) and `exif` extensions.

## Consequences

- An uploaded image is public as soon as its address is known (the id is a random UUID v7, not listed anywhere public) — like any image on the site.
- No image resizing or responsive variants yet; the owner uploads an image in a reasonable size.
- Deleting an old page version (Step 139) frees its images for deletion.

## Owner decision (2026-10-18)

- Formats and limits are configurable by the owner, not fixed: `WATCHDOG_SITE_MEDIA_TYPES` (`jpeg,png,webp`, optionally `pdf`), `WATCHDOG_SITE_MEDIA_MAX_BYTES` (5 MiB), `WATCHDOG_SITE_MEDIA_MAX_SIDE` (6000), `WATCHDOG_SITE_MEDIA_MAX_PIXELS` (16 000 000). The panel shows the limits in force from the server.
- Images are compressed: made smaller to `WATCHDOG_SITE_MEDIA_RESIZE_MAX_SIDE` (default 2560 px on the longer side, 0 keeps the size) and encoded with `WATCHDOG_SITE_MEDIA_JPEG_QUALITY` / `WATCHDOG_SITE_MEDIA_WEBP_QUALITY` (82) and `WATCHDOG_SITE_MEDIA_PNG_COMPRESSION` (9, lossless).
- PDF may be allowed later; it must not break anything. Implementation (Step 170): with `pdf` in the list a PDF is recognised by its `%PDF-` header, must end with `%%EOF`, is stored as it is (PHP cannot rewrite it, so its own metadata stays — the panel says so), served with a sandbox CSP and inserted into a page as a link. Without `pdf` it is refused like any other format.
- Not failing: the effective size limit is never above PHP's `upload_max_filesize`/`post_max_size` (a request over them answers 413 `media_too_large`, not a server error); before decoding, the memory an image needs is checked against `memory_limit` (422 `media_too_large_for_server` instead of a fatal out-of-memory); a missing GD format answers 503 `media_processing_unavailable`, unreachable storage 503 `media_storage_unavailable`; damaged files 422 `media_unreadable`. The backend image sets `upload_max_filesize=32M`, `post_max_size=34M`, `memory_limit=256M` (`infra/docker/backend/php-uploads.ini`), nginx accepts 34 MB; to allow more, raise those together with `WATCHDOG_SITE_MEDIA_MAX_BYTES`.

