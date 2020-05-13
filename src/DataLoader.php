<?php

declare(strict_types=1);

namespace leinonen\DataLoader;

use Amp\Loop;
use Amp\Deferred;
use Amp\Promise;
use Amp\Success;
use Amp\Failure;
use function Amp\Promise\all;

final class DataLoader implements DataLoaderInterface
{
    /**
     * @var callable
     */
    private $batchLoadFunction;

    private $promiseQueue = [];

    private $promiseCache;

    private $options;

    /**
     * Initiates a new DataLoader.
     *
     * @param callable $batchLoadFunction The function which will be called for the batch loading.
     * It must accept an array of keys and returns a Promise which resolves to an array of values.
     * @param CacheMapInterface $cacheMap
     * @param null|DataLoaderOptions $options
     */
    public function __construct(
        callable $batchLoadFunction,
        CacheMapInterface $cacheMap,
        DataLoaderOptions $options = null
    ) {
        $this->batchLoadFunction = $batchLoadFunction;
        $this->promiseCache = $cacheMap;
        $this->options = $options ?? new DataLoaderOptions();
    }

    /**
     * {@inheritdoc}
     */
    public function load($key): Promise
    {
        if ($key === null) {
            throw new \InvalidArgumentException(self::class . '::load must be called with a value, but got null');
        }

        if ($this->options->shouldCache() && $this->promiseCache->get($key)) {
            return $this->promiseCache->get($key);
        }

		$deferred = new Deferred();
		$resolve = array($deferred, 'resolve');
		$fail = array($deferred, 'fail');

		$this->promiseQueue[] = [
			'key'     => $key,
			'resolve' => $resolve,
			'reject'  => $fail,
		];

		if( \count( $this->promiseQueue ) === 1 ) {
			$this->scheduleDispatch();
		}

		$promise = $deferred->promise();

		if( $this->options->shouldCache() ) {
			$this->promiseCache->set( $key, $promise );
		}

		return $promise;
    }

    /**
     * {@inheritdoc}
     */
    public function loadMany(array $keys): Promise
    {
        return all(
            \array_map(
                function ( $key) {
					return $this->load( $key );
				},
                $keys
            )
        );
    }

    /**
     * {@inheritdoc}
     */
    public function clear($key): void
    {
        $this->promiseCache->delete($key);
    }

    /**
     * {@inheritdoc}
     */
    public function clearAll(): void
    {
        $this->promiseCache->clear();
    }

    /**
     * {@inheritdoc}
     */
    public function prime($key, $value): void
    {
        if (! $this->promiseCache->get($key)) {
            // Cache a rejected promise if the value is an Exception, in order to match
            // the behavior of load($key).
            $promise = $value instanceof \Exception
				? new Failure( $value )
				: new Success( $value );

            $this->promiseCache->set($key, $promise);
        }
    }

    /**
     * Schedules the dispatch to happen on the next tick of the EventLoop
     * If batching is disabled, schedule the dispatch immediately.
     *
     * @return void
     */
    private function scheduleDispatch(): void
    {
		if( $this->options->shouldBatch() ) {
			Loop::defer(
				function () {
					return $this->dispatchQueue();
				}
			);

			return;
		}

		$this->dispatchQueue();
	}

    /**
     * Resets and dispatches the DataLoaders queue.
     *
     * @return void
     */
    private function dispatchQueue(): void
    {
        $queue = $this->promiseQueue;
        $this->promiseQueue = [];

        $maxBatchSize = $this->options->getMaxBatchSize();
        $shouldBeDispatchedInMultipleBatches = $maxBatchSize !== null
            && $maxBatchSize > 0
            && $maxBatchSize < count($queue);

        $shouldBeDispatchedInMultipleBatches
            ? $this->dispatchQueueInMultipleBatches($queue, $maxBatchSize)
            : $this->dispatchQueueBatch($queue);
    }

