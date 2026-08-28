<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Contract;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Storm\Clock\PointInTime;
use Storm\Message\Header;
use Storm\Message\Message;
use Storm\Saga\Child\ChildCorrelation;
use Storm\Saga\Child\ParentRef;
use Storm\Saga\Engine\EffectEvidence;
use Storm\Saga\Exception\CorrelationAlreadyConsumed;
use Storm\Saga\Exception\CorrelationAlreadyOwned;
use Storm\Saga\Exception\SagaStateTooLarge;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Exception\StaleWorkflowInstance;
use Storm\Saga\Locking\SagaStepUnitOfWork;
use Storm\Saga\Outbox\RedriveOutcome;
use Storm\Saga\Outbox\WorkflowOutboxWriter;
use Storm\Saga\Store\TimerKind;
use Storm\Saga\Store\WorkflowId;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Store\WorkflowInstanceStore;
use Storm\Saga\Store\WorkflowStatus;
use Storm\Saga\Store\WorkflowTimerStore;
use Storm\Saga\Tests\Testing\Fixture\ReserveInventory;
use Storm\Saga\Workflow\CorrelationReuse;

/**
 * The store laws every saga persistence adapter must honor where the semantics overlap, run
 * against the DBAL adapters on PostgreSQL and against the in-memory adapters as they stand. Every
 * law here takes its instants explicitly, so the two time sources never diverge; concurrency, SQL
 * and crash laws are deliberately absent, they belong to the DBAL suite alone.
 */
trait SagaStoreContractLaws
{
    abstract protected function contractInstances(): WorkflowInstanceStore;

    abstract protected function contractTimers(): WorkflowTimerStore;

    abstract protected function contractCommands(): WorkflowOutboxWriter;

    abstract protected function contractFence(): SagaStepUnitOfWork;

    abstract protected function contractNow(): PointInTime;

    /**
     * Ages the row's last touch by `$seconds` against the adapter's own time source: the in-memory
     * twin advances its controlled clock, the DBAL twin rewinds `updated_at`, so an elapsed-time
     * law reads the same on both.
     *
     * @param  positive-int  $seconds
     */
    abstract protected function ageInstance(WorkflowId $id, int $seconds): void;

    #[Test]
    public function a_zero_quiet_window_sweeps_any_aged_waived_row(): void
    {
        // the sweep contract is "untouched for at least the window": zero is a legal window a
        // production probe already relies on, and a row aged one second past its waive is quiet
        // under it; an adapter flooring the window would hand a maintenance consumer a calmer
        // world than its production twin sweeps
        $instances = $this->contractInstances();
        $instances->create($this->row('law', 'c-quiet-zero'));
        $row = $instances->find(new WorkflowId('law', 'c-quiet-zero'));
        $this->assertNotNull($row);
        $instances->update($row->waived($this->contractNow()));

        $this->ageInstance(new WorkflowId('law', 'c-quiet-zero'), 1);

        $this->assertSame(['c-quiet-zero'], array_map(static fn (WorkflowInstanceRow $r): string => $r->correlationId, $instances->waivedAndQuiet(0)));
        $this->assertSame([], $instances->waivedAndQuiet(3600)); // a wide window keeps the fresh row out
    }

    #[Test]
    public function a_first_claim_is_generation_one_and_a_reject_correlation_is_spent_by_it(): void
    {
        $instances = $this->contractInstances();

        $generation = $instances->create($this->row('law', 'c-gen'));
        $this->assertSame(1, $generation);
        // the birth anchor is the GIVEN instant, never re-stamped by the store's own clock: an
        // instant distinct from now proves the store kept it rather than agreeing by coincidence
        $born = $this->contractNow()->subSeconds(100);
        $instances->create(WorkflowInstanceRow::fresh(new WorkflowId('law', 'c-born'), 'await', [], [], $born, 1));
        $this->assertSame($born->toString(), $instances->find(new WorkflowId('law', 'c-born'))?->startedAt?->toString());

        $instances->delete(new WorkflowId('law', 'c-gen')); // the prune: the claim must outlive it
        $this->expectException(CorrelationAlreadyConsumed::class);
        $instances->create($this->row('law', 'c-gen'));
    }

    #[Test]
    public function an_allowing_correlation_numbers_its_runs(): void
    {
        $instances = $this->contractInstances();

        $this->assertSame(1, $instances->create($this->row('law', 'c-again'), CorrelationReuse::Allow));
        $instances->delete(new WorkflowId('law', 'c-again'));
        $this->assertSame(2, $instances->create($this->row('law', 'c-again'), CorrelationReuse::Allow));
    }

    #[Test]
    public function an_update_refuses_a_stale_version_and_bumps_the_winner(): void
    {
        $instances = $this->contractInstances();
        $instances->create($this->row('law', 'c-occ'));

        $loaded = $instances->find(new WorkflowId('law', 'c-occ'));
        $this->assertNotNull($loaded);
        $instances->update($loaded->restingAt('next', WorkflowStatus::Running, ['step' => 1], [], []));
        $this->assertSame(1, $instances->find(new WorkflowId('law', 'c-occ'))?->version);

        $this->expectException(StaleWorkflowInstance::class);
        $instances->update($loaded->restingAt('other', WorkflowStatus::Running, [], [], []));
    }

    #[Test]
    #[Group('adversarial')]
    public function a_step_update_cannot_clobber_the_freeze_pair(): void
    {
        $instances = $this->contractInstances();
        $instances->create($this->row('law', 'c-freeze'));
        $this->assertTrue($instances->pauseInstance(new WorkflowId('law', 'c-freeze'), 'window'));

        // the mover threads a row read BEFORE the pause landed: its nulls must not win
        $loaded = $instances->find(new WorkflowId('law', 'c-freeze'));
        $this->assertNotNull($loaded);
        $bare = new WorkflowInstanceRow($loaded->workflowType, $loaded->correlationId, 'next', WorkflowStatus::Running, version: $loaded->version, generation: $loaded->generation);
        $instances->update($bare);

        $this->assertNotNull($instances->find(new WorkflowId('law', 'c-freeze'))?->pausedAt);
    }

