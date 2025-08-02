<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250802163928 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'add score to rankcache';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE rankcache ADD score_public NUMERIC(32, 9) DEFAULT \'0.000000000\' NOT NULL COMMENT \'Optional score for this run, e.g. for partial scoring\', ADD score_restricted NUMERIC(32, 9) DEFAULT \'0.000000000\' NOT NULL COMMENT \'Optional score for this run, e.g. for partial scoring (for restricted audience)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE rankcache DROP score_public, DROP score_restricted');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
