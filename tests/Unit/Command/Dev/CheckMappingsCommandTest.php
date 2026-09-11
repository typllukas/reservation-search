<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command\Dev;

use App\Command\Dev\CheckMappingsCommand;
use App\Elasticsearch\Mapping\IndexDefinitionInterface;
use App\Enum\IndexAlias;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tested because make check reads the result.
 */
final class CheckMappingsCommandTest extends TestCase
{
    public function testAnIndexedFieldNoQueryReadsFailsTheCommand(): void
    {
        $commandTester = $this->buildCommandTester([
            'properties' => [
                'unread_field' => ['type' => 'keyword'],
            ],
        ]);

        self::assertSame(Command::FAILURE, $commandTester->execute([]));
        self::assertStringContainsString('unread_field is indexed and no query reads it', $commandTester->getDisplay());
    }

    public function testAnIndexedFieldAQueryNamesPassesTheCommand(): void
    {
        $commandTester = $this->buildCommandTester([
            'properties' => [
                'number' => ['type' => 'keyword'],
            ],
        ]);

        self::assertSame(Command::SUCCESS, $commandTester->execute([]));
        self::assertStringContainsString('every indexed field has a reader', $commandTester->getDisplay());
    }

    /**
     * @param array<mixed> $reservationMapping
     */
    private function buildCommandTester(array $reservationMapping): CommandTester
    {
        $reservationIndexDefinition = self::createStub(IndexDefinitionInterface::class);
        $reservationIndexDefinition->method('getAlias')->willReturn(IndexAlias::RESERVATIONS);
        $reservationIndexDefinition->method('getMapping')->willReturn($reservationMapping);

        $guestIndexDefinition = self::createStub(IndexDefinitionInterface::class);
        $guestIndexDefinition->method('getAlias')->willReturn(IndexAlias::GUESTS);
        $guestIndexDefinition->method('getMapping')->willReturn([]);

        return new CommandTester(new CheckMappingsCommand($reservationIndexDefinition, $guestIndexDefinition));
    }
}
