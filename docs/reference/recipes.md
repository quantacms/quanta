# Recipes

Step-by-step checklists for the most common changes. Each one lists what to create, the wiring
that is easy to forget, and how to verify. `<module>` is a core module (`src/modules/<module>/`)
or a site module (`sites/<host>/_modules/<module>/`). Site-specific work always goes in a site
module.

## Add a Qtag

1. Create `<module>/classes/Qtags/<ClassName>.qtag.php` with `namespace Quanta\Qtags;` and
   `class <ClassName> extends Qtag` (or `HtmlTag`) implementing `render()`. See
   `templates-and-qtags.md#writing-a-qtag`.
2. Pick the tag name in `UPPER_SNAKE`: `[ORDER_TOTAL]` ↔ `OrderTotal`. The file basename must be
   exactly `OrderTotal`.
3. Escape output (`Api::filter_xss()`, `Api::string_normalize()`). Check access in `render()` if
   the tag exposes data: it can be rendered straight from a URL (`/q/ORDER_TOTAL/…`).
4. Don't change state in `render()`, and don't rely on per-occurrence randomness (identical
   markup renders once per request).
5. `php doctor <host> class_map`.
6. Verify: put the tag in a node's `tpl.html` and load the page, or request
   `/qtag/%5BORDER_TOTAL%5D/`.

## Implement a hook

1. In `<module>/hooks/<module>.hook.inc` (namespace `Quanta\Common`):

   ```php
   /**
    * Implements hook_node_after_save().
    *
    * @param Environment $env
    *   The Environment.
    * @param array $vars
    *   An array of variables.
    */
   function mymodule_node_after_save(Environment $env, array $vars) {
     $node = $vars['node'];
     // …
   }
   ```

2. Find the hook's `$vars` keys where it is fired: `grep -rn "hook('node_after_save'" src`.
3. Declare `array &$vars` if you must change a plain value the caller reads back (`api_routes`,
   for example).
4. Make sure the function name isn't already taken by another module, and doesn't accidentally
   match another hook (any `<module>_<x>` function is the implementation of hook `x`).
5. New hook files are picked up on the next request. No class-map rebuild is needed for hooks.

## Create a site module, or override a core one

1. `mkdir -p sites/<host>/_modules/<name>/{hooks,classes/Common,classes/Qtags,tpl,assets}` and
   add `hooks/<name>.hook.inc` starting with `<?php` and `namespace Quanta\Common;`.
2. Prefer a **new name** that hooks into core behaviour. A module named like a core module
   *replaces* it completely, so its hooks and classes stop loading.
3. To override a single core class, add a class file with the same basename in the site module.
   This shadows the core class for the whole site; document why in the file.
4. `php doctor <host> check` (class map + bundles).

## Add a form with a submit handler

1. Markup, for example in a node's `tpl.html`. The form id uses underscores, and every `INPUT`
   targets it:

   ```
   [FORM|name=newsletter_signup|ok_message=Thanks!:
     [INPUT|type=email|required|name=email|title=Email:newsletter_signup]
     [INPUT|type=submit|name=go|value=Subscribe:newsletter_signup]
   ]
   ```

2. Validation and submit, in a module's hook file:

   ```php
   function mymodule_newsletter_signup_form_validate(Environment $env, array &$vars) {
     $form_state = $vars['form_state'];
     if (!filter_var($form_state->getData('email'), FILTER_VALIDATE_EMAIL)) {
       $form_state->validationError('email', t('Please enter a valid e-mail address.'));
     }
   }

   function mymodule_newsletter_signup_form_submit(Environment $env, array &$vars) {
     $form_state = $vars['form_state'];
     // Store it as a node, queue a mail, etc.
   }
   ```

3. Read submitted values with `$form_state->getData()`. The field objects (`getItems()`) don't
   exist yet when the submit hooks run during `boot`.
4. Verify: submit with valid and invalid input, and confirm `ok_message` and the validation
   message both show.

## Add an API endpoint

1. Optional route with placeholders:

   ```php
   function mymodule_api_routes(Environment $env, array &$vars) {
     $vars['routes'][] = '1/{order_id}/order_status';
   }
   ```

2. Handler. The name comes from the literal segments, with the leading version dropped:

   ```php
   function mymodule_api_get_order_status(Environment $env, array $vars) {
     $user = UserFactory::current($env);
     if (!$user->hasRole(User::ROLE_LOGGED)) {
       http_response_code(403);
       exit(json_encode(array('success' => FALSE, 'error_code' => 'forbidden')));
     }
     $order_id = $vars['path_parts'][0];
     exit(json_encode(array('success' => TRUE, 'status' => '…')));
   }
   ```

