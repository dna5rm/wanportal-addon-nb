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
Address, Tags, CIDR (network of the address field), Hostname, and Description.

zones.php lists prefixes whose mask is shorter than `$zonesMaskLengthLt`
(default 24). Columns are Scope (scope name and tag names), CIDR, Network
Type (`role.name`, or the VLAN name and id when role is empty), and Zone
(the `firewall_zone` custom field, drawn as a color chicklet).
