# Audio & Video Media Plugin (`iikiti-media-av`)

`iikiti-media-av` is a first-party, opt-in application-scoped plugin (`plugins/iikiti-media-av/`) that adds audio and video support to the Digital Asset Manager: MIME type registration, FFprobe stream/duration metadata extraction, video poster frames, audio waveform peak generation, and optional HLS adaptive streaming transcoding.

See [`../digital-asset-manager.md`](../digital-asset-manager.md) for core DAM storage, delivery, and worker configuration.

## System requirements

Install **FFmpeg** (providing both `ffmpeg` and `ffprobe`) on every host that runs `asset_processing` or `media_transcoding` Messenger workers:

```sh
# Debian / Ubuntu
sudo apt-get update && sudo apt-get install -y ffmpeg

# Verify binaries
ffmpeg -version
ffprobe -version
```

All FFmpeg/FFprobe invocations use Symfony Process argument arrays (never shell strings) with explicit timeouts:

- Metadata probing (`ffprobe`): `30` seconds
- Video poster extraction (`ffmpeg`): `60` seconds (clamped to max `1920` px)
- HLS VOD transcoding (`ffmpeg`): `600` seconds

## Installation and activation

`iikiti-media-av` ships under `plugins/iikiti-media-av/` with `activation_mode: opt_in` and `scope: application`.

1. Enable the plugin, run Doctrine migrations (which applies `Version20261128025000` to seed the default `ffmpeg-video` and `ffmpeg-audio` asset processing workflows), initialize Messenger transports, and clear the cache:

```sh
php bin/console iikiti:plugin:enable iikiti-media-av
php bin/console doctrine:migrations:migrate
php bin/console messenger:setup-transports
php bin/console cache:clear
php bin/console messenger:restart-workers
```

2. Raise PHP and reverse-proxy upload limits (`upload_max_filesize`, `post_max_size`, Nginx `client_max_body_size`) to match `MEDIA_AV_MAX_UPLOAD_BYTES`.

## Configuration

| Environment variable | Default | Purpose |
| --- | --- | --- |
| `FFMPEG_BINARY` | `ffmpeg` | Executable name or absolute path to the `ffmpeg` binary. |
| `FFPROBE_BINARY` | `ffprobe` | Executable name or absolute path to the `ffprobe` binary. |
| `MEDIA_AV_MAX_UPLOAD_BYTES` | `262144000` (~250 MB) | Maximum upload size in bytes for audio and video MIME types registered by `AvMimeTypeProvider`. Must be a positive integer. |
| `MEDIA_AV_HLS_ENABLED` | `0` (`false`) | Globally enable queuing HLS VOD transcoding for uploaded video assets. Can also be enabled per workflow definition via `options.hls: true`. |

Example `.env.local`:

```dotenv
FFMPEG_BINARY=/usr/bin/ffmpeg
FFPROBE_BINARY=/usr/bin/ffprobe
MEDIA_AV_MAX_UPLOAD_BYTES=262144000
MEDIA_AV_HLS_ENABLED=0
```

## Allowed audio and video MIME types

`AvMimeTypeProvider` (`AssetMimeTypeProviderInterface`) registers seven user-uploadable MIME types, all configured with `inlineDelivery: true` so browsers can play them natively with byte-range (`Range` / `206`) seeking:

| MIME type | Extension | Category |
| --- | --- | --- |
| `video/mp4` | `mp4` | `video` |
| `video/webm` | `webm` | `video` |
| `audio/mpeg` | `mp3` | `audio` |
| `audio/ogg` | `ogg` | `audio` |
| `audio/wav` | `wav` | `audio` |
| `audio/mp4` | `m4a` | `audio` |
| `audio/webm` | `weba` | `audio` |

> **Security note:** HLS playlists (`.m3u8` / `application/vnd.apple.mpegurl`) and MPEG-TS segments (`.ts` / `video/mp2t`) are intentionally **excluded** from user-uploadable MIME types so untrusted manifests cannot be uploaded directly. Only server-side `HlsTranscoder` writes HLS variants.

## Metadata, poster frames, and audio waveforms

Migration `Version20261128025000` seeds two plugin-owned workflow definitions (`ffmpeg-video` for `video/*` using processor `ffmpeg_video`, and `ffmpeg-audio` for `audio/*` using processor `ffmpeg_audio`; both delegate to `FfmpegMediaProcessor` / `ffmpeg_media`):

