<?php

declare(strict_types=1);

namespace Storm\Saga\Store\Inspection;

use Storm\Saga\Workflow\CompensationRecord;

/**
 * A saga instance read for introspection: its scalar state plus its armed timers, the commands it has
 * issued, and its compensation log, THE forensic data of a halted saga: what was undone, what was
 * skipped, and why. The gateway returns one per workflow type sharing the correlation, so the rare
 * cross-saga case is preserved, not collapsed like the store's `find()`. Display-shaped; the console
 * command renders it.
 *
 * Everything here is FRAMEWORK bookkeeping: which shape it runs, which run it is, how much budget it
 * spent, when it last moved. The business bags, `vars` and `context`, are NOT exposed as such: they
 * are app-authored and may carry PII or secrets, and this contract is served over HTTP by ApiOps as
 * well as to the console. The one sanctioned window is `exposed`: the subset of `vars` the workflow
 * itself declared with `#[ExposesState]`, filtered by the gateway against the compiled allowlist.
 * It is closed by default, OMISSION rather than masking.
 *
 * `updatedAt` is the one an operator reaches for first, since "how long has this been stuck" is the
 * first question a halted saga raises and answering it otherwise means querying the table by hand.
 * `generation`, `definitionVersion` and `stateVersion` are the three axes that look alike and are
 * not: which RUN of the correlation this is, which declared GRAPH it was born under, and which shape
 * of the DATA bags the row currently carries.
 *
 * @see SagaInspectionGateway
 */
final readonly class SagaSnapshot
{
    /**
     * @param  array<string, array{n: int, since: string|null}>  $retries  state key => visit ledger: attempt count
     *                                                                     plus the visit window's opening instant
     * @param  list<CompensationRecord>  $compensations  the rollback log, in completion order
     * @param  list<TimerSnapshot>  $timers
     * @param  list<OutboxSnapshot>  $outbox
     * @param  list<ChildSnapshot>  $children  every child row, any status: the tree walk's next hop
     */
    public function __construct(
        public string $workflowType,
        public string $stateKey,
        public string $status,
        public int $version,
        public ?string $startedAt,
        public ?string $updatedAt,
        public int $generation,
        public int $definitionVersion,
        public int $retryTotal,
        public ?string $waivedAt,
        public array $retries,
        public array $compensations,
        public array $timers,
        public array $outbox,
        public ?string $parentWorkflowType = null,
        public ?string $parentCorrelationId = null,
        public ?string $rootCorrelationId = null,
        public array $children = [],
        public int $stateVersion = 1,
        /** @var array<string, mixed> the `#[ExposesState]`-declared subset of vars; [] when closed, the default */
        public array $exposed = [],
        public int $retimes = 0,
        /** The operator freeze, instance level: the stamp and its reason; null when executable. */
        public ?string $pausedAt = null,
        public ?string $pausedReason = null,
        /**
         * Whether the whole workflow TYPE is frozen, a fact no instance carries: the type freeze lives
         * in `workflow_pauses` alone and gates births as well as steps.
         */
        public bool $typePaused = false,
    ) {}

    /**
     * The machine-readable shape, snake_case wire keys, everything untruncated with full command
     * FQCNs and whole error strings: one contract for every renderer, so the console `--json` and
     * the ops HTTP surface serve THIS and a script reads one format wherever it looks.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'workflow_type' => $this->workflowType,
            'state_key' => $this->stateKey,
            'status' => $this->status,
            'version' => $this->version,
            'started_at' => $this->startedAt,
            'paused_at' => $this->pausedAt,
            'paused_reason' => $this->pausedReason,
            'type_paused' => $this->typePaused,
            'updated_at' => $this->updatedAt,
            'generation' => $this->generation,
            'definition_version' => $this->definitionVersion,
            'state_version' => $this->stateVersion,
            'retry_total' => $this->retryTotal,
            'retimes' => $this->retimes,
            'waived_at' => $this->waivedAt,
            'retries' => $this->retries,
            'exposed' => $this->exposed,
            'compensations' => array_map(static fn (CompensationRecord $c): array => $c->toArray(), $this->compensations),
            'timers' => array_map(static fn (TimerSnapshot $t): array => $t->toArray(), $this->timers),
            'outbox' => array_map(static fn (OutboxSnapshot $o): array => $o->toArray(), $this->outbox),
            'parent_workflow_type' => $this->parentWorkflowType,
            'parent_correlation_id' => $this->parentCorrelationId,
            'root_correlation_id' => $this->rootCorrelationId,
            'children' => array_map(static fn (ChildSnapshot $c): array => $c->toArray(), $this->children),
        ];
    }
}
