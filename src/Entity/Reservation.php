<?php
namespace App\Entity;

use App\Repository\ReservationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ReservationRepository::class)]
#[ORM\Table(name: '`reservation`')]
class Reservation
{
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_WAITLISTED = 'waitlisted';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Event::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Event $event = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255)]
    private ?string $email = null;

    #[ORM\Column(length: 255)]
    private ?string $phone = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(length: 32)]
    private string $status = self::STATUS_CONFIRMED;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $claimExpiresAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $claimedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->status = self::STATUS_CONFIRMED;
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_CONFIRMED,
            self::STATUS_WAITLISTED,
            self::STATUS_CANCELLED,
            self::STATUS_EXPIRED,
        ];
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEvent(): ?Event
    {
        return $this->event;
    }

    public function setEvent(?Event $event): self
    {
        $this->event = $event;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;
        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(string $phone): self
    {
        $this->phone = $phone;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        if (!in_array($status, self::statuses(), true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported reservation status: %s', $status));
        }

        $this->status = $status;
        return $this;
    }

    public function getClaimExpiresAt(): ?\DateTimeImmutable
    {
        return $this->claimExpiresAt;
    }

    public function setClaimExpiresAt(?\DateTimeImmutable $claimExpiresAt): self
    {
        $this->claimExpiresAt = $claimExpiresAt;
        return $this;
    }

    public function getClaimedAt(): ?\DateTimeImmutable
    {
        return $this->claimedAt;
    }

    public function setClaimedAt(?\DateTimeImmutable $claimedAt): self
    {
        $this->claimedAt = $claimedAt;
        return $this;
    }
}
