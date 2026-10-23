# PHP for wp-harness on a computer without PHP. Built once by `wp-harness up`.
FROM php:8.3-cli
RUN apt-get update \
	&& apt-get install -y --no-install-recommends git unzip curl ca-certificates libzip-dev libicu-dev libpng-dev libjpeg-dev \
	&& docker-php-ext-configure gd --with-jpeg \
	&& docker-php-ext-install zip intl gd exif \
	&& rm -rf /var/lib/apt/lists/* \
	&& git config --global --add safe.directory '*'
