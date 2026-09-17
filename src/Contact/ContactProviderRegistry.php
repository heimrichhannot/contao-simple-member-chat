<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Contact;

use HeimrichHannot\SimpleMemberChatBundle\Configuration\ChatOptions;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class ContactProviderRegistry
{
    /**
     * @param iterable<string, ContactProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator('contao_member_chat.contact_provider', defaultIndexMethod: 'getAlias')]
        private iterable $providers,
        private ChatOptions $options,
    ) {
    }

    public function get(): ContactProviderInterface
    {
        foreach ($this->providers as $alias => $provider) {
            if ($alias === $this->options->contactProvider) {
                return $provider;
            }
        }

        throw new \InvalidArgumentException(\sprintf('Unknown contact provider "%s".', $this->options->contactProvider));
    }
}
