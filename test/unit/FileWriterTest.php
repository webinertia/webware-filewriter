<?php

declare(strict_types=1);

namespace WebwareTest\FileWriter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Throwable;
use Webware\FileWriter\Exception;
use Webware\FileWriter\FileWriter;

use function chmod;
use function clearstatcache;
use function file_get_contents;
use function file_put_contents;
use function fileinode;
use function fileperms;
use function glob;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function is_writable;
use function link;
use function mkdir;
use function realpath;
use function restore_error_handler;
use function rmdir;
use function set_error_handler;
use function str_repeat;
use function strlen;
use function symlink;
use function sys_get_temp_dir;
use function tempnam;
use function umask;
use function unlink;

use const PHP_OS_FAMILY;

// One class per subject keeps the writer cases together, which is worth more here than a low
// method count, and each case is a flat arrange/act/assert rather than nested logic.
// @mago-expect lint:cyclomatic-complexity
// @mago-expect lint:kan-defect
#[CoversClass(FileWriter::class)]
final class FileWriterTest extends TestCase
{
    /** @var list<string> */
    private array $created = [];

    private string $directory;

    private static function tryLink(string $source, string $destination): bool
    {
        set_error_handler(static fn(): bool => true);

        try {
            return link($source, $destination);
        } catch (Throwable) {
            return false;
        } finally {
            restore_error_handler();
        }
    }

    #[Test]
    public function testAnExistingDestinationKeepsItsMode(): void
    {
        if ('Windows' === PHP_OS_FAMILY) {
            static::markTestSkipped('Windows does not model POSIX file modes');
        }

        $file = $this->path('target.txt');
        file_put_contents(
            filename: $file,
            data    : 'previous document',
        );
        chmod(
            filename   : $file,
            permissions: 0o640,
        );

        FileWriter::write($file, 'new document');

        clearstatcache();
        static::assertSame(0o640, fileperms($file) & 0o7777);
    }

    #[Test]
    public function testAnExplicitModeIsAppliedToANewFile(): void
    {
        if ('Windows' === PHP_OS_FAMILY) {
            static::markTestSkipped('Windows does not model POSIX file modes');
        }

        $file = $this->path('page.html');

        FileWriter::write(
            filename: $file,
            content : '<h1>Home</h1>',
            mode    : 0o644,
        );

        clearstatcache();
        static::assertSame(0o644, fileperms($file) & 0o7777);
    }

    #[Test]
    public function testAnExplicitModeIsNotNarrowedByTheProcessUmask(): void
    {
        if ('Windows' === PHP_OS_FAMILY) {
            static::markTestSkipped('Windows does not model POSIX file modes');
        }

        // A restrictive umask must not silently make a published page unreadable. An
        // explicit mode belongs to the artifact, so umask does not apply to it.
        $file  = $this->path('page.html');
        $umask = umask(mask: 0o077);

        try {
            FileWriter::write(
                filename: $file,
                content : '<p>page</p>',
                mode    : 0o644,
            );
        } finally {
            umask(mask: $umask);
        }

        clearstatcache();
        static::assertSame(0o644, fileperms($file) & 0o7777);
    }

    #[Test]
    public function testAnExplicitModeOverridesTheDestinationMode(): void
    {
        if ('Windows' === PHP_OS_FAMILY) {
            static::markTestSkipped('Windows does not model POSIX file modes');
        }

        // A published page rewritten by a later deploy must not inherit the mode of the
        // file it replaces. Without an explicit mode a restrictive predecessor decides
        // the mode of every future write to that path.
        $file = $this->path('page.html');
        file_put_contents(
            filename: $file,
            data    : 'previous page',
        );
        chmod(
            filename   : $file,
            permissions: 0o600,
        );

        FileWriter::write(
            filename: $file,
            content : '<p>new page</p>',
            mode    : 0o644,
        );

        clearstatcache();
        static::assertSame(0o644, fileperms($file) & 0o7777);
    }

