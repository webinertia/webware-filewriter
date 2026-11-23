# webware/webware-filewriter

[![PHP Version](https://img.shields.io/packagist/php-v/webware/webware-filewriter)](https://packagist.org/packages/webware/webware-filewriter)
[![Latest Version](https://img.shields.io/packagist/v/webware/webware-filewriter)](https://packagist.org/packages/webware/webware-filewriter)
[![License](https://img.shields.io/github/license/webinertia/webware-filewriter)](LICENSE)
[![Continuous Integration](https://github.com/webinertia/webware-filewriter/actions/workflows/required/webinertia/.github/.github/workflows/continuous-integration.yml/badge.svg)](https://github.com/webinertia/webware-filewriter/actions/workflows/required/webinertia/.github/.github/workflows/continuous-integration.yml)
[![codecov](https://codecov.io/gh/webinertia/webware-filewriter/graph/badge.svg)](https://codecov.io/gh/webinertia/webware-filewriter)
[![Mutation testing badge](https://img.shields.io/endpoint?style=flat&url=https%3A%2F%2Fbadge-api.stryker-mutator.io%2Fgithub.com%2Fwebinertia%2Fwebware-filewriter%2F1.0.x)](https://dashboard.stryker-mutator.io/reports/github.com/webinertia/webware-filewriter/1.0.x)

Writes files atomically, and optionally writes a tree of them.

A file is never written in place. The content is written to an exclusive temporary file beside the
destination, given the destination's mode, owner and group, and then renamed onto it. A reader
therefore sees either the previous document or the new one, and an interrupted write never leaves a
truncated file behind.

## Installation

```shell
composer require webware/webware-filewriter
```

## Usage

### Writing a single file

```php
use Webware\FileWriter\FileWriter;

FileWriter::write('/srv/app/config/autoload/local.php', $content);
```

`write()` requires the containing directory to exist. A wrong path fails rather than having a
directory invented for it, which is the right default for a single file whose location is meant to be
exact.

### Writing a tree

```php
FileWriter::writeTree('/srv/build/site/guide/intro/index.html', $html);
```

`writeTree()` creates the directories leading to the file first, then writes it the same way. Use it
when generating output, where every target below the first level is missing by construction.

```php
foreach ($pages as $relativePath => $html) {
    FileWriter::writeTree("{$output}/{$relativePath}", $html);
}
```

### Naming the mode

Both methods take an optional mode. It matters when the output is served rather than read by the
deployer, because the derived mode is a function of the environment:

- a new file gets `0o666` narrowed by umask
- an existing file keeps the mode it already has, forever

So a published page that replaces a file created `0o600` stays unreadable by the web server on every
future write, and a build host with a `0o077` umask silently makes every asset private.

```php
FileWriter::write(
    filename: '/srv/site/index.html',
    content : $html,
    mode    : 0o644,
);

FileWriter::writeTree(
    filename     : '/srv/build/site/guide/intro/index.html',
    content      : $html,
    mode         : 0o644,
    directoryMode: 0o755,
);
```

An explicit mode wins over both the destination's current bits and the process umask, and is applied
to the temporary file before the rename, so the destination never exists with any other mode. The
directory mode is applied after `mkdir()` because that call is always masked by umask. Both are
accepted but have no effect on Windows, which does not model POSIX permissions.

Omitting them keeps the derived behaviour described above.

### Symbolic links

A symlinked destination is resolved, and the write lands on the target, so the link survives. This
matters for release-directory deployments, where `config/app.php` is a link into `shared/`:
replacing the link would appear to succeed while every release kept reading the previous content.

A link whose target does not exist is reported instead of replaced, because there is nothing to
follow and guessing where the file belongs is worse than saying so.

## Behaviour worth knowing

| Situation | Behaviour |
|---|---|
| Existing destination | mode, owner and group carried over to the new file |
| New destination | mode `0666` narrowed by the process umask |
| Explicit `$mode` | applied verbatim, over both the destination's bits and umask |
| Explicit `$directoryMode` | applied after `mkdir()`, since that call is masked by umask |
| Windows | no mode or ownership adoption; `chmod` there only toggles the read-only attribute, and a read-only file cannot be renamed over |
| Unprivileged PHP process | mode preserved, ownership keeps the PHP user, since `chown` needs privilege |
| Destination is a directory | fails with `Exception\RuntimeException` |
| Empty file name | fails with `Exception\InvalidArgumentException` |
| Temporary file on failure | removed before the exception is thrown |

Owner and group adoption is best effort: a refused change does not fail the write. A host that runs
PHP as a different user than the file's owner therefore needs its ownership corrected outside the
application.

## Documentation

The 1.0 release line is documented under [docs/1.0](docs/1.0):

- [Introduction](docs/1.0): the write sequence, symbolic links, and every error case.
- [Modes and ownership](docs/1.0/modes.md): derived versus explicit modes, umask, directory modes,
  and what happens on Windows.
- [Writing a tree](docs/1.0/tree.md): the generator case, its cost, and what it does not guarantee.

## Quality gates

Both MSI gates are set to **98**, matching the sibling packages, and must not be lowered silently:

```json
"min_msi": "98",
"min_covered_msi": "98"
```

Four Mago gates run in CI and must be clean: `format --check`, `lint`, `analyze`, `guard`.

## Development

The toolchain runs in a container, so the host needs no PHP install:

```shell
docker compose up -d
docker compose exec tooling composer install
docker compose exec tooling composer test
docker compose exec tooling composer test-integration
docker compose exec tooling mago lint
docker compose down
```
