<?php

declare(strict_types=1);

namespace Storm\Saga\Exception;

use LogicException;

/**
 * A `#[Workflow]` class is malformed, or conflicts with another at registration: a declaration/wiring
 * bug caught at build time, so it fails fast and never reaches runtime. The `#[Workflow]` attribute is
 * missing, an activity state has no activity, a wait state has no `#[WaitFor]`, `#[Start]` points at an
 * undeclared state, or two classes collide on a `(name, version)` pair or a per-name version label.
 */
final class InvalidWorkflowDefinition extends LogicException implements SagaException
{
    public static function noWorkflowAttribute(string $class): self
    {
        return new self(sprintf('Class "%s" is not a workflow: missing #[Workflow].', $class));
    }

    public static function workflowNameBlank(string $class): self
    {
        return new self(sprintf('#[Workflow] on class "%s" declares a blank name — the name is the routing and storage identity of every instance, and every refusal message names it. Give it one.', $class));
    }

    public static function versionBelowOne(string $workflow, int $version): self
    {
        return new self(sprintf('#[Workflow(name: "%s", version: %d)] — version must be >= 1.', $workflow, $version));
    }

    public static function duplicateVersion(string $workflow, int $version): self
    {
        return new self(sprintf(
            'Two #[Workflow] classes declare name "%s" at version %d — a (name, version) pair is unique. Bump one, '
            .'or co-register them under distinct versions.',
            $workflow, $version,
        ));
    }

    public static function duplicateLabel(string $workflow, string $label): self
    {
        return new self(sprintf(
            'Two versions of workflow "%s" share label "%s" — a version label is a unique human tag for one version. Rename one.',
            $workflow, $label,
        ));
    }

    public static function stateVersionBelowOne(string $workflow, int $stateVersion): self
    {
        return new self(sprintf('#[Workflow(name: "%s", stateVersion: %d)] — stateVersion must be >= 1.', $workflow, $stateVersion));
    }

    public static function stateVersionDisagreement(string $workflow, int $declared, int $version, int $conflicting): self
    {
        return new self(sprintf(
            'Co-registered versions of workflow "%s" disagree on stateVersion: %d declared, but version %d declares %d — '
            .'the data contract is per NAME because the versions share their activities. Align them.',
            $workflow, $declared, $version, $conflicting,
        ));
    }

    public static function exposedStateKeyBlank(string $workflow): self
    {
        return new self(sprintf('#[ExposesState] on workflow "%s" declares a blank key — a typo, never an exposure. Remove it.', $workflow));
    }

    public static function exposedStateKeyDuplicated(string $workflow, string $key): self
    {
        return new self(sprintf('#[ExposesState] on workflow "%s" declares key "%s" twice. Declare it once.', $workflow, $key));
    }

    public static function exposedStateDisagreement(string $workflow, int $version): self
    {
        return new self(sprintf(
            'Co-registered versions of workflow "%s" disagree on #[ExposesState]: version %d declares a different list — '
            .'the exposure allowlist is per NAME, a security declaration the versions must share. Align them.',
            $workflow, $version,
        ));
    }

    public static function retryBudgetNotPositive(string $workflow, int $budget): self
    {
        return new self(sprintf('#[Workflow(name: "%s", retryBudget: %d)] — retryBudget must be >= 1 (or null for unlimited).', $workflow, $budget));
    }

    public static function startDelayNotPositive(string $workflow, int $seconds): self
    {
        return new self(sprintf('#[Start(afterSeconds: %d)] on workflow "%s" — a birth delay must be >= 1 second (or null to drive at birth).', $seconds, $workflow));
    }

    public static function startDelayReachesGlobalTimeout(string $workflow, int $seconds, int $globalTimeout): self
    {
        return new self(sprintf('#[Start(afterSeconds: %d)] on workflow "%s" — the delay meets or exceeds globalTimeout %d: the cap anchors at BIRTH and includes the delay, so this saga would expire before it ever runs.', $seconds, $workflow, $globalTimeout));
    }

    public static function stateKeyBlank(string $workflow): self
    {
        return new self(sprintf('#[State] in workflow "%s" declares a blank key — the key is what every #[On], #[WaitFor] and #[Retry] names, and what the store and the console print. A typo, never a state.', $workflow));
    }

    public static function retryBaseDelayNotPositive(string $stateKey, int $baseMs, string $workflow): self
    {
        return new self(sprintf('#[Retry] baseMs: %d on state "%s" in workflow "%s" — a base delay must be >= 1 millisecond. The engine floors the wait at 1ms, so the attempts burn back to back against a downstream that is still down: a declared backoff that does not back off.', $baseMs, $stateKey, $workflow));
    }

    public static function retryPatternBlank(string $stateKey, string $list, string $workflow): self
    {
        return new self(sprintf('#[Retry] %s on state "%s" in workflow "%s" holds a blank pattern — patterns match by substring, and every class name and message contains the empty string, so the entry matches EVERY error. Name an error, or drop the entry.', $list, $stateKey, $workflow));
    }

    public static function transitionUnreachable(string $stateKey, string $to, string $trigger, string $workflow): self
    {
        return new self(sprintf('#[On(from: "%s", trigger: "%s", to: "%s")] in workflow "%s" is unreachable — an earlier unguarded #[On] on the same trigger always answers first, so this edge never fires. Guard the earlier one, or drop this edge.', $stateKey, $trigger, $to, $workflow));
    }

    public static function activityStateMissingActivity(string $stateKey, string $workflow): self
    {
        return new self(sprintf('Activity state "%s" in workflow "%s" declares no activity.', $stateKey, $workflow));
    }

    public static function waitStateMissingWaitFor(string $stateKey, string $workflow): self
    {
        return new self(sprintf('Wait state "%s" in workflow "%s" has no #[WaitFor].', $stateKey, $workflow));
    }

