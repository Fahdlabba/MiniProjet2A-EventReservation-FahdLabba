<?php
namespace App\Controller;

use App\Entity\Event;
use App\Entity\Reservation;
use App\Entity\User;
use App\Service\ReservationNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class EventController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ReservationNotificationService $notificationService,
    ) {
    }

    #[Route('/', name: 'home')]
    public function index(): Response
    {
        $events = $this->entityManager->getRepository(Event::class)->findAll();
        return $this->render('events/index.html.twig', [
            'events' => $events,
        ]);
    }

    #[Route('/event/{id}', name: 'event_show', requirements: ['id' => '\d+'])]
    public function show(Event $event): Response
    {
        return $this->render('events/show.html.twig', [
            'event' => $event,
        ]);
    }

    #[Route('/my-reservations', name: 'my_reservations')]
    public function myReservations(): Response
    {
        return $this->render('events/my_reservations.html.twig');
    }

    #[Route('/event/{id}/book', name: 'event_book', methods: ['POST'])]
    public function book(Request $request, Event $event): Response
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            if ($this->isAjaxRequest($request)) {
                return $this->json([
                    'error' => 'Vous devez etre connecte pour reserver cet evenement.',
                ], Response::HTTP_UNAUTHORIZED);
            }

            $this->addFlash('error', 'Vous devez vous connecter pour reserver cet evenement.');
            return $this->redirectToRoute('app_login');
        }

        $now = new \DateTimeImmutable();
        $subscriptionOpenAt = $event->getSubscriptionOpenAt();
        $subscriptionCloseAt = $event->getSubscriptionCloseAt();

        if ($subscriptionOpenAt instanceof \DateTimeInterface && $now < $subscriptionOpenAt) {
            return $this->bookingError(
                $request,
                $event,
                sprintf('Les reservations ouvrent le %s.', $subscriptionOpenAt->format('d/m/Y H:i'))
            );
        }

        if ($subscriptionCloseAt instanceof \DateTimeInterface && $now > $subscriptionCloseAt) {
            return $this->bookingError($request, $event, 'La periode de reservation est terminee pour cet evenement.');
        }

        $bookFor = $request->request->get('book_for', 'self');
        $phone = trim((string) $request->request->get('phone', ''));

        if ($bookFor === 'other') {
            $name = trim((string) $request->request->get('name', ''));
            $email = trim((string) $request->request->get('email', ''));
        } else {
            $email = trim((string) $currentUser->getEmail());
            $name = trim((string) $request->request->get('name', ''));

            if ($name === '') {
                $name = $this->inferNameFromEmail($email);
            }
        }

        if ($name === '' || $email === '' || $phone === '') {
            return $this->bookingError(
                $request,
                $event,
                'Erreur lors de la reservation (informations manquantes).'
            );
        }

        $reservation = new Reservation();
        $reservation->setEvent($event);
        $reservation->setName($name);
        $reservation->setEmail($email);
        $reservation->setPhone($phone);

        $message = 'Votre reservation a ete confirmee avec succes !';

        if ($event->getSeats() > 0) {
            $reservation->setStatus(Reservation::STATUS_CONFIRMED);
            $reservation->setClaimedAt(new \DateTimeImmutable());
            $reservation->setClaimExpiresAt(null);
            $event->setSeats($event->getSeats() - 1);
        } else {
            $reservation->setStatus(Reservation::STATUS_WAITLISTED);
            $reservation->setClaimedAt(null);
            $reservation->setClaimExpiresAt(null);
            $waitlistedBefore = $this->entityManager->getRepository(Reservation::class)->count([
                'event' => $event,
                'status' => Reservation::STATUS_WAITLISTED,
            ]);
            $waitlistPosition = $waitlistedBefore + 1;
            $message = sprintf('Evenement complet: vous etes ajoute a la liste d\'attente (position %d).', $waitlistPosition);
        }

        $this->entityManager->persist($reservation);
        $this->entityManager->flush();
        $this->notificationService->notifyReservationCreated($reservation);

        if ($this->isAjaxRequest($request)) {
            return $this->json([
                'success' => true,
                'status' => $reservation->getStatus(),
                'message' => $message,
            ]);
        }

        $this->addFlash('success', $message);
        return $this->redirectToRoute('event_show', ['id' => $event->getId()]);
    }

    #[Route('/reservation/{id}/claim', name: 'reservation_claim', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function claimReservation(Request $request, int $id): Response
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            if ($this->isAjaxRequest($request)) {
                return $this->json(['error' => 'Vous devez etre connecte pour valider votre reservation.'], Response::HTTP_UNAUTHORIZED);
            }

            $this->addFlash('error', 'Vous devez vous connecter pour valider votre reservation.');
            return $this->redirectToRoute('app_login');
        }

        $reservation = $this->entityManager->getRepository(Reservation::class)->find($id);
        if (!$reservation instanceof Reservation) {
            return $this->claimError($request, null, 'Reservation introuvable.', Response::HTTP_NOT_FOUND);
        }

        if (strcasecmp(trim((string) $reservation->getEmail()), trim((string) $currentUser->getEmail())) !== 0) {
            return $this->claimError($request, $reservation->getEvent(), 'Vous ne pouvez pas valider cette reservation.', Response::HTTP_FORBIDDEN);
        }

        if ($reservation->getStatus() !== Reservation::STATUS_CONFIRMED) {
            return $this->claimError($request, $reservation->getEvent(), 'Cette reservation ne peut pas etre validee.', Response::HTTP_CONFLICT);
        }

        $claimDeadline = $reservation->getClaimExpiresAt();
        if (!$claimDeadline instanceof \DateTimeImmutable) {
            return $this->claimError($request, $reservation->getEvent(), 'Aucune validation supplementaire n\'est necessaire.', Response::HTTP_CONFLICT);
        }

        $now = new \DateTimeImmutable();
        if ($claimDeadline < $now) {
            $reservation->setStatus(Reservation::STATUS_EXPIRED);
            $reservation->setClaimExpiresAt(null);
            $reservation->setClaimedAt(null);
            $reservation->getEvent()->setSeats(($reservation->getEvent()->getSeats() ?? 0) + 1);
            $this->entityManager->flush();

            return $this->claimError(
                $request,
                $reservation->getEvent(),
                'Le delai de validation est depasse. Votre place a ete liberee.',
                Response::HTTP_GONE
            );
        }

        $reservation->setClaimedAt($now);
        $reservation->setClaimExpiresAt(null);
        $this->entityManager->flush();

        if ($this->isAjaxRequest($request)) {
            return $this->json([
                'success' => true,
                'message' => 'Votre reservation est maintenant confirmee.',
            ]);
        }

        $this->addFlash('success', 'Votre reservation est maintenant confirmee.');
        return $this->redirectToRoute('event_show', ['id' => $reservation->getEvent()->getId()]);
    }

    private function bookingError(Request $request, Event $event, string $message): Response
    {
        if ($this->isAjaxRequest($request)) {
            return $this->json(['error' => $message], Response::HTTP_BAD_REQUEST);
        }

        $this->addFlash('error', $message);
        return $this->redirectToRoute('event_show', ['id' => $event->getId()]);
    }

    private function claimError(Request $request, ?Event $event, string $message, int $status): Response
    {
        if ($this->isAjaxRequest($request)) {
            return $this->json(['error' => $message], $status);
        }

        $this->addFlash('error', $message);
        if (!$event instanceof Event) {
            return $this->redirectToRoute('home');
        }

        return $this->redirectToRoute('event_show', ['id' => $event->getId()]);
    }

    private function isAjaxRequest(Request $request): bool
    {
        $accept = (string) $request->headers->get('Accept', '');
        return $request->isXmlHttpRequest() || str_contains($accept, 'application/json');
    }

    private function inferNameFromEmail(string $email): string
    {
        $localPart = explode('@', $email)[0] ?? '';
        $readable = trim((string) preg_replace('/\s+/', ' ', str_replace(['.', '_', '-'], ' ', $localPart)));

        if ($readable === '') {
            return 'Participant';
        }

        return ucwords($readable);
    }
}
