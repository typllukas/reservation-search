<?php

declare(strict_types=1);

namespace App\Elasticsearch\DocumentFactory;

use App\Entity\Reservation;
use App\Helper\PhoneDigits;

/**
 * A key not declared in ReservationIndexDefinition is rejected, the mapping is dynamic: strict.
 *
 * @phpstan-type ReservationRoomDocument array{
 *     kind: string,
 *     guest_count: int,
 *     price: int,
 * }
 * @phpstan-type ReservationDocument array{
 *     id: string,
 *     number: string,
 *     status: string,
 *     source: string,
 *     arrival: string,
 *     departure: string,
 *     nights: int,
 *     total_price: int,
 *     paid: bool,
 *     note: string|null,
 *     created_at: string,
 *     updated_at: string,
 *     guest: array{id: string, name: string, email: string, phone: string, phone_digits: string},
 *     hotel: array{id: string, code: string, name: string, chain: string},
 *     rooms: list<ReservationRoomDocument>,
 * }
 */
final class ReservationDocumentFactory
{
    /**
     * @return ReservationDocument
     */
    public function build(Reservation $reservation): array
    {
        $hotel = $reservation->getHotel();
        $guest = $reservation->getGuest();

        return [
            'id' => $reservation->getId()->toBase32(),
            'number' => $reservation->getNumber(),
            'status' => $reservation->getStatus()->value,
            'source' => $reservation->getSource()->value,
            'arrival' => $reservation->getArrival()->format('Y-m-d'),
            'departure' => $reservation->getDeparture()->format('Y-m-d'),
            'nights' => $reservation->getNightCount(),
            'total_price' => $reservation->getTotalPrice(),
            'paid' => $reservation->isPaid(),
            'note' => $reservation->getNote(),
            'created_at' => $reservation->getCreatedAt()->format('Y-m-d\TH:i:s.uP'),
            'updated_at' => $reservation->getUpdatedAt()->format('Y-m-d\TH:i:s.uP'),
            'guest' => [
                'id' => $guest->getId()->toBase32(),
                'name' => $guest->getName(),
                'email' => $guest->getEmail(),
                'phone' => $guest->getPhone(),
                'phone_digits' => PhoneDigits::normalize($guest->getPhone()),
            ],
            'hotel' => [
                'id' => $hotel->getId()->toBase32(),
                'code' => $hotel->getCode(),
                'name' => $hotel->getName(),
                'chain' => $hotel->getChain(),
            ],
            'rooms' => $this->buildRooms($reservation),
        ];
    }

    /**
     * @return list<ReservationRoomDocument>
     */
    private function buildRooms(Reservation $reservation): array
    {
        $rooms = [];
        foreach ($reservation->getRooms() as $room) {
            $rooms[] = [
                'kind' => $room->getKind()->value,
                'guest_count' => $room->getGuestCount(),
                'price' => $room->getPrice(),
            ];
        }

        return $rooms;
    }
}
