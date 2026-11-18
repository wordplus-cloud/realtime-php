# Deploying a Laravel app

On Forge, Vapor, Cloud, or any server that runs Laravel: nothing realtime is kept on your servers, so any of them answers any request.

## Its settings

| | |
|---|---|
| `BROADCAST_CONNECTION` | `wordplus` |
| `WORDPLUS_SITE`, `WORDPLUS_SECRET` | from your app in WordPlus Cloud |
| `WORDPLUS_LOCAL_KEY` | yours: kept as long as the app |
| `WORDPLUS_REALTIME_GENERATIONS` | a cache store every server shares, for moving keys on (`redis`, `database`) |

The package reads them through its config, so `php artisan config:cache` keeps working. It checks `WORDPLUS_SITE` and `WORDPLUS_SECRET` only when first used, by a request or a broadcast, so a Docker or CI build may run `php artisan optimize` before they are set. The key reaches pages through `@wordplusRealtime`, which is what it is for.

## Its addresses

Add every address your pages are served from to the app in WordPlus Cloud: scheme, host and port exactly (`https://shop.example.com`, `https://www.shop.example.com`). A page anywhere else is refused with `wrong_origin`. Up to 20 addresses an app. If your app hears [webhooks](sending.md#webhooks), set their address there too.

## Its files

`realtime-worker.js`, `realtime-rooms.min.js`, `realtime-rooms-e2ee.worker.js`, `realtime-ui.min.js` and `realtime-ui-streams.min.js` come with the Composer package, and its routes serve them at `/wordplus/realtime/files/{version}/{name}`, from the address of your pages, where a browser runs a worker. Each release serves the ones its package came with, nothing is copied by the build, and each answer is kept for a year as immutable, so a CDN in front of your app keeps them too.

## Another address

A page served from another address than your routes, such as a single-page app at `https://app.shop.example.com` asking an API at `https://api.shop.example.com`, names your routes in full and sends its user in a header, as a browser sends no cookie to another address:

```js
const rt = await connect( { app: 's1500000007', endpoint: 'https://api.shop.example.com/wordplus/realtime', headers: { Authorization: 'Bearer ' + token } } );
```

- **In WordPlus Cloud,** add the page address to the app, as every other ([its addresses](#its-addresses)).
- **The routes go on middleware that knows the user by that token,** such as Sanctum, in `config/wordplus-realtime.php`: `'routes' => [ 'middleware' => [ 'api', 'auth:sanctum' ], … ]`. The `api` group asks no CSRF token, which a page on another address does not have.
- **CORS comes from Laravel, not this package,** so a page never gets the header twice, which a browser refuses. For the page address alone:
  - the address back exactly in `Access-Control-Allow-Origin`, with `Vary: Origin`
  - `POST` for the routes and `GET` for the files, as a group call fetches its encryption worker from there, and the `OPTIONS` asked before them
  - `Content-Type` and the headers the page sends (`Authorization` above)
  - no `Access-Control-Allow-Credentials`, as the page sends no cookie
- **The worker** at another address is one a browser never starts, so each tab of that page connects by itself.

In `config/cors.php` (`php artisan config:publish cors`):

```php fragment
'paths' => [ 'wordplus/realtime/*' ],
'allowed_methods' => [ 'GET', 'POST' ],
'allowed_origins' => [ 'https://app.shop.example.com' ],
'allowed_headers' => [ 'Content-Type', 'Authorization' ],
'max_age' => 600,
'supports_credentials' => false,
```

## The queue

Events that implement `ShouldBroadcast` go through your queue: run a worker (`php artisan queue:work`, Horizon), or they wait. `ShouldBroadcastNow` sends during the request, with no worker.

## Behind a proxy

Trust it (`$middleware->trustProxies( at: '*' )` in `bootstrap/app.php`), so sessions and CSRF see HTTPS. The browser routes are on the `web` middleware: serve them from the address of the pages.

## Several servers

Nothing realtime is kept on your servers: any of them answers any request, and broadcasting works from all of them. Only moving keys on keeps a little state, the key generations, in `WORDPLUS_REALTIME_GENERATIONS`: a cache store every server shares that does not evict ([encryption](encryption.md#when-someone-loses-access)).

## Content Security Policy

If your app sends one, let pages reach the WordPlus Cloud servers (the realtime servers, which may move a connection to another of them, and the media servers for group calls) and run the workers from your own address:

```text
connect-src 'self' https://*.wordplus.cloud wss://*.wordplus.cloud;
worker-src 'self';
```

## Clocks

Your app signs what it sends with the time. Keep the server clock synced (NTP): one more than 5 minutes off has its broadcasts refused with `stale_signature`.
