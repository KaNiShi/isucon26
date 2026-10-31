.PHONY: install run run-notifications test lint

# 依存パッケージを composer.lock のとおりに vendor/ へ入れる
install:
	composer install --no-dev --optimize-autoloader --no-interaction

# php-fpm をフォアグラウンドで起動する（nginx が :8080 で受けて FastCGI で渡す）
run:
	ISUREAL_PHP_FPM_ERROR_LOG=/proc/self/fd/2 php-fpm --nodaemonize --fpm-config php-fpm.conf

# SSE 通知を非同期 socket の専用イベントループで受ける
run-notifications:
	php bin/notifications-server.php

test:
	composer install --no-interaction
	vendor/bin/phpunit

lint:
	vendor/bin/phpstan analyse --memory-limit=1G
	vendor/bin/phpcs
