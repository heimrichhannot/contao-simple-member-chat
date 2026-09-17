<?php

declare(strict_types=1);

// Explicitly authorized demo fixtures, executed only in contao0507.contao.
require '/var/www/html/vendor/autoload.php';
$kernel = Contao\ManagerBundle\HttpKernel\ContaoKernel::fromInput('/var/www/html', new Symfony\Component\Console\Input\ArgvInput(['console', '--env=prod']));
$kernel->boot();
$kernel->getContainer()->get('contao.framework')->initialize();
$db = $kernel->getContainer()->get('database_connection');
$group = $db->fetchOne('SELECT id FROM tl_member_group WHERE name = ?', ['Phase 3a chat demo']);
if (!$group) {
    $db->insert('tl_member_group', ['name' => 'Phase 3a chat demo', 'tstamp' => time()]);
    $group = $db->lastInsertId();
}
$members = [];
foreach (['alice', 'bob', 'carol'] as $name) {
    $username = 'phase3a-chat-' . $name;
    $id = $db->fetchOne('SELECT id FROM tl_member WHERE username = ?', [$username]);
    if (!$id) {
        $db->insert('tl_member', ['tstamp' => time(), 'firstname' => 'Chat ' . ucfirst($name), 'lastname' => 'Phase3a', 'username' => $username, 'password' => password_hash('Phase3a-demo-only-2026!', PASSWORD_BCRYPT), 'email' => $username . '@example.invalid', 'login' => 1, 'groups' => serialize([(int) $group]), 'dateAdded' => time()]);
        $id = $db->lastInsertId();
    }
    $members[$name] = (int) $id;
}
$modernLayout = $db->fetchOne('SELECT id FROM tl_layout WHERE name = ?', ['Phase 3a chat modern']);
if (!$modernLayout) {
    $layout = $db->fetchAssociative('SELECT * FROM tl_layout WHERE id = 26');
    unset($layout['id']);
    $layout['name'] = 'Phase 3a chat modern';
    $layout['modules'] = serialize([['mod' => '0', 'col' => 'main', 'enable' => '1']]);
    $db->insert('tl_layout', array_combine(array_map($db->quoteIdentifier(...), array_keys($layout)), array_values($layout)));
    $modernLayout = $db->lastInsertId();
}
$legacyLayout = $db->fetchOne('SELECT id FROM tl_layout WHERE name = ?', ['Phase 3a chat legacy']);
if (!$legacyLayout) {
    $layout = $db->fetchAssociative('SELECT * FROM tl_layout WHERE id = 21');
    unset($layout['id']);
    $layout['name'] = 'Phase 3a chat legacy';
    $layout['addEncore'] = '1';
    $layout['encoreEntries'] = serialize([['entry' => 'huh_ux_turbo_encore_no_drive', 'active' => '1']]);
    $db->insert('tl_layout', array_combine(array_map($db->quoteIdentifier(...), array_keys($layout)), array_values($layout)));
    $legacyLayout = $db->lastInsertId();
}
$pages = [];
foreach (['legacy' => (int) $legacyLayout, 'modern' => (int) $modernLayout] as $variant => $layout) {
    $alias = 'phase3a-chat-' . $variant;
    $id = $db->fetchOne('SELECT id FROM tl_page WHERE alias = ?', [$alias]);
    if (!$id) {
        $db->insert('tl_page', ['pid' => 1, 'sorting' => 99999, 'tstamp' => time(), 'type' => 'regular', 'title' => 'Phase 3a chat ' . $variant, 'alias' => $alias, 'published' => 1, 'includeLayout' => 1, 'layout' => $layout, 'hide' => 1]);
        $id = $db->lastInsertId();
        $db->insert('tl_article', ['pid' => $id, 'sorting' => 128, 'tstamp' => time(), 'title' => 'Phase 3a chat', 'alias' => $alias, 'inColumn' => 'main', 'published' => 1]);
        $article = $db->lastInsertId();
        foreach (['login' => 128, 'member_chat' => 256] as $type => $sorting) {
            $db->insert('tl_content', ['pid' => $article, 'ptable' => 'tl_article', 'sorting' => $sorting, 'tstamp' => time(), 'type' => $type]);
        }
    }
    $db->update('tl_page', ['layout' => $layout], ['id' => $id]);
    $pages[$variant] = (int) $id;
}
$conversations = new HeimrichHannot\SimpleMemberChatBundle\Gateway\ConversationGateway($db);
$foreign = $conversations->findByPair($members['bob'], $members['carol']);
if ($foreign === null) {
    $foreign = $conversations->insert(Symfony\Component\Uid\Uuid::v7()->toRfc4122(), $members['bob'], $members['carol'], time());
    $participants = new HeimrichHannot\SimpleMemberChatBundle\Gateway\ParticipantGateway($db);
    $participants->add($foreign->id, $members['bob'], time());
    $participants->add($foreign->id, $members['carol'], time());
}
echo json_encode(['foreignUuid' => $foreign->uuid, 'legacyLayout' => (int) $legacyLayout, 'modernLayout' => (int) $modernLayout, 'group' => (int) $group, 'members' => $members, 'pages' => $pages], JSON_PRETTY_PRINT), "\n";
