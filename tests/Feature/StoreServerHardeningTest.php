<?php

/*
 * Security review L5: nginx does not advertise its version, and the store server refuses to start
 * rather than listen on every network when RMS_BIND_IP is missing from a hand-made .env.
 */
it('hides the nginx version', function () {
    expect(file_get_contents(base_path('docker/nginx/default.conf')))->toContain('server_tokens off;');
});

it('requires RMS_BIND_IP instead of falling back to all interfaces', function () {
    $compose = file_get_contents(base_path('docker-compose.yml'));

    expect($compose)->toContain('${RMS_BIND_IP:?')
        ->and($compose)->not->toContain('RMS_BIND_IP:-')
        ->and(file_get_contents(base_path('.env.docker.example')))->toMatch('/^RMS_BIND_IP=\S+/m')
        ->and(file_get_contents(base_path('install.sh')))->toContain('RMS_BIND_IP=$LAN_IP');
});
