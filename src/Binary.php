<?php

declare(strict_types=1);

namespace WordPlus\Realtime;

/**
 * Bytes in an event's data, which a PHP string can't tell from text: `new Binary( $bytes )` anywhere in the data goes as
 * pages carry binary in JSON, `{"$b": base64}`, sealed with the rest, and a page gets it as a Uint8Array. A string of bytes
 * that aren't UTF-8 can't go as it is: JSON has no way to carry it, and encoding it throws.
 */
final class Binary implements \JsonSerializable
{
    public function __construct(public readonly string $bytes)
    {
    }

    /** @return array{'$b': string} */
    public function jsonSerialize(): array
    {
        return ['$b' => base64_encode($this->bytes)];
    }
}
