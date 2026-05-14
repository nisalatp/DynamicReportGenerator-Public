<?php

namespace Nisalatp\DynamicReportGenerator\Exceptions;

use RuntimeException;

/**
 * Security Exception.
 *
 * Thrown whenever the engine detects an attempt to query or aggregate against
 * an attribute that has been 'blocked' or 'masked' by Attribute-Level Security (ALS).
 */
class ReportMakerSecurityException extends RuntimeException
{
}
