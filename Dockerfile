FROM php:8.3-apache

# 安装系统依赖和 PHP 扩展
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libwebp-dev \
    libmagickwand-dev \
    libzip-dev \
    unzip \
    git \
    && rm -rf /var/lib/apt/lists/*

# 安装 PHP 扩展
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) gd \
    && docker-php-ext-install fileinfo \
    && docker-php-ext-install exif \
    && docker-php-ext-install zip

# 安装 ImageMagick
RUN pecl install imagick \
    && docker-php-ext-enable imagick

# 启用 Apache mod_rewrite
RUN a2enmod rewrite

# 设置 Apache 配置
RUN echo '<Directory /var/www/html>\n\
    Options -Indexes +FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/litepic.conf \
    && a2enconf litepic

# 上传限制
RUN echo "upload_max_filesize = 50M\n\
post_max_size = 52M\n\
max_file_uploads = 50\n\
memory_limit = 256M" > /usr/local/etc/php/conf.d/litepic.ini

# 工作目录
WORKDIR /var/www/html

# 复制项目文件
COPY . /var/www/html/

# 创建必要目录并设置权限
RUN mkdir -p /var/www/html/uploads \
    /var/www/html/data \
    /var/www/html/logs \
    /var/www/html/data/challenges \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/uploads \
    && chmod -R 755 /var/www/html/data \
    && chmod -R 755 /var/www/html/logs

# 如果 .env 不存在，从模板复制
RUN if [ ! -f /var/www/html/.env ]; then \
    cp /var/www/html/.env.example /var/www/html/.env; \
    fi

EXPOSE 80

CMD ["apache2-foreground"]