    /**
     * Dispatches a batch of a queue. The given batch can also be the whole queue.
     *
     * @param array $batch
     */
    private function dispatchQueueBatch($batch)
    {
        $keys = \array_column($batch, 'key');
        $batchLoadFunction = $this->batchLoadFunction;

        /** @var Promise $batchPromise */
        $batchPromise = $batchLoadFunction($keys);

        try {
            $this->validateBatchPromise($batchPromise);
        } catch (DataLoaderException $exception) {
            return $this->handleFailedDispatch($batch, $exception);
        }

        $batchPromise
			->onResolve(
				function ($error, $values) use ($batch, $keys) {
					if($error) {
						$this->handleFailedDispatch($batch, $error);
					}
					else {
						try {
							$this->validateBatchPromiseOutput($values, $keys);
						} catch (DataLoaderException $exception) {
							return $this->handleFailedDispatch($batch, $exception);
						}

						$this->handleSuccessfulDispatch($batch, $values);
					}
				}
			);
    }

    /**
     * Dispatches the given queue in multiple batches.
     *
     * @param array $queue
     * @param int $maxBatchSize
     *
     * @return void
     */
    private function dispatchQueueInMultipleBatches(array $queue, $maxBatchSize): void
    {
        $numberOfBatchesToDispatch = \count($queue) / $maxBatchSize;

        for ($i = 0; $i < $numberOfBatchesToDispatch; $i++) {
            $this->dispatchQueueBatch(
                \array_slice($queue, $i * $maxBatchSize, $maxBatchSize)
            );
        }
    }

    /**
     * Handles the batch by resolving the promises and rejecting ones that return Exceptions.
     *
     * @param array $batch
     * @param array $values
     */
    private function handleSuccessfulDispatch(array $batch, array $values): void
    {
        foreach ($batch as $index => $queueItem) {
            $value = $values[$index];
            $value instanceof \Exception
                ? $queueItem['reject']($value)
                : $queueItem['resolve']($value);
        }
    }

    /**
     * Handles the failed batch dispatch.
     *
     * @param array $batch
     * @param \Exception $error
     */
    private function handleFailedDispatch(array $batch, \Exception $error)
    {
        foreach ($batch as $index => $queueItem) {
            // We don't want to cache individual loads if the entire batch dispatch fails.
            $this->clear($queueItem['key']);
            $queueItem['reject']($error);
        }
    }

    /**
     * Validates the batch promise's output.
     *
     * @param array $values Values from resolved promise.
     * @param array $keys Keys which the DataLoaders load was called with
     *
     * @throws DataLoaderException
     */
    private function validateBatchPromiseOutput($values, $keys): void
    {
        if (! \is_array($values)) {
            throw new DataLoaderException(
                self::class . ' must be constructed with a function which accepts ' .
                'an array of keys and returns a Promise which resolves to an array of values ' .
                \sprintf('not return a Promise: %s.', \gettype($values))
            );
        }

        if (\count($values) !== \count($keys)) {
            throw new DataLoaderException(
                self::class . ' must be constructed with a function which accepts ' .
                'an array of keys and returns a Promise which resolves to an array of values, but ' .
                'the function did not return a Promise of an array of the same length as the array of keys.' .
                \sprintf("\n Keys: %s\n Values: %s\n", \count($keys), \count($values))
            );
        }
    }

    /**
     * Validates the batch promise returned from the batch load function.
     *
     * @param $batchPromise
     *
     * @throws DataLoaderException
     */
    private function validateBatchPromise($batchPromise): void
    {
        if (! $batchPromise || ! \is_callable([$batchPromise, 'onResolve'])) {
            throw new DataLoaderException(
                self::class . ' must be constructed with a function which accepts ' .
                'an array of keys and returns a Promise which resolves to an array of values ' .
                \sprintf('the function returned %s.', \gettype($batchPromise))
            );
        }
    }
}
