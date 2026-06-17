<?php
namespace Nisalatp\DynamicReportGenerator\Types;
readonly class JoinStep {
    public function __construct(public string $fromModel, public string $toModel, public string $joinType, public string $localTableAlias, public string $remoteTableAlias, public string $localKey, public string $foreignKey, public string $relationType) {}
}
