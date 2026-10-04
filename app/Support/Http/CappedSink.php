<?php

namespace App\Support\Http;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Where a download is written, refusing to grow past a limit. The limit
 * counts the bytes after decompression, so a tiny gzip "bomb" that would
 * unpack to gigabytes stops at the limit instead of filling memory or disk.
 */
final class CappedSink implements StreamInterface
{
    use StreamDecoratorTrait;

    private StreamInterface $stream;

    private int $written = 0;

    public function __construct(private readonly int $limit)
    {
        $this->stream = Utils::streamFor(fopen('php://temp', 'w+b'));
    }

    public function write(string $string): int
    {
        $this->written += strlen($string);
        if ($this->written > $this->limit) {
            throw new RuntimeException('The response is larger than '.$this->limit.' bytes.');
        }

        return $this->stream->write($string);
    }
}
