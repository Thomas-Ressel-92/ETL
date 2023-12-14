<?php
namespace axenox\ETL\Facades;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use axenox\ETL\Actions\RunETLFlow;
use axenox\ETL\DataTypes\WebRequestStatusDataType;
use exface\Core\CommonLogic\Selectors\ActionSelector;
use exface\Core\CommonLogic\Tasks\HttpTask;
use exface\Core\DataTypes\JsonDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Exceptions\InternalError;
use exface\Core\Exceptions\Facades\FacadeRoutingError;
use exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade;
use exface\Core\Facades\AbstractHttpFacade\Middleware\AuthenticationMiddleware;
use exface\Core\Factories\ActionFactory;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\Exceptions\ExceptionInterface;
use exface\Core\Interfaces\Tasks\ResultInterface;
use JsonSchema\Validator;

/**
 *
 *
 * @author Andrej Kabachnik
 *
 */
class DataFlowFacade extends AbstractHttpFacade
{
    private $serviceData = null;

    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade::createResponse()
     */
    protected function createResponse(ServerRequestInterface $request) : ResponseInterface
    {
    	$requestLogData = $this->logRequestReceived($request);
    	$headers = $this->buildHeadersCommon();

        try {
            $path = $request->getUri()->getPath();

            $path = StringDataType::substringAfter($path, $this->getUrlRouteDefault() . '/', '');
            $routeModel = $this->getRouteData($path);

            // validate webservice swagger
            $response = $this->getSwaggerValidatorResponse($routeModel, $requestLogData, $headers);
            if ($response !== null){
            	return $response;
            }


            // handle route requests
            switch(true){
            	// webservice maintenance requests
            	case mb_stripos($path, '/openapi') !== false:
            		if ($request->getMethod() == 'GET'){
            			$response = new Response(200, $headers, $routeModel['swagger_json']);
            			$requestLogData = $this->logRequestDone($requestLogData, 'Web service swagger json has been provided.', $response);
            			return $response;
            		}
            		else if ($request->getMethod() == 'POST'){
            			$routeModel['swagger_json'] = $request->getBody();
            			$response = $this->getSwaggerValidatorResponse($routeModel, $requestLogData, $headers);
            			if ($response !== null){
            				return $response;
            			}

            			$this->updateRouteParameter($path, $routeModel, 'swagger_json');
            			$headers['Path'] = 'GET ' . $this->getUrlRouteDefault();
            			$response = new Response(201, $headers, $routeModel['swagger_json']);
            			$requestLogData = $this->logRequestDone($requestLogData, 'Web service swagger json has been updated', $response);
            			return $response;
            		}
            	// webservice dataflow request
            	default:
            		$routeUID = $routeModel['UID'];
            		$flowAlias = $routeModel['flow__alias'];
            		$flowRunUID = RunETLFlow::generateFlowRunUid();
            		$requestLogData = $this->logRequestProcessing($requestLogData, $routeUID, $flowRunUID); // flow data update
            		$flowResult = $this->runFlow($flowAlias, $request, $requestLogData); // flow data update
            		$flowOutput = $flowResult->getMessage();

            		// get changes by flow
            		$requestLogData->getFilters()->addConditionFromColumnValues($requestLogData->getUidColumn());
            		$requestLogData->getColumns()->addFromExpression('response_body');
            		$requestLogData->dataRead();

            		if ($requestLogData->countRows() == 1){
            			$response = $this->createRequestSuccessResponse($requestLogData, $headers, $routeModel);
            		}
            }
        } catch (\Throwable $e) {
        	$response = $this->createResponseFromError($e, $request, $requestLogData);
            $requestLogData = $this->logRequestFailed($requestLogData, $e, $response);
            return $response;
        }

        if ($response === null) {
			$response = new Response(200, $headers, "Dataflow successfull.");
        }

        $requestLogData = $this->logRequestDone($requestLogData, $flowOutput, $response);
        return $response;
    }

