<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260711100001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add categories and link corpora to them.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE categories (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                uuid VARCHAR(36) NOT NULL UNIQUE,
                name VARCHAR(120) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE corpora
                ADD COLUMN category_uuid VARCHAR(36) NULL,
                ADD KEY idx_corpus_category (category_uuid),
                ADD CONSTRAINT fk_corpus_category FOREIGN KEY (category_uuid) REFERENCES categories (uuid) ON DELETE SET NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE corpora
                DROP FOREIGN KEY fk_corpus_category,
                DROP KEY idx_corpus_category,
                DROP COLUMN category_uuid
        SQL);

        $this->addSql('DROP TABLE IF EXISTS categories');
    }
}
