<?php
namespace App\Controller;

use App\Entity\Event;
use App\Entity\Reservation;
use App\Service\ReservationNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin')]
#[IsGranted('ROLE_ADMIN')]
class AdminApiController extends AbstractController
{
    private const CLAIM_WINDOW_MINUTES = 30;

    public function __construct(
        private EntityManagerInterface $em,
        private ReservationNotificationService $notificationService,
    ) {
    }

    #[Route('/data', name: 'api_admin_data', methods: ['GET'])]
    public function data(): JsonResponse
    {
        $events = $this->em->getRepository(Event::class)->findAll();
        $reservations = $this->em->getRepository(Reservation::class)->findAll();

        $eventData = array_map(fn(Event $e) => $this->serializeEvent($e), $events);

        $reservationData = array_map(fn(Reservation $r) => $this->serializeReservation($r), $reservations);
        
        return $this->json([
            'events' => $eventData,
            'reservations' => $reservationData,
        ]);
    }
    
    #[Route('/event', name: 'api_admin_event_create', methods: ['POST'])]
    public function createEvent(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON payload'], 400);
        }

        $event = new Event();
        $validationError = $this->applyEventPayload($event, $data, true);
        if ($validationError instanceof JsonResponse) {
            return $validationError;
        }

        $this->em->persist($event);
        $this->em->flush();

