<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Tests\Unit;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Image\Studio\Studio;
use Doctrine\DBAL\Connection;
use HeimrichHannot\SimpleMemberChatBundle\Backend\MemberLabels;
use HeimrichHannot\SimpleMemberChatBundle\Configuration\ChatOptions;
use HeimrichHannot\SimpleMemberChatBundle\Contact\ContactFactory;
use HeimrichHannot\SimpleMemberChatBundle\Contact\ContactResolver;
use HeimrichHannot\SimpleMemberChatBundle\EventListener\DataContainer\ChatMessage\ListLabelLabelListener;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ContactGatewayInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

final class BackendLabelsTest extends TestCase
{
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