    public static function unknownStartState(string $stateKey, string $workflow): self
    {
        return new self(sprintf('#[Start] points at unknown state "%s" in workflow "%s".', $stateKey, $workflow));
    }

    public static function noStates(string $workflow): self
    {
        return new self(sprintf('Workflow "%s" declares no states.', $workflow));
    }

    public static function unresolvableActivity(string $activity, string $stateKey, string $workflow): self
    {
        return new self(sprintf('Activity "%s" for state "%s" in workflow "%s" is not a resolvable Activity service.', $activity, $stateKey, $workflow));
    }

    public static function transitionFromUnknownState(string $stateKey, string $workflow): self
    {
        return new self(sprintf('#[On(from: "%s")] references an undeclared state in workflow "%s".', $stateKey, $workflow));
    }

    public static function transitionToUnknownState(string $stateKey, string $workflow): self
    {
        return new self(sprintf('#[On(to: "%s")] references an undeclared state in workflow "%s".', $stateKey, $workflow));
    }

    public static function unknownGlobalTimeoutState(string $stateKey, string $workflow): self
    {
        return new self(sprintf('#[Workflow] onGlobalTimeout points at unknown state "%s" in workflow "%s".', $stateKey, $workflow));
    }

    public static function onGlobalTimeoutWithoutDeadline(string $onGlobalTimeout, string $workflow): self
    {
        return new self(sprintf(
            '#[Workflow] in "%s" declares onGlobalTimeout: "%s" but no globalTimeout — the target has no deadline '
            .'to fire it (dead config). Add a globalTimeout, or drop onGlobalTimeout. A globalTimeout without a '
            .'target is fine: it bounds the saga.',
            $workflow, $onGlobalTimeout,
        ));
    }

    public static function onGlobalTimeoutUnreachable(string $onGlobalTimeout, string $workflow): self
    {
        return new self(sprintf(
            '#[Workflow] in "%s" declares onGlobalTimeout: "%s", but every wait is effect-gating (the success-target '
            .'of an activity) and no async activity rests outside one. When the global deadline fires there the engine '
            .'can only halt + flag (bound the saga) — it never drives onGlobalTimeout, so the target is unreachable. '
            .'Add a non-gating resting point (a wait reached by an event, or an async activity with a timeout) to host '
            .'the forced resolution, or drop onGlobalTimeout for a bare cap.',
            $workflow, $onGlobalTimeout,
        ));
    }

    public static function waitDeclaresBothHeartbeatAndDeadline(string $waitState, string $workflow): self
    {
        return new self(sprintf(
            '#[WaitFor(state: "%s")] in workflow "%s" sets both heartbeatSeconds and deadlineSeconds — a wait either '
            .'pings (escalate) or expires (finalise), not both. Keep one.',
            $waitState, $workflow,
        ));
    }

    public static function waitDeclaresWallAndBusinessDeadline(string $waitState, string $workflow): self
    {
        return new self(sprintf(
            '#[WaitFor(state: "%s")] in workflow "%s" sets both a wall-clock deadlineSeconds and a business-time '
            .'deadline (deadlineBusinessDays/Hours) — a wait has one deadline mode. Keep one.',
            $waitState, $workflow,
        ));
    }

    public static function deadlineWithoutTarget(string $waitState, string $workflow): self
    {
        return new self(sprintf(
            '#[WaitFor(state: "%s")] in workflow "%s" sets deadlineSeconds without onDeadline — a business deadline '
            .'needs a state to finalise to. Add onDeadline, or use heartbeatSeconds for a liveness escalation.',
            $waitState, $workflow,
        ));
    }

    public static function onDeadlineWithoutDeadline(string $waitState, string $workflow): self
    {
        return new self(sprintf(
            '#[WaitFor(state: "%s")] in workflow "%s" sets onDeadline without deadlineSeconds — a finalise target with '
            .'no timer to fire it (dead config). Add deadlineSeconds, or drop onDeadline.',
            $waitState, $workflow,
        ));
    }

    public static function onDeadlineUnknownState(string $onDeadline, string $waitState, string $workflow): self
    {
        return new self(sprintf(
            '#[WaitFor(state: "%s", onDeadline: "%s")] in workflow "%s" finalises to an undeclared state.',
            $waitState, $onDeadline, $workflow,
        ));
    }

    public static function heartbeatOnNonGatingWait(string $waitState, string $workflow): self
    {
        return new self(sprintf(
            'Wait state "%s" in workflow "%s" declares heartbeatSeconds but is not effect-gating (it is not the '
            .'success-target of an activity). A heartbeat only escalates a wait whose committed effect is in flight; '
            .'on a wait reached by an event it would arm a timer with no finalise edge and silently halt. Use '
            .'deadlineSeconds + onDeadline for a business deadline here. (A liveness ping on a non-gating wait — the '
            .'soft-SLA case — is a future extension.)',
            $waitState, $workflow,
        ));
    }

    public static function retriableWaitWithoutHeartbeat(string $waitState, string $workflow): self
    {
        return new self(sprintf(
            'Wait state "%s" in workflow "%s" declares retriable: true but is not a heartbeat wait. retriable lets a '
            .'gating heartbeat wait re-arm past the global cap (its success is inevitable, post-pivot); it is meaningless '
            .'on a deadline wait or a wait without heartbeatSeconds. Pair it with heartbeatSeconds on a gating wait, or drop it.',
            $waitState, $workflow,
        ));
    }

    public static function waitForOnNonWaitState(string $stateKey, string $workflow): self
    {
        return new self(sprintf('#[WaitFor(state: "%s")] in workflow "%s" targets a state that is not a wait state.', $stateKey, $workflow));
    }

    public static function duplicateRaceArm(string $arm, string $stateKey, string $workflow): self
    {
        return new self(sprintf('Two #[RaceArm] on state "%s" in workflow "%s" share the arm name "%s" — the last one would silently win. Name each arm once.', $stateKey, $workflow, $arm));
    }

