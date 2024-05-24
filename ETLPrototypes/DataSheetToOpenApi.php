<?php
namespace axenox\ETL\ETLPrototypes;

use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Exceptions\NotImplementedError;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Factories\DataSheetFactory;
use axenox\ETL\Common\AbstractETLPrototype;
use axenox\ETL\Interfaces\ETLStepResultInterface;
use exface\Core\DataTypes\StringDataType;
use axenox\ETL\Common\IncrementalEtlStepResult;
use exface\Core\Interfaces\Tasks\HttpTaskInterface;
use exface\Core\Widgets\DebugMessage;
use axenox\ETL\Events\Flow\OnBeforeETLStepRun;
use axenox\ETL\Interfaces\ETLStepDataInterface;
use Flow\JSONPath\JSONPath;
use Flow\JSONPath\JSONPathException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Objects have to be defined with an x-object-alias and with x-attribute-aliases like:
 * {
 *     "Object": {
 *          "type": "object",
 *          "x-object-alias": "alias",
 *          "properties: {
 *               "Id": {
 *                   "type": "string",
 *                   "x-attribute-alias": "UID"
 *               }
 *          }
 *     }
 * }
 *
 * Attributes can be defined within the OpenApi like this:
 *  {
 *     "Id": {
 *         "type": "int",
 *         "x-attribute-alias": "UID"
 *     },
 *     "someThing": {
 *         "x-attribute-alias": "FORW_RELATION__OTHER_ATTR"
 *     },
 *     "someThing": {
 *         "x-attribute-alias": "BACKW_RELATION__OTHER_ATTR2:SUM"
 *     },
 *     "someThing": {
 *         "x-attribute-alias": "=CONCAT(ATTR1, ' ', ATTR2)"
 *     }
 *  }
 *
 * The to-object HAS to be defined within the response schema of the route to the step!
 * e.g. with multiple structural concepts
 * "responses": {
 *   "200": {
 *     "description": "Erfolgreiche Abfrage",
 *       "content": {
 *         "application/json": {
 *           "schema": {
 *             "type": "object",
 *             "properties": {
 *               "tranchen": {
 *                 "type": "object",
 *                 "properties": {
 *                   "rows": {
 *                     "type": "array",
 *                     "items": {
 *                       "$ref": "#/components/schemas/Object" // only for the example within the ui
 *                     },
 *                     "x-object-alias": "full.namespace.object" // filled with step data
 *                   }
 *                 }
 *               },
 *             "page_limit": {
 *               "type": "array",
 *               "items": {
 *                 "type": "object",
 *                 "properties": {
 *                   "offset": {
 *                     "type": "integer",
 *                     "nullable": true,
 *                     "x-placeholder": "[#~parameter:limit#]" // filled with placeholder values
 *                   }
 *                 }
 *               }
 *             },
 *            "page_offset": {
 *               "type": "integer",
 *               "nullable": true,
 *               "x-placeholder": "[#~parameter:offset#]" // filled with placeholder values
 *              }
 *            }
 *          }
 *        }
 *      }
 *    }
 *  }
 *
 * As you see in the response schema example, you can use placeholders for page_limit and offset, as well as
 * values within the response.
 *
 * @author miriam.seitz
 */
class DataSheetToOpenApi extends AbstractETLPrototype
{
    const JSON_PATH_TO_OPEN_API_SCHEMAS = '$.components.schemas';

    const OPEN_API_ATTRIBUTE_TO_OBJECT_ALIAS = 'x-object-alias';

    const OPEN_API_ATTRIBUTE_TO_ATTRIBUTE_ALIAS = 'x-attribute-alias';

    private $rowLimit = null;

    private $baseSheet = 0;

    private $rowOffset = 0;


