<?php
namespace AppBundle\Controller\ui;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Psr\Log\LoggerInterface;
use AppBundle\Service\HttpHelper;

class DictionaryController extends Controller implements TokenAuthenticatedController {
    /* DELETE
      public function connect(Application $app)
      {
      // creates a new controller based on the default route
      $controllers->get('/', 'Controllers\DictionaryController::dashboardAction')->bind('dashboard');
      $controllers->post('/dictionaries', 'Controllers\DictionaryController::uploadAction')->bind('upload');
      $controllers->get('/dictionaries/{dictname}', 'Controllers\DictionaryController::downloadAction')->bind('downloadDictionary');
      $controllers->delete('/dictionaries/{dictname}', 'Controllers\DictionaryController::deleteAction')->bind('deleteDictionary');

      $controllers->before(function(Request $request, Application $app) {
      $accesss_token = "";
      if (!$request->cookies->has('access_token')) {
      return $app->redirect($app["url_generator"]->generate('login'));
      }
      });
      return $controllers;
      }
     */

    /**
     * @Route("/dashboard", name="dashboard", methods={"GET"})
     */
    public function dashboardAction(Request $request) {
        $client = $this->get(HttpHelper::class);
        //set the oauth token
        $access_token = $request->cookies->has('access_token') ? $request->cookies->get('access_token') : "";
        $authHeader = 'Bearer ' . $access_token;
        $dictionariesEndpoint = $this->getParameter('cspro_rest_api_url') . 'dictionaries/';

        $response = $client->request('GET', 'dictionaries/', null, ['Authorization' => $authHeader, 'Accept' => 'application/json']);

        //unauthorized or expired  redirect to logout page
        if ($response->getStatusCode() == 401) {
            return $this->redirectToRoute('logout');
        }
        $dictionaries = json_decode($response->getBody());
        return $this->render('data.twig', array('dictionaries' => $dictionaries));
    }

    /**
     * @Route("/dashboard/dictionaries/{dictname}", name="downloadDictionary", methods={"GET"})
     */
    public function downloadAction(Request $request, $dictname) {
        $client = $this->get(HttpHelper::class);
        //set the oauth token
        $access_token = $request->cookies->has('access_token') ? $request->cookies->get('access_token') : "";
        $authHeader = 'Bearer ' . $access_token;
        //download the data
        $response = $client->request('GET', 'dictionaries/' . $dictname . '/syncspec', null, ['Authorization' => $authHeader, 'Accept' => 'application/json']);

        //unauthorized or expired  redirect to logout page
        if ($response->getStatusCode() == 401) {
            return $this->redirectToRoute('logout');
        }

        $downloadResponse = new Response($response->getBody(), $response->getStatusCode());
        if(isset($response->getHeader('Content-Disposition')[0])){
            $downloadResponse->headers->set('Content-Disposition', $response->getHeader('Content-Disposition')[0]);
        }
        return $downloadResponse;
    }
    /**
     * @Route("/dashboard/dictionaries", name="upload", methods={"POST"})
     */
    public function uploadAction(Request $request) {
        $client = $this->get(HttpHelper::class);
        //set the oauth token
        $access_token = $request->cookies->has('access_token') ? $request->cookies->get('access_token') : "";
        $authHeader = 'Bearer ' . $access_token;

        //get the json user info to add 
        $body = $request->getContent();

        //upload dictionary
        $response = $client->request('POST', 'dictionaries/', $body, ['Authorization' => $authHeader, 'Content-Type' => 'text/plain; charset=utf-8']);

        //unauthorized or expired  redirect to logout page
        if ($response->getStatusCode() == 401) {
            return $this->redirectToRoute('logout');
        }

        //create a symfony response object to return
        $uploadResponse = new Response($response->getBody(), $response->getStatusCode());
        $uploadResponse->headers->set('Content-Type', $response->getHeader('Content-Type'));
        return $uploadResponse;
    }

    /**
     * @Route("/dashboard/dictionaries/{dictname}", name="deleteDictionary", methods={"DELETE"})
     */
    public function deleteAction(Request $request, $dictname) {
        $client = $this->get(HttpHelper::class);
        //set the oauth token
        $access_token = $request->cookies->has('access_token') ? $request->cookies->get('access_token') : "";
        $authHeader = 'Bearer ' . $access_token;
        //download the data
        $response = $client->request('DELETE', 'dictionaries/' . $dictname, null, ['Authorization' => $authHeader, 'Accept' => 'application/json']);

        //unauthorized or expired  redirect to logout page
        if ($response->getStatusCode() == 401) {
            return $this->redirectToRoute('logout');
        }

        //create a symfony response object to return
        $deleteResponse = new Response($response->getBody(), $response->getStatusCode());
        $deleteResponse->headers->set('Content-Type', $response->getHeader('Content-Type'));
        return $deleteResponse;
    }

}