    #[Test]
    public function testAttributesAreNotAdoptedWherePosixPermissionsAreUnavailable(): void
    {
        $inherit     = new ReflectionMethod(FileWriter::class, 'adoptAttributes');
        $destination = $this->path('target.txt');
        file_put_contents(
            filename: $destination,
            data    : 'previous document',
        );
        chmod(
            filename   : $destination,
            permissions: 0o644,
        );

        $temporary = $this->path('.wcmprobe');
        file_put_contents(
            filename: $temporary,
            data    : 'new document',
        );

        $inherit->invoke(null, $temporary, $destination, null);

        // On Windows chmod toggles only the read-only bit, so adopting the
        // destination's mode there could leave the file unrenameable and break the
        // next write. The temporary file must be left as it was created.
        clearstatcache();
        static::assertSame('new document', file_get_contents($temporary));
        static::assertTrue(is_writable($temporary));
    }

    #[Test]
    public function testFailureToRemoveTheTemporaryFileIsSwallowed(): void
    {
        $discard = new ReflectionMethod(FileWriter::class, 'discard');
        $missing = $this->path('missing.txt');

        // Cleanup runs while the caller holds the failure that caused it, so a
        // cleanup failure must not escape and replace that failure. A directory is
        // not a file and an absent path is not found, so both attempts fail.
        $discard->invoke(null, $this->directory);
        $discard->invoke(null, $missing);

        static::assertFileDoesNotExist($missing);
    }

    #[Test]
    public function testLeavesNoTemporaryFileBehind(): void
    {
        $file = $this->path('target.txt');

        FileWriter::write($file, 'content');

        static::assertSame([], $this->leftovers());
    }

    #[Test]
    public function testLeavesNoTemporaryFileBehindWhenTheWriteFails(): void
    {
        $directory = $this->path('nested');
        mkdir($directory);

        try {
            FileWriter::write($directory, 'content');
            static::fail('Writing to a directory should have failed');
        } catch (Exception\RuntimeException $exception) {
            static::assertStringContainsString('unable to replace the file', $exception->getMessage());
        } finally {
            rmdir($directory);
        }

        static::assertSame([], $this->leftovers($directory));
    }

    #[Test]
    public function testOwnershipThatCannotBeRestoredIsNotFatal(): void
    {
        $adoptOwnership = new ReflectionMethod(FileWriter::class, 'adoptOwnership');
        $destination    = $this->path('target.txt');
        file_put_contents(
            filename: $destination,
            data    : 'previous document',
        );

        // A temporary file that does not exist stands in for a host where ownership
        // cannot be restored: every attempt fails, and the write must still stand.
        $adoptOwnership->invoke(null, $this->path('gone.txt'), $destination);

        static::assertFileExists($destination);
    }

    #[Test]
    public function testPosixInheritanceGivesANewDestinationTheDefaultMode(): void
    {
        $temporary = $this->path('.wcmprobe');
        file_put_contents(
            filename: $temporary,
            data    : 'new document',
        );
        chmod(
            filename   : $temporary,
            permissions: 0o600,
        );

        $inherit = new ReflectionMethod(FileWriter::class, 'inheritAttributes');
        $inherit->invoke(null, $temporary, $this->path('missing.txt'));

        static::assertFileExists($temporary);

        if ('Windows' !== PHP_OS_FAMILY) {
            clearstatcache();
            static::assertSame(0o666 & ~umask(), fileperms($temporary) & 0o7777);
        }
    }

    #[Test]
    public function testPosixInheritanceGivesTheTemporaryFileTheExistingMode(): void
    {
        $destination = $this->path('target.txt');
        file_put_contents(
            filename: $destination,
            data    : 'previous document',
        );
        chmod(
            filename   : $destination,
            permissions: 0o640,
        );

        $temporary = $this->path('.wcmprobe');
        file_put_contents(
            filename: $temporary,
            data    : 'new document',
        );

        $inherit = new ReflectionMethod(FileWriter::class, 'inheritAttributes');
        $inherit->invoke(null, $temporary, $destination);

        static::assertSame('new document', file_get_contents($temporary));

        if ('Windows' !== PHP_OS_FAMILY) {
            clearstatcache();
            static::assertSame(0o640, fileperms($temporary) & 0o7777);
        }
    }

