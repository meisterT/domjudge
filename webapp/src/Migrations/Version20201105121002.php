<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20201105121002 extends AbstractMigration
{
    public function getDescription() : string
    {
        return 'Add link from judging_run to judgetask';
    }

    public function up(Schema $schema) : void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE judging_run ADD judgetaskid INT UNSIGNED NOT NULL COMMENT \'JudgeTask ID\'');
        $this->addSql('ALTER TABLE judging_run ADD CONSTRAINT FK_29A6E6E13CBA64F2 FOREIGN KEY (judgetaskid) REFERENCES judgetask (judgetaskid)');
        $this->addSql('CREATE INDEX IDX_29A6E6E13CBA64F2 ON judging_run (judgetaskid)');

    }

    public function down(Schema $schema) : void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE judging_run DROP FOREIGN KEY FK_29A6E6E13CBA64F2');
        $this->addSql('DROP INDEX IDX_29A6E6E13CBA64F2 ON judging_run');
        $this->addSql('ALTER TABLE judging_run DROP judgetaskid');
    }
}
