<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250714181858 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'add tree to tcgroup';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE testcase_group ADD parent_id INT UNSIGNED DEFAULT NULL COMMENT \'Testcase group ID\'');
        $this->addSql('ALTER TABLE testcase_group ADD CONSTRAINT FK_F02888FE727ACA70 FOREIGN KEY (parent_id) REFERENCES testcase_group (testcase_group_id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_F02888FE727ACA70 ON testcase_group (parent_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE testcase_group DROP FOREIGN KEY FK_F02888FE727ACA70');
        $this->addSql('DROP INDEX IDX_F02888FE727ACA70 ON testcase_group');
        $this->addSql('ALTER TABLE testcase_group DROP parent_id');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
