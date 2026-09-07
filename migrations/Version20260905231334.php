<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260905231334 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add status to magazine_follow';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE magazine_follow ADD status VARCHAR(255) DEFAULT \'pending\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE magazine_follow DROP status');
    }
}
