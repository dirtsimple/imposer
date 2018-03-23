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

The combined PHP code supplied by all the loaded states is then run via [`wp eval-file`](https://developer.wordpress.org/cli/commands/eval-file/), with the JSON configuration passed in as a command-line argument for it to operate on -- which means that you can extend the configuration format by adding PHP to a state file that reads that extended configuration data from a key in `$state`.  (More on this in the "[Extending The System](#extending-the-system)" section, below.)

### Contents

<!-- toc -->

- [How States Work](#how-states-work)
  * [Extending The System](#extending-the-system)
  * [Shell Hooks](#shell-hooks)
- [Installation, Requirements, and Use](#installation-requirements-and-use)
  * [Imposer Subcommands](#imposer-subcommands)
    + [imposer apply *[state...]*](#imposer-apply-state)
    + [imposer json *[state...]*](#imposer-json-state)
    + [imposer php *[state...]*](#imposer-php-state)
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
# Load these states before proceeding
require "some/state" "foo/other" "this/that"
```

Now that we've done that, any YAML or JSON we include will *override* what the above states set, and any PHP code we include will run after the PHP code defined in those states.  (Since each state file is only loaded once during an `imposer` run, we can use `require` to enforce precedence between states.)

Let's quickly override something with a bit of jq code:

```jq
.plugins."some-plugin" = true  # activate `some-plugin`
```

Each `jq` block must be a valid jq filter expression.  (If you want to do multiple things in the same block, separate them with a `|`. )

For most things, though, it's both clearer and simpler to just use YAML and JSON blocks, leaving jq code for those rare instances where you need to manipulate the configuration in a way not supported by YAML or JSON blocks.  For example, if you need to read one part of the configuration in order to set another, the only reasonable way to do that is with jq code.

(For more on what you can do with `jq` blocks and shell scripting, see the [jqmd docs](https://github.com/bashup/jqmd).)

Last, but not least, you can define PHP blocks.  Unlike the other types of blocks, PHP blocks are "saved up" and executed later, after imposer has finished executing all of the state files to create the complete JSON configuration map.

All the PHP blocks defined by all the states are joined together in one giant PHP file that gets passed into `wp eval-file`, with the configuration map as a command-line argument.  The configuration map is stored  in the `$state` variable, which your code should not modify.  All of the builtin configuration keys (just `options` and `plugins` at this moment) will have already been processed before your PHP code runs.

(Note: the configuration map in `$state` is built up from nothing on each imposer run, and only contains values put there by the state files loaded *during that imposer run*.  It does *not* contain existing plugin or option settings, etc.  If you need to read the existing-in-Wordpress values of such things, you must use PHP code that invokes the Wordpress API.  Think of the configuration map as a to-do list or list of "things we'd like to ensure are this way in Wordpress as of this run".)

### Extending The System

You can use PHP blocks to do either WP API operations that aren't currently built-in to imposer, or to create extensions to the configuration format.  For example, if there's a plugin or wp-cli package that defines an API for some type of object, like LMS courses or e-commerce products, you could extend the configuration format with a new top-level key like `my_ecommerce_plugin`, containing a subkey for products.

You would then include a state file in your plugin to initialize this key, with something like:

```yaml
my_ecommerce_plugin:
  products: {}   # empty maps for other state files to insert configuration into
  categories: {}
```

Your plugin would then follow this with some PHP code to read this data  and do something with it.  It's best to keep the actual code brief, just calling into your actual plugin to load the data like this:

```php
$my_plugin_info = $state['my_ecommerce_plugin'];

MyPluginAPI::setup_products($my_plugin_info['products']);
MyPluginAPI::setup_categories($my_plugin_info['categories']);
```

And then users who want to impose product definitions in their state files would `require` your state file before adding in their own YAML or JSON with product data.  Any PHP they defined after `require`-ing your state file would then run after your own PHP code, allowing others to further extend and build on what you did.

If you're distributing your state as part of a wordpress theme or plugin, you can include the state file as `default.state.md` in the root of your plugin, or inside an `imposer/` subdirectory.  Users can then `require "your-theme-or-plugin-name"` to load the default state file.  If on the other hand you're distributing it as a `composer` package, it would work the same way  except people would `require "your-org/your-name"` to load its default state file.

You can of course have state files besides a default: you can use them to provide a variety of profiles for configuring your plugin or theme.  For example, if your theme has various example or starter sites, you can define them as state files, and people could import them with `imposer apply my-theme/portfolio`, to load the `portfolio.state.md` in the root or `imposer/` subdirectory of your theme.  Such state files can depend on other state files, and users can build their own state files on top of those, using `require`.

### Shell Hooks

Imposer offers a system of event hooks for `shell` code, similar to Wordpress's `add_action` and `do_action` system for PHP.  State files can use the [bashup events](https://github.com/bashup/events/) API to register bash functions that will then be called when specific events are fired.  For example:

```shell
my_plugin.message() { echo "$@"; }
my_plugin.handle_json() { echo "The JSON going to eval-file is:"; echo "$IMPOSER_JSON"; }

event on "after_state"    my_plugin.message "The current state file ($IMPOSER_STATE) is finished loading."
event on "state_loaded"/1 my_plugin.message "Just loaded a state called:"
event on "imposer_loaded" my_plugin.message "All states have finished loading."
event on "json_loaded"    my_plugin.handle_json
event on "imposer_done"   my_plugin.message "All PHP code has been run."
```

The system is very similar to Wordpress actions, except there is no priority system, and you specify the number of *additional* arguments your function takes by adding a `/` and a number at the end of the event name.  (So above, the `state_loaded` event will pass up to one argument to `my_plugin.message` in addition to `"Just loaded a state called:"`, which in this case will be the name of the state loaded.)

Also, you can put arguments after the name of your function, and any arguments supplied by the event will be added after those. Duplicate registrations have no effect, but you can register the same function multiple times for the same event if it has different arguments or a different argument count.

Imposer currently offers the following built-in events:

* `after_state` -- fires when the *currently loading* state file (and all its dependencies) have finished loading.  (Note that the "currently loading" file is not necessarily the same as the file where a callback is being registered, which means that state files can define APIs that register callbacks to run when the *calling* state file is finished.)
* `state_loaded` *statename sourcefile*-- emitted when *any* state has finished loading.  Callbacks can register to receive up to two arguments: the state's name and the path to the source file it was loaded from.
* `imposer_loaded` -- fires when all state files are finished loading, but before jq is run to produce the configuration JSON.  You can hook this to add additional data or jq code that will postprocess your configuration in some fashion.
* `json_loaded` -- fires after jq has been run, with the JSON configuration in the read-only variable `$IMPOSER_JSON`.  You can hook this event to run shell operations before any PHP code is run.
* `imposer_done` -- fires after `wp eval-file` has been run, allowing you to run additional shell commands after all the PHP code has been run.

Of course, just like with Wordpress, you are not restricted to the built-in events!  You can create your own custom events, and trigger them with `event emit` or `event fire`.  (See the [event API docs](https://github.com/bashup/events/#events-api) for more info.)



## Installation, Requirements, and Use

Imposer is packaged with composer, and is intended to be installed that way, i.e. via `composer require dirtsimple/imposer:dev-master` or `composer global require dirtsimple/imposer:dev-master`.  In either case, make sure that the appropriate `vendor/bin` directory is on your `PATH`, so that you can just run `imposer` without having to specify the exact location.

In addition to PHP, Composer, and Wordpress, imposer requires:

* [jq](http://stedolan.github.io/jq/) 1.5 or better
* the bash shell, version 3.2 or better
* Optionally, a copy of [this fast yaml2json written in go](https://github.com/bronze1man/yaml2json), to speed up YAML processing over the [yaml2json.php](https://packagist.org/packages/dirtsimple/yaml2json) that gets installed alongside imposer.

Imposer is not yet regularly tested on anything other than Linux, but it *should* work on OS/X and other Unix-like operating systems with a suitable version of bash and jq.  (It *can* be run on Windows using the Cygwin versions of PHP and jq, but will run painfully slowly compared to the Linux Subsystem for Windows or using a Linux VM or Docker container.)

To use Imposer, you must have an `imposer-project.md`,  `composer.json` OR `wp-cli.yml` file located in the root of your current project.  Imposer will search the current directory and its parent directories until it finds one of the three files, and all relative paths (e.g. those in `IMPOSER_PATH`) will be treated as relative to that directory.  (And all code in state files is executed with that directory as the current directory.)  If you have an `imposer-project.md`, it will be loaded as though it were the state file for a state called `imposer-project`.

Basic usage is `imposer apply` *state...*, where each state name designates a state file to be loaded.  States are loaded in the order specified, unless an earlier state `require`s a later state, forcing it to be loaded earlier than its position in the list.  You don't have to list any states if everything you want to apply is in your `imposer-project.md`, or is `require`d by it.

For convenience, state names do not include the `.state.md` suffix, and can also just be the name of a composer package (e.g. `foo/bar`) or theme/plugin directory (e.g. `sometheme` or `someplugin`).  Given a string such as `foo/bar/baz`, imposer will look for the following files in the following order, in each directory on `IMPOSER_PATH`, with the first found file being used:

* `foo/bar/baz.state.md` (the exact name, as a `.state.md` file)
* `foo/bar/baz/default.state.md` (the exact name as a directory, containing a `default.state.md` file)
* `foo/bar/baz/imposer-states/default.state.md` (the exact name as a directory, containing an `imposer-states/default.state.md` file)
* `foo/bar/imposer-states/baz.state.md` (the namespace of the name as a directory, containing the last name part in an `imposer-states` subdirectory)

(The last rule means that you can create a composer package called e.g. `mycompany/imposer-states` containing a library of state files, that you can then require as `mycompany/foo` to load `foo.state.md` from `vendor/mycompany/imposer-states`.  Or you can make a Wordpress plugin called `myplugin`, and then require  `myplugin/bar` to load `bar.state.md` from the plugin's `imposer-states/` directory or its root.)

The default `IMPOSER_PATH` contains:

* `./imposer` (i.e., the `imposer` subdirectory of the project root)
* The Wordpress themes directory as provided by wp-cli (e.g. `wp-content/themes/`)
* The Wordpress plugin directory as provided by wp-cli (e.g. `wp-content/plugins/`)
* The `COMPOSER_VENDOR_DIR` (e.g. `vendor/`), if a `composer.json` is present
* The wp-cli package path, as provided by wp-cli (typically `~/.wp-cli/packages`)
* The global composer `vendor` directory, e.g. `${COMPOSER_HOME}/vendor`

This allows states to be distributed and installed in a variety of ways, while still being overrideable at the project level (via the main `imposer/`) directory.  (For example, if you add an `imposer/foo/bar.state.md` file to your project, it will replace the `bar` state of any theme/plugin named `foo`, or the default state of a composer package named `foo/bar`.)

While we're discussing precedence order, you may find it useful to have an explicit listing of the phases in which `imposer apply` executes:

* First phase: load and execute state files by converting them to (timestamp-cached) shell scripts that are then `source`d by imposer.  (The `imposer_loaded` event fires at the end of this phase.)
* Second phase: run `jq` using the accumulated jq code generated by the YAML, JSON, jq, etc. code blocks executed during the first phase, creating a new JSON configuration map.  (The `json_loaded` event fires at the end of this phase.)
* Third phase: run `wp eval-file` on the accumulated PHP code generated by the code blocks executed during the first phase, passing the JSON output by the second phase as a command-line argument.  (The `imposer_done` event fires at the end of this phase.)

So, even though it looks like shell, PHP, and YAML/JSON/jq code execution are interleaved, in reality all the shell code is executed first: it's just that any code block *other* than shell code blocks are translated to shell code that accumulates either jq or PHP code for execution in the second or third phase.  This means that you can't (for example) have two YAML blocks reference the same environment variable and change it "in between them" using shell code, because whatever value the environment variable has at the end of phase one is what will be used when *both* blocks are executed during phase two.

### Imposer Subcommands

Note: imposer always operates on the nearest directory at or above the current directory that contains either an `imposer-project.md `, a `wp-cli.yml`, and/or a `composer.json`.  This directory is assumed to be your project root, and all relative paths are based there.

(Note also that imposer does not currently support operating on remote sites: state files are always read and run on the *local* machine, even if `wp eval-file` successfully sends the resulting JSON and PHP for remote execution!)

#### imposer apply *[state...]*

Load and execute the specified states, building a JSON configuration and accumulating PHP code, before handing them both off to `wp eval-file`.

#### imposer json *[state...]*

Just like `imposer apply`, except that instead of handing off the JSON and PHP to `wp eval-file`, the JSON is written to standard output for debugging.  The `imposer_loaded` event will fire, but the `json_loaded` and `imposer_done` events will not.  (Any jq and shell code in the states will still execute, since that's how the JSON is created in the first place.)

#### imposer php *[state...]*

Just like `imposer json`, except that instead of dumping the JSON to stdout, the accumulated PHP code is dumped to stdout.  The `imposer_loaded` event will fire, but the `json_loaded` and `imposer_done` events will not.

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
* Currently, calculating the default `IMPOSER_PATH` is slow because it runs `wp` and `composer` up to three times each.  You can speed this up considerably by supplying an explicit `IMPOSER_PATH`.  (You can run `imposer path` to find out the directories imposer is currently using, or `imposer default-path` to get the directories imposer would use if `IMPOSER_PATH` were not set.)
* By default, the compiled version of state files are cached in `imposer/.cache` in your project root.  You can change this by setting `IMPOSER_CACHE` to the desired directory, or an empty string to disable caching.  (It's best to keep this enabled, and delete it rarely, since uncached compilation is slow.)
* In situations where caching is disabled, or your cache is frequently cleared, YAML blocks are processed slower than JSON blocks. You can speed this up a bit by installing  [yaml2json](https://github.com/bronze1man/yaml2json), or elimnate the overhead altogether by using JSON blocks instead.
* wp-cli commands are generally slow to start: if you have a choice between running wp-cli from a `shell` block, or writing PHP code directly, the latter is considerably faster.

