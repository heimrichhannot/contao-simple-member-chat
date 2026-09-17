<?php

declare(strict_types=1);

use Contao\DC_Table;

$GLOBALS['TL_DCA']['tl_chat_participant'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'ptable' => 'tl_chat_conversation',
        'notCreatable' => true,
        'notEditable' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'pid,member' => 'unique',
                'member' => 'index',
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
        'tstamp' => [
            'sql' => [
                'type' => 'integer',
                'unsigned' => true,
                'default' => 0,
            ],
        ],
        'pid' => [
            'sql' => [
                'type' => 'integer',
                'unsigned' => true,
                'default' => 0,
            ],
        ],
        'member' => [
            'sql' => [
                'type' => 'integer',
                'unsigned' => true,
                'default' => 0,
            ],
        ],
        'joinedAt' => [
            'sql' => [
                'type' => 'integer',
                'unsigned' => true,
                'default' => 0,
            ],
        ],
        'lastReadAt' => [
            'sql' => [
                'type' => 'integer',
                'unsigned' => true,
                'default' => 0,
            ],
        ],
        'lastReadMessageId' => [
            'sql' => [
                'type' => 'integer',
                'unsigned' => true,
                'default' => 0,
            ],
        ],
        'lastPageId' => [
            'sql' => [
                'type' => 'integer',
                'unsigned' => true,
                'default' => 0,
            ],
        ],
        'muted' => [
            'sql' => [
                'type' => 'string',
                'length' => 1,
                'fixed' => true,
                'default' => '',
            ],
        ],
    ],
];
