<?php
namespace App\Repository;

use App\Entity\Event;
use App\Entity\Reservation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Reservation>
 */
class ReservationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reservation::class);
    }

    /**
     * @return Reservation[]
     */
    public function findByEmailSorted(string $email): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('LOWER(r.email) = :email')
            ->setParameter('email', strtolower(trim($email)))
            ->orderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function getWaitlistPosition(Reservation $reservation): ?int
    {
        if ($reservation->getStatus() !== Reservation::STATUS_WAITLISTED) {
            return null;
        }

        $createdAt = $reservation->getCreatedAt();
        $event = $reservation->getEvent();
        $id = $reservation->getId();
        if (!$createdAt instanceof \DateTimeImmutable || !$event instanceof Event || !is_int($id)) {
            return null;
        }

        $position = (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.event = :event')
            ->andWhere('r.status = :status')
            ->andWhere('(r.createdAt < :createdAt OR (r.createdAt = :createdAt AND r.id <= :id))')
            ->setParameter('event', $event)
            ->setParameter('status', Reservation::STATUS_WAITLISTED)
            ->setParameter('createdAt', $createdAt)
            ->setParameter('id', $id)
            ->getQuery()
            ->getSingleScalarResult();

        return $position > 0 ? $position : null;
    }
}
