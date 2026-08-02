FROM php:8.4-apache

# Laravel's MySQL driver, archive support, and the libraries required to
# compile their corresponding PHP extensions.
RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    gnupg \
    libzip-dev \
    zip \
    && docker-php-ext-install pdo_mysql mysqli pcntl zip \
    && a2enmod rewrite \
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

WORKDIR /var/www/html
COPY . .

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

RUN cp .env.example .env \
    && composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader \
    && npm ci \
    && npm run build \
    && rm -rf node_modules \
    && php artisan key:generate --force \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && if [ -d public/farmville/flashservices ]; then touch public/farmville/flashservices/amfphplog.log; chown www-data:www-data public/farmville/flashservices/amfphplog.log; chmod 664 public/farmville/flashservices/amfphplog.log; fi
