<?php

namespace App\Controller;

use App\Entity\Account;
use App\Entity\Login;
use App\Entity\Role;
use App\Repository\AccountRepository;
use App\Service\Functions;
use Doctrine\ORM\EntityManagerInterface;
use League\OAuth2\Client\Provider\GenericProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use TheNetworg\OAuth2\Client\Provider\Azure;
use DateTime;

class Auth extends AbstractController
{
    private EntityManagerInterface $entityManager;

    private Functions $functions;

    private AccountRepository $accountRepository;

    private RoleRepository $roleRepository;

    private String $azureClientId;

    private String $azureClientSecret;

    private String $azureRedirectUri;

    private String $azureTenantId;

    public function __construct(Functions $functions, AccountRepository $accountRepository, EntityManagerInterface $entityManager, String $azureClientId, String $azureClientSecret, String $azureRedirectUri, String $azureTenantId)
    {
        $this->entityManager = $entityManager;

        $this->functions = $functions;
        $this->accountRepository = $accountRepository;

        $this->azureClientId = $azureClientId;
        $this->azureClientSecret = $azureClientSecret;
        $this->azureRedirectUri = $azureRedirectUri;
        $this->azureTenantId = $azureTenantId;
    }

    #[Route('/login/microsoft')]
    public function microsoftLogin(): RedirectResponse
    {
        $provider = new Azure([
            'clientId' => $_ENV['AZURE_CLIENT_ID'],
            'clientSecret' => $_ENV['AZURE_CLIENT_SECRET'],
            'redirectUri' => $_ENV['AZURE_REDIRECT_URI'],
            'urlAuthorize' => 'https://login.microsoftonline.com/' . $this->azureTenantId . '/oauth2/v2.0/authorize',
            'urlAccessToken' => 'https://login.microsoftonline.com/' . $this->azureTenantId . '/oauth2/v2.0/token',
            'urlResourceOwnerDetails' => '',
            'scopes' => ['User.Read.All', 'https://graph.microsoft.com/.default'],
        ]);

        $authUrl = $provider->getAuthorizationUrl();
        $_SESSION['oauth2state'] = $provider->getState();

        return new RedirectResponse($authUrl);
    }

    #[Route('/login/microsoft/callback')]
    public function microsoftCallback(Request $request): RedirectResponse
    {
        $provider = new Azure([
            'clientId' => $_ENV['AZURE_CLIENT_ID'],
            'clientSecret' => $_ENV['AZURE_CLIENT_SECRET'],
            'redirectUri' => $_ENV['AZURE_REDIRECT_URI']
        ]);

        $baseGraphUri = $provider->getRootMicrosoftGraphUri(null);
        $provider->scope = 'openid profile email offline_access ' . $baseGraphUri . '/User.Read';

        $code = $request->query->get('code');
        $state = $request->query->get('state');

        if (empty($state) || ($state !== $_SESSION['oauth2state'])) {
            unset($_SESSION['oauth2state']);
            exit('State value does not match the one initially sent');
        }

        $token = $provider->getAccessToken('authorization_code', [
            'code' => $code,
        ]);

        // --

        $user = $provider->getResourceOwner($token)->toArray();
        $msoId = $user['oid'];

        $account = $this->accountRepository->findOneByMSOID($msoId);

        if ($account)  // the user has an account, we open the session and register the connection
        {
            // Est ce que le compte est bien actif ? (isIsActive)
            if (!$account->isIsActive()) {
                return new RedirectResponse('/login?is_active=false');
            }

            $login = new Login();
            $date = new DateTime();
            $login->setAccount($account);
            $login->setDatetime($date);
            $login->setIp($user['ipaddr']);

            $this->entityManager->persist($login);
            $this->entityManager->flush();

            // getAll roles in a list
            $roles = [];
            foreach ($account->getRole() as $_role) {
                $roles[] = $_role->getRole();
            }
            $_SESSION['account_id'] = $account->getId();
            $_SESSION['family_name'] = $account->getFamilyName();
            $_SESSION['given_name'] = $account->getGivenName();
            $_SESSION['role'] = $roles;
            $_SESSION['upn'] = $account->getEmail();

            return new RedirectResponse('/home');
        } else {  // the user doesn't have an account, so we create one for him and give him the ‘default’ role
            $account = new Account();
            $account->setMSOID($msoId);
            $account->setFamilyName($user['family_name']);
            $account->setGivenName($user['given_name']);
            $account->setEmail($user['upn']);

            $role = new Role();
            $role->setRole('default');
            $account->addRole($role);

            $login = new Login();
            $date = new DateTime();
            $login->setAccount($account);
            $login->setDatetime($date);
            $login->setIp($user['ipaddr']);

            $this->entityManager->persist($role);
            $this->entityManager->persist($account);
            $this->entityManager->persist($login);
            $this->entityManager->flush();

            // Création de la session
            // getAll roles in a list
            $roles = [];
            foreach ($account->getRole() as $_role) {
                $roles[] = $_role->getRole();
            }
            $_SESSION['account_id'] = $account->getId();
            $_SESSION['family_name'] = $account->getFamilyName();
            $_SESSION['given_name'] = $account->getGivenName();
            $_SESSION['role'] = $roles;
            $_SESSION['upn'] = $account->getEmail();
            return new RedirectResponse('/home');
        }
    }
}