	/**
	 * @param DataSheetInterface $requestLogData
	 * @param array $headers
	 * @param array $routeModel
	 * @return ResponseInterface
	 */
	 private function createRequestSuccessResponse(DataSheetInterface $requestLogData, array $headers, array $routeModel) : ResponseInterface
	{
		$headers['Content-Type'] = 'application/json';

		if (empty($requestLogData->getRow()['response_body'])){
			$emptyResponse = "";
			$swaggerObject = json_decode($routeModel['swagger_json'] , true);
			$this->searchArrayTree("defaultResponse", $swaggerObject, $emptyResponse);
			return new Response(200, $headers, json_encode($emptyResponse["value"]));
		}

		return new Response(200, $headers, $requestLogData->getRow()['response_body']);
	}

	/**
	 * @param mixed $needle
	 * @param array $haysack
	 * @param mixed $value
	 */
    private function searchArrayTree(mixed $needle, array $haysack, mixed &$value) : void
    {
    	if (!empty($value)){
    		return;
    	}

    	if(array_key_exists($needle, $haysack) ) {
    		$value = $haysack[$needle];
    		return;
    	}

    	foreach($haysack as $child) {
    		if(gettype($child) === 'array') {
    			$this->searchArrayTree($needle, $child, $value);
    		}
    	}
    }

	/**
	 * @param string path
	 * @param array routeModel
	 * @param string response
	 */
	private function updateRouteParameter(string $path, array $routeModel, string $parameter)
	{
		$rows = $this->serviceData->getRows();
		for ($i = 0; count($rows) > $i; $i++) {
			if ($rows[$i]['in_url'] && StringDataType::startsWith($path, $rows[$i]['in_url'])) {
				$this->serviceData->setCellValue($parameter, $i, $routeModel[$parameter]);
				$this->serviceData->dataUpdate();
			}
		}
	}


	/**
	 * @param $routeModel
	 * @param requestLogData
	 * @param $headers
	 */
    private function getSwaggerValidatorResponse($routeModel, $requestLogData, $headers) : mixed
	{
		$validator = $this->validateRouteSwagger($routeModel);
		if (!$validator->isValid()){
			$response = $this->createSwaggerErrorResponse($headers, $validator->getErrors());
			$requestLogData = $this->logRequestFailed($requestLogData, new \InvalidArgumentException('Invalid swagger json.'), $response);
			return $response;
		}

		return null;
	}


    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade::getUrlRouteDefault()
     */
    public function getUrlRouteDefault(): string
    {
        return 'api/dataflow';
    }

    /**
     *
     * @param string $flowAlias
     * @param ServerRequestInterface $request
     * @param DataSheetInterface $requestLogData
     * @return ResultInterface
     */
    protected function runFlow(string $flowAlias, ServerRequestInterface $request, DataSheetInterface $requestLogData) : ResultInterface
    {
        $task = new HttpTask($this->getWorkbench(), $this, $request);
        $task->setInputData($requestLogData);

        $actionSelector = new ActionSelector($this->getWorkbench(), RunETLFlow::class);
        /* @var $action \axenox\ETL\Actions\RunETLFlow */
        $action = ActionFactory::createFromPrototype($actionSelector, $this->getApp());
        $action->setMetaObject($requestLogData->getMetaObject());
        $action->setFlowAlias($flowAlias);
        $action->setInputFlowRunUid('flow_run');
        $result = $action->handle($task);
        return $result;
    }

    /**
     *
     * @param string $routeUID
     * @param string $flowRunUID
     * @param ServerRequestInterface $request
     * @return DataSheetInterface
     */
    protected function logRequestReceived(ServerRequestInterface $request) : DataSheetInterface
    {
        $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.ETL.webservice_request');
        $ds->addRow([
            'status' => WebRequestStatusDataType::RECEIVED,
            'url' => $request->getUri()->__toString(),
            'url_path' => StringDataType::substringAfter($request->getUri()->getPath(), $this->getUrlRouteDefault() . '/', $request->getUri()->getPath()),
            'http_method' => $request->getMethod(),
            'http_headers' => JsonDataType::encodeJson($request->getHeaders()),
            'http_body' => $request->getBody()->__toString(),
            'http_content_type' => implode(';', $request->getHeader('Content-Type'))
        ]);
        $ds->dataCreate(false);
        return $ds;
    }

