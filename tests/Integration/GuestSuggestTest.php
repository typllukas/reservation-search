<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\DTO\GuestSuggestion;
use App\Elasticsearch\DocumentFactory\GuestDocumentFactory;
use App\Elasticsearch\GuestSuggester;
use App\Elasticsearch\IndexNameFactory;
use App\Elasticsearch\Mapping\GuestIndexDefinition;
use App\Entity\Guest;
use App\Enum\IndexAlias;
use App\Repository\GuestRepository;
use App\Tests\ElasticsearchTestCase;
use Doctrine\ORM\EntityManagerInterface;

use function array_map;
use function sprintf;

final class GuestSuggestTest extends ElasticsearchTestCase
{
    private const string TEST_INDEX_SUFFIX = '_test';

    private const string TEST_INDEX_NAME = IndexAlias::GUESTS->value . self::TEST_INDEX_SUFFIX;

    private const array NAMES = ['Nováková Petra', 'Novák Jan', 'Svoboda Marek'];

    private const int SUGGESTION_LIMIT = 8;

    public static function setUpBeforeClass(): void
    {
        self::connectToElasticsearch();
        self::dropIndices(self::TEST_INDEX_NAME);

        self::createIndex(self::TEST_INDEX_NAME, new GuestIndexDefinition(
            self::createStub(GuestRepository::class),
            new GuestDocumentFactory(),
            self::createStub(EntityManagerInterface::class),
        ));

        self::indexFixtures();
    }

    public static function tearDownAfterClass(): void
    {
        self::dropIndices(self::TEST_INDEX_NAME);
    }

    private static function indexFixtures(): void
    {
        $guestDocumentFactory = new GuestDocumentFactory();
        $operations = [];

        foreach (self::NAMES as $index => $name) {
            $guest = new Guest()
                ->setName($name)
                ->setEmail(sprintf('test%d@test.com', $index))
                ->setPhone('+420 111 222 333');

            $operations[] = [
                'index' => [
                    '_index' => self::TEST_INDEX_NAME,
                    '_id' => $guest->getId()->toBase32(),
                ],
            ];
            $operations[] = $guestDocumentFactory->build($guest);
        }

        self::indexDocuments($operations);
    }

    /**
     * @return array<int, GuestSuggestion>
     */
    private function suggest(string $text): array
    {
        return new GuestSuggester(self::$elasticsearchClient, new IndexNameFactory(self::TEST_INDEX_SUFFIX))
            ->suggest($text, self::SUGGESTION_LIMIT);
    }

    public function testAPrefixWithoutDiacriticsCompletesTheName(): void
    {
        $names = array_map(
            static fn (GuestSuggestion $guestSuggestion): string => $guestSuggestion->name,
            $this->suggest('nov'),
        );

        self::assertContains('Nováková Petra', $names);
        self::assertContains('Novák Jan', $names);
        self::assertNotContains('Svoboda Marek', $names);
    }

    public function testTheSecondWordCompletesOnceTheFirstOneIsTyped(): void
    {
        $suggestions = $this->suggest('novakova pe');

        self::assertNotSame([], $suggestions);
        self::assertSame('Nováková Petra', $suggestions[0]->name);
        self::assertSame('test0@test.com', $suggestions[0]->email);
    }
}
