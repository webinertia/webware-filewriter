<?php

declare(strict_types=1);

namespace Webware\FileWriter;

use Psl\Exception\ExceptionInterface as PslExceptionInterface;
use Psl\File;
use Psl\Filesystem;
use Throwable;

use function is_string;
use function readlink;
use function rename;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;
use function umask;

use const PHP_OS_FAMILY;

/**
 * Writes a file by filling an exclusive temporary file and renaming it onto the
 * target, so a reader never observes a partially written document.
 *
 * PSL supplies the primitives: {@see Filesystem\create_temporary_file()} creates
 * the private temporary file, {@see File\write()} fills it,
 * {@see Filesystem\change_permissions()} sets the final mode, and
 * {@see Filesystem\change_owner()} / {@see Filesystem\change_group()} carry the
 * existing ownership over. PSL has no rename equivalent, so the swap itself is
 * the one native call.
 */
// The steps are one operation each, kept separate so their failure can be handled
// differently, which is clearer than a single method that branches four ways. Each
// branch is a distinct filesystem outcome rather than nested logic.
// @mago-expect lint:too-many-methods
// @mago-expect lint:cyclomatic-complexity
final class FileWriter
{
    /** Mode applied to a new file, before the process umask is applied. */
    private const int MODE = 0o666;

    /** The permission bits of a file, without its type bits. */
    private const int PERMISSION_MASK = 0o7777;

    /** Mode applied to a directory created for a generated file, before umask. */
    private const int DIRECTORY_MODE = 0o777;

    /**
     * Give up retrying the rename after this many attempts.
     *
     * Windows refuses to rename over a file that another handle has open, and the
     * competing handle is usually a short-lived read, so a bounded retry succeeds
     * in practice where a single attempt would not.
     */
    private const int RENAME_ATTEMPTS = 100;

    /**
     * Resolve a symbolic link at the destination to the file it names.
     *
     * A configuration file is often a link into a shared directory, so the write has
     * to land on the target. Replacing the link would appear to succeed while every
     * other release kept reading the previous content, and the next deploy would
     * recreate the link and lose the write.
     *
     * A link whose target does not exist has nothing to follow, so it is reported
     * rather than replaced: guessing where the file belongs is worse than saying so.
     *
     * Only a link at the final path component matters. A link to the containing
     * directory needs no resolution, because the write then lands in the real
     * directory and stays a rename within it.
     *
     * @param string $filename Callers may pass an empty name, which is rejected here
     *                         so every path reports it the same way.
     * @return non-empty-string
     * @throws Exception\InvalidArgumentException If the file name is empty.
     * @throws Exception\RuntimeException If the link cannot be resolved.
     */
    public static function resolve(string $filename): string
    {
        if ('' === $filename) {
            throw new Exception\InvalidArgumentException('No file name specified');
        }

        if (! Filesystem\is_symbolic_link($filename)) {
            return $filename;
        }

        // canonicalize() covers a chain of links and relative targets, but only while
        // the final target exists. It also returns null rather than an empty string,
        // which states the non-empty result honestly.
        $resolved = Filesystem\canonicalize($filename);

        if (null !== $resolved) {
            return $resolved;
        }

        $target = readlink($filename);

        throw new Exception\RuntimeException(sprintf(
            'The symbolic link "%s" points at "%s", which does not exist',
            $filename,
            is_string($target) ? $target : 'an unreadable target',
        ));
    }

    /**
     * @param string $filename Callers may pass an empty name, which is rejected.
     * @throws Exception\InvalidArgumentException If the file name is empty.
     * @throws Exception\RuntimeException If the file cannot be written.
     */
    public static function write(string $filename, string $content): void
    {
        $filename  = self::resolve($filename);
        $temporary = self::createTemporary(Filesystem\get_directory($filename));

        try {
            self::fill($temporary, $content);
            self::adoptAttributes($temporary, $filename);
            self::swap($temporary, $filename);
        } catch (Throwable $throwable) {
            self::discard($temporary);

            throw new Exception\RuntimeException(
                sprintf('Error writing to "%s": %s', $filename, $throwable->getMessage()),
                0,
                $throwable,
            );
        }
    }

    /**
     * @param string $filename Callers may pass an empty name, which is rejected.
     * @throws Exception\InvalidArgumentException If the file name is empty.
     * @throws Exception\RuntimeException If the file cannot be written.
     */
    /**
     * Write a file, creating the directories that lead to it.
     *
     * write() refuses to invent a directory, which is the right default for a single
     * configuration file: a wrong path should fail rather than silently appear. A
     * generator that writes a tree of documents is the opposite case, where every
     * target below the first level is missing by construction, so this variant
     * creates what it needs and then delegates to write().
     *
     * @param string $filename Callers may pass an empty name, which is rejected.
     * @throws Exception\InvalidArgumentException If the file name is empty.
     * @throws Exception\RuntimeException If a directory or the file cannot be created.
     */
    public static function writeTree(string $filename, string $content): void
    {
        $filename = self::resolve($filename);

        try {
            // Creates the parent chain only. The default mode means a generated tree
            // is usable by another account, which is what a published site needs.
            Filesystem\create_directory_for_file($filename, self::DIRECTORY_MODE);
        } catch (PslExceptionInterface $exception) {
            throw new Exception\RuntimeException(
                sprintf('Unable to create the directory for "%s"', $filename),
                0,
                $exception,
            );
        }

        self::write($filename, $content);
    }

