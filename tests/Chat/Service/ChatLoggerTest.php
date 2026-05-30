<?php

declare(strict_types=1);

namespace App\Tests\Chat\Service;

use App\Chat\Entity\ChatLog;
use App\Chat\Entity\ChatOutcome;
use App\Chat\Service\ChatLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ChatLoggerTest extends KernelTestCase
{
    public function testPersistsLogEntry(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        $tool = new \Doctrine\ORM\Tools\SchemaTool($em);
        $tool->dropSchema($em->getMetadataFactory()->getAllMetadata());
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());

        $logger = new ChatLogger($em);

        $logger->log('Q?', 'A.', 0.81, ['k1', 'k2'], ChatOutcome::Answered);

        $repo = $em->getRepository(ChatLog::class);
        $rows = $repo->findAll();
        self::assertCount(1, $rows);
        self::assertSame(ChatOutcome::Answered, $rows[0]->getOutcome());
    }

    /**
     * An over-length question (e.g. one that just failed the Length(max: 500)
     * validation) must be truncated before storage so it never overflows the
     * VARCHAR(500) column — otherwise the SQL "Data too long" error masks the
     * intended 400 with a 500.
     */
    public function testQuestionIsTruncatedToColumnLength(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        $tool = new \Doctrine\ORM\Tools\SchemaTool($em);
        $tool->dropSchema($em->getMetadataFactory()->getAllMetadata());
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());

        $logger = new ChatLogger($em);

        $logger->log(str_repeat('A', 501), '', 0.0, null, ChatOutcome::ValidationError);

        $rows = $em->getRepository(ChatLog::class)->findAll();
        self::assertCount(1, $rows);
        self::assertSame(500, mb_strlen($rows[0]->getQuestion()));
    }
}
