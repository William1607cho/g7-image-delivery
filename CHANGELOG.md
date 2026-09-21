# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.0] - 2026-09-21

### Added

- A 240px-wide variant for board list thumbnails, and a rewrite of the list API's `thumbnail`
  field to point at it. List thumbnails are drawn in an 80x80 CSS px box, but until now the list
  sent the untouched original — on one board's first page that was 3.5 MiB of image for eight
  80x80 squares, with sources as large as 2000px wide. The `thumbnail` field is replaced only when
  its value is exactly an editor image address and a ready 240 variant exists for that hash;
  external addresses, attachment previews, `null` and anything else are left alone, and a hash with
  no 240 variant keeps the original address. One query per response, regardless of how many rows
  it holds.

  Post bodies are untouched. Bodies still use the 960 variant as `src` with 960 and 1600 in
  `srcset`; the 240 variant never appears there.

- `--backfill-width=<width>` on `g7-image-delivery:build-variants`, with `--dry-run`, `--limit`
  and a progress bar. Regular batches decide per original whether there is anything left to do, so
  an original that already has a 960 variant is never revisited — adding a width to the plan would
  not, on its own, produce a single new file for existing images. The backfill asks a different
  question ("which originals lack this width?") and leaves the regular path alone. Newly uploaded
  images continue to get all three widths from the ordinary batch.

  Skip markers are re-examined by reason. `no_downscale_needed` means "not needed at the widths we
  had", which a new width can change, so those originals are reconsidered. `source_pixel_cap` and
  `gif_not_targeted` mean the original cannot be processed at any width and are left out. No
  existing variant row, marker row or file is modified or deleted.

### Changed

- Width constants are now split by meaning: `BODY_BASE_WIDTH`, `BODY_SRCSET_WIDTHS`,
  `BODY_MAX_WIDTH`, `THUMB_WIDTH` and `BUILD_WIDTHS`. Previously a single `NOMINAL_WIDTHS` list was
  read positionally — `[0]` for the body `src` width and the last element for the maximum width —
  so adding a smaller width to the front would have silently made post bodies load the 240px file,
  and adding one to the end would have disabled the original `srcset` candidate. No index-based
  access to a width list remains.

- The variant route no longer hardcodes the allowed widths. It accepts a 2-4 digit width and the
  controller checks it against `BUILD_WIDTHS`, so a future width change no longer needs the route
  definition and the route cache to be kept in step with the constant.

## [0.1.1] - 2026-09-19

### Fixed

- Batch variant generation could stall permanently. Candidates were selected as "originals with no
  variant row", but an original that can never produce a variant — one narrower than the smallest
  nominal width, for example — never got a row, so it was picked again on every run. Where enough
  such originals sat next to each other in id order, a batch spent its whole `--limit` re-examining
  them, built nothing, and the next batch saw exactly the same window. The scheduled task uses the
  same limit, so a backlog would stop advancing at that point and never finish. On one site, 142 of
  369 originals were in this state, with 28 of them consecutive against a batch size of 20.

  Such originals are now recorded with a skip marker and drop out of the candidate set, so batches
  keep moving. The marker also stops the scheduler from reopening the same files every ten minutes.

  A marker is written only for reasons that cannot change for the same file (nothing to downscale,
  source pixel cap, GIF). A missing file or a failed encode is treated as temporary and retried.

  A marker records the original's path, byte size and MIME type at the time it was written, and is
  honoured only while all three still match. The image hash deliberately survives in-place
  conversion, so it cannot be used to detect that the original changed; converting or reverting an
  original therefore invalidates its marker on its own and the original becomes a candidate again.

  Skip markers are records, not files, so they are excluded wherever a real variant is expected: the
  body rewriter, the public endpoint, and the check for an already-built width. The rewriter also
  treats any variant entry with a zero width or an empty URL as "no variant", so a stray record can
  never reach the markup.

### Changed

- `build-variants` now reports how many originals are excluded by a marker, and how many markers the
  run added (or would add, in a dry run).

## [0.1.0] - 2026-09-19

### Added

- Pre-built resized variants (960 and 1600 wide) for editor-uploaded body images,
  encoded as WebP, or JPEG when the resized height would exceed the WebP dimension
  limit of 16,383 pixels. Variants are never upscaled: a nominal width is built only
  when the original is wider than it.
- A public, read-only endpoint that serves already-built variants. It never generates
  on request, so its cost per request is fixed. Responses carry a one-year immutable
  cache header, which is safe because a version token in the URL changes whenever a
  variant is rebuilt. It returns 404 for an unknown hash, a width outside the allow
  list, a stale token, a missing file, or an original row that no longer exists.
- A response middleware for the post detail and comment list APIs that rewrites only
  `img` tags whose `src` points at an editor upload. It adds `srcset`, `sizes`,
  `width`, `height`, `decoding="async"`, `loading="lazy"` for every image after the
  first, and `fetchpriority="high"` for the first image in the post body, and wraps
  images that are not already inside a link. Every other byte of the body is copied
  through unchanged.
- An in-place conversion of PNG and JPEG originals to WebP that keeps the image hash,
  so post and comment bodies are never modified. It backs up each original file and
  database row before converting, and ships a matching revert command. It is available
  only through artisan, defaults to a dry run, and requires `--apply` to write.
- Artisan commands: `build-variants`, `convert-originals`, `revert-originals`,
  and `prune-variants`.
- Scheduled tasks: build missing variants every ten minutes in small batches, and
  prune variants whose original was deleted once a day.
- Settings for turning response rewriting, variant generation, and link wrapping
  on or off individually.

### Notes

- Image work uses imagick. The GD build in this environment has no WebP encoder, which
  is also why the core `ImageResizer` cannot be reused here.
- Resource limits are applied to every imagick operation (memory, map, disk, area,
  width, height, time), along with a source pixel cap checked before decoding and a
  per-image wall-clock limit.
- `fetchpriority` is emitted as specified but is currently removed by the template's
  DOMPurify configuration on the visitor-facing screen; it survives on the bot/SSR
  page. Making it effective on the visitor screen requires a template-side change.
