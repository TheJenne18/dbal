<?php
namespace Enqueue\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Enqueue\Psr\InvalidMessageException;
use Enqueue\Psr\PsrConsumer;
use Enqueue\Psr\PsrMessage;
use Enqueue\Util\JSON;

class DbalConsumer implements PsrConsumer
{
    /**
     * @var DbalContext
     */
    private $context;

    /**
     * @var Connection
     */
    private $dbal;

    /**
     * @var DbalDestination
     */
    private $queue;

    /**
     * @var string
     */
    private $consumerId;

    /**
     * @var int microseconds
     */
    private $pollingInterval = 1000000;

    /**
     * @param DbalContext     $context
     * @param DbalDestination $queue
     */
    public function __construct(DbalContext $context, DbalDestination $queue)
    {
        $this->context = $context;
        $this->queue = $queue;
        $this->dbal = $this->context->getDbalConnection();
        $this->consumerId = uniqid('', true);
    }

    /**
     * Set polling interval in milliseconds
     *
     * @param int $msec
     */
    public function setPollingInterval($msec)
    {
        $this->pollingInterval = $msec * 1000;
    }

    /**
     * Get polling interval in milliseconds
     *
     * @return int
     */
    public function getPollingInterval()
    {
        return (int) $this->pollingInterval / 1000;
    }

    /**
     * {@inheritdoc}
     *
     * @return DbalDestination
     */
    public function getQueue()
    {
        return $this->queue;
    }

    /**
     * {@inheritdoc}
     *
     * @return DbalMessage|null
     */
    public function receive($timeout = 0)
    {
        $startAt = microtime(true);

        while (true) {
            $message = $this->receiveMessage();

            if ($message) {
                return $message;
            }

            if ($timeout && (microtime(true) - $startAt) >= $timeout) {
                return;
            }

            usleep($this->pollingInterval);

            if ($timeout && (microtime(true) - $startAt) >= $timeout) {
                return;
            }
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return DbalMessage|null
     */
    public function receiveNoWait()
    {
        return $this->receiveMessage();
    }

    /**
     * {@inheritdoc}
     *
     * @param DbalMessage $message
     */
    public function acknowledge(PsrMessage $message)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @param DbalMessage $message
     */
    public function reject(PsrMessage $message, $requeue = false)
    {
        InvalidMessageException::assertMessageInstanceOf($message, DbalMessage::class);

        if (false == $requeue) {
            return;
        }

        $dbalMessage = [
            'body' => $message->getBody(),
            'headers' => JSON::encode($message->getHeaders()),
            'properties' => JSON::encode($message->getProperties()),
            'priority' => $message->getPriority(),
            'queue' => $this->queue->getQueueName(),
            'redelivered' => true,
        ];

        $affectedRows = $this->dbal->insert($this->context->getTableName(), $dbalMessage, [
            'body' => Type::TEXT,
            'headers' => Type::TEXT,
            'properties' => Type::TEXT,
            'priority' => Type::SMALLINT,
            'queue' => Type::STRING,
            'redelivered' => Type::BOOLEAN,
        ]);

        if (1 !== $affectedRows) {
            throw new \LogicException(sprintf(
                'Expected record was inserted but it is not. message: "%s"',
                JSON::encode($dbalMessage)
            ));
        }
    }

    /**
     * @return DbalMessage|null
     */
    protected function receiveMessage()
    {
        $this->dbal->beginTransaction();
        try {
            $now = time();

            $sql = sprintf(
                'SELECT id FROM %s WHERE queue=:queue AND consumer_id IS NULL AND ' .
                '(delayed_until IS NULL OR delayed_until<=:delayedUntil) ' .
                'ORDER BY priority DESC, id ASC LIMIT 1 FOR UPDATE',
                $this->context->getTableName()
            );

            $dbalMessage = $this->dbal->executeQuery(
                $sql,
                [
                    'queue' => $this->queue->getQueueName(),
                    'delayedUntil' => $now,
                ],
                [
                    'queue' => Type::STRING,
                    'delayedUntil' => Type::INTEGER,
                ]
            )->fetch();

            if ($dbalMessage) {
                $message = $this->convertMessage($dbalMessage);

                $affectedRows = $this->dbal->delete($this->context->getTableName(), ['id' => $message->getId()], [
                    'id' => Type::INTEGER,
                ]);

                if (1 !== $affectedRows) {
                    throw new \LogicException(sprintf(
                        'Expected record was removed but it is not. id: "%s"',
                        $message->getId()
                    ));
                }

                $this->dbal->commit();

                return $message;
            }

            $this->dbal->commit();
        } catch (\LogicException $e) {
            $this->dbal->rollBack();
            throw $e;
        } catch (\Exception $e) {
            $this->dbal->rollBack();
        }
    }

    /**
     * @param array $dbalMessage
     *
     * @return DbalMessage
     */
    protected function convertMessage(array $dbalMessage)
    {
        $message = $this->context->createMessage();

        $message->setId($dbalMessage['id']);
        $message->setBody($dbalMessage['body']);
        $message->setPriority((int) $dbalMessage['priority']);
        $message->setRedelivered((bool) $dbalMessage['redelivered']);

        if ($dbalMessage['headers']) {
            $message->setHeaders(JSON::decode($dbalMessage['headers']));
        }

        if ($dbalMessage['properties']) {
            $message->setProperties(JSON::decode($dbalMessage['properties']));
        }

        return $message;
    }

    /**
     * @return string
     */
    public function getId()
    {
        return $this->consumerId;
    }
}
