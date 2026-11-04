# Directorist MCP image media

Media tools require the existing Directorist connection gates and WordPress `upload_files`. Reading and reusing an attachment additionally requires native `edit_post` access to that image. The same gates apply to direct abilities and HTTP MCP calls. No tool accepts a local filesystem path or forwards the client's Authorization header to an image source.

| Tool | Input | Behavior |
|---|---|---|
| `directorist-upload-media` | `file_name`, canonical `data_base64`, optional metadata and mutation controls | Validate a raster image in memory, preview, then create a native WordPress attachment after confirmation. Maximum decoded size: 512 KiB. Data URI prefixes are rejected. |
| `directorist-import-media` | Public HTTPS `source_url`, optional `file_name`, metadata and mutation controls | Preview the source and policy without downloading. At execution, stream at most 10 MiB plus one overflow-detection byte, validate actual image bytes, then create the attachment. Redirects, credentials, private/reserved addresses, local hosts, fragments and non-443 ports are rejected. |
| `directorist-get-media` | `attachment_id` | Read the permitted image's URL, MIME, dimensions, local-file availability, bytes, SHA-256 and attribution. No filesystem paths or binary data are returned. The checksum is null for absent files or files above 10 MiB. |

Both writers accept `title`, `alt_text`, `caption`, `credit` and an HTTPS `source_page`. They allow JPEG, PNG, GIF and WebP only when WordPress permits that MIME for the current user. The site's lower `wp_max_upload_size()` applies. Images are limited to 10,000 pixels per dimension and 25 million pixels total; multisite storage quota is checked before persistence. SVG, executable extensions, path traversal, invalid base64, mismatched extensions and excessive sizes/dimensions fail validation. Base64 uploads fit within the existing 1 MiB MCP request limit.

Execution requires a five-minute preview token and an idempotency key. A successful response includes `attachment_id` and a `media` inspection result. The exact same execution payload/key returns the existing result for 24 hours; replay rechecks attachment permission. Metadata uses a native WordPress transaction, and temporary files are cleaned in `finally`. Failures before product persistence return their validation reason and release the reservation. Failures after persistence starts retain the existing conservative `outcome_unknown` behavior; reconcile through media references and inspection before trying another key. Generated files are retained when the database commit outcome is uncertain.

## Import and use a directory preview

1. Call `directorist-import-media` with `dry_run: true`. Prefer a fixed source format when supplying `file_name`; content negotiation can otherwise produce a different image format. Without `file_name`, the tool derives the extension from the actual image MIME.
2. Execute the identical input with `dry_run: false`, its `confirm_token`, and an `idempotency_key`.
3. Inspect the returned attachment with `directorist-get-media`.
4. Read the directory using `directorist-get-directory` and retain its version.
5. Preview `directorist-update-directory` with `changes: {"preview_image": ATTACHMENT_ID}`. The preview shows the resolved native URL.
6. Execute with the preview token, `expected_version` and a distinct idempotency key.
7. Read the directory again. Its `configuration.preview_image` and `default_preview_image_url` should match the image URL. Other configuration should remain unchanged.

An import preview input looks like:

```json
{
  "source_url": "https://images.unsplash.com/photo-1624880056139-d1212d7ff347?fit=crop&fm=jpg&q=85&w=1600&h=1000",
  "file_name": "car-default-preview-unsplash.jpg",
  "title": "Car directory default preview",
  "alt_text": "White Porsche 911 Carrera 4 GTS photographed in a studio",
  "credit": "Idzard Schiphof / Unsplash",
  "source_page": "https://unsplash.com/photos/white-porsche-911-on-white-background-bhuyaZftOB8",
  "dry_run": true
}
```

The source is [Idzard Schiphof's Unsplash photograph](https://unsplash.com/photos/white-porsche-911-on-white-background-bhuyaZftOB8). Importing media does not assign it to any directory or listing automatically. Use the returned attachment ID in the existing assignment tools.