    #[Test]
    public function testRejectsAnEmptyFilename(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('No file name specified');

        FileWriter::write('', 'content');
    }

    #[Test]
    public function testReplacesTheFileInsteadOfModifyingItInPlace(): void
    {
        $file = $this->path('target.txt');
        $link = $this->path('sibling.txt');

        file_put_contents(
            filename: $file,
            data    : 'original',
        );

        if (! self::tryLink($file, $link)) {
            static::markTestSkipped('The filesystem does not support hard links');
        }

        $inode = fileinode($file);

        FileWriter::write($file, 'replacement');

        static::assertSame('replacement', file_get_contents($file));
        static::assertNotSame($inode, fileinode($file), 'The target inode should be replaced');

        // The sibling name still refers to the previous inode, which proves the
        // write did not go through the existing file.
        static::assertSame('original', file_get_contents($link));
    }

    #[Test]
    public function testResolveFollowsASymbolicLink(): void
    {
        $target = $this->path('target.txt');
        file_put_contents(
            filename: $target,
            data    : 'content',
        );

        $link = $this->path('link.txt');
        $this->symlink(
            target: 'target.txt',
            link  : $link,
        );

        static::assertSame(realpath($target), FileWriter::resolve($link));
    }

    #[Test]
    public function testResolveLeavesANonLinkUnchanged(): void
    {
        $file = $this->path('target.txt');

        static::assertSame($file, FileWriter::resolve($file));
    }

    #[Test]
    public function testResolveRejectsADanglingSymbolicLink(): void
    {
        $link = $this->path('link.txt');
        $this->symlink(
            target: 'missing.txt',
            link  : $link,
        );

        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('points at "missing.txt", which does not exist');

        FileWriter::resolve($link);
    }

    #[Test]
    public function testResolveRejectsAnEmptyName(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('No file name specified');

        FileWriter::resolve('');
    }

    #[Test]
    public function testShorterContentLeavesNoTrailingBytes(): void
    {
        $file = $this->path('target.txt');

        FileWriter::write($file, 'a much longer document than the next one');
        FileWriter::write($file, 'short');

        static::assertSame('short', file_get_contents($file));
        static::assertSame(5, strlen((string) file_get_contents($file)));
    }

    #[Test]
    public function testThrowsWhenNoTemporaryFileCanBeCreated(): void
    {
        // The parent of the target is a regular file, so no temporary file can be
        // created beside the target.
        $parent = $this->path('parent.txt');
        file_put_contents(
            filename: $parent,
            data    : 'not a directory',
        );

        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('Unable to create a temporary file in');

        FileWriter::write("{$parent}/target.txt", 'content');
    }

    #[Test]
    public function testThrowsWhenTheTargetDirectoryDoesNotExist(): void
    {
        $this->expectException(Exception\RuntimeException::class);

        FileWriter::write("{$this->directory}/missing/file.txt", 'content');
    }

    #[Test]
    public function testThrowsWhenTheTargetIsADirectory(): void
    {
        $this->expectException(Exception\RuntimeException::class);

        FileWriter::write($this->directory, 'content');
    }

    #[Test]
    public function testWriteFollowsASymbolicLinkAndKeepsTheLink(): void
    {
        $target = $this->path('target.txt');
        file_put_contents(
            filename: $target,
            data    : 'previous document',
        );

        $link = $this->path('link.txt');
        $this->symlink(
            target: 'target.txt',
            link  : $link,
        );

        FileWriter::write($link, 'new document');

        // The link survives and the target carries the new content.
        static::assertTrue(is_link($link));
        static::assertSame('new document', file_get_contents($target));
    }

    #[Test]
    public function testWritesContent(): void
    {
        $file = $this->path('target.txt');

        FileWriter::write($file, 'written');

        static::assertSame('written', file_get_contents($file));
    }

