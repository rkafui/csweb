<?php

namespace AppBundle\Controller\ui;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\PreAuthenticatedToken;
use Psr\Log\LoggerInterface;
use AppBundle\Service\HttpHelper;
use AppBundle\Security\ApiKeyUserProvider;
use AppBundle\CSPro\User\User;

class LoginController extends Controller {

    private $client;
    private $userName;
    private $lastError;
    private $lastErrorDetails;
    private $logger;
    private $apikeyUserProvider;
    private $tokenStorage;

    public function __construct(HttpHelper $client, TokenStorageInterface $tokenStorage, ApiKeyUserProvider $keyUserProvider, LoggerInterface $logger) {
        $this->client = $client;
        $this->logger = $logger;
        $this->apikeyUserProvider = $keyUserProvider;
        $this->tokenStorage = $tokenStorage;
    }

    /**
     * @Route("/", methods={"GET"})
     * @Route("/",name="login", methods={"GET"})
     */
    public function login() {
        $configExists = file_exists(realpath(__DIR__ . '/../../config.php'));
        if ($configExists === false) {
            return new Response('This application needs to be configured. Please run <a href="/setup/index.php">setup</a>', 403);
        }
        return $this->render('login.twig', array(
                    'error' => $this->lastError,
                    'errorDetails' => $this->lastErrorDetails,
                    'last_username' => $this->userName,
        ));
    }

    /**
     * @Route("/login_check", name="login_check", methods={"POST"})
     */
    public function loginAction(Request $request) {
        $this->userName = trim($_POST['_username']);
        
        $password = trim($_POST['_password']);
        $requestBody = json_encode(array("client_id" => "cspro_android",
            "client_secret" => "cspro",
            "grant_type" => "password",
            "username" => $this->userName,
            "password" => $password));
        $response = $this->client->request('POST', 'token', $requestBody, ['Content-Type' => 'application/json',
            'Accept' => 'application/json']);

        $this->logger->debug('login user "' . $this->userName . '" status='. $response->getStatusCode());
        $jsonResponse = json_decode($response->getBody(), true);

        //if the authentication failed.  redirect to the login page and set the lastError and the lastUserName 
        if (isset($jsonResponse['error_description'])) {
            $this->lastError = $jsonResponse['error_description'];
            $this->logger->warn('Login failed:' . $this->lastError);
            return $this->login();
        } else if ($response->getStatusCode() == 401 && isset($jsonResponse['message'])) {
            $this->lastError = $jsonResponse['message'];
            $this->logger->warn('Login failed:' . $this->lastError);
            return $this->login();
        } else if ($response->getStatusCode() == 200 && isset($jsonResponse['access_token'])) {
            $apiKey = $jsonResponse['access_token'];
            $user = $this->apikeyUserProvider->loadUserByApiKey($apiKey);
            $roles = $user->getRoles();
            $providerKey = 'cspro_oauth_provider';
            $tokenStorage = new PreAuthenticatedToken(
                    $user, $apiKey, $providerKey, $roles
            );
            //set tokenstorage for authorization
            $this->container->get('security.token_storage')->setToken($tokenStorage);

            if (!$this->canUserLogin($user)) {
               return $this->login(); //redirect ??
            }
            
            //check if the access_token in available and then set the cookie 
            //if it succeeds redirect to the targetpath with the cookie set correctly with the token.
            $targetPath = $this->getLandingPage();
            $response = $this->redirect($targetPath);
            $response->headers->setCookie(new Cookie("access_token", $jsonResponse['access_token']));
            $response->headers->setCookie(new Cookie("username", $this->userName));
            return $response;
        } else {
            $this->logger->error('Failed to contact API:' . $response->getBody());
            $this->lastError = 'Failed to contact authentication server. Please contact your site administrator.';
            $this->lastErrorDetails = "Error code: {$response->getStatusCode()}. Message: {$response->getBody()}";
            return $this->login(); //redirect ??
        }
    }

    public function canUserLogin($user) {
        //check is granted for the user
        $hasLoginAccess = $this->isGranted('ROLE_USERS_ALL') || $this->isGranted('ROLE_DATA_ALL') ||
                $this->isGranted('ROLE_REPORTS_ALL') || $this->isGranted('ROLE_APPS_ALL') || $this->isGranted('ROLE_ROLES_ALL')
                || $this->isGranted('ROLE_SETTINGS_ALL');
        
        if (empty($user)) {
            $this->logger->warn('Login failed. User not found.');
            $this->lastError = 'Login failed. Unable to retrieve user information';
        } 
        elseif ($user->getRoleId() == User::STANDARD_USER){
           $this->logger->warn('Login failed. User is standard user.');
           $this->lastError = 'Login failed. Insufficient privileges.';
       }
        elseif  ($hasLoginAccess || $user->getRoleId() == User::ADMINISTRATOR) {//built-in administrator
            return true;
        }
        else { //any other user
            $this->logger->warn('Login failed. User does not have login access. Role='. $user->getRoleId());
            $this->lastError = 'Login failed. Insufficient privileges.';
        }
        return false;
    }

    /**
     * @Route("/logout", name="logout", methods={"GET"})
     */
    public function logoutAction() {
        $this->lastError = "";
        $this->lastErrorDetails = "";
        $this->userName = "";
        $response = $this->redirectToRoute('login');
        $response->headers->clearCookie('access_token');
        $response->headers->clearCookie('username');
        return $response;
    }

   public function getLandingPage(){
       $targetPath = '';
       if($this->isGranted('ROLE_DATA_ALL')){
           $targetPath = 'dashboard' ;
       }
       elseif($this->isGranted('ROLE_REPORTS_ALL')){
           $targetPath = 'sync-report' ; 
       }
       elseif($this->isGranted('ROLE_APPS_ALL')){
           $targetPath = 'apps' ; 
       }
       elseif($this->isGranted('ROLE_USERS_ALL')){
           $targetPath = 'users' ; 
       }
       elseif($this->isGranted('ROLE_ROLES_ALL')){
           $targetPath = 'roles' ; 
       }
       return $targetPath;
   }
}
