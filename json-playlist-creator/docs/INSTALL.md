# Installing JSON Playlist Creator

It's a folder of PHP files with no database. Copy it somewhere a web server with PHP can serve it, make three folders
writable, and open `admin/` in a browser.

**Requirements**: PHP 8.0 or newer with the `json` and `mbstring` extensions (both are standard). Optional:
`ffmpeg` / `ffprobe` on the server, to prepare your videos for live channels and to read video lengths and make
posters automatically.

Writable by PHP: `data/` (playlist, logins, backups), `media/` (uploaded videos, live segments), `images/`
(uploaded artwork).

> **Security:** `data/`, `app/` and `tools/` must never be reachable from the web: `data/` holds your logins and
> playlist. Apache does this with the included `.htaccess` files; nginx needs the rules in `docs/nginx.conf`. The
> setup page checks it for you, and so does **Test like a player** in the admin panel.

---

## On your own computer (no web server needed)

```sh
cd json-playlist-creator
PHP_CLI_SERVER_WORKERS=4 php -S 0.0.0.0:8080 router.php
```

Open <http://localhost:8080/admin/> and complete setup. Players on the same network use
`http://<this computer's IP address>:8080` as the server. The workers let the built-in server handle several players
at once; keep the terminal open while you use it. For anything beyond your home, use a real web server.

## Apache (shared hosting, XAMPP, MAMP, most hosts)

1. Upload the folder, e.g. to `public_html/playlist/`.
2. Make sure `AllowOverride All` (or at least `FileInfo AuthConfig Indexes Options`) is allowed for it, so the
   `.htaccess` files work. `mod_rewrite` must be on (it is on almost every host).
3. Make `data/`, `media/` and `images/` writable by PHP (on most hosts they already are; otherwise `chmod 775`).
4. Open `https://your-site/playlist/admin/` and complete setup.

For big video uploads, raise PHP's limits if your host allows it (uploads are sent in parts, so even small limits
work, just more slowly): `upload_max_filesize = 64M`, `post_max_size = 72M`, `max_execution_time = 300`.

## nginx

1. Put the folder under your site's root, e.g. `/var/www/site/playlist/`.
2. Add the rules from `docs/nginx.conf` to the site's `server { … }` block, *before* its PHP location, replacing
   `/playlist` with your folder (use an empty prefix when it's the site root).
3. `nginx -t && nginx -s reload`
4. Make `data/`, `media/` and `images/` writable by the PHP-FPM user (`chown -R www-data: data media images`, or
   the user your PHP runs as).
5. Open `https://your-site/playlist/admin/` and complete setup.

## Setup from the command line

On a public server, the first person to open `admin/` completes setup. To avoid that window, run setup on the
server before opening the admin panel:

```sh
php tools/setup.php --password='a long admin password' --accept-terms --name='My Playlist' --timezone=Europe/London
```

`--accept-terms` records that you have read and accept `TERMS.md`.

## Live channels from your own videos

Channels play your videos back to back, like broadcast TV. For a continuous stream (and exact catch-up), each video
is cut into short segments once ("Prepare for live" in the admin panel), which needs `ffmpeg`:

- **ffmpeg on the server**: the admin panel does it in the background; nothing else to do. Install it with your
  package manager (`apt install ffmpeg`, `dnf install ffmpeg`, `brew install ffmpeg`).
- **No ffmpeg on the server** (common on shared hosting): copy the install (with `data/catalog.json` and the
  videos) to a computer that has PHP and ffmpeg, run `php tools/build-hls.php --all`, and upload the new
  `media/hls/` folders to the server.

Without preparation a channel still works: players get the video that's on now, from its beginning.

## Forgot the admin password

```sh
php tools/set-password.php 'new password'
```

## Updating

Replace everything except `data/`, `media/` and `images/` with the new version. Your playlist, logins, backups and
files stay as they are.

## Backups

The admin panel keeps the last 40 versions of the playlist (Backups & export) and can export it as one JSON file.
To back up everything, copy the `data/`, `media/` and `images/` folders.
