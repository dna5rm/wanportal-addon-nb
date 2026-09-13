<?php
/**
 * src/netbox.php
 * Loader for NetBox API functions. Requires client.php (HTTP wrapper),
 * prefixes.php (pool discovery and free blocks), reservation.php
 * (reservation create/get/delete) and helpers.php (naming and block
 * utilities) so callers include only this file.
 */

require __DIR__ . '/netbox/client.php';
require __DIR__ . '/netbox/prefixes.php';
require __DIR__ . '/netbox/reservation.php';
require __DIR__ . '/netbox/helpers.php';
