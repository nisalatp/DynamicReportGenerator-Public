<?php
namespace Nisalatp\DynamicReportGenerator\Exceptions;
use Exception;
class ReportMakerException extends Exception {
    public static function confusingPath(string $from, string $to): self { return new self("Oops! We found multiple ways to connect '{$from}' to '{$to}', so we aren't sure which path to take. Could you specify it?"); }
    public static function noPath(string $from, string $to): self { return new self("Hmm, we couldn't figure out how to link '{$from}' to '{$to}'. Are they related?"); }
    public static function badFilter(string $operator): self { return new self("Sorry, the filter operator '{$operator}' isn't something we recognize."); }
    public static function badFilterType(string $operator, string $type): self { return new self("We can't use the '{$operator}' operator on '{$type}' data. It just doesn't mix well!"); }
    public static function badFilterValue(string $operator): self { return new self("The value you gave for '{$operator}' doesn't seem right. Please check and try again."); }
    public static function modelNotAllowed(string $modelClass): self { return new self("Looks like the model '{$modelClass}' hasn't been allowed for reports yet. Did you forget to add it?"); }
    public static function unsupportedRelation(string $type, string $method): self { return new self("We don't support the '{$type}' relationship on the '{$method}' method yet. Sorry about that!"); }
}
