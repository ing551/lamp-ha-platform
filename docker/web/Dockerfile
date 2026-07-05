FROM php:8.0-fpm

# 换阿里云 Debian 源
RUN sed -i 's/deb.debian.org/mirrors.aliyun.com/g' /etc/apt/sources.list && \
    sed -i 's/security.debian.org/mirrors.aliyun.com/g' /etc/apt/sources.list

# 安装 Nginx + PHP 扩展
RUN apt-get update && \
    apt-get install -y nginx && \
    docker-php-ext-install mysqli pdo pdo_mysql && \
    pecl install redis && docker-php-ext-enable redis && \
    rm -rf /tmp/pear && \
    rm -rf /var/lib/apt/lists/*

# 删除 Nginx 默认站点（会和我们的配置冲突）
RUN rm -f /etc/nginx/sites-enabled/default

# 覆盖 Nginx 默认配置
COPY web/web-nginx.conf /etc/nginx/conf.d/default.conf

# 拷贝 WordPress 代码
COPY wordpress/wordpress /usr/share/nginx/html/wordpress

# 启动脚本
RUN echo '#!/bin/bash' > /start.sh && \
    echo 'php-fpm --daemonize' >> /start.sh && \
    echo 'nginx -g "daemon off;"' >> /start.sh && \
    chmod +x /start.sh && \
    chown -R www-data:www-data /usr/share/nginx/html/wordpress

CMD ["/start.sh"]