    #[Test]
    public function the_resume_lifts_and_releases_the_claims_as_one_and_an_unfrozen_saga_is_untouched(): void
    {
        $instances = $this->contractInstances();
        $timers = $this->contractTimers();
        $now = $this->contractNow();

        $instances->create($this->row('law', 'c-resume'));
        $timers->arm(new WorkflowId('law', 'c-resume'), 'await', TimerKind::Timeout, $now);
        $this->assertCount(1, $timers->claimDue(10, $now));

        $instances->pauseInstance(new WorkflowId('law', 'c-resume'), null);
        $this->assertTrue($instances->resumeInstance(new WorkflowId('law', 'c-resume')));
        $this->assertCount(1, $timers->claimDue(10, $now)); // the claim came back with the lift

        $this->assertFalse($instances->resumeInstance(new WorkflowId('law', 'c-resume')));
        $this->assertSame([], $timers->claimDue(10, $now)); // nothing lifted, nothing released
    }

    #[Test]
    public function a_re_frozen_type_still_lifts_with_a_single_resume(): void
    {
        // re-pausing is a no-op, not a second freeze stacking on the first, so one lift clears it.
        // That the FIRST reason is the one kept cannot be judged here: the port exposes the freeze
        // as a boolean and hands back no reason, so each adapter pins that payload where it lives
        $instances = $this->contractInstances();

        $instances->pauseType('law-frozen', 'window');
        $instances->pauseType('law-frozen', 'a different reason');

        $this->assertTrue($instances->pausedType('law-frozen'));
        $this->assertTrue($instances->resumeType('law-frozen'));
        $this->assertFalse($instances->pausedType('law-frozen'), 'one lift clears one freeze, whatever the number of stamps');
    }

    #[Test]
    public function the_type_lift_releases_that_type_claims_and_leaves_the_other_types_alone(): void
    {
        $instances = $this->contractInstances();
        $timers = $this->contractTimers();
        $now = $this->contractNow();

        $instances->create($this->row('law-frozen', 'c-frozen'));
        $instances->create($this->row('law-open', 'c-open'));
        $timers->arm(new WorkflowId('law-frozen', 'c-frozen'), 'await', TimerKind::Timeout, $now);
        $timers->arm(new WorkflowId('law-open', 'c-open'), 'await', TimerKind::Timeout, $now);
        $this->assertCount(2, $timers->claimDue(10, $now));

        $instances->pauseType('law-frozen', null);

        $this->assertTrue($instances->resumeType('law-frozen'));
        $released = $timers->claimDue(10, $now);
        $this->assertCount(1, $released, 'the lift releases the frozen type alone, never the neighbour it never held');
        $this->assertSame('law-frozen', $released[0]->workflowType);
    }

    #[Test]
    public function lifting_a_type_that_was_never_frozen_refuses_and_releases_nothing(): void
    {
        $instances = $this->contractInstances();
        $timers = $this->contractTimers();
        $now = $this->contractNow();

        $instances->create($this->row('law-open', 'c-untouched'));
        $timers->arm(new WorkflowId('law-open', 'c-untouched'), 'await', TimerKind::Timeout, $now);
        $this->assertCount(1, $timers->claimDue(10, $now));

        $this->assertFalse($instances->resumeType('law-open'));
        $this->assertSame([], $timers->claimDue(10, $now), 'nothing lifted, nothing released');
    }

    #[Test]
    public function a_failed_effect_strands_only_a_saga_that_is_still_running(): void
    {
        // the reconciliation read an operator acts on: a saga still waiting on an effect that
        // failed is stranded and needs a decision, while the same failure under a settled saga is
        // history, already resolved by whatever settled it
        $instances = $this->contractInstances();
        $commands = $this->contractCommands();

        foreach (['c-stranded', 'c-stranded-too', 'c-settled'] as $correlation) {
            $instances->create($this->row('law', $correlation));
            $commands->write(new WorkflowId('law', $correlation), $this->sealed('m-'.$correlation), 'issuing', 3, 1);
            $this->publish($commands, $correlation, 'm-'.$correlation);
            $commands->markFailed($correlation, 'm-'.$correlation, 'boom', EffectEvidence::Uncommitted);
        }

        $settled = $instances->find(new WorkflowId('law', 'c-settled'));
        $this->assertNotNull($settled);
        $instances->update(new WorkflowInstanceRow(
            $settled->workflowType,
            $settled->correlationId,
            $settled->stateKey,
            WorkflowStatus::Completed,
            version: $settled->version,
            generation: $settled->generation,
        ));

        // a failure whose saga was never born, or was already deleted, has no one to strand: the
        // read joins on the instance and drops what it cannot find
        $commands->write(new WorkflowId('law', 'c-orphan'), $this->sealed('m-orphan'), 'issuing', 3, 1);
        $this->publish($commands, 'c-orphan', 'm-orphan');
        $commands->markFailed('c-orphan', 'm-orphan', 'boom', EffectEvidence::Uncommitted);

        // every stranded pair comes back, not just the first one an operator would happen to see;
        // and IN correlation order, part of the port's contract on both adapters, so a persistent
        // poison cannot reshuffle which sagas get reconciled run to run
        $this->assertSame(
            [['c-stranded', 'm-c-stranded'], ['c-stranded-too', 'm-c-stranded-too']],
            $instances->strandedByFailedEffect(),
        );
    }

