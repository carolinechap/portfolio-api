<?php

declare(strict_types=1);

namespace App\Tests\Chat\Command;

use App\Chat\Service\EmbeddingService;
use App\Chat\Service\EmbeddingTaskType;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class IngestChatKnowledgeCommandTest extends KernelTestCase
{
    public function testRunsCommandAgainstTempFile(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $em = $c->get('doctrine.orm.entity_manager');
        $tool = new \Doctrine\ORM\Tools\SchemaTool($em);
        $tool->dropSchema($em->getMetadataFactory()->getAllMetadata());
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());

        $c->set(EmbeddingService::class, new class extends EmbeddingService {
            public function __construct() {}
            public function embed(string $text, EmbeddingTaskType $taskType): array { return [1.0]; }
            public function embedBatch(array $texts, EmbeddingTaskType $taskType): array { return array_map(static fn () => [1.0], $texts); }
        });

        $tmp = tempnam(sys_get_temp_dir(), 'chatkb');
        self::assertNotFalse($tmp);
        file_put_contents($tmp, json_encode([
            'entries' => [['key' => 'k1', 'type' => 'skill', 'content' => 'A']],
        ], JSON_THROW_ON_ERROR));

        $app = new Application(self::$kernel);
        $cmd = $app->find('app:chat:ingest');
        $tester = new CommandTester($cmd);
        $tester->execute(['--file' => $tmp]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Added       : 1', $tester->getDisplay());

        unlink($tmp);
    }
}
