<?php
namespace App\Repository;

use App\Entity\User;
use App\Entity\WebauthnCredential;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Webauthn\Bundle\Repository\CanSaveCredentialSource;
use Webauthn\Bundle\Repository\PublicKeyCredentialSourceRepositoryInterface;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * @extends ServiceEntityRepository<WebauthnCredential>
 */
class WebauthnCredentialRepository extends ServiceEntityRepository implements PublicKeyCredentialSourceRepositoryInterface, CanSaveCredentialSource
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WebauthnCredential::class);
    }

    public function findOneByCredentialId(string $publicKeyCredentialId): ?PublicKeyCredentialSource
    {
        $credential = $this->findByCredentialId($publicKeyCredentialId);
        return $credential ? $credential->getCredentialSource() : null;
    }

    public function findAllForUserEntity(PublicKeyCredentialUserEntity $publicKeyCredentialUserEntity): array
    {
        $user = $this->getEntityManager()
            ->getRepository(User::class)
            ->findOneBy(['email' => $publicKeyCredentialUserEntity->name]);

        if (!$user) {
            return [];
        }

        $credentials = $this->createQueryBuilder('c')
            ->andWhere('c.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        return array_map(function (WebauthnCredential $c) {
            return $c->getCredentialSource();
        }, $credentials);
    }

    public function saveCredentialSource(PublicKeyCredentialSource $publicKeyCredentialSource): void
    {
        $userHandle = $publicKeyCredentialSource->userHandle;
        $user = $this->getEntityManager()
            ->getRepository(User::class)
            // Uuid from bytes or just find by id. The userHandle contains the user id binary.
            ->find(\Symfony\Component\Uid\Uuid::fromBinary($userHandle));

        if (!$user) {
            return;
        }

        $existing = $this->findByCredentialId($publicKeyCredentialSource->publicKeyCredentialId);
        $credential = $existing ?? clone new WebauthnCredential();
        $credential->setUser($user);
        $credential->setCredentialSource($publicKeyCredentialSource);

        $this->getEntityManager()->persist($credential);
        $this->getEntityManager()->flush();
    }

    public function findByCredentialId(string $credentialId): ?WebauthnCredential
    {
        // The credentialId from frontend is base64url-encoded, but stored as binary in the source object
        // We need to decode the input to binary for proper comparison
        $binaryCredentialId = base64_decode(strtr($credentialId, '-_', '+/') . str_repeat('=', (4 - strlen($credentialId) % 4) % 4));
        
        $credentials = $this->findAll();
        foreach ($credentials as $cred) {
            $source = $cred->getCredentialSource();
            // Compare binary data directly
            if ($source->publicKeyCredentialId === $binaryCredentialId) {
                return $cred;
            }
        }
        return null;
    }

    public function saveCredential(User $user, PublicKeyCredentialSource $source): void
    {
        // Check if credential already exists
        $existing = $this->findByCredentialId(base64_encode($source->publicKeyCredentialId));
        
        if ($existing) {
            // Update existing credential
            $existing->setCredentialSource($source);
            $credential = $existing;
        } else {
            // Create new credential
            $credential = new WebauthnCredential();
            $credential->setUser($user);
            $this->getEntityManager()->persist($credential);
        }
        
        $credential->setCredentialSource($source);
        $this->getEntityManager()->flush();
    }
}
