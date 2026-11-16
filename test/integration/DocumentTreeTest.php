<?php

declare(strict_types=1);

namespace WebwareTestIntegration\FileWriter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webware\FileWriter\FileWriter;

use function bin2hex;
use function chmod;
use function clearstatcache;
use function file_get_contents;
use function file_put_contents;
use function fileperms;
use function glob;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function microtime;
use function mkdir;
use function random_bytes;
use function restore_error_handler;
use function rmdir;
use function set_error_handler;
use function str_repeat;
use function symlink;
use function sys_get_temp_dir;
use function umask;
use function unlink;

use const PHP_OS_FAMILY;

/**
 * Builds a documentation tree the way a site generator does: a set of pages whose
 * output paths nest, written once each, with no preparation of the output directory.
 */
#[CoversClass(FileWriter::class)]
final class DocumentTreeTest extends TestCase
{
    private string $root;

    #[Test]
    public function testAGeneratedSiteIsWrittenFromAnEmptyOutputDirectory(): void
    {
        $pages = [
            'index.html'                        => '<h1>Home</h1>',
            'guide/index.html'                  => '<h1>Guide</h1>',
            'guide/install/index.html'          => '<h1>Install</h1>',
            'guide/install/advanced/index.html' => '<h1>Advanced</h1>',
            'reference/api/index.html'          => '<h1>API</h1>',
            'reference/api/classes/index.html'  => '<h1>Classes</h1>',
        ];

        $output = "{$this->root}/build/site";

        static::assertFalse(is_dir($output), 'The output directory must start absent');

        foreach ($pages as $relative => $html) {
            FileWriter::writeTree("{$output}/{$relative}", $html);
        }

        foreach ($pages as $relative => $html) {
            $path = "{$output}/{$relative}";
            static::assertFileExists($path);
            static::assertSame($html, file_get_contents($path));
        }
    }

    #[Test]
    public function testAnExplicitModeIsAppliedToTheTreeItWrites(): void
    {
        if ('Windows' === PHP_OS_FAMILY) {
            static::markTestSkipped('Windows does not model POSIX directory modes');
        }

        // A publish step owns the mode of what it emits: mkdir() is narrowed by umask,
        // so a directory mode has to survive a restrictive umask to be worth naming.
        $page    = "{$this->root}/out/nested/index.html";
        $umask   = umask(mask: 0o077);
        $written = "{$this->root}/out/nested";

        try {
            FileWriter::writeTree(
                filename     : $page,
                content      : '<h1>Home</h1>',
                mode         : 0o644,
                directoryMode: 0o755,
            );
        } finally {
            umask(mask: $umask);
        }

        clearstatcache();
        static::assertSame(0o644, fileperms($page) & 0o7777);
        static::assertSame(0o755, fileperms($written) & 0o7777);
    }

    #[Test]
    public function testAPageIsWrittenThroughASymlinkedDestinationAndKeepsTheLink(): void
    {
        $target = "{$this->root}/shared/page.html";
        mkdir(
            directory  : "{$this->root}/shared",
            permissions: 0o777,
            recursive  : true,
        );
        file_put_contents(
            filename: $target,
            data    : 'previous',
        );

        $link = "{$this->root}/release/page.html";
        mkdir(
            directory  : "{$this->root}/release",
            permissions: 0o777,
            recursive  : true,
        );

        set_error_handler(static fn(): bool => true);

        try {
            $created = symlink(
                target: '../shared/page.html',
                link  : $link,
            );
        } finally {
            restore_error_handler();
        }

        if (! $created) {
            static::markTestSkipped('This host does not permit creating symbolic links');
        }

        FileWriter::writeTree($link, 'rebuilt');

        static::assertTrue(is_link($link));
        static::assertSame('rebuilt', file_get_contents($target));
    }

    #[Test]
    public function testManyPagesAreWrittenQuickly(): void
    {
        $output = "{$this->root}/build/many";
        $html   = str_repeat(
            string: '<p>Lorem ipsum</p>',
            times : 20,
        );

        $start = microtime(true);

        for ($page = 0; $page < 300; $page++) {
            FileWriter::writeTree("{$output}/section{$page}/index.html", $html);
        }

        $elapsed = microtime(true) - $start;

        static::assertFileExists("{$output}/section299/index.html");

        // Generous bound: the measured cost is a fraction of a millisecond per page,
        // so this only catches a change that turns a linear build into a slow one.
        static::assertLessThan(30.0, $elapsed, 'Writing 300 pages should not take seconds');
    }

    #[Test]
    public function testRebuildingOverAnExistingTreeReplacesEveryPage(): void
    {
        $output = "{$this->root}/build/site";
        $path   = "{$output}/guide/index.html";

        FileWriter::writeTree($path, str_repeat(
            string: '<p>old</p>',
            times : 100,
        ));
        FileWriter::writeTree($path, '<p>new</p>');

        static::assertSame('<p>new</p>', file_get_contents($path));
    }

    #[Test]
    public function testTheTreeIsWrittenWithoutLeavingTemporaryFiles(): void
    {
        $output = "{$this->root}/build/site";

        FileWriter::writeTree("{$output}/guide/index.html", '<h1>Guide</h1>');

        static::assertSame([], glob(pattern: "{$output}/guide/.wcm*"));
        static::assertSame([], glob(pattern: "{$output}/.wcm*"));
    }

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wcm-doc-' . bin2hex(random_bytes(6));

        mkdir(
            directory  : $this->root,
            permissions: 0o777,
            recursive  : true,
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

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

                if (is_file($match)) {
                    chmod(
                        filename   : $match,
                        permissions: 0o644,
                    );
                    unlink($match);
                }
            }
        }

        rmdir($directory);
    }
}
