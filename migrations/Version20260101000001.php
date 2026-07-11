<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema: corpora, vocabulary, language models, weight tables, training jobs, predictions, messenger transport.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE corpora (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                uuid VARCHAR(36) NOT NULL UNIQUE,
                name VARCHAR(120) NOT NULL,
                raw_text LONGTEXT NOT NULL,
                created_at DATETIME(6) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE vocabulary (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                corpus_id BIGINT NOT NULL,
                token_id INT NOT NULL,
                `character` VARBINARY(8) NOT NULL,
                UNIQUE KEY uq_vocab_corpus_token (corpus_id, token_id),
                KEY idx_vocab_corpus (corpus_id),
                CONSTRAINT fk_vocab_corpus FOREIGN KEY (corpus_id) REFERENCES corpora (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE language_models (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                uuid VARCHAR(36) NOT NULL UNIQUE,
                name VARCHAR(120) NOT NULL,
                d_model SMALLINT UNSIGNED NOT NULL,
                num_heads SMALLINT UNSIGNED NOT NULL,
                num_layers SMALLINT UNSIGNED NOT NULL,
                d_ff SMALLINT UNSIGNED NOT NULL,
                max_seq_len SMALLINT UNSIGNED NOT NULL,
                vocab_size SMALLINT UNSIGNED NOT NULL,
                status VARCHAR(16) NOT NULL,
                created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE model_token_embeddings (
                model_id BIGINT NOT NULL,
                token_id INT NOT NULL,
                dim SMALLINT UNSIGNED NOT NULL,
                value FLOAT NOT NULL,
                PRIMARY KEY (model_id, token_id, dim),
                CONSTRAINT fk_mte_model FOREIGN KEY (model_id) REFERENCES language_models (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE model_positional_embeddings (
                model_id BIGINT NOT NULL,
                position SMALLINT UNSIGNED NOT NULL,
                dim SMALLINT UNSIGNED NOT NULL,
                value FLOAT NOT NULL,
                PRIMARY KEY (model_id, position, dim),
                CONSTRAINT fk_mpe_model FOREIGN KEY (model_id) REFERENCES language_models (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE model_attention_weights (
                model_id BIGINT NOT NULL,
                layer SMALLINT UNSIGNED NOT NULL,
                matrix VARCHAR(2) NOT NULL,
                row SMALLINT UNSIGNED NOT NULL,
                col SMALLINT UNSIGNED NOT NULL,
                value FLOAT NOT NULL,
                PRIMARY KEY (model_id, layer, matrix, row, col),
                CONSTRAINT fk_maw_model FOREIGN KEY (model_id) REFERENCES language_models (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE model_ffn_weights (
                model_id BIGINT NOT NULL,
                layer SMALLINT UNSIGNED NOT NULL,
                matrix VARCHAR(2) NOT NULL,
                row SMALLINT UNSIGNED NOT NULL,
                col SMALLINT UNSIGNED NOT NULL,
                value FLOAT NOT NULL,
                PRIMARY KEY (model_id, layer, matrix, row, col),
                CONSTRAINT fk_mfw_model FOREIGN KEY (model_id) REFERENCES language_models (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE model_final_projection (
                model_id BIGINT NOT NULL,
                row SMALLINT UNSIGNED NOT NULL,
                col SMALLINT UNSIGNED NOT NULL,
                value FLOAT NOT NULL,
                PRIMARY KEY (model_id, row, col),
                CONSTRAINT fk_mfp_model FOREIGN KEY (model_id) REFERENCES language_models (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE model_layer_norms (
                model_id BIGINT NOT NULL,
                layer SMALLINT UNSIGNED NOT NULL,
                which_kind VARCHAR(20) NOT NULL,
                dim SMALLINT UNSIGNED NOT NULL,
                value FLOAT NOT NULL,
                PRIMARY KEY (model_id, layer, which_kind, dim),
                CONSTRAINT fk_mln_model FOREIGN KEY (model_id) REFERENCES language_models (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE training_jobs (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                uuid VARCHAR(36) NOT NULL UNIQUE,
                model_id BIGINT NOT NULL,
                status VARCHAR(16) NOT NULL,
                total_epochs INT UNSIGNED NOT NULL,
                learning_rate FLOAT NOT NULL,
                seq_len INT UNSIGNED NOT NULL,
                batch_size INT UNSIGNED NOT NULL DEFAULT 1,
                epoch INT UNSIGNED NOT NULL DEFAULT 0,
                loss FLOAT NULL,
                started_at DATETIME(6) NULL,
                finished_at DATETIME(6) NULL,
                error_message TEXT NULL,
                created_at DATETIME(6) NOT NULL,
                KEY idx_tj_model (model_id),
                CONSTRAINT fk_tj_model FOREIGN KEY (model_id) REFERENCES language_models (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE training_loss_history (
                training_job_id BIGINT NOT NULL,
                epoch INT UNSIGNED NOT NULL,
                loss FLOAT NOT NULL,
                PRIMARY KEY (training_job_id, epoch),
                CONSTRAINT fk_tlh_job FOREIGN KEY (training_job_id) REFERENCES training_jobs (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE predictions (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                uuid VARCHAR(36) NOT NULL UNIQUE,
                model_id BIGINT NOT NULL,
                prompt VARCHAR(500) NOT NULL,
                generated_text VARCHAR(500) NULL,
                sampling VARCHAR(8) NOT NULL,
                top_k SMALLINT UNSIGNED NULL,
                max_new_tokens SMALLINT UNSIGNED NOT NULL,
                status VARCHAR(16) NOT NULL,
                error_message TEXT NULL,
                created_at DATETIME(6) NOT NULL,
                finished_at DATETIME(6) NULL,
                KEY idx_pred_model (model_id),
                CONSTRAINT fk_pred_model FOREIGN KEY (model_id) REFERENCES language_models (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE adam_state (
                model_id BIGINT NOT NULL,
                param_path VARCHAR(100) NOT NULL,
                row_idx INT NOT NULL,
                col_idx INT NOT NULL,
                m FLOAT NOT NULL,
                v FLOAT NOT NULL,
                PRIMARY KEY (model_id, param_path, row_idx, col_idx),
                CONSTRAINT fk_adam_model FOREIGN KEY (model_id) REFERENCES language_models (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE messenger_messages (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                body LONGTEXT NOT NULL,
                headers LONGTEXT NOT NULL,
                queue_name VARCHAR(190) NOT NULL,
                created_at DATETIME(6) NOT NULL,
                available_at DATETIME(6) NOT NULL,
                delivered_at DATETIME(6) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE messenger_failed_messages (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                transport VARCHAR(190) NOT NULL,
                body LONGTEXT NOT NULL,
                headers LONGTEXT NOT NULL,
                queue_name VARCHAR(190) NOT NULL,
                created_at DATETIME(6) NOT NULL,
                available_at DATETIME(6) NOT NULL,
                delivered_at DATETIME(6) NULL,
                failed_at DATETIME(6) NULL,
                error LONGTEXT NULL,
                error_count INT NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS messenger_failed_messages');
        $this->addSql('DROP TABLE IF EXISTS messenger_messages');
        $this->addSql('DROP TABLE IF EXISTS adam_state');
        $this->addSql('DROP TABLE IF EXISTS predictions');
        $this->addSql('DROP TABLE IF EXISTS training_loss_history');
        $this->addSql('DROP TABLE IF EXISTS training_jobs');
        $this->addSql('DROP TABLE IF EXISTS model_layer_norms');
        $this->addSql('DROP TABLE IF EXISTS model_final_projection');
        $this->addSql('DROP TABLE IF EXISTS model_ffn_weights');
        $this->addSql('DROP TABLE IF EXISTS model_attention_weights');
        $this->addSql('DROP TABLE IF EXISTS model_positional_embeddings');
        $this->addSql('DROP TABLE IF EXISTS model_token_embeddings');
        $this->addSql('DROP TABLE IF EXISTS language_models');
        $this->addSql('DROP TABLE IF EXISTS vocabulary');
        $this->addSql('DROP TABLE IF EXISTS corpora');
    }
}
