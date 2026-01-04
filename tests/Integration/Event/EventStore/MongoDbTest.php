<?php

declare(strict_types=1);

namespace TwanHaverkamp\EventStorageInMongoDbWithPhp\Tests\Integration\Event\EventStore;

use DateTime;
use DateTimeInterface;
use MongoDB\Client as MongoDbClient;
use PHPUnit\Framework\Attributes;
use PHPUnit\Framework\TestCase;
use TwanHaverkamp\EventSourcingWithPhp\Event;
use TwanHaverkamp\EventSourcingWithPhp\Event\EventDescriber;
use TwanHaverkamp\EventSourcingWithPhp\Event\Exception;
use TwanHaverkamp\EventSourcingWithPhp\Example;
use TwanHaverkamp\EventStorageInMongoDbWithPhp\Event\EventStore;

#[Attributes\CoversClass(EventStore\MongoDb::class)]
class MongoDbTest extends TestCase
{
    protected const string DATABASE_NAME = 'eventStore';
    protected const string COLLECTION_NAME = 'invoices';

    /**
     * @var Event\EventInterface[]|null
     */
    protected static array|null $events;

    protected static MongoDbClient $client;

    public static function setUpBeforeClass(): void
    {
        static::$client = new MongoDbClient(sprintf(
            'mongodb://%s:%s@%s:%d',
            'root',
            'password',
            'mongodb',
            27017,
        ));
    }

    public static function tearDownAfterClass(): void
    {
        static::$client
            ->getDatabase(static::DATABASE_NAME)
            ->dropCollection(static::COLLECTION_NAME);
    }

    #[Attributes\Test]
    #[Attributes\TestDox('Assert that \'save\' doesn\'t fail')]
    public function save(): Example\Aggregate\Invoice
    {
        $this->expectNotToPerformAssertions();

        $invoice = Example\Aggregate\Invoice::create(
            '12-34',
            new Example\Aggregate\DTO\Item('prod.123.456', 'Product', 3, 5.95, 21.),
            new Example\Aggregate\DTO\Item(null, 'Shipping', 1, 4.95, 0.),
        );

        $paymentTransaction = $invoice->startPaymentTransaction('Manual', 10.);
        $invoice->completePaymentTransaction($paymentTransaction->id);

        static::$events = $invoice->getEvents();

        $eventStore = new EventStore\MongoDb(
            static::$client,
            new EventDescriber\KebabCase(),
            static::DATABASE_NAME,
            static::COLLECTION_NAME,
        );

        $eventStore->save($invoice);

        return $invoice;
    }

    #[Attributes\Test]
    #[Attributes\TestDox('Assert that \'save\' created a new \'collection\' for the Aggregate')]
    #[Attributes\Depends('save')]
    public function saveAddedEventsInCollection(Example\Aggregate\Invoice $invoice): void
    {
        $collection = static::$client
            ->getDatabase(static::DATABASE_NAME)
            ->getCollection(static::COLLECTION_NAME);

        $documents = $collection->find(
            filter: ['aggregateRootId' => $invoice->getAggregateRootId()->toString()],
            options: ['sort' => [
                'recordedAt'   => 1,
                'microseconds' => 1,
            ]],
        );

        $documents->setTypeMap([
            'root'     => 'array',
            'document' => 'array',
            'array'    => 'array',
        ]);

        /**
         * @var array<int, array{
         *     aggregateRootId: string,
         *     type: string,
         *     payload: array<string, mixed>,
         *     recordedAt: string,
         *     microseconds: int,
         * }> $data
         */
        $data = $documents->toArray();

        static::assertCount(3, $data);

        $this
            ->assertInvoiceWasCreated($data[0])
            ->assertPaymentTransactionWasStarted($data[1])
            ->assertPaymentTransactionWasCompleted($data[2]);
    }

    #[Attributes\Test]
    #[Attributes\TestDox('Assert that \'load\' throws an EventRetrievalFailedException for an unregistered event')]
    #[Attributes\Depends('save')]
    public function loadWithUnregisteredEventThrowsEventRetrievalFailedException(
        Example\Aggregate\Invoice $invoice,
    ): void {
        $this->expectException(Exception\EventRetrievalFailedException::class);
        $this->expectExceptionMessage('Could not find an Event class for type \'invoice-was-created\'.');

        // Remove all registered Event classes.
        EventStore\MongoDb::register();

        $eventStore = new EventStore\MongoDb(
            static::$client,
            new EventDescriber\KebabCase(),
            static::DATABASE_NAME,
            static::COLLECTION_NAME,
        );

        $eventStore->load($invoice);
    }

