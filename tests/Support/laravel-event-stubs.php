<?php

declare(strict_types=1);

/**
 * Minimal stand-ins for optional Laravel event classes that are NOT pulled in
 * by this package's dev dependencies (the queue / cache / redis components).
 *
 * The Laravel integration registers its listeners against the canonical
 * framework class names (e.g. Illuminate\Queue\Events\JobProcessing). To drive
 * those listeners in a unit test without installing the whole framework, we
 * define byte-compatible stubs under the exact same namespace — but ONLY when
 * the real class is absent. In a full Laravel install the real classes win and
 * these stubs are skipped, so this can never shadow framework behavior.
 *
 * Each stub exposes the public shape the integration reads (resolveName(),
 * connectionName, attempts(), command, exitCode, key, etc).
 */

namespace Illuminate\Queue\Events {
    if (!\class_exists(JobProcessing::class, false)) {
        class JobProcessing
        {
            public function __construct(
                public string $connectionName,
                public object $job
            ) {
            }
        }
    }
    if (!\class_exists(JobProcessed::class, false)) {
        class JobProcessed
        {
            public function __construct(
                public string $connectionName,
                public object $job
            ) {
            }
        }
    }
    if (!\class_exists(JobFailed::class, false)) {
        class JobFailed
        {
            public function __construct(
                public string $connectionName,
                public object $job,
                public ?\Throwable $exception = null
            ) {
            }
        }
    }
    if (!\class_exists(JobExceptionOccurred::class, false)) {
        class JobExceptionOccurred
        {
            public function __construct(
                public string $connectionName,
                public object $job,
                public ?\Throwable $exception = null
            ) {
            }
        }
    }
}

namespace Illuminate\Log\Events {
    if (!\class_exists(MessageLogged::class, false)) {
        class MessageLogged
        {
            public function __construct(
                public string $level,
                public string $message,
                public array $context = []
            ) {
            }
        }
    }
}

namespace AllStak\Tests\Support {
    /**
     * A fake queued-job contract exposing the methods the integration calls.
     */
    final class FakeQueueJob
    {
        public function __construct(
            private string $name = 'App\\Jobs\\SendEmail',
            private int $attempts = 1,
            private string $queue = 'default',
            private string $jobId = 'job-1'
        ) {
        }

        public function resolveName(): string
        {
            return $this->name;
        }

        public function attempts(): int
        {
            return $this->attempts;
        }

        public function getQueue(): string
        {
            return $this->queue;
        }

        public function getJobId(): string
        {
            return $this->jobId;
        }
    }
}
