# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
