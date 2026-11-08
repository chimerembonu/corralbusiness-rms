# Hand the request to Laravel (php-fpm) as /index.php.
fastcgi_pass app:9000;
include fastcgi_params;
fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;
fastcgi_param DOCUMENT_ROOT $realpath_root;
fastcgi_read_timeout 120s;
