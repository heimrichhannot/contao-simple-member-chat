<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Tests\Unit;

use HeimrichHannot\SimpleMemberChatBundle\Configuration\ChatOptions;
use HeimrichHannot\SimpleMemberChatBundle\Contact\ContactProviderInterface;
use HeimrichHannot\SimpleMemberChatBundle\Contact\ContactProviderRegistry;
use HeimrichHannot\SimpleMemberChatBundle\Contact\ContactService;
use HeimrichHannot\SimpleMemberChatBundle\Contact\Viewer;
use HeimrichHannot\SimpleMemberChatBundle\Domain\Contact;
use HeimrichHannot\SimpleMemberChatBundle\Exception\AuthenticationRequiredException;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ContactGatewayInterface;
use HeimrichHannot\SimpleMemberChatBundle\Service\ContactPermission;
use HeimrichHannot\SimpleMemberChatBundle\Tests\ServiceTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class ContactServiceTest extends ServiceTestCase
{
    public function testSearchNormalisesCapsExcludesSelfAndDeduplicates(): void
    {
        $provider = $this->createMock(ContactProviderInterface::class);
        $provider->expects(self::once())->method('search')->with(new Viewer(9, [2]), 'Än', 2)->willReturn([
            new Contact(9, 'Self'), new Contact(7, 'First'), new Contact(7, 'Duplicate'), new Contact(0, 'Deleted'), new Contact(8, 'Second'), new Contact(10, 'Over limit'),
        ]);
        $members = $this->createMock(ContactGatewayInterface::class);
        $members->expects(self::once())->method('activeGroupIds')->with(9)->willReturn([2]);
        $options = new ChatOptions(searchLimit: 2);
        $requests = new RequestStack([new Request()]);
        $service = new ContactService(new ContactProviderRegistry([
            'member_groups' => $provider,
        ], $options), $members, $this->memberProvider(9), $options, $requests);
        self::assertSame([7, 8], array_column($service->search('  Än  ', 999), 'memberId'));
        self::assertSame($service->viewer(), $service->viewer());
        self::assertSame([], $service->search(' Ä '));
        self::assertSame([], $service->search('Anna', 0));
        self::assertSame([], $service->search('Anna', -1));
    }

    public function testDefaultAndSmallerRequestedLimit(): void
    {
        $provider = $this->createMock(ContactProviderInterface::class);
        $provider->expects(self::exactly(2))->method('search')->willReturnCallback(static function (Viewer $viewer, string $query, int $limit): array {
            self::assertSame('Anna', $query);

            return array_fill(0, $limit, new Contact(7, (string) $limit));
        });
        $members = self::createStub(ContactGatewayInterface::class);
        $members->method('activeGroupIds')->willReturn([]);
        $options = new ChatOptions(searchLimit: 3);
        $service = new ContactService(new ContactProviderRegistry([
            'member_groups' => $provider,
        ], $options), $members, $this->memberProvider(9), $options, new RequestStack());
        self::assertSame('3', $service->search('Anna')[0]->displayName);
        self::assertSame('1', $service->search('Anna', 1)[0]->displayName);
    }

    public function testPermissionAdapterAndOwnIdDenial(): void
    {
        $provider = $this->createMock(ContactProviderInterface::class);
        $provider->expects(self::once())->method('canContact')->with(new Viewer(9, [2]), 7)->willReturn(true);
        $members = self::createStub(ContactGatewayInterface::class);
        $members->method('activeGroupIds')->willReturn([2]);
        $options = new ChatOptions();
        $service = new ContactService(new ContactProviderRegistry([
            'member_groups' => $provider,
        ], $options), $members, $this->memberProvider(9), $options, new RequestStack());
        $adapter = new ContactPermission($service);
        self::assertFalse($adapter->canContact(8, 7));
        self::assertFalse($adapter->canContact(9, 9));
        self::assertFalse($service->canContact(0));
        self::assertTrue($adapter->canContact(9, 7));
    }

    public function testViewerCacheDoesNotSurviveTheRequest(): void
    {
        $members = $this->createMock(ContactGatewayInterface::class);
        $members->expects(self::exactly(2))->method('activeGroupIds')->willReturnOnConsecutiveCalls([2], [3]);
        $requests = new RequestStack([new Request()]);
        $options = new ChatOptions();
        $service = new ContactService(new ContactProviderRegistry([], $options), $members, $this->memberProvider(9), $options, $requests);
        self::assertSame([2], $service->viewer()->groupIds);
        $requests->push(new Request());
        self::assertSame([2], $service->viewer()->groupIds);
        $requests->pop();
        $requests->pop();
        $requests->push(new Request());
        self::assertSame([3], $service->viewer()->groupIds);
    }

    public function testDeletedViewerCannotUseProviders(): void
    {
        $members = self::createStub(ContactGatewayInterface::class);
        $members->method('activeGroupIds')->willReturn(null);
        $options = new ChatOptions();
        $service = new ContactService(new ContactProviderRegistry([], $options), $members, $this->memberProvider(9), $options, new RequestStack());
        $this->expectException(AuthenticationRequiredException::class);
        $service->search('Anna');
    }
}
