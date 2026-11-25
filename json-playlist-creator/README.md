# JSON Playlist Creator

A lightweight, self-hosted **Open JSON Playlist** generator. Put your own videos in a library, arrange them as
movies, series and live TV-style channels with a programme guide and catch-up, and publish them to players
through the Open JSON Playlist API (plus M3U and XMLTV). Runs on your own computer or any PHP web host; no database.

> JSON Playlist Creator doesn't come with or point to any content. Only publish content that you own or are
> authorized by the rights holders to distribute; see [TERMS.md](TERMS.md).

## Features

- **Library**: upload videos (drag and drop, many at once; large files go up in resumable parts) or add them by
  link. Lengths and poster images are read from the video automatically when ffmpeg is available.
- **Live channels** of two kinds:
  - *Your videos, back to back*: a channel plays a list of videos from a start time, looping or once, the same
    moment for every viewer. Prepared videos are stitched into one continuous HLS stream.
  - *Stream link*: relays a live stream you are authorized to redistribute.
- **Timeline**: see what's on now and next before saving; reorder, shuffle, "start now", one-run length and how
  often it repeats per day.
- **Programme guide**: automatic (from the videos a channel plays) or entered by hand, with quick lengths, gap
  and overlap warnings, "copy a day" for daily or weekly schedules, and XMLTV import.
- **Catch-up**: replay past programmes for up to 30 days: exactly what a channel played, or a recording you attach
  to a programme.
- **Movies and series** with artwork, descriptions, ratings, trailers, seasons and episodes.
- **Accounts**: as many sign-ins as you like, each with its own password, connection limit, expiry date and note.
- **Safe editing**: every save is validated, a backup is kept (last 40), and two people editing at once can't
  overwrite each other. Export and import the whole playlist as JSON.
- **Standards**: Open JSON Playlist API, extended M3U, XMLTV, HLS.

## Quick start

```sh
PHP_CLI_SERVER_WORKERS=4 php -S 0.0.0.0:8080 router.php
```

Open <http://localhost:8080/admin/>, complete setup (admin password, time zone, terms), then:

1. **Library** → drop in some videos.
2. **Live channels** → Add channel → *My videos, back to back* → add videos → Save → *Prepare for live*.
3. **Overview** → **Test like a player**, then enter the server URL, username and password in your player.

For Apache, nginx and shared hosting, see [docs/INSTALL.md](docs/INSTALL.md).

## How players connect

In a player that supports JSON Playlists (API sign-in), enter:

- **Server**: the install's address, e.g. `https://example.com/playlist`
- **Username / password**: one of the accounts from the admin panel

Players that only take a playlist link can use
`https://example.com/playlist/get.php?username=…&password=…&type=m3u_plus&output=m3u8`.

The full API is documented in [docs/API.md](docs/API.md).

## Folders

| Folder | What | Web access |
|---|---|---|
| `admin/` | The admin panel | yes (login) |
| `app/` | Code | **never** |
| `data/` | Playlist, logins, backups | **never** |
| `tools/` | Command-line tools | **never** |
| `media/` | Uploaded videos and live segments | yes |
| `images/` | Uploaded artwork | yes |
| `docs/` | Documentation | — |

## Command-line tools

```sh
php tools/setup.php --password='…' --accept-terms [--name='…'] [--timezone=…]   # first-run setup
php tools/set-password.php 'new password'                                      # reset the admin password
php tools/build-hls.php <key> … | --all [--force] [--from file.mp4]              # prepare videos for live
```

## License

Source code is licensed under [MIT](LICENSE.md). See the [publishing terms](TERMS.md) for the admin setup.
