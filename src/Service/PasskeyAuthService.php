<?php
namespace App\Service;

use App\Entity\User;
use App\Repository\WebauthnCredentialRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\Bundle\Service\PublicKeyCredentialCreationOptionsFactory;
use Webauthn\Bundle\Service\PublicKeyCredentialRequestOptionsFactory;
use Webauthn\PublicKeyCredentialUserEntity;

class PasskeyAuthService
{
    public function __construct(
        private PublicKeyCredentialCreationOptionsFactory $creationFactory,
        private PublicKeyCredentialRequestOptionsFactory $requestFactory,
        private AuthenticatorAttestationResponseValidator $attestationValidator,
        private AuthenticatorAssertionResponseValidator $assertionValidator,
        private SerializerInterface $serializer,
        private RequestStack $requestStack,
        private WebauthnCredentialRepository $credRepo
    ) {}

    public function getRegistrationOptions(User $user): array
    {
        $userEntity = new PublicKeyCredentialUserEntity(
            $user->getEmail(),
            $user->getId()->toBinary(),
            $user->getEmail()
        );

        $options = $this->creationFactory->create(
            'default',
            $userEntity,
            $this->getExcludedCredentials($user)
        );

        $this->requestStack->getSession()->set('webauthn_registration', $options);
        return json_decode($this->serializer->serialize($options, 'json'), true);
    }

    public function verifyRegistration(string $responseJson, User $user): void
    {
        $options = $this->requestStack->getSession()->get('webauthn_registration');
        if (!$options) {
            throw new \Exception("Pas d'options d'enregistrement en session");
        }

        /** @var \Webauthn\PublicKeyCredential $publicKeyCredential */
        $publicKeyCredential = $this->serializer->deserialize($responseJson, \Webauthn\PublicKeyCredential::class, 'json');
        
        $attestationResponse = $publicKeyCredential->response;
        if (!$attestationResponse instanceof AuthenticatorAttestationResponse) {
            throw new \Exception("Invalid response type");
        }

        $request = $this->requestStack->getCurrentRequest();
        $credentialSource = $this->attestationValidator->check(
            $attestationResponse,
            $options,
            $request->getHost()
        );

        $this->credRepo->saveCredential($user, $credentialSource);
        $this->requestStack->getSession()->remove('webauthn_registration');
    }

    public function getLoginOptions(): array
    {
        $options = $this->requestFactory->create('default', []);
        $this->requestStack->getSession()->set('webauthn_login', $options);
        return json_decode($this->serializer->serialize($options, 'json'), true);
    }

    public function verifyLogin(string $responseJson): User
    {
        $options = $this->requestStack->getSession()->get('webauthn_login');
        if (!$options) {
            throw new \Exception("Pas d'options de login en session");
        }

        /** @var \Webauthn\PublicKeyCredential $publicKeyCredential */
        $publicKeyCredential = $this->serializer->deserialize($responseJson, \Webauthn\PublicKeyCredential::class, 'json');
        
        $assertionResponse = $publicKeyCredential->response;
        if (!$assertionResponse instanceof AuthenticatorAssertionResponse) {
            throw new \Exception("Invalid response type");
        }

        $request = $this->requestStack->getCurrentRequest();

        $source = $this->credRepo->findOneByCredentialId(base64_encode($publicKeyCredential->rawId));
        if (!$source) {
            throw new \Exception("Credential not found");
        }

        $credentialSource = $this->assertionValidator->check(
            $source,
            $assertionResponse,
            $options,
            $request->getHost(),
            $assertionResponse->userHandle ?? null
        );

        // Fetch User by finding Credential
        $entity = $this->credRepo->findByCredentialId(base64_encode($credentialSource->publicKeyCredentialId));
        
        if (!$entity) {
            throw new \Exception("Credential not found");
        }

        $entity->touch(); // Update last usage
        $this->requestStack->getSession()->remove('webauthn_login');

        return $entity->getUser();
    }

    private function getExcludedCredentials(User $user): array
    {
        // For simplicity returning empty. If you want to prevent double-registration:
        // return array_map(fn($c) => new \Webauthn\PublicKeyCredentialDescriptor('public-key', $c->getCredentialId()), $user->getCredentials()->toArray());
        return [];
    }
}
