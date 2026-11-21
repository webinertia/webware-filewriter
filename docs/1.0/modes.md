# Modes and ownership

A write replaces the destination rather than modifying it, which means the new file's mode, owner and
group are decided by this package rather than inherited for free from the file that was there. There
are two ways that decision is made, and they are chosen per call.

## Derived modes

When no mode is passed, the mode is a function of the environment:

| Situation | Mode |
|---|---|
| Destination exists | the destination's current permission bits, carried over |
| Destination does not exist | `0o666` narrowed by the process umask |
| Directory created by `writeTree()` | `0o777` narrowed by the process umask |

Under the common `0o022` umask that gives `0o644` for a new file and `0o755` for a created directory.
Under a stricter `0o077` umask it gives `0o600` and `0o700`.

For a configuration file this is the right default. Such a file is usually owned by the deployer, its
mode is often restrictive on purpose, and a write should not loosen it.

The carry-over has a consequence worth stating plainly: a mode, once set, is inherited forever. If a
file is created restrictive and later rewritten by code that wanted it readable, the rewrite keeps
the restrictive mode and nothing reports it. Generated output that is meant to be served therefore
should not rely on the derived mode.

## Explicit modes

Passing a mode makes it a property of the artifact rather than of the environment it lands in.

```php
FileWriter::write(
    filename: '/srv/site/index.html',
    content : $html,
    mode    : 0o644,
);
```

An explicit mode wins over both the destination's current bits and the process umask. Three
properties are deliberate:

- **Umask does not apply.** A restrictive umask on a build host would otherwise make every published
  asset unreadable by the web server, with no error anywhere.
- **The destination's mode does not apply.** A file that previously happened to be `0o600` becomes
  `0o644`, which is the point of naming the mode.
- **It is applied before the rename.** The mode is set on the temporary file, so the destination never
  exists with any other mode. Setting it on the destination afterwards would leave a window at the
  umask mode, and would touch whatever else happens to be at that path if the write had failed.

The mode is applied verbatim, not narrowed. `0o644` is `0o644` even if umask is `0o077`.

## Directory modes

`writeTree()` creates the missing directories and takes a directory mode on the same terms:

```php
FileWriter::writeTree(
    filename     : '/srv/build/site/guide/intro/index.html',
    content      : $html,
    mode         : 0o644,
    directoryMode: 0o755,
);
```

`mkdir()` is always masked by the process umask, so an explicit directory mode cannot be honoured by
the creation call alone. The mode passed to `mkdir()` is therefore the requested one, and the same
mode is applied immediately afterwards with a `chmod`, which is not masked. The intermediate state is
never wider than the result, and the end state is exact.

Only the directory that directly contains the file is adjusted. The rest of the created chain, and any
directory that already existed, are left as they are: widening a directory this call did not create is
not its decision, and a publish step that adjusted a shared parent could hand out more access than the
operator intended.

## Ownership

The new file is given the owner and group of the document it replaces, because a rename replaces the
inode and the new file would otherwise belong to whichever user ran PHP.

`chown` and `chgrp` need privilege, so both are attempted and a refusal is ignored rather than failing
the write. The outcome depends on how PHP is deployed:

| Host configuration | Result |
|---|---|
| PHP runs as the file's owner (FPM pool per user, suexec, CLI as the deployer) | owner, group and mode preserved |
| PHP runs as a privileged user | owner, group and mode preserved |
| PHP runs as a different unprivileged user than the owner | mode preserved, owner becomes the PHP user |

The third row is a host configuration issue rather than something this library can correct: an
unprivileged process cannot hand a file to another user. Such a deployment needs the PHP process user
to be the deployer, or the ownership corrected outside the application.

Ownership adoption is skipped when an explicit mode is passed, because the temporary file is already
the artifact and there is no previous document whose ownership is being preserved.

## Windows

None of the permission handling runs on Windows. That platform does not model POSIX permission bits:
`chmod` there only toggles the read-only attribute, and a read-only destination cannot be renamed over,
so adopting a mode would make the next write fail. The temporary file keeps the writable mode it was
created with, and a requested mode is accepted but has no effect.

## The containing directory needs write permission

Because the destination is replaced rather than written through, the write needs write permission on
the containing directory, where writing through an existing file needed write permission only on the
file itself. A deployment that granted the PHP user write access to `config/autoload/local.php` but not
to `config/autoload/` will fail after adopting this package, and the exception will name the file, not
the directory.