    public static function raceWithoutASuccessEdge(string $stateKey, string $workflow): self
    {
        return new self(sprintf('State "%s" in workflow "%s" declares #[RaceArm]s but no success transition — the settling wait is DERIVED from the success edge, so a race with nowhere to settle has no winner to crown.', $stateKey, $workflow));
    }

    public static function raceWithMoreThanOneSuccessEdge(string $stateKey, string $workflow): self
    {
        return new self(sprintf('State "%s" in workflow "%s" declares #[RaceArm]s and MORE THAN ONE success transition — the settling wait is derived from the success edge statically, taking the first and reading no guard, while the runtime selector answers with the first success edge whose guard passes: the race would arm one wait and leave through another. Keep exactly one success edge on a racing state.', $stateKey, $workflow));
    }

    public static function raceArmOnNonActivityState(string $stateKey, string $workflow): self
    {
        return new self(sprintf('#[RaceArm(state: "%s")] in workflow "%s" targets a state that is not an activity — only an activity can fan a race out.', $stateKey, $workflow));
    }

    public static function raceNeedsTwoArms(string $stateKey, string $workflow, int $count): self
    {
        return new self(sprintf('State "%s" in workflow "%s" declares %d #[RaceArm] — one arm is not a race; declare at least two, or drop the attribute.', $stateKey, $workflow, $count));
    }

    public static function raceStateAlsoCompensatable(string $stateKey, string $workflow): self
    {
        return new self(sprintf('State "%s" in workflow "%s" declares both #[RaceArm]s and #[Compensate] — a race compensates PER ARM by construction, and a state-level undo beside it would leave the rollback two truths. Keep the arms\' compensations.', $stateKey, $workflow));
    }

    public static function raceArmBlankName(string $stateKey, string $workflow): self
    {
        return new self(sprintf('A #[RaceArm] on state "%s" in workflow "%s" has a blank arm name — the name is the outbox effect_group and the log identity; it cannot be empty.', $stateKey, $workflow));
    }

    public static function raceArmUnknownClass(string $arm, string $field, string $class, string $stateKey, string $workflow): self
    {
        return new self(sprintf('#[RaceArm(state: "%s", arm: "%s")] in workflow "%s" names a %s class that does not exist: %s.', $stateKey, $arm, $workflow, $field, $class));
    }

    public static function raceArmsShareACommand(string $command, string $stateKey, string $workflow): self
    {
        return new self(sprintf('Two #[RaceArm] on state "%s" in workflow "%s" issue the same command class %s — the outbox stamping matches on the command class, so a shared one cannot be attributed to an arm.', $stateKey, $workflow, $command));
    }

    public static function raceArmOutcomesOverlap(string $arm, string $other, string $stateKey, string $workflow): self
    {
        return new self(sprintf('Arms "%s" and "%s" on state "%s" in workflow "%s" declare outcome classes that overlap by inheritance — one arriving event would crown two winners. Declare disjoint outcome classes.', $arm, $other, $stateKey, $workflow));
    }

    public static function raceSettlesOnNonWait(string $stateKey, string $settledBy, string $workflow): self
    {
        return new self(sprintf('The race on state "%s" in workflow "%s" settles on "%s", its success target, which is not a wait state — a race settles where its first outcome is awaited.', $stateKey, $workflow, $settledBy));
    }

    public static function raceWaitUsesAliases(string $stateKey, string $waitKey, string $workflow): self
    {
        return new self(sprintf('The wait "%s" settling the race on state "%s" in workflow "%s" accepts event type aliases — the winner lookup matches outcome CLASSES, so the settling wait must declare classes only.', $waitKey, $stateKey, $workflow));
    }

    public static function raceArmOutcomeNotAwaited(string $arm, string $outcome, string $waitKey, string $workflow): self
    {
        return new self(sprintf('Arm "%s" in workflow "%s" wins by %s, which the settling wait "%s" does not accept — an arm that cannot win through the wait that settles it is a fire-and-forget wearing a racer\'s number.', $arm, $workflow, $outcome, $waitKey));
    }

    public static function raceWaitAcceptsForeignEvent(string $accepted, string $waitKey, string $stateKey, string $workflow): self
    {
        return new self(sprintf('The wait "%s" settling the race on state "%s" in workflow "%s" also accepts %s, which no arm wins by — leaving through a foreign event would strand every loser undisposed. The settling wait accepts exactly the arms\' outcomes.', $waitKey, $stateKey, $workflow, $accepted));
    }

    public static function duplicateJoinArm(string $arm, string $stateKey, string $workflow): self
    {
        return new self(sprintf('Two #[JoinArm] on state "%s" in workflow "%s" share the arm name "%s" — the last one would silently win. Name each arm once.', $stateKey, $workflow, $arm));
    }

    public static function joinWithoutASuccessEdge(string $stateKey, string $workflow): self
    {
        return new self(sprintf('State "%s" in workflow "%s" declares #[JoinArm]s but no success transition — the joining wait is DERIVED from the success edge, so a join with nowhere to complete has nothing to wait on.', $stateKey, $workflow));
    }

    public static function joinWithMoreThanOneSuccessEdge(string $stateKey, string $workflow): self
    {
        return new self(sprintf('State "%s" in workflow "%s" declares #[JoinArm]s and MORE THAN ONE success transition — the joining wait is derived from the success edge statically, taking the first and reading no guard, while the runtime selector answers with the first success edge whose guard passes: the join would wait on one state and leave through another. Keep exactly one success edge on a joining state.', $stateKey, $workflow));
    }

    public static function joinArmOnNonActivityState(string $stateKey, string $workflow): self
    {
        return new self(sprintf('#[JoinArm(state: "%s")] in workflow "%s" targets a state that is not an activity — only an activity can fan a join out.', $stateKey, $workflow));
    }