    #[Attributes\Test]
    #[Attributes\TestDox('Assert that \'load\' populates the Aggregate')]
    #[Attributes\Depends('save')]
    public function loadPopulatesAggregate(Example\Aggregate\Invoice $invoice): void
    {
        $aggregateRootId = $invoice->getAggregateRootId()->toString();
        $createdAt = $invoice->createdAt->format(DATE_ATOM);

        $eventStore = new EventStore\MongoDb(
            static::$client,
            new EventDescriber\KebabCase(),
            static::DATABASE_NAME,
            static::COLLECTION_NAME,
        );

        $eventStore->load(
            $invoice = Example\Aggregate\Invoice::init($aggregateRootId),
        );

        static::assertCount(0, $invoice->getEvents());

        static::assertSame('12-34', $invoice->number);
        static::assertSame($createdAt, $invoice->createdAt->format(DATE_ATOM));

        static::assertSame('prod.123.456', $invoice->items[0]->reference);
        static::assertSame('Product', $invoice->items[0]->description);
        static::assertSame(3, $invoice->items[0]->quantity);
        static::assertSame(5.95, $invoice->items[0]->price);
        static::assertSame(21., $invoice->items[0]->tax);

        static::assertSame('Shipping', $invoice->items[1]->description);
        static::assertSame(1, $invoice->items[1]->quantity);
        static::assertSame(4.95, $invoice->items[1]->price);
        static::assertSame(0., $invoice->items[1]->tax);

        static::assertSame(22.8, $invoice->getSubTotal());
        static::assertSame(3.75, $invoice->getTax());
        static::assertSame(16.55, $invoice->getTotal());
    }

    /**
     * @param array{
     *     aggregateRootId: string,
     *     type: string,
     *     payload: array<string, mixed>,
     *     recordedAt: string,
     *     microseconds: int,
     * } $document
     */
    protected function assertInvoiceWasCreated(array $document): self
    {
        static::assertSame('invoice-was-created', $document['type']);

        if (isset(static::$events[0]) === false) {
            static::fail(sprintf(
                'Failed to assert that Event \'%s\' exists in memory.',
                Example\Event\InvoiceWasCreated::class,
            ));
        }

        static::assertRecordedAt(
            static::$events[0]->getRecordedAt(),
            $document['recordedAt'],
            $document['microseconds'],
        );

        /**
         * @var array{
         *     number: string,
         *     items: array{
         *         reference: string|null,
         *         description: string,
         *         quantity: int,
         *         price: float,
         *         tax: float,
         *     }[],
         * } $payload
         */
        $payload = $document['payload'];

        static::assertSame('12-34', $payload['number']);

        static::assertSame('prod.123.456', $payload['items'][0]['reference']);
        static::assertSame('Product', $payload['items'][0]['description']);
        static::assertSame(3, $payload['items'][0]['quantity']);
        static::assertSame(5.95, $payload['items'][0]['price']);
        static::assertSame(21., $payload['items'][0]['tax']);

        static::assertSame('Shipping', $payload['items'][1]['description']);
        static::assertSame(1, $payload['items'][1]['quantity']);
        static::assertSame(4.95, $payload['items'][1]['price']);
        static::assertSame(0., $payload['items'][1]['tax']);

        return $this;
    }

    /**
     * @param array{
     *     aggregateRootId: string,
     *     type: string,
     *     payload: array<string, mixed>,
     *     recordedAt: string,
     *     microseconds: int,
     * } $document
     */
    protected function assertPaymentTransactionWasStarted(array $document): self
    {
        static::assertSame('payment-transaction-was-started', $document['type']);

        if (isset(static::$events[1]) === false) {
            static::fail(sprintf(
                'Failed to assert that Event \'%s\' exists in memory.',
                Example\Event\PaymentTransactionWasStarted::class,
            ));
        }

        static::assertRecordedAt(
            static::$events[1]->getRecordedAt(),
            $document['recordedAt'],
            $document['microseconds'],
        );

        static::assertSame('Manual', $document['payload']['paymentMethod']);
        static::assertSame(10., $document['payload']['amount']);

        return $this;
    }

    /**
     * @param array{
     *     aggregateRootId: string,
     *     type: string,
     *     payload: array<string, mixed>,
     *     recordedAt: string,
     *     microseconds: int,
     * } $document
     */
    protected function assertPaymentTransactionWasCompleted(array $document): self
    {
        static::assertSame('payment-transaction-was-completed', $document['type']);

        if (isset(static::$events[2]) === false) {
            static::fail(sprintf(
                'Failed to assert that Event \'%s\' exists in memory.',
                Example\Event\PaymentTransactionWasCompleted::class,
            ));
        }

        static::assertRecordedAt(
            static::$events[2]->getRecordedAt(),
            $document['recordedAt'],
            $document['microseconds'],
        );

        return $this;
    }

    protected static function assertRecordedAt(
        DateTimeInterface $expectedAt,
        string $datetime,
        int $microseconds,
    ): void {
        $recordedAt = new DateTime($datetime);
        $recordedAt->setTime(
            (int)$recordedAt->format('H'),
            (int)$recordedAt->format('i'),
            (int)$recordedAt->format('s'),
            $microseconds,
        );

        static::assertSame(
            $expectedAt->format('Uu'),
            $recordedAt->format('Uu'),
        );
    }

    protected function setUp(): void
    {
        EventStore\MongoDb::register(
            Example\Event\InvoiceWasCreated::class,
            Example\Event\PaymentTransactionWasStarted::class,
            Example\Event\PaymentTransactionWasCompleted::class,
        );
    }
}
