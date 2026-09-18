<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Tests\Unit\Pwa;

use Contao\TestCase\ContaoTestCase;
use HeimrichHannot\PwaBundle\Model\PwaConfigurationsModel;
use HeimrichHannot\PwaBundle\Model\PwaPushSubscriberModel;
use HeimrichHannot\PwaBundle\Sender\PushNotificationSender;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\MessageGatewayInterface;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ParticipantGatewayInterface;
use HeimrichHannot\SimpleMemberChatBundle\Integration\Pwa\ChatNotification;
use HeimrichHannot\SimpleMemberChatBundle\Integration\Pwa\PushOptions;
use HeimrichHannot\SimpleMemberChatBundle\Integration\Pwa\SendChatPushMessage;
use HeimrichHannot\SimpleMemberChatBundle\Tests\PwaTestTrait;
use Psr\Log\LoggerInterface;

final class SendChatPushHandlerTest extends ContaoTestCase
{
    use PwaTestTrait;

    public function testFreshRecipientStateAndPerRecipientAbsoluteLinks(): void
    {
        $participants = self::createStub(ParticipantGatewayInterface::class);
        $participants->method('state')->willReturnCallback(static fn (int $conversation, int $member): ?array => $member === 11 ? null : [
            'lastReadAt' => $member === 12 ? time() : 0,
            'lastReadMessageId' => 0,
            'lastPageId' => $member + 100,
            'muted' => $member === 10,
        ]);
        $sent = [];
        $sender = $this->createMock(PushNotificationSender::class);
        $sender->expects(self::exactly(2))->method('sendWithLog')->willReturnCallback(static function (ChatNotification $notification, PwaConfigurationsModel $config, LoggerInterface $logger, array $targets) use (&$sent): bool {
            self::assertCount(1, $targets);
            self::assertInstanceOf(PwaPushSubscriberModel::class, $targets[0]);
            $member = (int) $targets[0]->member;
            $sent[] = $member;
            self::assertSame([
                'data' => [
                    'clickJumpTo' => 'https://example.org/page' . ($member + 100) . '/uuid',
                ],
                'title' => 'Alice Chat',
                'body' => 'Hell',
            ], $notification->toArray());

            return true;
        });
        $this->pushHandler($participants, $sender)(new SendChatPushMessage(42, [7, 9, 9, 10, 11, 12, 13]));
        self::assertSame([9, 13], $sent);
    }

    public function testUnicodeTruncationPreservesCharacters(): void
    {
        $participants = self::createStub(ParticipantGatewayInterface::class);
        $participants->method('state')->willReturn([
            'lastReadAt' => 0,
            'lastReadMessageId' => 0,
            'lastPageId' => 0,
            'muted' => false,
        ]);
        $sender = $this->createMock(PushNotificationSender::class);
        $sender->expects(self::once())->method('sendWithLog')->willReturnCallback(static function (ChatNotification $notification): bool {
            self::assertSame([
                'title' => 'Alice Chat',
                'body' => 'Hello 🌍',
            ], $notification->toArray());

            return true;
        });
        $this->pushHandler($participants, $sender, new PushOptions(true, 3, 60, 7), link: false)(new SendChatPushMessage(42, [9]));
    }

    public function testNoDestinationAndPrivacyDefaultStillSend(): void
    {
        $participants = self::createStub(ParticipantGatewayInterface::class);
        $participants->method('state')->willReturn([
            'lastReadAt' => 0,
            'lastReadMessageId' => 0,
            'lastPageId' => 0,
            'muted' => false,
        ]);
        $sender = $this->createMock(PushNotificationSender::class);
        $sender->expects(self::once())->method('sendWithLog')->willReturnCallback(static function (ChatNotification $notification): bool {
            self::assertSame([
                'title' => 'Alice Chat',
            ], $notification->toArray());

            return true;
        });
        $this->pushHandler($participants, $sender, new PushOptions(true, 3), link: false)(new SendChatPushMessage(42, [9]));
    }

    public function testEmptyAndForeignSubscriptionsNeverBecomeBroadcast(): void
    {
        $participants = self::createStub(ParticipantGatewayInterface::class);
        $participants->method('state')->willReturn([
            'lastReadAt' => 0,
            'lastReadMessageId' => 0,
            'lastPageId' => 0,
            'muted' => false,
        ]);
        $sender = $this->createMock(PushNotificationSender::class);
        $sender->expects(self::never())->method('sendWithLog');
        foreach ([[], [
            $this->createClassWithPropertiesStub(PwaPushSubscriberModel::class, [
                'pid' => 3,
                'member' => 99,
            ])], [
                $this->createClassWithPropertiesStub(PwaPushSubscriberModel::class, [
                    'pid' => 4,
                    'member' => 9,
                ])]] as $targets) {
            $this->pushHandler($participants, $sender, subscribers: $targets)(new SendChatPushMessage(42, [9]));
        }
    }

    public function testFailureIsLoggedAndLaterRecipientsContinue(): void
    {
        $participants = self::createStub(ParticipantGatewayInterface::class);
        $participants->method('state')->willReturn([
            'lastReadAt' => time(),
            'lastReadMessageId' => 0,
            'lastPageId' => 0,
            'muted' => false,
        ]);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('Chat push failed for recipient.', self::callback(static fn (array $context): bool => $context['recipientId'] === 9 && $context['exception'] instanceof \RuntimeException));
        $logger->expects(self::once())->method('warning')->with('Chat push sender could not send notification.', [
            'messageId' => 42,
            'recipientId' => 10,
        ]);
        $calls = 0;
        $sender = $this->createMock(PushNotificationSender::class);
        $sender->expects(self::exactly(3))->method('sendWithLog')->willReturnCallback(static function () use (&$calls): bool {
            if (++$calls === 1) {
                throw new \RuntimeException('Offline');
            }

            return $calls === 3;
        });
        $this->pushHandler($participants, $sender, new PushOptions(true, 3, 0), $logger, link: false)(new SendChatPushMessage(42, [9, 10, 11]));
    }

    public function testDisabledPushDeletedMessageAndDisabledConfigurationDoNotSend(): void
    {
        $participants = self::createStub(ParticipantGatewayInterface::class);
        $sender = $this->createMock(PushNotificationSender::class);
        $sender->expects(self::never())->method('sendWithLog');
        $this->pushHandler($participants, $sender, new PushOptions())(new SendChatPushMessage(42, [9]));
        $this->pushHandler($participants, $sender, messages: self::createStub(MessageGatewayInterface::class))(new SendChatPushMessage(42, [9]));
        $this->pushHandler($participants, $sender, supportPush: '')(new SendChatPushMessage(42, [9]));
    }

    public function testLookupFailureIsLogged(): void
    {
        $messages = self::createStub(MessageGatewayInterface::class);
        $messages->method('find')->willThrowException(new \RuntimeException('Database unavailable'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('Chat push failed.', self::callback(static fn (array $context): bool => $context['messageId'] === 42));
        $this->pushHandler(self::createStub(ParticipantGatewayInterface::class), self::createStub(PushNotificationSender::class), logger: $logger, messages: $messages)(new SendChatPushMessage(42, [9]));
    }
}