    public static function joinNeedsTwoArms(string $stateKey, string $workflow, int $count): self
    {
        return new self(sprintf('State "%s" in workflow "%s" declares %d #[JoinArm] — one arm is not a join; declare at least two, or drop the attribute.', $stateKey, $workflow, $count));
    }

    public static function joinStateAlsoCompensatable(string $stateKey, string $workflow): self
    {
        return new self(sprintf('State "%s" in workflow "%s" declares both #[JoinArm]s and #[Compensate] — a join compensates PER ARM by construction, and a state-level undo beside it would leave the rollback two truths. Keep the arms\' compensations.', $stateKey, $workflow));
    }

    public static function joinStateAlsoRaces(string $stateKey, string $workflow): self
    {
        return new self(sprintf('State "%s" in workflow "%s" declares both #[JoinArm]s and #[RaceArm]s — out at the first outcome or out at the last are opposite contracts; one step is a race or a join, never both.', $stateKey, $workflow));
    }

    public static function joinArmBlankName(string $stateKey, string $workflow): self
    {
        return new self(sprintf('A #[JoinArm] on state "%s" in workflow "%s" has a blank arm name — the name is the outbox effect_group and the log identity; it cannot be empty.', $stateKey, $workflow));
    }

    public static function joinArmUnknownClass(string $arm, string $field, string $class, string $stateKey, string $workflow): self
    {
        return new self(sprintf('#[JoinArm(state: "%s", arm: "%s")] in workflow "%s" names a %s class that does not exist: %s.', $stateKey, $arm, $workflow, $field, $class));
    }

    public static function joinArmsShareACommand(string $command, string $stateKey, string $workflow): self
    {
        return new self(sprintf('Two #[JoinArm] on state "%s" in workflow "%s" issue the same command class %s — the outbox stamping matches on the command class, so a shared one cannot be attributed to an arm.', $stateKey, $workflow, $command));
    }

    public static function joinArmEventsOverlap(string $arm, string $field, string $other, string $otherField, string $stateKey, string $workflow): self
    {
        return new self(sprintf('The %s of arm "%s" and the %s of arm "%s" on state "%s" in workflow "%s" overlap by inheritance — one arriving event must mean exactly one thing for exactly one arm. Declare disjoint event classes.', $field, $arm, $otherField, $other, $stateKey, $workflow));
    }

    public static function joinSettlesOnNonWait(string $stateKey, string $joinedBy, string $workflow): self
    {
        return new self(sprintf('The join on state "%s" in workflow "%s" completes on "%s", its success target, which is not a wait state — a join completes where its arms\' outcomes are awaited.', $stateKey, $workflow, $joinedBy));
    }

    public static function joinWaitUsesAliases(string $stateKey, string $waitKey, string $workflow): self
    {
        return new self(sprintf('The wait "%s" joining the arms of state "%s" in workflow "%s" accepts event type aliases — the arm lookups match event CLASSES, so the joining wait must declare classes only.', $waitKey, $stateKey, $workflow));
    }

    public static function joinArmEventNotAwaited(string $arm, string $field, string $event, string $waitKey, string $workflow): self
    {
        return new self(sprintf('Arm "%s" in workflow "%s" declares %s %s, which the joining wait "%s" does not accept — an arm whose outcome cannot arrive through the wait that joins it can never complete nor fail.', $arm, $workflow, $field, $event, $waitKey));
    }

    public static function spawnSlotEndsInAMemberIndex(string $slot, string $workflow): self
    {
        return new self(sprintf('Spawn slot "%s" in workflow "%s" ends in a dash-and-digits suffix, the form an indexed family mints its members under. Whatever a slot declares, that suffix makes it read as a member of a family named "%s"; pick a slot without a trailing index.', $slot, $workflow, preg_replace('/-\d+$/', '', $slot)));
    }

    public static function spawnSlotShadowsAFamilyMember(string $slot, string $family, string $workflow): self
    {
        return new self(sprintf('Spawn slot "%s" in workflow "%s" is shaped like a member of the indexed family "%s" — one birth would have two declarations to answer to. Rename the slot, or drop the exact declaration.', $slot, $workflow, $family));
    }

    public static function spawnsOnReusableWorkflow(string $workflow): self
    {
        return new self(sprintf('Workflow "%s" declares #[Spawns] together with reuse: Allow — a child correlation is minted from the parent correlation and the slot alone, with no generation, so a second run would remint the first run\'s children and count their claims as its own. Keep the default Reject, or drop the spawns.', $workflow));
    }

    public static function joinCompletionIndividuallyRouted(string $arm, string $event, string $waitKey, string $workflow): self
    {
        return new self(sprintf('The joining wait "%s" in workflow "%s" routes %s, arm "%s"\'s completion, through its own #[On(onEvent:)] edge — only the LAST completion crosses, so the target would depend on which arm happened to arrive last. Completions leave through the catch-all event edge.', $waitKey, $workflow, $event, $arm));
    }

    public static function joinFailureNotRouted(string $arm, string $event, string $waitKey, string $workflow): self
    {
        return new self(sprintf('The joining wait "%s" in workflow "%s" accepts %s, arm "%s"\'s failedBy, but declares no #[On(onEvent:)] edge for it — through the catch-all it would cross into the success path, a definitive refusal dressed as the join\'s completion. Route the failure to its own state.', $waitKey, $workflow, $event, $arm));
    }

    public static function joinWaitAcceptsForeignEvent(string $accepted, string $waitKey, string $stateKey, string $workflow): self
    {
        return new self(sprintf('The wait "%s" joining the arms of state "%s" in workflow "%s" also accepts %s, which no arm completes nor fails by — leaving through a foreign event would strand every arrival unaccounted. The joining wait accepts exactly the arms\' events.', $waitKey, $stateKey, $workflow, $accepted));
    }

    public static function retimableOnNonWaitState(string $stateKey, string $workflow): self
    {
        return new self(sprintf('#[Retimable(state: "%s")] in workflow "%s" targets a state that is not a wait state — only a wait\'s deadline can be retimed.', $stateKey, $workflow));
    }

