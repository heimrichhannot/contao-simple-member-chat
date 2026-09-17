<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Tests\Unit;

use Contao\ContentModel;
use Contao\CoreBundle\Event\CloseAccountEvent;
use Contao\DataContainer;
use Contao\MemberModel;
use Doctrine\DBAL\Connection;
use HeimrichHannot\SimpleMemberChatBundle\EventListener\CloseAccountEventListener;
use HeimrichHannot\SimpleMemberChatBundle\EventListener\DataContainer\Member\ConfigOnDeleteListener;
use HeimrichHannot\SimpleMemberChatBundle\EventListener\Hook\CloseAccountListener;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ConversationGatewayInterface;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\MessageGatewayInterface;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ParticipantGatewayInterface;
use HeimrichHannot\SimpleMemberChatBundle\Service\ChatTransaction;
use HeimrichHannot\SimpleMemberChatBundle\Service\MemberDataEraser;
use HeimrichHannot\SimpleMemberChatBundle\Tests\ServiceTestCase;

final class MemberDeletionListenersTest extends ServiceTestCase
{
    public function testAllDeletionEntrypointsDelegateButDeactivationDoesNot(): void
    {
        $connection = self::createStub(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->method('transactional')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $participants = $this->createMock(ParticipantGatewayInterface::class);
        $participants->expects(self::exactly(3))->method('conversationIds')->with(7)->willReturn([1]);
        $participants->expects(self::exactly(3))->method('remove')->with(1, 7);
        $participants->method('memberIds')->willReturn([9]);
        $conversations = $this->createMock(ConversationGatewayInterface::class);
        $conversations->expects(self::exactly(3))->method('find')->with(1, true);
        $conversations->expects(self::never())->method('delete');
        $messages = $this->createMock(MessageGatewayInterface::class);
        $messages->expects(self::exactly(3))->method('anonymize')->with(7);
        $eraser = new MemberDataEraser($conversations, $participants, $messages, new ChatTransaction($connection));
        (new ConfigOnDeleteListener($eraser))($this->createClassWithPropertiesStub(DataContainer::class, [
            'id' => 7,
        ]));
        $hook = new CloseAccountListener($eraser);
        $hook(7, 'close_delete');
        $hook(7, 'close_deactivate');
        $listener = new CloseAccountEventListener($eraser);
        foreach (['close_delete', 'close_deactivate'] as $mode) {
            $listener(new CloseAccountEvent($this->createClassWithPropertiesStub(MemberModel::class, [
                'id' => 7,
            ]), $this->createClassWithPropertiesStub(ContentModel::class, [
                'reg_close' => $mode,
            ])));
        }
    }

    public function testNestedTransactionsAreRejectedBeforeOperation(): void
    {
        $connection = self::createStub(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $this->expectException(\LogicException::class);
        new ChatTransaction($connection)->run(static function (): never {
            self::fail('The nested operation must not run.');
        });
    }
}
