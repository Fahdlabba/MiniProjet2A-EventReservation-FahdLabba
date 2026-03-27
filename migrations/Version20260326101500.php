<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260326101500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add subscription window fields on event';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event ADD subscription_open_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE event ADD subscription_close_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event DROP subscription_open_at');
        $this->addSql('ALTER TABLE event DROP subscription_close_at');
    }
}
