<?php

declare(strict_types=1);

namespace App\Tests\Unit\DTO;

use App\DTO\SearchCursor;
use App\Enum\ReservationSort;
use App\Exception\InvalidSearchCursorException;
use PHPUnit\Framework\TestCase;

use function base64_encode;

/**
 * @see SearchCursor
 */
final class SearchCursorTest extends TestCase
{
    public function testTheOffsetVariantSurvivesARoundTrip(): void
    {
        $cursor = SearchCursor::decode(SearchCursor::createForOffset(20)->encode());

        self::assertSame(20, $cursor->offset);
        self::assertNull($cursor->sort);
        self::assertNull($cursor->sortValues);
    }

    public function testTheSortValuesVariantSurvivesARoundTrip(): void
    {
        $encoded = SearchCursor::createForSortValues(ReservationSort::ARRIVAL, [1788198451000, '01M1CEXX'])->encode();

        $cursor = SearchCursor::decode($encoded);

        self::assertSame([1788198451000, '01M1CEXX'], $cursor->sortValues);
        self::assertSame(ReservationSort::ARRIVAL, $cursor->sort);
        self::assertNull($cursor->offset);
    }

    public function testACursorRemembersWhichSortOrderIssuedIt(): void
    {
        $encoded = SearchCursor::createForSortValues(ReservationSort::LAST_CHANGE, [1788198451000, '01M1CEXX'])
            ->encode();

        self::assertSame(ReservationSort::LAST_CHANGE, SearchCursor::decode($encoded)->sort);
    }

    public function testACursorNamingAnUnknownSortOrderIsRejected(): void
    {
        $this->expectException(InvalidSearchCursorException::class);

        SearchCursor::decode(base64_encode('{"sort":"by_hotel","after":[1]}'));
    }

    public function testACursorCarryingValuesWithoutASortOrderIsRejected(): void
    {
        $this->expectException(InvalidSearchCursorException::class);

        SearchCursor::decode(base64_encode('{"after":[1]}'));
    }

    public function testACursorCarryingNeitherVariantIsRejected(): void
    {
        $this->expectException(InvalidSearchCursorException::class);

        SearchCursor::decode(base64_encode('{"nonsense":1}'));
    }

    public function testACursorCarryingBothVariantsIsRejected(): void
    {
        $this->expectException(InvalidSearchCursorException::class);

        SearchCursor::decode(base64_encode('{"offset":20,"sort":"arrival","after":[1]}'));
    }

    public function testACursorCarryingANegativeOffsetIsRejected(): void
    {
        $this->expectException(InvalidSearchCursorException::class);

        SearchCursor::decode(base64_encode('{"offset":-1}'));
    }

    public function testACursorThatCarriesNoJsonIsRejected(): void
    {
        $this->expectException(InvalidSearchCursorException::class);
        $this->expectExceptionMessageIs('The cursor does not contain valid JSON.');

        SearchCursor::decode(base64_encode('not json at all'));
    }

    /**
     * Without the base64 check the JSON check would throw the same exception.
     */
    public function testACursorThatIsNotBase64IsRejected(): void
    {
        $this->expectException(InvalidSearchCursorException::class);
        $this->expectExceptionMessageIs('The cursor is not valid base64.');

        SearchCursor::decode('nonsense!!');
    }
}
