# Quanta behavior tests

The nine Behat scenarios cover homepage rendering, the real login form, normal
password login, incorrect password/unknown user, logout, node creation, editing,
and denied anonymous creation. Login and node changes use Quanta's existing HTTP
actions and real session cookies. Assertions check rendered DOM, the saved user
login timestamp, node JSON fields and author, and a subsequent rendered request.

## Run in the isolated Linux container

From the repository root, with Docker available:

```sh
docker build -f tests/behat/Dockerfile -t quanta-behat:local .
docker run --rm --network none quanta-behat:local
```

The build needs internet access for official PHP/Composer images, OS packages and
Composer dependencies. It uses PHP 8.5.12 with GD/cURL/mbstring and Apache, installs
development dependencies with `--no-scripts --no-plugins`, and runs as `www-data`.
It does not execute Quanta's Composer post-install hook changes, Doctor, the main
Dockerfile/entrypoint, or build/publish a production image. The test run itself
needs only loopback networking; no host ports or production directories are mounted.

The Dockerfile-specific ignore file keeps credentials, real sites, dependency
trees and unrelated build material out of the image. The root project's production
`.dockerignore` remains unchanged. No external service/account is required.

## Run with installed development dependencies

On Unix with PHP 8.5+, the required extensions and a Debian-style Apache/PHP
installation (`/etc/apache2/mods-enabled`, PHP module, rewrite and headers), run:

```sh
composer install --no-scripts --no-plugins
composer test:behat
# Behat arguments are forwarded, for example:
composer test:behat -- --name='Normal password'
```

Use a non-root account and leave localhost port 8080 free. The container above
provides this configuration; the native command does not install or reconfigure
your web server. Do not point these tests at a real site. `TMPDIR` selects where
the suite creates its uniquely named disposable fixture.

Each scenario starts with a fresh site and browser cookie jar. The runner links
the actual application source into a private fixture, creates synthetic content
and an `example.invalid` account, starts its own Apache instance, and stops it
after the suite. Cleanup is restricted to its marked fixture tree and never follows
the application symlinks. Failures return a nonzero exit and print server diagnostics.

## Coverage boundaries

Symfony BrowserKit supplies HTTP/DOM/cookie browser emulation. It does not execute
JavaScript, so the context submits the same JSON form envelope as Quanta's action
client; it does not call `User::logIn` or `Node::save` in place of those HTTP actions.
The seed uses the real user factory only to prepare the fixture. No forced login
is used. These tests do not cover the JavaScript overlay interactions, external
integrations, all application features, or the optional native qdb extension.

The GitHub workflow uses a standard Ubuntu runner with read-only contents
permission. Results and tool versions stay in job logs; there are no package
publishes, artifact uploads, cache actions or secrets.
