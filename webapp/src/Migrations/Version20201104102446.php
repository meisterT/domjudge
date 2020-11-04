<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20201104102446 extends AbstractMigration
{
    public function getDescription() : string
    {
        return 'Add new (auto-increment) primary key to testcase content.';
    }

    public function up(Schema $schema) : void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE testcase_content DROP FOREIGN KEY `testcase_content_ibfk_1`');
        $this->addSql('ALTER TABLE testcase_content ADD tc_contentid INT UNSIGNED AUTO_INCREMENT NOT NULL COMMENT \'Testcase content ID\', CHANGE testcaseid testcaseid INT UNSIGNED DEFAULT NULL COMMENT \'Testcase ID\', DROP PRIMARY KEY, ADD PRIMARY KEY (tc_contentid)');
        $this->addSql('CREATE INDEX IDX_50A5CCE2D360BB2B ON testcase_content (testcaseid)');
    }

    public function down(Schema $schema) : void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE testcase_content MODIFY tc_contentid INT UNSIGNED NOT NULL COMMENT \'Testcase content ID\'');
        $this->addSql('DROP INDEX IDX_50A5CCE2D360BB2B ON testcase_content');
        $this->addSql('ALTER TABLE testcase_content DROP PRIMARY KEY');
        $this->addSql('ALTER TABLE testcase_content DROP tc_contentid, CHANGE testcaseid testcaseid INT UNSIGNED NOT NULL COMMENT \'Testcase ID\'');
        $this->addSql('ALTER TABLE testcase_content ADD PRIMARY KEY (testcaseid)');
    }
}
