<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20201219154651 extends AbstractMigration
{
    public function getDescription() : string
    {
        return '';
    }

    public function up(Schema $schema) : void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('CREATE TABLE judgetask (judgetaskid INT UNSIGNED AUTO_INCREMENT NOT NULL COMMENT \'Judgetask ID\', hostname VARCHAR(255) DEFAULT NULL COMMENT \'hostname of the judge which executes the task\', type ENUM(\'judging_run\', \'generic_task\', \'config_check\', \'debug_info\') DEFAULT \'judging_run\' NOT NULL COMMENT \'Type of the judge task.(DC2Type:judge_task_type)\', priority INT NOT NULL COMMENT \'Priority; negative means higher priority\', jobid INT UNSIGNED NOT NULL COMMENT \'All judgetasks with the same jobid belong together.\', submitid INT UNSIGNED DEFAULT NULL COMMENT \'Submission ID being judged\', compile_script_id INT UNSIGNED DEFAULT NULL COMMENT \'Compile script ID\', run_script_id INT UNSIGNED DEFAULT NULL COMMENT \'Run script ID\', compare_script_id INT UNSIGNED DEFAULT NULL COMMENT \'Compare script ID\', testcase_id INT UNSIGNED DEFAULT NULL COMMENT \'Testcase ID\', compile_config LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin` COMMENT \'The compile config as JSON-blob.\', run_config LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin` COMMENT \'The run config as JSON-blob.\', compare_config LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin` COMMENT \'The compare config as JSON-blob.\', PRIMARY KEY(judgetaskid)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'Individual judge tasks.\' ');
        $this->addSql('CREATE TABLE executable_file (execfileid INT UNSIGNED AUTO_INCREMENT NOT NULL COMMENT \'Executable file ID\', immutable_execid INT UNSIGNED DEFAULT NULL COMMENT \'ID\', filename VARCHAR(255) NOT NULL COMMENT \'Filename as uploaded\', rank INT UNSIGNED NOT NULL COMMENT \'Order of the executable files, zero-indexed\', file_content LONGBLOB NOT NULL COMMENT \'Full file content(DC2Type:blobtext)\', is_executable TINYINT(1) DEFAULT \'0\' NOT NULL COMMENT \'Whether this file gets an executable bit.\', INDEX immutable_execid (immutable_execid), UNIQUE INDEX rank (immutable_execid, rank), UNIQUE INDEX filename (immutable_execid, filename(190)), PRIMARY KEY(execfileid)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'Files associated to an executable\' ');
        $this->addSql('CREATE TABLE immutable_executable (immutable_execid INT UNSIGNED AUTO_INCREMENT NOT NULL COMMENT \'ID\', userid INT UNSIGNED DEFAULT NULL COMMENT \'User ID\', INDEX IDX_676B601AF132696E (userid), PRIMARY KEY(immutable_execid)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'Immutable wrapper for a collection of files for executable bundles.\' ');
        $this->addSql('ALTER TABLE executable_file ADD CONSTRAINT FK_99FA6255979A9F09 FOREIGN KEY (immutable_execid) REFERENCES immutable_executable (immutable_execid) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE immutable_executable ADD CONSTRAINT FK_676B601AF132696E FOREIGN KEY (userid) REFERENCES user (userid) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE judging CHANGE starttime starttime NUMERIC(32, 9) UNSIGNED DEFAULT NULL COMMENT \'Time judging started\'');
        $this->addSql('ALTER TABLE judging_run ADD judgetaskid INT UNSIGNED NOT NULL COMMENT \'JudgeTask ID\', CHANGE endtime endtime NUMERIC(32, 9) UNSIGNED DEFAULT NULL COMMENT \'Time run judging ended\'');
        $this->addSql('ALTER TABLE judging_run ADD CONSTRAINT FK_29A6E6E13CBA64F2 FOREIGN KEY (judgetaskid) REFERENCES judgetask (judgetaskid)');
        $this->addSql('CREATE INDEX IDX_29A6E6E13CBA64F2 ON judging_run (judgetaskid)');
        $this->addSql('ALTER TABLE testcase_content ADD tc_contentid INT UNSIGNED AUTO_INCREMENT NOT NULL COMMENT \'Testcase content ID\', CHANGE testcaseid testcaseid INT UNSIGNED DEFAULT NULL COMMENT \'Testcase ID\', DROP FOREIGN KEY testcase_content_ibfk_1, DROP PRIMARY KEY, ADD PRIMARY KEY (tc_contentid)');
        $this->addSql('CREATE INDEX IDX_50A5CCE2D360BB2B ON testcase_content (testcaseid)');
        $this->addSql('ALTER TABLE executable ADD immutable_execid INT UNSIGNED DEFAULT NULL COMMENT \'ID\', DROP md5sum');
        $this->addSql('ALTER TABLE executable ADD CONSTRAINT FK_D68EDA01979A9F09 FOREIGN KEY (immutable_execid) REFERENCES immutable_executable (immutable_execid)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D68EDA01979A9F09 ON executable (immutable_execid)');
    }

    public function down(Schema $schema) : void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE judging_run DROP FOREIGN KEY FK_29A6E6E13CBA64F2');
        $this->addSql('ALTER TABLE executable DROP FOREIGN KEY FK_D68EDA01979A9F09');
        $this->addSql('ALTER TABLE executable_file DROP FOREIGN KEY FK_99FA6255979A9F09');
        $this->addSql('DROP TABLE judgetask');
        $this->addSql('DROP TABLE executable_file');
        $this->addSql('DROP TABLE immutable_executable');
        $this->addSql('DROP INDEX UNIQ_D68EDA01979A9F09 ON executable');
        $this->addSql('ALTER TABLE executable ADD md5sum CHAR(32) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci` COMMENT \'Md5sum of zip file\', DROP immutable_execid');
        $this->addSql('ALTER TABLE judging CHANGE starttime starttime NUMERIC(32, 9) UNSIGNED NOT NULL COMMENT \'Time judging started\'');
        $this->addSql('DROP INDEX IDX_29A6E6E13CBA64F2 ON judging_run');
        $this->addSql('ALTER TABLE judging_run DROP judgetaskid, CHANGE endtime endtime NUMERIC(32, 9) UNSIGNED NOT NULL COMMENT \'Time run judging ended\'');
        $this->addSql('ALTER TABLE testcase_content MODIFY tc_contentid INT UNSIGNED NOT NULL COMMENT \'Testcase content ID\'');
        $this->addSql('DROP INDEX IDX_50A5CCE2D360BB2B ON testcase_content');
        $this->addSql('ALTER TABLE testcase_content DROP PRIMARY KEY');
        $this->addSql('ALTER TABLE testcase_content DROP tc_contentid, CHANGE testcaseid testcaseid INT UNSIGNED NOT NULL COMMENT \'Testcase ID\'');
        $this->addSql('ALTER TABLE testcase_content ADD PRIMARY KEY (testcaseid)');
    }
}