    /**
     *
     * {@inheritDoc}
     * @throws JSONPathException
     * @see \axenox\ETL\Interfaces\ETLStepInterface::run()
     */
    public function run(ETLStepDataInterface $stepData) : \Generator
    {
    	$stepRunUid = $stepData->getStepRunUid();
    	$placeholders = $this->getPlaceholders($stepData);
    	$baseSheet = DataSheetFactory::createFromObject($this->getFromObject());
    	$result = new IncrementalEtlStepResult($stepRunUid);
        $stepTask = $stepData->getTask();

        if ($stepTask instanceof HttpTaskInterface === false){
            throw new InvalidArgumentException('Http request needed to process OpenApi definitions! Request type: ' . get_class($stepTask));
        }

        if ($limit = $this->getRowLimit($placeholders)) {
            $baseSheet->setRowsLimit($limit);
        }
        $baseSheet->setAutoCount(false);
        
        $this->baseSheet = $baseSheet;
        $this->getWorkbench()->eventManager()->dispatch(new OnBeforeETLStepRun($this));

        $offset = $this->getRowOffset($placeholders) ?? 0;
        $fromSheet = $baseSheet->copy();
        $fromSheet->setRowsOffset($offset);
        yield 'Reading '
            . ($limit ? 'rows ' . ($offset+1) . ' - ' . ($offset+$limit) : 'all rows')
            . ' requested in OpenApi definition';

        $openApiJson = $stepData->getOpenApiJson();
        $schemas = (new JSONPath(json_decode($openApiJson, false)))->find(self::JSON_PATH_TO_OPEN_API_SCHEMAS)->getData()[0];
        $schemas = json_decode(json_encode($schemas), true);

        $fromObjectSchema = $this->findObjectSchema($fromSheet, $schemas);
        foreach ($this->findAttributesInSchema($fromObjectSchema) as $propName => $attrAlias) {
            $fromSheet->getColumns()->addFromExpression($attrAlias, $propName);
        }
        $fromSheet->dataRead();

        // enforce from sheet defined data types
        $rows = $fromSheet->getRows();
        $index = 0;
        foreach ($fromSheet->getColumns() as $column) {
            $values = $column->getValuesNormalized();
            foreach ($rows as &$row) {
                $row[$column->getName()] = $values[$index];
                $index++;
            }
            $index = 0;
        }

        $requestLogData = $this->loadRequestData($stepData);
        $request = $stepTask->getHttpRequest();
        $this->updateRequestData($requestLogData, $request, $openApiJson, $rows, $fromObjectSchema[self::OPEN_API_ATTRIBUTE_TO_OBJECT_ALIAS], $placeholders);
        $transformedElementCount = $fromSheet->countRows();


        return $result->setProcessedRowsCounter($transformedElementCount);
    }

    /**
     * Finds the object schema by mapping the from-object to the ´x-object-alias´ in the OpenApi schema.
     *
     * @param DataSheetInterface $fromSheet
     * @param array $schemas
     * @return array
     * @throws InvalidArgumentException
     */
    protected function findObjectSchema(DataSheetInterface $fromSheet, array $schemas): array
    {
        switch(true) {
            case array_key_exists($fromSheet->getMetaObject()->getAliasWithNamespace(), $schemas):
                $fromObjectSchema = $schemas[$fromSheet->getMetaObject()->getAliasWithNamespace()];
                break;
            case array_key_exists($fromSheet->getMetaObject()->getAlias(), $schemas):
                $fromObjectSchema = $schemas[$key[0] ?? $fromSheet->getMetaObject()->getAlias()];

                if ($fromObjectSchema[self::OPEN_API_ATTRIBUTE_TO_OBJECT_ALIAS] !== $fromSheet->getMetaObject()->getAliasWithNamespace()) {
                    throw new InvalidArgumentException('From sheet does not match ' .
                        self::OPEN_API_ATTRIBUTE_TO_OBJECT_ALIAS .
                        ' of found schema in the OpenApi definition!');
                }
                break;
            default:
                foreach ($schemas as $schema) {
                    if ($schema[self::OPEN_API_ATTRIBUTE_TO_OBJECT_ALIAS] === $fromSheet->getMetaObject()->getAliasWithNamespace()) {
                        return $schema;
                    }
                }

                throw new InvalidArgumentException('From object not found in OpenApi schema!');
        }

        return $fromObjectSchema;
    }

