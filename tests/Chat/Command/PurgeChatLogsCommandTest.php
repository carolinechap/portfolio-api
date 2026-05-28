<?php

declare(strict_types=1);

namespace App\Tests\Chat\Command;

use App\Chat\Entity\ChatLog;
use App\Chat\Entity\ChatOutcome;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class PurgeChatLogsCommandTest extends KernelTestCase
{
    public function testDeletesOnlyOldLogs(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $em = $c->get('doctrine.orm.entity_manager');
        $tool = new \Doctrine\ORM\Tools\SchemaTool($em);
        $tool->dropSchema($em->getMetadataFactory()->getAllMetadata());
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());

        $old = new ChatLog('q1', 'a1', 0.9, [], ChatOutcome::Answered);
        $young = new ChatLog('q2', 'a2', 0.9, [], ChatOutcome::Answered);
        $em->persist($old);
        $em->persist($young);
        $em->flush();

        $conn = $em->getConnection();
        $conn->executeStatement(
            'UPDATE chat_log SET created_at = ? WHERE id = ?',
            [(new \DateTimeImmutable('-90 days'))->format('Y-m-d H:i:s'), $old->getId()],
        );

        $app = new Application(self::$kernel);
        $tester = new CommandTester($app->find('app:chat:purge-logs'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        self::assertStringContainsString('Deleted: 1', $tester->getDisplay());
    }
}
