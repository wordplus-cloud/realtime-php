<?php
// The shop page. JSON for a <script> block: JSON_HEX_TAG keeps anything in it from closing the block.
$json = fn ($value) => json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$e = fn ($text) => htmlspecialchars((string) $text, ENT_QUOTES);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>API demo</title>
    <link rel="stylesheet" href="/shop.css">
    <?= $head /* the realtime settings, and the import map: shop.js imports @wordplus/realtime with no bundler */ ?>
    <script type="application/json" id="shop"><?= $json($shop) ?></script>
    <script type="module" src="/shop.js"></script>
</head>
<body>
    <header class="shop-bar">
        <strong>API demo</strong>
        <form class="shop-me" method="post" action="/logout">
            <img class="avatar" src="<?= $e($me['avatar']) ?>" alt="" width="24" height="24"> <?= $e($me['name']) ?>
            <button class="quiet" type="submit">Sign out</button>
        </form>
    </header>
    <main class="shop-main" id="shop-root"></main>
</body>
</html>
