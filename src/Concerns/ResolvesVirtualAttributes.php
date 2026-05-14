<?php

namespace Nisalatp\DynamicReportGenerator\Concerns;

use Nisalatp\DynamicReportGenerator\Types\ReportRequest;
use Nisalatp\DynamicReportGenerator\Types\FilterNode;
use Nisalatp\DynamicReportGenerator\Types\FilterGroup;
use Nisalatp\DynamicReportGenerator\Types\FilterLeaf;

/**
 * Virtual Attribute Resolution — VA dependency extraction from the AST.
 *
 * Scans the report request's selected attributes and filter trees to detect
 * Virtual Attributes, then merges their dependency models into the target
 * model list so the BFS join planner can include them.
 */
trait ResolvesVirtualAttributes
{
    /**
     * Extract Virtual Attribute dependencies from the AST and merge into targetModels.
     */
    private function extractVirtualAttributeDependencies(ReportRequest $request, array &$targetModels): void
    {
        if (!$this->vaRegistry)
            return;

        $baseModel = $request->baseModel;

        foreach ($request->selectedAttributes as $attr) {
            $isVirtualCol = $attr->isVirtual || str_starts_with($attr->column, 'va:');
            
            if ($isVirtualCol) {
                $virtualName = str_starts_with($attr->column, 'va:') 
                    ? substr($attr->column, 3) 
                    : $attr->column;
                    
                $virtualAttribute = $this->vaRegistry->findByName($attr->modelClass, $virtualName);
                
                if ($virtualAttribute && is_array($virtualAttribute->dependencies)) {
                    $validDependencies = array_filter(
                        $virtualAttribute->dependencies, 
                        fn($dependency) => is_string($dependency) && $dependency !== '_ast'
                    );
                    
                    $targetModels = array_merge($targetModels, $validDependencies);
                }
            }
        }

        $this->extractVAsFromFilter($request->innerFilters, $baseModel, $targetModels);
        $this->extractVAsFromFilter($request->outerFilters, $baseModel, $targetModels);

        $targetModels = array_unique($targetModels);
        $targetModels = array_values($targetModels);
    }

    /**
     * Recursively extract VA dependencies from a filter tree.
     */
    private function extractVAsFromFilter(?FilterNode $node, string $baseModel, array &$targetModels): void
    {
        if (!$node || !$this->vaRegistry)
            return;

        if ($node instanceof FilterGroup) {
            foreach ($node->children as $child) {
                $this->extractVAsFromFilter($child, $baseModel, $targetModels);
            }
        } elseif ($node instanceof FilterLeaf) {
            
            $isVirtualCol = $node->attribute->isVirtual || str_starts_with($node->attribute->column, 'va:');
            
            if ($isVirtualCol) {
                $virtualName = str_starts_with($node->attribute->column, 'va:') 
                    ? substr($node->attribute->column, 3) 
                    : $node->attribute->column;
                    
                $virtualAttribute = $this->vaRegistry->findByName($node->attribute->modelClass, $virtualName);
                
                if ($virtualAttribute && is_array($virtualAttribute->dependencies)) {
                    $validDependencies = array_filter(
                        $virtualAttribute->dependencies, 
                        fn($dependency) => is_string($dependency) && $dependency !== '_ast'
                    );
                    
                    $targetModels = array_merge($targetModels, $validDependencies);
                }
            }
        }
    }
}
