<?php

return [
    'doctrine' => [
        'connection' => [
            'driver' => 'pdo_mysql',
            'url' => '%env(resolve:DATABASE_URL)%',
            'charset' => 'utf8mb4',
        ],
        'orm' => [
            'auto_generate_proxy_classes' => true,
            'naming_strategy' => 'doctrine.orm.naming_strategy.underscore_number_aware',
            'auto_mapping' => true,
            'mappings' => [
                'App' => [
                    'is_bundle' => false,
                    'dir' => '%kernel.project_dir%/src/LanguageModel/Infrastructure/Persistence/Doctrine/Mapping',
                    'prefix' => 'App\LanguageModel\Infrastructure\Persistence\Doctrine\Entity',
                    'type' => 'xml',
                    'alias' => 'App',
                ],
            ],
        ],
    ],
];
