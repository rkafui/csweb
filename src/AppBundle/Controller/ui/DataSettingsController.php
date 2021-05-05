<?php

namespace AppBundle\Controller\ui;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Psr\Log\LoggerInterface;
use AppBundle\Service\HttpHelper;
use AppBundle\Service\PdoHelper;
use AppBundle\CSPro\Data\DataSettings;
use AppBundle\CSPro\CSProResponse;

/**
 * Description of DataSettingsController
 *
 * @author savy
 */
class DataSettingsController extends Controller implements TokenAuthenticatedController {

    private $client;
    private $logger;
    private $pdo;
    private $dataSettings;

    public function __construct(HttpHelper $client, PdoHelper $pdo, LoggerInterface $logger) {
        $this->client = $client;
        $this->logger = $logger;
        $this->pdo = $pdo;
    }

//overrider the setcontainer to get access to container parameters and initiailize the roles repository
    public function setContainer(ContainerInterface $container = null) {
        parent::setContainer($container);
        $this->dataSettings = new DataSettings($this->pdo, $this->logger);
    }

    /**
     * @Route("/dataSettings", name="dataSettings", methods={"GET"})
     */
    public function viewDataSettingsAction(Request $request) {
// Set the oauth token
        $access_token = $request->cookies->has('access_token') ? $request->cookies->get('access_token') : "";
        $authHeader = 'Bearer ' . $access_token;
        $dataSettings = $this->dataSettings->getDataSettings();
        return $this->render('dataSettings.twig', array('dataSettings' => $dataSettings));
    }

    /**
     * @Route("/getSettings", name="getSettings", methods={"GET"})
     */
    public function getDataSettings(Request $request) {
//get data settings
        $dataSettings = $this->dataSettings->getDataSettings();
        return $this->render('dataSettings.twig', array('dataSettings' => $dataSettings));
    }

    /**
     * @Route("/addSetting", name="addSetting", methods={"POST"})
     */
    public function addDataSetting(Request $request) {
        //get the json setting  info to add
        $body = $request->getContent();
        $dataSetting = json_decode($body, true);
        $label = $dataSetting['label'];
        try {
            $isAddded = $this->dataSettings->addDataSetting($dataSetting);

            if ($isAddded === true) {
                $result['description'] = "Added configuration for $label";
                $result['code'] = 200;
                $response = new Response(json_encode($result), 200);
            } else {
                $result['description'] = "Failed to add  configuration for $label";
                $result['code'] = 500;
                $response = new Response(json_encode($result), 500);
            }
        } catch (\Exception $e) {
            $errMsg = $e->getMessage();
            $pattern = "/(?<=SQLSTATE\[HY\d{3}\]\s\[\d{4}\]).*/";
            $match =  preg_match($pattern, $errMsg, $matchStr); 
            if ($match) {
                $errMsg = $matchStr[0];
            }
            $result['description'] = "Failed to add  configuration for $label. $errMsg";
            $result['code'] = 500;
            $response = new Response(json_encode($result), 500);
            $this->logger->error("Failed adding configuration", array("context" => (string) $e));
            return $response;
        }
        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

    /**
     * @Route("/updateSetting", name="updateSetting", methods={"PUT"})
     */
    public function updateDataSetting(Request $request) {
        //get the json setting  info to add
        $body = $request->getContent();
        $dataSetting = json_decode($body, true);
        $label = $dataSetting['label'];
        try {
            $isAddded = $this->dataSettings->updateDataSetting($dataSetting);

            if ($isAddded === true) {
                $result['description'] = "Updated configuration for $label";
                $result['code'] = 200;
                $response = new Response(json_encode($result), 200);
            } else {
                $result['description'] = "Failed to update configuration for $label";
                $result['code'] = 500;
                $response = new Response(json_encode($result), 500);
            }
        } catch (\Exception $e) {
            $errMsg = $e->getMessage();
            $pattern = "/(?<=SQLSTATE\[HY\d{3}\]\s\[\d{4}\]).*/";
            $match =  preg_match($pattern, $errMsg, $matchStr); 
            if ($match) {
                $errMsg = $matchStr[0];
            }
            $result['description'] = "Failed to update  configuration for $label. $errMsg";
            $result['code'] = 500;
            $response = new Response(json_encode($result), 500);
            $this->logger->error("Failed updating configuration", array("context" => (string) $e));
            return $response;
        }
        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

    /**
     * @Route("/dataSettings/{dictionaryId}", name="deleteSetting", methods={"DELETE"})
     */
    function deleteSetting(Request $request, $dictionaryId) {
        try {
            $isDeleted = $this->dataSettings->deleteDataSetting($dictionaryId);

            if ($isDeleted) {
                $result['description'] = 'Deleted configuration. Dictionary Id: ' . $dictionaryId;
                $result['code'] = 200;
                $this->logger->debug($result['description']);
                $response = new Response(json_encode($result), 200);
            } else {
                $result['description'] = 'Failed deleting configuration. Dictionary Id: ' . $dictionaryId;
                $result['code'] = 500;
                $response = new Response(json_encode($result), 500);
            }
        } catch (\Exception $e) {
            $result['description'] = 'Failed deleting configuration. Dictionary Id: ' . $dictionaryId;
            $result['code'] = 500;
            $response = new Response(json_encode($result), 500);
        }
        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

}
