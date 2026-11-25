# JSON Playlist Creator download site

For a local preview, run from this directory:

```sh
php -S 127.0.0.1:8091 -t public router.php
```

Open http://127.0.0.1:8091/.

On a PHP host, point the web server's document root at `public/`. Keep
the ZIP and `state/` outside the document root. Give the PHP worker write
access to `state/` and read access to the ZIP. The site requires PHP 8.0+.
Use HTTPS for a public deployment.

The download endpoint permits five requests per rolling ten minutes per IPv4
address or IPv6 /64. It returns HTTP 429 and Retry-After when the limit
is reached. The ledger is shared across PHP workers on one host. If using a
reverse proxy, configure the web server to restore the client IP only from
trusted proxies so PHP receives the correct REMOTE_ADDR. For multiple hosts,
enforce a shared rate limit at the ingress.
