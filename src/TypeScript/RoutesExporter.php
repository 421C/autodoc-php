<?php declare(strict_types=1);

namespace AutoDoc\TypeScript;

use AutoDoc\Analyzer\Scope;
use AutoDoc\Config;
use AutoDoc\OpenApi\MediaType;
use AutoDoc\OpenApi\Parameter;
use AutoDoc\OpenApi\Response;

/**
 * @phpstan-import-type TypeScriptRouteExportConfig from Config
 */
class RoutesExporter
{
    /**
     * @param TypeScriptRouteExportConfig $exportOptions
     */
    public function __construct(
        private readonly Config $config,
        private readonly string $targetFilePath,
        private readonly array $exportOptions,
        private readonly TypeScriptOutputPathResolver $outputPathResolver = new TypeScriptOutputPathResolver,
        private readonly TypeScriptRouteTypeMap $routeTypeMap = new TypeScriptRouteTypeMap,
        ?TypeScriptRouteExportCollector $routeExportCollector = null,
    ) {
        $this->routeExportCollector = $routeExportCollector ?? new TypeScriptRouteExportCollector($config->getRouteLoader());
    }

    private readonly TypeScriptRouteExportCollector $routeExportCollector;


    /**
     * @return array{
     *     filePath: string,
     *     exportedRequests: int,
     *     exportedResponses: int,
     * }
     */
    public function export(): array
    {
        $includeRequestsWithoutBody = (bool) ($this->exportOptions['include_requests_without_body'] ?? false);

        foreach ($this->routeExportCollector->collect($this->exportOptions) as $route => $path) {
            foreach ($path->operations as $method => $operation) {
                $method = strtoupper($method);
                $requestBodyType = MediaType::findPreferredType($operation->requestBody->content ?? []);

                if ($requestBodyType) {
                    $this->routeTypeMap->addRequestBody($route, $method, $requestBodyType);
                }

                foreach ($operation->parameters ?? [] as $parameter) {
                    if ($parameter instanceof Parameter && $parameter->in === 'query' && $parameter->type) {
                        $this->routeTypeMap->addQueryParameter($route, $method, $parameter->name, $parameter->type);
                    }
                }

                if ($includeRequestsWithoutBody && ! $this->routeTypeMap->hasRequest($route, $method)) {
                    $this->routeTypeMap->addRequestWithoutBody($route, $method);
                }

                foreach ($operation->responses ?? [] as $responseCode => $response) {
                    $responseType = $response instanceof Response
                        ? MediaType::findPreferredType($response->content ?? [])
                        : null;

                    if ($responseType) {
                        $this->routeTypeMap->addResponse($route, $method, $responseCode, $responseType);
                    }
                }
            }
        }

        $typeConverter = new TypeConverter;

        $tsConfig = $this->config->getTypeScriptConfig();
        $scope = new Scope($this->config);

        $this->routeTypeMap->sortRoutes();

        $requestsTsType = $typeConverter->convertToTypeScriptType(
            type: $this->routeTypeMap->requests,
            context: new TypeScriptRenderContext(scope: $scope, config: $tsConfig, isRequest: true),
        );
        $responsesTsType = $typeConverter->convertToTypeScriptType(
            type: $this->routeTypeMap->responses,
            context: new TypeScriptRenderContext(scope: $scope, config: $tsConfig),
        );

        $fileBody = 'export type Requests = ' . $requestsTsType . "\n\n"
            . 'export type Responses = ' . $responsesTsType . "\n";

        $fullPath = $this->outputPathResolver->resolve($this->targetFilePath, $tsConfig['path_prefixes']);

        new GeneratedTypeScriptFile($fullPath)->write($fileBody);

        return [
            'filePath' => $fullPath,
            'exportedRequests' => count($this->routeTypeMap->requests->properties),
            'exportedResponses' => count($this->routeTypeMap->responses->properties),
        ];
    }


}
