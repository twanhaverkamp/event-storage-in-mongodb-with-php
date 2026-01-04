# Event storage in MongoDB with PHP

This package is a [MongoDB](https://www.mongodb.com/) implementation for the
["Event Sourcing with PHP"](https://github.com/twanhaverkamp/event-sourcing-with-php) library.

**Table of Contents**

- [Usage](#usage)
  - [Installation](#installation)
  - [Implementation](#implementation)
    - [Connect with MongoDB](#connect-with-mongodb)
    - [Event storage](#event-storage)
  - [Dependency injection](#dependency-injection)
    - [Laravel project](#laravel-project)
    - [Symfony project](#symfony-project)

## Usage

### Installation

**Requirements:**
- PHP 8.3 (or higher)

If you're using [Composer](https://getcomposer.org/) in your project you can run the following command:

```shell
composer require composer require twanhaverkamp/event-storage-in-mongodb-with-php:^1.0 
```

### Implementation
Most PHP frameworks like Symfony and Laravel allows you to register classes as services; If you like, you can register
this Event store where you bind it to the EventStoreInterface.

#### Connect with MongoDB
When constructing the [Event Store](/src/Event/EventStore/MongoDb.php) you're required to pass an instance of
the [MongoDB client](https://www.mongodb.com/docs/php-library/current/) as first argument, an Event describer instance
as second argument and your [Database and Collection](https://www.mongodb.com/docs/php-library/current/databases-collections)
names as third and fourth arguments.

```php

// ...

use MongoDB\Client as MongoDbClient;
use TwanHaverkamp\EventSourcingWithPhp\Event\EventDescriber;
use TwanHaverkamp\EventStorageInMongoDbWithPhp\Event\EventStore;

// ...

$eventStore = new EventStore\MongoDb(
    new MongoDbClient(sprintf(
        'mongodb://%s:%s@%s:%d',
        'root',
        'password',
        'mongodb',
        27017,
    )),
    new EventDescriber\KebabCase(),
    'your-database-name',
    'your-collection-name',
);

// ...
```

> An Event describer can be found in the "Event Sourcing with PHP" library, which is automatically installed
> whenever you install this package. You can create your own Describer if you like; just make sure it implements the
> EventDescriberInterface, which can also be found in the "Event Sourcing with PHP" library.

#### Event registration
In order for the Event Store to know which type of Events exist, you need to register them in the Event Store:

```php
use TwanHaverkamp\EventSourcingWithPhp\Example;
use TwanHaverkamp\EventStorageInMongoDbWithPhp\Event\EventStore;

EventStore\MongoDb::register(
    Example\Event\InvoiceWasCreated::class,
    Example\Event\PaymentTransactionWasStarted::class,
    Example\Event\PaymentTransactionWasCompleted::class,
    Example\Event\PaymentTransactionWasCancelled::class,
);
```

> You have to register your Events before actually using the Event Store.

#### Event storage
When you pass an Aggregate to the `save` function it loops over its Events and for every Event it will insert
a new "document" for the constructed [Database and Collection](https://www.mongodb.com/docs/php-library/current/databases-collections) names.

### Dependency injection

#### Laravel project

Create your own service provider when your working in a [Laravel](https://laravel.com) project to bind the MongoDB class
to the EventStoreInterface:

```php
namespace App\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use MongoDB\Client as MongoDbClient;
use TwanHaverkamp\EventSourcingWithPhp\Event\EventDescriber\KebabCase;
use TwanHaverkamp\EventSourcingWithPhp\Event\EventStore\EventStoreInterface;
use TwanHaverkamp\EventStorageInMongoDbWithPhp\Event\EventStore\MongoDb;

class EventStoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EventStoreInterface::class, function (Application $app) {
            return new MongoDb(
                new MongoDbClient(sprintf(
                    'mongodb://%s:%s@%s:%d',
                    config('mongodb.username'),
                    config('mongodb.password'),
                    config('mongodb.host'),
                    config('mongodb.port'),
                )),
            new EventDescriber\KebabCase(),
            config('mongodb.database'),
            config('mongodb.collection'),
        });
    }
}
```

#### Symfony project

If you're working in a [Symfony](https://symfony.com/) project, you can leverage it's built-in "autowire" mechanism
by registering the Event Store as a service in the `services.yaml`: 

```yaml
services:
  _defaults:
    bind:
      TwanHaverkamp\EventSourcingWithPhp\Event\EventDescriber\EventDescriberInterface: '@event.describer'
      TwanHaverkamp\EventSourcingWithPhp\Event\EventStore\EventStoreInterface: '@event_store.mongodb'

  # ...

  event.describer:
    class: TwanHaverkamp\EventSourcingWithPhp\Event\EventDescriber\KebabCase

  event_store.mongodb:
    class: TwanHaverkamp\EventStorageInMongoDbWithPhp\Event\EventStore\MongoDb
    arguments:
      $client: '@mongodb.client'
      $databaseName: '%env(string:MONGODB_DATABASE)%'
      $collectionName: '%env(string:MONGODB_COLLECTION)%'

  mongodb.client:
    class: MongoDB\Client
    arguments:
      $uri: '%env(string:MONGODB_URI)%'

  # ...

```
