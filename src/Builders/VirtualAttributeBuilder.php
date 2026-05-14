<?php

namespace Nisalatp\DynamicReportGenerator\Builders;

use Nisalatp\DynamicReportGenerator\Models\VirtualAttribute;

class VirtualAttributeBuilder
{
    private string $name;
    private string $baseModel;
    private string $returnType = 'string';
    private string $sqlFragment;
    private array $dependencies = [];

    private function __construct(string $name)
    {
        $this->name = $name;
    }

    public static function create(string $name): self
    {
        return new self($name);
    }

    public function forBaseModel(string $modelClass): self
    {
        $this->baseModel = $modelClass;
        return $this;
    }

    public function withReturnType(string $type): self
    {
        $this->returnType = $type;
        return $this;
    }

    public function withSqlFragment(string $sql): self
    {
        $this->sqlFragment = $sql;
        return $this;
    }

    public function dependsOn(array $targetModelClasses): self
    {
        $this->dependencies = $targetModelClasses;
        return $this;
    }

    public function register(): VirtualAttribute
    {
        // Use updateOrCreate so running it repeatedly doesn't fail
        return VirtualAttribute::updateOrCreate(
            ['base_model' => $this->baseModel, 'name' => $this->name],
            [
                'return_type' => $this->returnType,
                'sql_fragment' => $this->sqlFragment, 
                'dependencies' => $this->dependencies
            ]
        );
    }
}
