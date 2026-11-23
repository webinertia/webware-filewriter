# Introduction

`webware/webware-filewriter` writes a file without ever writing into the destination itself. The
content goes into an exclusive temporary file beside the destination, the temporary file is given the
attributes the final document should have, and it is then renamed onto the destination. A rename
within a single directory is atomic, so a concurrent reader sees either the previous document or the
new one, and an interrupted write never leaves a truncated file behind.

`FileWriter` is a single final class with static methods. There is no factory, no configuration object
and no state: the destination and the content are the whole input.

```shell
composer require webware/webware-filewriter
```

## The two entry points

```php
namespace Webware\FileWriter;

FileWriter::write(string $filename, string $content, ?int $mode = null): void;

FileWriter::writeTree(
    string $filename,
    string $content,
    ?int $mode = null,
    ?int $directoryMode = null,
): void;
```

`write()` requires the containing directory to exist. A wrong path fails rather than having a
directory invented for it, which is the right default for a single file whose location is meant to be
exact.

`writeTree()` creates the directories leading to the file first, then writes it the same way. Use it
when generating output, where every target below the first level is missing by construction.

```php
foreach ($pages as $relativePath => $html) {
    FileWriter::writeTree(
        filename     : "{$output}/{$relativePath}",
        content      : $html,
        mode         : 0o644,
        directoryMode: 0o755,
    );
}
```

Both `$mode` and `$directoryMode` are optional. Passing `null`, or omitting them, keeps the derived
defaults described in [modes and ownership](modes.md).

## What a write consists of

1. The destination is resolved, so a symbolic link is followed rather than replaced.
2. A uniquely named temporary file is created in the destination's directory.
3. The content is written into the temporary file in truncate mode.
4. The temporary file is given the mode, owner and group the final document should have.
5. The temporary file is renamed onto the destination.
6. On any failure, the temporary file is removed before the exception is thrown.

Every step after the temporary file is created is inside that cleanup, so a failed write leaves no
debris next to the destination.

Truncate mode is required rather than optional: opening an existing file for writing without
truncating would leave a tail of the previous, longer document after the new content.

## Symbolic links

A symlinked destination is resolved first, and the write lands on the target, so the link survives.
This matters for release-directory deployments, where `config/app.php` is a link into `shared/`:
writing to the link path would replace the link, appear to succeed, and leave every release reading
the previous content. The next deploy would recreate the link and lose the write.

A link whose target does not exist is reported instead of replaced, because there is nothing to
follow and guessing where the file belongs is worse than saying so.

Only a link in the final path component is resolved. A link to the containing directory needs no
resolution: the write then lands in the real directory and stays a rename within it.

## Errors

| Situation | Thrown |
|---|---|
| Empty file name | `Exception\InvalidArgumentException` |
| Destination is a directory | `Exception\RuntimeException` |
| Containing directory does not exist | `Exception\RuntimeException` |
| Symbolic link points at a missing target | `Exception\RuntimeException` |
| Directory cannot be created (`writeTree()`) | `Exception\RuntimeException` |
| Rename cannot replace the destination | `Exception\RuntimeException` |

Both implement `Exception\ExceptionInterface`. A failure raised by a step in the write is wrapped, so
the message names the file and carries the original exception, with `0` as the code, because the
underlying filesystem or PSL code is more specific than anything this package could invent.

## Further reading

- [Modes and ownership](modes.md): what mode a file gets, how umask and an explicit mode interact,
  and what happens to ownership and on Windows.
- [Writing a tree](tree.md): the generator case, including directory modes.
