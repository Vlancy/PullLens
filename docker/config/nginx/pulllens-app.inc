# The application server body, shared by the plain-HTTP server and by the TLS server
# that install_tls.sh generates. It lives in a .inc rather than a .conf so that Nginx's
# `include /etc/nginx/conf.d/*.conf` does not load it as a server in its own right.
#
# Anything here must be valid inside a `server { }` block.

root /var/www/html/public;
index index.php index.html;

# Nginx resolves an upstream name once, at startup, and then holds the address for the
# life of the process. Recreating the app container hands it a new one, and every
# request comes back 502 until Nginx is restarted. Resolving through a variable against
# Docker's embedded DNS re-resolves on a timer instead, so a deploy heals itself.
resolver 127.0.0.11 valid=10s ipv6=off;

client_max_body_size 50M;
fastcgi_buffer_size 32k;
fastcgi_buffers 16 16k;

location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location ~ \.php$ {
    set $upstream_app app:9000;
    fastcgi_pass $upstream_app;
    fastcgi_index index.php;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME /var/www/html/public$fastcgi_script_name;
    fastcgi_param DOCUMENT_ROOT /var/www/html/public;

    # $pulllens_https is "on" only in the TLS server. Laravel reads it to decide
    # whether the request was secure, which is what keeps generated links, the
    # canonical URL and the Secure cookie flag on https:// once a certificate is in
    # place - without it the app would advertise http:// from behind its own TLS.
    fastcgi_param HTTPS $pulllens_https if_not_empty;
}

location ~ /\.(?!well-known).* {
    deny all;
}
