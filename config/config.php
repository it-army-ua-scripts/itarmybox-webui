<?php
return [
    'daemonNames' => [
        'mhddos', 'distress', 'x100'
    ],
    'adjustableParams' => [
        'mhddos' => [
            'lang',
            'copies',
            'use-my-ip',
            'threads',
            'proxies'
        ],
        'distress' => [
            'use-my-ip',
            'use-tor',
            'concurrency',
            'enable-icmp-flood',
            'enable-packet-flood',
            'disable-udp-flood',
            'udp-packet-size',
            'direct-udp-mixed-flood-packets-per-conn',
            'proxies-path'
        ],
        'x100' => [
            'initialDistressScale',
            'ignoreBundledFreeVpn'
        ]
    ]
];
