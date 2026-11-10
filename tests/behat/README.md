# Behat CMS tests

The default suite runs nine scenarios against Quanta's real PHP classes and
HTTP entrypoint. It covers Doctor installation, Qtag rendering, missing-content
pages, successful and rejected logins, logout, node persistence, page rendering
and isolation between scenarios. BrowserKit makes HTTP requests and keeps the
normal PHP session cookie; it does not execute JavaScript.

The `chrome` profile runs a tenth scenario in Chromium through Selenium. This
checks the JavaScript redirect from an unknown URL to the missing-content page.

## Run in Docker

From the repository root:

```sh
docker build -f tests/behat/Dockerfile -t quanta-behat .
docker run --rm --network none --cap-drop ALL --security-opt no-new-privileges quanta-behat
```

To run the real-browser scenario:

```sh
docker compose -f tests/behat/compose.yml up --build --abort-on-container-exit --exit-code-from tests
docker compose -f tests/behat/compose.yml down --volumes
```

The browser and PHP containers share a private network namespace so Chromium
can reach the temporary HTTP server on `127.0.0.1`. No host ports, directories,
credentials or Docker socket are mounted. The test image installs Composer
dependencies with scripts and plugins disabled. Its build context includes
only application source, profiles, Composer metadata and this harness.

## Run with Composer

With PHP 8.5, GD, DOM, cURL, mbstring and the other application prerequisites:

```sh
composer install --no-scripts --no-plugins
composer test:behat
composer test:behat -- --name='A user can sign in'
```

`composer test:behat:chrome` requires a Selenium Chromium server reachable at
`http://127.0.0.1:4444/wd/hub`; set `SELENIUM_URL` to override the WebDriver URL.
The browser must be able to reach the test PHP process on its loopback address,
as it does in the supplied Compose setup.

## Fixtures and new scenarios

`CmsFixture` creates a random directory in the system temporary directory and
links the checkout's source, profiles and vendor dependencies into it. Doctor
installs the generic profile with a test-only administrator password. Each
scenario receives a clean site, cache and PHP session directory. A small theme exposes the current
username and renders the normal content Qtags. Node steps call `NodeFactory`
in separate PHP processes so process-wide class maps and caches cannot leak
between sites. Authentication posts Quanta's existing AJAX action envelope to the normal entrypoint.

The server uses an OS-selected port and is stopped at suite shutdown, including
failures. Cleanup removes only the generated directory and does not follow
symlinks into the checkout. The test router is guarded to PHP's development
server and is excluded from the production Docker image.

Add scenarios to `features/cms.feature` and reusable domain steps to
`bootstrap/CmsContext.php`. Tag scenarios that need JavaScript with
`@javascript`; the default profile excludes them and the Chrome profile selects
them. Keep assertions on observable CMS behavior and avoid external services.

The `CMS behavior tests` GitHub Actions workflow runs both profiles on pull
requests. These smoke tests complement the existing logger and qdb suites;
they do not cover every module or external integration.
