<?php

namespace App\Modules\Printing\Services;

use App\Modules\Printing\Contracts\PortProber;

/** Non-blocking TCP connects, 64 at a time, so a /24 scan takes a couple of seconds. */
class SocketPortProber implements PortProber
{
    public function open(array $hosts, int $port, float $timeoutSeconds = 0.4): array
    {
        $found = [];
        foreach (array_chunk($hosts, 64) as $batch) {
            $sockets = [];
            foreach ($batch as $host) {
                $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 0, STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT);
                if ($socket) {
                    stream_set_blocking($socket, false);
                    $sockets[$host] = $socket;
                }
            }
            $deadline = microtime(true) + $timeoutSeconds;
            while ($sockets !== [] && microtime(true) < $deadline) {
                $write = array_values($sockets);
                $read = $except = [];
                $left = max(0.01, $deadline - microtime(true));
                if (@stream_select($read, $write, $except, 0, (int) ($left * 1_000_000)) === false) {
                    break;
                }
                foreach ($write as $socket) {
                    $host = array_search($socket, $sockets, true);
                    // A completed non-blocking connect is writable; a refused one reports an error on read.
                    if ($host !== false && @stream_socket_get_name($socket, true) !== false) {
                        $found[] = $host;
                    }
                    if ($host !== false) {
                        fclose($socket);
                        unset($sockets[$host]);
                    }
                }
            }
            foreach ($sockets as $socket) {
                fclose($socket);
            }
        }
        sort($found, SORT_NATURAL);

        return $found;
    }

    public function localAddress(): ?string
    {
        $output = @shell_exec(PHP_OS_FAMILY === 'Darwin' ? 'ifconfig 2>/dev/null' : 'ip -4 -o addr show 2>/dev/null');
        if (! $output) {
            return null;
        }
        if (PHP_OS_FAMILY === 'Darwin') {
            // "inet 192.168.8.10 netmask 0xffffff00" → /24
            if (preg_match_all('/inet (\d+\.\d+\.\d+\.\d+) netmask 0x([0-9a-f]{8})/i', $output, $m, PREG_SET_ORDER)) {
                foreach ($m as [$all, $ip, $mask]) {
                    if (! str_starts_with($ip, '127.')) {
                        return $ip.'/'.substr_count(str_pad(base_convert($mask, 16, 2), 32, '0', STR_PAD_LEFT), '1');
                    }
                }
            }

            return null;
        }
        if (preg_match_all('/inet (\d+\.\d+\.\d+\.\d+\/\d+)/', $output, $m)) {
            foreach ($m[1] as $cidr) {
                if (! str_starts_with($cidr, '127.')) {
                    return $cidr;
                }
            }
        }

        return null;
    }
}
