FROM php:8.3-cli
RUN apt-get update && apt-get install -y --no-install-recommends libzip-dev \
	&& docker-php-ext-install zip \
	&& rm -rf /var/lib/apt/lists/*
RUN pecl install pcov && docker-php-ext-enable pcov
ADD https://github.com/infection/infection/releases/download/0.29.14/infection.phar /usr/local/bin/infection
RUN chmod a+rx /usr/local/bin/infection
