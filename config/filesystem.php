<?php
return [
    'default' => 'local',
    'disks'   => [
        'local' => [
            'type' => 'local',
            'root' => app()->getRuntimePath() . 'storage',
        ],
    ],
];
