<?php

define('AUTH_KEY', '2FvD_QpjYrGvx8GNb9pze952FkK2PScA_tAPHF4c');
define('DNS', [
        'f11d69a9a73006bfaf5e11590ac57c91' => [ // gymsgrind.com
        [
            'record' => 'my.gymsgrind.com',
            'proxy' => 'true'
        ],
    ], 
    '4464bc304a9d51fc0b0e05b9cbf8934f' => [ // mate-team.com
        [
            'record' => 'admin.mate-team.com',
            'proxy' => 'true'
        ],
        [
            'record' => 'gymsgrind.mate-team.com',
            'proxy' => 'true'
        ]
    ],
    '77f7e2351044719ec1132e5458782258' => [ // dressitrain.com
        [
            'record' => 'revshell.dressitrain.com',
            'proxy' => 'false'
        ]
    ], 
]);
while (true) {
    foreach (DNS as $key => $value) {
        foreach ( $value as $record) {
            $cmd = "./ddns.sh ". AUTH_KEY ." {$key} {$record['record']} {$record['proxy']}";
            exec($cmd);
        }
    }
    sleep(1600);
}