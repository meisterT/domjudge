<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20201105091711 extends AbstractMigration
{
    public function getDescription() : string
    {
        return 'Store testcase ID instead of separate input/output IDs. Some more missing(?)/harmless changes to reduce the size of the diff';
    }

    public function up(Schema $schema) : void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE executable DROP md5sum, CHANGE immutable_execid immutable_execid INT UNSIGNED DEFAULT NULL COMMENT \'ID\'');
        $this->addSql('ALTER TABLE executable ADD CONSTRAINT FK_D68EDA01979A9F09 FOREIGN KEY (immutable_execid) REFERENCES immutable_executable (immutable_execid)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D68EDA01979A9F09 ON executable (immutable_execid)');

        $this->addSql('DROP INDEX filename ON executable_file');
        $this->addSql('ALTER TABLE executable_file CHANGE immutable_execid immutable_execid INT UNSIGNED DEFAULT NULL COMMENT \'ID\'');
        $this->addSql('CREATE UNIQUE INDEX filename ON executable_file (immutable_execid, filename(190))');

        $this->addSql('ALTER TABLE immutable_executable CHANGE userid userid INT UNSIGNED DEFAULT NULL COMMENT \'User ID\'');

        $this->addSql('ALTER TABLE judgetask ADD testcase_id INT UNSIGNED DEFAULT NULL COMMENT \'Testcase ID\', DROP input_id, DROP output_id');

    }

    public function down(Schema $schema) : void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE executable DROP FOREIGN KEY FK_D68EDA01979A9F09');
        $this->addSql('DROP INDEX UNIQ_D68EDA01979A9F09 ON executable');
        $this->addSql('ALTER TABLE executable ADD md5sum CHAR(32) CHARACTER SET utf8mb4 DEFAULT \'NULL\' COLLATE `utf8mb4_unicode_ci` COMMENT \'Md5sum of zip file\', CHANGE immutable_execid immutable_execid INT UNSIGNED DEFAULT NULL COMMENT \'ID\'');

        $this->addSql('DROP INDEX filename ON executable_file');
        $this->addSql('ALTER TABLE executable_file CHANGE immutable_execid immutable_execid INT UNSIGNED DEFAULT NULL COMMENT \'ID\'');
        $this->addSql('CREATE UNIQUE INDEX filename ON executable_file (immutable_execid, filename(190))');

        $this->addSql('ALTER TABLE immutable_executable CHANGE userid userid INT UNSIGNED DEFAULT NULL COMMENT \'User ID\'');

        $this->addSql('ALTER TABLE judgetask ADD input_id INT UNSIGNED DEFAULT NULL COMMENT \'Input ID\', ADD output_id INT UNSIGNED DEFAULT NULL COMMENT \'Expected output ID\', DROP testcase_id');
    }
}
