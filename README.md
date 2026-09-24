# wanportal-addon-nb

NetBox sidecar for wanportal. One container runs Apache, PHP and Ansible. The
wanportal reverse proxy fronts it over a shared docker network, so the sidecar
never publishes a host port.

## What is inside

| Path in container | Serves |
| --- | --- |
| `/var/www/localhost/htdocs/nb` | addon tree at `/nb/` (`RewriteBase /nb/`): certificate console, VIP builder, sites report, cloud-api |
| `/var/www/localhost/htdocs/health.php` | `GET /health` probe |
| `/opt/ansible-venv` | Python venv: ansible, ansible-vault, dnspython, fqdn |

Collections in the venv: `community.crypto`, `community.general`,
`netbox.netbox`. The app prepends `VENV_PATH/bin` to PATH when it shells out
to `ansible-playbook`, so the venv location matters.

The certificate console drives four flows against NetBox:

- Build: generates a private key and CSR, reuses an existing key from NetBox,
  and creates the DNS record if it is missing. Returns the CSR for a CA.
- Show: retrieves and decrypts the certificate, private key and passphrase for
  an FQDN.
- Import: validates a signed certificate against the stored private key and
  saves it to NetBox.
- Self-sign: generates a self-signed certificate from the stored key for
  testing. It is not stored in NetBox.

A sites report under `/nb/reports/sites.php` lists NetBox sites. The cloud-api
is a small IP reservation API for callers outside the portal.

## VIP builder

`/nb/vip.php` is a second tool in the same tree. It builds an F5 VIP as one
JSON build document, shows it as a diagram, and stores it on an existing
NetBox IP address. The playbook reads the stored build back through the API.

Three NetBox custom fields hold the state, all under the group name VIP:

| Custom field | On | Content |
| --- | --- | --- |
| `vip_build` | IP address | JSON object, `{"build":...}` |
| `vip_ssl` | IP address | object link to its netbox-dns record; null when the build has no SSL Common Name |
| `vip_address` | DNS record | object link back to the IP address |

There is no `vip_fqdn` custom field. API responses derive the hostname string
from the `vip_ssl` link.

The API spec is `app/vip-openapi.yaml`, browsable at `/nb/vip-swagger`. The
page and its API are specified in `SPEC-vipbuilder.md`. F5 profile names and
limit choices come from `config.php` — the `VIP_*` constants and the
same-named environment variables; the shipped defaults are the builtin
`/Common` objects.

## Environment

Configuration comes from the environment. At container start the entrypoint
writes `config.php` files for the app tree and for cloud-api, but only
when the file is missing. The generated files read the
environment themselves, so no secret is written to disk or baked into the
image. If the app tree is bind-mounted read-only, the entrypoint logs a
warning and continues; mount your own `config.php` in that case.

| Variable | Used for | Required |
| --- | --- | --- |
| `NETBOX_URL` | NetBox base URL, for the app and cloud-api config. Must be reachable from the docker network the sidecar joins, so not `localhost`. | yes |
| `NETBOX_TOKEN` | NetBox API token. Used by the cloud-api config and the sites report. | yes |
| `VAULT_PASS` | ansible-vault passphrase. Decrypts and encrypts the private key values stored in NetBox custom fields. Every certificate flow uses it. | yes |
| `VENV_PATH` | venv location. Defaults to `/opt/ansible-venv`. | no |
| `VIP_*` | Optional VIP builder overrides: F5 object names (`VIP_PROFILE_HTTP`, `VIP_PROFILE_TCP`, `VIP_MONITOR_TCP`, `VIP_MONITOR_PING`, `VIP_PERSIST_COOKIE`, `VIP_PERSIST_SOURCE`, `VIP_IRULE_HTTPS_REDIRECT`) and limit choices (`VIP_LIMIT_HOSTS`). See `app/config.php.example` and `SPEC-vipbuilder.md`. | no |
| `CLOUD_API_BEARER_TOKEN` | Caller-facing bearer token for the cloud-api. | cloud-api auth |

Secrets live in `.env` next to the compose file:

```
NETBOX_URL=https://netbox.example
NETBOX_TOKEN=<a NetBox API token>
VAULT_PASS=<output of: openssl rand -hex 32>
CLOUD_API_BEARER_TOKEN=<token for cloud-api callers>
```

Keep `.env` out of version control. The shipped `.gitignore` already excludes
it.

Rotating `VAULT_PASS` makes existing vaulted private keys unreadable. If
NetBox already holds certificates, re-encrypt them with the new passphrase
instead of switching the value over.