    public static function retimableWithoutACap(string $stateKey, string $workflow): self
    {
        return new self(sprintf(
            '#[Retimable(state: "%s")] in workflow "%s" declares no cap — an uncapped retime lets chatter push a '
            .'business deadline forever, the starvation the engine refuses by default. Declare maxRetimes and/or '
            .'maxExtensionSeconds.',
            $stateKey, $workflow,
        ));
    }

    public static function retimableOnGatingWait(string $stateKey, string $workflow): self
    {
        return new self(sprintf(
            '#[Retimable(state: "%s")] in workflow "%s" targets an effect-gating wait — that deadline belongs to the '
            .'engine, which re-arms and escalates; moving it from a signal could silence the liveness of an issued '
            .'effect. Retime only a wait that owns its own business expiry.',
            $stateKey, $workflow,
        ));
    }

    public static function retimableWithoutADeadline(string $stateKey, string $workflow): self
    {
        return new self(sprintf(
            '#[Retimable(state: "%s")] in workflow "%s" targets a wait with no finalizing deadline — there is nothing '
            .'to retime: an untimed wait has no timer, and a heartbeat is the escalator\'s liveness, not a business '
            .'expiry. Declare deadlineSeconds (or a business-time deadline) with onDeadline on the wait.',
            $stateKey, $workflow,
        ));
    }

    public static function unknownOnEventClass(string $onEvent, string $from, string $workflow): self
    {
        return new self(sprintf('#[On(from: "%s", onEvent: "%s")] in workflow "%s" names a class/interface that does not exist.', $from, $onEvent, $workflow));
    }

    public static function circuitBreakerMissingResource(string $stateKey, string $workflow): self
    {
        return new self(sprintf('#[CircuitBreaker] on state "%s" in workflow "%s" needs a non-empty `resource` (the shared resource it guards).', $stateKey, $workflow));
    }

    public static function unknownMethod(string $method, string $workflow): self
    {
        return new self(sprintf('Workflow "%s" references method "%s" (guard/matcher/extract) that does not exist.', $workflow, $method));
    }

    public static function extractMapEmpty(string $stateKey, string $workflow): self
    {
        return new self(sprintf('#[WaitFor(state: "%s")] in workflow "%s" declares an empty `extract` map; give at least one `varName => payloadField`, or drop it.', $stateKey, $workflow));
    }

    public static function extractMapMalformed(string $stateKey, string $workflow): self
    {
        return new self(sprintf('#[WaitFor(state: "%s")] in workflow "%s" has a malformed `extract` map; every entry must be a non-empty string `varName => payloadField`.', $stateKey, $workflow));
    }

    public static function fallbackTargetAmbiguous(string $stateKey, string $workflow): self
    {
        return new self(sprintf('#[Fallback] on state "%s" in workflow "%s" sets both `activity` and `vars`; give exactly one.', $stateKey, $workflow));
    }

    public static function fallbackMissingTarget(string $stateKey, string $workflow): self
    {
        return new self(sprintf('#[Fallback] on state "%s" in workflow "%s" needs an `activity` or `vars` (the repli to run).', $stateKey, $workflow));
    }

    public static function decoratesNonActivityState(string $attribute, string $stateKey, string $workflow): self
    {
        return new self(sprintf('#[%s] in workflow "%s" targets state "%s", which is not an activity state (only activity states take retry / compensation / circuit-breaker / fallback).', $attribute, $workflow, $stateKey));
    }

    public static function duplicateState(string $stateKey, string $workflow): self
    {
        return new self(sprintf('Workflow "%s" declares state "%s" more than once.', $workflow, $stateKey));
    }

    public static function confirmedByUnknownClass(string $confirmedBy, string $stateKey, string $workflow): self
    {
        return new self(sprintf('#[Compensate(confirmedBy: "%s")] on state "%s" in workflow "%s" names a class/interface that does not exist.', $confirmedBy, $stateKey, $workflow));
    }

    public static function onEventOnNonEventTrigger(string $from, string $onEvent, string $workflow): self
    {
        return new self(sprintf('#[On(from: "%s", onEvent: "%s")] in workflow "%s" sets `onEvent` on a non-event trigger (it only applies to the `event` trigger).', $from, $onEvent, $workflow));
    }

    public static function timeoutOwnedByEngineOnEffectGatingWait(string $waitState, string $workflow): self
    {
        return new self(sprintf(
            'Wait state "%s" in workflow "%s" gates an issued effect (it is the success-target of an activity — '
            .'an "issue-and-wait" step), so its deadline is owned by the engine as liveness — you cannot declare '
            .'#[On(from: "%s", trigger: "timeout")]. In a fully-async pipeline a timeout cannot tell a '
            .'committed-but-unconfirmed effect from a failed one, so finalising on it would discard (or, on a later '
            .'rollback, duplicate) a possibly-committed effect. Remove the timeout transition: the engine re-arms '
            .'the deadline and escalates (SagaAwaitEscalated), resolving only on the outcome event.',
            $waitState, $workflow, $waitState,
        ));
    }

    public static function perStateTimeoutAtOrAboveGlobal(string $stateKey, int $stateSeconds, int $globalSeconds, string $workflow): self
    {
        $relation = $stateSeconds === $globalSeconds ? 'equal to' : 'greater than';
        $consequence = $stateSeconds === $globalSeconds
            ? 'would race the global (timer ordering in the runner is non-deterministic at the tick)'
            : 'would never fire — the global preempts it';

        return new self(sprintf(
            'State "%s" in workflow "%s" declares timeoutSeconds=%d, which is %s the workflow globalTimeout=%d — '
            .'its timer %s. Lower the per-state timeout below the global, or drop it and let the global handle '
            .'the deadline.',
            $stateKey, $workflow, $stateSeconds, $relation, $globalSeconds, $consequence,
        ));
    }