    #[Test]
    public function the_stranded_scan_reaches_a_live_saga_behind_a_settled_ones_failure(): void
    {
        // the law above leaves both live rows FIRST, so a scan that stopped on the settled one still
        // answered right. A settled saga's failed effect is skipped, and the scan must keep reading:
        // ending there would report nothing whenever a settled failure is older than a live one, and
        // the stranded saga would wait for a reconciliation that never names it.
        $instances = $this->contractInstances();
        $commands = $this->contractCommands();

        foreach (['c-quiet', 'c-live'] as $correlation) {
            $instances->create($this->row('law', $correlation));
            $commands->write(new WorkflowId('law', $correlation), $this->sealed('m-'.$correlation), 'issuing', 3, 1);
            $this->publish($commands, $correlation, 'm-'.$correlation);
            $commands->markFailed($correlation, 'm-'.$correlation, 'boom', EffectEvidence::Uncommitted);
        }

        $quiet = $instances->find(new WorkflowId('law', 'c-quiet'));
        $this->assertNotNull($quiet);
        $instances->update(new WorkflowInstanceRow(
            $quiet->workflowType,
            $quiet->correlationId,
            $quiet->stateKey,
            WorkflowStatus::Completed,
            version: $quiet->version,
            generation: $quiet->generation,
        ));

        $this->assertSame([['c-live', 'm-c-live']], $instances->strandedByFailedEffect());
    }

    #[Test]
    public function the_stranded_page_is_ordered_and_bounded(): void
    {
        // the deterministic page: a mass dead-letter is also a producer of stranded rows, so the
        // scan reads limit-first in correlation order and repeated passes drain the backlog page
        // by page, settled rows leaving the result set.
        //
        // Written in REVERSE on purpose: rows inserted already in order cannot tell a sorted page
        // from an unsorted one, and the assertion below would hold over an adapter that dropped its
        // ordering entirely.
        $instances = $this->contractInstances();
        $commands = $this->contractCommands();

        foreach (['c-page-c', 'c-page-b', 'c-page-a'] as $correlation) {
            $instances->create($this->row('law', $correlation));
            $commands->write(new WorkflowId('law', $correlation), $this->sealed('m-'.$correlation), 'issuing', 3, 1);
            $this->publish($commands, $correlation, 'm-'.$correlation);
            $commands->markFailed($correlation, 'm-'.$correlation, 'boom', EffectEvidence::Uncommitted);
        }

        $this->assertSame(
            [['c-page-a', 'm-c-page-a'], ['c-page-b', 'm-c-page-b']],
            $instances->strandedByFailedEffect(limit: 2),
            'the first page in correlation order, bounded at the limit',
        );
    }

    #[Test]
    public function the_running_census_counts_by_type_then_version_and_ignores_the_settled(): void
    {
        // what a rollout reads to know whether an old definition version still has live sagas on
        // it; a settled instance is not a reason to keep a version alive
        $instances = $this->contractInstances();
        $now = $this->contractNow();

        $instances->create($this->row('law', 'c-v1'));
        $instances->create($this->row('law', 'c-v1-bis')); // a second live saga on the SAME version: the census adds up, it does not overwrite
        $instances->create(WorkflowInstanceRow::fresh(new WorkflowId('law', 'c-v2'), 'await', [], [], $now, 2));
        $instances->create($this->row('other', 'c-other'));
        $instances->create($this->row('law', 'c-done'));

        $done = $instances->find(new WorkflowId('law', 'c-done'));
        $this->assertNotNull($done);
        $instances->update(new WorkflowInstanceRow(
            $done->workflowType,
            $done->correlationId,
            $done->stateKey,
            WorkflowStatus::Completed,
            version: $done->version,
            generation: $done->generation,
        ));

        $this->assertSame(
            ['law' => [1 => 2, 2 => 1], 'other' => [1 => 1]],
            $instances->runningCountsByVersion(),
        );
    }

    #[Test]
    public function a_parent_counts_its_children_and_the_serialized_count_agrees(): void
    {
        // the two counts answer the same question: the plain one for a report, the serialized one
        // under the advisory spawn lane, where sequential execution IS the serialization
        $instances = $this->contractInstances();
        $now = $this->contractNow();
        $instances->create($this->row('law', 'c-parent'));

        foreach (['kyc', 'screening'] as $slot) {
            $instances->create(WorkflowInstanceRow::fresh(
                new WorkflowId('law-child', 'c-parent'.ChildCorrelation::DELIMITER.$slot),
                'await', [], [ParentRef::CONTEXT_KEY => new ParentRef('law', 'c-parent', 'c-parent', $slot, 1)->toContext()],
                $now, 1,
            ));
        }

        $this->assertSame(2, $instances->countChildren('c-parent'));
        $this->assertSame(2, $instances->countChildrenSerialized('c-parent'));
        $this->assertSame(0, $instances->countChildren('c-childless'));
    }

