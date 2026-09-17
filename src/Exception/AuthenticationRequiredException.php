<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Exception;

final class AuthenticationRequiredException extends ChatException
{
    public function __construct()
    {
        parent::__construct('member_chat.authentication_required', 401);
    }
}
