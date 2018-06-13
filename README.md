# Imposer: Modular State and Configuration Management for Wordpress

Storing configuration in a database *sucks*.  You can't easily document it, revision control it, reproduce it, or vary it programmatically.

Unfortunately, Wordpress doesn't really give you many alternatives.  While some configuration management tools exist, they typically lack one or more of the following:

* Twelve-factor support (e.g. environment variables for secrets, keys, API urls, etc.)
* Modularity (ability to bundle states as, or with plugins, wp-cli packages, or composer libraries)
* Incrementality/Composability (ability to spread configuration across multiple files, even when the values are settings for different aspects of the same plugin, page, menu, etc.)
* Scriptability and metaprogramming (ability to use PHP or other scripting languages to decide how to generate data based on other aspects of the configuration, or external files, environment vars, etc.)

Imposer is a command-line tool (for \*nix-type OSes) that does all of these things using "state" files.

State files work a bit like "[migrations](https://en.wikipedia.org/wiki/Schema_migration)" or [Drupal's "features"](https://www.drupal.org/project/features).  They're Markdown documents whose names end in `.state.md`, and can contain code blocks written in various languages, including YAML, JSON, shell script, PHP, and [jq](http://stedolan.github.io/jq/).

State files can be distributed as part of Wordpress plugins, themes, composer or wp-cli packages, or simply placed in any directory listed by an `IMPOSER_PATH` environment variable.  States can be applied on the command line, and those states can `require` other states.  YAML, JSON, shell and jq blocks are executed first, in source order, to create a jq program that builds a JSON configuration map of the desired states.

This JSON configuration map is then passed to various [actions and filters](#actions-and-filters) by way of a wp-cli command.  But first, all the PHP code supplied by all the loaded states is run, so that state files can register any needed action and filter callbacks.  This makes it easy to extend the configuration format in a state file, by adding JSON data to set up the configuration, and PHP callbacks to apply that configuration to the Wordpress database.  (For more details on how to do this, see "[Extending The System](#extending-the-system)", below.)

### Contents

<!-- toc -->

- [How States Work](#how-states-work)
  * [Adding Code Tweaks](#adding-code-tweaks)
  * [Extending The System](#extending-the-system)
  * [Actions and Filters](#actions-and-filters)
  * [Event Hooks](#event-hooks)
  * [Identifying What Options To Set](#identifying-what-options-to-set)
- [Installation, Requirements, and Use](#installation-requirements-and-use)
  * [Lookup and Processing Order](#lookup-and-processing-order)
    + [State File Lookup](#state-file-lookup)
    + [IMPOSER_PATH](#imposer_path)
    + [Processing Phases for `imposer apply`](#processing-phases-for-imposer-apply)
    + [Dependency Ordering](#dependency-ordering)
  * [Imposer Subcommands](#imposer-subcommands)
    + [imposer apply *[state...]*](#imposer-apply-state)
    + [imposer json *[state...]*](#imposer-json-state)
    + [imposer options](#imposer-options)
      - [imposer options review](#imposer-options-review)
      - [imposer options list *[list-options...]*](#imposer-options-list-list-options)
      - [imposer options diff](#imposer-options-diff)
      - [imposer options watch](#imposer-options-watch)
    + [imposer php *[state...]*](#imposer-php-state)
    + [imposer sources *[state...]*](#imposer-sources-state)
    + [imposer tweaks](#imposer-tweaks)
    + [imposer path](#imposer-path)
    + [imposer default-path](#imposer-default-path)
- [Project Status](#project-status)
  * [Performance Notes](#performance-notes)

<!-- tocstop -->

## How States Work

If this document were a state file, it might contain some YAML like this, to set options for the wp_mail_smtp plugin:

```yaml
options:
  wp_mail_smtp:
    mail:
      from_email: \(env.WP_FROM_EMAIL)
      from_name: \(env.WP_FROM_NAME)
      mailer: mailgun
      return_path: true
    mailgun:
      api_key: \(env.MAILGUN_API_KEY)
      domain: \(env.MAILGUN_API_DOMAIN)
```

This is already sufficient to be a state file.  State files are parsed using [jqmd](https://github.com/bashup/jqmd), so strings in YAML blocks can contain [jq](http://stedolan.github.io/jq/) interpolation expressions like ``\(env.MAILGUN_API_KEY)`` to get values from environment variables.  (JSON blocks can do that too, and use plain jq expressions as well as string interpolation.)

A state file can include multiple YAML or JSON blocks, and their contents are merged, with later values overriding earlier ones (or appending in the case of lists and arrays).  In addition to setting options, we can also indicate that a particular plugin should be activated or deactivated:

```yaml
plugins:
  wp_mail_smtp:      # if a value is omitted or true, the plugin is activated
  disable_me: false  # if the value is explicitly `false`, deactivate the plugin
```

Of course, depending on the state we're defining, it might rely on other states.  We can add a `shell` block to do that:

```shell
# Load a required states before proceeding
require "some/state"

# Use `have_state` to test for availability
if have_state "foo/other"; then
    require "foo/other" "this/that"
fi
```

Now that we've done that, any YAML or JSON we include will *override* what the above states set, and any PHP code we include will run after the PHP code defined in those states.  (Since each state file is only loaded once during an `imposer` run, we can use `require` to enforce precedence between states.)

Let's quickly override something with a bit of jq code:

```jq
.plugins."some-plugin" = true  # activate `some-plugin`
```

Each `jq` block must be a valid jq filter expression.  (If you want to do multiple things in the same block, separate them with a `|`. )

For most things, though, it's both clearer and simpler to just use YAML and JSON blocks, leaving jq code for those rare instances where you need to manipulate the configuration in a way not supported by YAML or JSON blocks.  (For more on what you can do with `jq` blocks and shell scripting, see the [jqmd docs](https://github.com/bashup/jqmd).)

Last, but not least, you can define PHP blocks.  Unlike the other types of blocks, PHP blocks are "saved up" and executed later, after imposer has finished executing all of the state files to create the complete JSON configuration map.  PHP blocks are individually syntax-checked, and can contain namespaced code as long as the entire block is either wrapped in  `namespace ... {  }` blocks, or does not use namespaces at all.

All the PHP blocks defined by all the states are joined together in one giant PHP file that gets loaded by wp-cli, just before imposer's [Wordpress hooks](#actions-and-filters) are invoked.  Most of those hooks receive a `$state` parameter containing the full JSON configuration map, so your PHP blocks can register callbacks to make database changes using this data.

(Note: the configuration map in `$state` is built up from nothing on each imposer run, and only contains values put there by the state files loaded *during that imposer run*.  It does *not* contain any existing plugin or option settings, etc.  If you need to read the existing-in-Wordpress values of such things, you must use PHP code that invokes the Wordpress API.  Think of the configuration map as a to-do list or list of "things we'd like to ensure are this way in Wordpress as of this run".)

### Adding Code Tweaks

A lot of Wordpress plugins require you to add code to your theme's `functions.php` in order to get them to work the way you want.  But it can be a hassle to manage those bits of code, especially when switching themes, or when you need a tweak to be applied consistently across multiple sites.  To address this issue, state files can also include "tweaks" -- code blocks tagged as `php tweak`, like this one:

```php tweak
add_filter('made_up_example', '__return_true');
```

When you run `imposer apply`, these code blocks are joined together and written to a dummy plugin called `imposer-tweaks` in the `$IMPOSER_PLUGINS` directory (which defaults to the `wp plugin path`).  This plugin is also activated, unless you explicitly *deactivate* it at some point after the first `php tweak` block is reached.

Since any state file can potentially include tweaks, this is a powerful tool for modularizing and reusing these types of code snippets.

Note, however, that only state files that are directly or indirectly `require`d by your `imposer-project.md` or global imposer configuration will have their tweaks included in the plugin.  If you specify states on the command line that contain tweaks, Imposer will output warnings for each such state, and will not actually add the code to the generated plugin.  (This is because the plugin is generated from scratch each time, so its contents would change whenever you re-ran `imposer apply` with different arguments.)

### Extending The System

Aside from tweaks, you can use PHP blocks to do custom WP API operations and to extend the configuration format.  For example, if there's a plugin or wp-cli package that defines an API for some type of object, like LMS courses or e-commerce products, you could extend the configuration format with a new top-level key like `my_ecommerce_plugin`, containing a subkey for products.

You would then include a state file in your plugin to initialize this key, with something like:

```yaml
my_ecommerce_plugin:
  products: {}   # empty maps for other state files to insert configuration into
  categories: {}
```

Your plugin would then follow this with some PHP code to read this data  and do something with it.  It's best to keep the actual code in the state file brief, just calling into your actual plugin to load the data like this:

```php
function my_ecommerce_plugin_impose($data) {
	MyPluginAPI::setup_products($data['products']);
	MyPluginAPI::setup_categories($data['categories']);
}

add_action('imposer_impose_my_ecommerce_plugin', 'my_ecommerce_plugin_impose', 10, 1);
```

And then users who want to impose product definitions in their state files would `require` your state file before adding in their own YAML or JSON with product data.  Any PHP they defined after `require`-ing your state file would then run after your own PHP code, allowing others to further extend and build on what you did.

(You might be wondering why you couldn't just put the above code directly into your plugin.  Well, you *could*, except then you would have bugs whenever your plugin is freshly activated.  By the time imposer loads the code for a freshly-activated plugin, it's too late to register for almost any of imposer's actions or filters!)

So, given that this code is only needed when running `imposer apply` anyway, you might as well just put it in the state file.  If you absolutely *must* put the code in your plugin, though, you can always do something like this in the state file:

````php
// Pre-activate the plugin so it can register its hooks early
activate_plugin('my_ecommerce_plugin/my-ecommerce-plugin.php');
````

If you're distributing your state as part of a wordpress theme or plugin, you can include the state file as `default.state.md` in the root of your plugin, or inside an `imposer-states/` subdirectory.  Users can then `require "your-theme-or-plugin-name"` to load the default state file.  If on the other hand you're distributing it as a `composer` package, it would work the same way  except people would `require "your-org/your-name"` to load its default state file.

You can of course have state files besides a default: you can use them to provide a variety of profiles for configuring your plugin or theme.  For example, if your theme has various example or starter sites, you can define them as state files, and people could import them with `imposer apply my-theme/portfolio`, to load the `portfolio.state.md` in the root or `imposer-states/` subdirectory of your theme.  Such state files can depend on other state files, and users can build their own state files on top of those, using `require`.

### Actions and Filters

For plugins and PHP blocks within state files, imposer offers the following actions and filters (listed in execution order):

* `apply_filters("imposer_state_$key", $value, $state)` -- each top-level key in the JSON configuration map is passed through a filter named `imposer_state_KEY`, where `KEY` is the key name.  So for example, if you want to alter the `options` or `plugins` imposer will apply, you can add filters to `imposer_state_options` and `imposer_state_plugins`.  The first argument is the value of the key, the second is the current contents of the overall configuration map.

* `apply_filters("imposer_state", $state)` -- add a filter to this hook to make any changes to the configuration map that span multiple keys: the individual keys will have already been modified by the preceding `imposer_state_KEY` filters.

* `do_action("imposer_impose_$key", $value, $state, $imposer)` -- hook this to actually perform your state or plugin's configuration process.  Each top-level key in the JSON configuration map is passed exactly once to an action called `imposer_impose_KEY`, where `KEY` is the key name.

  The first argument passed to this action is the value of the key, the second is the contents of the overall configuration map, and the third is a `dirtsimple\Imposer` object that you can call `$imposer->impose('key1', 'key2', ...)` on, to ensure that the other keys have been applied before you continue.)

  For example, if you're writing an action that imports posts, it might `$imposer->impose('categories')` at the beginning to ensure that any needed categories are already set up.  (Assuming there was a top-level configuration key called `categories`, of course!)  `impose()` is a no-op for keys that have already been applied or are in the middle of being applied, so it's safe to call it from more than one place.

  Some changes to Wordpress's state may require restarting the PHP process to fully take effect (e.g. theme/plugin activation or deactivation).  You can request a restart with `$imposer->request_restart()`.  The process will exit and restart at the end of the current or next `$imposer->impose()` call, which means you should not call `$imposer->impose()` in the middle of anything that would be hurt by an abrupt exit.

* `do_action('imposed_state', $state)` -- this is run after all top-level keys have been imposed, to allow for cleanup operations before the script exits.

Note that the ordering of key-specific hooks is not guaranteed.  They may run in JSON config order, but  `imposer_impose_KEY` actions will start with `plugins` and `options`, and the actions for other keys can request that other keys be imposed first (using `$imposer->impose('key', ...)`).  You can even register actions for `imposer_impose_options` or `imposer_impose_plugins` that force some *other* keys to be processed before these, so the exact order in which impose hooks runs will be determined by dependency resolution at runtime.

### Event Hooks

In additon to its PHP actions and filters, Imposer offers a system of event hooks for `shell` code.  State files can use the [bashup events](https://github.com/bashup/events/) API to register bash functions that will then be called when specific events are fired.  For example:

```shell
my_plugin.message() { echo "$@"; }
my_plugin.handle_json() { echo "The JSON configuration is:"; echo "$IMPOSER_JSON"; }

event on "after_state"              my_plugin.message "The current state file ($IMPOSER_STATE) is finished loading."
event on "state_loaded" @1          my_plugin.message "Just loaded a state called:"
event on "state_loaded_this/that"   my_plugin.message "State 'this/that' has been loaded"
event on "persistent_states_loaded" my_plugin.message "The project configuration has been loaded."
event on "all_states_loaded"        my_plugin.message "All states have finished loading."
event on "before_apply"             my_plugin.handle_json
event on "after_apply"              my_plugin.message "All PHP code has been run."
```

The system is very similar to Wordpress actions, except there is no priority system, and you specify the number of *additional* arguments your function takes by adding a `@` and a number before the callback.  (So above, the `state_loaded` event will pass up to one argument to `my_plugin.message` in addition to `"Just loaded a state called:"`, which in this case will be the name of the state loaded.)

Also, you can put arguments after the name of your function, and any arguments supplied by the event will be added after those. Duplicate registrations have no effect, but you can register the same function multiple times for the same event if it has different arguments or a different argument count.

Imposer currently offers the following built-in events:

* `after_state` -- fires when the *currently loading* state file (and all its dependencies) have finished loading.  (Note that the "currently loading" file is not necessarily the same as the file where a callback is being registered, which means that state files can define APIs that register callbacks to run when the *calling* state file is finished.)

* `state_loaded` *statename sourcefile*-- emitted when *any* state has finished loading.  Callbacks can register to receive up to two arguments: the state's name and the path to the source file it was loaded from.

* `state_loaded_`*statename* -- a [promise-like event](https://github.com/bashup/events/#promise-like-events) that's resolved when the named state is loaded.  If you register a callback before the state is loaded, it will be called if/when the state is loaded later.  But if you register a callback *after* the state is already loaded, the callback will run immediately.  This allows you to have "addon" code or configuration that's only included if some other state is loaded, e.g.:

  ````sh
  # If some other state loads "otherplugin/something", load our addon for it:
  on state_loaded_"otherplugin/something" require "my_plugin/addons/otherplugin-something"
  ````

* `persistent_states_loaded` -- fires after the global and project-specific configuration files have been loaded, along with any states they `require`d.  This event is another promise-like event: you can register for it even after it has already happened, and your callback will be invoked immediately.

  The purpose of this event is to let you disable functionality that should only be available to persistent (i.e. project-defined) states, and not from states added on the command line.

* `all_states_loaded` -- fires when all state files are finished loading, but before jq is run to produce the configuration JSON.  You can hook this to add additional data or jq code that will postprocess your configuration in some fashion.

* `before_apply` -- fires after jq has been run, with the JSON configuration in the read-only variable `$IMPOSER_JSON`.  You can hook this event to run shell operations before any PHP code is run.

* `after_apply` -- fires after imposer's [actions and filters](#actions-and-filters) have successfully completed their database updates, allowing you to run additional shell commands afterwards.

Of course, just like with Wordpress, you are not restricted to the built-in events!  You can create your own custom events, and trigger them with `event emit`, `event fire`, etc..  (See the [event API docs](https://github.com/bashup/events/#readme) for more info.)

Note: if your state file needs to run shell commands that will change the state of the system in some way, you must **only** run these commands during the `before_apply` or `after_apply` events, so that they are only run by the  `imposer apply` subcommand and not by diagnostic commands like `imposer json` or `imposer php`.

### Identifying What Options To Set

To set up the configuration in a state file, you need to know what option keys and values you need to set.  But since most users only configure Wordpress via the web UI, plugin developers rarely *document* the relevant keys and values.  So, to help you discover what keys and values to use, imposer provides tools that let you inspect and monitor option changes made through the Wordpress UI.

The main tool you will use for this process is `imposer options review`, which lets you interactively review and approve changes made to the options in the database since your last review.  (Note: it's still up to you to edit your state file(s) to set anything you want set.  Approving a change is just a way to say, "I've seen this change and done whatever I need to do about it, so stop showing it to me".)

To start a review, just run `imposer options review`.  Either you'll be immediately presented with any existing changes as JSON patch chunks (for review and approval via the `git add --patch` UI), or else the command will begin monitoring the database for new changes, waiting for you to change something via the Wordpress UI.

Once you've approved a change, it won't show up during future runs of the `review`, `diff`, or `watch` subcommands of `imposer options`.  This lets you filter out changes you already know how to map to a state file, and irrelevant "noise" changes (like changes to the `cron` option), while still observing changes to values you're still working on with `watch` or `diff`.

For more details on imposer's tools for monitoring and inspecting Wordpress options, see the [imposer options](#imposer-options) command reference, below.

## Installation, Requirements, and Use

Imposer is packaged with composer, and is intended to be installed that way, i.e. via `composer require dirtsimple/imposer:dev-master` or `composer global require dirtsimple/imposer:dev-master`.  In either case, make sure that the appropriate `vendor/bin` directory is on your `PATH`, so that you can just run `imposer` without having to specify the exact location.

In addition to PHP, Composer, and Wordpress, imposer requires:

* [jq](http://stedolan.github.io/jq/) 1.5 or better
* the bash shell, version 3.2 or better
* Optionally, a copy of [this fast yaml2json written in go](https://github.com/bronze1man/yaml2json), to speed up YAML processing over the [yaml2json.php](https://packagist.org/packages/dirtsimple/yaml2json) that gets installed alongside imposer.

Imposer is not yet regularly tested on anything other than Linux, but it *should* work on OS/X and other Unix-like operating systems with a suitable version of bash and jq.  (It *can* be run on Windows using the Cygwin versions of PHP and jq, but will run painfully slowly compared to the Linux Subsystem for Windows or using a Linux VM or Docker container.)

To use Imposer, you must have an `imposer-project.md`,  `composer.json` OR `wp-cli.yml` file located in the root of your current project.  Imposer will search the current directory and its parent directories until it finds one of the three files, and all relative paths (e.g. those in `IMPOSER_PATH`) will be treated as relative to that directory.  (And all code in state files is executed with that directory as the current directory.)  If you have an `imposer-project.md`, it will be loaded as though it were the state file for a state called `imposer-project`.

Basic usage is `imposer apply` *state...*, where each state name designates a state file to be loaded.  States are loaded in the order specified, unless an earlier state `require`s a later state, forcing it to be loaded earlier than its position in the list.  You don't have to list any states if everything you want to apply is in your `imposer-project.md`, or is `require`d by it.

### Lookup and Processing Order

#### State File Lookup

For convenience, state names do not include the `.state.md` suffix, and can also just be the name of a composer package (e.g. `foo/bar`) or theme/plugin directory (e.g. `sometheme` or `someplugin`).  Given a string such as `foo/bar/baz`, imposer will look for the following files in the following order, in each directory on `IMPOSER_PATH`, with the first found file being used:

* `foo/bar/baz.state.md` (the exact name, as a `.state.md` file)
* `foo/bar/baz/default.state.md` (the exact name as a directory, containing a `default.state.md` file)
* `foo/bar/baz/imposer-states/default.state.md` (the exact name as a directory, containing an `imposer-states/default.state.md` file)
* `foo/bar/imposer-states/baz.state.md` (the namespace of the name as a directory, containing the last name part in an `imposer-states` subdirectory)

(The last rule means that you can create a composer package called e.g. `mycompany/imposer-states` containing a library of state files, that you can then require as `mycompany/foo` to load `foo.state.md` from `vendor/mycompany/imposer-states`.  Or you can make a Wordpress plugin called `myplugin`, and then require  `myplugin/bar` to load `bar.state.md` from the plugin's `imposer-states/` directory or its root.)

#### IMPOSER_PATH

The default `IMPOSER_PATH` is assembled from:

* `./imposer` (i.e., the `imposer` subdirectory of the project root)
* `$IMPOSER_THEMES`, defaulting to the Wordpress themes directory as provided by `wp theme path` (e.g. `wp-content/themes/`)
* `$IMPOSER_PLUGINS`, defaulting to the Wordpress plugin directory as provided by `wp plugin path` (e.g. `wp-content/plugins/`)
* `$IMPOSER_VENDOR`, defaulting to the `COMPOSER_VENDOR_DIR` (e.g. `vendor/`), if a `composer.json` is present
* `$IMPOSER_PACKAGES`, defaulting to the `vendor/` subdirectory of the `wp package path` (typically `~/.wp-cli/packages/vendor`)
* `$IMPOSER_GLOBALS`, defaulting to the global composer `vendor` directory, e.g. `${COMPOSER_HOME}/vendor`

(You can remove any of the above directories from consideration for the default `IMPOSER_PATH` by setting the corresponding variable to an empty string.)

This allows states to be distributed and installed in a variety of ways, while still being overrideable at the project level (via the main `imposer/`) directory.  (For example, if you add an `imposer/foo/bar.state.md` file to your project, it will replace the `bar` state of any theme/plugin named `foo`, or the default state of a composer package named `foo/bar`.)

#### Processing Phases for `imposer apply`

While we're discussing precedence order, you may find it useful to have an explicit listing of the phases in which `imposer apply` executes:

* First phase: **load and execute state files** by converting them to (timestamp-cached) shell scripts that are then `source`d by imposer.  (The `all_states_loaded` event fires at the end of this phase.)  During this phase, most non-shell code blocks are accumulated in memory for use by later phases.
* Second phase: **generate a JSON configuration map** by running the `jq` command on the accumulated jq code generated by the YAML, JSON, jq, or shell code blocks processed during the first phase.
* Third phase: **apply changes to Wordpress** by:
  1. Firing the `before_apply` [shell event](#event-hooks) (which will also generate the `imposer-tweaks` plugin file for any accumulated [code tweaks](#adding-code-tweaks))
  2. Running imposer's PHP code (via `wp eval`) to load the PHP code accumulated during the first phase, then running the imposer [actions and filters](#actions-and-filters) to actually update the Wordpress database.  (This step may run multiple times if `$imposer->request_restart()` is called, e.g. when the theme is changed or plugins are activated or deactivated.)
  3. If the previous steps finished without a fatal error, the `after_apply` shell event is then run.

So, even though it looks like shell, PHP, and YAML/JSON/jq code execution are interleaved, in reality all the shell code is executed first: it's just that any code block *other* than shell code blocks are translated to shell code that accumulates either jq or PHP code for execution in the second or third phase.  This means that you can't (for example) have two YAML blocks reference the same environment variable and change its value "in between them" using shell code, because whatever value the environment variable has at the end of phase one is what will be used when *both* blocks are executed during phase two.

#### Dependency Ordering

State files and top-level configuration keys are processed in *dependency order*, meaning that any state file or `imposer_impose_KEY` action can trigger the processing of other states or keys, using `require` and `$imposer->impose()` respectively.  In both cases, the operation does nothing if the requested state or key has already been processed, *or is being processed*.

That is, in the event of a circular dependency, the state or key that completes the cycle will proceed even though its dependency is not actually finished yet.  Future versions of imposer may issue warnings or abort with an error when dependency cycles are discovered.

### Imposer Subcommands

Note: imposer always operates on the nearest directory at or above the current directory that contains either an `imposer-project.md `, a `wp-cli.yml`, and/or a `composer.json`.  This directory is assumed to be your project root, and all relative paths are based there.

(Note also that imposer does not currently support operating on remote sites: state files are always read and run on the *local* machine, and cannot be executed remotely by wp-cli.  If you need to run a command remotely, use something like e.g. `ssh myserver bash -c 'cd /my/wp-instance; imposer apply'` instead.)

#### imposer apply *[state...]*

Load and execute the specified states, building a JSON configuration and accumulating PHP code, before handing them both off to `wp eval` to run the PHP code and invoke the imposer [actions and filters](#actions-and-filters).

#### imposer json *[state...]*

Just like `imposer apply`, except that instead of actually applying the changes, the JSON is written to standard output for debugging.  The `all_states_loaded` shell event will fire, but the `before_apply` and `after_apply` events will not.  (Any jq and shell code in the states will still execute, since that's how the JSON is created in the first place.)

If the output is a tty and `less` is available, the output is colorized and paged.  `IMPOSER_PAGER` can be set to override the default of `less -FRX`; an empty string disables paging.

#### imposer options

The `imposer options` subcommand provides various sub-subcommands for inspecting the contents of, and monitoring changes made to, the Wordpress options table.

For the most part, you will only use these subcommands on a development instance of Wordpress, in order to discover and verify what options in the database match what parts of a plugin's configuration UI.  (So you can figure out what to put in your state files for production, or verify that your state files are setting things correctly.)

By default, changes to options are monitored using a git repository in `$IMPOSER_CACHE/.options-snapshot`, but this location can be overridden by setting `IMPOSER_OPTIONS_SNAPSHOT `or by passing the `--dir` option to `imposer options`.  (For example, `imposer options --dir foo review` runs the `review` command against a git repository called `foo` under the current project root.)

Note that although snapshot directories are managed using git, their contents should *not* be considered part of your project, and should not be committed or pushed to any remote servers, as they may contain security-sensitive data.  (Note, too, that you can safely *delete* a snapshot directory at any time, as nothing is lost except the knowledge of what options were changed since the last fully-approved `review`.)

Most `imposer options` subcommands provide paged and colorized output, unless their output is piped or redirected to a file.  JSON is colorized using `jq`, diffs are colorized with `colordiff` or `pygments` if available, and paging is done with `less -FRX`.  (You can override the diff coloring command by setting `IMPOSER_COLORDIFF`, and the paging command via `IMPOSER_PAGER`.  Setting them to empty disables diff coloring and/or paging.)

##### imposer options review

Interactively revew and approve changes made to the JSON form of all non-transient Wordpress options since the last review, or the last time the snapshot directory was erased.  (If there are no changes pending, the command waits, taking snapshots of the options in the database every 10 seconds until a change is detected.)

The review process has the same UI as `git add --patch`: any changes you approve will not show up on future reviews, allowing you to figuratively "sign off" on the changes that you understand, or which are irrelevant to your current goals (like changes to the `cron` option).  Once you've approved a change, it will not show up during future runs of `imposer options` subcommands such as `review`, `diff`, or `watch`.

(Note: on Alpine linux, the default `git` package doesn't include the UI for `git add --patch`.  So, if you're working in an Alpine environment (e.g. in a docker container), you'll need to install the Alpine `git-perl` package to make `options review` work correctly.)

##### imposer options list *[list-options...]*

This command outputs a JSON map of all non-transient wordpress options, in the form they would need to appear under the `options` key in the imposer state.  (You can use the `wp option list` options `--search=`, `--exclude=`, and `--autoload=` to limit the output to a desired subset of options.)

##### imposer options diff

Compare the current output of `imposer options list` against the last approved snapshot, displaying the differences as a unified diff (possibly colorized and paged).

##### imposer options watch

Every 10 seconds, clear the screen and display the first screenful of output from `imposer options diff`.  (Use Control-C to exit.)

#### imposer php *[state...]*

Just like `imposer apply`, except that instead of actually applying the changes, the accumulated PHP code is dumped to stdout instead.  The `all_states_loaded` shell event will fire, but the `before_apply` and `after_apply` events will not.

If the output is a tty and `pygmentize` and `less` are available, the output is colorized and paged.  `IMPOSER_PAGER` can be set to override the default of `less -FRX`, and `IMPOSER_PHP_COLOR` can be set to override the default of `pygmentize -f 256 -O style=igor -l php`; setting them to empty strings disables them.

#### imposer sources *[state...]*

Like the `json` and `php` commands, except that a list of all source state files is output, one file per line, using paths relative to the project root. (This can be useful as input to a file watcher like [entr](http://entrproject.org/), to watch the files for changes and re-run `imposer apply`.)

If the output is a tty and `$IMPOSER_PAGER` is available (`less -FRX` by default), the output is paged.

The output includes all source files read (or cached), including any global config files and the `imposer-project.md`, if any.  If a state file reads non-state files as part of its operation, it should call the shell function `mark-read` with one or more file names.  The named files will then be output when this command is run.

#### imposer tweaks

Outputs the PHP that would be written to `imposer-tweaks.php` if `imposer apply` were run.  The output is colorized and paged (if possible) according to the same rules as for [`imposer php`](#imposer-php-state).

#### imposer path

Output a `:`-separated list of absolute paths that imposer will currently look for states under.  The output takes into account the current value of  `IMPOSER_PATH`, if any.  This value is useful for checking that imposer is searching where you think it's searching.

#### imposer default-path

Like `imposer path`, except that the current value of `IMPOSER_PATH` is ignored.  This is useful for computing what value you might want to put into `IMPOSER_PATH` to speed up start times.

## Project Status

Currently, this project is in very early development, as it doesn't have 100% documentation or test coverage yet, nor does it provide a built-in schema for any configuration other than Wordpress options and plugin activation.  (But the configuration schema can be extended using state files, as described in [Extending The System](#extending-the-system) above.)

### Performance Notes

While imposer is not generally performance-critical, you may be running it a lot during development, and a second or two of run time can add up quickly during rapid development.  If you are experiencing slow run times, you may wish to note that:

* Due to limitations of the Windows platform, bash scripts like imposer run painfully slowly under Cygwin.  If possible, use a VM, Docker container, or the Linux Subsystem for Windows to get decent performance.
* On average, Imposer spends most of its execution time running large php programs (`composer` and `wp`) from the command line, so [enabling the CLI opcache](https://pierre-schmitz.com/using-opcache-to-speed-up-your-cli-scripts/) will help a lot.
* Currently, calculating the default `IMPOSER_PATH` is slow because it runs `wp` and `composer` up to three times each.  You can speed this up considerably by supplying an explicit `IMPOSER_PATH`, or at least the individual directories such as `IMPOSER_PLUGINS`.  (You can run `imposer path` to find out the directories imposer is currently using, or `imposer default-path` to get the directories imposer would use if `IMPOSER_PATH` were not set.)
* By default, the compiled version of state files are cached in `imposer/.cache` in your project root.  You can change this by setting `IMPOSER_CACHE` to the desired directory, or an empty string to disable caching.  (It's best to keep this enabled, and delete it rarely, since uncached compilation is slow.)
* In situations where caching is disabled, or your cache is frequently cleared, YAML blocks are compiled more slowly than JSON blocks. You can speed this up a bit by installing  [yaml2json](https://github.com/bronze1man/yaml2json), or by using JSON blocks instead.
* wp-cli commands are generally slow to start: if you have a choice between running wp-cli from a `shell` block, or writing PHP code directly, the latter is considerably faster.