## Certificate subject

The CSR subject details live in one place only: `config.php`. It defines
`SSL_CSR_COUNTRY`, `SSL_CSR_STATE`, `SSL_CSR_LOCALITY`, `SSL_CSR_ORG`,
`SSL_CSR_OU` (plus optional `SSL_KEY_SIZE`, default 4096, and `SSL_VAULT_ID`,
default `netops`), each read from the same-named environment variable.
`run_script()` exports them to the Ansible wrappers and the playbooks read
them back with `lookup('env', ...)`, so setting the environment variables -
e.g. in `.env` next to the compose file - changes every certificate build.
Defaults: `US`, `California`, `Example City`, `Example Org`, `IT`. The
playbooks hardcode no subject values.

## Attach to the portal

Define the service in the portal `docker-compose.override.yml` (same
`netops` network, `expose: ["80"]`, no host `ports`). Build and start
from the portal directory, not this tree:

```sh
cd /path/to/wanportal
docker compose up --build -d wanportal-addon-nb
docker exec wanportal /usr/sbin/httpd -k graceful
```

`NETBOX_URL` must be reachable from the sidecar container, not
`localhost`.

On the wanportal proxy, scope the prefix header and pair ProxyPass:

```apache
<Location /nb/>
    RequestHeader set X-Forwarded-Prefix "/nb"
</Location>
ProxyPass        /nb/ http://wanportal-addon-nb:80/nb/
ProxyPassReverse /nb/ http://wanportal-addon-nb:80/nb/
```

Keep the trailing slashes. `/nb/...` maps 1:1 onto the container,
including `/nb/cloud-api/`.

Health: `GET /health` on the sidecar (not through the portal prefix)
returns HTTP 200 with `{"status":"ok"}`.

A 500 / AH00898 on `/nb/` means Apache cannot resolve
`wanportal-addon-nb` (wrong network or compose run from this directory).
AH00035 is host directory execute bits on the portal bind-mount.

## Adding pages without touching the SPA

This addon is PHP. You can add tools without Vue, without AddonFrame, and
without a SPA rebuild.

1. Put a page under `app/` so it is served at `/nb/...` (for example
   `app/reports/foo.php` at `/nb/reports/foo.php`).
2. Link it in `app/src/chrome.php` (topnav). Those links are
   hardcoded there, not read from a config file.
3. Optional: in wanportal `htdocs/config.json`, add a menu item with
   `"href": "/nb/reports/foo.php"`. Same-origin hrefs open in this tab. That
   leaves the Vue shell and loads the PHP page. You do not need a hash route
   or an iframe.

The `/nb/` ProxyPass already covers every path under that prefix, including
`/nb/cloud-api/`. Do not add a second prefix unless you are attaching a
different sidecar.

## NetBox custom fields

Certificate material is stored in netbox-dns record custom fields. Create the
fields on the object type `netbox_dns.record` (not `dcim.site`), and install
the netbox-dns plugin in NetBox before first use.

| Custom field | Content |
| --- | --- |
| `ssl_certificate` | PEM certificate text |
| `ssl_privkey` | JSON object `{privkey, passphrase}`, each value ansible-vault encrypted |
| `ssl_expiry` | expiry date, `YYYY-MM-DD` |
| `ssl_sans` | subject alt names, comma separated |
| `ssl_issuer` | issuer common name |

The playbooks read and patch these fields at
`/api/plugins/netbox-dns/records/:id/`. If no record exists for an FQDN, the
build flow creates one (type `A`) in the matching zone.

## Authentication

Bearer tokens are NetBox tokens. Callers of the `/nb/` API send:

```
Authorization: Bearer <netbox-api-token>
```

The sidecar validates the token against NetBox, then passes the same token to
the playbooks for their NetBox API calls. One token does both jobs, and
nothing is stored on the server. The browser UI keeps the token in
localStorage.

The cloud-api is separate. Callers present `CLOUD_API_BEARER_TOKEN`, and its
own NetBox calls use `NETBOX_TOKEN`.

## Operations

- The container runs unprivileged. Apache runs as the `apache` user and owns
  the served tree, the pid dir and the logs.
- `mod_rewrite`, `mod_headers` and the PHP module are enabled. `AllowOverride`
  is `All`, so the shipped `.htaccess` files apply: Authorization passthrough,
  CORS headers and front-controller routing.
- Playbooks run on `localhost` inside the container (`ansible_connection=local`),
  and `openssl` is present for certificate work.
- Update the addon by replacing `app/` and rebuilding the image.