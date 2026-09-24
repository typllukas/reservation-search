<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260825174208 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the hotel, guest, reservation and reservation room tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE guest (id BINARY(16) NOT NULL, name VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL, phone VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE hotel (id BINARY(16) NOT NULL, code VARCHAR(16) NOT NULL, name VARCHAR(255) NOT NULL, chain VARCHAR(255) NOT NULL, city VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_3535ED977153098 (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE reservation (id BINARY(16) NOT NULL, number VARCHAR(16) NOT NULL, status VARCHAR(16) NOT NULL, source VARCHAR(16) NOT NULL, arrival DATE NOT NULL, departure DATE NOT NULL, total_price INT NOT NULL, paid TINYINT NOT NULL, note LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, guest_id BINARY(16) NOT NULL, hotel_id BINARY(16) NOT NULL, UNIQUE INDEX UNIQ_42C8495596901F54 (number), INDEX IDX_42C849559A4AA658 (guest_id), INDEX IDX_42C849553243BB18 (hotel_id), INDEX idx_hotel_arrival_status (hotel_id, arrival, status), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE reservation_room (id BINARY(16) NOT NULL, kind VARCHAR(16) NOT NULL, guest_count INT NOT NULL, price INT NOT NULL, created_at DATETIME NOT NULL, reservation_id BINARY(16) NOT NULL, INDEX IDX_64A69CF3B83297E7 (reservation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE reservation ADD CONSTRAINT FK_42C849559A4AA658 FOREIGN KEY (guest_id) REFERENCES guest (id)');
        $this->addSql('ALTER TABLE reservation ADD CONSTRAINT FK_42C849553243BB18 FOREIGN KEY (hotel_id) REFERENCES hotel (id)');
        $this->addSql('ALTER TABLE reservation_room ADD CONSTRAINT FK_64A69CF3B83297E7 FOREIGN KEY (reservation_id) REFERENCES reservation (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation DROP FOREIGN KEY FK_42C849559A4AA658');
        $this->addSql('ALTER TABLE reservation DROP FOREIGN KEY FK_42C849553243BB18');
        $this->addSql('ALTER TABLE reservation_room DROP FOREIGN KEY FK_64A69CF3B83297E7');
        $this->addSql('DROP TABLE guest');
        $this->addSql('DROP TABLE hotel');
        $this->addSql('DROP TABLE reservation');
        $this->addSql('DROP TABLE reservation_room');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
