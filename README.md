# JSON Playlist Creator

## Your videos. Your channels.

Turn your video library into movies, series and live channels with a programme guide and catch-up. Host it on your own computer or PHP server. No database required.

**[Download JSON Playlist Creator 1.0.2 (ZIP)](https://github.com/Nasib555/json-playlist-creator/releases/download/v1.0.2/json-playlist-creator-1.0.2.zip)**

### From library to lineup

- **Build your library.** Upload batches of videos with resumable uploads or add videos by URL. Organize them into movies and series.
- **Schedule live channels.** Play videos back to back on a shared schedule or relay a stream you’re authorized to distribute. Prepare your videos with FFmpeg for continuous HLS playback.
- **Publish a guide and catch-up.** Generate a guide from your schedule, edit it by hand, or import XMLTV. Offer up to 30 days of catch-up.
- **Connect your players.** Set account expiry dates and connection limits. Share through the Open JSON Playlist API, M3U playlists, or XMLTV guides.
- **Keep control of your data.** Back up each save, prevent concurrent edits from overwriting each other, and export or import your catalog as JSON.

### Install on your computer

You’ll need PHP 8.0 or newer with JSON and mbstring. FFmpeg is optional for basic use and enables live preparation, automatic video lengths, and posters.

1. Extract the ZIP and open a terminal in the extracted `json-playlist-creator` folder.
2. Start a local server.

   ```sh
   php -S 127.0.0.1:8080 router.php
   ```

3. Open <http://127.0.0.1:8080/admin/> and complete setup.

### Install on a PHP server

Extract the ZIP into your site directory. Give the PHP worker write access to `data/`, `media/`, and `images/`. Follow the [installation guide](json-playlist-creator/docs/INSTALL.md) for Apache rules, the sample nginx configuration, and command-line setup before exposing the app.

### Explore the project

- [Application source and usage guide](json-playlist-creator/)
- [Open JSON Playlist API reference](json-playlist-creator/docs/API.md)
- [Release notes](json-playlist-creator/CHANGELOG.md)
- [Self-host the rate-limited download page](landing/)

Created by **Nasib Zada**. Source code is available under the [MIT License](LICENSE). The app comes without media. Only publish content you own or are authorized to distribute. See the [publishing terms](json-playlist-creator/TERMS.md).
