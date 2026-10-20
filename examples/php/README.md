# The API demo in plain PHP

Sign in as Ann (support), Ben (a customer) or Cara (the warehouse), each in a browser of their own: the order of Ben follows as Ann moves it on and Ben alone gets a notice, Ann and Ben chat with “typing…” and the server keeps the conversation from its webhook, everyone sees who is in the shop and who is online and can call them, a call can bring in more staff, and all three can meet in the daily meeting. `wordplus/realtime-php` without a framework: one front controller. The guide is at [docs.wordplus.cloud/php](https://docs.wordplus.cloud/php/getting-started/).

```bash
cp .env.example .env       # the key and secret of your app, with http://localhost:8000 among its addresses, and a local key
composer install           # wordplus/realtime-php, from this repository
php -S localhost:8000 -t public public/index.php
```

| File | |
|---|---|
| `src/app.php` | the realtime SDK with its rooms and calls and who may use them, the shop people, and its order in a JSON file |
| `public/index.php` | the front controller: the browser files and routes of the SDK and the webhook, signing in, the order route, and the page |
| `templates/page.php` | the page, with `$realtime->head()`, the user settings and the import map of the client, and the shop data |
| `public/shop.js` | the page script, the same in every example, as it is: no Node and no bundler |
