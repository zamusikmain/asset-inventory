<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Защита журналов от изменения (п. 18.1: история не удаляется вместе с записью)
 * и индексы для поиска в реестре (п. 19: открытие реестра ≤ 3 с, поиск ≤ 1 с).
 */
final class Version20261107170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Append-only audit_log/asset_movements, trigram search indexes, messenger tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION forbid_history_change() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Таблица % только для добавления: изменение и удаление истории запрещены', TG_TABLE_NAME;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        $this->addSql('CREATE TRIGGER audit_log_append_only BEFORE UPDATE OR DELETE ON audit_log FOR EACH ROW EXECUTE FUNCTION forbid_history_change()');
        // asset_movements: запрет UPDATE; DELETE разрешен только каскадом при физическом удалении актива
        // (в приложении физическое удаление не используется — только архивирование)
        // Срабатывает только при изменении содержательных колонок; FK ON DELETE SET NULL
        // (удаление справочника) разрешен — снимки названий остаются в *_name.
        $this->addSql('CREATE TRIGGER asset_movements_no_update BEFORE UPDATE OF type, from_status, to_status, from_employee_name, to_employee_name, from_location_name, to_location_name, performed_by_name, performed_at, source, comment ON asset_movements FOR EACH ROW EXECUTE FUNCTION forbid_history_change()');

        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        $this->addSql('CREATE INDEX assets_name_trgm ON assets USING gin (lower(name) gin_trgm_ops)');
        $this->addSql('CREATE INDEX assets_inv_trgm ON assets USING gin (lower(inventory_number) gin_trgm_ops)');
        $this->addSql('CREATE INDEX assets_serial_trgm ON assets USING gin (lower(serial_number) gin_trgm_ops)');
        $this->addSql('CREATE INDEX employees_name_trgm ON employees USING gin (lower(display_name) gin_trgm_ops)');

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS messenger_messages (
                id BIGSERIAL NOT NULL PRIMARY KEY,
                body TEXT NOT NULL,
                headers TEXT NOT NULL,
                queue_name VARCHAR(190) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                available_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS messenger_messages_queue ON messenger_messages (queue_name, available_at, delivered_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS audit_log_append_only ON audit_log');
        $this->addSql('DROP TRIGGER IF EXISTS asset_movements_no_update ON asset_movements');
        $this->addSql('DROP FUNCTION IF EXISTS forbid_history_change()');
        $this->addSql('DROP INDEX IF EXISTS assets_name_trgm');
        $this->addSql('DROP INDEX IF EXISTS assets_inv_trgm');
        $this->addSql('DROP INDEX IF EXISTS assets_serial_trgm');
        $this->addSql('DROP INDEX IF EXISTS employees_name_trgm');
        $this->addSql('DROP TABLE IF EXISTS messenger_messages');
    }
}
