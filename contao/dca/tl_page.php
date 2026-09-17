<?php

declare(strict_types=1);

use Contao\CoreBundle\DataContainer\PaletteManipulator;

$GLOBALS['TL_DCA']['tl_page']['fields']['memberChatPage'] = [
    'inputType' => 'pageTree',
    'eval' => [
        'fieldType' => 'radio',
        'tl_class' => 'clr',
    ],
    'sql' => [
        'type' => 'integer',
        'unsigned' => true,
        'default' => 0,
    ],
    'relation' => [
        'type' => 'hasOne',
        'load' => 'lazy',
    ],
];

PaletteManipulator::create()
    ->addField('memberChatPage', 'global_legend', PaletteManipulator::POSITION_APPEND)
    ->applyToPalette('root', 'tl_page')
    ->applyToPalette('rootfallback', 'tl_page');
