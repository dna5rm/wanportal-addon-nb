# reports: NetBox sites table

sites.php lists every active NetBox site as a searchable table: ID, site
name, description and the physical and shipping addresses. The info icon
on each row opens a modal with the rest of the record: ASN, facility,
contact, time zone, and a Leaflet map when the site has coordinates. The
page refreshes itself every five minutes.

This one is read-only. vip-api keeps F5 VIP records and cloud-api carves
prefix reservations; this report only fetches `/api/dcim/sites/` and
formats what comes back.

Configuration comes from the sidecar config.php when present, otherwise
from the NETBOX_URL and NETBOX_TOKEN environment variables. Without a
token the page renders an error instead of the table.

Query options: `?embed=1` drops the topnav for iframe use, and
`?format=json` returns the site rows as JSON. The NetBox button jumps to
the same site list in the NetBox UI.

nat.php lists IP addresses whose `nat` custom field is true. Columns are
Address (bold link to the NetBox IP page; the raw dump does not include the link), Tags, CIDR (network of the address field), Hostname, and Description.

zones.php lists prefixes whose mask is shorter than `$zonesMaskLengthLt`
(default 24) and drops Container status (`status__n=container`). The CIDR
cell links to that prefix in the NetBox GUI (`display_url`); the raw JSON
dump does not include the link. Columns are Scope (scope name and tag names), CIDR, Role
(`role.name`, or `vlan.name` when role is empty; an em dash when both
are empty), Status (centered chicklet: Container gray, Active blue, Reserved
cyan, Deprecated red — the NetBox GUI colors), Environment (`prod`
Production, `dev` Development, `test` Test (UAT); an em dash for any
other value), and Zone (the `firewall_zone` custom field, drawn as a
color chicklet).
