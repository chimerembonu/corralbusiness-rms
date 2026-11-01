<?php

namespace App\Modules\Printing\Contracts;

/** Checks whether a TCP port answers on a host (swapped for a fake in tests). */
interface PortProber
{
    /**
     * @param  array<int, string>  $hosts
     * @return array<int, string> the hosts whose port was open
     */
    public function open(array $hosts, int $port, float $timeoutSeconds = 0.4): array;

    /** The first non-loopback IPv4 address of this computer, with its prefix length (e.g. "192.168.8.10/24"), or null. */
    public function localAddress(): ?string;
}
