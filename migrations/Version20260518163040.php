<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260518163040 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create chat_chunk table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE chat_chunk (id INT AUTO_INCREMENT NOT NULL, source_key VARCHAR(120) NOT NULL, content LONGTEXT NOT NULL, content_hash VARCHAR(64) NOT NULL, embedding JSON NOT NULL, metadata JSON DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_947B9CB5DB64AEE8 (source_key), INDEX idx_content_hash (content_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE chat_chunk');
    }
}
