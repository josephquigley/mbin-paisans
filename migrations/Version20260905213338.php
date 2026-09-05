<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260905213338 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.push_prompt_declined, so a dismissed push notification prompt stays dismissed';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD push_prompt_declined BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP push_prompt_declined');
    }
}
