# Open JSON Playlist API

JSON Playlist Creator publishes your playlist in the **Open JSON Playlist** format: a small HTTP API that players
call to sign in, list categories and items, read details and the programme guide, and play media. This document
describes every endpoint.

- **Base URL**: the address of your install, e.g. `https://example.com/playlist` (no trailing slash). Players ask
  for it as the "server" or "URL".
- **Credentials**: every request carries the account's `username` and `password` (accounts are managed in the admin
  panel). Media URLs carry them in the path.
- **Encoding**: UTF-8. JSON replies use `Content-Type: application/json`; playlists use
  `application/vnd.apple.mpegurl`; the guide uses `application/xml`.
- **Times**: the playlist has one time zone (Settings). Unix timestamps are UTC seconds; formatted times
  (`YYYY-MM-DD HH:MM:SS`) are in the playlist's time zone.
- **CORS**: every public reply sends `Access-Control-Allow-Origin: *`, so web players can use it.
- **Errors**: a bad sign-in on the JSON API is not an HTTP error: the reply is `{"user_info": {"auth": 0}}`. Unknown
  ids return an empty object or list. Media URLs answer `403` (bad credentials) or `404` (not found / off air).

Identifiers are numbers you choose in the admin panel: `stream_id` of a live channel, `vod_id` of a movie,
`series_id` of a series. Episode ids are derived: `series_id × 1000 + season × 100 + episode` (e.g. series 12,
season 2, episode 5 → `12205`).

---

## 1. Sign-in and server info

```
GET {base}/player_api.php?username={u}&password={p}
```

```json
{
  "user_info": {
    "username": "user7k2m", "password": "…", "auth": 1, "status": "Active",
    "message": "Welcome!",               // Settings → Message to players ("" when empty)
    "exp_date": "1798761599",            // unix time the account expires, or null for no expiry
    "is_trial": "0", "active_cons": "0", "created_at": "1735689600",
    "max_connections": "2",
    "allowed_output_formats": ["m3u8", "ts"]
  },
  "server_info": {
    "url": "example.com", "port": "443", "https_port": "443", "server_protocol": "https", "rtmp_port": "0",
    "timezone": "Europe/London", "timestamp_now": 1795560000, "time_now": "2026-11-24 19:20:00",
    "name": "My Playlist"
  }
}
```

A disabled or expired account, or a wrong password, gets `{"user_info": {"auth": 0}}`.

## 2. Categories

```
GET {base}/player_api.php?username={u}&password={p}&action=get_live_categories
GET {base}/player_api.php?username={u}&password={p}&action=get_vod_categories
GET {base}/player_api.php?username={u}&password={p}&action=get_series_categories
```

```json
[{ "category_id": "1", "category_name": "Family", "parent_id": 0 }]
```

## 3. Lists

All three accept an optional `&category_id={id}` filter.

### Live channels

```
GET {base}/player_api.php?username={u}&password={p}&action=get_live_streams
```

```json
[{
  "num": 1, "name": "Family Videos", "stream_type": "live", "stream_id": 101,
  "stream_icon": "https://example.com/playlist/images/3f2a….png",
  "epg_channel_id": "ch101",            // matches the channel id in the XMLTV guide
  "added": "0", "category_id": "1", "custom_sid": "", "direct_source": "",
  "tv_archive": 1,                       // 1 = catch-up available
  "tv_archive_duration": 3               // catch-up window, in days
}]
```

### Movies

```
GET {base}/player_api.php?username={u}&password={p}&action=get_vod_streams
```

```json
[{
  "num": 1, "name": "Holiday 2024", "stream_type": "movie", "stream_id": 1001,
  "stream_icon": "https://…/poster.jpg", "rating": "7.5", "rating_5based": 3.8,
  "added": "1735689600", "category_id": "1", "container_extension": "mp4",
  "custom_sid": "", "direct_source": "", "tmdb": ""
}]
```

### Series

```
GET {base}/player_api.php?username={u}&password={p}&action=get_series
```

```json
[{
  "num": 1, "name": "Cooking at Home", "series_id": 12, "cover": "https://…/cover.jpg", "plot": "…",
  "cast": "", "director": "", "genre": "Food", "releaseDate": "2025-01-01", "last_modified": "1735689600",
  "rating": "8", "rating_5based": 4, "backdrop_path": ["https://…/backdrop.jpg"], "youtube_trailer": "",
  "episode_run_time": "24", "category_id": "1"
}]
```

## 4. Details

### Movie

```
GET {base}/player_api.php?username={u}&password={p}&action=get_vod_info&vod_id={id}
```

```json
{
  "info": {
    "tmdb_id": "", "name": "Holiday 2024", "o_name": "Holiday 2024",
    "movie_image": "https://…/poster.jpg", "cover_big": "https://…/poster.jpg", "backdrop_path": ["https://…"],
    "youtube_trailer": "dQw4w9WgXcQ", "genre": "Travel", "plot": "…", "description": "…", "cast": "", "actors": "",
    "director": "", "rating": "7.5", "rating_5based": 3.8, "releasedate": "2024-01-01", "release_date": "2024-01-01",
    "year": "2024", "duration": "01:12:30", "duration_secs": 4350, "episode_run_time": 73,
    "country": "", "language": "English", "age": "PG", "mpaa_rating": "PG",
    "video": { "codec_name": "h264", "width": 1920, "height": 1080 },
    "audio": { "codec_name": "aac", "channels": 2, "channel_layout": "stereo" }, "bitrate": 0
  },
  "movie_data": { "stream_id": 1001, "name": "Holiday 2024", "added": "1735689600", "category_id": "1",
                  "container_extension": "mp4", "custom_sid": "", "direct_source": "" }
}
```

