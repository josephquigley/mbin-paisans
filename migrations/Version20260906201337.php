<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260906201337 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'add the read only flag to instance';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE instance ADD is_read_only BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE instance DROP is_read_only');
    }
}
