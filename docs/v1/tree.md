# Writing a tree

`writeTree()` exists for the generator case: a build step that emits a set of files whose paths nest,
none of which exist yet.

```php
use Webware\FileWriter\FileWriter;

foreach ($pages as $relativePath => $html) {
    FileWriter::writeTree(
        filename     : "{$output}/{$relativePath}",
        content      : $html,
        mode         : 0o644,
        directoryMode: 0o755,
    );
}
```

## Why it is separate from write()

`write()` refuses to create a directory. A single file whose location is meant to be exact should fail
loudly when the path is wrong, rather than have a plausible-looking directory invented for it and the
content quietly land somewhere nobody looks.

A generator is the opposite case. Every target below the first level is missing by construction, so
"the directory does not exist" is the normal condition and not an error. Rather than making every
caller create directories itself, `writeTree()` creates the parent chain and then delegates to
`write()`, so the atomic write, the link resolution and the failure handling are the same code.

## Directory creation

Only the parent chain is created. The mode passed to `mkdir()` is the requested directory mode, or
`0o777` narrowed by umask when none is given, and an explicit mode is applied again immediately
afterwards because `mkdir()` is always masked by umask. See [modes and ownership](modes.md) for why
that second step is needed and why only the containing directory is adjusted.

A failure to create the directory is reported as `Exception\RuntimeException`, naming the file rather
than the directory, because the file is what the caller asked to write.

## Cost

Each file is one temporary file plus one rename, in the destination directory. There is no batching,
no in-memory accumulation and no single final rename of a staging directory. A tree of `n` files
therefore costs `n` temporary files and `n` renames.

That is deliberate for a publish step. Each file becomes visible atomically, so a deploy that is
interrupted leaves a partially updated tree of complete files rather than one truncated file, and a
reader never sees a half-written document. It is not a transactional guarantee across the tree: there
is no point at which the whole set swaps. A build that needs an all-or-nothing directory swap should
write into a staging directory and rename that directory itself, which is outside this package's
scope.

The measured cost is about 2.0 ms per file for small documents on a Windows workstation including
creating a fresh directory per file, so 300 files took 606 ms. The cost is dominated by the
filesystem calls, not by anything in PHP: each file is a temp file creation, a write, a `chmod`, a
rename and a directory creation.

Measure it on your own target rather than taking that figure: a warm page cache, a different
filesystem and an existing directory all move it, and a build that writes into one directory without
creating one per file is materially cheaper.
