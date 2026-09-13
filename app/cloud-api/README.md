# cloud-api: IP prefix reservations

REST API in front of NetBox IPAM that reserves /21 through /24 blocks for
cloud accounts. The caller names an account, region, environment and size;
the API finds a free sub-prefix under the right parent pool, creates the
child prefix in NetBox, and returns its address. Creating the same
reservation twice is safe: an existing container prefix is returned as
found, not duplicated.

There is no UI here, just four endpoints, the OpenAPI spec, and Swagger UI
for reference. It serves account automation.

## Endpoints

| Method | Path | Purpose |
| --- | --- | --- |
| POST /reservation | Create a reservation, or return the existing one |
| GET /reservation | Reservation details for an account and region |
| DELETE /reservation | Delete the reservation prefix |
| GET /free-blocks | Free blocks under the parent pool |

Auth is a static bearer token set in config.php, checked by require_auth
before any endpoint runs (only swagger and openapi.yaml are public). The
API is meant for a single operator and the shared bearer token is the
intended auth for it; there are no per-user identities. GET /reservation
takes account and region as query parameters or as a JSON body.

Create example:

```bash
curl -s -X POST "https://netops.example.com/nb/cloud-api/reservation" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <token>" \
  -d '{
    "account":     "example01",
    "account_id":  123456789012,
    "environment": "aws-development",
    "region":      "eu-west-1",
    "size":        "24"
  }'
```

## How a reservation is made

1. Region, environment and size are checked against allowlists: regions
   eu-west-1, eu-west-2, us-east-1 and us-west-2; environments
   aws-production and aws-development; sizes 21, 22, 23 and 24.
2. The container name is `<account>-<region>`. When a NetBox prefix
   already carries that description, it comes back unchanged (HTTP 200
   instead of 201).
3. Otherwise the parent pool is found by tags: a prefix tagged
   `automation` plus the environment and the region.
4. NetBox suggests a free sub-prefix of the requested size under that
   parent. The new child inherits VRF, tenant, role and scope from the
   parent and is tagged with the environment, region and account; the
   account tag is created when missing.
5. Description and comments record which account the block is for.

GET /free-blocks lists the same parent pool's free space in the legacy
blockAddr / blockSize / blockStatus / blockName shape.

Every response uses one envelope: `{ "success": bool, "message": string,
"data": object }`. Status codes: 200 success, 201 created, 400 bad
parameters, 401 missing or wrong token, 404 unknown reservation, parent
pool or no space left, 500 when NetBox calls fail.

## Configuration

Copy config.php.example to config.php and fill in the NetBox API URL, the
NetBox token, and the bearer token callers must present. config.php is
gitignored and not part of the tree. The debug flag turns on error_log
output.

Deployed under `/nb/cloud-api/` (see the RewriteBase in .htaccess);
index.php strips that prefix before routing.

## Files

```
cloud-api/
├── index.php            # Routing and the require_auth gate
├── config.php.example   # Configuration template
├── openapi.yaml         # OpenAPI spec, served at /openapi.yaml
├── swagger.html         # Swagger UI, served at /swagger
└── src/
    ├── utils.php        # Logging, validation, response envelope
    ├── netbox.php       # Loads the four modules below
    └── netbox/
        ├── client.php      # curl wrapper for the NetBox API
        ├── prefixes.php    # Pool discovery and free blocks
        ├── reservation.php # Reservation create/get/delete
        └── helpers.php     # Naming and block utilities
```