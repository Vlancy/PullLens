FROM nginx:alpine

COPY docker/config/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=laravel /var/www/html/public /var/www/html/public
