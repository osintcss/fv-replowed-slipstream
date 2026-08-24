FROM php:8.4-apache

# Laravel's MySQL driver, archive support, and the libraries required to
# compile their corresponding PHP extensions.
RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    gnupg \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libwebp-dev \
    libzip-dev \
    zip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install pdo_mysql mysqli pcntl zip gd \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Vite requires Node during the image build. NodeSource provides a current
# supported Node release for this Debian base image.
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get update \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Hashed game assets are content-addressed: a changed file receives a new
# URL. Let browsers and the legacy Flash runtime keep those icons locally,
# while leaving dynamic PHP/AMF endpoints uncached.
COPY apache2-config/hashed-assets-cache.conf /etc/apache2/conf-available/hashed-assets-cache.conf
RUN a2enconf hashed-assets-cache

WORKDIR /var/www/html

# Keep the image self-contained for PHP dependencies and application code,
# but only send files it needs at runtime. The 20 GB FarmVille asset archive
# is mounted from the host by Docker Compose and is intentionally excluded
# from the build context.
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY public ./public
COPY resources ./resources
COPY routes ./routes
COPY scripts ./scripts
COPY artisan composer.json composer.lock package.json package-lock.json phpunit.xml postcss.config.js tailwind.config.js vite.config.js .env.example ./

# The archived quest settings let Flash predict crop/harvest progress. Our
# server already persists these actions, so make the client consume the
# authoritative QuestComponent returned with each AMF response instead.
RUN php scripts/patch-quest-settings.php

# Farm-size expansion used Facebook neighbour gates. Keep the original coin
# progression, but remove that unavailable social prerequisite from the XML
# that Flash uses to populate the Market's Farm Expansions category.
RUN php -d memory_limit=512M scripts/patch-farm-expansion-settings.php

# Some client experiment assignments request the reduced locale filename even
# when the complete locale is the only archive asset available. Both contain
# the same localization contract for this deployment.
RUN if [ -f public/farmville/xml/gz/v855038/en_US.swf ] && [ ! -e public/farmville/xml/gz/v855038/en_US_min.swf ]; then \
        ln -s en_US.swf public/farmville/xml/gz/v855038/en_US_min.swf; \
    fi

# Give the locale loader a versioned path. This bypasses CDN/browser caches
# without duplicating the XML asset tree.
RUN if [ -d public/farmville/xml/gz/v855038 ] && [ ! -e public/farmville/xml/gz/v855038-locale ]; then \
        ln -s v855038 public/farmville/xml/gz/v855038-locale; \
    fi

# Flash persists downloaded SWFs aggressively. A new full-tree alias forces
# it to fetch the complete archived locale movie without breaking its other
# XML, settings, or asset lookups.
RUN if [ -d public/farmville/xml/gz/v855038 ] && [ ! -e public/farmville/xml/gz/v855038-locale-v2 ]; then \
        ln -s v855038 public/farmville/xml/gz/v855038-locale-v2; \
    fi

RUN if [ -d public/farmville/xml/gz/v855038 ] && [ ! -e public/farmville/xml/gz/v855038-locale-v3 ]; then \
        ln -s v855038 public/farmville/xml/gz/v855038-locale-v3; \
    fi

# Flash's XML cache is keyed by path on some legacy players and ignores a
# query-string revision. Give the patched item catalog a fresh path so a
# rebuilt image cannot reuse the pre-patch expansion definitions.
RUN if [ -d public/farmville/xml/gz/v855038 ] && [ ! -e public/farmville/xml/gz/v855038-expansions-v1 ]; then \
        ln -s v855038 public/farmville/xml/gz/v855038-expansions-v1; \
    fi

RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && cp .env.example .env \
    && composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader \
    && npm ci \
    && npm run build \
    && rm -rf node_modules \
    && php artisan key:generate --force \
    && chown -R www-data:www-data storage bootstrap/cache \
    && if [ -d public/farmville/flashservices/amfphp/Plugins/AmfphpLogger ]; then \
        touch public/farmville/flashservices/amfphp/Plugins/AmfphpLogger/amfphplog.log; \
        chown www-data:www-data public/farmville/flashservices/amfphp/Plugins/AmfphpLogger/amfphplog.log; \
        chmod 664 public/farmville/flashservices/amfphp/Plugins/AmfphpLogger/amfphplog.log; \
    fi