    public static function scheduleStateMissingSchedule(string $stateKey, string $workflow): self
    {
        return new self(sprintf('Schedule state "%s" in workflow "%s" has no #[Schedule].', $stateKey, $workflow));
    }

    public static function scheduleOnNonScheduleState(string $stateKey, string $workflow): self
    {
        return new self(sprintf('#[Schedule(state: "%s")] in workflow "%s" targets a state that is not a schedule state.', $stateKey, $workflow));
    }

    public static function scheduleCadenceAmbiguous(string $stateKey, string $workflow): self
    {
        return new self(sprintf('#[Schedule(state: "%s")] in workflow "%s" sets more than one cadence source (intervalSeconds / intervalVar / intervalMethod / cron / cronVar / cronMethod) — a schedule has exactly one. Keep one.', $stateKey, $workflow));
    }

    public static function scheduleCadenceMissing(string $stateKey, string $workflow): self
    {
        return new self(sprintf('#[Schedule(state: "%s")] in workflow "%s" sets no cadence source (intervalSeconds / intervalVar / intervalMethod / cron / cronVar / cronMethod) — a schedule needs exactly one.', $stateKey, $workflow));
    }

    public static function scheduleIntervalNotPositive(string $stateKey, int $seconds, string $workflow): self
    {
        return new self(sprintf('#[Schedule(state: "%s", intervalSeconds: %d)] in workflow "%s" needs a positive interval.', $stateKey, $seconds, $workflow));
    }

    public static function scheduleSpreadNotPositive(string $stateKey, int $seconds, string $workflow): self
    {
        return new self(sprintf('#[Schedule(state: "%s", spreadSeconds: %d)] in workflow "%s" needs a positive spread (or none).', $stateKey, $seconds, $workflow));
    }

    public static function invalidCron(string $cron, string $stateKey, string $workflow): self
    {
        return new self(sprintf('#[Schedule(state: "%s", cron: "%s")] in workflow "%s" is not a valid cron expression.', $stateKey, $cron, $workflow));
    }

    public static function unsatisfiableCron(string $cron, string $stateKey, string $workflow): self
    {
        return new self(sprintf(
            '#[Schedule(state: "%s", cron: "%s")] in workflow "%s" parses but can never be satisfied — the walk finds '
            .'no next occurrence (e.g. a Feb 30 date part). The cadence would poison every tick; fix the expression.',
            $stateKey, $cron, $workflow,
        ));
    }

    public static function catchUpLimitRequired(string $stateKey, string $workflow): self
    {
        return new self(sprintf('#[Schedule(state: "%s")] in workflow "%s" uses catchUp ReplayBounded but sets no catchUpLimit — give the max number of missed slots to replay.', $stateKey, $workflow));
    }

    public static function catchUpRequiresIdempotentTick(string $stateKey, string $workflow): self
    {
        return new self(sprintf(
            '#[Schedule(state: "%s")] in workflow "%s" replays missed slots (catchUp is not Skip) but does not set '
            .'idempotentTick: true. Replaying the period\'s work runs the tick more than once, so it must be idempotent — '
            .'the engine cannot prove that, you assert it. Set idempotentTick: true, or use catchUp Skip.',
            $stateKey, $workflow,
        ));
    }

    public static function globalTimeoutOnScheduleWorkflow(string $workflow): self
    {
        return new self(sprintf(
            'Workflow "%s" declares a schedule state and a globalTimeout — a recurring workflow has no overall deadline '
            .'(it is a durable, indefinitely-living entity, not a bounded run). Drop the globalTimeout.',
            $workflow,
        ));
    }

    public static function scheduleStateMissingScheduleEdge(string $stateKey, string $workflow): self
    {
        return new self(sprintf(
            'Schedule state "%s" in workflow "%s" has no #[On(from: "%s", trigger: "schedule")] edge — when a slot comes '
            .'due the tick has nowhere to go. Add a schedule transition (typically to an activity that does the period\'s work).',
            $stateKey, $workflow, $stateKey,
        ));
    }

    public static function scheduleEdgesAllGuarded(string $stateKey, string $workflow): self
    {
        return new self(sprintf(
            'Schedule state "%s" in workflow "%s" — every schedule edge is guarded. A tick where all guards reject '
            .'would HALT the recurring workflow permanently: the fired cadence timer is consumed and nothing re-arms '
            .'it. Keep at least one unguarded schedule edge (guarded edges above it route conditionally; model '
            .'"skip this slot" inside the tick, not on the edge).',
            $stateKey, $workflow,
        ));
    }

    public static function spawnSlotInvalid(string $slot, string $workflow): self
    {
        return new self(sprintf(
            'Workflow "%s" declares #[Spawns] with slot "%s", which is not a valid static identifier '
            .'(1-64 chars of [A-Za-z0-9_-]). A slot names a declared child position, never runtime data — '
            .'the child correlation embeds it and must stay log- and URL-safe.',
            $workflow, $slot,
        ));
    }

    public static function spawnSlotDuplicated(string $slot, string $workflow): self
    {
        return new self(sprintf(
            'Workflow "%s" declares #[Spawns] twice for slot "%s". A slot is one child position with one '
            .'declared child type; two declarations would make the adoption proof ambiguous.',
            $workflow, $slot,
        ));
    }

    public static function spawnChildTypeEmpty(string $slot, string $workflow): self
    {
        return new self(sprintf(
            'Workflow "%s" declares #[Spawns] for slot "%s" with an empty child workflow name. The declaration '
            .'IS the parent\'s consent — an empty type consents to nothing.',
            $workflow, $slot,
        ));
    }

    public static function spawnAwaitedByNotAWait(string $awaitedBy, string $slot, string $workflow): self
    {
        return new self(sprintf(
            'Workflow "%s" declares #[Spawns] for slot "%s" awaited by "%s", which is not a declared wait state. '
            .'A spawned child concludes into a wait its parent declares; completing without awaiting it is refused '
            .'at runtime (ChildrenStillRunning), and the declaration names the wait that should consume the child\'s '
            .'end. A detached process is not a child — start it through a reaction instead.',
            $workflow, $slot, $awaitedBy,
        ));
    }

