<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260518163736 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create chat_log table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE chat_log (id BIGINT AUTO_INCREMENT NOT NULL, question VARCHAR(500) NOT NULL, answer LONGTEXT NOT NULL, top_score DOUBLE PRECISION NOT NULL, chunks_used JSON DEFAULT NULL, outcome VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_created_at (created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE chat_log');
    }
}