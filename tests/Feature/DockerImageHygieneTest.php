<?php

/*
 * Security review M8: the store-server image is built with `COPY . .`, so .dockerignore must keep
 * secrets, the git history and local data out of it. A copied image must not leak production secrets.
 */
it('keeps secrets, git history and local data out of the Docker image', function () {
    $ignored = collect(file(base_path('.dockerignore'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
        ->reject(fn (string $line) => str_starts_with($line, '#'))
        ->all();

    foreach (['.env', '.env.*', '.git', 'node_modules', 'vendor', 'storage/logs/*', 'storage/backups/*', 'storage/app/private/*', 'storage/app/public/*', 'storage/framework/sessions/*'] as $mustIgnore) {
        expect($ignored)->toContain($mustIgnore);
    }
    // The templates stay in: the installer copies .env.docker.example to .env on the server.
    expect($ignored)->toContain('!.env.example')->toContain('!.env.docker.example');
});

it('builds the image from the project root, where .dockerignore applies', function () {
    $compose = file_get_contents(base_path('docker-compose.yml'));

    expect($compose)->toContain("context: .\n")->and(file_get_contents(base_path('docker/Dockerfile')))->toContain('COPY . .');
});