    /**
     * @param array $fromObjectSchema
     * @return array
     */
    protected function findAttributesInSchema(array $fromObjectSchema) : array
    {
        $attributes = [];

        if (array_key_exists('properties', $fromObjectSchema) === false) {
            throw new NotImplementedError('Only type ´object´ schemas are implemented for the ETLPrototype ' . DataSheetToOpenApi::class);
        }

        foreach ($fromObjectSchema['properties'] as $propName => $property) {
            if (array_key_exists(self::OPEN_API_ATTRIBUTE_TO_ATTRIBUTE_ALIAS, $property)) {
                $attributes[$propName] = $property[self::OPEN_API_ATTRIBUTE_TO_ATTRIBUTE_ALIAS];
            }
        }

        return $attributes;
    }

    /**
     * Finds success response of the current route in the given OpenApi json.
     *
     * @param ServerRequestInterface $request
     * @param string $openApiJson
     * @return array
     * @throws JSONPathException
     * @throws InvalidArgumentException
     */
    protected function getResponseSchema(ServerRequestInterface $request, string $openApiJson) : array
    {
        // Use local version of JSONPathLexer with edit to
        // Make sure to require BEFORE the JSONPath classes are loaded, so that the custom lexer replaces
        // the one shipped with the library.
        require_once '..' . DIRECTORY_SEPARATOR
            . '..' . DIRECTORY_SEPARATOR
            . 'axenox' . DIRECTORY_SEPARATOR
            . 'etl' . DIRECTORY_SEPARATOR
            . 'Common' . DIRECTORY_SEPARATOR
            . 'JSONPath' . DIRECTORY_SEPARATOR
            . 'JSONPathLexer.php';

        $path = $request->getUri()->getPath();
        $path = StringDataType::substringAfter($path, 'dataflow' . '/', '');
        $routePath = rtrim(strstr($path, '/'), '/');
        $methodType = strtolower($request->getMethod());
        $contentType = $request->getHeader('accept')[0];
        $jsonPath = "$.paths.{$routePath}.{$methodType}.responses.200.content.{$contentType}.schema";
        $jsonPathFinder = new JSONPath(json_decode($openApiJson, false));
        $data = $jsonPathFinder->find($jsonPath)->getData()[0];

        if ($data === null) {
            throw new InvalidArgumentException('Cannot find response schema in OpenApi. Please check the route definition!');
        }

        return json_decode(json_encode($data), true);
    }

    /**
     * @param array $responseSchema
     * @param array $newContent
     * @param string $objectAlias
     * @return
     */
    protected function createBodyFromSchema(array $responseSchema, array $newContent, string $objectAlias, array $placeholders) : array
    {
        if ($responseSchema['type'] == 'array') {
            $result = $this->createBodyFromSchema($responseSchema['items'], $newContent, $objectAlias, $placeholders);

            if (empty($result) === false) {
                $body[] = $result;
            }
        }

        if ($responseSchema['type'] == 'object') {
            foreach ($responseSchema['properties'] as $propertyName => $propertyValue) {
                switch (true) {
                    case array_key_exists('x-object-alias', $propertyValue) && $propertyValue['x-object-alias'] === $objectAlias:
                        $body[$propertyName] = $newContent;
                        break;
                    case array_key_exists('x-placeholder', $propertyValue):
                        $value = StringDataType::replacePlaceholders($propertyValue['x-placeholder'], $placeholders, false);

                        switch (true) {
                            case empty($value):
                                $value = null;
                                break;
                            case ($propertyValue['type'] === 'integer'):
                                $value = (int)$value;
                                break;
                            case ($propertyValue['type'] === 'boolean'):
                                $value = (bool)$value;
                                break;
                        }

                        $body[$propertyName] = $value;
                        break;
                    case $propertyValue['type'] === 'array':
                    case $propertyValue['type'] === 'object':
                        $body[$propertyName] = $this->createBodyFromSchema($propertyValue, $newContent, $objectAlias, $placeholders);
                }
            }
        }

        if ($body === null) {
            return [];
        }

        return $body;
    }

