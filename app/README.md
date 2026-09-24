# certmgr — SSL Certificate Manager

certmgr is a small PHP application that manages SSL certificates end to end:
it generates private keys and CSRs, takes the signed certificate back once
the CA has issued it, mints self-signed certificates for testing, and hands
out whatever is on record. Everything is stored in NetBox — a certificate
lives in the custom fields of the hostname's DNS record — so there is no
certificate directory to back up and no repo to keep in sync. The crypto
work itself is done by Ansible playbooks that certmgr shells out to.

## What it can do

- **Build** — Generate a private key and CSR. If NetBox already holds a
  private key for the FQDN it is reused; if the DNS record does not exist
  yet it is created. The response contains the CSR to submit to your CA.
- **Show** — Retrieve the certificate, private key and passphrase for an
  FQDN straight from NetBox. The private key is decrypted on the way out.
- **Import** — Store a CA-signed certificate. The certificate is validated
  against the private key already on record (CN and public key must match)
  before it is written to NetBox.
- **Self-sign** — Mint a self-signed certificate (valid 365 days) from the
  private key on record, for smoke-testing while you wait for the real
  thing. The self-signed certificate is never stored in NetBox.

## How it works

```
request
  → index.php (router)
    → api/*.php (endpoint: auth + validation)
      → src/auth.php (Bearer = NetBox token, validated live)
      → src/utils.php run_script()
        → src/ansible/*_ssl_cert.sh (755) → ansible-playbook
          → NetBox API (read/write, using the caller's token)
```

**Auth model.** There is no certmgr credential. The Bearer token in the
`Authorization` header *is* the caller's NetBox API token. Each request
validates it live against NetBox, and the same token is then reused for
every NetBox read/write the request performs and passed to the playbooks as
`NETBOX_TOKEN`. A caller can only ever do through certmgr what their NetBox
token already allows them to do directly.

**Storage.** Certificates live in netbox-dns record custom fields:

| Custom field     | Contents                                            |
| ---              | ---                                                 |
| `ssl_certificate` | PEM text of the certificate                       |
| `ssl_privkey`    | JSON blob with the Ansible-Vault-encrypted private key and its passphrase |

Private keys are encrypted with Ansible Vault (vault ID `netops`) before
they are written to NetBox. When a build needs a record for a brand-new
FQDN, an A record is created in the most specific matching zone; the IP is
resolved from DNS, falling back to the loopback address when the name does
not resolve yet (correct it in NetBox afterwards).

## Directory layout

```
./
├── .htaccess                    # Apache routing (RewriteBase /nb/), CORS + security headers
├── index.php                    # Router: maps URI paths to api/*.php, serves the UI at /
├── frontend.php                 # Web UI — the certificate console (uses src/chrome.php)
├── frontend.html                # Legacy static UI, kept for reference
├── config.php.example           # Configuration template — copy to config.php
├── openapi.yaml                 # OpenAPI 3.0 spec for the API
├── swagger.html                 # Swagger UI for the spec
├── vip.php                      # VIP builder page (see SPEC-vipbuilder.md)
├── vip-openapi.yaml             # OpenAPI 3.0 spec for the VIP builder API
├── vip-swagger.html             # Swagger UI for vip-openapi.yaml
├── requirements.txt             # Python deps for the Ansible playbooks
├── api/
│   ├── build.php                # POST /api/build    — new private key + CSR
│   ├── show.php                 # POST /api/show     — retrieve stored cert
│   ├── import.php               # POST /api/import   — store a CA-signed cert
│   ├── selfsign.php             # POST /api/selfsign — mint a self-signed cert
│   └── vip.php                  # /api/vip.php       — VIP builder: lookups, validate, save
└── src/
    ├── auth.php                 # Bearer token auth (the token is a NetBox token)
    ├── utils.php                # Shared plumbing: content negotiation, run_script()
    ├── chrome.php               # Shared page chrome for the sidecar web pages
    ├── vip_profiles.php         # Site-overridable F5 object names for the VIP builder
    ├── ansible/
    │   ├── build.yml            # Playbook: generate/reuse private key, create DNS record, CSR
    │   ├── import.yml           # Playbook: validate signed cert against the key, store it
    │   ├── selfsign.yml         # Playbook: mint a self-signed cert
    │   ├── build_ssl_cert.sh    # Wrapper called by api/build.php   (must be 755)
    │   ├── show_ssl_cert.sh     # Wrapper called by api/show.php    (must be 755)
    │   ├── import_ssl_cert.sh   # Wrapper called by api/import.php  (must be 755)
    │   └── selfsign_ssl_cert.sh # Wrapper called by api/selfsign.php (must be 755)
    └── netbox/
        ├── client.php           # Low-level NetBox API curl wrapper (GET/POST/PATCH/PUT/DELETE)
        └── helpers.php          # Zones, DNS record lookup/create/update/upsert, token check
```

The same tree also hosts three more entry points behind the same mount point:

