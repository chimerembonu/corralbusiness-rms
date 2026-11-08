<?php

/*
 * Complete Edition E9.1 — HTTPS on the restaurant network: the device setup page helps each device trust the
 * restaurant's own certificate (made on the server by scripts/https.sh), then sends it to the secure address.
 */
beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/rms-certs-'.uniqid();
    mkdir($this->dir);
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    $csr = openssl_csr_new(['commonName' => 'CorralBusiness RMS – test'], $key);
    openssl_x509_export(openssl_csr_sign($csr, null, $key, 365), $pem);
    file_put_contents($this->dir.'/ca.crt', $pem);
    file_put_contents($this->dir.'/server.crt', $pem);
    $this->pem = $pem;
    config(['rms.https.ca_certificate' => $this->dir.'/ca.crt', 'rms.https.server_certificate' => $this->dir.'/server.crt', 'rms.https.port' => 443]);
});

afterEach(function () {
    array_map('unlink', glob($this->dir.'/*'));
    rmdir($this->dir);
});

it('says nothing is needed while HTTPS is off, and offers no certificate', function () {
    config(['rms.https.enabled' => false]);
    $this->get('/device-setup?next=/pos')->assertOk()->assertSee('needs nothing')->assertSee('href="/pos"', false);
    $this->get('/device-setup/certificate')->assertNotFound();
});

it('shows the steps for this kind of device, the fingerprint, and checks before moving on to https', function () {
    config(['rms.https.enabled' => true]);
    $fingerprint = implode(':', str_split(strtoupper(openssl_x509_fingerprint($this->pem, 'sha256')), 2));

    $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 13; SM-T220) AppleWebKit/537.36 Chrome/120 Safari/537.36')
        ->get('http://192.168.8.10/device-setup?next=/pos')->assertOk()
        ->assertSee('Download certificate')->assertSee($fingerprint)
        ->assertSee('Android tablet or phone')->assertSee('Install anyway')
        ->assertSee('"https:\/\/192.168.8.10\/pos"', false)          // where a trusting device goes next
        ->assertSee('<details class="mt-3 rounded-xl bg-slate-800/60 p-3"  open', false);

    $this->withHeader('User-Agent', 'Mozilla/5.0 (iPad; CPU OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Safari/604.1')
        ->get('http://192.168.8.10/device-setup')->assertSee('Certificate Trust Settings');

    $this->get('/device-setup/certificate')->assertOk()
        ->assertHeader('Content-Type', 'application/x-x509-ca-cert')
        ->assertHeader('Content-Disposition', 'attachment; filename="corralbusiness-rms-certificate.crt"')
        ->assertContent($this->pem);
});

it('only ever sends a device on to a page of this server', function () {
    config(['rms.https.enabled' => true, 'rms.https.port' => 8443]);
    foreach (['//evil.example/x', 'https://evil.example', '/\\evil.example', '/device-setup?next=/x'] as $next) {
        $this->get('http://192.168.8.10/device-setup?next='.urlencode($next))->assertSee('"https:\/\/192.168.8.10:8443\/"', false)->assertDontSee('evil.example');
    }
});

it('stays usable when the server says HTTPS is on but the certificate file is missing', function () {
    config(['rms.https.enabled' => true, 'rms.https.ca_certificate' => $this->dir.'/missing.crt']);
    $this->get('/device-setup')->assertOk()->assertSee('needs nothing');
    $this->get('/device-setup/certificate')->assertNotFound();
});
