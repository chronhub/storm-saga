<?php

declare(strict_types=1);

namespace Storm\Saga\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The structural guard the review doesn't have to carry: everything but the DELIBERATE adapters
 * speaks ONLY ports, with no Doctrine type and no driver exception. The concrete port
 * implementations live under their `<Space>\Dbal\` subspaces, so the directory alone proves the
 * nature; the remaining deliberate speakers are the relay, raw SQL by design for the claim-loop, and
 * the ops surfaces `Console/` and `Store/Inspection/`, where decision D5 assumes raw SQL. Swapping
 * the storage tech never touches anything else. This test guards DRIVER LEAKAGE; the dependency
 * DIRECTION between core and adapters is deptrac's job, layers Saga and SagaAdapters.
 */
final class ArchitectureTest extends TestCase
{
    #[Test]
    public function only_the_deliberate_adapters_touch_doctrine(): void
    {
        $offenders = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/..'));
        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            if (str_contains($path, '/Tests/') || str_contains($path, '/config/')
                || str_contains($path, '/Console/') || str_contains($path, '/Inspection/')
                || str_contains($path, '/Dbal/')
                || $file->getFilename() === 'SagaOutboxRelay.php') {
                continue;
            }
            $source = (string) file_get_contents($path);
            if (str_contains($source, 'Doctrine\\')) {
                $offenders[] = $file->getFilename();
            }
        }

        $this->assertSame([], $offenders, 'these files leak a Doctrine type outside the deliberate adapters');
    }
}
