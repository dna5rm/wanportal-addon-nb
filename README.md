# wanportal-addon-nb

NetBox sidecar for wanportal. One container runs Apache, PHP and Ansible. The
wanportal reverse proxy fronts it over a shared docker network, so the sidecar
never publishes a host port.

## What is inside

| Path in container | Serves |
| --- | --- |
| `/var/www/localhost/htdocs/nb` | addon tree at `/nb/` (`RewriteBase /nb/`): certificate console, sites report, cloud-api |
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
Defaults: `US`, empty state and locality, `Example Org`, `IT`. The playbooks
hardcode no subject values.

## Attach to the portal

1. Join the portal compose network. `netops` is a placeholder; use the real
   external network name:

   ```yaml
   networks:
     netops:
       external: true
       name: netops
   ```

2. Fill in `.env` and run `docker compose up -d --build`. The service listens
   on port 80 inside the docker network only. Do not add a `ports:` mapping;
   the portal proxy is the only entry point.

3. On the wanportal proxy, route to the container by service name:

   ```apache
   ProxyPass        /nb/             http://wanportal-addon-nb/nb/
   ProxyPassReverse /nb/             http://wanportal-addon-nb/nb/
   ```

   Keep the trailing slashes. `/nb/...` then maps 1:1 onto the container
   paths, including the cloud-api at `/nb/cloud-api/`.

4. Health check for the proxy or load balancer: `GET /health` returns HTTP 200
   with `{"status":"ok"}`.

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
Authorization: Bearer <your NetBox API token>
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