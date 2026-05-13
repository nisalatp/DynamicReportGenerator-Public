<?php

namespace Nisalatp\DynamicReportGenerator\Services;

use Nisalatp\DynamicReportGenerator\Types\VirtualAttributeRequest;
use Nisalatp\DynamicReportGenerator\ReportMaker;

class VirtualAttributeCompiler
{
    /**
     * Compiles a visual frontend payload into a raw SQL scalar subquery fragment.
     */
    public static function compileVisualPayload(array $payload): string
    {
        $baseModel = $payload['baseModel'];
        $dependencies = $payload['dependencies'] ?? [];
        $ast = $payload['ast'] ?? [];

        $targetClass = $dependencies[0] ?? ($payload['targetModels'][0] ?? null);
        if (!$targetClass) {
            throw new \Exception("Visual Builder requires at least one Target Model dependency.");
        }

        $requestObj = VirtualAttributeRequest::fromArray([
            'baseModel' => $baseModel,
            'targetModel' => $targetClass,
            'aggregateFunction' => $ast['aggregateFunction'] ?? 'SUM',
            'aggregateColumn' => $ast['aggregateColumn'] ?? 'id',
            'innerFilters' => $ast['innerFilters'] ?? null,
            'outerFilters' => $ast['outerFilters'] ?? null,
        ]);

        return app(ReportMaker::class)->buildScalarSubquery($requestObj);
    }
}
