# Acceptance tests

Quanta's Behat smoke suite exercises a real disposable installation.

## Run

```bash
composer install
composer test:behat
```

The runner creates a disposable test site, installs it through Doctor with the
generic profile, then overlays deterministic image-free content fixtures. This
keeps the core acceptance tests independent from demo-media thumbnail behavior.

The scenarios cover installation, page rendering, unresolved Qtags,
administrator login success and rejection, node creation, node loading, and
rendering a newly created node.

The test-site hostname and test credential can be overridden through the
`QUANTA_BEHAT_SITE` and `QUANTA_BEHAT_PASSWORD` environment variables.
