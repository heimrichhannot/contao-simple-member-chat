<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Integration\Pwa;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Model\Collection;
use HeimrichHannot\PwaBundle\Model\PwaConfigurationsModel;
use HeimrichHannot\PwaBundle\Model\PwaPushSubscriberModel;
use HeimrichHannot\PwaBundle\Sender\PushNotificationSender;
use HeimrichHannot\SimpleMemberChatBundle\Contact\ContactResolver;
use HeimrichHannot\SimpleMemberChatBundle\Domain\Conversation;
use HeimrichHannot\SimpleMemberChatBundle\Domain\Message;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ConversationGatewayInterface;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\MessageGatewayInterface;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ParticipantGatewayInterface;
use HeimrichHannot\SimpleMemberChatBundle\Service\ConversationUrlGenerator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsMessageHandler]
final readonly class SendChatPushHandler
{
    public function __construct(
        private MessageGatewayInterface $messages,
        private ConversationGatewayInterface $conversations,
        private ParticipantGatewayInterface $participants,
        private ContactResolver $contacts,
        private ConversationUrlGenerator $urls,
        private ContaoFramework $framework,
        private PushNotificationSender $sender,
        private PushOptions $options,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SendChatPushMessage $queued): void
    {
        if (!$this->options->enabled || $this->options->configuration === 0) {
            return;
        }

        try {
            $message = $this->messages->find($queued->messageId);
            if (!$message instanceof Message || !($conversation = $this->conversations->find($message->conversationId)) instanceof Conversation) {
                return;
            }

            $this->framework->initialize();
            $config = $this->framework->getAdapter(PwaConfigurationsModel::class)->__call('findByPk', [$this->options->configuration]);
            if (!$config instanceof PwaConfigurationsModel || !(bool) $config->supportPush) {
                return;
            }

            $title = $this->contacts->resolve($message->authorId)->displayName;
            $body = $this->options->bodyLength === 0 ? null : mb_substr($message->body, 0, $this->options->bodyLength, 'UTF-8');
            foreach (array_unique($queued->recipientIds) as $recipientId) {
                try {
                    $state = $this->participants->state($conversation->id, $recipientId);
                    if ($recipientId <= 0 || $recipientId === $message->authorId || $state === null || $state['muted']
                        || ($this->options->activeRecipientGrace > 0 && $state['lastReadAt'] > 0
                            && $state['lastReadAt'] >= time() - $this->options->activeRecipientGrace)) {
                        continue;
                    }

                    $subscribers = $this->framework->getAdapter(PwaPushSubscriberModel::class)->__call('findBy', [
                        ['tl_pwa_pushsubscriber.pid=?', 'tl_pwa_pushsubscriber.member=?'],
                        [$this->options->configuration, $recipientId],
                    ]);
                    if (!$subscribers instanceof Collection) {
                        continue;
                    }

                    $targets = [];
                    foreach ($subscribers as $subscriber) {
                        if ($subscriber instanceof PwaPushSubscriberModel && (int) $subscriber->pid === $this->options->configuration && (int) $subscriber->member === $recipientId) {
                            $targets[] = $subscriber;
                        }
                    }

                    // PWA treats an empty list as a broadcast to every subscriber!
                    if ($targets === []) {
                        continue;
                    }

                    $url = $this->urls->forConversation($conversation, $recipientId, UrlGeneratorInterface::ABSOLUTE_URL);
                    if (!$this->sender->sendWithLog(new ChatNotification($title, $body, $url), $config, $this->logger, $targets)) {
                        $this->logger->warning('Chat push sender could not send notification.', [
                            'messageId' => $queued->messageId,
                            'recipientId' => $recipientId,
                        ]);
                    }
                } catch (\Throwable $exception) {
                    $this->logger->error('Chat push failed for recipient.', [
                        'messageId' => $queued->messageId,
                        'recipientId' => $recipientId,
                        'exception' => $exception,
                    ]);
                }
            }
        } catch (\Throwable $throwable) {
            $this->logger->error('Chat push failed.', [
                'messageId' => $queued->messageId,
                'exception' => $throwable,
            ]);
        }
    }
}
