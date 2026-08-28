<?php

declare(strict_types=1);

namespace Storm\Saga\Testing\InMemory;

use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * The runtime's announcement sink: every dispatched `Saga*` announcement is recorded in order for
 * inspection. The executor dispatches after its unit settles, so what lands here is COMMITTED
 * facts only; a step that rolled back announced nothing, the same visibility a listener has in the
 * wired deployment.
 */
final class RecordingSagaEvents implements EventDispatcherInterface
{
    /** @var list<object> */
    private array $announcements = [];

    public function dispatch(object $event): object
    {
        $this->announcements[] = $event;

        return $event;
    }

    /**
     * @return list<object>
     */
    public function announcements(): array
    {
        return $this->announcements;
    }

    /** Forget what was announced so a scenario's next phase asserts only its own facts. */
    public function reset(): void
    {
        $this->announcements = [];
    }
}
