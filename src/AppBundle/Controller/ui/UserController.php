<?php

namespace AppBundle\Controller\ui;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Psr\Log\LoggerInterface;
use AppBundle\Service\HttpHelper;
use AppBundle\CSPro\RolesRepository;
use AppBundle\Service\PdoHelper;

class UserController extends Controller implements TokenAuthenticatedController {

    private $client;
    private $logger;
    private $rolesRepository;
    private $pdo;

    public function __construct(HttpHelper $client, PdoHelper $pdo, LoggerInterface $logger) {
        $this->client = $client;
        $this->logger = $logger;
        $this->pdo = $pdo;
    }

    //overrider the setcontainer to get access to container parameters and initiailize the roles repository
    public function setContainer(ContainerInterface $container = null) {
        parent::setContainer($container);
        $this->rolesRepository = new RolesRepository($this->pdo, $this->logger);
    }
    
    /**
     * @Route("/users/", name="users", methods={"GET"})
     */
    public function viewUserListAction(Request $request) {
        $this->logger->debug('processing view user list');
        //set the oauth token
        $access_token = $request->cookies->has('access_token') ? $request->cookies->get('access_token') : "";
        $authHeader = 'Bearer ' . $access_token;
        $response = $this->client->request('GET', 'users', null, ['Authorization' => $authHeader, 'Accept' => 'application/json']);

        //unauthorized or expired  redirect to logout page
        if ($response->getStatusCode() == 401) {
            return $this->redirectToRoute('logout');
        }
        $userlist = json_decode($response->getBody());
        $rolesList = $this->rolesRepository->getRoles();

        return $this->render('users.twig', array('userlist' => $userlist,"rolesList" => $rolesList));
    }

    /**
     * @Route("/users/json", name="usersJson", methods={"GET"})
     */
    public function viewUserListJson(Request $request) {
        // set client
        //set the oauth token
        $access_token = $request->cookies->has('access_token') ? $request->cookies->get('access_token') : "";

        $start = $request->get('start');
        $length = $request->get('length');
        $draw = (int) $request->get('draw');
        $search = $request->get('search');
        $order = $request->get('order');
        $orderColumn = $order[0]['column'];
        $orderDirection = $order[0]['dir'];

        // set authorization header
        $authHeader = 'Bearer ' . $access_token;

        $apiResponse = $this->client->request('GET', 'users', null, ['Authorization' => $authHeader,
            'Accept' => 'application/json',
            'x-csw-user-start' => $start,
            'x-csw-user-length' => $length,
            'x-csw-user-search' => $search['value'],
            'x-csw-user-order-column' => $orderColumn,
            'x-csw-user-order-direction' => $orderDirection
        ]);

        //unauthorized or expired  redirect to logout page
        if ($apiResponse->getStatusCode() == 401) {
            return $this->redirectToRoute('logout');
        }
        $headers = $apiResponse->getHeaders();
        $userCount = 0;
        $usersFiltered = 0;

        //user count
        if (array_key_exists('x-csw-user-count', $headers)) {
            $temp = $headers['x-csw-user-count'];
            $userCount = $temp[0];
        } else
            $userCount = 0;

        //users filtered
        if (array_key_exists('x-csw-users-filtered', $headers)) {
            $temp = $headers['x-csw-users-filtered'];
            $usersFiltered = $temp[0];
        } else
            $usersFiltered = 0;

        $userlist = json_decode($apiResponse->getBody());
        
        $rolesList = $this->rolesRepository->getRoles();
         
        $result = array("data" => $userlist,"rolesList" => $rolesList, "draw" => $draw, "recordsTotal" => $userCount, "recordsFiltered" => $usersFiltered);

        $response = new Response(json_encode($result));
        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

    /**
     * @Route("/users/", name="add", methods={"POST"})
     */
    public function addUserAction(Request $request) {

        $maxScriptExecutionTime = $this->container->getParameter('csweb_max_script_execution_time');
        ini_set('max_execution_time', $maxScriptExecutionTime);
        //set the oauth token
        $access_token = $request->cookies->has('access_token') ? $request->cookies->get('access_token') : "";
        $authHeader = 'Bearer ' . $access_token;

        //get the json user info to add
        $body = $request->getContent();

        //call the rest api to  add the user	    
        $response = $this->client->request('POST', 'users', $body, ['Authorization' => $authHeader, 'Content-Type' => 'application/json', 'Accept' => 'application/json', 'Time-Stamp' => $request->headers->get("TIME-STAMP")]);                       //unauthorized or expired  redirect to logout page
        $this->logger->debug('**********addUserAction-UI status-code: ' . $response->getStatusCode());
        if ($response->getStatusCode() == 401) {
            return $this->redirectToRoute('logout');
        }
        //create a symfony response object to return
        $addUserResponse = new Response($response->getBody(), $response->getStatusCode());
        $addUserResponse->headers->set('Content-Type', $response->getHeader('Content-Type'));
        return $addUserResponse;
    }

    /**
     * @Route("/users/{username}", name="update", methods={"PUT"})
     */
    public function updateUserAction(Request $request, $username) {
        //set the oauth token
        $access_token = $request->cookies->has('access_token') ? $request->cookies->get('access_token') : "";
        $authHeader = 'Bearer ' . $access_token;

        //get the json user info to update 
        $body = $request->getContent();
        //call the rest api to  update the user
        $response = $this->client->request('PUT', 'users/' . $username, $body, ['Authorization' => $authHeader]);
		
		//unauthorized or expired  redirect to logout page
        if ($response->getStatusCode() == 401) {
            return $this->redirectToRoute('logout');
        }
        //create a symfony response object to return
        $updateUserResponse = new Response($response->getBody(), $response->getStatusCode());
        $updateUserResponse->headers->set('Content-Type', $response->getHeader('Content-Type'));
        return $updateUserResponse;
    }

    /**
     * @Route("/users/{username}", name="delete", methods={"DELETE"})
     */
    public function deleteAction(Request $request, $username) {
        //set the oauth token
        $access_token = $request->cookies->has('access_token') ? $request->cookies->get('access_token') : "";
        $authHeader = 'Bearer ' . $access_token;

        //call the rest-api to delete the user
        $response = $this->client->request('DELETE', 'users/' . $username, null, ['Authorization' => $authHeader, 'Accept' => 'application/json']);
		
		//unauthorized or expired  redirect to logout page
        if ($response->getStatusCode() == 401) {
            return $this->redirectToRoute('logout');
        }
        //create the symfony response for the delete action
        $deleteResponse = new Response($response->getBody(), $response->getStatusCode());
        $deleteResponse->headers->set('Content-Type', $response->getHeader('Content-Type'));
        return $deleteResponse;
    }

}
