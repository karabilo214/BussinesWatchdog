# ADR 0063: Image upload in the page editor

Status: implemented (Step 169); the owner asked for the upload on 2026-10-16 (ADR 0045 owner decision). The limits and the formats below were chosen by the implementer and wait for the owner's confirmation.

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
