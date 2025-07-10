<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250714184607 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE problem ADD parent_testcase_group_id INT UNSIGNED DEFAULT NULL COMMENT \'Testcase group ID\'');
        $this->addSql('ALTER TABLE problem ADD CONSTRAINT FK_D7E7CCC8A090DCC7 FOREIGN KEY (parent_testcase_group_id) REFERENCES testcase_group (testcase_group_id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_D7E7CCC8A090DCC7 ON problem (parent_testcase_group_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE problem DROP FOREIGN KEY FK_D7E7CCC8A090DCC7');
        $this->addSql('DROP INDEX IDX_D7E7CCC8A090DCC7 ON problem');
        $this->addSql('ALTER TABLE problem DROP parent_testcase_group_id');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
