<?php

namespace Nisalatp\DynamicReportGenerator\Contracts;

/**
 * Interface DynamicReportSubject
 * 
 * Implement this interface on your host application's User model (or any authenticatable entity)
 * to allow the Dynamic Report Generator to resolve all relevant security subjects (e.g., the User itself, 
 * plus any associated Roles or Groups) when applying Attribute Level Security rules.
 */
interface DynamicReportSubject
{
    /**
     * Get an array of Model instances that represent the security subjects for the current context.
     * 
     * For example, this should return [$this] array to apply User-level rules,
     * or array_merge([$this], $this->roles->all()) to apply both User-level and Role-level rules.
     *
     * @return \Illuminate\Database\Eloquent\Model[]
     */
    public function getDynamicReportSubjects(): array;
}
