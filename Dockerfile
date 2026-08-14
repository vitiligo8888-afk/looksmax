# Flarum 1.8.x on PHP 8.3 — nginx + php-fpm in one container, supervised.
#
# Built rather than pulled: the widely-referenced community image
# (mondedie/docker-flarum) is a 404 on GitHub, and a self-built image is what a
# heavily-customized install needs anyway — local path repositories for our own
# extensions, and composer available at runtime to add/remove them.
FROM php:8.3-fpm-alpine

# libwebp and libavif are not optional decoration. Without them GD reports
# `WebP Support =>` empty, Intervention Image cannot decode the file, and Flarum
# rejects the upload with "The avatar must be an image" — which is what a user
# sees for any .webp, and .webp is what Chrome saves images as by default. Same
# story for .avif, which phones increasingly produce. Measured on the running
# container before this change: WebP, AVIF and XPM all off.
RUN apk add --no-cache \
      nginx supervisor git unzip icu-dev libzip-dev libpng-dev libjpeg-turbo-dev \
      freetype-dev oniguruma-dev libwebp-dev libavif-dev $PHPIZE_DEPS \
 && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp --with-avif \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql gd exif zip intl opcache bcmath \
 && apk del $PHPIZE_DEPS \
 && rm -rf /var/cache/apk/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Flarum needs these; the defaults are too small for extension installs, which
# run composer inside the request lifecycle from the admin panel.
RUN { \
      # 1024M, not the 512M I first guessed: a prior live test on this stack
      # exhausted the default 128M compiling the merged JS/CSS bundle for 150+
      # extensions, and 512M leaves little headroom above that.
      echo "memory_limit=1024M"; \
      # stock php:8.3 prints deprecation notices from older extensions straight
      # into the HTML, corrupting the response before Flarum's error handler
      # sees it. Flarum logs instead.
      echo "display_errors=Off"; \
      echo "upload_max_filesize=32M"; \
      echo "post_max_size=32M"; \
      echo "max_execution_time=300"; \
      echo "opcache.enable=1"; \
      echo "opcache.validate_timestamps=1"; \
    } > /usr/local/etc/php/conf.d/zz-flarum.ini

COPY nginx.conf /etc/nginx/nginx.conf
COPY supervisord.conf /etc/supervisord.conf
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /flarum/app
EXPOSE 80
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
