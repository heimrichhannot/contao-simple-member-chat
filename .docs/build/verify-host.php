<?php

declare(strict_types=1);

// Run using the host project's PHP runtime; no host source changes are needed.
$projectDir = $argv[1] ?? '/var/www/html';
require $projectDir . '/vendor/autoload.php';

$kernel = Contao\ManagerBundle\HttpKernel\ContaoKernel::fromInput(
    $projectDir,
    new Symfony\Component\Console\Input\ArgvInput(['console', '--env=prod']),
);
$kernel->boot();
$kernel->getContainer()->get('contao.framework')->initialize();
Contao\Controller::loadDataContainer('tl_page');
Contao\System::loadLanguageFile('tl_page', 'de', true);

$result = [
    'root' => str_contains($GLOBALS['TL_DCA']['tl_page']['palettes']['root'], 'memberChatPage'),
    'rootfallback' => str_contains($GLOBALS['TL_DCA']['tl_page']['palettes']['rootfallback'], 'memberChatPage'),
    'label' => $GLOBALS['TL_LANG']['tl_page']['memberChatPage'] ?? null,
];
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
if (!$result['root'] || !$result['rootfallback'] || !is_array($result['label'])) {
    exit(1);
}
