<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Tests\Unit;

use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\Model\Collection;
use Contao\PageModel;
use Contao\TestCase\ContaoTestCase;
use HeimrichHannot\SimpleMemberChatBundle\Domain\Conversation;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ParticipantGatewayInterface;
use HeimrichHannot\SimpleMemberChatBundle\Service\ConversationUrlGenerator;

final class ConversationUrlGeneratorTest extends ContaoTestCase
{
    public function testTrackedPageWinsAndListClearsConversationItem(): void
    {
        $page = $this->createClassWithPropertiesStub(PageModel::class, [
            'type' => 'regular',
            'isPublic' => true,
            'rootIsPublic' => true,
        ]);
        $adapter = $this->createAdapterMock(['findPublishedById', 'findPublishedRootPages']);
        $adapter->expects(self::exactly(2))->method('__call')->with('findPublishedById', [
            12, [
                'ignoreFePreview' => true,
            ]])->willReturn($page);
        $participants = self::createStub(ParticipantGatewayInterface::class);
        $participants->method('state')->willReturn([
            'lastPageId' => 12,
            'lastReadAt' => 1,
            'lastReadMessageId' => 0,
            'muted' => false,
        ]);
        $participants->method('lastPageId')->willReturn(12);
        $urls = $this->createMock(ContentUrlGenerator::class);
        $urls->expects(self::exactly(2))->method('generate')->willReturnCallback(static function (PageModel $page, array $parameters): string {
            self::assertIsString($parameters['parameters']);

            return '/chat' . $parameters['parameters'];
        });
        $generator = new ConversationUrlGenerator($this->createContaoFrameworkStub([
            PageModel::class => $adapter,
        ]), $urls, $participants);
        self::assertSame('/chat/uuid', $generator->forConversation(new Conversation(1, 'uuid', 7, 9, 0, 0, 0), 7));
        self::assertSame('/chat', $generator->listPage(7));
    }

    public function testInvalidTrackedDestinationsFallBackInPublishedRootOrder(): void
    {
        foreach ([null, 'redirect', 'unpublished-root'] as $invalid) {
            $bad = $invalid === null ? null : $this->createClassWithPropertiesStub(PageModel::class, [
                'type' => $invalid === 'redirect' ? 'redirect' : 'regular',
                'isPublic' => true,
                'rootIsPublic' => false,
            ]);
            $good = $this->createClassWithPropertiesStub(PageModel::class, [
                'type' => 'regular',
                'isPublic' => true,
                'rootIsPublic' => true,
            ]);
            $root = $this->createClassWithPropertiesStub(PageModel::class, [
                'memberChatPage' => 20,
            ]);
            $adapter = $this->createAdapterMock(['findPublishedById', 'findPublishedRootPages']);
            $adapter->expects(self::exactly(3))->method('__call')->willReturnCallback(static function (string $method, array $args) use ($bad, $good, $root): mixed {
                if ($method === 'findPublishedRootPages') {
                    self::assertSame([[
                        'ignoreFePreview' => true,
                        'order' => 'tl_page.sorting, tl_page.id',
                    ]], $args);

                    return new Collection([$root], 'tl_page');
                }

                return $args[0] === 12 ? $bad : $good;
            });
            $participants = self::createStub(ParticipantGatewayInterface::class);
            $participants->method('lastPageId')->willReturn(12);
            $urls = self::createStub(ContentUrlGenerator::class);
            $urls->method('generate')->willReturn('/fallback');
            $generator = new ConversationUrlGenerator($this->createContaoFrameworkStub([
                PageModel::class => $adapter,
            ]), $urls, $participants);
            self::assertSame('/fallback', $generator->listPage(7));
        }
    }

    public function testNoPublishedRootReturnsNull(): void
    {
        $adapter = $this->createAdapterStub(['findPublishedRootPages']);
        $adapter->method('__call')->willReturn(null);
        $generator = new ConversationUrlGenerator($this->createContaoFrameworkStub([
            PageModel::class => $adapter,
        ]), self::createStub(ContentUrlGenerator::class), self::createStub(ParticipantGatewayInterface::class));
        self::assertNull($generator->listPage(7));
        self::assertNull($generator->forConversation(new Conversation(1, 'uuid', 7, 9, 0, 0, 0), 7));
    }
}
