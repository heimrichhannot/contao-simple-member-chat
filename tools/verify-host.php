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

foreach (['tl_chat_conversation', 'tl_chat_message'] as $table) {
    Contao\Controller::loadDataContainer($table);
    Contao\System::loadLanguageFile($table, 'en', true);
    $dca = $GLOBALS['TL_DCA'][$table];
    if (!$dca['config']['notCreatable'] || !$dca['config']['notEditable'] || !isset($dca['list']['label']['label_callback'])) {
        throw new RuntimeException('Missing moderation configuration: ' . $table);
    }
}
if ($GLOBALS['BE_MOD']['accounts']['member_chat']['tables'] !== ['tl_chat_conversation', 'tl_chat_message']) {
    throw new RuntimeException('Unexpected backend module tables.');
}
if (!isset($GLOBALS['TL_DCA']['tl_chat_message']['config']['ondelete_callback'])) {
    throw new RuntimeException('Deletion repair callback is not registered.');
}
$twig = $kernel->getContainer()->get('twig');
if ($twig->getFunction('member_chat_unread_badge') === false || $twig->getFunction('member_chat_unread_badge') === null) {
    throw new RuntimeException('Badge function is not registered.');
}
if ($twig->createTemplate('{{ member_chat_unread_badge() }}')->render() !== '') {
    throw new RuntimeException('Anonymous badge must be empty.');
}
echo "Backend module, read-only lists, label/deletion callbacks and anonymous Twig badge verified", PHP_EOL;
