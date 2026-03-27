<?php
namespace App\Controller;

use App\Entity\User;
use App\Service\PasskeyAuthService;
use Gesdinet\JWTRefreshTokenBundle\Entity\RefreshToken;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\ORM\EntityManagerInterface;

#[Route('/api/auth')]
class AuthApiController extends AbstractController
{
    public function __construct(
        private JWTTokenManagerInterface $jwtManager,
        private RefreshTokenManagerInterface $refreshManager,
        private EntityManagerInterface $entityManager
    ) {}

    #[Route('/register/options', methods: ['POST'])]
    public function registerOptions(
        Request $request,
        PasskeyAuthService $passkeyService
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        $email = $data['email'] ?? null;

        if (!$email) {
            return $this->json(['error' => 'Email requis'], Response::HTTP_BAD_REQUEST);
        }

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]) ?? new User($email);

        // Pre-persist new user so ID constraint isn't violated.
        if (!$user->getId() || !$this->entityManager->contains($user)) {
             $this->entityManager->persist($user);
             $this->entityManager->flush();
        }

        try {
            $options = $passkeyService->getRegistrationOptions($user);
            return $this->json($options);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/register/verify', methods: ['POST'])]
    public function registerVerify(
        Request $request,
        PasskeyAuthService $passkeyService
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        $email = $data['email'] ?? null;
        $credential = $data['credential'] ?? null;

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

        if (!$user || !$credential) {
            return $this->json(['error' => 'Données invalides'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $passkeyService->verifyRegistration(json_encode($credential), $user);

            $jwt = $this->jwtManager->create($user);
            $refreshTokenString = bin2hex(random_bytes(32));
            $refresh = RefreshToken::createForUserWithTtl($refreshTokenString, $user, 2592000); // 30 days
            $this->refreshManager->save($refresh);

            return $this->json([
                'success' => true,
                'token' => $jwt,
                'refresh_token' => $refresh->getRefreshToken(),
                'user' => [
                    'id' => $user->getId(),
                    'email' => $user->getEmail()
                ]
            ]);
        } catch (\Exception $e) {
            error_log('Registration Error: ' . $e->getMessage() . ' - ' . $e->getTraceAsString());
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/login/options', methods: ['POST'])]
    public function loginOptions(PasskeyAuthService $passkeyService): JsonResponse
    {
        try {
            return $this->json($passkeyService->getLoginOptions());
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/login/verify', methods: ['POST'])]
    public function loginVerify(
        Request $request,
        PasskeyAuthService $passkeyService
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        $credential = $data['credential'] ?? null;

        if (!$credential) {
            return $this->json(['error' => 'Credential requis'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $user = $passkeyService->verifyLogin(json_encode($credential));

            $jwt = $this->jwtManager->create($user);
            $refreshTokenString = bin2hex(random_bytes(32));
            $refresh = RefreshToken::createForUserWithTtl($refreshTokenString, $user, 2592000); // 30 days
            $this->refreshManager->save($refresh);

            return $this->json([
                'success' => true,
                'token' => $jwt,
                'refresh_token' => $refresh->getRefreshToken(),
                'user' => [
                    'id' => $user->getId(),
                    'email' => $user->getEmail()
                ]
            ]);
        } catch (\Exception $e) {
            error_log('Login Error: ' . $e->getMessage() . ' - ' . $e->getTraceAsString());
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/refresh', methods: ['POST'])]
    public function refreshToken(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $refreshTokenString = $data['refresh_token'] ?? null;

        if (!$refreshTokenString) {
            return $this->json(['error' => 'Refresh token requis'], Response::HTTP_BAD_REQUEST);
        }

        $refreshToken = $this->refreshManager->get($refreshTokenString);
        
        if (!$refreshToken || !$refreshToken->isValid()) {
            return $this->json(['error' => 'Invalid refresh token'], Response::HTTP_UNAUTHORIZED);
        }

        $user = $refreshToken->getUser();
        
        if (!$user) {
            return $this->json(['error' => 'User not found'], Response::HTTP_UNAUTHORIZED);
        }

        // Invalidate old refresh token and create new one
        $this->refreshManager->delete($refreshToken);
        
        $jwt = $this->jwtManager->create($user);
        $newRefreshTokenString = bin2hex(random_bytes(32));
        $newRefresh = RefreshToken::createForUserWithTtl($newRefreshTokenString, $user, 2592000); // 30 days
        $this->refreshManager->save($newRefresh);

        return $this->json([
            'token' => $jwt,
            'refresh_token' => $newRefresh->getRefreshToken()
        ]);
    }
}
