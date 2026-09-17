<?php

declare(strict_types=1);

// Authorized, idempotent history fixtures for the existing local demo only.
require '/var/www/html/vendor/autoload.php';
$kernel = Contao\ManagerBundle\HttpKernel\ContaoKernel::fromInput('/var/www/html', new Symfony\Component\Console\Input\ArgvInput(['console', '--env=prod']));
$kernel->boot();
$kernel->getContainer()->get('contao.framework')->initialize();
$db = $kernel->getContainer()->get('database_connection');
$alice = (int) $db->fetchOne('SELECT id FROM tl_member WHERE username = ?', ['phase3a-chat-alice']);
if ($alice < 1) {
    throw new RuntimeException('The phase 3a demo must already exist.');
}
$conversations = new HeimrichHannot\SimpleMemberChatBundle\Gateway\ConversationGateway($db);
$participants = new HeimrichHannot\SimpleMemberChatBundle\Gateway\ParticipantGateway($db);
$messages = new HeimrichHannot\SimpleMemberChatBundle\Gateway\MessageGateway($db);
$created = ['members' => [], 'conversations' => [], 'messages' => 0];
$db->transactional(function () use ($db, $alice, $conversations, $participants, $messages, &$created): void {
    for ($index = 1; $index <= 51; ++$index) {
        $username = sprintf('phase3b-history-%02d', $index);
        $member = (int) $db->fetchOne('SELECT id FROM tl_member WHERE username = ?', [$username]);
        if ($member === 0) {
            $db->insert('tl_member', ['tstamp' => time(), 'firstname' => 'History', 'lastname' => sprintf('%02d', $index), 'username' => $username, 'email' => $username . '@example.invalid', 'login' => 0, 'dateAdded' => time()]);
            $member = (int) $db->lastInsertId();
            $created['members'][] = $member;
        }
        if ($conversations->findByPair(min($alice, $member), max($alice, $member)) !== null) {
            continue;
        }
        $start = new DateTimeImmutable('today')->modify('-10 days')->getTimestamp();
        $conversation = $conversations->insert(Symfony\Component\Uid\Uuid::v7()->toRfc4122(), min($alice, $member), max($alice, $member), $start);
        $participants->add($conversation->id, $alice, $start);
        $participants->add($conversation->id, $member, $start);
        for ($number = 1; $number <= ($index === 1 ? 55 : 1); ++$number) {
            $timestamp = $start + ($index === 1 ? intdiv($number - 1, 10) * 86400 : 0) + $number;
            $message = $messages->insert($conversation->id, $member, sprintf('Phase 3b history %02d, message %02d', $index, $number), $timestamp);
            $conversation = $conversations->updateLastMessage($conversation, $message);
            ++$created['messages'];
        }
        $created['conversations'][] = ['id' => $conversation->id, 'uuid' => $conversation->uuid];
    }
});
echo json_encode(['memberIds' => $created['members'], 'conversationCount' => count($created['conversations']), 'historyConversation' => $created['conversations'][0] ?? null, 'messageCount' => $created['messages']], JSON_PRETTY_PRINT), "\n";
