<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Tests\Integration;

use Contao\DataContainer;
use HeimrichHannot\SimpleMemberChatBundle\EventListener\DataContainer\ChatMessage\ConfigOnDeleteListener;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ConversationGateway;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\MessageGateway;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ParticipantGateway;
use HeimrichHannot\SimpleMemberChatBundle\Tests\DatabaseTestCase;

final class BackendDeletionTest extends DatabaseTestCase
{
    public function testBeforeDeleteRepairHandlesMiddleTailAndFinalMessage(): void
    {
        $conversations = new ConversationGateway($this->connection);
        $messages = new MessageGateway($this->connection);
        $participants = new ParticipantGateway($this->connection);
        $conversation = $conversations->insert('01994daa-1111-7111-8111-111111111111', 7, 9, 100);
        $participants->add($conversation->id, 7, 100);
        $participants->add($conversation->id, 9, 100);

        $first = $messages->insert($conversation->id, 7, 'first', 101);
        $middle = $messages->insert($conversation->id, 7, 'middle', 102);
        $last = $messages->insert($conversation->id, 7, 'last', 103);
        $conversations->updateLastMessage($conversation, $last);
        foreach ([[$middle, $last->id, 103], [$last, $first->id, 101], [$first, 0, 0]] as [$deleted, $expectedId, $expectedAt]) {
            $dc = self::createStub(DataContainer::class);
            $dc->method('getCurrentRecord')->willReturn([
                'id' => $deleted->id,
                'pid' => $conversation->id,
            ]);
            new ConfigOnDeleteListener($this->connection)($dc);
            self::assertNotNull($messages->find($deleted->id), 'Contao invokes the callback before DELETE.');
            $this->connection->delete('tl_chat_message', [
                'id' => $deleted->id,
            ]);
            $repaired = $conversations->find($conversation->id);
            self::assertNotNull($repaired);
            self::assertSame($expectedId, $repaired->lastMessageId);
            self::assertSame($expectedAt, $repaired->lastMessageAt);
        }

        self::assertSame(0, $participants->unreadCount(9));
        $dc = self::createStub(DataContainer::class);
        $dc->method('getCurrentRecord')->willReturn(null);
        new ConfigOnDeleteListener($this->connection)($dc);
        self::assertNotNull($conversations->find($conversation->id));
    }

    public function testLatestTrackedPageAndPublicRecipientState(): void
    {
        $participants = new ParticipantGateway($this->connection);
        self::assertSame(0, $participants->lastPageId(7));
        self::assertNull($participants->state(1, 7));
        $participants->add(1, 7, 100);
        $participants->add(2, 7, 100);
        $participants->markRead(1, 7, 1, 101, 12);
        $participants->markRead(2, 7, 2, 102, 13);
        $participants->setMuted(2, 7, true, 103);
        self::assertSame(13, $participants->lastPageId(7));
        self::assertSame([
            'lastReadAt' => 102,
            'lastReadMessageId' => 2,
            'lastPageId' => 13,
            'muted' => true,
        ], $participants->state(2, 7));
        $participants->remove(2, 7);
        self::assertSame(12, $participants->lastPageId(7));
    }
}
