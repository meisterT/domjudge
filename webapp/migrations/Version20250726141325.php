<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250726141325 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'add output_validator_flags';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE testcase_group ADD output_validator_flags VARCHAR(255) DEFAULT NULL COMMENT \'Flags for output validation\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE testcase_group DROP output_validator_flags');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
