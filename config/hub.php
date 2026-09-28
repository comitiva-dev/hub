<?php

return [

    /*
     * `community` here. The hosted hub's enterprise package reports `cloud`
     * (ADR 0015 in comitiva).
     */
    'edition' => env('HUB_EDITION', 'community'),

    /*
     * `open`: anyone can create an account. `invite-only`: only with an
     * invitation, except the very first account on an empty hub.
     */
    'registration' => env('HUB_REGISTRATION', 'open'),

    // Seconds a run stays alive without events or heartbeats (ADR 0017).
    'run_lease_seconds' => (int) env('HUB_RUN_LEASE_SECONDS', 30),

    'invitation_ttl_days' => (int) env('HUB_INVITATION_TTL_DAYS', 7),

    /*
     * Where clients open the WebSocket. Reverb listens inside the network on
     * REVERB_HOST:REVERB_PORT; these are the host and port desktops reach.
     */
    'realtime' => [
        'host' => env('HUB_REALTIME_HOST', env('REVERB_HOST', 'localhost')),
        'port' => (int) env('HUB_REALTIME_PORT', env('REVERB_PORT', 8080)),
        'scheme' => env('HUB_REALTIME_SCHEME', env('REVERB_SCHEME', 'http')),
    ],

    'attachments_disk' => env('HUB_ATTACHMENTS_DISK', 'local'),

];
