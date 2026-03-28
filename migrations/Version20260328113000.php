<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260328113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add status field to reservation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE \"reservation\" ADD status VARCHAR(32) DEFAULT 'confirmed' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "reservation" DROP status');
    }
}
