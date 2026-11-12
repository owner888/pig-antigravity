<?php

declare(strict_types=1);

namespace PigAntigravity\Test;

use Closure;
use Pig\Async\Loop;
use RuntimeException;

/**
 * A local stand-in for the Antigravity endpoints that answers each JSON request by what it asks —
 * its path and body — where pig's `CannedServer` answers by the order requests arrive in. The
 * reader's requests depend on what came back before, so its tests say "the cache has this, the
 * live fetch has that" rather than counting connections.
 *
 * The handler answers `[status, body]` (an array is sent as JSON), or `'hang'` to never answer,
 * or `'drop'` to close without answering.
 */
final class RoutedServer
{
    /** @var list<resource> */
    private array $open = [];

    /** @var array<int, string> watcher ids by stream id, each taken off the loop before its stream closes */
    private array $watchers = [];

    /** @var list<array{path: string, body: array<string, mixed>, head: string}> */
    public array $calls = [];

    /** @param Closure(string $path, array<string, mixed> $body): (array{0: int, 1: array<string, mixed>|string}|string) $handler */
    public function __construct(private Closure $handler)
    {
    }

    public function __destruct()
    {
        $this->stop();
    }

    /** @param Closure(string $path, array<string, mixed> $body): (array{0: int, 1: array<string, mixed>|string}|string) $handler */
    public function handle(Closure $handler): void
    {
        $this->handler = $handler;
        $this->calls = [];
    }

    /** @return string the base URL, without a trailing slash */
    public function start(): string
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($server === false) {
            throw new RuntimeException("Cannot open test server: {$errstr}");
        }

        stream_set_blocking($server, false);
        $this->open[] = $server;
        $this->watchers[(int) $server] = Loop::get()->onReadable($server, function ($listening): void {
            $connection = stream_socket_accept($listening, 0);

            if ($connection === false) {
                return;
            }

            stream_set_blocking($connection, false);
            $this->open[] = $connection;
            $buffer = '';
            $this->watchers[(int) $connection] = Loop::get()->onReadable($connection, function ($peer) use (&$buffer): void {
                $data = fread($peer, 65536);
                $buffer .= $data === false ? '' : $data;
                $headEnd = strpos($buffer, "\r\n\r\n");

                if ($headEnd === false) {
                    if ($data === '' || $data === false) {
                        $this->close($peer);
                    }

                    return;
                }

                $head = substr($buffer, 0, $headEnd);
                $length = preg_match('/^content-length:\s*(\d+)/mi', $head, $match) === 1 ? (int) $match[1] : 0;

                if (strlen($buffer) - $headEnd - 4 < $length) {
                    return;
                }

                // The whole request is here: no more reading from this connection.
                $this->unwatch($peer);
                $path = preg_match('#^\S+ (\S+)#', $head, $line) === 1 ? $line[1] : '';
                $body = json_decode(substr($buffer, $headEnd + 4, $length), true);
                $body = is_array($body) ? $body : [];
                $this->calls[] = ['path' => $path, 'body' => $body, 'head' => $head];
                $answer = ($this->handler)($path, $body);

                if ($answer === 'hang') {
                    return;
                }

                if (!is_array($answer)) {
                    $this->close($peer);

                    return;
                }

                $text = is_array($answer[1]) ? (string) json_encode($answer[1], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $answer[1];
                $pending = "HTTP/1.1 {$answer[0]} Answer\r\nContent-Type: application/json\r\nContent-Length: " . strlen($text) . "\r\nConnection: close\r\n\r\n{$text}";

                // Written as the socket takes it: the client reading it is on this same loop.
                $this->watchers[(int) $peer] = Loop::get()->onWritable($peer, function ($writable) use (&$pending): void {
                    $written = fwrite($writable, $pending);

                    if ($written === false) {
                        $this->close($writable);

                        return;
                    }

                    $pending = substr($pending, $written);

                    if ($pending === '') {
                        $this->close($writable);
                    }
                });
            });
        });

        $name = (string) stream_socket_get_name($server, false);

        return 'http://' . $name;
    }

    /** @param resource $stream */
    private function unwatch(mixed $stream): void
    {
        $id = (int) $stream;

        if (isset($this->watchers[$id])) {
            Loop::get()->cancel($this->watchers[$id]);
            unset($this->watchers[$id]);
        }
    }

    /** @param resource $stream */
    private function close(mixed $stream): void
    {
        $this->unwatch($stream);

        if (is_resource($stream)) {
            fclose($stream);
        }
    }

    public function stop(): void
    {
        foreach ($this->open as $stream) {
            if (is_resource($stream)) {
                $this->close($stream);
            }
        }

        $this->open = [];
    }
}
