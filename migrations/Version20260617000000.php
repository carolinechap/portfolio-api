<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drop the now-unused `user` table. The admin/JWT login model was removed in
 * favour of a public contact endpoint protected by single-use hCaptcha, rate
 * limits, honeypot and origin checks; there is no longer any authenticated
 * user in the application.
 */
final class Version20260617000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the unused user table (admin/JWT auth removed).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS user');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE user (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, UNIQUE INDEX UNIQ_IDENTIFIER_EMAIL (email), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
    }
}
