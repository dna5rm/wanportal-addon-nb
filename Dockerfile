# wanportal-addon-nb: NetBox PHP/Ansible addon sidecar.
# Public framework example: no secrets, no hostnames, no host ports.
# Runtime secrets are provided via the environment (docker-compose .env or
# equivalent); config.php files are generated at container start.

FROM alpine:3.21

ENV VENV_PATH=/opt/ansible-venv \
    HOME=/var/www

# System packages: Apache + PHP apache2 module, CLI tooling, Python for the
# Ansible venv. The build-only compiler toolchain lives in a virtual package
# that is removed after pip finishes; it is only needed when pip has to
# compile a package instead of downloading a wheel.
RUN apk add --no-cache \
      apache2 \
      php84-apache2 \
      php84-curl \
      php84-session \
      php84-json \
      php84-phar \
      php84-mbstring \
      php84-openssl \
      bash \
      curl \
      jq \
      openssl \
      python3 \
      py3-pip \
 && apk add --no-cache --virtual .ansible-build-deps \
      python3-dev gcc musl-dev make

# Ansible toolchain in a dedicated venv (the addon puts VENV_PATH/bin on PATH).
RUN python3 -m venv "$VENV_PATH" \
 && "$VENV_PATH/bin/pip" install --no-cache-dir --upgrade pip \
 && "$VENV_PATH/bin/pip" install --no-cache-dir ansible ansible-vault dnspython fqdn \
 && apk del .ansible-build-deps \
 && "$VENV_PATH/bin/ansible-galaxy" collection install \
      community.crypto community.general netbox.netbox

# Web root layout:
#   /var/www/localhost/htdocs/nb             -> full addon tree  (URL /nb/)
#   /var/www/localhost/htdocs/health.php     -> GET /health probe
# cloud-api is served under /nb/cloud-api straight out of the addon tree;
# no alias or second copy is needed.
COPY app/ /var/www/localhost/htdocs/nb/
RUN find /var/www/localhost/htdocs/nb -type f -name '*.sh' -exec chmod 755 {} +

RUN printf '<?php\nhttp_response_code(200);\nheader("Content-Type: application/json");\necho json_encode(["status" => "ok"]);\n' \
      > /var/www/localhost/htdocs/health.php \
 && ln -s /var/www/localhost/htdocs/nb /srv/htdocs

# Apache configuration:
#  - enable mod_rewrite (the addon .htaccess files rely on RewriteBase /nb/,
#    the cloud-api one on RewriteBase /nb/cloud-api/)
#  - AllowOverride All so the shipped .htaccess files apply
#  - drop auto-indexing from the default htdocs Options
#  - conf.d snippet: Authorization header passthrough, /health probe alias,
#    ServerName to keep logs clean
# mod_headers and the PHP module (conf.d/php84-module.conf) ship enabled.
RUN sed -i -e 's/^#LoadModule rewrite_module/LoadModule rewrite_module/' /etc/apache2/httpd.conf \
 && sed -i -e 's/AllowOverride None/AllowOverride All/' /etc/apache2/httpd.conf \
 && sed -i -e 's/Options Indexes FollowSymLinks/Options FollowSymLinks/' /etc/apache2/httpd.conf \
 && printf '%s\n' \
      'ServerName localhost' \
      'SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1' \
      'Alias /health /var/www/localhost/htdocs/health.php' \
      > /etc/apache2/conf.d/wanportal-addon-nb.conf

# Entrypoint generates config.php from env at start. Never bake secrets here.
COPY --chmod=755 docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh

# Unprivileged runtime: apache owns the served tree, pid dir and logs.
RUN chown -R apache:apache /var/www \
 && mkdir -p /var/run/apache2 /var/log/apache2 \
 && chown -R apache:apache /var/run/apache2 /var/log/apache2

USER apache

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]