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

// Exercise registered callbacks and the exact value formatter used by DC_Table's
// parentView(), with deterministic read-only rows. No backend login is required.
$container = $kernel->getContainer();
$requests = $container->get('request_stack');
$request = Symfony\Component\HttpFoundation\Request::create('/contao?do=member_chat');
$requests->push($request);
$translator = $container->get('translator');
$previousLocale = $translator->getLocale();
$timestamp = 1789653654;
try {
    foreach (['en' => 'last message', 'de' => 'letzte Nachricht'] as $locale => $wording) {
        $requests->pop();
        $requests->push(clone $request);
        $translator->setLocale($locale);
        foreach (['tl_chat_conversation', 'tl_chat_message', 'default'] as $file) {
            Contao\System::loadLanguageFile($file, $locale, true);
        }
        $deleted = $translator->trans('MSC.member_chat.deleted_member', [], 'contao_default');
        foreach ([
            'tl_chat_conversation' => ['memberLow' => 0, 'memberHigh' => 0, 'lastMessageAt' => $timestamp],
            'tl_chat_message' => ['author' => 0, 'createdAt' => $timestamp, 'body' => '<script> & verification'],
        ] as $table => $row) {
            $callback = $GLOBALS['TL_DCA'][$table]['list']['label']['label_callback'];
            $label = is_array($callback)
                ? Contao\System::importStatic($callback[0])->{$callback[1]}($row)
                : $callback($row);
            $expected = $table === 'tl_chat_conversation'
                ? $deleted . ' ↔ ' . $deleted . ', ' . $wording . ': ' . date('Y-m-d H:i', $timestamp)
                : $deleted . ' · ' . date('Y-m-d H:i', $timestamp) . ' · <script> & verification';
            if ($label !== htmlspecialchars($expected, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) {
                throw new RuntimeException('Unexpected rendered label: ' . $table . ' (' . $locale . ')');
            }
            echo $locale . ' ' . $table . ': ' . $label, PHP_EOL;
        }
        $parentTable = $GLOBALS['TL_DCA']['tl_chat_message']['config']['ptable'];
        $dc = (new ReflectionClass(Contao\DC_Table::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Contao\DataContainer::class, 'strTable'))->setValue($dc, $parentTable);
        $dc->id = 0;
        $headers = [];
        foreach ($GLOBALS['TL_DCA']['tl_chat_message']['list']['sorting']['headerFields'] as $field) {
            $dc->field = $field;
            $formatter = $container->get('contao.data_container.value_formatter');
            $value = $formatter->format($parentTable, $field, $timestamp, $dc);
            $expected = Contao\Date::parse(Contao\Config::get('datimFormat'), $timestamp);
            if ($value !== $expected || $value === (string) $timestamp) {
                throw new RuntimeException('Header timestamp is not formatted: ' . $field);
            }
            if ($formatter->format($parentTable, $field, 0, $dc) !== '') {
                throw new RuntimeException('Empty conversation header must omit the zero timestamp.');
            }
            $headers[$GLOBALS['TL_LANG'][$parentTable][$field][0]] = $value;
        }
        if ($headers === []) {
            throw new RuntimeException('No child-list header fields were rendered.');
        }
        echo $locale . ' header: ' . json_encode($headers, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
    }
} finally {
    $translator->setLocale($previousLocale);
    $requests->pop();
}
echo "Conversation/message labels and parent header values rendered in English and German; zero timestamp omitted", PHP_EOL;
