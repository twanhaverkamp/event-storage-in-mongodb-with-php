<?php

declare(strict_types=1);

namespace TwanHaverkamp\EventStorageInMongoDbWithPhp\Tests\Unit\Event\EventStore;

use MongoDB\Client as MongoDbClient;
use MongoDB\Collection as MongoDbCollection;
use MongoDB\Database as MongoDbDatabase;
use MongoDB\Driver\Exception\RuntimeException as MongoDbRuntimeException;
use MongoDB\Exception\InvalidArgumentException as MongoDbInvalidArgumentException;
use PHPUnit\Framework\Attributes;
use PHPUnit\Framework\TestCase;
use Throwable;
use TwanHaverkamp\EventSourcingWithPhp\Event\EventDescriber;
use TwanHaverkamp\EventSourcingWithPhp\Event\Exception;
use TwanHaverkamp\EventSourcingWithPhp\Example;
use TwanHaverkamp\EventStorageInMongoDbWithPhp\Event\EventStore;

#[Attributes\CoversClass(EventStore\MongoDb::class)]
class MongoDbTest extends TestCase
{
    protected const string DATABASE_NAME = 'eventStore';
    protected const string COLLECTION_NAME = 'invoices';

    #[Attributes\Test]
    #[Attributes\TestDox('Assert that \'save\' throws an EventStorageFailedException when an exception was thrown')]
    #[Attributes\TestWith([new MongoDbRuntimeException()], 'RuntimeException')]
    #[Attributes\TestWith([new MongoDbInvalidArgumentException()], 'InvalidArgumentException')]
    public function saveThrowsEventStorageFailedException(Throwable $e): void
    {
        $invoice = Example\Aggregate\Invoice::create(
            '12-34',
            new Example\Aggregate\DTO\Item('prod.123.456', 'Product', 3, 5.95, 21.),
            new Example\Aggregate\DTO\Item(null, 'Shipping', 1, 4.95, 0.),
        );

        $paymentTransaction = $invoice->startPaymentTransaction('Manual', 10.);
        $invoice->completePaymentTransaction($paymentTransaction->id);

        $this->expectException(Exception\EventStorageFailedException::class);
        $this->expectExceptionMessage(
            sprintf(
                'Failed to store Event(s) for Aggregate with AggregateRootId %s.',
                $invoice->getAggregateRootId()->toString(),
            ),
        );

        $collection = $this->createMock(MongoDbCollection::class);
        $collection
            ->method('insertOne')
            ->willThrowException($e);

        $eventStore = new EventStore\MongoDb(
            $this->mockClientWithCollection($collection),
            new EventDescriber\KebabCase(),
            static::DATABASE_NAME,
            static::COLLECTION_NAME,
        );

        $eventStore->save($invoice);
    }

    protected function mockClientWithCollection(MongoDbCollection $collection): MongoDbClient
    {
        $database = $this->createMock(MongoDbDatabase::class);
        $database
            ->method('getCollection')
            ->with(static::COLLECTION_NAME)
            ->willReturn($collection);

        $client = $this->createMock(MongoDbClient::class);
        $client
            ->method('getDatabase')
            ->with(static::DATABASE_NAME)
            ->willReturn($database);

        return $client;
    }
}
