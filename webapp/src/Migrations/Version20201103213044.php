<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20201103213044 extends AbstractMigration
{
    public function getDescription() : string
    {
        return 'Add immutable executable tables';
    }

    public function up(Schema $schema) : void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('CREATE TABLE executable_file (execfileid INT UNSIGNED AUTO_INCREMENT NOT NULL COMMENT \'Executable file ID\', immutable_execid INT UNSIGNED DEFAULT NULL COMMENT \'ID\', filename VARCHAR(255) NOT NULL COMMENT \'Filename as uploaded\', rank INT UNSIGNED NOT NULL COMMENT \'Order of the executable files, zero-indexed\', file_content LONGBLOB NOT NULL COMMENT \'Full file content(DC2Type:blobtext)\', is_executable TINYINT(1) DEFAULT \'0\' NOT NULL COMMENT \'Whether this file gets an executable bit.\', INDEX immutable_execid (immutable_execid), UNIQUE INDEX rank (immutable_execid, rank), UNIQUE INDEX filename (immutable_execid, filename(190)), PRIMARY KEY(execfileid)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'Files associated to an executable\' ');
        $this->addSql('CREATE TABLE immutable_executable (immutable_execid INT UNSIGNED AUTO_INCREMENT NOT NULL COMMENT \'ID\', userid INT UNSIGNED DEFAULT NULL COMMENT \'User ID\', INDEX IDX_676B601AF132696E (userid), PRIMARY KEY(immutable_execid)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'Immutable wrapper for a collection of files for executable bundles.\' ');
        $this->addSql('ALTER TABLE executable_file ADD CONSTRAINT FK_99FA6255979A9F09 FOREIGN KEY (immutable_execid) REFERENCES immutable_executable (immutable_execid) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE immutable_executable ADD CONSTRAINT FK_676B601AF132696E FOREIGN KEY (userid) REFERENCES user (userid) ON DELETE SET NULL');
    }

    public function down(Schema $schema) : void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE executable DROP FOREIGN KEY FK_D68EDA01979A9F09');
        $this->addSql('ALTER TABLE executable_file DROP FOREIGN KEY FK_99FA6255979A9F09');
        $this->addSql('DROP TABLE executable_file');
        $this->addSql('DROP TABLE immutable_executable');
    }
}
