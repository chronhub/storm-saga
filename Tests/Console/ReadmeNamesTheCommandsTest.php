<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Console;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Storm\Saga\Console\InstallSagaCommand;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * The package README names the console surface, and that list drifts silently: a renamed or added
 * command leaves the README advertising a name that no longer resolves, which is the one part of the
 * docs a reader COPIES.
 *
 * Prose cannot be linted, but a name can: the commands are discovered from the filesystem, so a new
 * one is seen without anyone remembering to look, and each declared name must appear in the README.
 */
final class ReadmeNamesTheCommandsTest extends TestCase
{
    #[Test]
    public function the_readme_names_every_console_command(): void
    {
        $readme = $this->readme();

        $missing = array_values(array_filter(
            $this->declaredNames(),
            static fn (string $name): bool => ! str_contains($readme, $name),
        ));

        self::assertSame([], $missing, 'commands the package README does not name: '.implode(', ', $missing));
    }

    #[Test]
    public function the_readme_names_no_command_that_does_not_exist(): void
    {
        // the other half, and the one that actually bit: a stale name reads as authoritative, and a
        // reader who copies it gets "command not found" with no clue what replaced it.
        preg_match_all('/`(storm:saga:[a-z-]+)`/', $this->readme(), $matches);

        $unknown = array_values(array_diff(array_unique($matches[1]), $this->declaredNames()));

        self::assertSame([], $unknown, 'the README names commands that no longer exist: '.implode(', ', $unknown));
    }

    /**
     * @return list<string>
     */
    private function declaredNames(): array
    {
        $reflection = new ReflectionClass(InstallSagaCommand::class);
        $namespace = $reflection->getNamespaceName();
        $names = [];

        foreach (glob(dirname((string) $reflection->getFileName()).'/*.php') ?: [] as $file) {
            $class = $namespace.'\\'.basename($file, '.php');
            self::assertTrue(class_exists($class), sprintf('%s does not autoload — a stray file in the Console directory?', $class));

            foreach (new ReflectionClass($class)->getAttributes(AsCommand::class) as $attribute) {
                $names[] = $attribute->newInstance()->name;
            }
        }

        self::assertNotEmpty($names, 'no console command was discovered — the walk itself broke');

        return $names;
    }

    private function readme(): string
    {
        $path = dirname((string) new ReflectionClass(InstallSagaCommand::class)->getFileName(), 2).'/README.md';
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
