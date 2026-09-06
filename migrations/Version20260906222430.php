<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260906222430 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'add the activity kind a magazine follow carries';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE magazine_follow ADD kind VARCHAR(255) DEFAULT 'both' NOT NULL");
        // Backfill by the followed actor's type rather than leaving every existing row
        // on the column default, so a follow made before this column existed behaves the
        // way MagazineFollowKind::defaultFor() would have set it.
        $this->addSql("UPDATE magazine_follow SET kind = 'announce' WHERE following_magazine_id IS NOT NULL");
        $this->addSql("UPDATE magazine_follow mf SET kind = 'create' FROM \"user\" u WHERE mf.following_user_id = u.id AND u.type NOT IN ('Application', 'Service')");
        $this->addSql("UPDATE magazine_follow mf SET kind = 'both' FROM \"user\" u WHERE mf.following_user_id = u.id AND u.type IN ('Application', 'Service')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE magazine_follow DROP kind');
    }
}
