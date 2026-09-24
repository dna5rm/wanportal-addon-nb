# VIP builder (wanportal-addon-nb)

This file is the contract for the VIP builder page and its API. The playbook
that reads the stored build lives outside this addon.

## Goal

A certmgr-style page where an operator builds a VIP, sees it as a diagram, and
submits it. Submit stores the build on the existing NetBox IP address as the
`vip_build` JSON object. Load reads it back into the form.

## Where it lives

- Page: `/nb/vip.php` in wanportal-addon-nb. Not a new sidecar. Not a new host port.
- Swagger UI: `/nb/vip-swagger`. Spec: `/nb/vip-openapi.yaml`. Try it out uses
  server `/nb`, so requests stay on this origin.
- SPA: `#/addons/vips` via AddonFrame, `?embed=1`. Menu child next to Certs.
  Same-tab. No `target=_blank`.
- Standalone `/nb/vip.php` keeps the sidecar topnav. Embed renders no second header.
- Auth matches certmgr: the NetBox token field, localStorage key
  `wanportal-nb-token`. The token is not stored server-side.
- Public tree. No company names, no lab hostnames, no real VIP addresses in
  source, placeholders, or examples. example.com only.

## What it does not do

- Create or reserve the VIP address. The IP object must already exist.
- Create the DNS record. The FQDN must already exist in netbox-dns.
- Edit or create iRules. The only accepted `irules` value is the port-80 SSL
  redirect list below.
- Attach an analytics profile.
- Create monitors. The only choices are the configured tcp and ping monitors
  (builtin `/Common/tcp` and `/Common/gateway_icmp`; UI labels: tcp, ping).
- Call Tower, ansible, or a webhook.

## NetBox objects

One VIP is one `ipam.ip-address`. The address the user types must already
exist. Submit PATCHes that object. It does not POST a new one.

Custom fields, create only if missing (GET first). All three use the group
name VIP in NetBox:

| Field | Object type | Type | Purpose |
| --- | --- | --- | --- |
| `vip_build` | `ipam.ipaddress` | JSON object | The build, stored as `{"build":...}`. This is what the playbook reads. |
| `vip_ssl` | `ipam.ipaddress` | object (`netbox_dns.record`) | Link to the DNS record behind the SSL Common Name. Null when the build names no FQDN. |
| `vip_address` | `netbox_dns.record` | object (`ipam.ipaddress`) | Link back to the VIP IP. |

The links are object fields: each stores the linked object's id and reads
back as a bare id or a nested object. There is no `vip_fqdn` custom field;
API responses derive the hostname string from the `vip_ssl` link when they
need one. If a field already exists with the right name and type, use it. If
it exists with the wrong type or on the wrong object type, resolve that
first. Do not silently rebind it.

FQDN check: GET netbox-dns records for that exact name. One match is good.
Zero matches is not good. More than one match is not good; show the count and
do not pick. The checkbox is a status indicator, not a control the user
toggles. It is checked only after a successful unique lookup. Debounce the
lookup. Do not block typing.

IP check: GET `ipam.ip-addresses` for that exact address. Unique existing
object is required before Submit enables. The VIP address must be role VIP and
status Active; a hit with the wrong role or status stays unchecked, and save
and validate reject it. Member addresses only have to exist and be unique in
IPAM. The page offers no "create this IP".

## Stored build

`vip_build` is a JSON object custom field holding `{"build":{...}}`. API
responses carry it as one compact line, no pretty-print, no trailing
commentary. That line is what the page shows and what `action=build` returns.

Shape (store the `build` wrapper; a bare build object is accepted too):

```json
{"build":{"ssl_key_cert":[{"name":"app.example.com"}],"virtual":[{"name":"app.example.com","address":"192.0.2.10","port":443,"ip_protocol":"tcp","source":"0.0.0.0/0","snat":"automap","profiles":["/Common/http","/Common/tcp",{"name":"/Common/app.example.com_clientssl","context":"client-side"}],"default_persistence_profile":"/Common/cookie","pool":{"name":"app.example.com_8080_pool","lb_method":"least-connections-node","monitors":["/Common/tcp"]},"nodes":[{"address":"192.0.2.20","port":8080}]}]}}
```

Rules for the build:

- Top key is `build`.
- `limit` is required. `build.limit` is a JSON array of hostname strings, one
  per line from the form. No default hosts in the page. Submit stays disabled
  while the list is empty.
- Do not emit `irule`. The only allowed `irules` value is on a port-80
  listener with its SSL Redirect box checked:
  `["<configured redirect iRule>"]`, builtin `/Common/_sys_https_redirect`.
  That listener must also include the configured HTTP profile and does not
  get a clientssl profile. Any other `irule` or `irules` value is rejected.
