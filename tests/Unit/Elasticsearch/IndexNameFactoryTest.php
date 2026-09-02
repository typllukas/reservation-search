<?php

declare(strict_types=1);

namespace App\Tests\Unit\Elasticsearch;

use App\Elasticsearch\IndexNameFactory;
use App\Enum\IndexAlias;
use PHPUnit\Framework\TestCase;

/**
 * @see IndexNameFactory
 */
final class IndexNameFactoryTest extends TestCase
{
    public function testAnEmptySuffixLeavesTheAliasAlone(): void
    {
        self::assertSame('reservations', new IndexNameFactory('')->build(IndexAlias::RESERVATIONS));
    }

    public function testTheSuffixIsAppendedToTheAlias(): void
    {
        self::assertSame('guests_test', new IndexNameFactory('_test')->build(IndexAlias::GUESTS));
    }
}
