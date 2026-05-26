<?php

declare(strict_types=1);

namespace AllStak\Monolog;

use AllStak\AllStak;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

/**
 * Monolog handler that forwards log records into AllStak.
 *
 * Records at or above {@see $eventLevel} (default ERROR) are captured as
 * AllStak *events*: if the record carries an exception in
 * `$record['context']['exception']` it is sent via captureError() with the
 * full stack trace, otherwise via captureMessage(). Records below that level
 * (but at or above the handler's own threshold) are recorded as AllStak
 * *breadcrumbs* so they show up as context on the next captured event.
 *
 * This makes the AllStak SDK usable in ANY PHP application — and in plain
 * Symfony via the framework's Monolog integration — without writing capture
 * calls by hand. Wire it into your Monolog stack:
 *
 *     use AllStak\AllStak;
 *     use AllStak\Monolog\AllStakHandler;
 *     use Monolog\Logger;
 *     use Monolog\Level;
 *
 *     AllStak::init(['apiKey' => 'allstak_live_...']);
 *
 *     $log = new Logger('app');
 *     // Forward everything from DEBUG up; ERROR+ become events, the rest
 *     // become breadcrumbs.
 *     $log->pushHandler(new AllStakHandler(AllStak::getInstance(), Level::Debug, Level::Error));
 *
 * Requires monolog/monolog ^3.0. The handler is a no-op (it never throws,
 * never blocks the host logger) when the SDK has not been initialised.
 */
final class AllStakHandler extends AbstractProcessingHandler
{
    /**
     * Records at or above this Monolog level are captured as events; lower
     * records (still at/above the handler bubble level) become breadcrumbs.
     */
    private int $eventLevel;

    /**
     * Reentrancy guard. The SDK logs its own diagnostics to STDERR (never
     * through Monolog), so true recursion is not possible through the normal
     * path — but a misconfigured host that routes SDK output back into the
     * same Monolog channel could loop. This flag short-circuits any record
     * produced while we are already inside write().
     */
    private bool $handling = false;

    private ?AllStak $sdk;

    /**
     * @param AllStak|null $sdk        The SDK instance. Defaults to the
     *                                 singleton from {@see AllStak::getInstance()}.
     * @param int|string|Level $level  Minimum level the handler reacts to at
     *                                 all (records below this bubble through
     *                                 untouched). Defaults to DEBUG so lower
     *                                 records become breadcrumbs.
     * @param int|string|Level $eventLevel Level at/above which a record is
     *                                 captured as an event instead of a
     *                                 breadcrumb. Defaults to ERROR.
     * @param bool $bubble             Whether handled records bubble to the
     *                                 next handler in the stack.
     */
    public function __construct(
        ?AllStak $sdk = null,
        int|string|Level $level = Level::Debug,
        int|string|Level $eventLevel = Level::Error,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
        $this->sdk = $sdk ?? AllStak::getInstance();
        $this->eventLevel = self::toLevelInt($eventLevel);
    }

    protected function write(LogRecord $record): void
    {
        if ($this->handling) {
            return;
        }

        $sdk = $this->sdk ?? AllStak::getInstance();
        if ($sdk === null || $sdk->isDisabled()) {
            return;
        }

        $this->handling = true;
        try {
            $levelValue = $record->level->value;

            if ($levelValue >= $this->eventLevel) {
                $this->captureEvent($sdk, $record);
            } else {
                $this->recordBreadcrumb($sdk, $record);
            }
        } catch (Throwable $e) {
            // Never let logging break the host application.
        } finally {
            $this->handling = false;
        }
    }

    /**
     * Capture an ERROR+ record as an AllStak event. When the record context
     * carries a Throwable (Monolog's conventional `context.exception` key), it
     * is sent via captureError() so the full stack trace is preserved;
     * otherwise the message is sent via captureMessage().
     */
    private function captureEvent(AllStak $sdk, LogRecord $record): void
    {
        $context = $record->context;
        $exception = $context['exception'] ?? null;

        $metadata = $this->buildMetadata($record);

        if ($exception instanceof Throwable) {
            $sdk->captureError($exception, [
                'level' => self::mapLevel($record->level),
                'metadata' => $metadata,
            ]);
            return;
        }

        $sdk->captureMessage(
            $record->message,
            self::mapLevel($record->level),
            $metadata,
        );
    }

    /**
     * Record a sub-event record as an AllStak breadcrumb so it appears as
     * context on the next captured event.
     */
    private function recordBreadcrumb(AllStak $sdk, LogRecord $record): void
    {
        $level = match (true) {
            $record->level->value >= Level::Error->value => 'error',
            $record->level->value >= Level::Warning->value => 'warn',
            $record->level->value <= Level::Debug->value => 'debug',
            default => 'info',
        };

        $sdk->addBreadcrumb(
            'log',
            $record->message,
            $level,
            $this->breadcrumbData($record),
        );
    }

    /**
     * Build event metadata from the record: channel + sanitisable context
     * (minus the exception object, which is captured separately).
     *
     * @return array<string,mixed>
     */
    private function buildMetadata(LogRecord $record): array
    {
        $context = $record->context;
        unset($context['exception']);

        $metadata = $context;
        $metadata['monolog.channel'] = $record->channel;
        $metadata['monolog.level'] = $record->level->getName();

        if (!empty($record->extra)) {
            $metadata['monolog.extra'] = $record->extra;
        }

        return $metadata;
    }

    /**
     * @return array<string,mixed>
     */
    private function breadcrumbData(LogRecord $record): array
    {
        $context = $record->context;
        unset($context['exception']);
        $context['channel'] = $record->channel;
        return $context;
    }

    /**
     * Map a Monolog level to an AllStak error/message level
     * (debug|info|warn|error|fatal).
     */
    private static function mapLevel(Level $level): string
    {
        return match ($level) {
            Level::Emergency, Level::Alert, Level::Critical => 'fatal',
            Level::Error => 'error',
            Level::Warning, Level::Notice => 'warn',
            Level::Info => 'info',
            Level::Debug => 'debug',
        };
    }

    /**
     * Normalise an int|string|Level into a Monolog integer level value.
     */
    private static function toLevelInt(int|string|Level $level): int
    {
        if ($level instanceof Level) {
            return $level->value;
        }
        if (is_int($level)) {
            return Level::from($level)->value;
        }
        return Level::fromName($level)->value;
    }
}