    /**
     * Give the temporary file the attributes of the document it replaces.
     *
     * A rename replaces the inode, so without this the new file belongs to
     * whichever user ran PHP and carries a mode derived from umask. A configuration
     * file is usually owned by the deployer rather than by the web server, and its
     * mode is often restrictive on purpose, so both are carried over instead. A new
     * file gets MODE narrowed by umask.
     *
     * @param non-empty-string $temporary
     * @param non-empty-string $filename
     * @throws PslExceptionInterface
     */
    private static function adoptAttributes(string $temporary, string $filename): void
    {
        // Windows is skipped entirely. It does not model POSIX permissions: chmod
        // there toggles only the read-only bit, and a read-only destination cannot be
        // renamed over, so adopting a mode would break the next write. Ownership is
        // not settable without elevation either. The temporary file keeps the mode it
        // was created with, which on Windows means writable.
        if ('Windows' !== PHP_OS_FAMILY) {
            self::inheritAttributes($temporary, $filename);
        }
    }

    /**
     * @param non-empty-string $temporary
     * @param non-empty-string $filename
     */
    private static function adoptGroup(string $temporary, string $filename): void
    {
        try {
            Filesystem\change_group($temporary, Filesystem\get_group($filename));
        } catch (PslExceptionInterface) {
            // Unless the process already belongs to the target group.
            return;
        }
    }

    /**
     * @param non-empty-string $temporary
     * @param non-empty-string $filename
     */
    private static function adoptOwner(string $temporary, string $filename): void
    {
        try {
            Filesystem\change_owner($temporary, Filesystem\get_owner($filename));
        } catch (PslExceptionInterface) {
            // Only a privileged process can hand a file to another user.
            return;
        }
    }

    /**
     * Hand the temporary file to the owner and group of the document it replaces.
     *
     * Ownership needs privilege, so this is best effort: a host that runs PHP as a
     * different user than the deployer still needs its file ownership fixed, and a
     * configuration write must not fail because of that, so an unprivileged process
     * keeps the file it created.
     *
     * @param non-empty-string $temporary
     * @param non-empty-string $filename
     */
    private static function adoptOwnership(string $temporary, string $filename): void
    {
        // Attempted separately: a group can be settable where ownership cannot, so a
        // refused ownership change must not skip the group change.
        self::adoptOwner($temporary, $filename);
        self::adoptGroup($temporary, $filename);
    }

    /**
     * @param non-empty-string $directory
     * @return non-empty-string
     * @throws Exception\RuntimeException If a temporary file cannot be created.
     */
    private static function createTemporary(string $directory): string
    {
        try {
            return Filesystem\create_temporary_file(
                directory: $directory,
                prefix   : '.wcm',
            );
        } catch (PslExceptionInterface $exception) {
            throw new Exception\RuntimeException(
                sprintf('Unable to create a temporary file in "%s"', $directory),
                0,
                $exception,
            );
        }
    }

    /**
     * Remove the temporary file, never letting cleanup mask the real failure.
     *
     * @param non-empty-string $temporary
     */
    private static function discard(string $temporary): void
    {
        try {
            Filesystem\delete_file($temporary);
        } catch (PslExceptionInterface) {
            // Best effort: the caller already holds the failure that matters.
            return;
        }
    }

    /**
     * @param non-empty-string $temporary
     * @throws PslExceptionInterface
     */
    private static function fill(string $temporary, string $content): void
    {
        // Truncate is required: OpenOrCreate would leave a tail of the previous
        // document in place whenever the new content is shorter.
        File\write($temporary, $content, File\WriteMode::Truncate);
    }

    /**
     * @param non-empty-string $temporary
     * @param non-empty-string $filename
     * @throws PslExceptionInterface
     */
    private static function inheritAttributes(
        string $temporary,
        string $filename,
    ): void {
        if (! Filesystem\is_file($filename)) {
            Filesystem\change_permissions($temporary, self::MODE & ~umask());

            return;
        }

        Filesystem\change_permissions(
            $temporary,
            Filesystem\get_permissions($filename) & self::PERMISSION_MASK,
        );

        self::adoptOwnership($temporary, $filename);
    }

    /**
     * @param non-empty-string $filename
     */
    private static function mayRetry(string $filename): bool
    {
        // Only a regular file that another handle has open can be retried; a
        // directory target is a permanent failure.
        return (
            'Windows' === PHP_OS_FAMILY
                && Filesystem\is_file($filename)
                && Filesystem\is_writable($filename)
        );
    }

    /**
     * Replace the target, reporting failure through the return value only.
     *
     * A failed rename raises a warning, and this method reports that failure as a
     * typed exception instead, so the warning is not allowed to surface.
     *
     * @param non-empty-string $temporary
     * @param non-empty-string $filename
     */
    private static function rename(string $temporary, string $filename): bool
    {
        set_error_handler(static fn(): bool => true);

        try {
            return rename($temporary, $filename);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param non-empty-string $temporary
     * @param non-empty-string $filename
     * @throws Exception\RuntimeException If the target cannot be replaced.
     */
    private static function swap(string $temporary, string $filename): void
    {
        for ($attempt = 1; $attempt <= self::RENAME_ATTEMPTS; $attempt++) {
            if (self::rename($temporary, $filename)) {
                return;
            }

            if (! self::mayRetry($filename)) {
                break;
            }
        }

        throw new Exception\RuntimeException('unable to replace the file');
    }
}
