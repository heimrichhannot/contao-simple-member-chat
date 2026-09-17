<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Contact;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Image\Studio\Studio;
use Contao\FilesModel;
use Contao\MemberModel;
use Contao\Model\Collection;
use HeimrichHannot\SimpleMemberChatBundle\Configuration\ChatOptions;
use HeimrichHannot\SimpleMemberChatBundle\Domain\Contact;

final readonly class ContactFactory
{
    public function __construct(
        private ChatOptions $options,
        private Studio $studio,
        private ContaoFramework $framework,
    ) {
    }

    public function fromMemberModel(MemberModel $member, ?string $subtitle = null): Contact
    {
        /** @var array<string, mixed> $row */
        $row = $member->row();

        return $this->fromMemberRow($row, $subtitle);
    }

    /**
     * @param array<string, mixed> $row
     */
    public function fromMemberRow(array $row, ?string $subtitle = null): Contact
    {
        $avatar = null;
        $uuid = $this->options->avatarField === null ? null : ($row[$this->options->avatarField] ?? null);
        if (\is_string($uuid) && $uuid !== '') {
            $avatar = $this->studio->createFigureBuilder()->fromUuid($uuid)->setSize($this->options->avatarSize)->buildIfResourceExists()?->getImage()->getImageSrc();
        }

        return $this->contact($row, $subtitle, $avatar);
    }

    /** @param list<array<string, mixed>> $rows
     * @return list<Contact>
     */
    public function fromMemberRows(array $rows): array
    {
        $uuids = [];
        foreach ($rows as $row) {
            $uuid = $this->options->avatarField === null ? null : ($row[$this->options->avatarField] ?? null);
            if (\is_string($uuid) && $uuid !== '') {
                $uuids[] = $uuid;
            }
        }

        $avatars = [];
        if ($uuids !== []) {
            $this->framework->initialize();
            /** @var Collection<FilesModel>|null $files */
            $files = $this->framework->getAdapter(FilesModel::class)->__call('findMultipleByUuids', [array_values(array_unique($uuids))]);
            foreach ($files ?? [] as $file) {
                $avatars[$file->uuid] = $this->studio->createFigureBuilder()->fromFilesModel($file)->setSize($this->options->avatarSize)->buildIfResourceExists()?->getImage()->getImageSrc();
            }
        }

        $contacts = [];
        foreach ($rows as $row) {
            $uuid = $this->options->avatarField === null ? null : ($row[$this->options->avatarField] ?? null);
            $contacts[] = $this->contact($row, null, \is_string($uuid) ? ($avatars[$uuid] ?? null) : null);
        }

        return $contacts;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function contact(array $row, ?string $subtitle, ?string $avatar): Contact
    {
        $firstname = \is_string($row['firstname'] ?? null) ? $row['firstname'] : '';
        $lastname = \is_string($row['lastname'] ?? null) ? $row['lastname'] : '';
        $username = \is_string($row['username'] ?? null) ? $row['username'] : '';
        $id = $row['id'] ?? 0;
        $name = trim($firstname . ' ' . $lastname);

        return new Contact(\is_int($id) || \is_string($id) ? (int) $id : 0, $name !== '' ? $name : $username, $subtitle, $avatar);
    }
}
