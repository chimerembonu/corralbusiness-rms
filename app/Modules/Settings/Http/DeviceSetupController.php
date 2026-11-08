<?php

namespace App\Modules\Settings\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * /device-setup (Complete Edition E9.1): helps a till, tablet or phone trust the restaurant's own certificate,
 * then sends it on to the secure address. While HTTPS is on, every plain-http visit lands here first: the page
 * checks whether this device already trusts the certificate and, if so, moves straight on.
 */
class DeviceSetupController
{
    public function show(Request $request): View
    {
        $https = (bool) config('rms.https.enabled');
        $ca = $this->read((string) config('rms.https.ca_certificate'));
        $server = $this->read((string) config('rms.https.server_certificate'));
        $port = (int) config('rms.https.port', 443);
        $origin = 'https://'.$request->getHost().($port === 443 ? '' : ':'.$port);

        return view('settings::device-setup', [
            'https' => $https && $ca !== null,
            'secure' => $request->isSecure(),
            'origin' => $origin,
            'next' => $this->safePath((string) $request->query('next', '/')),
            'fingerprint' => $ca ? $this->fingerprint($ca) : null,
            'endsAt' => $server ? $this->endsAt($server) : null,
            'device' => $this->device((string) $request->userAgent()),
        ]);
    }

    public function certificate(): Response
    {
        $ca = $this->read((string) config('rms.https.ca_certificate'));
        abort_unless(config('rms.https.enabled') && $ca !== null, 404);

        // This content type makes iPhones/iPads offer to install it, and Android/Windows/Mac open their importer.
        return response($ca, 200, [
            'Content-Type' => 'application/x-x509-ca-cert',
            'Content-Disposition' => 'attachment; filename="corralbusiness-rms-certificate.crt"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Only a path on this server: never somewhere else (no open redirect). */
    private function safePath(string $next): string
    {
        if ($next === '' || $next[0] !== '/' || str_starts_with($next, '//') || str_contains($next, '\\') || str_starts_with($next, '/device-setup')) {
            return '/';
        }

        return $next;
    }

    private function read(string $path): ?string
    {
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            return null;
        }
        $pem = (string) file_get_contents($path);

        return openssl_x509_read($pem) ? $pem : null;
    }

    /** "AB:CD:…" — the same as the server's `scripts/https.sh status` prints, to compare on the device. */
    private function fingerprint(string $pem): string
    {
        return implode(':', str_split(strtoupper((string) openssl_x509_fingerprint($pem, 'sha256')), 2));
    }

    private function endsAt(string $pem): ?Carbon
    {
        $info = openssl_x509_parse($pem);

        return isset($info['validTo_time_t']) ? Carbon::createFromTimestamp($info['validTo_time_t']) : null;
    }

    private function device(string $agent): string
    {
        return match (true) {
            (bool) preg_match('/iPhone|iPad|iPod/i', $agent), str_contains($agent, 'Macintosh') && str_contains($agent, 'Mobile') => 'ios',
            str_contains($agent, 'Android') => 'android',
            str_contains($agent, 'CrOS') => 'chromebook',
            str_contains($agent, 'Windows') => 'windows',
            str_contains($agent, 'Macintosh') => 'mac',
            default => 'android',
        };
    }
}
