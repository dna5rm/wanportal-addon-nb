#!/bin/sh
# docker-entrypoint.sh - wanportal-addon-nb
# Generates runtime config.php files from environment variables if they are
# missing, then starts Apache in the foreground. No secrets are baked into the
# image: every sensitive value is read from the environment at startup.

set -eu

NB_ROOT="/var/www/localhost/htdocs/nb"
CLOUD_ROOT="/var/www/localhost/htdocs/nb/cloud-api"

# ---------------------------------------------------------------------------
# NetBox addon config (root of the /nb/ tree)
# ---------------------------------------------------------------------------
if [ ! -f "${NB_ROOT}/config.php" ]; then
  if cat > "${NB_ROOT}/config.php" <<PHPEOF
<?php
/**
 * config.php - generated at container start by docker-entrypoint.sh
 * Sensitive values come from the container environment.
 */

// NetBox
define('NETBOX_URL',  getenv('NETBOX_URL')  ?: '');

// Ansible Vault password - injected via Docker environment variable
define('VAULT_PASS',  getenv('VAULT_PASS')  ?: '');

// Certificate subject (CSR) - single source of truth, see config.php.example
define('SSL_CSR_COUNTRY',  getenv('SSL_CSR_COUNTRY')  ?: 'US');
define('SSL_CSR_STATE',    getenv('SSL_CSR_STATE')    ?: 'California');
define('SSL_CSR_LOCALITY', getenv('SSL_CSR_LOCALITY') ?: 'Example City');
define('SSL_CSR_ORG',      getenv('SSL_CSR_ORG')      ?: 'Example Org');
define('SSL_CSR_OU',       getenv('SSL_CSR_OU')       ?: 'IT');

// Certificate key size (bits) and ansible-vault id used for the private keys
define('SSL_KEY_SIZE',  getenv('SSL_KEY_SIZE')  ?: '4096');
define('SSL_VAULT_ID',  getenv('SSL_VAULT_ID')  ?: 'netops');

// Paths
define('ANSIBLE_PATH', 'src/ansible');
define('VENV_PATH', getenv('VENV_PATH') ?: '/opt/ansible-venv');

// Script execution timeout in seconds
define('SCRIPT_TIMEOUT', 300);

// VIP builder F5 object names. Builtin defaults; override via environment.
define('VIP_PROFILE_HTTP',         getenv('VIP_PROFILE_HTTP')         ?: '/Common/http');
define('VIP_PROFILE_TCP',          getenv('VIP_PROFILE_TCP')          ?: '/Common/tcp');
define('VIP_MONITOR_TCP',          getenv('VIP_MONITOR_TCP')          ?: '/Common/tcp');
define('VIP_MONITOR_PING',         getenv('VIP_MONITOR_PING')         ?: '/Common/gateway_icmp');
define('VIP_PERSIST_COOKIE',       getenv('VIP_PERSIST_COOKIE')       ?: '/Common/cookie');
define('VIP_PERSIST_SOURCE',       getenv('VIP_PERSIST_SOURCE')       ?: '/Common/source_addr');
define('VIP_IRULE_HTTPS_REDIRECT', getenv('VIP_IRULE_HTTPS_REDIRECT') ?: '/Common/_sys_https_redirect');

// Playbook limit choices. Empty keeps a free-text box. One option per line,
// or several options separated by semicolons. A comma inside an option is a pair.
define('VIP_LIMIT_HOSTS', getenv('VIP_LIMIT_HOSTS') ?: '');

?>
PHPEOF
  then
    echo "wanportal-addon-nb: generated ${NB_ROOT}/config.php from environment"
  else
    echo "wanportal-addon-nb: WARNING could not write ${NB_ROOT}/config.php (read-only mount?) - continuing" >&2
  fi
fi

# ---------------------------------------------------------------------------
# cloud-api config (served under /nb/cloud-api/)
# ---------------------------------------------------------------------------
if [ -d "${CLOUD_ROOT}" ] && [ ! -f "${CLOUD_ROOT}/config.php" ]; then
  if cat > "${CLOUD_ROOT}/config.php" <<PHPEOF
<?php
/**
 * config.php - generated at container start by docker-entrypoint.sh
 * Values come from the container environment.
 */

return [
    'api' => [
        'url'   => rtrim((string) (getenv('NETBOX_URL') ?: ''), '/') . '/api',
        'token' => (string) (getenv('NETBOX_TOKEN') ?: ''),
    ],
    'bearer_token' => (string) (getenv('CLOUD_API_BEARER_TOKEN') ?: ''),
    'debug'        => false,
    'require_auth' => true,
];
PHPEOF
  then
    echo "wanportal-addon-nb: generated ${CLOUD_ROOT}/config.php from environment"
  else
    echo "wanportal-addon-nb: WARNING could not write ${CLOUD_ROOT}/config.php (read-only mount?) - continuing" >&2
  fi
fi

# ---------------------------------------------------------------------------
# Start Apache in the foreground
# ---------------------------------------------------------------------------
exec httpd -DFOREGROUND