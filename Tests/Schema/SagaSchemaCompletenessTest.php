<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Schema;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Storm\Saga\Schema\SagaSchemaCatalog;
use Storm\Saga\Schema\WorkflowInstancesSchema;

/**
 * Pins the two lists that can silently drift apart: the `*Schema` classes the package declares, and
 * the catalog `storm:saga:install` verifies against the live PostgreSQL catalogs. The schema classes
 * are discovered from the FILESYSTEM, so a new one is seen without anyone remembering to look.
 *
 * A schema class gaining a table, a column, a constraint or an index without {@see SagaSchemaCatalog}
 * noticing turns this red, which matters more here than the count suggests: two of the verified
 * indexes ARE invariants, the reuse refusal and one-saga-per-correlation, and an unverified one
 * fails in silence.
 */
final class SagaSchemaCompletenessTest extends TestCase
{
    #[Test]
    public function every_declared_table_is_in_the_catalog_and_nothing_more(): void
    {
        $declared = [];
        foreach ($this->schemaClasses() as $class) {
            $declared = [...$declared, ...$this->createdTables($class)];
        }
        sort($declared);

        $mapped = array_keys(SagaSchemaCatalog::COLUMNS);
        sort($mapped);

        self::assertSame($declared, $mapped, 'a saga *Schema class and SagaSchemaCatalog::COLUMNS drifted apart — storm:saga:install would not verify what the package declares');
    }

    #[Test]
    public function catalog_columns_match_the_declared_ddl_exactly(): void
    {
        // Tables and their names are pinned above; this pins the COLUMN lists. Without it, a Schema
        // class gaining a column while SagaSchemaCatalog::COLUMNS stays behind fails no test, and
        // the probe then blesses a pre-existing table missing that column, an install reported
        // verified and a runtime failing later.
        $declared = $this->declaredColumnsByTable();

        foreach (SagaSchemaCatalog::COLUMNS as $table => $columns) {
            self::assertArrayHasKey($table, $declared, sprintf('%s is in SagaSchemaCatalog::COLUMNS but no saga *Schema class declares it', $table));

            self::assertSame(
                [],
                array_values(array_diff($declared[$table], array_keys($columns))),
                sprintf('%s: column(s) declared by the DDL but absent from SagaSchemaCatalog::COLUMNS — the probe would bless a pre-existing table missing them', $table),
            );
            self::assertSame(
                [],
                array_values(array_diff(array_keys($columns), $declared[$table])),
                sprintf('%s: column(s) in SagaSchemaCatalog::COLUMNS that the DDL no longer declares — the catalog drifted away from the schema', $table),
            );
        }
    }

    #[Test]
    public function every_schema_class_drops_what_it_creates(): void
    {
        foreach ($this->schemaClasses() as $class) {
            $dropped = $this->tables($class, 'down', '/DROP TABLE IF EXISTS ([a-z_]+)/');
            foreach ($this->createdTables($class) as $table) {
                self::assertContains($table, $dropped, sprintf('%s creates %s but its down() never drops it', $class, $table));
            }
        }
    }

    #[Test]
    public function every_verified_constraint_and_index_is_still_declared_in_the_ddl(): void
    {
        $ddl = [];
        foreach ($this->schemaClasses() as $class) {
            $sql = implode("\n", $this->statements($class, 'up'));
            foreach ($this->createdTables($class) as $table) {
                $ddl[$table] = $sql;
            }
        }

        $this->assertVerifiedConstraintsDeclared(SagaSchemaCatalog::CONSTRAINTS, $ddl);

        foreach (SagaSchemaCatalog::INDEXES as $table => $indexes) {
            foreach (array_keys($indexes) as $name) {
                self::assertStringContainsString('IF NOT EXISTS '.$name, $ddl[$table], sprintf('index %s is verified but no longer declared for %s', $name, $table));
            }
        }
    }

    #[Test]
    public function every_declared_constraint_and_index_is_verified_by_the_catalog(): void
    {
        // the OTHER direction, the one the test above cannot see: a schema class gaining a CHECK or
        // an index that the catalog never learns about is a shape nobody proves, and the install
        // still reports "verified" over a pre-existing table that may not carry it at all
        $verified = [];
        foreach ([SagaSchemaCatalog::CONSTRAINTS, SagaSchemaCatalog::INDEXES] as $catalog) {
            foreach ($catalog as $entries) {
                $verified = [...$verified, ...array_keys($entries)];
            }
        }

        foreach ($this->schemaClasses() as $class) {
            $sql = implode("\n", $this->statements($class, 'up'));
            preg_match_all('/(?:CONSTRAINT|INDEX IF NOT EXISTS) ([a-z_]+)/', $sql, $matches);

            foreach ($matches[1] as $name) {
                self::assertContains($name, $verified, sprintf('%s declares %s, and SagaSchemaCatalog verifies no such name — storm:saga:install would never prove it', $class, $name));
            }
        }
    }

