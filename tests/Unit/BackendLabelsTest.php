<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Tests\Unit;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Image\Studio\Studio;
use Contao\CoreBundle\Translation\Translator;
use Doctrine\DBAL\Connection;
use HeimrichHannot\SimpleMemberChatBundle\Backend\MemberLabels;
use HeimrichHannot\SimpleMemberChatBundle\Configuration\ChatOptions;
use HeimrichHannot\SimpleMemberChatBundle\Contact\ContactFactory;
use HeimrichHannot\SimpleMemberChatBundle\Contact\ContactResolver;
use HeimrichHannot\SimpleMemberChatBundle\EventListener\DataContainer\ChatConversation\ListLabelLabelListener as ConversationLabelListener;
use HeimrichHannot\SimpleMemberChatBundle\EventListener\DataContainer\ChatMessage\ListLabelLabelListener;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ContactGatewayInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Contracts\Translation\TranslatorInterface;

final class BackendLabelsTest extends TestCase
{
    public function testConversationLabelsUseContaoFormattingInBothLanguages(): void
    {
        foreach ([
            'en' => 'last message',
            'de' => 'letzte Nachricht',
        ] as $locale => $wording) {
            // Only catalogue loading is isolated; trans() executes Contao's real vsprintf path.
            $translator = $this->getMockBuilder(Translator::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['getCatalogue'])
                ->getMock();
            $translator->expects(self::atLeastOnce())->method('getCatalogue')->willReturn(new MessageCatalogue($locale, [
                'contao_tl_chat_conversation' => require __DIR__ . '/../../translations/contao_tl_chat_conversation.' . $locale . '.php',
                'contao_default' => require __DIR__ . '/../../translations/contao_default.' . $locale . '.php',
            ]));
            $connection = $this->createMock(Connection::class);
            $connection->expects(self::once())->method('fetchFirstColumn')->willReturn([7, 9]);
            $gateway = $this->createMock(ContactGatewayInterface::class);
            $gateway->expects(self::once())->method('findMembers')->with([7, 9])->willReturn([
                [
                    'id' => 7,
                    'username' => '<Alice> 100%',
                ],
            ]);
            $resolver = new ContactResolver($gateway, new ContactFactory(new ChatOptions(), self::createStub(Studio::class), self::createStub(ContaoFramework::class)), $translator);
            $listener = new ConversationLabelListener(new MemberLabels($connection, $resolver, new RequestStack([Request::create('/contao')])), $translator);
            $deleted = $translator->trans('MSC.member_chat.deleted_member', [], 'contao_default');
            foreach ([1789653654, 0] as $timestamp) {
                $label = $listener([
                    'memberLow' => 7,
                    'memberHigh' => 9,
                    'lastMessageAt' => $timestamp,
                ]);
                self::assertSame('&lt;Alice&gt; 100% ↔ ' . $deleted . ', ' . $wording . ': ' . ($timestamp > 0 ? date('Y-m-d H:i', $timestamp) : '—'), $label);
            }
        }
    }

    public function testAuthorBatchIsReusedAcrossRowsAndLabelsAreEscaped(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchFirstColumn')->willReturn([7, 9]);
        $gateway = $this->createMock(ContactGatewayInterface::class);
        $gateway->expects(self::once())->method('findMembers')->with([7, 9])->willReturn([[
            'id' => 7,
            'username' => '<Alice>',
        ]]);
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Deleted member');
        $resolver = new ContactResolver($gateway, new ContactFactory(new ChatOptions(), self::createStub(Studio::class), self::createStub(ContaoFramework::class)), $translator);
        $listener = new ListLabelLabelListener(new MemberLabels($connection, $resolver, new RequestStack([Request::create('/contao')])));
        self::assertStringContainsString('&lt;Alice&gt;', $listener([
            'author' => 7,
            'createdAt' => 100,
            'body' => '<script>',
        ]));
        $label = $listener([
            'author' => 9,
            'createdAt' => 100,
            'body' => "first\n<script>",
        ]);
        self::assertStringContainsString('Deleted member', $label);
        self::assertStringContainsString('first &lt;script&gt;', $label);
        self::assertStringNotContainsString('<script>', $label);
    }
}
