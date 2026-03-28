<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260328120500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add claim window fields to reservation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "reservation" ADD claim_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE "reservation" ADD claimed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "reservation" DROP claim_expires_at');
        $this->addSql('ALTER TABLE "reservation" DROP claimed_at');
    }
}
