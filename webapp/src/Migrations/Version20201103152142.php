<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20201103152142 extends AbstractMigration
{
    public function getDescription() : string
    {
        return '';
    }

    public function up(Schema $schema) : void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('CREATE TABLE judgetask (judgetaskid INT UNSIGNED AUTO_INCREMENT NOT NULL COMMENT \'Judgetask ID\', type ENUM(\'judging_run\', \'generic_task\', \'config_check\', \'debug_info\') DEFAULT \'judging_run\' NOT NULL COMMENT \'Type of the judge task.(DC2Type:judge_task_type)\', rank INT NOT NULL COMMENT \'Priority; negative means higher priority\', submitid INT UNSIGNED DEFAULT NULL COMMENT \'Submission ID being judged\', judgingrunid INT UNSIGNED DEFAULT NULL COMMENT \'Corresponding judging run ID\', compile_script_id INT UNSIGNED DEFAULT NULL COMMENT \'Compile script ID\', run_script_id INT UNSIGNED DEFAULT NULL COMMENT \'Run script ID\', compare_script_id INT UNSIGNED DEFAULT NULL COMMENT \'Compare script ID\', input_id INT UNSIGNED DEFAULT NULL COMMENT \'Input ID\', output_id INT UNSIGNED DEFAULT NULL COMMENT \'Expected output ID\', compile_config LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin` COMMENT \'The compile config as JSON-blob.\', run_config LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin` COMMENT \'The run config as JSON-blob.\', compare_config LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin` COMMENT \'The compare config as JSON-blob.\', PRIMARY KEY(judgetaskid)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'Individual judge tasks.\' ');
    }

    public function down(Schema $schema) : void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('DROP TABLE judgetask');
    }
}
