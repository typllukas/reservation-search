<?php

declare(strict_types=1);

namespace App\Tests\Unit\DTO;

use App\DTO\ReservationSearchInput;
use App\Enum\ReservationSort;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

use function array_map;
use function iterator_to_array;
use function str_repeat;

/**
 * @see ReservationSearchInput
 */
final class ReservationSearchInputTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    /**
     * @return array<string, array{ReservationSearchInput}>
     */
    public static function provideAcceptedInputs(): array
    {
        return [
            'the ordinary first page' => [new ReservationSearchInput(text: 'novak')],
            'the narrowest page the range allows' => [new ReservationSearchInput(size: 1)],
            'the widest page the range allows' => [new ReservationSearchInput(size: 100)],
            'a query at the length limit' => [new ReservationSearchInput(text: str_repeat('a', 200))],
        ];
    }

    /**
     * @return array<string, array{ReservationSearchInput, string}>
     */
    public static function provideRejectedInputs(): array
    {
        return [
            'a page above the range' => [new ReservationSearchInput(size: 101), 'size'],
            'a page below the range' => [new ReservationSearchInput(size: 0), 'size'],
            'an arrival range that ends before it starts' => [
                new ReservationSearchInput(
                    arrivalFrom: '2026-09-14',
                    arrivalTo: '2026-09-13',
                ),
                'arrivalTo',
            ],
            'an arrival day the calendar does not have' => [
                new ReservationSearchInput(arrivalFrom: '2026-02-30'),
                'arrivalFrom',
            ],
            'an arrival bound carrying a time' => [
                new ReservationSearchInput(arrivalFrom: '2026-02-28T23:00:00Z'),
                'arrivalFrom',
            ],
            'a negative price floor' => [new ReservationSearchInput(priceFrom: -1), 'priceFrom'],
            'a price range that ends below its floor' => [
                new ReservationSearchInput(
                    priceFrom: 200000,
                    priceTo: 100000,
                ),
                'priceTo',
            ],
            'a query longer than the limit' => [new ReservationSearchInput(text: str_repeat('a', 201)), 'text'],
        ];
    }

    /**
     * @return array<int, string> the property paths the violations point at
     */
    private function findViolatedFields(ReservationSearchInput $input): array
    {
        $violations = $this->validator->validate($input);

        return array_map(
            static fn (ConstraintViolationInterface $violation): string => $violation->getPropertyPath(),
            iterator_to_array($violations),
        );
    }

    public function testTheDefaultsAreRelevanceAndAPageOfTwenty(): void
    {
        $input = new ReservationSearchInput();

        self::assertSame(ReservationSort::RELEVANCE, $input->sort);
        self::assertSame(20, $input->size);
    }

    #[DataProvider('provideAcceptedInputs')]
    public function testAValidInputRaisesNoViolation(ReservationSearchInput $input): void
    {
        self::assertSame([], $this->findViolatedFields($input));
    }

    #[DataProvider('provideRejectedInputs')]
    public function testAnInvalidInputNamesTheFieldItRejects(
        ReservationSearchInput $input,
        string $expectedField,
    ): void {
        self::assertContains($expectedField, $this->findViolatedFields($input));
    }
}