    public static function waitEventWithoutUnguardedRoute(string $event, string $stateKey, string $workflow): self
    {
        return new self(sprintf(
            'Wait state "%s" in workflow "%s" accepts "%s" but keeps no unguarded route for it. A matched event '
            .'whose class no edge covers, or whose every candidate edge is guarded and every guard rejects, would '
            .'HALT the saga permanently: the event is consumed by the settle and its redelivery lands on a terminal '
            .'instance. Keep one unguarded edge for the class, scoped onEvent or catch-all (guarded edges above it '
            .'route conditionally; "reject this content" belongs to the matcher or inside the target, not to the '
            .'last edge\'s guard).',
            $stateKey, $workflow, $event,
        ));
    }

    public static function waitEventEmpty(string $waitState, string $workflow): self
    {
        return new self(sprintf(
            '#[WaitFor(state: "%s")] in workflow "%s" declares an empty event entry ("") — name an event class or a '
            .'stable type alias.',
            $waitState, $workflow,
        ));
    }

    public static function unknownWaitEventClass(string $event, string $waitState, string $workflow): self
    {
        return new self(sprintf(
            '#[WaitFor(state: "%s", events: "%s")] in workflow "%s" looks like a class (it contains a namespace '
            .'separator) but does not exist — a typo here would silently become a type alias that never matches, and '
            .'the wait would never resolve. Fix the class name, or use a stable alias without "\".',
            $waitState, $event, $workflow,
        ));
    }

    public static function waitAcceptsNothing(string $waitState, string $workflow): self
    {
        return new self(sprintf(
            '#[WaitFor(state: "%s")] in workflow "%s" accepts no events and declares no deadline — the wait can never '
            .'be left. Declare the events it resolves on, or a deadline (deadlineSeconds/business + onDeadline) for a '
            .'durable sleep.',
            $waitState, $workflow,
        ));
    }

    public static function waitWithoutEventEdge(string $waitState, string $workflow): self
    {
        return new self(sprintf(
            'Wait state "%s" in workflow "%s" accepts events but declares no #[On(from: "%s", trigger: "event")] '
            .'edge — a matched event would have nowhere to route and the saga would halt. Add an event edge (or a '
            .'catch-all one without onEvent).',
            $waitState, $workflow, $waitState,
        ));
    }

    public static function eventEdgeOnEventlessWait(string $waitState, string $workflow): self
    {
        return new self(sprintf(
            'Wait state "%s" in workflow "%s" declares an event edge but accepts no events — the edge can never fire '
            .'(dead config). Declare the events in #[WaitFor(events:)], or drop the edge.',
            $waitState, $workflow,
        ));
    }

    public static function onEventNotAccepted(string $onEvent, string $waitState, string $workflow): self
    {
        return new self(sprintf(
            '#[On(from: "%s", onEvent: "%s")] in workflow "%s" routes an event class the wait does not accept (it is '
            .'neither among nor a subtype of the #[WaitFor(events:)] classes) — the edge can never fire. Add the class '
            .'to the wait\'s events, or fix the edge.',
            $waitState, $onEvent, $workflow,
        ));
    }

    public static function onEventNotConcrete(string $onEvent, string $from, string $workflow): self
    {
        return new self(sprintf(
            '#[On(from: "%s", onEvent: "%s")] in workflow "%s" names an interface or abstract class — routing compares '
            .'the DELIVERED event\'s concrete class, so this edge can never be selected (the wait may still match it '
            .'by instanceof, then halt with no route). Use the concrete event class, or a catch-all event edge.',
            $from, $onEvent, $workflow,
        ));
    }

    public static function triggerForeignToStateKind(string $trigger, string $from, string $kind, string $legal, string $workflow): self
    {
        return new self(sprintf(
            '#[On(from: "%s", trigger: "%s")] in workflow "%s" — a %s state never yields the "%s" trigger, so the edge '
            .'is dead config (it would silently never fire). Legal triggers out of a %s state: %s.',
            $from, $trigger, $workflow, $kind, $trigger, $kind, $legal,
        ));
    }

    public static function stateFieldForeignToKind(string $field, string $stateKey, string $kind, string $hint, string $workflow): self
    {
        return new self(sprintf(
            '#[State(key: "%s", type: "%s")] in workflow "%s" sets %s, which a %s state silently ignores. %s',
            $stateKey, $kind, $workflow, $field, $kind, $hint,
        ));
    }

    public static function retryElapsedAtOrAboveGlobal(string $stateKey, int $elapsedSeconds, int $globalSeconds, string $workflow): self
    {
        return new self(sprintf(
            '#[Retry(state: "%s", maxElapsedSeconds: %d)] in workflow "%s" is at or above the workflow '
            .'globalTimeout=%d — the visit window cannot outlive the instance, so the budget never bounds '
            .'anything the global cap does not already bound. Lower it below the global, or drop it.',
            $stateKey, $elapsedSeconds, $workflow, $globalSeconds,
        ));
    }

    public static function durationNotPositive(string $field, int $value, string $subject, string $workflow): self
    {
        return new self(sprintf(
            '%s: %d on "%s" in workflow "%s" — a duration must be >= 1 (a zero or negative timer would fire '
            .'immediately, or flood: a business decision taken by a config accident).',
            $field, $value, $subject, $workflow,
        ));
    }

    public static function businessDeadlineZero(string $waitState, string $workflow): self
    {
        return new self(sprintf(
            '#[WaitFor(state: "%s")] in workflow "%s" declares a business-time deadline that is negative or adds up '
            .'to zero (deadlineBusinessDays + deadlineBusinessHours) — it would be due immediately, and the business '
            .'path has no runtime floor. Give at least one business day or hour, never a negative field.',
            $waitState, $workflow,
        ));
    }