    #[Test]
    public function a_family_counts_every_member_it_spawned_and_the_living_subset_separately(): void
    {
        // the indexed fan-out reads both: `spawned` is the ceiling the declaration bought, taken
        // from the correlations, and `living` is what still runs. A settled member stays spawned
        $instances = $this->contractInstances();
        $now = $this->contractNow();
        $instances->create($this->row('law', 'c-family'));

        foreach (['leg-0', 'leg-1', 'leg-2', 'leg-3'] as $member) {
            $instances->create(WorkflowInstanceRow::fresh(
                new WorkflowId('law-child', 'c-family'.ChildCorrelation::DELIMITER.$member),
                'await', [], [ParentRef::CONTEXT_KEY => new ParentRef('law', 'c-family', 'c-family', $member, 1)->toContext()],
                $now, 1,
            ));
        }

        // ONE settled of four, deliberately: with a living count of three, none of the filter's
        // negations lands on it, neither the settled count, nor the out-of-family one, nor the
        // whole-condition flip. A half-and-half family would let a broken status test read as right
        foreach (['leg-1'] as $done) {
            $settled = $instances->find(new WorkflowId('law-child', 'c-family'.ChildCorrelation::DELIMITER.$done));
            $this->assertNotNull($settled);
            $instances->update(new WorkflowInstanceRow(
                $settled->workflowType, $settled->correlationId, $settled->stateKey, WorkflowStatus::Completed,
                version: $settled->version, generation: $settled->generation,
            ));
        }

        // asymmetric ON PURPOSE: with one living and one settled, a negated status test
        // would count the same total and the law would pass over a broken filter
        $this->assertSame(4, $instances->spawnedMembers('c-family', 'leg'));
        $this->assertSame(3, $instances->livingMembers('c-family', 'leg'));
    }

    #[Test]
    public function a_family_counts_its_indexed_members_and_never_a_neighbouring_slot(): void
    {
        // Membership is `family-<digits>`, nothing else. A static slot named `leg-final` beside an
        // indexed family `leg` is a DIFFERENT slot, and the build gate admits it precisely because it
        // cannot collide with a member, refusing only `leg-<digit>`. An adapter matching on the
        // prefix alone counts it as a fifth leg, and the two adapters then disagree in the one
        // direction nothing else can see: the family gate crosses on the twin and rests forever on
        // the database, a saga stuck with every member concluded.
        //
        // The three non-members are three DIFFERENT refusals, and a list of one would leave two of
        // them unproven: `leg-final` carries no digit at all, `leg-12abc` OPENS on digits, and
        // `leg-abc12` CLOSES on them. The suffix test is anchored at both ends, so dropping either
        // anchor lets one of the last two in, and only a fixture carrying both shapes can tell.
        $instances = $this->contractInstances();
        $now = $this->contractNow();
        $instances->create($this->row('law', 'c-neighbour'));

        foreach (['leg-0', 'leg-1', 'leg-final', 'leg-12abc', 'leg-abc12'] as $slot) {
            $instances->create(WorkflowInstanceRow::fresh(
                new WorkflowId('law-child', 'c-neighbour'.ChildCorrelation::DELIMITER.$slot),
                'await', [], [ParentRef::CONTEXT_KEY => new ParentRef('law', 'c-neighbour', 'c-neighbour', $slot, 1)->toContext()],
                $now, 1,
            ));
        }

        $this->assertSame(2, $instances->spawnedMembers('c-neighbour', 'leg'), 'the neighbour is not a member, however alike its name reads');
        $this->assertSame(2, $instances->livingMembers('c-neighbour', 'leg'));
    }

    #[Test]
    public function the_adoptable_parent_is_the_row_that_owns_the_correlation_or_nothing(): void
    {
        // a birth reads its parent under a shared lock; an absent parent is not an error here, it
        // is the not-yet-committed case the spawn retries on
        $instances = $this->contractInstances();
        $instances->create($this->row('law', 'c-adoptable'));

        $found = $instances->loadAdoptableParent('c-adoptable');

        $this->assertNotNull($found);
        $this->assertSame('c-adoptable', $found->correlationId);
        $this->assertNull($instances->loadAdoptableParent('c-never-born'));
    }

    #[Test]
    public function a_fresh_arm_is_a_fresh_budget(): void
    {
        $timers = $this->contractTimers();
        $now = $this->contractNow();
        $id = new WorkflowId('law', 'c-arm');
        $this->contractInstances()->create($this->row('law', 'c-arm'));

        $timers->arm($id, 'await', TimerKind::Timeout, $now);
        $claimed = $timers->claimDue(10, $now);
        $this->assertCount(1, $claimed);
        $timers->recordFailure($claimed[0]->id, 'boom');
        $timers->park($claimed[0]->id, 'boom');
        $this->assertSame([], $timers->claimDue(10, $now)); // parked rows never claim

        $timers->arm($id, 'await', TimerKind::Timeout, $now);
        $this->assertCount(1, $timers->claimDue(10, $now)); // claim, park and attempts all reset
    }

    #[Test]
    public function a_timer_still_in_the_future_does_not_hide_the_due_ones_behind_it(): void
    {
        // the claim walks the armed rows and SKIPS those not yet due; ending on the first of them
        // would let a saga's deadline pass unclaimed for no reason but the order its timers were
        // armed in, and a deadline that never fires is a saga that never times out
        $timers = $this->contractTimers();
        $now = $this->contractNow();
        $id = new WorkflowId('law', 'c-due-order');
        $this->contractInstances()->create($this->row('law', 'c-due-order'));

        $timers->arm($id, 'later', TimerKind::Timeout, $now->addSeconds(600));
        $timers->arm($id, 'await', TimerKind::Timeout, $now);

        $claimed = $timers->claimDue(10, $now);

        $this->assertCount(1, $claimed);
        $this->assertSame('await', $claimed[0]->stateKey);
    }

    #[Test]
    public function unpark_refuses_an_unknown_row_and_one_that_was_never_parked(): void
    {
        $timers = $this->contractTimers();
        $now = $this->contractNow();
        $this->contractInstances()->create($this->row('law', 'c-unpark'));
        $timers->arm(new WorkflowId('law', 'c-unpark'), 'await', TimerKind::Timeout, $now);
        $armed = $timers->listFor(new WorkflowId('law', 'c-unpark'));
        $this->assertCount(1, $armed);

        $this->assertFalse($timers->unpark($armed[0]->id));
        $this->assertFalse($timers->unpark($armed[0]->id + 1000));

        $timers->park($armed[0]->id, 'boom');
        $this->assertTrue($timers->unpark($armed[0]->id));
        $this->assertCount(1, $timers->claimDue(10, $now));
    }

