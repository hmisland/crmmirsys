<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Customer Satisfaction foundation (docs/integrations-architecture.md).
 * Independent of any data source: a survey is triggered by a
 * source-neutral event (today "order.completed" from any external
 * shop), not by WooCommerce itself.
 *
 *  cx_surveys            — one survey: type csat | nps | ces, trigger,
 *                          send delay, link validity, preferred channel.
 *  cx_survey_questions   — kind score (the main CSAT/NPS/CES scale) |
 *                          rating | text; inactive questions are kept so
 *                          old answers still point at them.
 *  cx_invitations        — one per (survey, context): who is asked, why
 *                          (context_ref, an opaque internal reference such
 *                          as "woocommerce:{connection}:{order}"), when,
 *                          over which channel, and the delivery state.
 *                          UNIQUE(survey_id, context_ref) = never ask twice
 *                          for the same order.
 *  cx_responses          — one per invitation: main score, sentiment,
 *                          comment, follow-up state for dissatisfied
 *                          customers.
 *  cx_response_answers   — every answered question.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE cx_surveys (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                name VARCHAR(150) NOT NULL,
                type VARCHAR(10) NOT NULL DEFAULT 'csat',
                status VARCHAR(20) NOT NULL DEFAULT 'inactive',
                trigger_event VARCHAR(60) NULL,
                send_delay_minutes INT UNSIGNED NOT NULL DEFAULT 60,
                link_valid_days SMALLINT UNSIGNED NOT NULL DEFAULT 14,
                channel VARCHAR(20) NULL,
                intro_text VARCHAR(500) NULL,
                message_text VARCHAR(500) NULL,
                thank_you_text VARCHAR(500) NULL,
                created_by_user_id INT UNSIGNED NULL,
                updated_by_user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_cx_surveys_trigger (organization_id, status, trigger_event),
                CONSTRAINT fk_cx_surveys_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_cx_surveys_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_cx_surveys_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE cx_survey_questions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                survey_id INT UNSIGNED NOT NULL,
                position SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                kind VARCHAR(10) NOT NULL,
                label VARCHAR(500) NOT NULL,
                scale_min TINYINT NULL,
                scale_max TINYINT NULL,
                required TINYINT(1) NOT NULL DEFAULT 1,
                active TINYINT(1) NOT NULL DEFAULT 1,
                KEY idx_cx_questions_survey (survey_id, position),
                CONSTRAINT fk_cx_questions_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_cx_questions_survey FOREIGN KEY (survey_id) REFERENCES cx_surveys (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE cx_invitations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                survey_id INT UNSIGNED NOT NULL,
                source VARCHAR(40) NOT NULL,
                context_type VARCHAR(40) NOT NULL,
                context_ref VARCHAR(120) NOT NULL,
                context_label VARCHAR(60) NULL,
                contact_id INT UNSIGNED NULL,
                recipient_name VARCHAR(200) NULL,
                recipient_email VARCHAR(190) NULL,
                recipient_phone VARCHAR(40) NULL,
                locale VARCHAR(5) NULL,
                channel VARCHAR(20) NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'scheduled',
                send_after DATETIME NOT NULL,
                sent_at DATETIME NULL,
                public_link_id BIGINT UNSIGNED NULL,
                delivery_ref VARCHAR(100) NULL,
                attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                last_error VARCHAR(100) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_cx_invitation_context (survey_id, context_ref),
                KEY idx_cx_invitations_due (status, send_after),
                KEY idx_cx_invitations_org (organization_id, survey_id, status),
                KEY idx_cx_invitations_contact (contact_id),
                CONSTRAINT fk_cx_invitations_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_cx_invitations_survey FOREIGN KEY (survey_id) REFERENCES cx_surveys (id) ON DELETE CASCADE,
                CONSTRAINT fk_cx_invitations_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE cx_responses (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                survey_id INT UNSIGNED NOT NULL,
                invitation_id INT UNSIGNED NULL,
                score TINYINT NULL,
                sentiment VARCHAR(20) NULL,
                comment TEXT NULL,
                follow_up_status VARCHAR(20) NOT NULL DEFAULT 'none',
                follow_up_by_user_id INT UNSIGNED NULL,
                follow_up_at DATETIME NULL,
                submitted_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_cx_responses_invitation (invitation_id),
                KEY idx_cx_responses_survey (organization_id, survey_id, submitted_at),
                KEY idx_cx_responses_follow_up (organization_id, follow_up_status),
                CONSTRAINT fk_cx_responses_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_cx_responses_survey FOREIGN KEY (survey_id) REFERENCES cx_surveys (id) ON DELETE CASCADE,
                CONSTRAINT fk_cx_responses_invitation FOREIGN KEY (invitation_id) REFERENCES cx_invitations (id) ON DELETE SET NULL,
                CONSTRAINT fk_cx_responses_follow_up_by FOREIGN KEY (follow_up_by_user_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE cx_response_answers (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                response_id INT UNSIGNED NOT NULL,
                question_id INT UNSIGNED NOT NULL,
                numeric_value SMALLINT NULL,
                text_value TEXT NULL,
                KEY idx_cx_answers_response (response_id),
                KEY idx_cx_answers_question (question_id),
                CONSTRAINT fk_cx_answers_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_cx_answers_response FOREIGN KEY (response_id) REFERENCES cx_responses (id) ON DELETE CASCADE,
                CONSTRAINT fk_cx_answers_question FOREIGN KEY (question_id) REFERENCES cx_survey_questions (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS cx_response_answers');
        $pdo->exec('DROP TABLE IF EXISTS cx_responses');
        $pdo->exec('DROP TABLE IF EXISTS cx_invitations');
        $pdo->exec('DROP TABLE IF EXISTS cx_survey_questions');
        $pdo->exec('DROP TABLE IF EXISTS cx_surveys');
    }
};
