<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add company and opportunity columns to contact.
 */
final class Version20260530174314 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add company and opportunity columns to contact';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact ADD company VARCHAR(100) DEFAULT NULL, ADD opportunity VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact DROP company, DROP opportunity');
    }
}
