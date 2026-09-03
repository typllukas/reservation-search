<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

abstract class ElasticsearchTestCase extends TestCase
{
    use ElasticsearchIndexTrait;
}