    #[Test]
    public function the_claim_is_a_lease_and_the_freeze_gates_it_while_the_global_deadline_traverses(): void
    {
        $instances = $this->contractInstances();
        $timers = $this->contractTimers();
        $now = $this->contractNow();
        $id = new WorkflowId('law', 'c-lease');

        $instances->create($this->row('law', 'c-lease'));
        $timers->arm($id, 'await', TimerKind::Timeout, $now);
        $timers->arm($id, WorkflowTimerStore::GLOBAL_KEY, TimerKind::Global, $now);

        $this->assertCount(2, $timers->claimDue(10, $now));
        $this->assertSame([], $timers->claimDue(10, $now)); // held by the lease
        $later = $now->addSeconds(301);
        $this->assertCount(2, $timers->claimDue(10, $later)); // the lease elapsed, re-claimable

        $instances->pauseInstance($id, 'window');
        $expired = $later->addSeconds(301);
        $claimed = $timers->claimDue(10, $expired);
        $this->assertSame([TimerKind::Global], array_map(static fn ($t): TimerKind => $t->kind, $claimed));
    }

    #[Test]
    public function the_dead_letter_flip_takes_only_a_published_row(): void
    {
        $commands = $this->contractCommands();
        $id = new WorkflowId('law', 'c-flip');
        $this->contractInstances()->create($this->row('law', 'c-flip'));
        $commands->write($id, $this->sealed('m-flip'), 'issuing', 3, 1);

        $this->assertFalse($commands->markFailed('c-flip', 'm-flip', 'boom')); // pending is the relay's
        $this->assertNull($commands->provenance('c-flip', 'm-flip', 1)); // and not yet a dead letter
    }

    #[Test]
    public function provenance_answers_a_failed_row_sealed_to_its_run_and_sees_the_alive_sibling(): void
    {
        $commands = $this->contractCommands();
        $id = new WorkflowId('law', 'c-prov');
        $this->contractInstances()->create($this->row('law', 'c-prov'));
        $commands->write($id, $this->sealed('m-a'), 'issuing', 3, 1);
        $commands->write($id, $this->sealed('m-b'), 'issuing', 3, 1); // same step marker: the sibling

        $this->publish($commands, 'c-prov', 'm-a');
        $this->assertTrue($commands->markFailed('c-prov', 'm-a', 'boom', EffectEvidence::Uncommitted));

        $this->assertNull($commands->provenance('c-prov', 'm-a', 2)); // a past run's pairing is refused

        $provenance = $commands->provenance('c-prov', 'm-a', 1);
        $this->assertNotNull($provenance);
        $this->assertSame('issuing', $provenance->issuedFromState);
        $this->assertTrue($provenance->hasAliveSiblings);
        $this->assertSame(EffectEvidence::Uncommitted, $provenance->evidence);
    }

    #[Test]
    public function the_redrive_guards_refuse_in_the_operator_actionable_order(): void
    {
        $commands = $this->contractCommands();
        $id = new WorkflowId('law', 'c-redrive');
        $this->contractInstances()->create($this->row('law', 'c-redrive'));
        $commands->write($id, $this->sealed('m-red'), 'issuing', 3, 1);

        $this->assertSame(RedriveOutcome::NotFound, $commands->redrive('c-redrive', 'm-ghost'));
        $this->assertSame(RedriveOutcome::NotDeadLettered, $commands->redrive('c-redrive', 'm-red'));

        $this->publish($commands, 'c-redrive', 'm-red');
        $commands->markFailed('c-redrive', 'm-red', 'boom'); // evidence stays Unknown
        $this->assertSame(RedriveOutcome::EffectUnproven, $commands->redrive('c-redrive', 'm-red'));

        $this->assertSame(RedriveOutcome::Redriven, $commands->redrive('c-redrive', 'm-red', force: true));
        $this->assertSame(RedriveOutcome::NotDeadLettered, $commands->redrive('c-redrive', 'm-red')); // back in flight
    }

    #[Test]
    public function cancel_pending_recalls_only_what_the_relay_never_claimed(): void
    {
        $commands = $this->contractCommands();
        $id = new WorkflowId('law', 'c-recall');
        $this->contractInstances()->create($this->row('law', 'c-recall'));
        $commands->write($id, $this->sealed('m-p1'), 'issuing', 3, 1);
        $commands->write($id, $this->sealed('m-p2'), 'issuing', 3, 1);
        $this->publish($commands, 'c-recall', 'm-p2');

        $this->assertSame(1, $commands->cancelPending($id));
        $this->assertSame(0, $commands->cancelPending($id)); // nothing pending is left to recall
    }

    #[Test]
    #[Group('adversarial')]
    public function a_throw_mid_unit_restores_the_instance_the_timer_and_the_command_together(): void
    {
        $instances = $this->contractInstances();
        $timers = $this->contractTimers();
        $commands = $this->contractCommands();
        $fence = $this->contractFence();
        $now = $this->contractNow();
        $id = new WorkflowId('law', 'c-atomic');

        try {
            $fence->tryWithin($id, function () use ($instances, $timers, $commands, $id, $now): void {
                $instances->create($this->row('law', 'c-atomic'));
                $timers->arm($id, 'await', TimerKind::Timeout, $now);
                $commands->write($id, $this->sealed('m-atomic'), 'issuing', 0, 1);

                throw new RuntimeException('the step dies after all three families were written');
            });
            $this->fail('the unit must surface the throwable');
        } catch (RuntimeException) {
            // the law: everything the unit wrote is gone together
        }

        $this->assertNull($instances->find($id));
        $this->assertSame([], $timers->listFor($id));
        $this->assertSame(RedriveOutcome::NotFound, $commands->redrive('c-atomic', 'm-atomic'));

        // and the same unit retried commits whole
        $committed = $this->contractFence()->tryWithin($id, function () use ($instances, $timers, $commands, $id, $now): void {
            $instances->create($this->row('law', 'c-atomic'));
            $timers->arm($id, 'await', TimerKind::Timeout, $now);
            $commands->write($id, $this->sealed('m-atomic'), 'issuing', 0, 1);
        });

        $this->assertTrue($committed);
        $this->assertNotNull($instances->find($id));
        $this->assertCount(1, $timers->listFor($id));
    }

