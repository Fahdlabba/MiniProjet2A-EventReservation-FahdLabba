<?php

namespace App\Controller;

use App\Entity\Reservation;
use App\Entity\User;
use App\Repository\ReservationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/reservations')]
#[IsGranted('ROLE_USER')]
class ReservationApiController extends AbstractController
{
    public function __construct(private ReservationRepository $reservationRepository)
    {
    }

    #[Route('/mine', name: 'api_reservations_mine', methods: ['GET'])]
    public function mine(): JsonResponse
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            return $this->json(['error' => 'Authentication required'], 401);
        }

        $reservations = $this->reservationRepository->findByEmailSorted((string) $currentUser->getEmail());
        $now = new \DateTimeImmutable();

        $payload = array_map(function (Reservation $reservation) use ($now): array {
            $claimExpiresAt = $reservation->getClaimExpiresAt();
            $canClaim = $reservation->getStatus() === Reservation::STATUS_CONFIRMED
                && $claimExpiresAt instanceof \DateTimeImmutable
                && $claimExpiresAt > $now
                && $reservation->getClaimedAt() === null;

            return [
                'id' => $reservation->getId(),
                'eventId' => $reservation->getEvent()->getId(),
                'eventName' => $reservation->getEvent()->getTitle(),
                'eventDate' => $reservation->getEvent()->getDate()?->format('Y-m-d\TH:i:s'),
                'eventLocation' => $reservation->getEvent()->getLocation(),
                'status' => $reservation->getStatus(),
                'createdAt' => $reservation->getCreatedAt()?->format('Y-m-d\TH:i:s'),
                'waitlistPosition' => $this->reservationRepository->getWaitlistPosition($reservation),
                'claimExpiresAt' => $claimExpiresAt?->format('Y-m-d\TH:i:s'),
                'claimedAt' => $reservation->getClaimedAt()?->format('Y-m-d\TH:i:s'),
                'canClaim' => $canClaim,
            ];
        }, $reservations);

        return $this->json([
            'reservations' => $payload,
        ]);
    }
}