    /**
     * @param DataSheetInterface $requestLogData
     * @param ServerRequestInterface $request
     * @param string|null $openApiJson
     * @param array $rows
     * @param string $objectAlias
     * @param array $placeholders
     * @return void
     * @throws JSONPathException
     */
    protected function updateRequestData(
        DataSheetInterface $requestLogData,
        ServerRequestInterface $request,
        ?string $openApiJson,
        array $rows,
        string $objectAlias,
        array $placeholders): void
    {
        $currentBody = json_decode($requestLogData->getCellValue('response_body', 0), true);
        $responseSchema = $this->getResponseSchema($request, $openApiJson);
        $newBody = $this->createBodyFromSchema($responseSchema, $rows, $objectAlias, $placeholders);
        $newBody = $currentBody === null ? $newBody : $this->deepMerge($currentBody, $newBody);
        $requestLogData->setCellValue('response_header', 0, 'application/json');
        $requestLogData->setCellValue('response_body', 0, json_encode($newBody));
        $requestLogData->dataUpdate();
    }

    protected function deepMerge(array $first, array $second) {
        $result = [];
        foreach ($first as $key => $entry) {
            if (is_array($entry) && array_key_exists($key, $second)){
                $result[$key] = array_merge($entry, $second[$key]);
            } else if (array_key_exists($key, $second)) {
                $result[$key] = $second[$key];
            } else {
                $result[$key] = $entry;
            }
        }

        return $result;
    }

    /**
     * @param ETLStepDataInterface $stepData
     * @return \exface\Core\CommonLogic\DataSheets\DataSheet|DataSheetInterface
     */
    protected function loadRequestData(ETLStepDataInterface $stepData): \exface\Core\CommonLogic\DataSheets\DataSheet|DataSheetInterface
    {
        $requestLogData = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.ETL.webservice_request');
        $requestLogData->getColumns()->addFromSystemAttributes();
        $requestLogData->getColumns()->addMultiple([
            'response_body',
            'response_header'
        ]);
        $requestLogData->getFilters()->addConditionFromString('flow_run', $stepData->getFlowRunUid());
        $requestLogData->dataRead();
        return $requestLogData;
    }

    /**
     *
     * @return int|NULL
     */
    protected function getRowLimit($placeholders) : ?int
    {
        if (is_string($this->rowLimit)){
            $value = StringDataType::replacePlaceholders($this->rowLimit, $placeholders, false);
            return empty($value) ? null : $value;
        }

        return $this->rowLimit;
    }

    /**
     * Number of rows to read at once - no limit if set to NULL.
     *
     * Use this parameter if the data of the from-object has
     * large amounts of data at once.
     *
     * Use ´row_offset´ to read the next chunk.
     *
     * @uxon-property row_limit
     * @uxon-type int|null
     *
     * @param $numberOfRows
     * @return DataSheetToOpenApi
     */
    protected function setRowLimit($numberOfRows) : DataSheetToOpenApi
    {
        $this->rowLimit = $numberOfRows;
        return $this;
    }

    protected function getRowOffset($placeholders) : ?int
    {
        if (is_string($this->rowOffset)){
            $value = StringDataType::replacePlaceholders($this->rowOffset, $placeholders, false);
            return empty($value) ? null : $value;
        }

        return $this->rowOffset;
    }

    /**
     * Start position from which to read the from-sheet - no offset if set to NULL.
     *
     * Use this parameter if the data of the from-object has
     * large amounts of data at once.
     *
     * Use ´row_limit´ to define the chunk size.
     *
     * @uxon-property row_offset
     * @uxon-type int|null
     *
     * @param $startPosition
     * @return DataSheetToOpenApi
     */
    protected function setRowOffset($startPosition) : DataSheetToOpenApi
    {
        $this->rowOffset = $startPosition;
        return $this;
    }

    /**
     *
     * {@inheritDoc}
     * @see \axenox\ETL\Interfaces\ETLStepInterface::parseResult()
     */
    public static function parseResult(string $stepRunUid, string $resultData = null): ETLStepResultInterface
    {
        return new IncrementalEtlStepResult($stepRunUid, $resultData);
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\iCanGenerateDebugWidgets::createDebugWidget()
     */
    public function createDebugWidget(DebugMessage $debug_widget)
    {
        if ($this->baseSheet !== null) {
            $debug_widget = $this->baseSheet->createDebugWidget($debug_widget);
        }
        return $debug_widget;
    }

    /**
     *
     * {@inheritDoc}
     * @see \axenox\ETL\Interfaces\ETLStepInterface::isIncremental()
     */
    public function isIncremental(): bool
    {
        return false;
    }

    public function validate(): \Generator
    {
        yield from [];
    }
}