    #[Test]
    public function an_arm_is_keyed_by_all_four_dimensions(): void
    {
        $timers = $this->contractTimers();
        $now = $this->contractNow();
        $later = $now->addSeconds(500);
        $this->contractInstances()->create($this->row('law', 'c-key'));
        $this->contractInstances()->create($this->row('law', 'c-key2'));

        $timers->arm(new WorkflowId('law', 'c-key'), 'await', TimerKind::Timeout, $now);
        $timers->arm(new WorkflowId('law', 'c-key'), 'other', TimerKind::Timeout, $now);
        $timers->arm(new WorkflowId('law', 'c-key'), 'await', TimerKind::Kick, $now);
        $timers->arm(new WorkflowId('law', 'c-key2'), 'await', TimerKind::Timeout, $now);

        // the upsert must key on ALL of type, correlation, state and kind: re-arming one row moves
        // it alone, and a neighbor differing in any single dimension keeps its instant
        $timers->arm(new WorkflowId('law', 'c-key'), 'await', TimerKind::Timeout, $later);

        $this->assertSame($later->toString(), $timers->fireAt(new WorkflowId('law', 'c-key'), 'await', TimerKind::Timeout)?->toString());
        $this->assertSame($now->toString(), $timers->fireAt(new WorkflowId('law', 'c-key'), 'other', TimerKind::Timeout)?->toString());
        $this->assertSame($now->toString(), $timers->fireAt(new WorkflowId('law', 'c-key'), 'await', TimerKind::Kick)?->toString());
        $this->assertSame($now->toString(), $timers->fireAt(new WorkflowId('law', 'c-key2'), 'await', TimerKind::Timeout)?->toString());
    }

    #[Test]
    public function every_failure_budget_counts_from_zero_wherever_it_restarts(): void
    {
        $timers = $this->contractTimers();
        $now = $this->contractNow();
        $id = new WorkflowId('law', 'c-budget');
        $this->contractInstances()->create($this->row('law', 'c-budget'));

        $this->assertSame(0, $timers->recordFailure(987654, 'ghost')); // an unknown row counts nothing

        $timers->arm($id, 'await', TimerKind::Timeout, $now);
        $armed = $timers->listFor($id)[0]->id;
        $this->assertSame(1, $timers->recordFailure($armed, 'boom'));
        $this->assertSame(2, $timers->recordFailure($armed, 'boom'));

        $timers->arm($id, 'await', TimerKind::Timeout, $now); // the fresh arm resets the budget
        $this->assertSame(1, $timers->recordFailure($armed, 'boom'));

        $timers->park($armed, 'boom');
        $this->assertTrue($timers->unpark($armed)); // and so does the operator's unpark
        $this->assertSame(1, $timers->recordFailure($armed, 'boom'));
    }

    #[Test]
    public function the_claim_takes_the_earliest_due_rows_up_to_the_limit_in_a_stable_order(): void
    {
        $timers = $this->contractTimers();
        $now = $this->contractNow();
        $this->contractInstances()->create($this->row('law', 'c-order'));
        $id = new WorkflowId('law', 'c-order');

        $timers->arm($id, 'b', TimerKind::Timeout, $now->subSeconds(1));
        $timers->arm($id, 'c', TimerKind::Timeout, $now->subSeconds(1)); // same instant, armed later
        $timers->arm($id, 'a', TimerKind::Timeout, $now->subSeconds(2)); // earliest, armed last
        $timers->arm($id, 'd', TimerKind::Timeout, $now->addSeconds(100)); // future: never claimed

        $claimed = array_map(static fn ($t): string => $t->stateKey, $timers->claimDue(2, $now));
        sort($claimed);

        // the EARLIEST two are selected, the limit cuts, the future row waits; the returned order is
        // each adapter's own, the in-memory model pinning a deterministic one in its edge tests
        $this->assertSame(['a', 'b'], $claimed);
        $this->assertSame(['c'], array_map(static fn ($t): string => $t->stateKey, $timers->claimDue(10, $now)));
    }

    #[Test]
    public function a_lease_expiring_exactly_at_the_cutoff_is_reclaimable(): void
    {
        $timers = $this->contractTimers();
        $now = $this->contractNow();
        $this->contractInstances()->create($this->row('law', 'c-cutoff'));
        $timers->arm(new WorkflowId('law', 'c-cutoff'), 'await', TimerKind::Timeout, $now);

        $this->assertCount(1, $timers->claimDue(10, $now));
        $this->assertSame([], $timers->claimDue(10, $now->addSeconds(299))); // one second early, still leased
        // at now + lease exactly, the previous claim sits ON the cutoff: reclaimable, not held
        $this->assertCount(1, $timers->claimDue(10, $now->addSeconds(300)));
        // a caller-chosen short lease is honored to the second
        $this->assertSame([], $timers->claimDue(10, $now->addSeconds(300), leaseSeconds: 1));
        $this->assertCount(1, $timers->claimDue(10, $now->addSeconds(301), leaseSeconds: 1));
    }

