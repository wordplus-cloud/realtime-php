# Deploying a PHP app

Under PHP-FPM with nginx or Apache, or any host that runs PHP 8.1 with sodium: nothing realtime is kept on your servers, so any of them answers any request.

## Its settings

| | |
|---|---|
| `WORDPLUS_SITE`, `WORDPLUS_SECRET` | from your app in WordPlus Cloud |
| `WORDPLUS_LOCAL_KEY` | yours: kept as long as the app |

Set them in the server environment (the PHP-FPM `env[WORDPLUS_SITE] = …`, the Apache `SetEnv`), or a file outside the web root. Keep the secret and the local key out of your repository and out of every page. The key reaches pages through `head()`, which is what it is for.

## Its addresses

Add every address your pages are served from to the app in WordPlus Cloud: scheme, host and port exactly (`https://shop.example.com`, `https://www.shop.example.com`). A page anywhere else is refused with `wrong_origin`. Up to 20 addresses an app. If your server hears [webhooks](sending.md#webhooks), set their address there too.

## Its routes

Send every request that is not a file to your front controller, so `/api/realtime/auth`, `/calls`, `/keys` and `/webhook` reach it:

```nginx
location / {
    try_files $uri /index.php$is_args$args;
}
```

```apache
FallbackResource /index.php
```

## Its files

`realtime.js`, `realtime-worker.js`, `realtime-rooms.min.js`, `realtime-rooms-e2ee.worker.js`, `realtime-ui.min.js` and `realtime-ui-streams.min.js` come with the Composer package, and `respondFile()` serves them at `/api/realtime/files/{version}/{name}`, from the address of your pages, where a browser runs a worker. Each release serves the ones its package came with, and each answer is kept for a year as immutable, so a CDN in front of your app keeps them too. Send `/api/realtime/*` to your front controller, GETs included.

## Another address

A page served from another address than your routes, such as a single-page app at `https://app.shop.example.com` asking an API at `https://api.shop.example.com`, names your routes in full and sends its user in a header, as a browser sends no cookie to another address:

```js
const rt = await connect( { app: 's1500000007', endpoint: 'https://api.shop.example.com/api/realtime', headers: { Authorization: 'Bearer ' + token } } );
```

- **In WordPlus Cloud,** add the page address to the app, as every other ([its addresses](#its-addresses)).
- **The user you hand `respond()`** comes from that header, as the rest of your API finds it.
- **CORS comes from your app, not this package,** so a page never gets the header twice, which a browser refuses. For the page address alone:
  - the address back exactly in `Access-Control-Allow-Origin`, with `Vary: Origin`
  - `POST` for the routes and `GET` for the files, as a group call fetches its encryption worker from there, and the `OPTIONS` asked before them
  - `Content-Type` and the headers the page sends (`Authorization` above)
  - no `Access-Control-Allow-Credentials`, as the page sends no cookie
- **The worker** at another address is one a browser never starts, so each tab of that page connects by itself.

```php
// public/index.php, before the realtime routes.
$pages  = [ 'https://app.shop.example.com' ];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if ( in_array( $origin, $pages, true ) && str_starts_with( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/api/realtime/' ) ) {
    header( 'Access-Control-Allow-Origin: ' . $origin );
    header( 'Access-Control-Allow-Methods: GET, POST' );
    header( 'Access-Control-Allow-Headers: Content-Type, Authorization' );
    header( 'Access-Control-Max-Age: 600' );
    header( 'Vary: Origin' );

    if ( 'OPTIONS' === $_SERVER['REQUEST_METHOD'] ) {
        http_response_code( 204 );
        exit;
    }
}
```

## Sessions

The PHP file sessions are locked while a request has them open, so the requests of a page wait on each other. Open the session for reading only where a route just needs to know the user: `session_start( [ 'read_and_close' => true ] )`, as the example routes do.

## Behind a proxy

Behind a proxy that ends TLS, let PHP see HTTPS, so the session cookie is sent as Secure (`session.cookie_secure = 1`). The routes need the session cookie: serve them from the address of the pages.

## Several servers

Nothing realtime is kept on your servers: any of them answers any request, and sending works from all of them. Only moving keys on keeps a little state, the key generations, in a store they share, such as a table every server reads ([encryption](encryption.md#when-someone-loses-access)).

## Content Security Policy

If your pages send one, let them reach the WordPlus Cloud servers (the realtime servers, which may move a connection to another of them, and the media servers for group calls) and run the workers from your own address:

```text
connect-src 'self' https://*.wordplus.cloud wss://*.wordplus.cloud;
worker-src 'self';
```

## Clocks

Your server signs what it sends with the time. Keep its clock synced (NTP): one more than 5 minutes off has its emits refused with `stale_signature`.
