<?php

declare(strict_types=1);

namespace TwanHaverkamp\EventStorageInMongoDbWithPhp\Event\EventStore;

use DateTime;
use DateTimeImmutable;
use MongoDB\Client as MongoDbClient;
use MongoDB\Collection as MongoDbCollection;
use MongoDB\Driver\Exception\RuntimeException as MongoDbRuntimeException;
use MongoDB\Exception\InvalidArgumentException as MongoDbInvalidArgumentException;
use TwanHaverkamp\EventSourcingWithPhp\Aggregate;
use TwanHaverkamp\EventSourcingWithPhp\Event\EventDescriber;
use TwanHaverkamp\EventSourcingWithPhp\Event\EventStore;
use TwanHaverkamp\EventSourcingWithPhp\Event\EventStore\Traits;
use TwanHaverkamp\EventSourcingWithPhp\Event\Exception;

class MongoDb implements EventStore\EventStoreInterface
{
    use Traits\Register;

    protected MongoDbCollection $collection;

    public function __construct(
        MongoDbClient $client,
        protected EventDescriber\EventDescriberInterface $describer,
        string $databaseName,
        string $collectionName,
    ) {
        $this->collection = $client
            ->getDatabase($databaseName)
            ->getCollection($collectionName);
    }

    /**
     * @throws Exception\EventRetrievalFailedException when the Event class cannot be found.
     */
    public function load(Aggregate\AggregateInterface $aggregate): void
    {
        $aggregateRootId = $aggregate->getAggregateRootId();

        $documents = $this->collection->find(
            filter: ['aggregateRootId' => $aggregateRootId->toString()],
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
         * @var array{
         *     aggregateRootId: string,
         *     type: string,
         *     payload: array<string, mixed>,
         *     recordedAt: string,
         *     microseconds: int,
         * } $document
         */
        foreach ($documents as $document) {
            $eventClass = array_values(
                array_filter(
                    static::$registeredEventClasses,
                    fn (string $eventClass) => $this->describer->describe($eventClass) === $document['type'],
                ),
            )[0] ?? null;

            if ($eventClass == null) {
                throw new Exception\EventRetrievalFailedException(sprintf(
                    'Could not find an Event class for type \'%s\'.',
                    $document['type'],
                ));
            }

            /** @var DateTime $recordedAt */
            $recordedAt = DateTime::createFromFormat(DATE_ATOM, $document['recordedAt']);
            $recordedAt->setTime(
                (int)$recordedAt->format('H'),
                (int)$recordedAt->format('i'),
                (int)$recordedAt->format('s'),
                $document['microseconds'],
            );

            $aggregate->apply($eventClass::fromPayload(
                $aggregate->getAggregateRootId(),
                $document['payload'],
                DateTimeImmutable::createFromMutable($recordedAt),
            ));
        }
    }

    /**
     * @throws Exception\EventStorageFailedException when the MongoDB client throws a {@see MongoDbInvalidArgument} or
     *                                               {@see MongoDbRuntimeException} when inserting "one" document.
     */
    public function save(Aggregate\AggregateInterface $aggregate): void
    {
        foreach ($aggregate->getEvents() as $event) {
            try {
                $this->collection->insertOne([
                    'aggregateRootId' => $aggregate->getAggregateRootId()->toString(),
                    'type'            => $this->describer->describe($event),
                    'payload'         => $event->getPayload(),
                    'recordedAt'      => $event->getRecordedAt()->format(DATE_ATOM),
                    'microseconds'    => (int)$event->getRecordedAt()->format('u'),
                ]);
            } catch (MongoDbInvalidArgumentException | MongoDbRuntimeException $e) {
                throw new Exception\EventStorageFailedException(
                    message: sprintf(
                        "Failed to store Event(s) for Aggregate with AggregateRootId %s.",
                        $aggregate->getAggregateRootId()->toString(),
                    ),
                    previous: $e,
                );
            }
        }
    }
}
