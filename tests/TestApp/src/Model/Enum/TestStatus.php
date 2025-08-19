<?php
declare(strict_types=1);

namespace TestApp\Model\Enum;

/**
 * Test enum for testing enum generation
 */
enum TestStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Pending = 'pending';
}