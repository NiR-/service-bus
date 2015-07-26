<?php
/*
 * This file is part of the prooph/service-bus.
 * (c) 2014 - 2015 prooph software GmbH <contact@prooph.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * Date: 22.05.15 - 22:16
 */
namespace Prooph\ServiceBus;

use Prooph\ServiceBus\Exception\MessageDispatchException;
use Prooph\ServiceBus\Exception\RuntimeException;
use React\Promise\Deferred;
use React\Promise\Promise;

/**
 * Class QueryBus
 *
 * The query bus dispatches a query message to a finder.
 * The query is maybe dispatched async so the bus returns a promise
 * which gets either resolved with the response of the finder or rejected with an exception.
 * Additionally the finder can provide an update status but this is not guaranteed.
 *
 * @package Prooph\ServiceBus
 * @author Alexander Miertsch <kontakt@codeliner.ws>
 */
class QueryBus extends MessageBus
{
    const EVENT_LOCATE_FINDER      = 'locate-finder';
    const EVENT_INVOKE_FINDER      = 'invoke-finder';

    const EVENT_PARAM_FINDER = 'query-finder';
    const EVENT_PARAM_DEFERRED = 'query-deferred';

    /**
     * @param mixed $query
     * @return Promise
     */
    public function dispatch($query)
    {
        $deferred = new Deferred();

        $promise = $deferred->promise();

        $actionEvent = $this->getActionEventEmitter()->getNewActionEvent();

        $actionEvent->setTarget($this);

        $actionEvent->setParam(self::EVENT_PARAM_DEFERRED, $deferred);

        try {
            $this->initialize($query, $actionEvent);

            if ($actionEvent->getParam(self::EVENT_PARAM_FINDER) === null) {
                $actionEvent->setName(self::EVENT_ROUTE);
                $this->trigger($actionEvent);
            }

            if ($actionEvent->getParam(self::EVENT_PARAM_FINDER) === null) {
                throw new RuntimeException(sprintf(
                    "QueryBus was not able to identify a Finder for query %s",
                    $this->getMessageType($query)
                ));
            }

            if (is_string($actionEvent->getParam(self::EVENT_PARAM_FINDER))) {
                $actionEvent->setName(self::EVENT_LOCATE_FINDER);

                $this->trigger($actionEvent);
            }

            $finder = $actionEvent->getParam(self::EVENT_PARAM_FINDER);

            if (is_callable($finder)) {
                $finder($query, $deferred);
            } else {
                $actionEvent->setName(self::EVENT_INVOKE_FINDER);
                $this->trigger($actionEvent);
            }

            $this->triggerFinalize($actionEvent);
        } catch (\Exception $ex) {
            $failedPhase = $actionEvent->getName();

            $actionEvent->setParam(self::EVENT_PARAM_EXCEPTION, $ex);

            $this->triggerError($actionEvent);
            $this->triggerFinalize($actionEvent);

            //Check if a listener has removed the exception to indicate that it was able to handle it
            if ($ex = $actionEvent->getParam(self::EVENT_PARAM_EXCEPTION)) {
                $actionEvent->setName($failedPhase);
                $deferred->reject(MessageDispatchException::failed($actionEvent, $ex));
            }
        }

        return $promise;
    }
}