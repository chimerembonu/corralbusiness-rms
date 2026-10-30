<?php

namespace App\Modules\Printing\Drivers;

use App\Modules\Printing\Contracts\PrinterDriver;
use App\Modules\Printing\Documents\PrintDocument;
use App\Modules\Printing\Exceptions\PrintFailedException;
use App\Modules\Printing\Models\Printer;
use App\Modules\Printing\Services\EscPosRenderer;
use Throwable;

/** ESC/POS over the network (LAN thermal printers, default port 9100). */
class NetworkEscPos implements PrinterDriver
{
    public function __construct(private readonly EscPosRenderer $renderer, private readonly int $timeoutSeconds = 5) {}

    public function print(Printer $printer, PrintDocument $document): void
    {
        [$host, $port] = $printer->networkAddress();
        $bytes = $this->renderer->render($document, $printer->paper_width);

        $socket = @fsockopen($host, $port, $errno, $errstr, $this->timeoutSeconds);

        if ($socket === false) {
            throw new PrintFailedException(__('printing::printing.errors.unreachable', ['address' => "{$host}:{$port}", 'error' => trim($errstr) ?: $errno]));
        }

        try {
            stream_set_timeout($socket, $this->timeoutSeconds);
            $written = @fwrite($socket, $bytes);

            if ($written === false || $written < strlen($bytes)) {
                throw new PrintFailedException(__('printing::printing.errors.write_failed', ['address' => "{$host}:{$port}"]));
            }
        } catch (Throwable $e) {
            throw $e instanceof PrintFailedException ? $e : new PrintFailedException($e->getMessage(), 0, $e);
        } finally {
            fclose($socket);
        }
    }

    public function completesImmediately(): bool
    {
        return true;
    }
}
