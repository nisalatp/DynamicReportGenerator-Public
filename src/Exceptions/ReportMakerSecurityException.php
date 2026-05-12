<?php

namespace Nisalatp\DynamicReportGenerator\Exceptions;

use RuntimeException;

class ReportMakerSecurityException extends RuntimeException
{
    // Custom exception for when a user tries to access a blocked or masked attribute
}
