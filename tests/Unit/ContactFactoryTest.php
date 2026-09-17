<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Tests\Unit;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Image\Studio\Figure;
use Contao\CoreBundle\Image\Studio\FigureBuilder;
use Contao\CoreBundle\Image\Studio\ImageResult;
use Contao\CoreBundle\Image\Studio\Studio;
use Contao\FilesModel;
use Contao\MemberModel;
use Contao\Model\Collection;
use HeimrichHannot\SimpleMemberChatBundle\Configuration\ChatOptions;
use HeimrichHannot\SimpleMemberChatBundle\Contact\ContactFactory;
use HeimrichHannot\SimpleMemberChatBundle\Tests\ServiceTestCase;

final class ContactFactoryTest extends ServiceTestCase
{
    public function testNamesModelAndNullAvatars(): void
    {
        $studio = $this->createMock(Studio::class);
        $studio->expects(self::never())->method('createFigureBuilder');
        $framework = $this->createMock(ContaoFramework::class);
        $framework->expects(self::never())->method('getAdapter');
        $factory = new ContactFactory(new ChatOptions(), $studio, $framework);
        $row = [
            'id' => '7',
            'firstname' => 'Anne',
            'lastname' => 'Example',
            'username' => 'fallback',
            'avatar' => 'ignored',
        ];
        $member = self::createStub(MemberModel::class);
        $member->method('row')->willReturn($row);
        $contact = $factory->fromMemberModel($member, 'Coach');
        self::assertSame(7, $contact->memberId);
        self::assertSame('Anne Example', $contact->displayName);
        self::assertSame('Coach', $contact->subtitle);
        self::assertNull($contact->avatarUrl);
        self::assertSame('fallback', $factory->fromMemberRow([
            'username' => 'fallback',
        ])->displayName);
        self::assertSame('0', $factory->fromMemberRow([
            'firstname' => '0',
            'username' => 'fallback',
        ])->displayName);
        $configured = new ContactFactory(new ChatOptions(avatarField: 'avatar'), $studio, $framework);
        foreach ([[], [
            'avatar' => null,
        ], [
            'avatar' => '',
        ]] as $missing) {
            self::assertNull($configured->fromMemberRow($missing)->avatarUrl);
        }

        self::assertNull($configured->fromMemberRows([[]])[0]->avatarUrl);
        self::assertSame([], $configured->fromMemberRows([]));
    }

    public function testSingleUuidUsesConfiguredSizeAndMissingFileIsNull(): void
    {
        $builder = $this->createMock(FigureBuilder::class);
        $builder->expects(self::exactly(2))->method('fromUuid')->with('uuid')->willReturnSelf();
        $builder->expects(self::exactly(2))->method('setSize')->with(12)->willReturnSelf();
        $image = self::createStub(ImageResult::class);
        $image->method('getImageSrc')->willReturn('/assets/avatar.webp');
        $builder->method('buildIfResourceExists')->willReturnOnConsecutiveCalls(new Figure($image), null);
        $studio = self::createStub(Studio::class);
        $studio->method('createFigureBuilder')->willReturn($builder);
        $factory = new ContactFactory(new ChatOptions(avatarField: 'avatar', avatarSize: 12), $studio, self::createStub(ContaoFramework::class));
        self::assertSame('/assets/avatar.webp', $factory->fromMemberRow([
            'avatar' => 'uuid',
        ])->avatarUrl);
        self::assertNull($factory->fromMemberRow([
            'avatar' => 'uuid',
        ])->avatarUrl);
    }

    public function testBatchLoadsFilesOnceAndUsesModelsWithoutUuidLookups(): void
    {
        $uuid = str_repeat('a', 16);
        $missing = str_repeat('b', 16);
        $file = $this->createClassWithPropertiesStub(FilesModel::class, [
            'uuid' => $uuid,
        ]);
        $adapter = $this->createAdapterMock(['findMultipleByUuids']);
        $adapter->expects(self::once())->method('__call')->with('findMultipleByUuids', [[$uuid, $missing]])->willReturn(new Collection([$file], 'tl_files'));
        $framework = $this->createContaoFrameworkStub([
            FilesModel::class => $adapter,
        ]);
        $builder = $this->createMock(FigureBuilder::class);
        $builder->expects(self::never())->method('fromUuid');
        $builder->expects(self::once())->method('fromFilesModel')->with($file)->willReturnSelf();
        $builder->expects(self::once())->method('setSize')->with([96, 96, 'crop'])->willReturnSelf();
        $image = self::createStub(ImageResult::class);
        $image->method('getImageSrc')->willReturn('/avatar.webp');
        $builder->method('buildIfResourceExists')->willReturn(new Figure($image));
        $studio = self::createStub(Studio::class);
        $studio->method('createFigureBuilder')->willReturn($builder);
        $factory = new ContactFactory(new ChatOptions(avatarField: 'avatar'), $studio, $framework);
        $contacts = $factory->fromMemberRows([[
            'id' => 1,
            'avatar' => $uuid,
        ], [
            'id' => 2,
            'avatar' => $uuid,
        ], [
            'id' => 3,
            'avatar' => $missing,
        ], [
            'id' => 4,
        ]]);
        self::assertSame(['/avatar.webp', '/avatar.webp', null, null], array_column($contacts, 'avatarUrl'));
    }

    public function testBatchMissingFileReturnsNull(): void
    {
        $adapter = $this->createAdapterMock(['findMultipleByUuids']);
        $adapter->expects(self::once())->method('__call')->with('findMultipleByUuids', [['missing']])->willReturn(null);
        $studio = $this->createMock(Studio::class);
        $studio->expects(self::never())->method('createFigureBuilder');
        $factory = new ContactFactory(new ChatOptions(avatarField: 'avatar'), $studio, $this->createContaoFrameworkStub([
            FilesModel::class => $adapter,
        ]));
        self::assertNull($factory->fromMemberRows([[
            'avatar' => 'missing',
        ]])[0]->avatarUrl);
    }
}
