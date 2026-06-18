<?php

/*
 * Package-bundled Cloudflare IP ranges.
 *
 * This is the authoritative cold-start fallback, used only on a brand-new
 * install before the first `cloudflare:refresh` has run (and only when the
 * published config `fallback.ipv4/ipv6` is empty).
 *
 * DO NOT edit by hand. Regenerate from the live Cloudflare endpoints with:
 *
 *     php artisan cloudflare:bundle-fallback
 *
 * (a maintainer/release tool, run from the package repo before tagging).
 *
 * Last generated against https://www.cloudflare.com/ips-v4 and
 * https://www.cloudflare.com/ips-v6.
 */

return [
    'ipv4' => [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
    ],
    'ipv6' => [
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ],
];
