<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Tests\Integration;

use HeimrichHannot\PwaBundle\Model\PwaConfigurationsModel;
use HeimrichHannot\PwaBundle\Model\PwaPushSubscriberModel;
use HeimrichHannot\PwaBundle\Sender\PushNotificationSender;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ConversationGateway;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\MessageGateway;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ParticipantGateway;
use HeimrichHannot\SimpleMemberChatBundle\Integration\Pwa\ChatNotification;
use HeimrichHannot\SimpleMemberChatBundle\Integration\Pwa\SendChatPushMessage;
use HeimrichHannot\SimpleMemberChatBundle\Tests\DatabaseTestCase;
use HeimrichHannot\SimpleMemberChatBundle\Tests\PwaTestTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

final class PushRecipientTest extends DatabaseTestCase
{
    use PwaTestTrait;

    public function testQueuedRecipientsAreFilteredAgainstCurrentDatabaseState(): void
    {
        $conversations = new ConversationGateway($this->connection);
        $messages = new MessageGateway($this->connection);
        $participants = new ParticipantGateway($this->connection);
        $conversation = $conversations->insert(Uuid::v7()->toRfc4122(), 7, 9, time() - 100);
        foreach ([7, 9, 10, 11, 12, 13] as $member) {
            $participants->add($conversation->id, $member, time() - 100);
        }

        $message = $messages->insert($conversation->id, 7, 'Hello!', time() - 100);
        $queued = new SendChatPushMessage($message->id, [9, 10, 11, 12]);
        // These changes happen after enqueue: stale event state must never control delivery.
        $participants->setMuted($conversation->id, 10, true, time());
        $participants->remove($conversation->id, 11);
        $participants->markRead($conversation->id, 12, $message->id, time(), 112);
        $participants->markRead($conversation->id, 9, 0, time() - 61, 109);

        $sender = $this->createMock(PushNotificationSender::class);
        $sender->expects(self::once())->method('sendWithLog')->willReturnCallback(static function (ChatNotification $notification, PwaConfigurationsModel $config, LoggerInterface $logger, array $targets): bool {
            self::assertCount(1, $targets);
            self::assertInstanceOf(PwaPushSubscriberModel::class, $targets[0]);
            self::assertSame(9, $targets[0]->member);

            return true;
        });
        $handler = $this->pushHandler($participants, $sender, messages: $messages, conversations: $conversations);
        $handler($queued);
        // Erasure after queueing also suppresses delivery.
        $conversations->delete($conversation->id);
        $handler($queued);
    }
}
