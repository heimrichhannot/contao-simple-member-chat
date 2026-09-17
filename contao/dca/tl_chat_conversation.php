<?php

declare(strict_types=1);

use Contao\DC_Table;

$GLOBALS['TL_DCA']['tl_chat_conversation'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'ctable' => ['tl_chat_participant', 'tl_chat_message'],
        'notCreatable' => true,
        'notEditable' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'uuid' => 'unique',
                'memberLow,memberHigh' => 'unique',
                'lastMessageAt' => 'index',
            ],
        ],
    ],
    'fields' => [
        'id' => [
            'sql' => [
                'type' => 'integer',
                'unsigned' => true,
                'autoincrement' => true,
            ],
        ],
        'uuid' => [
            'sql' => [
                'type' => 'binary',
                'length' => 16,
                'fixed' => true,
            ],
        ],
        'tstamp' => [
            'sql' => [
                'type' => 'integer',
                'unsigned' => true,
                'default' => 0,
            ],
        ],
        'memberLow' => [
            'sql' => [
                'type' => 'integer',
                'unsigned' => true,
                'default' => 0,
            ],
        ],
        'memberHigh' => [
            'sql' => [
                'type' => 'integer',
                'unsigned' => true,
                'default' => 0,
            ],
        ],
        'createdAt' => [
            'sql' => [
                'type' => 'integer',
                'unsigned' => true,
                'default' => 0,
            ],
        ],
        'lastMessageAt' => [
            'sql' => [
                'type' => 'integer',
                'unsigned' => true,
                'default' => 0,
            ],
        ],
        'lastMessageId' => [
            'sql' => [
                'type' => 'integer',
                'unsigned' => true,
                'default' => 0,
            ],
        ],
    ],
];
