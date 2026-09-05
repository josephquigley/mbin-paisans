<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260905224855 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE magazine_follow_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE magazine_follow (id INT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, magazine_id INT NOT NULL, following_user_id INT DEFAULT NULL, following_magazine_id INT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_89F80B9F3EB84A1D ON magazine_follow (magazine_id)');
        $this->addSql('CREATE INDEX IDX_89F80B9F1896F387 ON magazine_follow (following_user_id)');
        $this->addSql('CREATE INDEX IDX_89F80B9F7B5E5641 ON magazine_follow (following_magazine_id)');
        $this->addSql('CREATE UNIQUE INDEX magazine_follow_user_idx ON magazine_follow (magazine_id, following_user_id)');
        $this->addSql('CREATE UNIQUE INDEX magazine_follow_magazine_idx ON magazine_follow (magazine_id, following_magazine_id)');
        $this->addSql('ALTER TABLE magazine_follow ADD CONSTRAINT FK_89F80B9F3EB84A1D FOREIGN KEY (magazine_id) REFERENCES magazine (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE magazine_follow ADD CONSTRAINT FK_89F80B9F1896F387 FOREIGN KEY (following_user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE magazine_follow ADD CONSTRAINT FK_89F80B9F7B5E5641 FOREIGN KEY (following_magazine_id) REFERENCES magazine (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE magazine_follow DROP CONSTRAINT FK_89F80B9F3EB84A1D');
        $this->addSql('ALTER TABLE magazine_follow DROP CONSTRAINT FK_89F80B9F1896F387');
        $this->addSql('ALTER TABLE magazine_follow DROP CONSTRAINT FK_89F80B9F7B5E5641');
        $this->addSql('DROP TABLE magazine_follow');
        $this->addSql('DROP SEQUENCE magazine_follow_id_seq CASCADE');
    }
}