    /**
     * Every verified constraint is still declared, and a declared FRAGMENT appears verbatim in the
     * rendered DDL: the probe compares it against `pg_get_constraintdef`, so a fragment the DDL no
     * longer contains means either the catalog drifted or the fragment was never deparse-stable to
     * begin with. The parameter carries the catalog's declared contract shape rather than the
     * constant itself, so the fragment branch is read against that type and not against whichever
     * values happen to sit in the list today.
     *
     * @param  array<string, array<string, string|null>>  $constraintsByTable
     * @param  array<string, string>  $ddl
     */
    private function assertVerifiedConstraintsDeclared(array $constraintsByTable, array $ddl): void
    {
        foreach ($constraintsByTable as $table => $constraints) {
            foreach ($constraints as $name => $fragment) {
                self::assertStringContainsString('CONSTRAINT '.$name, $ddl[$table], sprintf('constraint %s is verified but no longer declared for %s', $name, $table));
                if ($fragment !== null) {
                    self::assertStringContainsString($fragment, $ddl[$table], sprintf("constraint %s: required fragment '%s' is not in the declared DDL for %s — the catalog drifted away from the schema", $name, $fragment, $table));
                }
            }
        }
    }

    /**
     * @return list<class-string>
     */
    private function schemaClasses(): array
    {
        $reflection = new ReflectionClass(WorkflowInstancesSchema::class);
        $namespace = $reflection->getNamespaceName();
        $classes = [];

        foreach (glob(dirname((string) $reflection->getFileName()).'/*.php') ?: [] as $file) {
            $class = $namespace.'\\'.basename($file, '.php');
            self::assertTrue(class_exists($class), sprintf('%s does not autoload — a stray file in the Schema directory?', $class));
            if (is_callable([$class, 'up'])) {
                $classes[] = $class; // the catalog lives here too and declares no DDL
            }
        }

        return $classes;
    }

    /**
     * @param  class-string  $class
     * @return list<string>
     */
    private function statements(string $class, string $method): array
    {
        $callable = [$class, $method];
        self::assertIsCallable($callable, sprintf('%s::%s() — a Schema class must expose the static statement list', $class, $method));

        /** @var list<string> */
        return $callable();
    }

    /**
     * @param  class-string  $class
     * @return list<string>
     */
    private function createdTables(string $class): array
    {
        return $this->tables($class, 'up', '/CREATE TABLE IF NOT EXISTS ([a-z_]+)/');
    }

    /**
     * @param  class-string  $class
     * @return list<string>
     */
    private function tables(string $class, string $method, string $pattern): array
    {
        preg_match_all($pattern, implode("\n", $this->statements($class, $method)), $matches);

        return $matches[1];
    }

    /**
     * Column names per table, parsed from every `up()` CREATE TABLE body.
     *
     * @return array<string, list<string>>
     */
    private function declaredColumnsByTable(): array
    {
        $columns = [];
        foreach ($this->schemaClasses() as $class) {
            foreach ($this->statements($class, 'up') as $statement) {
                if (preg_match('/CREATE TABLE IF NOT EXISTS ([a-z_]+)\s*\(/', $statement, $matches) === 1) {
                    $columns[$matches[1]] = $this->columnsOf($statement);
                }
            }
        }

        return $columns;
    }

    /**
     * The column names of one CREATE TABLE statement, read line by line: the house DDL format puts
     * one column per line, `CONSTRAINT` and `--` comment lines aside, and closes the body on its own
     * `)` line. Deliberately NOT an SQL parser: this suite pins conventions, and a statement this
     * parse misreads is a statement breaking the format every schema follows.
     *
     * @return list<string>
     */
    private function columnsOf(string $statement): array
    {
        $columns = [];
        foreach (array_slice(explode("\n", $statement), 1) as $line) {
            $line = trim($line);
            if (str_starts_with($line, ')')) {
                break;
            }
            if (str_starts_with($line, '--') || str_starts_with($line, 'CONSTRAINT ')) {
                continue;
            }
            if (preg_match('/^([a-z_]+)/', $line, $matches) === 1) {
                $columns[] = $matches[1];
            }
        }

        self::assertNotSame([], $columns, 'no columns parsed from a CREATE TABLE body — the DDL no longer follows the one-column-per-line format this suite relies on');

        return $columns;
    }
}