3. Always `exit` with the response. Otherwise the router appends its 404 JSON. Always check auth
   yourself: the router has none. Captured values also have `-` turned into `_`, because the
   whole path is converted before matching.
4. Verify: `curl -s https://<host>/api/1/<id>/order-status/` (hyphens map to underscores).

## Add a JSON action

1. `function mymodule_action_<name>(Environment $env, array $vars)`, with the payload in
   `$vars['data']`.
2. Check access (`NodeAccess::check()` / `UserFactory::current()`), do the work, and
   `exit(json_encode($response))`.
3. Client side: `engine.js`'s `action()` POSTs a JSON string, as `list.js` does:
   `action('{"action": {"value":"<name>"}, "nodes": ' + JSON.stringify(nodes) + '}')`. Shadow
   forms send their hidden `action` field the same way.

## Add a job type

1. Queue: `JobsFactory::queueJob($env, '<type>', $source, $data)`.
2. Handle: `function mymodule_job_run_<type>(Environment $env, array &$vars)`. Do the work, then
   set `$vars['completed'] = TRUE` (and optionally `$vars['log']`).
3. Make sure something calls `JobsFactory::processQueue($env)` (core doesn't), for example a
   doctor command run by cron.

## Add CSS or JavaScript

1. Put the file in `<module>/assets/css/` or `assets/js/`.
2. Register it in `function mymodule_load_includes(Environment $env, array $vars)` with
   `$env->addInclude($env->getModulePath('mymodule') . '/assets/js/mymodule.js')`.
3. `php doctor <host> check` to rebuild the bundles. In a downstream Docker image, run
   `RUN quanta-build-assets` after copying the site.
4. Inline JS: no `//` comments, because newlines are stripped before rendering.

## Add a doctor command

1. `function mymodule_doctor_<command>(Environment $env, array $vars)`. `$vars['doctor']` is the
   `Doctor`: `op()`, `ok()`, `ko()`, `ask()`, `execute()`. Options arrive as `--key=value`.
2. A hyphenated command (`my-command`) maps to `mymodule_doctor_my_command`.
3. Verify: `php doctor <host> <command>`. An unimplemented command prints "Wrong doctor command".

## Add or change a node storage method

Applies to any change in `FilesDb` / `FilesDbExt`
(`src/modules/environment/classes/Common/`) or any new `$env->db()` call site.

1. **Call sites** just call `$this->env->db()-><method>(…, array('at' => $path))`. Don't branch on
   `available()` / `coherent()`, and catch `FilesDbException` only where a failure needs a message
   (`qdb/docs/usage.md`, "Writing a new call site").
2. **`FilesDb`**: implement the complete filesystem behaviour. It must work with no extension.
3. **`FilesDbExt`**: ask `\QuantaDb` first. When it can't answer (query not expressible, index
   incoherent, `at` mismatch, exception), `return parent::<method>(…)`. Rethrow write failures as
   `FilesDbException`; never retry a failed write on the filesystem.
4. **Parity tests**: add assertions to the matching `qdb/tests/quanta/0N_*.php`. Use `ok`/`eq` for
   behaviour that must hold in every mode, and the `_ext` / daemon variants for extension-only
   facts. Add a counter assertion to `06_wired.php` so a silent fallback fails the suite.
5. If the extension needs a new operation, change the contract (`qdb/docs/api-contract.md`,
   `CONTRACT_VERSION`, the stub, `usage.md`). See `qdb/AGENTS.md`.
6. Update the "Where it is wired" table in `qdb/docs/usage.md` and its counterpart in
   `qdb/docs/api-contract.md`.
7. Run `sh qdb/tests/run-in-docker.sh` (all three modes).

## Add or change a URL route

1. Apache: `.htaccess`. nginx: `docker/nginx/quanta.conf`, keeping the parity table at its top up
   to date. Change both.
2. In nginx, a new `location ^~` block must `include` the three deny snippets
   (`docker/nginx/snippets/deny-dotfiles.conf`, `deny-php.conf`, `deny-dev-files.conf`).
3. Prefer handling application routes in PHP (a `boot` hook that inspects `$env->request` and
   exits) over web-server rules, because that works in both servers unchanged.
4. Verify: `sh qdb/tests/run-in-docker.sh parity` (runs `nginx -t`), then request the URL against
   a running container and a native Apache install.
