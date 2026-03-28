<?php

namespace App\Service;

use App\Entity\Reservation;
use Psr\Log\LoggerInterface;

class ReservationNotificationService
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function notifyPromotionClaimWindow(Reservation $reservation): void
    {
        $claimDeadline = $reservation->getClaimExpiresAt();

        $this->logger->info('Waitlist promotion claim window notification dispatched.', [
            'reservationId' => $reservation->getId(),
            'eventId' => $reservation->getEvent()?->getId(),
            'email' => $reservation->getEmail(),
            'claimDeadline' => $claimDeadline?->format(DATE_ATOM),
        ]);
    }
}