    #[Test]
    public function list_for_returns_the_one_saga_in_firing_order(): void
    {
        $timers = $this->contractTimers();
        $now = $this->contractNow();
        $this->contractInstances()->create($this->row('law', 'c-mine'));
        $this->contractInstances()->create($this->row('law', 'c-theirs'));

        $timers->arm(new WorkflowId('law', 'c-mine'), 'late', TimerKind::Timeout, $now->addSeconds(60));
        $timers->arm(new WorkflowId('law', 'c-theirs'), 'noise', TimerKind::Timeout, $now);
        $timers->arm(new WorkflowId('law', 'c-mine'), 'soon', TimerKind::Kick, $now);

        $this->assertSame(
            ['soon', 'late'],
            array_map(static fn ($t): string => $t->stateKey, $timers->listFor(new WorkflowId('law', 'c-mine'))),
        );
    }

    #[Test]
    #[Group('adversarial')]
    public function the_resume_releases_only_the_claims_the_freeze_held(): void
    {
        $instances = $this->contractInstances();
        $timers = $this->contractTimers();
        $now = $this->contractNow();

        $instances->create($this->row('law', 'c-frozen'));
        $instances->create($this->row('law', 'c-bystander'));
        $timers->arm(new WorkflowId('law', 'c-frozen'), 'await', TimerKind::Timeout, $now);
        $timers->arm(new WorkflowId('law', 'c-frozen'), 'poison', TimerKind::Kick, $now);
        $timers->arm(new WorkflowId('law', 'c-frozen'), WorkflowTimerStore::GLOBAL_KEY, TimerKind::Global, $now);
        $timers->arm(new WorkflowId('law', 'c-bystander'), 'await', TimerKind::Timeout, $now);

        $claimed = $timers->claimDue(10, $now);
        $this->assertCount(4, $claimed);
        foreach ($claimed as $t) {
            if ($t->stateKey === 'poison') {
                $timers->park($t->id, 'boom');
            }
        }

        $instances->pauseInstance(new WorkflowId('law', 'c-frozen'), 'window');
        $this->assertTrue($instances->resumeInstance(new WorkflowId('law', 'c-frozen')));

        // the parked row kept its LEASE through the resume: un-parked, it must still wait it out
        foreach ($this->contractTimers()->listFor(new WorkflowId('law', 'c-frozen')) as $t) {
            if ($t->stateKey === 'poison') {
                $this->assertTrue($timers->unpark($t->id));
            }
        }

        // released: the frozen saga's unparked STATE timer alone; the parked row stays parked, the
        // global keeps its lease, and the bystander's claim is never disturbed
        $this->assertSame(
            ['await'],
            array_map(static fn ($t): string => $t->stateKey, $timers->claimDue(10, $now)),
        );
    }

    #[Test]
    public function a_freeze_takes_only_a_running_unfrozen_row(): void
    {
        $instances = $this->contractInstances();
        $instances->create($this->row('law', 'c-gate'));

        $this->assertTrue($instances->pauseInstance(new WorkflowId('law', 'c-gate'), 'once'));
        $this->assertFalse($instances->pauseInstance(new WorkflowId('law', 'c-gate'), 'twice'));

        $loaded = $instances->find(new WorkflowId('law', 'c-gate'));
        $this->assertNotNull($loaded);
        $instances->resumeInstance(new WorkflowId('law', 'c-gate'));
        $instances->update($loaded->restingAt('done', WorkflowStatus::Completed, [], [], []));
        $this->assertFalse($instances->pauseInstance(new WorkflowId('law', 'c-gate'), 'settled'));
    }

    #[Test]
    public function a_correlation_owned_by_another_type_refuses_the_birth(): void
    {
        $instances = $this->contractInstances();
        $instances->create($this->row('law', 'c-owned'));
        $this->assertNotNull($instances->findByCorrelation('c-owned'));

        $this->expectException(CorrelationAlreadyOwned::class);
        $instances->create($this->row('other', 'c-owned'));
    }

    #[Test]
    public function a_duplicate_birth_is_a_storage_translation(): void
    {
        $instances = $this->contractInstances();
        $instances->create($this->row('law', 'c-twice'));

        $this->expectException(SagaStorageFailure::class);
        $instances->create($this->row('law', 'c-twice'));
    }

    #[Test]
    public function the_state_cap_guards_the_birth_and_every_step_alike(): void
    {
        $instances = $this->contractInstances();
        $heavy = ['blob' => str_repeat('x', 9000)];

        try {
            $instances->create(WorkflowInstanceRow::fresh(new WorkflowId('law', 'c-fat'), 'await', $heavy, [], $this->contractNow(), 1));
            $this->fail('an oversized birth must be refused');
        } catch (SagaStateTooLarge $refused) {
            // the cap holds at the birth, and the breakdown names each bag's true weight
            $this->assertStringContainsString('vars=9011', $refused->getMessage());
        }

        $instances->create($this->row('law', 'c-grows'));
        $lean = $instances->find(new WorkflowId('law', 'c-grows'));
        $this->assertNotNull($lean);
        $this->expectException(SagaStateTooLarge::class);
        $instances->update($lean->restingAt('await', WorkflowStatus::Running, $heavy, [], []));
    }

    #[Test]
    public function provenance_stays_mute_on_a_row_that_never_named_its_issuing_state(): void
    {
        $commands = $this->contractCommands();
        $id = new WorkflowId('law', 'c-anon');
        $this->contractInstances()->create($this->row('law', 'c-anon'));
        $commands->write($id, $this->sealed('m-anon'), '', 3, 1);

        $this->publish($commands, 'c-anon', 'm-anon');
        $this->assertTrue($commands->markFailed('c-anon', 'm-anon', 'boom'));

        $this->assertNull($commands->provenance('c-anon', 'm-anon', 1));
    }

