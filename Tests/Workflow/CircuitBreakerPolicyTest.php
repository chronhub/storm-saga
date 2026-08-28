<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Workflow;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Saga\Exception\MissingBreakerResourceKey;
use Storm\Saga\Workflow\CircuitBreakerPolicy;

final class CircuitBreakerPolicyTest extends TestCase
{
    #[Test]
    public function a_policy_without_a_resource_key_resolves_to_itself(): void
    {
        $policy = new CircuitBreakerPolicy('card-network', 3, 90);

        $this->assertSame($policy, $policy->resolve(['atmId' => 'atm-17']));
    }

    #[Test]
    public function a_resource_key_folds_the_vars_value_into_the_key(): void
    {
        $policy = new CircuitBreakerPolicy('atm-dispense', 3, 90, resourceKey: 'atmId');

        $resolved = $policy->resolve(['atmId' => 'atm-17']);

        $this->assertSame('atm-dispense:atm-17', $resolved->key);
        $this->assertSame(3, $resolved->failureThreshold);   // thresholds preserved
        $this->assertSame(90, $resolved->cooldownSeconds);
        $this->assertNull($resolved->resourceKey);            // resolved, won't re-resolve
    }

    #[Test]
    public function distinct_instances_resolve_to_distinct_keys(): void
    {
        $policy = new CircuitBreakerPolicy('atm-dispense', 3, 90, resourceKey: 'atmId');

        $this->assertNotSame(
            $policy->resolve(['atmId' => 'atm-17'])->key,
            $policy->resolve(['atmId' => 'atm-42'])->key,
        );
    }

    #[Test]
    public function a_missing_vars_value_fails_loud_rather_than_collapsing_to_the_shared_key(): void
    {
        $policy = new CircuitBreakerPolicy('atm-dispense', 3, 90, resourceKey: 'atmId');

        $this->expectException(MissingBreakerResourceKey::class);

        $policy->resolve(['somethingElse' => 'x']);
    }

    #[Test]
    public function a_non_scalar_vars_value_fails_loud(): void
    {
        $policy = new CircuitBreakerPolicy('atm-dispense', 3, 90, resourceKey: 'atmId');

        $this->expectException(MissingBreakerResourceKey::class);

        $policy->resolve(['atmId' => ['not', 'scalar']]);
    }
}
