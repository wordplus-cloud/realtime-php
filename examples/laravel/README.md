# The API demo on Laravel

Sign in as Ann (support), Ben (a customer) or Cara (the warehouse), each in a browser of their own: the order of Ben follows as Ann moves it on and Ben alone gets a notice, Ann and Ben chat with “typing…” and the server keeps the conversation from its webhook, everyone sees who is in the shop and who is online and can call them, a call can bring in more staff, and all three can meet in the daily meeting. The Laravel broadcasting through `wordplus/realtime-php`: `routes/channels.php` as the room rules, broadcast events, presence, and the call screen. The guide is at [docs.wordplus.cloud/laravel](https://docs.wordplus.cloud/laravel/getting-started/).

```bash
cp .env.example .env       # then the key and secret of your app (http://localhost:8000 among its addresses) and a local key
composer setup             # composer install, the app key, the database with Ann, Ben and Cara, npm install and the build
php artisan serve          # http://localhost:8000
```

It takes `wordplus/realtime-php` from this repository (the path repository in `composer.json`). In your own app, `composer require wordplus/realtime-php`.

| File | |
|---|---|
| `config/broadcasting.php`, `.env.example` | the `wordplus` connection, and the app credentials |
| `routes/channels.php` | who may join the order room, the chat and the shop, and what each room is (its third argument) |
| `app/Events/OrderUpdated.php` | the order moving on, broadcast to its room |
| `app/Http/Controllers/ShopController.php` | the shop page, and moving the order on with a notice to its customer |
| `app/Providers/AppServiceProvider.php` | who may call whom and add whom, and the daily meeting |
| `resources/views/shop.blade.php` | `@wordplusRealtime`, and the shop data for the page |
| `resources/js/shop.js` | the page: the same in every example |