    #[Test]
    public function a_dead_command_with_no_living_step_sibling_says_so(): void
    {
        $commands = $this->contractCommands();
        $id = new WorkflowId('law', 'c-alone');
        $this->contractInstances()->create($this->row('law', 'c-alone'));
        $commands->write($id, $this->sealed('m-alone'), 'issuing', 3, 1);
        $commands->write($id, $this->sealed('m-elder'), 'issuing', 2, 1); // an EARLIER step, never a sibling
        $commands->write($id, $this->sealed('m-past'), 'issuing', 3, 2); // another RUN, never a sibling
        $this->contractInstances()->create($this->row('law', 'c-noise'));
        $commands->write(new WorkflowId('law', 'c-noise'), $this->sealed('m-noise'), 'issuing', 3, 1); // another saga

        $this->publish($commands, 'c-alone', 'm-alone');
        $this->assertTrue($commands->markFailed('c-alone', 'm-alone', 'boom'));

        $provenance = $commands->provenance('c-alone', 'm-alone', 1);
        $this->assertNotNull($provenance);
        $this->assertFalse($provenance->hasAliveSiblings);
    }

    #[Test]
    public function the_dead_letter_flip_is_keyed_by_the_correlation_too(): void
    {
        $commands = $this->contractCommands();
        $id = new WorkflowId('law', 'c-mid');
        $this->contractInstances()->create($this->row('law', 'c-mid'));
        $commands->write($id, $this->sealed('m-mid'), 'issuing', 3, 1);
        $this->publish($commands, 'c-mid', 'm-mid');

        $this->assertFalse($commands->markFailed('c-other', 'm-mid', 'boom'));
        $this->assertTrue($commands->markFailed('c-mid', 'm-mid', 'boom'));
    }

    #[Test]
    public function the_targeted_recall_touches_only_its_effect_group(): void
    {
        $commands = $this->contractCommands();
        $id = new WorkflowId('law', 'c-arm-recall');
        $this->contractInstances()->create($this->row('law', 'c-arm-recall'));
        $commands->write($id, $this->sealed('m-g1'), 'issuing', 3, 1, 'left');
        $commands->write($id, $this->sealed('m-g2'), 'issuing', 3, 1, 'right');
        $commands->write($id, $this->sealed('m-plain'), 'issuing', 3, 1);

        $this->contractInstances()->create($this->row('law', 'c-neighbor'));
        $commands->write(new WorkflowId('law', 'c-neighbor'), $this->sealed('m-nb'), 'issuing', 3, 1);

        $this->assertSame(1, $commands->cancelPending($id, 'left'));
        $this->assertSame(2, $commands->cancelPending($id)); // the same-type neighbor saga is untouched
        $this->assertSame(1, $commands->cancelPending(new WorkflowId('law', 'c-neighbor')));
    }

    #[Test]
    public function the_targeted_recall_reaches_its_group_behind_another_ones_row(): void
    {
        // the law above leaves the targeted group's row FIRST, so a recall that stopped at the first
        // foreign group would still answer 1. Written the other way round, a recall that reads only
        // until the first row of another group leaves its own arm pending and the losing arm's
        // command flies after the race was settled.
        $commands = $this->contractCommands();
        $id = new WorkflowId('law', 'c-arm-order');
        $this->contractInstances()->create($this->row('law', 'c-arm-order'));
        $commands->write($id, $this->sealed('m-other'), 'issuing', 3, 1, 'right');
        $commands->write($id, $this->sealed('m-target'), 'issuing', 3, 1, 'left');

        $this->assertSame(1, $commands->cancelPending($id, 'left'));
        $this->assertSame(1, $commands->cancelPending($id)); // the other group's row is still pending
    }

    #[Test]
    public function the_redrive_diagnosis_names_a_missing_saga_and_a_stale_generation(): void
    {
        $commands = $this->contractCommands();
        $instances = $this->contractInstances();

        $instances->create($this->row('law', 'c-gone'));
        $commands->write(new WorkflowId('law', 'c-gone'), $this->sealed('m-gone'), 'issuing', 3, 1);
        $this->publish($commands, 'c-gone', 'm-gone');
        $commands->markFailed('c-gone', 'm-gone', 'boom', EffectEvidence::Uncommitted);
        $instances->delete(new WorkflowId('law', 'c-gone'));
        $this->assertSame(RedriveOutcome::SagaNotRunning, $commands->redrive('c-gone', 'm-gone'));

        $instances->create($this->row('law', 'c-past'));
        $commands->write(new WorkflowId('law', 'c-past'), $this->sealed('m-past'), 'issuing', 3, 2); // sealed to a run that is not the living one
        $this->publish($commands, 'c-past', 'm-past');
        $commands->markFailed('c-past', 'm-past', 'boom', EffectEvidence::Uncommitted);
        $this->assertSame(RedriveOutcome::StaleGeneration, $commands->redrive('c-past', 'm-past'));
    }

    private function row(string $type, string $correlation): WorkflowInstanceRow
    {
        return WorkflowInstanceRow::fresh(new WorkflowId($type, $correlation), 'await', [], [], $this->contractNow(), 1);
    }

    private function sealed(string $messageId): Message
    {
        return new Message(new ReserveInventory('law'), [Header::MessageId->value => $messageId]);
    }

    /**
     * Reach `published` through the port surface alone: a redrive-shaped helper is not available,
     * so the concrete supplies its adapter's honest way of marking one pending row published.
     */
    abstract protected function publish(WorkflowOutboxWriter $commands, string $correlationId, string $messageId): void;
}
