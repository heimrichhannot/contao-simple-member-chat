<?php

declare(strict_types=1);

use Contao\DataContainer;
use Contao\DC_Table;

$GLOBALS['TL_DCA']['tl_chat_message'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'ptable' => 'tl_chat_conversation',
        'notCreatable' => true,
        'notEditable' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'pid,id' => 'index',
                'author' => 'index',
            ],
        ],
    ],
    'list' => [
        'sorting' => [
            'mode' => DataContainer::MODE_PARENT,
            'fields' => ['id DESC'],
            'headerFields' => ['lastMessageAt'],
            'panelLayout' => 'limit',
        ],
        'label' => [
            'fields' => ['author', 'createdAt', 'body'],
        ],
        'operations' => [
            'delete' => [
                'href' => 'act=delete',
                'icon' => 'delete.svg',
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
        'author' => [
            'sql' => [
                'type' => 'integer',
                'unsigned' => true,
                'default' => 0,
            ],
        ],
        'body' => [
            'sql' => [
                'type' => 'text',
            ],
        ],
        'createdAt' => [
            'sql' => [
                'type' => 'integer',
                'unsigned' => true,
                'default' => 0,
            ],
        ],
    ],
];
