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

## The queue

Events that implement `ShouldBroadcast` go through your queue: run a worker (`php artisan queue:work`, Horizon), or they wait. `ShouldBroadcastNow` sends during the request, with no worker.

## Behind a proxy

Trust it (`$middleware->trustProxies( at: '*' )` in `bootstrap/app.php`), so sessions and CSRF see HTTPS. The browser routes are on the `web` middleware: serve them from the address of the pages.

## Content Security Policy

If your app sends one, let pages reach the WordPlus Cloud servers (the realtime servers, which may move a connection to another of them, and the media servers for group calls) and run the workers from your own address:

```text
connect-src 'self' https://*.wordplus.cloud wss://*.wordplus.cloud;
worker-src 'self';
```

## Clocks

Your app signs what it sends with the time. Keep the server clock synced (NTP): one more than 5 minutes off has its broadcasts refused with `stale_signature`.
