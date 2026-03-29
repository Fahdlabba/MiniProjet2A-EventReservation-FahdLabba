<?php

namespace App\Service;

use App\Entity\Event;
use App\Entity\Reservation;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

class ReservationNotificationService
{
    public function __construct(
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        #[Autowire('%env(string:MAILER_FROM_ADDRESS)%')] private string $fromAddress,
    ) {
    }

    public function notifyReservationCreated(Reservation $reservation): void
    {
        $event = $reservation->getEvent();
        $recipient = trim((string) $reservation->getEmail());

        if (!$event instanceof Event || $recipient === '') {
            $this->logger->warning('Skipping reservation confirmation email: missing event or recipient.', [
                'reservationId' => $reservation->getId(),
            ]);

            return;
        }

        $isWaitlisted = $reservation->getStatus() === Reservation::STATUS_WAITLISTED;
        $subject = $isWaitlisted
            ? sprintf('Inscription liste d attente - %s', (string) $event->getTitle())
            : sprintf('Confirmation de reservation - %s', (string) $event->getTitle());

        $statusLine = $isWaitlisted
            ? 'Votre inscription est en liste d attente.'
            : 'Votre reservation est confirmee.';

        $body = implode("\n", [
            sprintf('Bonjour %s,', (string) ($reservation->getName() ?: 'Participant')),
            '',
            $statusLine,
            sprintf('Evenement: %s', (string) $event->getTitle()),
            sprintf('Date: %s', $event->getDate()?->format('d/m/Y H:i') ?? 'A confirmer'),
            sprintf('Lieu: %s', (string) $event->getLocation()),
            '',
            $isWaitlisted
                ? 'Nous vous contacterons automatiquement si une place se libere.'
                : 'Merci pour votre inscription. A bientot !',
            '',
        ]);

        $this->sendEmail($recipient, $subject, $body, [
            'reservationId' => $reservation->getId(),
            'eventId' => $event->getId(),
            'type' => 'reservation_created',
        ]);
    }

    public function notifyPromotionClaimWindow(Reservation $reservation): void
    {
        $event = $reservation->getEvent();
        $recipient = trim((string) $reservation->getEmail());
        $claimDeadline = $reservation->getClaimExpiresAt();

        if (!$event instanceof Event || $recipient === '') {
            $this->logger->warning('Skipping waitlist promotion email: missing event or recipient.', [
                'reservationId' => $reservation->getId(),
                'claimDeadline' => $claimDeadline?->format(DATE_ATOM),
            ]);

            return;
        }

        $deadlineLabel = $claimDeadline?->format('d/m/Y H:i') ?? 'des que possible';

        $subject = sprintf('Une place est disponible - %s', (string) $event->getTitle());
        $body = implode("\n", [
            sprintf('Bonjour %s,', (string) ($reservation->getName() ?: 'Participant')),
            '',
            'Bonne nouvelle : une place vient de se liberer pour votre evenement.',
            sprintf('Evenement: %s', (string) $event->getTitle()),
            sprintf('Date: %s', $event->getDate()?->format('d/m/Y H:i') ?? 'A confirmer'),
            sprintf('Lieu: %s', (string) $event->getLocation()),
            sprintf('Date limite de validation: %s', $deadlineLabel),
            '',
            'Merci de finaliser rapidement votre reservation.',
            '',
        ]);

        $this->sendEmail($recipient, $subject, $body, [
            'reservationId' => $reservation->getId(),
            'eventId' => $event->getId(),
            'type' => 'waitlist_promoted',
            'claimDeadline' => $claimDeadline?->format(DATE_ATOM),
        ]);
    }

    private function sendEmail(string $to, string $subject, string $body, array $context = []): void
    {
        try {
            $message = (new Email())
                ->from($this->fromAddress)
                ->to($to)
                ->subject($subject)
                ->text($body);

            $this->mailer->send($message);

            $this->logger->info('Reservation email sent.', array_merge($context, [
                'to' => $to,
                'subject' => $subject,
            ]));
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to send reservation email.', array_merge($context, [
                'to' => $to,
                'subject' => $subject,
                'error' => $exception->getMessage(),
            ]));
        }
    }
}
