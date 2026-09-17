<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Tests;

use Contao\FrontendUser;
use Contao\TestCase\ContaoTestCase;
use HeimrichHannot\SimpleMemberChatBundle\Service\FrontendMemberProvider;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

abstract class ServiceTestCase extends ContaoTestCase
{
    protected function memberProvider(int $id): FrontendMemberProvider
    {
        $user = $this->createClassWithPropertiesStub(FrontendUser::class, [
            'id' => $id,
        ]);
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken($user, 'contao_frontend', ['ROLE_MEMBER']));

        return new FrontendMemberProvider($storage);
    }
}