- `ssl_key_cert` is present only when at least one listener has client SSL
  on. Each entry is `{"name":"<fqdn>"}`. The profile reference is
  `/Common/<fqdn>_clientssl` with context `client-side`. Cert upload stays on
  the certmgr page.
- Every listener `name` is the SSL Common Name when one is set, otherwise the
  VIP address. Every listener `address` is the VIP IP.
- A listener that has a pool owns it as an object: `name`, `lb_method`
  `least-connections-node`, `monitors` a one-element list of the configured
  tcp or ping monitor.
- Pool name is `<name>_<nodeport>_pool` when the listener has nodes.
  `<name>` is the FQDN, or the VIP address when no SSL Common Name is set.
  The node port is the first member's port. Mixed member ports on one
  listener are rejected; split them onto separate listeners.
- A second listener that uses the same pool stores `pool` as that string, and
  omits `nodes`.
- A listener with no pool omits `pool` and sets `nodes` to `[]`.
- Members are supported. Empty `nodes` is valid.
- Persistence is optional. If set, `default_persistence_profile` is the
  configured cookie or source-address profile. Shipped defaults are the
  builtin objects `/Common/cookie` and `/Common/source_addr`. A site
  overrides those in `config.php` (or `VIP_PERSIST_COOKIE` /
  `VIP_PERSIST_SOURCE`). No other values. No new persistence profile. The
  cookie profile additionally requires the HTTP profile on the same listener;
  the source-address profile does not.
- Profiles always include the configured tcp profile. HTTP listeners also
  include the configured HTTP profile. Do not attach an analytics profile.
- Profile names come from `config.php` / the `VIP_*` environment variables.
  Shipped defaults are the builtin objects `/Common/http`, `/Common/tcp`,
  `/Common/gateway_icmp`, `/Common/cookie`, `/Common/source_addr`,
  `/Common/_sys_https_redirect`.
- `ip_protocol` is `tcp`. `snat` is `automap`. `source` is `0.0.0.0/0`.

Submit writes in one action: PATCH `vip_build` and `vip_ssl` on the IP, then
PATCH `vip_address` on the DNS record. With no SSL Common Name the DNS side
is skipped, `vip_ssl` is stored null, and the DNS record is not touched. If a
write fails, the response names which object failed. Do not claim the link
exists.

Load: user types the FQDN (or the IP). Resolve to the IP object. If
`vip_build` is empty, the form stays blank aside from the FQDN and address.
If it parses, fill the form and redraw. If it does not parse, show the raw
string and do not destroy it on the next submit unless the user confirms
overwrite.

## Page

Two panes, top-aligned, one scroll surface inside the embed (the form pane).
The diagram does not get its own page scrollbar.

Left, top to bottom:

1. SSL Common Name. Optional unless a listener has Client SSL checked
   (port-80 SSL Redirect does not count). When filled, background lookup must
   be a unique DNS hit. When empty, the virtual name and pool prefix are the
   VIP address, and save does not touch a DNS record.
2. VIP address. Background lookup. Same three states, and the checkbox checks
   only when the IP exists, its role is VIP (`role.value` `vip`), and its
   status is Active (`status.value` `active`). A hit with the wrong role or
   status stays unchecked and names the actual values. Save and validate
   reject that address. Member IPs are not required to be role VIP.