1. **FFprobe metadata (`FfprobeMetadataParser`):**
   - Stores `durationMs` on the asset (`objects.asset_duration_ms`).
   - Stores intrinsic video `width` and `height` on the asset (`asset_width`, `asset_height`).
   - Persists parsed container, codec, bitrate, frame rate, sample rate, channel count, and stream summary fields into `asset_media_metadata` (`JSONB`).
2. **Video poster frame extraction:**
   - Enabled by default (`options.poster: true`, `options.poster_offset_percent: 10`, capturing at 10% of duration).
   - Generates JPEG and WebP poster `DigitalAssetVariant` records (capped at `1920` px) stored through `AssetStorageInterface` with SHA-256 checksums.
3. **Audio waveform peaks (`WaveformPeakExtractor`):**
   - Enabled by default (`options.waveform: true`, `options.waveform_buckets: 120`) for both audio files and videos containing an audio stream.
   - Extracts normalized peak amplitude buckets and stores them under `asset_media_metadata.waveform` for rendering audio scrubbers in admin and frontend players.

Customize workflow options per site or globally under the **Workflows** admin screen (`/api/admin/workflows/definitions`).

## Optional HLS transcoding and dedicated worker setup

When `MEDIA_AV_HLS_ENABLED=1` (for video assets) or a workflow definition sets `options.hls: true`, `FfmpegMediaProcessor` dispatches `iikiti\Extension\MediaAv\Message\TranscodeMediaAsset` to the dedicated `media_transcoding` Messenger transport (`config/packages/messenger.yaml`).

### How HLS transcoding and delivery work

- `TranscodeMediaAssetHandler` invokes `HlsTranscoder`, which transcodes the source into 6-second MPEG-TS segments and VOD M3U8 playlists stored via `AssetStorageInterface` as `DigitalAssetVariant` records (`hls_master` for `master.m3u8`, `hls_<stem>` for rendition playlists and `.ts` segments).
- `HlsTranscoder::rewritePlaylist()` rewrites every relative playlist and segment reference inside `.m3u8` manifests to CMS-controlled paths (`/assets/{publicToken}/hls/{segment}`).
- `HlsDeliveryController` serves `GET|HEAD /assets/{publicToken}/hls/{segment}`:
  - In database-free `consumer` deployments (classified by `MediaAvRouteContextContributor`), it proxies requests via `PublicAssetApiProxy::hls()` to `GET|HEAD /api/public/assets/{publicToken}/hls/{segment}` (`api_public_asset_media_av_hls`, guarded by `#[RequiresPlugin('iikiti-media-av')]`).
  - On standalone and API runtimes, it verifies `PluginActivationChecker::isActive('iikiti-media-av')` and enforces `isEffectivelyPrivate()` (including expired `make_private` assets), MFA completion (for private assets), site access grants, and segment extension/MIME consistency (`m3u8` ↔ `application/vnd.apple.mpegurl`, `ts` ↔ `video/mp2t`).
  - Responses use `AssetStreamResponseFactory` for strong ETags, byte-range (`Range` / `206` / `416`), and `Cache-Control` policies. `.m3u8` playlists always stream through the CMS proxy (`allowRedirect: false`) even under `ASSET_DELIVERY_MODE=redirect` so CMS-rewritten segment URLs continue to work, while `.ts` segments may redirect to a CDN or presigned URL.

### Running a dedicated `media_transcoding` worker

Because FFmpeg transcoding is CPU- and memory-intensive and can run for several minutes per video, **always exclude `media_transcoding` from the post-response lazy background runner** and run a dedicated Messenger worker process:

```dotenv
MEDIA_AV_HLS_ENABLED=1
BACKGROUND_RUNNER_EXCLUDED_RECEIVERS=failed,media_transcoding
```

Start one or more dedicated worker processes (for example via systemd or Supervisor):

```sh
php bin/console messenger:setup-transports
php bin/console messenger:consume media_transcoding --time-limit=3600 --memory-limit=512M
```

After code deployments, run `php bin/console cache:clear` and `php bin/console messenger:restart-workers` so transcoding workers gracefully restart between jobs.