    #[Test]
    public function testWriteTreeCreatesAWholeTreeInOnePass(): void
    {
        $pages = [
            'index.html',
            'guide/index.html',
            'guide/intro/index.html',
            'guide/intro/deep/nested/index.html',
            'reference/api/index.html',
        ];

        foreach ($pages as $page) {
            FileWriter::writeTree("{$this->directory}/{$page}", "<p>{$page}</p>");
        }

        foreach ($pages as $page) {
            static::assertSame("<p>{$page}</p>", file_get_contents("{$this->directory}/{$page}"));
        }
    }

    #[Test]
    public function testWriteTreeCreatesTheDirectoriesLeadingToTheFile(): void
    {
        $file = "{$this->directory}/guide/intro/index.html";

        FileWriter::writeTree($file, '<p>page</p>');

        static::assertSame('<p>page</p>', file_get_contents($file));
    }

    #[Test]
    public function testWriteTreeKeepsTheLinkAndWritesThroughIt(): void
    {
        $target = $this->path('target.html');
        file_put_contents(
            filename: $target,
            data    : 'previous document',
        );

        $link = $this->path('link.html');
        $this->symlink(
            target: 'target.html',
            link  : $link,
        );

        FileWriter::writeTree($link, 'new document');

        static::assertTrue(is_link($link));
        static::assertSame('new document', file_get_contents($target));
    }

    #[Test]
    public function testWriteTreeRejectsAnEmptyName(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('No file name specified');

        FileWriter::writeTree('', 'content');
    }

    #[Test]
    public function testWriteTreeStillReplacesRatherThanAppends(): void
    {
        $file        = "{$this->directory}/guide/index.html";
        $longContent = str_repeat(
            string: 'x',
            times : 500,
        );
        $shortContent = 'y';

        FileWriter::writeTree($file, $longContent);
        FileWriter::writeTree($file, $shortContent);

        static::assertSame($shortContent, file_get_contents($file));
    }

    #[Test]
    public function testWriteTreeSucceedsWhenTheDirectoryAlreadyExists(): void
    {
        $file = "{$this->directory}/guide/index.html";

        FileWriter::writeTree($file, 'first');
        FileWriter::writeTree($file, 'second');

        static::assertSame('second', file_get_contents($file));
    }

    protected function setUp(): void
    {
        $directory = tempnam(
            directory: sys_get_temp_dir(),
            prefix   : 'wcmfw',
        );

        self::assertIsString($directory);
        unlink($directory);
        mkdir($directory);

        $this->directory = $directory;
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $file) {
            if (is_link($file)) {
                unlink($file);

                continue;
            }

            if (! is_file($file)) {
                continue;
            }

            chmod(
                filename   : $file,
                permissions: 0o644,
            );
            unlink($file);
        }

        foreach ($this->leftovers() as $leftover) {
            unlink($leftover);
        }

        $this->removeDirectory($this->directory);

        $this->created = [];
    }

    /**
     * @return list<string>
     */
    private function leftovers(?string $directory = null): array
    {
        $matches = glob(pattern: ($directory ?? $this->directory) . '/.wcm*');

        if (false === $matches) {
            return [];
        }

        return $matches;
    }

    private function path(string $name): string
    {
        $path          = "{$this->directory}/{$name}";
        $this->created = [...$this->created, $path];

        return $path;
    }

    /**
     * Remove a directory and everything below it.
     *
     * writeTree() creates a nested tree, so a flat rmdir() would leave the deeper
     * directories behind and PHPUnit turns the resulting warning into a failure.
     */
    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $matches = glob(pattern: "{$directory}/*");

        if (is_array($matches)) {
            foreach ($matches as $match) {
                if (is_link($match)) {
                    unlink($match);

                    continue;
                }

                if (is_dir($match)) {
                    $this->removeDirectory($match);

                    continue;
                }

                chmod(
                    filename   : $match,
                    permissions: 0o644,
                );
                unlink($match);
            }
        }

        rmdir($directory);
    }

    private function symlink(string $target, string $link): void
    {
        set_error_handler(static fn(): bool => true);

        try {
            $created = symlink($target, $link);
        } catch (Throwable) {
            $created = false;
        } finally {
            restore_error_handler();
        }

        if (! $created) {
            static::markTestSkipped('This host does not permit creating symbolic links');
        }
    }
}
