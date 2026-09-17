<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\DependencyInjection\Compiler;

use HeimrichHannot\SimpleMemberChatBundle\Contact\ContactProviderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ContactProviderPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $aliases = [];
        foreach ($container->findTaggedServiceIds('contao_member_chat.contact_provider') as $id => $tags) {
            $definition = $container->findDefinition($id);
            if ($definition->isAbstract()) {
                continue;
            }

            $class = $container->getParameterBag()->resolveValue($definition->getClass());
            if (!\is_string($class) || !is_a($class, ContactProviderInterface::class, true)) {
                throw new \InvalidArgumentException(\sprintf('Contact provider "%s" must implement %s.', $id, ContactProviderInterface::class));
            }

            $alias = $class::getAlias();
            if (isset($aliases[$alias])) {
                throw new \InvalidArgumentException(\sprintf('Duplicate contact provider alias "%s".', $alias));
            }

            $aliases[$alias] = true;
        }

        $selected = $container->getParameter('contao_member_chat.contact_provider');
        if (!\is_string($selected) || !isset($aliases[$selected])) {
            throw new \InvalidArgumentException(\sprintf('Unknown contact provider "%s". Available providers: %s.', \is_string($selected) ? $selected : '', implode(', ', array_keys($aliases))));
        }
    }
}