    /**
     *
     * @param string $routeUID
     * @param string $flowRunUID
     * @param ServerRequestInterface $request
     * @return DataSheetInterface
     */
    protected function logRequestProcessing(DataSheetInterface $requestLogData, string $routeUID, string $flowRunUID) : DataSheetInterface
    {
        $ds = $requestLogData->extractSystemColumns();
        $ds->setCellValue('route', 0, $routeUID);
        $ds->setCellValue('status', 0, WebRequestStatusDataType::PROCESSING);
        $ds->setCellValue('flow_run', 0, $flowRunUID);
        $ds->dataUpdate(false);
        return $ds;
    }

    /**
     *
     * @param string $requestLogUID
     * @param ExceptionInterface $e
     * @return DataSheetInterface
     */
    protected function logRequestFailed(DataSheetInterface $requestLogData, \Throwable $e, ResponseInterface $response = null) : DataSheetInterface
    {
        if (! ($e instanceof ExceptionInterface)) {
            $e = new InternalError($e->getMessage(), null, $e);
        }
        $this->getWorkbench()->getLogger()->logException($e);
        $ds = $requestLogData->extractSystemColumns();
        $ds->setCellValue('status', 0, WebRequestStatusDataType::ERROR);
        $ds->setCellValue('error_message', 0, $e->getMessage());
        $ds->setCellValue('error_logid', 0, $e->getId());
        $ds->setCellValue('http_response_code', 0, $response !== null ? $response->getStatusCode() : $e->getStatusCode());
        $ds->setCellValue('response_header', 0, json_encode($response->getHeaders()));
        $ds->setCellValue('response_body', 0, $response->getBody()->__toString());
        $ds->dataUpdate(false);
        return $ds;
    }

    /**
     *
     * @param string $requestLogUID
     * @param string $output
     * @return DataSheetInterface
     */
    protected function logRequestDone(DataSheetInterface $requestLogData, string $output, ResponseInterface $response) : DataSheetInterface
    {
        $ds = $requestLogData->extractSystemColumns();
        $ds->setCellValue('status', 0, WebRequestStatusDataType::DONE);
        $ds->setCellValue('result_text', 0, $output);
        $ds->setCellValue('http_response_code', 0, $response->getStatusCode());
        $ds->setCellValue('response_header', 0, json_encode($response->getHeaders()));
        $ds->setCellValue('response_body', 0, $response->getBody()->__toString());
        $ds->dataUpdate(false);
        return $ds;
    }

    /**
     *
     * @param string $route
     * @throws FacadeRoutingError
     * @return string[]
     */
    protected function getRouteData(string $route) : array
    {
        if ($this->serviceData === null) {
            $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.ETL.webservice_route');
            $ds->getColumns()->addMultiple([
                'UID',
                'flow',
                'flow__alias',
                'in_url',
            	'type__schema_json',
            	'swagger_json'
            ]);
            $ds->dataRead();
            $this->serviceData = $ds;
        }

        foreach ($this->serviceData->getRows() as $row) {
            if ($row['in_url'] && StringDataType::startsWith($route, $row['in_url'])) {
                return $row;
            }
        }

        throw new FacadeRoutingError('No route configuration found for "' . $route . '"');
    }

    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade::getMiddleware()
     */
    protected function getMiddleware() : array
    {
        $middleware = parent::getMiddleware();
        $middleware[] = new AuthenticationMiddleware(
            $this,
            [
                [AuthenticationMiddleware::class, 'extractBasicHttpAuthToken']
            ]
        );

        return $middleware;
    }

    /** Validating swagger json agains the corresonding schema json from the route type.
     *
     * @param array $routeModel
     */
    private function validateRouteSwagger(array $routeModel) : Validator
    {
    	$validator = (new Validator);
    	$validator->validate(json_decode($routeModel['swagger_json']), json_decode($routeModel['type__schema_json']));
    	return $validator;
    }


    /**
     * @param array $headers
     * @param array $errors
     */
    private function createSwaggerErrorResponse(array $headers, array $validatorErrors) : ResponseInterface
    {
    	$headers['Content-Type'] = 'application/json';
    	$errors = ['Invalid Swagger' => []];
    	foreach ($validatorErrors as $error){
    		array_push(
    			$errors['Invalid Swagger'],
    			array('source' => $error['property'], 'message' => $error['message']));
    	}

    	return new Response(400, $headers, json_encode($errors));
    }
}