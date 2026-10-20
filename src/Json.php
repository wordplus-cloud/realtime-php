<?php

declare(strict_types=1);

namespace WordPlus\Realtime;

/** JSON as the browser and the realtime server read it: slashes and Unicode as they are, an empty object as `{}`. */
final class Json
{
    public static function encode(mixed $data): string
    {
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
