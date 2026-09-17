<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Service;

use Doctrine\DBAL\Connection;

final readonly class ChatTransaction
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @template T
     *
     * @param \Closure(): T $operation
     *
     * @return T
     */
    public function run(\Closure $operation): mixed
    {
        // An inner commit cannot guarantee after-commit event delivery.
        if ($this->connection->isTransactionActive()) {
            throw new \LogicException('Chat services must own the top-level transaction.');
        }

        return $this->connection->transactional($operation);
    }
}
