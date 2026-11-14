<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261111210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add judgetask.compile_success to track per-task compilation results';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE judgetask ADD compile_success TINYINT(1) DEFAULT NULL COMMENT \'Whether compilation succeeded for this judge task\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE judgetask DROP compile_success');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
