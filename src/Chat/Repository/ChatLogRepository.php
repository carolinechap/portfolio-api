<?php

declare(strict_types=1);

namespace App\Chat\Repository;

use App\Chat\Entity\ChatLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChatLog>
 */
class ChatLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatLog::class);
    }

    public function deleteOlderThan(\DateTimeImmutable $threshold): int
    {
        $affected = $this->createQueryBuilder('l')
            ->delete()
            ->where('l.createdAt < :threshold')
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->execute();

        return is_int($affected) ? $affected : 0;
    }
}