- `vip.php` + `api/vip.php` — the VIP builder page and its API (see
  `SPEC-vipbuilder.md` at the repo root and `vip-openapi.yaml`)
- `reports/` — a NetBox sites table page
- `cloud-api/` — address reservation API (see `cloud-api/README.md`)

## Requirements

- PHP 7.2+ with the cURL extension
- `jq`
- Ansible with the `community.crypto`, `community.general` and
  `netbox.netbox` collections (Python deps: `pip install -r requirements.txt`)
- A NetBox instance with the netbox-dns plugin, and the `ssl_certificate`
  and `ssl_privkey` custom fields on DNS records

## Setup

1. Copy `config.php.example` to `config.php` and fill in the values — or
   set the environment variables and leave the defaults:

   | Env var      | Purpose                                                      |
   | ---          | ---                                                          |
   | `NETBOX_URL` | Base URL of your NetBox instance                             |
   | `VAULT_PASS` | Ansible Vault password for the stored private keys           |
   | `VENV_PATH`  | Optional path to a virtualenv containing Ansible (its `bin/` is put first on `PATH` for the wrappers) |

   `ANSIBLE_PATH` defaults to `src/ansible` and is interpreted **relative
   to the app root** (the directory holding `index.php`) unless it starts
   with `/`. Point it elsewhere only if you keep the playbooks outside the
   app tree.

2. Make the wrapper scripts executable — this is required, not cosmetic:

   ```bash
   chmod 755 src/ansible/*.sh
   ```

   `run_script()` refuses to execute anything that is not executable, so a
   missed `chmod` turns every cert operation into an HTTP 500.

### Certificate subject

The CSR subject details live in one place only: `config.php`. It defines
`SSL_CSR_COUNTRY`, `SSL_CSR_STATE`, `SSL_CSR_LOCALITY`, `SSL_CSR_ORG` and
`SSL_CSR_OU` (plus optional `SSL_KEY_SIZE`, default 4096, and `SSL_VAULT_ID`,
default `netops`), each read from the same-named environment variable.
`run_script()` exports them to the wrappers and the playbooks read them back
with `lookup('env', ...)`. Defaults: `US`, `California`, `Example City`,
`Example Org`, `IT`. The playbooks hardcode no subject values.

## Running

Under Apache, mount the app at `/nb/` with the provided `.htaccess` (it
already handles the rewrite to `index.php`, CORS preflight, security
headers and passing the `Authorization` header through to PHP).

For local development the PHP built-in server works too:

```bash
php -S 0.0.0.0:8080 index.php
```

## API

The API is documented in `openapi.yaml` and browsable at `/swagger`.

Every endpoint takes a `POST` with a JSON body and requires the caller's
NetBox API token as a Bearer token:

```
Authorization: Bearer <netbox-api-token>
```

| Endpoint          | Body                                                             |
| ---               | ---                                                              |
| `POST /api/build`   | `common_name` (req), `owner_group` (req), `reference_number`, `subject_alt_names` |
| `POST /api/show`    | `common_name` (req)                                              |
| `POST /api/import`  | `common_name` (req), `certificate` (req, PEM text)               |
| `POST /api/selfsign`| `common_name` (req), `owner_group` (req), `subject_alt_names`    |

Concurrent builds for the same FQDN are rejected with `409`; missing fields
with `400`; an unknown FQDN on `show` with `404`.

All endpoints negotiate content via the `Accept` header:
`application/json` (the default) returns structured JSON, `text/plain`
returns the raw script output — handy in the web console and for piping
into `jq`.

### Examples

```bash
export NB=https://netbox.example.com
export TOK=<netbox-api-token>

# Build a new certificate (returns the CSR for your CA)
curl -s -X POST "$NB/nb/api/build" \
  -H "Authorization: Bearer $TOK" \
  -H "Content-Type: application/json" \
  -d '{
    "common_name": "app.example.com",
    "owner_group": "network-engineering",
    "subject_alt_names": ["alias1.example.com", "alias2.example.com"]
  }'

# Retrieve a stored certificate, key and passphrase
curl -s -X POST "$NB/nb/api/show" \
  -H "Authorization: Bearer $TOK" \
  -H "Content-Type: application/json" \
  -d '{"common_name": "app.example.com"}'

# Import the signed certificate once the CA has issued it
curl -s -X POST "$NB/nb/api/import" \
  -H "Authorization: Bearer $TOK" \
  -H "Content-Type: application/json" \
  -d "$(jq -n \
    --arg common_name 'app.example.com' \
    --arg cert "$(cat certificate.pem)" \
    '{common_name: $common_name, certificate: $cert}')"

# Mint a self-signed certificate for testing
curl -s -X POST "$NB/nb/api/selfsign" \
  -H "Authorization: Bearer $TOK" \
  -H "Content-Type: application/json" \
  -d '{"common_name": "app.example.com", "owner_group": "network-engineering"}'
```