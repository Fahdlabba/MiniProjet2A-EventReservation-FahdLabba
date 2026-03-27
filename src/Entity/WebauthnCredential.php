<?php
namespace App\Entity;

use App\Repository\WebauthnCredentialRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Webauthn\PublicKeyCredentialSource;

#[ORM\Entity(repositoryClass: WebauthnCredentialRepository::class)]
#[ORM\Table(name: 'webauthn_credential')]
class WebauthnCredential
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'webauthnCredentials')]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column(type: 'text')]
    private string $credentialData;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $lastUsedAt;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
        $this->lastUsedAt = new \DateTimeImmutable();
        $this->name = 'Passkey';
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getCredentialData(): ?string
    {
        return $this->credentialData;
    }

    public function setCredentialData(string $credentialData): self
    {
        $this->credentialData = $credentialData;
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

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function setLastUsedAt(\DateTimeImmutable $lastUsedAt): self
    {
        $this->lastUsedAt = $lastUsedAt;
        return $this;
    }

    public function touch(): void
    {
        $this->lastUsedAt = new \DateTimeImmutable();
    }

    public function getCredentialSource(): PublicKeyCredentialSource
    {
        $data = $this->credentialData;
        
        // Try base64-decoded PHP serialization first (new format)
        $decoded = base64_decode($data, true);
        if ($decoded !== false) {
            $result = @unserialize($decoded);
            if ($result instanceof PublicKeyCredentialSource) {
                return $result;
            }
        }
        
        // Try raw PHP serialization (legacy)
        $result = @unserialize($data);
        if ($result instanceof PublicKeyCredentialSource) {
            return $result;
        }
        
        // Fall back to JSON (legacy format)
        $json = json_decode($data, true);
        if (is_array($json)) {
            return $this->createFromLegacyJson($json);
        }
        
        throw new \Exception('Unable to deserialize credential data: format not recognized');
    }
    
    private function createFromLegacyJson(array $json): PublicKeyCredentialSource
    {
        // Handle trust path
        $trustPathData = $json['trustPath'] ?? null;
        $trustPath = $this->createTrustPath($trustPathData);
        
        return PublicKeyCredentialSource::create(
            base64_decode($json['publicKeyCredentialId'] ?? ''),
            $json['type'] ?? 'public-key',
            $json['transports'] ?? [],
            $json['attestationType'] ?? 'none',
            $trustPath,
            $json['aaguid'] ?? '00000000-0000-0000-0000-000000000000',
            base64_decode($json['credentialPublicKey'] ?? ''),
            $json['userHandle'] ?? '',
            $json['counter'] ?? 0
        );
    }
    
    private function createTrustPath(?array $trustPathData): \Webauthn\TrustPath\TrustPath
    {
        if (!$trustPathData) {
            return new \Webauthn\TrustPath\EmptyTrustPath();
        }
        
        $type = $trustPathData['type'] ?? 'Webauthn\TrustPath\EmptyTrustPath';
        
        return match($type) {
            'Webauthn\TrustPath\EmptyTrustPath' => new \Webauthn\TrustPath\EmptyTrustPath(),
            'Webauthn\TrustPath\CertificateTrustPath' => \Webauthn\TrustPath\CertificateTrustPath::create(
                $trustPathData['certificates'] ?? []
            ),
            default => new \Webauthn\TrustPath\EmptyTrustPath()
        };
    }

    public function setCredentialSource(PublicKeyCredentialSource $source): void
    {
        // Base64 encode to ensure valid UTF-8 for database storage
        $this->credentialData = base64_encode(serialize($source));
    }
}
