<?php
return [
    'daemonNames' => [
        'mhddos', 'distress', 'x100'
    ],
    'adjustableParams' => [
        'mhddos' => [
            'user-id',
            'lang',
            'copies',
            'use-my-ip',
            'threads',
            'proxies',
            'ifaces'
        ],
        'distress' => [
            'user-id',
            'use-my-ip',
            'use-tor',
            'concurrency',
            'enable-icmp-flood',
            'enable-packet-flood',
            'disable-udp-flood',
            'udp-packet-size',
            'direct-udp-mixed-flood-packets-per-conn',
            'proxies-path',
            'interface'
        ],
        'x100' => [
            'itArmyUserId'
        ]
    ]
];
