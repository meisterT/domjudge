<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250710194647 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add sample field to judgetask';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE judgetask ADD is_sample TINYINT(1) DEFAULT NULL COMMENT \'Whether this is a sample testcase\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE judgetask DROP is_sample');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