    public static function retryAttemptsNotPositive(string $stateKey, int $maxAttempts, string $workflow): self
    {
        return new self(sprintf(
            '#[Retry(state: "%s", maxAttempts: %d)] in workflow "%s" — maxAttempts must be >= 1: at %d the decorator '
            .'never retries anything (an inert decorator reads as protection that is not there). Declare the attempts, '
            .'or drop the attribute.',
            $stateKey, $maxAttempts, $workflow, $maxAttempts,
        ));
    }

    public static function breakerFieldNotPositive(string $field, int $value, string $stateKey, string $workflow): self
    {
        return new self(sprintf(
            '#[CircuitBreaker] on state "%s" in workflow "%s" — %s must be >= 1 (at %d the breaker silently degrades: '
            .'a non-positive cooldown admits every call while "open", a non-positive threshold never trips).',
            $stateKey, $workflow, $field, $value,
        ));
    }

    public static function waitWithoutLiveness(string $waitState, string $workflow): self
    {
        return new self(sprintf(
            'Wait state "%s" in workflow "%s" declares no liveness: no deadline, and no workflow globalTimeout. '
            .'Nothing it awaits is guaranteed to arrive, so a lost message rests it forever — running, zero timers, '
            .'invisible to reconciliation, which derives from dead-letters and never from silence. Declare a deadline '
            .'with its onDeadline to expire, or a #[Workflow(globalTimeout:)] cap. A heartbeat is NOT an option here: '
            .'this wait gates no issued effect, and a heartbeat escalates a step nobody is waiting on. A wait named by '
            .'#[Spawns(awaitedBy:)] is exempt: the child it awaits carries the horizon, and a second clock would race it.',
            $waitState, $workflow,
        ));
    }

    public static function gatingWaitWithoutLiveness(string $waitState, string $workflow): self
    {
        return new self(sprintf(
            'Wait state "%s" in workflow "%s" gates an issued effect (it is the success-target of an activity) but '
            .'declares no liveness: no heartbeatSeconds and no workflow globalTimeout. If the outcome event is lost, '
            .'the saga strands silently — running, zero timers, invisible to reconciliation. Declare '
            .'#[WaitFor(heartbeatSeconds:)] (escalates, never finalizes), or a #[Workflow(globalTimeout:)] cap.',
            $waitState, $workflow,
        ));
    }

    public static function compensationUnverifiableAtGatingWait(string $stateKey, string $waitState, string $workflow): self
    {
        return new self(sprintf(
            '#[Compensate(state: "%s")] in workflow "%s" declares no confirmedBy while "%s" rests at the effect-gating '
            .'wait "%s". At that wait, progression only proves the step RAN — for an issued command, that its dispatch '
            .'was written, not that its effect committed; a settle or forced cancel there would undo an effect that may '
            .'never have happened. Declare confirmedBy (usually the success event the wait consumes) so the rollback '
            .'only undoes a confirmed effect.',
            $stateKey, $workflow, $stateKey, $waitState,
        ));
    }

    public static function compensatableStateInCycle(string $stateKey, string $workflow): self
    {
        return new self(sprintf(
            '#[Compensate(state: "%s")] in workflow "%s" sits on a transition cycle (a path of edges leads back to it). '
            .'The compensation log keys records by state key alone, so a second visit of "%s" ALIASES the first: a '
            .'class-only confirmation can confirm the wrong visit, and the rollback collapses distinct visits into one, '
            .'a money-shaped audit lie. Until every visit carries its own execution identity, a compensatable activity '
            .'must be reachable at most once: break the cycle, or drop the compensation on "%s".',
            $stateKey, $workflow, $stateKey, $stateKey,
        ));
    }

    public static function duplicateDecorator(string $attribute, string $stateKey, string $workflow): self
    {
        return new self(sprintf(
            'Two #[%s] attributes target state "%s" in workflow "%s" — the last one would silently win. Keep one.',
            $attribute, $stateKey, $workflow,
        ));
    }

    public static function unknownSignalClass(string $signalClass, string $workflow): self
    {
        return new self(sprintf(
            '#[Signal] in workflow "%s" declares the signal class "%s" that does not exist. The handler lookup is '
            .'exact-class, so an interface or a typo could never match an incoming signal: dead config.',
            $workflow, $signalClass,
        ));
    }

    public static function signalClassNotConcrete(string $signalClass, string $workflow): self
    {
        return new self(sprintf(
            '#[Signal] in workflow "%s" declares "%s", an interface or abstract class. A signal is looked up by its '
            .'exact class, so only a concrete class can ever match — declare one handler per concrete signal.',
            $workflow, $signalClass,
        ));
    }

    public static function duplicateSignalHandler(string $signalClass, string $workflow): self
    {
        return new self(sprintf(
            'Two #[Signal] attributes claim the signal class "%s" in workflow "%s" — the last one would silently win. Keep one.',
            $signalClass, $workflow,
        ));
    }

    public static function signalHandlerBadSignature(string $handler, string $signalClass, string $workflow): self
    {
        return new self(sprintf(
            'Signal handler "%s" for "%s" in workflow "%s" must take two arguments (object $signal, array $vars) '
            .'and declare the return type SignalResult — the executor invokes it blind with exactly those two. '
            .'A handler that cannot see the vars cannot return a faithful bag; one demanding more can never be called.',
            $handler, $signalClass, $workflow,
        ));
    }

    public static function signalHandlerCannotAcceptSignal(string $handler, string $parameterType, string $signalClass, string $workflow): self
    {
        return new self(sprintf(
            'Signal handler "%s" in workflow "%s" types its first parameter "%s", which cannot accept the declared '
            .'signal "%s" — a cross-wiring. Type it with the signal class itself (or a supertype, or plain `object`).',
            $handler, $workflow, $parameterType, $signalClass,
        ));
    }
}