3. Limit. Required. One hostname per line when VIP_LIMIT_HOSTS is empty. When
   that config value is set, the page shows a multi-select instead of a text
   box, and several choices may be selected at once (build.limit keeps the
   union of the selected options' hosts).
   Each config line is one choice: a single host, or a comma-separated pair.
   Choosing a pair stores both hostnames in build.limit.
4. Listeners. Add/remove rows. Each row: port, HTTP on/off, client SSL on/off
   (label becomes "SSL Redirect" when the port is 80; checked means the
   configured redirect iRule, builtin `/Common/_sys_https_redirect`, not a
   clientssl profile), monitor tcp or ping, persistence none/cookie/source.
   Load must restore the HTTP checkbox and the client-SSL / SSL-Redirect
   checkbox from the stored profiles and irules.
5. Members under that listener. Add/remove rows: address and port. Empty is
   allowed. A filled member address gets the same disabled grey checkbox as
   the VIP address. It checks only when that IP exists in NetBox. Save and
   validate reject a member IP that is missing or ambiguous. The page uses
   GET action=address for the checkbox. The API check is the enforcement, not
   the checkbox.
6. Submit. Disabled until both lookups are unique hits, limit has at least
   one hostname, and the form validates (port integer, member IP, no mixed
   member ports, no analytics, no iRule).
7. A line showing the compact string that will be stored. It is also an
   import: paste a build string and press Import. A string that parses fills
   the form (FQDN, address, limit, listeners). A string that does not parse
   stays in the field and is not applied. The live redraw must not clobber
   the field while it has focus.

Right: the diagram, redrawn on every valid edit. The VIP node text is
`VIP <address> (<fqdn>)`.

```
client
  -> VIP <address>
       -> :<port>  [http] [ssl]
            -> pool <name>  monitor tcp|ping
                 -> <member>:<port>
                 -> (no members)
```

Plain HTML/CSS boxes. No mermaid, no extra library. Token colors only
(`var(--panel)`, `var(--bg)`, `var(--up)`). Dark and light. Header is a
`.bar` with h1 `vip`.

## API

Every page action is an API call. The browser does not talk to NetBox. Other
teams call the same routes with the same Bearer token (the caller's NetBox
token). No session cookie. No GUI-only path.

One file, `app/api/vip.php`. Dispatch on method plus `action`.

- `GET ?action=fqdn&fqdn=<name>` — DNS lookup only. `200` unique, `404`
  missing, `409` ambiguous (`count`). The `200` body adds `address` (bare)
  and `address_id` from the record's `vip_address` link — `''`/`null` when
  the link is unset or unresolvable, and still `200` when the record exists
  with no link. The page loads the VIP through the address path when that
  address is present and the VIP address field is empty or already that
  address; a focused field holding a different address is never clobbered.
- `GET ?action=address&address=<ip>` — IP lookup only. Same three statuses.
  Include `vip_build` (compact line or empty), `vip_fqdn` (hostname derived
  from the `vip_ssl` link; empty when unset), and the IP's role and status
  when the IP exists.
- `GET ?action=load&fqdn=<name>` — resolve the unique DNS name, then the
  linked IP. The record's `vip_address` link wins: that IPAM object is
  loaded by id and a failed load is an error (`404` when the linked IP is
  gone, `500` otherwise), never a silent fallback to the DNS value. The
  A/AAAA value is the fallback only when the link is unset, so records
  saved before the link existed still load. `200` with `fqdn`, `address`,
  `id`, `dns_id`, and `vip_build`. `404` if either side is missing. `409`
  if either side is ambiguous.
- `GET ?action=list` — IP addresses whose `vip_build` custom field is
  non-empty. `200` `{"vips":[{"id","address","fqdn","vip_build"}]}`. No
  secrets. This is how an automation finds what already exists. The page does
  not have to render the list.
- `GET ?action=build&fqdn=<name>` or `&address=<ip>` — for the playbook.
  `200` body is the stored object itself (`{"build":{...}}`),
  `Content-Type: application/json`, compact, no wrapper. `404` if the IP
  exists but `vip_build` is empty. `409` if the lookup is ambiguous. This is
  the document the playbook fetches. `load` stays the metadata call. `build`
  is the document.
- `POST ?action=validate` — body is `{"build":{...}}`. Run every save check:
  limit non-empty; one listener name and one VIP address; the VIP exists, is
  unique, role VIP, status Active; members exist and are unique; no `irule`,
  `irules` only as the port-80 redirect list; no analytics profile;
  configured monitors and persistence profiles only, and cookie persistence
  only with the HTTP profile; no mixed member ports; a clientssl profile
  requires an SSL Common Name that exists in netbox-dns. Write nothing. `200`
  `{"ok":true,"vip_build":"<the one-line string that would be stored>"}` or
  `400` with `error`.
- `POST ?action=save` — same body and the same checks as validate, then PATCH
  `vip_build` and `vip_ssl` on the IP and `vip_address` on the DNS record.
  `200` `{"ok":true,"id","dns_id","vip_build":"<stored one-line string>"}`;
  `dns_id` is null when the build has no SSL Common Name. A failed link write
  answers `500` with `failed` set to `ip_address` or `dns_record`. Do not
  create the IP or the DNS record.

A missing `action` is `400`. The HTML form uses `fqdn`, `address`, `load`,
`validate`, and `save`. It does not duplicate those checks in JavaScript
except to disable the button.

## Files

- Page: `app/vip.php`.
- API: `app/api/vip.php` — every action above lives in this one file. The
  browser does not talk to NetBox directly, and there is no GUI-only path.
- Spec and Swagger UI: `app/vip-openapi.yaml`, `app/vip-swagger.html`.
- Profile names: `vip_profile_map()` in `app/src/vip_profiles.php`, fed from
  `config.php` / the `VIP_*` environment variables.
- The playbook that consumes `action=build` lives outside this addon.