        return $this->json([
            'success' => true,
            'event' => $this->serializeEvent($event),
        ]);
    }

    #[Route('/event/{id}', name: 'api_admin_event_update', methods: ['PUT', 'PATCH'])]
    public function updateEvent(int $id, Request $request): JsonResponse
    {
        $event = $this->em->getRepository(Event::class)->find($id);
        if (!$event instanceof Event) {
            return $this->json(['error' => 'Event not found'], 404);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON payload'], 400);
        }

        $validationError = $this->applyEventPayload($event, $data, false);
        if ($validationError instanceof JsonResponse) {
            return $validationError;
        }

        $this->em->flush();

        return $this->json([
            'success' => true,
            'event' => $this->serializeEvent($event),
        ]);
    }

    #[Route('/event/{id}', name: 'api_admin_event_delete', methods: ['DELETE'])]
    public function deleteEvent(int $id): JsonResponse
    {
        $event = $this->em->getRepository(Event::class)->find($id);
        if (!$event instanceof Event) {
            return $this->json(['error' => 'Event not found'], 404);
        }

        $reservationCount = $this->em->getRepository(Reservation::class)->count(['event' => $event]);
        if ($reservationCount > 0) {
            return $this->json([
                'error' => 'Cannot delete an event with existing reservations',
                'reservations' => $reservationCount,
            ], 409);
        }

        $this->em->remove($event);
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    #[Route('/reservation/{id}/cancel', name: 'api_admin_reservation_cancel', methods: ['POST'])]
    public function cancelReservation(int $id): JsonResponse
    {
        $reservation = $this->em->getRepository(Reservation::class)->find($id);
        if (!$reservation instanceof Reservation) {
            return $this->json(['error' => 'Reservation not found'], 404);
        }

        $status = $reservation->getStatus();
        if ($status === Reservation::STATUS_CANCELLED || $status === Reservation::STATUS_EXPIRED) {
            return $this->json(['error' => 'Reservation is already inactive'], 409);
        }

        $event = $reservation->getEvent();
        $promotedReservation = null;

        if ($status === Reservation::STATUS_CONFIRMED) {
            $reservation->setStatus(Reservation::STATUS_CANCELLED);
            $event->setSeats(($event->getSeats() ?? 0) + 1);
            $promotedReservation = $this->promoteNextWaitlistedReservation($event);
        } else {
            $reservation->setStatus(Reservation::STATUS_CANCELLED);
        }

        $this->em->flush();

        return $this->json([
            'success' => true,
            'reservation' => $this->serializeReservation($reservation),
            'promotedReservation' => $promotedReservation ? $this->serializeReservation($promotedReservation) : null,
        ]);
    }

    private function applyEventPayload(Event $event, array $data, bool $requireAllFields): ?JsonResponse
    {
        $requiredFields = ['title', 'description', 'date', 'seats', 'location', 'image'];
        if ($requireAllFields) {
            foreach ($requiredFields as $field) {
                if (!array_key_exists($field, $data)) {
                    return $this->json(['error' => sprintf('Missing field: %s', $field)], 400);
                }
            }
        }

        if (array_key_exists('title', $data)) {
            $title = trim((string) $data['title']);
            if ($title === '') {
                return $this->json(['error' => 'Title cannot be empty'], 400);
            }
            $event->setTitle($title);
        }

        if (array_key_exists('description', $data)) {
            $description = trim((string) $data['description']);
            if ($description === '') {
                return $this->json(['error' => 'Description cannot be empty'], 400);
            }
            $event->setDescription($description);
        }

        if (array_key_exists('image', $data)) {
            if ($data['image'] === null) {
                return $this->json(['error' => 'Image cannot be empty'], 400);
            } else {
                $image = trim((string) $data['image']);
                if ($image === '') {
                    return $this->json(['error' => 'Image cannot be empty'], 400);
                } elseif (!$this->isValidImageReference($image)) {
                    return $this->json(['error' => 'Image must be a valid URL or path'], 400);
                } else {
                    $event->setImage($image);
                }
            }
        }

        if (array_key_exists('location', $data)) {
            $location = trim((string) $data['location']);
            if ($location === '') {
                return $this->json(['error' => 'Location cannot be empty'], 400);
            }
            $event->setLocation($location);
        }

        if (array_key_exists('seats', $data)) {
            if (!is_numeric($data['seats'])) {
                return $this->json(['error' => 'Seats must be numeric'], 400);
            }

            $seats = (int) $data['seats'];
            if ($seats < 0) {
                return $this->json(['error' => 'Seats cannot be negative'], 400);
            }

            $event->setSeats($seats);
        }

        if (array_key_exists('date', $data)) {
            $eventDate = $this->parseDateTime($data['date']);
            if (!$eventDate instanceof \DateTimeInterface) {
                return $this->json(['error' => 'Invalid event date format'], 400);
            }
            $event->setDate($eventDate);
        }

        if (array_key_exists('subscriptionOpenAt', $data)) {
            $subscriptionOpenAt = $this->parseNullableDateTime($data['subscriptionOpenAt']);
            if ($subscriptionOpenAt === false) {
                return $this->json(['error' => 'Invalid subscriptionOpenAt format'], 400);
            }
            $event->setSubscriptionOpenAt($subscriptionOpenAt);
        }

        if (array_key_exists('subscriptionCloseAt', $data)) {
            $subscriptionCloseAt = $this->parseNullableDateTime($data['subscriptionCloseAt']);
            if ($subscriptionCloseAt === false) {
                return $this->json(['error' => 'Invalid subscriptionCloseAt format'], 400);
            }
            $event->setSubscriptionCloseAt($subscriptionCloseAt);
        }

        $subscriptionOpenAt = $event->getSubscriptionOpenAt();
        $subscriptionCloseAt = $event->getSubscriptionCloseAt();
        if (
            $subscriptionOpenAt instanceof \DateTimeInterface
            && $subscriptionCloseAt instanceof \DateTimeInterface
            && $subscriptionCloseAt < $subscriptionOpenAt
        ) {
            return $this->json(['error' => 'Subscription close must be after subscription open'], 400);
        }

        return null;
    }

    private function serializeEvent(Event $event): array
    {
        return [
            'id' => $event->getId(),
            'title' => $event->getTitle(),
            'description' => $event->getDescription(),
            'date' => $event->getDate()?->format('Y-m-d\\TH:i'),
            'location' => $event->getLocation(),
            'seats' => $event->getSeats(),
            'image' => $event->getImage(),
            'subscriptionOpenAt' => $event->getSubscriptionOpenAt()?->format('Y-m-d\\TH:i'),
            'subscriptionCloseAt' => $event->getSubscriptionCloseAt()?->format('Y-m-d\\TH:i'),
        ];
    }

    private function serializeReservation(Reservation $reservation): array
    {
        return [
            'id' => $reservation->getId(),
            'eventId' => $reservation->getEvent()->getId(),
            'eventName' => $reservation->getEvent()->getTitle(),
            'name' => $reservation->getName(),
            'email' => $reservation->getEmail(),
            'phone' => $reservation->getPhone(),
            'status' => $reservation->getStatus(),
            'createdAt' => $reservation->getCreatedAt()?->format('Y-m-d\\TH:i:s'),
            'claimExpiresAt' => $reservation->getClaimExpiresAt()?->format('Y-m-d\\TH:i:s'),
            'claimedAt' => $reservation->getClaimedAt()?->format('Y-m-d\\TH:i:s'),
        ];
    }

    private function promoteNextWaitlistedReservation(Event $event): ?Reservation
    {
        if (($event->getSeats() ?? 0) <= 0) {
            return null;
        }

        $nextReservation = $this->em->getRepository(Reservation::class)->findOneBy([
            'event' => $event,
            'status' => Reservation::STATUS_WAITLISTED,
        ], [
            'createdAt' => 'ASC',
            'id' => 'ASC',
        ]);

        if (!$nextReservation instanceof Reservation) {
            return null;
        }

        $claimDeadline = (new \DateTimeImmutable())->modify(sprintf('+%d minutes', self::CLAIM_WINDOW_MINUTES));
        $nextReservation->setStatus(Reservation::STATUS_CONFIRMED);
        $nextReservation->setClaimedAt(null);
        $nextReservation->setClaimExpiresAt($claimDeadline);
        $event->setSeats(max(($event->getSeats() ?? 0) - 1, 0));
        $this->notificationService->notifyPromotionClaimWindow($nextReservation);

        return $nextReservation;
    }

    private function isValidImageReference(string $image): bool
    {
        if (strlen($image) > 255) {
            return false;
        }

        if (filter_var($image, FILTER_VALIDATE_URL) !== false) {
            return true;
        }

        if (str_starts_with($image, '/')) {
            return true;
        }

        return (bool) preg_match('/^[A-Za-z0-9._\\/-]+$/', $image);
    }

    private function parseDateTime(mixed $value): ?\DateTimeInterface
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTime($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function parseNullableDateTime(mixed $value): \DateTimeInterface|false|null
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && trim($value) === '') {
            return null;
        }

        return $this->parseDateTime($value) ?: false;
    }
}
