FROM php:8.1-fpm

# 设置工作目录
WORKDIR /var/www/html/backend

# 安装系统依赖
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    libcurl4-openssl-dev \
    && rm -rf /var/lib/apt/lists/*

# 安装 PHP 扩展
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd curl xml dom

# 安装 Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 复制 composer 文件
COPY composer.json ./

# 清理可能存在的 composer.lock 并重新生成
RUN rm -f composer.lock

# 安装 PHP 依赖（重新生成 lock 文件）
RUN composer install --no-dev --optimize-autoloader --no-interaction --ignore-platform-reqs

# 复制应用代码
COPY . .

# 确保目录权限正确
RUN chown -R www-data:www-data /var/www/html/backend \
    && chmod -R 755 /var/www/html/backend

# 创建日志目录
RUN mkdir -p logs && chmod 777 logs