### Series

```
GET {base}/player_api.php?username={u}&password={p}&action=get_series_info&series_id={id}
```

```json
{
  "seasons": [{ "season_number": 1, "name": "Season 1", "episode_count": 6, "cover": "https://…", "air_date": "" }],
  "info": { "name": "Cooking at Home", "cover": "…", "plot": "…", "genre": "…", "rating": "8", "backdrop_path": ["…"],
            "youtube_trailer": "", "episode_run_time": "24", "category_id": "1", "…": "…" },
  "episodes": {
    "1": [{
      "id": "12101", "episode_num": 1, "title": "Bread", "container_extension": "mp4", "season": 1,
      "added": "1735689600", "custom_sid": "", "direct_source": "",
      "info": { "movie_image": "https://…", "name": "Bread", "plot": "…", "duration": "00:24:10", "duration_secs": 1450,
                "rating": "8", "releasedate": "", "video": { … }, "audio": { … } }
    }]
  }
}
```

## 5. Programme guide

Titles and descriptions in these two replies are **base64-encoded** UTF-8.

### Now and next

```
GET {base}/player_api.php?username={u}&password={p}&action=get_short_epg&stream_id={id}[&limit=4]
```

### Full guide of a channel

From the start of its catch-up window (at least one day back) through two days ahead.

```
GET {base}/player_api.php?username={u}&password={p}&action=get_simple_data_table&stream_id={id}
```

Both reply:

```json
{ "epg_listings": [{
    "id": "101029325112", "epg_id": "101", "channel_id": "ch101", "lang": "en",
    "title": "SG9saWRheSAyMDI0", "description": "QSB0cmlwIHRvLi4u",
    "start": "2026-11-24 19:00:00", "end": "2026-11-24 20:12:30",
    "start_timestamp": "1795561200", "stop_timestamp": "1795565550",
    "now_playing": 1,                    // 1 while it is on
    "has_archive": 0                     // 1 = can be replayed now (catch-up)
}]}
```

### XMLTV

The guide of every channel, in the standard XMLTV format.

```
GET {base}/xmltv.php?username={u}&password={p}[&days=2]
```

`days` (1–14) is how far ahead; the past part covers each channel's catch-up window (at least a day). Channel ids
are `ch{stream_id}`, the same as `epg_channel_id`.

## 6. Media URLs

Players build these from the ids above. All of them reply with the media itself or a `302` redirect to it.

| What | URL |
|---|---|
| Live channel | `{base}/live/{u}/{p}/{stream_id}.m3u8` (also `.ts` or no extension) |
| Movie | `{base}/movie/{u}/{p}/{vod_id}.{container_extension}` |
| Episode | `{base}/series/{u}/{p}/{episode_id}.{container_extension}` |
| Catch-up | `{base}/timeshift/{u}/{p}/{minutes}/{YYYY-MM-DD:HH-MM}/{stream_id}.ts` |
| Catch-up (query form) | `{base}/timeshift.php?username={u}&password={p}&stream={stream_id}&start={YYYY-MM-DD:HH-MM}&duration={minutes}` (also at `streaming/timeshift.php`) |

**Live channels**

- *Channels built from your videos*, once every video is prepared for live: an HLS live playlist, a short sliding
  window over the channel's schedule. Everyone sees the same moment; videos follow each other with
  `#EXT-X-DISCONTINUITY` between them. A `.ts` request is redirected to the `.m3u8`. Before videos are prepared,
  the reply redirects to the video playing now (from its beginning).
- *Relay channels* redirect to their stream link.
- Off air (before the schedule starts, or after a one-time run ends): `404`.

**Catch-up** `start` is the programme start in the playlist's time zone (`YYYY-MM-DD:HH-MM`; `YYYY-MM-DD HH:MM`
also works). A programme with an attached recording redirects to it. Otherwise channels built from your videos reply
with a finished (seekable) HLS playlist of what played from `start` for `minutes`. Outside the catch-up window: `404`.

## 7. M3U playlist

For players that only accept playlist links.

```
GET {base}/get.php?username={u}&password={p}[&type=m3u_plus][&output=ts|m3u8]
```

An extended M3U with every live channel (`tvg-id`, `tvg-chno`, `tvg-logo`, `group-title`, and `catchup="default"`
with a `catchup-source` template when catch-up is on), movie and episode. The header's `url-tvg` points at the
XMLTV guide. `output=m3u8` makes live entries end in `.m3u8` (recommended); the default is `.ts`.

## 8. Versioning

The API follows the reply shapes above. New fields may be added; existing ones aren't removed or renamed within a
major version. The server reports its version nowhere publicly on purpose; the admin panel shows it.
